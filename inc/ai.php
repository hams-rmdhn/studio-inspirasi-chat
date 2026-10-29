<?php
/**
 * Multi-provider AI layer — semua provider memakai format OpenAI
 * /chat/completions: hanya beda base_url + key + model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registry provider OpenAI-compatible. */
function cc_ai_providers() {
	return array(
		'openai'        => array( 'label' => 'OpenAI', 'base' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'key' => true, 'note' => '' ),
		'openrouter'    => array( 'label' => 'OpenRouter', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'meta-llama/llama-3.3-70b-instruct:free', 'key' => true, 'note' => 'ada model :free' ),
		'groq'          => array( 'label' => 'Groq', 'base' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile', 'key' => true, 'note' => 'free tier, cepat' ),
		'ollama'        => array( 'label' => 'Ollama (lokal)', 'base' => 'http://localhost:11434/v1', 'model' => 'llama3.2', 'key' => false, 'note' => '100% gratis, jalan lokal — pilih model bertool' ),
		'github-models' => array( 'label' => 'GitHub Models', 'base' => 'https://models.github.ai/inference', 'model' => 'openai/gpt-4o-mini', 'key' => true, 'note' => 'gratis — pakai GitHub PAT' ),
		'cerebras'      => array( 'label' => 'Cerebras', 'base' => 'https://api.cerebras.ai/v1', 'model' => 'llama-3.3-70b', 'key' => true, 'note' => 'free tier' ),
		'custom'        => array( 'label' => 'Custom URL', 'base' => '', 'model' => '', 'key' => false, 'note' => 'endpoint OpenAI-compatible apa pun' ),
	);
}

function cc_ai_provider() {
	$p = (string) get_option( 'cc_ai_provider', '' );
	if ( '' === $p ) {
		$p = 'openai';
	}
	return array_key_exists( $p, cc_ai_providers() ) ? $p : 'openai';
}

function cc_ai_baseurl() {
	$prov = cc_ai_providers()[ cc_ai_provider() ];
	$base = (string) $prov['base'];
	if ( 'custom' === cc_ai_provider() ) {
		$base = (string) get_option( 'cc_ai_custom_baseurl', '' );
	}
	$override = trim( (string) get_option( 'cc_ai_baseurl', '' ) );
	return untrailingslashit( '' !== $override ? $override : $base );
}

function cc_ai_key() {
	// Legacy cc_openai_key dipakai untuk provider openai.
	$opt = 'openai' === cc_ai_provider() ? 'cc_openai_key' : 'cc_ai_key_' . cc_ai_provider();
	return cc_decrypt( get_option( $opt, '' ) );
}

function cc_ai_model() {
	if ( 'openai' === cc_ai_provider() ) {
		$m = (string) get_option( 'cc_openai_model', '' );
	} else {
		$m = (string) get_option( 'cc_ai_model_' . cc_ai_provider(), '' );
	}
	if ( '' === $m ) {
		$m = (string) cc_ai_providers()[ cc_ai_provider() ]['model'];
	}
	return $m;
}

function cc_ai_configured() {
	$prov = cc_ai_providers()[ cc_ai_provider() ];
	if ( ! $prov['key'] ) {
		return '' !== cc_ai_baseurl();
	}
	return '' !== cc_ai_key();
}

class CC_AI {

	const MAX_TOKENS  = 1200;
	const TEMPERATURE = 0.3;

	/**
	 * One completions round → array{message,usage} | WP_Error.
	 * $messages / $tools = wire format OpenAI chat/completions.
	 */
	public static function chat( $messages, $tools = null ) {
		$key  = cc_ai_key();
		$prov = cc_ai_providers()[ cc_ai_provider() ];
		if ( $prov['key'] && '' === $key ) {
			return new WP_Error( 'cc_no_key', 'API key ' . $prov['label'] . ' belum diisi — atur di Settings › Agen AI.' );
		}
		$base = cc_ai_baseurl();
		if ( '' === $base ) {
			return new WP_Error( 'cc_no_base', 'Base URL provider belum diisi.' );
		}

		$body = array(
			'model'       => cc_ai_model(),
			'messages'    => $messages,
			'max_tokens'  => self::MAX_TOKENS,
			'temperature' => self::TEMPERATURE,
		);
		if ( $tools ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'auto';
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_post(
			$base . '/chat/completions',
			array( 'timeout' => 60, 'headers' => $headers, 'body' => wp_json_encode( $body ) )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cc_api', $prov['label'] . ': respons bukan JSON (HTTP ' . $status . ').' );
		}
		if ( empty( $data['choices'][0]['message'] ) ) {
			$err = $data['error']['message'] ?? 'respons tidak dikenal';
			return new WP_Error( 'cc_api', $prov['label'] . ': ' . $err );
		}
		return array(
			'message' => $data['choices'][0]['message'],
			'usage'   => $data['usage'] ?? array(),
		);
	}

	/** Cheap connectivity probe for the settings test button. */
	public static function ping() {
		$res = self::chat( array( array( 'role' => 'user', 'content' => 'Jawab satu kata: siap' ) ), null );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = trim( (string) ( $res['message']['content'] ?? '' ) );
		return cc_ai_providers()[ cc_ai_provider() ]['label'] . ' · ' . cc_ai_model() . ': ' . ( $out ? mb_substr( $out, 0, 60 ) : '(kosong)' );
	}

	/**
	 * Vision description of a single JPEG/PNG frame.
	 * Uses active provider if vision-capable, or falls back to stored OpenAI key (gpt-4o-mini).
	 * Returns string description or WP_Error.
	 */
	public static function vision( $prompt, $image_path ) {
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return new WP_Error( 'cc_vision', 'File gambar tidak ditemukan.' );
		}
		$bin = @file_get_contents( $image_path );
		if ( false === $bin || '' === $bin ) {
			return new WP_Error( 'cc_vision', 'Gagal membaca frame gambar.' );
		}
		$b64     = base64_encode( $bin );
		$content = array(
			array( 'type' => 'text', 'text' => (string) $prompt ),
			array( 'type' => 'image_url', 'image_url' => array( 'url' => 'data:image/jpeg;base64,' . $b64, 'detail' => 'low' ) ),
		);
		$res = self::chat( array( array( 'role' => 'user', 'content' => $content ) ), null );
		if ( ! is_wp_error( $res ) ) {
			$txt = trim( (string) ( $res['message']['content'] ?? '' ) );
			if ( '' !== $txt ) {
				return $txt;
			}
		}
		// Fallback ke OpenAI gpt-4o-mini bila provider aktif bukan openai tapi key OpenAI ada.
		$okey = cc_decrypt( get_option( 'cc_openai_key', '' ) );
		if ( 'openai' !== cc_ai_provider() && '' !== $okey ) {
			$resp = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 45,
					'headers' => array( 'Authorization' => 'Bearer ' . $okey, 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'model'      => 'gpt-4o-mini',
						'max_tokens' => 150,
						'messages'   => array( array( 'role' => 'user', 'content' => $content ) ),
					) ),
				)
			);
			if ( ! is_wp_error( $resp ) ) {
				$d   = json_decode( wp_remote_retrieve_body( $resp ), true );
				$txt = trim( (string) ( $d['choices'][0]['message']['content'] ?? '' ) );
				if ( '' !== $txt ) {
					return $txt;
				}
			}
		}
		return is_wp_error( $res ) ? $res : new WP_Error( 'cc_vision', 'Model tidak mengembalikan deskripsi visual.' );
	}

	/**
	 * Speech-to-Text via Whisper API (Groq gratis atau OpenAI) -> returns SRT string or WP_Error.
	 */
	public static function transcribe( $audio_path, $lang = 'id' ) {
		if ( ! $audio_path || ! file_exists( $audio_path ) ) {
			return new WP_Error( 'cc_stt', 'File audio tidak ditemukan untuk transkripsi.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'cc_stt', 'Ekstensi PHP cURL diperlukan untuk upload audio Whisper.' );
		}

		// Pilih endpoint STT: prioritas provider aktif (groq/openai), lalu fallback key tersimpan.
		$prov = cc_ai_provider();
		$gkey = cc_decrypt( get_option( 'cc_ai_key_groq', '' ) );
		$okey = cc_decrypt( get_option( 'cc_openai_key', '' ) );

		if ( 'groq' === $prov && '' !== $gkey ) {
			$url   = 'https://api.groq.com/openai/v1/audio/transcriptions';
			$key   = $gkey;
			$model = 'whisper-large-v3-turbo';
		} elseif ( 'openai' === $prov && '' !== $okey ) {
			$url   = 'https://api.openai.com/v1/audio/transcriptions';
			$key   = $okey;
			$model = 'whisper-1';
		} elseif ( '' !== $gkey ) {
			$url   = 'https://api.groq.com/openai/v1/audio/transcriptions';
			$key   = $gkey;
			$model = 'whisper-large-v3-turbo';
		} elseif ( '' !== $okey ) {
			$url   = 'https://api.openai.com/v1/audio/transcriptions';
			$key   = $okey;
			$model = 'whisper-1';
		} else {
			return new WP_Error( 'cc_stt_key', 'Transkripsi audio otomatis membutuhkan API Key OpenAI atau Groq di Settings › Agen AI (atau unggah file .srt manual).' );
		}

		$post = array(
			'file'            => new CURLFile( $audio_path, 'audio/mpeg', basename( $audio_path ) ),
			'model'           => $model,
			'response_format' => 'verbose_json',
		);
		if ( in_array( $lang, array( 'id', 'en' ), true ) ) {
			$post['language'] = $lang;
		}

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $post,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 180,
				CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $key ),
			)
		);
		$raw  = curl_exec( $ch );
		$err  = curl_error( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'cc_stt', 'Whisper gagal dihubungi: ' . ( $err ?: 'empty response' ) );
		}
		$data = json_decode( (string) $raw, true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? ( $data['error']['message'] ?? 'HTTP ' . $code ) : 'HTTP ' . $code;
			return new WP_Error( 'cc_stt', 'Whisper error: ' . $msg );
		}
		$segs = $data['segments'] ?? array();
		if ( ! $segs && ! empty( $data['text'] ) ) {
			$dur  = max( 2.0, (float) ( $data['duration'] ?? 10.0 ) );
			$segs = array( array( 'start' => 0.0, 'end' => $dur, 'text' => (string) $data['text'] ) );
		}
		$blocks = array();
		$idx    = 0;
		foreach ( (array) $segs as $s ) {
			$txt = trim( (string) ( $s['text'] ?? '' ) );
			if ( '' === $txt ) {
				continue;
			}
			$t1 = max( 0.0, (float) ( $s['start'] ?? 0 ) );
			$t2 = max( $t1 + 0.2, (float) ( $s['end'] ?? ( $t1 + 2.0 ) ) );
			$idx++;
			$blocks[] = $idx . "\n" . self::srt_time( $t1 ) . ' --> ' . self::srt_time( $t2 ) . "\n" . $txt;
		}
		return $blocks ? implode( "\n\n", $blocks ) . "\n" : new WP_Error( 'cc_stt_empty', 'Whisper tidak mendeteksi ucapan di file audio.' );
	}

	private static function srt_time( $sec ) {
		$sec = max( 0.0, (float) $sec );
		$h   = (int) floor( $sec / 3600 );
		$m   = (int) floor( fmod( $sec, 3600 ) / 60 );
		$s   = (int) floor( fmod( $sec, 60 ) );
		$ms  = (int) round( ( $sec - floor( $sec ) ) * 1000 );
		if ( $ms >= 1000 ) {
			$ms = 999;
		}
		return sprintf( '%02d:%02d:%02d,%03d', $h, $m, $s, $ms );
	}
}
