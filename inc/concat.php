<?php
/**
 * Studio Inspirasi bridge — newline-delimited JSON-RPC 2.0 over TCP.
 *
 * One connection per worker run. First line is always an `auth` call;
 * after that, requests/responses are matched by `id` and event lines
 * (no `id`) are forwarded to the optional on_event callback.
 * See wp-content/docs/concat-ai-openai.md for the protocol notes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Concat {

	private $fp       = null;
	private $seq      = 0;
	private $on_event = null;

	/* ------------------------------------------------------------- */

	private static function split_addr( $address ) {
		$parts = explode( ':', trim( (string) $address ) );
		$port  = (int) end( $parts );
		array_pop( $parts );
		$host = implode( ':', $parts ) ?: '127.0.0.1';
		return array( $host, $port > 0 ? $port : 7420 );
	}

	private function fail( $code, $message ) {
		return new WP_Error( $code, $message );
	}

	/**
	 * Connect + authenticate. Returns true or WP_Error.
	 */
	public function connect( $timeout = 5 ) {
		$creds = cc_concat_credentials();
		if ( is_wp_error( $creds ) ) {
			return $creds;
		}
		list( $host, $port ) = self::split_addr( $creds['address'] );

		$errno = 0;
		$err   = '';
		$this->fp = @fsockopen( $host, $port, $errno, $err, $timeout ); // phpcs:ignore — local socket, errors handled
		if ( ! $this->fp ) {
			return $this->fail( 'cc_unreachable', "Tidak bisa terhubung ke Studio Inspirasi di {$host}:{$port} ({$err}). Buka Studio Inspirasi dan aktifkan Settings › Automation." );
		}
		stream_set_timeout( $this->fp, 10 );

		$auth = $this->request( 'auth', array( 'token' => $creds['token'] ), 10 );
		if ( is_wp_error( $auth ) ) {
			$this->close();
			return new WP_Error( 'cc_auth', 'Autentikasi Studio Inspirasi gagal: ' . $auth->get_error_message() );
		}
		return true;
	}

	public function close() {
		if ( is_resource( $this->fp ) ) {
			fclose( $this->fp );
		}
		$this->fp = null;
	}

	public function set_event_handler( $cb ) {
		$this->on_event = $cb;
	}

	/* ------------------------------------------------------------- */

	private function read_line( $deadline ) {
		$left = $deadline - microtime( true );
		if ( $left <= 0 ) {
			return false;
		}
		stream_set_timeout( $this->fp, (int) $left, (int) ( ( $left - (int) $left ) * 1e6 ) );
		$line = fgets( $this->fp );
		if ( false === $line ) {
			return false;
		}
		return $line;
	}

	private function read_message( $deadline ) {
		while ( microtime( true ) < $deadline ) {
			$line = $this->read_line( $deadline );
			if ( false === $line ) {
				return false;
			}
			$msg = json_decode( trim( $line ), true );
			if ( ! is_array( $msg ) ) {
				continue; // noise — keep reading
			}
			return $msg;
		}
		return false;
	}

	/**
	 * Raw request → result array or WP_Error.
	 * Event lines between request and response go to on_event.
	 */
	private function request( $method, $params = array(), $timeout = 30 ) {
		if ( ! is_resource( $this->fp ) ) {
			return $this->fail( 'cc_closed', 'Koneksi Studio Inspirasi belum dibuka.' );
		}
		$id = ++$this->seq;
		$out = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => (object) $params,
			)
		);
		if ( ! $out || false === @fwrite( $this->fp, $out . "\n" ) ) { // phpcs:ignore
			return $this->fail( 'cc_write', 'Gagal mengirim ke socket Studio Inspirasi.' );
		}

		$deadline = microtime( true ) + $timeout;
		while ( microtime( true ) < $deadline ) {
			$msg = $this->read_message( $deadline );
			if ( false === $msg ) {
				return $this->fail( 'cc_timeout', "Studio Inspirasi tidak menjawab dalam {$timeout} detik." );
			}
			if ( ! isset( $msg['id'] ) ) {
				if ( $this->on_event ) {
					call_user_func( $this->on_event, $msg );
				}
				continue;
			}
			if ( $msg['id'] !== $id ) {
				continue; // response to another caller — shared dispatcher
			}
			if ( isset( $msg['error'] ) ) {
				$code = is_array( $msg['error'] ) && isset( $msg['error']['data']['code'] )
					? $msg['error']['data']['code']
					: 'failed';
				return new WP_Error(
					'cc_' . sanitize_key( $code ),
					(string) ( $msg['error']['message'] ?? 'Error Studio Inspirasi.' ),
					array( 'api_code' => $code )
				);
			}
			return isset( $msg['result'] ) ? $msg['result'] : array();
		}
		return $this->fail( 'cc_timeout', "Studio Inspirasi tidak menjawab dalam {$timeout} detik." );
	}

	/**
	 * Public API call. Reconnects once on a dead socket.
	 */
	public function call( $method, $params = array(), $timeout = 30 ) {
		// Lazy connect — the worker hands out an unconnected handle so
		// pure-chat turns never touch the socket.
		if ( ! is_resource( $this->fp ) ) {
			$con = $this->connect();
			if ( is_wp_error( $con ) ) {
				return $con;
			}
		}
		$res = $this->request( $method, $params, $timeout );
		if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'cc_timeout', 'cc_write', 'cc_closed' ), true ) ) {
			$this->close();
			$re = $this->connect();
			if ( is_wp_error( $re ) ) {
				return $re;
			}
			$res = $this->request( $method, $params, $timeout );
		}
		return $res;
	}

	/**
	 * Read events until export.done / export.failed for $job.
	 * $on_event receives every event array; it may return false to abort
	 * (we then try export.cancel before reporting cancelled).
	 */
	public function wait_export( $job, $on_event = null, $deadline = 0 ) {
		if ( ! is_resource( $this->fp ) ) {
			return $this->fail( 'cc_closed', 'Koneksi Studio Inspirasi belum dibuka.' );
		}
		if ( ! $deadline ) {
			$deadline = microtime( true ) + HOUR_IN_SECONDS;
		}
		$aborted = false;
		while ( microtime( true ) < $deadline ) {
			$msg = $this->read_message( min( microtime( true ) + 30, $deadline ) );
			if ( false === $msg ) {
				if ( feof( $this->fp ) ) {
					// Socket died — ask the server directly whether the
					// export survived, instead of spinning silently.
					$this->close();
					$re = $this->connect();
					if ( is_wp_error( $re ) ) {
						return new WP_Error( 'cc_export_lost', 'Koneksi ke Studio Inspirasi terputus saat export berjalan.' );
					}
					$st = $this->call( 'export.status', array( 'job' => $job ), 10 );
					if ( is_wp_error( $st ) ) {
						return $st;
					}
					if ( 'done' === ( $st['status'] ?? '' ) ) {
						return $st;
					}
					return new WP_Error( 'cc_export_lost', 'Export terputus: ' . ( $st['status'] ?? 'status tidak diketahui' ) );
				}
				continue; // idle socket — check deadline again
			}
			if ( isset( $msg['id'] ) ) {
				continue; // a response, not an event — shouldn't happen mid-wait
			}
			$name   = $msg['method'] ?? '';
			$params = $msg['params'] ?? array();
			if ( $on_event ) {
				$keep = call_user_func( $on_event, $name, $params );
				if ( false === $keep && ! $aborted ) {
					$aborted = true;
					$this->request( 'export.cancel', array( 'job' => $job ), 5 );
				}
			}
			if ( ( $params['job'] ?? '' ) === $job
				&& in_array( $name, array( 'export.done', 'export.failed' ), true ) ) {
				if ( 'export.done' === $name ) {
					return $params;
				}
				$code = $params['error']['code'] ?? 'failed';
				return new WP_Error(
					'cc_export_' . sanitize_key( $code ),
					(string) ( $params['error']['message'] ?? 'Export gagal.' ),
					$params
				);
			}
		}
		return $this->fail( 'cc_export_timeout', 'Export tidak selesai dalam batas waktu.' );
	}

	/* ------------------------------------------------------------- */

	/** Quick connectivity probe for the settings test + status badge. */
	public static function probe() {
		$cx  = new self();
		$con = $cx->connect();
		if ( is_wp_error( $con ) ) {
			return $con;
		}
		$ver = $cx->call( 'version', array(), 10 );
		$cx->close();
		return $ver;
	}
}
