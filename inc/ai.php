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
}
