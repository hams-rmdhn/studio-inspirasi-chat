<?php
/**
 * Tool layer — OpenAI function calls → Studio Inspirasi API / local helpers.
 *
 * $ctx (array, by reference) travels through the worker:
 *   'job_id', 'session_id', 'cx' (CC_Concat), 'task_cb' (callable for
 *   progress reporting back to the job row + task message).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_Tools {

	/* -------------------------------------------------------------
	 * Path guards
	 * ----------------------------------------------------------- */

	private static function norm( $path ) {
		return wp_normalize_path( trim( (string) $path ) );
	}

	private static function under( $path, $root ) {
		$root = trailingslashit( self::norm( $root ) );
		$path = self::norm( $path ) . '/';
		// Windows paths are case-insensitive — compare that way there.
		if ( 'Windows' === PHP_OS_FAMILY ) {
			return 0 === stripos( $path, $root );
		}
		return '' !== trim( $root, '/' ) && 0 === strpos( $path, $root );
	}

	/** Folders the assistant may BROWSE: home + copy roots + workspace. */
	public static function read_roots() {
		$roots   = cc_copy_roots();
		$roots[] = cc_workspace();
		$home    = (string) getenv( 'USERPROFILE' ) ?: (string) getenv( 'HOME' );
		if ( $home ) {
			$roots[] = wp_normalize_path( $home );
		}
		return array_filter( array_unique( $roots ) );
	}

	public static function read_allowed( $path ) {
		foreach ( self::read_roots() as $r ) {
			if ( self::under( $path, $r ) ) {
				return true;
			}
		}
		return false;
	}

	/** Folders copy_result may WRITE to: explicit whitelist only. */
	public static function write_allowed_dir( $dir ) {
		foreach ( cc_copy_roots() as $r ) {
			if ( self::under( $dir, $r ) ) {
				return true;
			}
		}
		return false;
	}

	/* -------------------------------------------------------------
	 * Tool schemas for the OpenAI request
	 * ----------------------------------------------------------- */

	public static function definitions() {
		$path  = array( 'type' => 'string', 'description' => 'Absolute path' );
		$req   = function ( $props, $required ) {
			// (object) so empty $props encodes as {} not [] — OpenAI
			// rejects "properties":[] as "not of type object".
			$schema = array( 'type' => 'object', 'properties' => (object) $props );
			if ( $required ) {
				$schema['required'] = $required;
			}
			return $schema;
		};

		$defs = array(
			array( 'concat_status', 'Status koneksi & versi Studio Inspirasi API — panggil dulu bila belum tahu apakah Studio Inspirasi siap.', $req( array(), array() ) ),
			array( 'list_projects', 'Daftar proyek Studio Inspirasi yang ada.', $req( array(), array() ) ),
			array( 'project_open', 'Buka proyek Studio Inspirasi yang sudah ada di disk — wajib dipanggil sebelum membaca/mengedit proyek lama yang tidak dibuat di turn ini.', $req( array( 'project' => $path ), array( 'project' ) ) ),
			array( 'project_save', 'Simpan proyek ke disk (concat.json). Panggil setelah selesai mengedit, wajib bila user ingin melanjutkan manual di aplikasi.', $req( array( 'project' => $path ), array( 'project' ) ) ),
			array( 'project_close', 'Tutup proyek di engine dan lepas lock "Remote API" — pakai bila user ingin membuka/mengekspor proyek lewat UI Clovercut.', $req( array( 'project' => $path ), array( 'project' ) ) ),
			array( 'set_video', 'Ubah ukuran/fps video timeline proyek (mis. vertikal 1080x1920 untuk reel).', $req( array( 'project' => $path, 'video' => array( 'type' => 'object', 'description' => '{width, height, rateNum?, rateDen?}' ) ), array( 'project', 'video' ) ) ),
			array( 'template_list', 'Daftar template proyek yang tersimpan di Studio Inspirasi — mengembalikan path untuk dipakai template_instantiate.', $req( array(), array() ) ),
			array( 'template_instantiate', 'Buat proyek baru dari template di workspace — struktur timeline, ukuran, media, dan overlay ikut terbawa. fills = array pengisian slot placeholder (boleh kosong).', $req( array( 'template' => array( 'type' => 'string', 'description' => 'Path template dari template_list' ), 'name' => array( 'type' => 'string', 'description' => 'Nama proyek baru' ), 'fills' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ), array( 'template', 'name', 'fills' ) ) ),
			array( 'template_save', 'Simpan proyek sebagai template baru.', $req( array( 'project' => $path, 'name' => array( 'type' => 'string', 'description' => 'Nama template' ) ), array( 'project', 'name' ) ) ),
			array( 'replace_clip_media', 'Ganti media sebuah clip dengan file baru — clip mempertahankan posisi, trim, dan efeknya. Pakai untuk mengisi clip utama hasil template dengan video baru.', $req( array( 'project' => $path, 'clip' => array( 'type' => 'string', 'description' => 'Id clip dari timeline_read' ), 'file' => $path ), array( 'project', 'clip', 'file' ) ) ),
			array( 'list_folder', 'Daftar file media dalam sebuah folder lokal (baca saja, dibatasi folder yang diizinkan).', $req( array( 'path' => $path ), array( 'path' ) ) ),
			array( 'probe_file', 'Info detail sebuah file media (durasi, resolusi, dsb.).', $req( array( 'path' => $path ), array( 'path' ) ) ),
			array( 'create_project', 'Buat proyek Studio Inspirasi baru di workspace. Mengembalikan path folder proyek — simpan untuk langkah berikutnya.', $req( array( 'name' => array( 'type' => 'string', 'description' => 'Nama proyek' ) ), array( 'name' ) ) ),
			array( 'import_media', 'Impor file media ke proyek. Mengembalikan mediaId (createdId).', $req( array( 'project' => $path, 'file' => $path ), array( 'project', 'file' ) ) ),
			array( 'timeline_read', 'Baca ringkasan timeline proyek (lanes, clips terurut, media, ids).', $req( array( 'project' => $path ), array( 'project' ) ) ),
			array( 'edit_apply', 'Terapkan satu atau beberapa edit command ke timeline (mis. addClipAtFirstFree, addTextClip). commands = array objek command.', $req( array( 'project' => $path, 'commands' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Array objek {op, ...}' ) ), array( 'project', 'commands' ) ) ),
			array( 'export_run', 'Render/export proyek ke file video di workspace lalu tunggu sampai selesai. Mengembalikan path file hasil.', $req( array( 'project' => $path, 'output_name' => array( 'type' => 'string', 'description' => 'Nama file hasil, mis. reel.mp4' ) ), array( 'project', 'output_name' ) ) ),
			array( 'export_status', 'Periksa status sebuah job export.', $req( array( 'job' => array( 'type' => 'string', 'description' => 'Nama job dari export_run' ) ), array( 'job' ) ) ),
			array( 'copy_result', 'Salin file hasil dari workspace ke folder tujuan yang diizinkan + daftarkan di uploads agar bisa tampil di chat.', $req( array( 'source' => $path, 'dest_dir' => $path ), array( 'source', 'dest_dir' ) ) ),
		);

		$tools = array();
		foreach ( $defs as $d ) {
			$tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $d[0],
					'description' => $d[1],
					'parameters'  => $d[2],
				),
			);
		}
		return $tools;
	}

	/* -------------------------------------------------------------
	 * Dispatch
	 * ----------------------------------------------------------- */

	private static function out( $data ) {
		$s = is_string( $data ) ? $data : wp_json_encode( $data );
		if ( strlen( $s ) > 4000 ) {
			$s = substr( $s, 0, 4000 ) . '…(dipotong)';
		}
		return $s;
	}

	private static function err( $e ) {
		return 'ERROR ' . $e->get_error_code() . ': ' . $e->get_error_message();
	}

	/** Execute one tool call. Always returns a string for the tool message. */
	public static function run( $name, $args, &$ctx ) {
		$args = is_array( $args ) ? $args : array();
		try {
			switch ( $name ) {
				case 'concat_status':
					return self::out( self::cx( $ctx )->call( 'version' ) );

				case 'list_projects':
					return self::out( self::cx( $ctx )->call( 'project.list' ) );

				case 'project_open':
					return self::out( self::cx( $ctx )->call( 'project.open', array( 'path' => $args['project'] ?? '' ) ) );

				case 'project_save':
					return self::out( self::cx( $ctx )->call( 'project.save', array( 'path' => $args['project'] ?? '' ) ) );

				case 'project_close':
					return self::out( self::cx( $ctx )->call( 'project.close', array( 'path' => $args['project'] ?? '' ) ) );

				case 'set_video':
					return self::out(
						self::cx( $ctx )->call(
							'project.setVideo',
							array( 'path' => $args['project'] ?? '', 'video' => (object) ( $args['video'] ?? array() ) )
						)
					);

				case 'template_list':
					return self::out( self::cx( $ctx )->call( 'template.list' ) );

				case 'template_instantiate':
					return self::out(
						self::cx( $ctx )->call(
							'template.instantiate',
							array(
								'template' => $args['template'] ?? '',
								'location' => untrailingslashit( cc_ws_projects() ),
								'name'     => sanitize_file_name( $args['name'] ?? 'project' ),
								'fills'    => is_array( $args['fills'] ?? null ) ? array_values( $args['fills'] ) : array(),
							)
						)
					);

				case 'template_save':
					return self::out(
						self::cx( $ctx )->call(
							'template.save',
							array( 'path' => $args['project'] ?? '', 'name' => (string) ( $args['name'] ?? '' ) )
						)
					);

				case 'replace_clip_media':
					return self::out(
						self::replace_clip_media( $ctx, $args['project'] ?? '', $args['clip'] ?? '', $args['file'] ?? '' )
					);

				case 'list_folder':
					return self::out( self::list_folder( $args['path'] ?? '' ) );

				case 'probe_file':
					return self::out( self::cx( $ctx )->call( 'media.probe', array( 'path' => $args['path'] ?? '' ) ) );

				case 'create_project':
					return self::out( self::create_project( $ctx, $args['name'] ?? '' ) );

				case 'import_media':
					return self::out(
						self::cx( $ctx )->call(
							'media.import',
							array( 'path' => $args['project'] ?? '', 'file' => $args['file'] ?? '' )
						)
					);

				case 'timeline_read':
					return self::out( self::cx( $ctx )->call( 'project.get', array( 'path' => $args['project'] ?? '' ) ) );

				case 'edit_apply':
					return self::out( self::edit_apply( $ctx, $args['project'] ?? '', $args['commands'] ?? array() ) );

				case 'export_run':
					return self::out( self::export_run( $ctx, $args['project'] ?? '', $args['output_name'] ?? '' ) );

				case 'export_status':
					return self::out( self::cx( $ctx )->call( 'export.status', array( 'job' => $args['job'] ?? '' ) ) );

				case 'copy_result':
					return self::out( self::copy_result( $args['source'] ?? '', $args['dest_dir'] ?? '' ) );
			}
			return 'ERROR cc_tool: tool tidak dikenal: ' . $name;
		} catch ( Throwable $e ) {
			return 'ERROR exception: ' . $e->getMessage();
		}
	}

	private static function cx( &$ctx ) {
		return $ctx['cx'];
	}

	/* -------------------------------------------------------------
	 * Individual tools
	 * ----------------------------------------------------------- */

	private static function list_folder( $path ) {
		$path = self::norm( $path );
		if ( ! self::read_allowed( $path ) ) {
			return 'ERROR cc_path: folder di luar daftar yang diizinkan. Folder yang boleh dibaca: ' . implode( '; ', self::read_roots() );
		}
		if ( ! is_dir( $path ) ) {
			return 'ERROR cc_path: folder tidak ditemukan.';
		}
		$exts = array( 'mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v', 'mp3', 'wav', 'aac', 'jpg', 'jpeg', 'png' );
		$out  = array();
		foreach ( (array) scandir( $path ) as $f ) {
			$full = $path . '/' . $f;
			if ( '.' === $f[0] || ! is_file( $full ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $exts, true ) ) {
				$out[] = array( 'file' => $f, 'size' => filesize( $full ), 'path' => $full );
			}
		}
		return $out ? $out : '(folder kosong — tidak ada file media)';
	}

	private static function create_project( &$ctx, $name ) {
		$name = sanitize_file_name( $name ? $name : 'project' );
		$ws   = cc_workspace();
		wp_mkdir_p( $ws );
		$res = self::cx( $ctx )->call( 'project.create', array( 'location' => untrailingslashit( $ws ), 'name' => $name ) );
		if ( is_wp_error( $res ) ) {
			return self::err( $res );
		}
		// API may return the path or an object containing it.
		$path = $res['path'] ?? $res['project']['path'] ?? ( $ws . $name );
		return array( 'project' => self::norm( $path ), 'result' => $res );
	}

	/**
	 * replaceClipMedia expects a NewMedia descriptor (~14 fields). Build it
	 * from media.probe so the model only has to pass {project, clip, file}.
	 */
	public static function replace_clip_media( &$ctx, $project, $clip, $file ) {
		// media.import menyalin file ke assets/ proyek — wajib agar preview
		// Clovercut bisa membaca (path eksternal → preview kosong).
		$mi = self::cx( $ctx )->call( 'media.import', array( 'path' => $project, 'file' => $file ), 90 );
		if ( is_wp_error( $mi ) ) {
			return self::err( $mi );
		}
		$item = null;
		foreach ( array_reverse( (array) ( $mi['project']['media'] ?? $mi['media'] ?? array() ) ) as $m ) {
			if ( basename( (string) ( $m['path'] ?? '' ) ) === basename( (string) $file ) ) {
				$item = $m;
				break;
			}
		}
		if ( ! $item ) {
			// Fallback: item terakhir yang berjenis video.
			foreach ( array_reverse( (array) ( $mi['project']['media'] ?? $mi['media'] ?? array() ) ) as $m ) {
				if ( 'video' === ( $m['kind'] ?? '' ) ) {
					$item = $m;
					break;
				}
			}
		}
		if ( ! $item ) {
			return self::err( new WP_Error( 'cc_import', 'media.import tidak mengembalikan item media.' ) );
		}
		$p   = (string) ( $item['path'] ?? $file );
		$res = self::cx( $ctx )->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'replaceClipMedia', 'clipId' => $clip, 'item' => $item ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::err( $res );
		}
		// Perbarui nama tampilan clip agar ikut nama media baru (cosmetic).
		self::cx( $ctx )->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'updateClip', 'clipId' => $clip, 'patch' => array( 'name' => basename( $p ) ) ),
			),
			15
		);
		return $res;
	}

	private static function edit_apply( &$ctx, $project, $commands ) {
		$commands = is_array( $commands ) ? array_values( $commands ) : array();
		if ( ! $commands ) {
			return 'ERROR cc_edit: commands kosong.';
		}
		$results = array();
		foreach ( $commands as $i => $cmd ) {
			if ( ! is_array( $cmd ) || empty( $cmd['op'] ) || ! is_string( $cmd['op'] ) || ! preg_match( '/^[a-z][a-zA-Z0-9]+$/', $cmd['op'] ) ) {
				$results[] = array( 'index' => $i, 'error' => 'command tidak valid (op wajib camelCase string)' );
				continue;
			}
			$res = self::cx( $ctx )->call( 'edit.apply', array( 'path' => $project, 'command' => $cmd ) );
			$results[] = is_wp_error( $res )
				? array( 'index' => $i, 'op' => $cmd['op'], 'error' => self::err( $res ) )
				: array( 'index' => $i, 'op' => $cmd['op'], 'ok' => true, 'result' => $res );
			// Stop the batch on first hard failure — later commands may depend on it.
			if ( is_wp_error( $res ) ) {
				break;
			}
		}
		return $results;
	}

	/**
	 * export.run → block on wait_export → report progress through
	 * $ctx['task_cb'] (the worker writes it to the job row + task card).
	 */
	private static function export_run( &$ctx, $project, $output_name ) {
		$name   = sanitize_file_name( $output_name ? $output_name : 'export.mp4' );
		if ( ! preg_match( '/\.(mp4|mov|mkv|webm)$/i', $name ) ) {
			$name .= '.mp4';
		}
		$output = cc_ws_exported() . $name;
		wp_mkdir_p( dirname( $output ) );

		$started = self::cx( $ctx )->call( 'export.run', array( 'path' => $project, 'output' => self::norm( $output ) ), 30 );
		if ( is_wp_error( $started ) ) {
			return self::err( $started );
		}
		$job = $started['job'] ?? '';
		if ( ! $job ) {
			return 'ERROR cc_export: respons export.run tidak membawa nama job.';
		}

		$cb = isset( $ctx['task_cb'] ) ? $ctx['task_cb'] : null;
		if ( $cb ) {
			call_user_func( $cb, 'export_start', array( 'job' => $job, 'output' => $output ) );
		}

		$done = self::cx( $ctx )->wait_export(
			$job,
			function ( $event, $params ) use ( $cb, $ctx ) {
				if ( 'export.progress' === $event && $cb ) {
					call_user_func( $cb, 'export_progress', $params );
				}
				// Abort the wait when the WP job got cancelled.
				if ( ! empty( $ctx['job_id'] ) ) {
					$j = cc_job_get( $ctx['job_id'] );
					if ( $j && 'cancelled' === $j->status ) {
						return false;
					}
				}
				return true;
			}
		);

		if ( is_wp_error( $done ) ) {
			return self::err( $done );
		}
		if ( $cb ) {
			call_user_func( $cb, 'export_done', $params = array( 'output' => $output ) );
		}
		return array( 'output' => self::norm( $output ), 'done' => $done );
	}

	/**
	 * Copy an exported file to a whitelisted destination dir + into
	 * wp-content/uploads/concat-chat/ so it can play inside the chat.
	 */
	public static function copy_result( $source, $dest_dir ) {
		$source = self::norm( $source );
		if ( ! file_exists( $source ) ) {
			return 'ERROR cc_copy: file sumber tidak ditemukan: ' . $source;
		}
		$dest_dir = self::norm( $dest_dir );
		if ( ! self::write_allowed_dir( $dest_dir ) ) {
			return 'ERROR cc_copy: folder tujuan di luar daftar yang diizinkan: ' . $dest_dir
				. '. Diizinkan: ' . ( cc_copy_roots() ? implode( '; ', cc_copy_roots() ) : '(kosong — atur di Settings)' );
		}
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return 'ERROR cc_copy: gagal membuat folder tujuan.';
		}
		$base = basename( $source );
		$dest = trailingslashit( $dest_dir ) . $base;
		if ( ! copy( $source, $dest ) ) {
			return 'ERROR cc_copy: gagal menyalin ke ' . $dest;
		}

		// Public copy under uploads for in-chat playback.
		$uploads = wp_upload_dir();
		$pub_dir = trailingslashit( $uploads['basedir'] ) . 'concat-chat/';
		wp_mkdir_p( $pub_dir );
		$pub = $pub_dir . $base;
		copy( $source, $pub );
		$url = trailingslashit( $uploads['baseurl'] ) . 'concat-chat/' . rawurlencode( $base );

		return array(
			'copied_to'   => self::norm( $dest ),
			'playable_url' => $url,
		);
	}
}
