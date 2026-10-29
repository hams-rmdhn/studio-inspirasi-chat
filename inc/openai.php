<?php
/**
 * OpenAI client — Chat Completions + function calling.
 * Same transport shape as SC_AI (wp_remote_post, chat/completions).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CC_OpenAI {

	const MAX_TOKENS  = 1200;
	const TEMPERATURE = 0.3;

	/**
	 * One completions round.
	 * Returns array{message: array, usage: array} or WP_Error.
	 * $messages / $tools are the wire formats (tools may be omitted).
	 */
	public static function chat( $messages, $tools = null ) {
		$key = cc_openai_key();
		if ( '' === $key ) {
			return new WP_Error( 'cc_no_key', 'API key OpenAI belum diisi — atur di Studio Inspirasi Chat → Settings.' );
		}

		$body = array(
			'model'       => cc_openai_model(),
			'messages'    => $messages,
			'max_tokens'  => self::MAX_TOKENS,
			'temperature' => self::TEMPERATURE,
		);
		if ( $tools ) {
			$body['tools']       = $tools;
			$body['tool_choice'] = 'auto';
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cc_api', 'OpenAI: respons bukan JSON (HTTP ' . $status . ').' );
		}
		if ( empty( $data['choices'][0]['message'] ) ) {
			$err = $data['error']['message'] ?? 'respons tidak dikenal';
			return new WP_Error( 'cc_api', 'OpenAI: ' . $err );
		}
		return array(
			'message' => $data['choices'][0]['message'],
			'usage'   => $data['usage'] ?? array(),
		);
	}

	/** Cheap connectivity probe for the settings test button. */
	public static function ping() {
		$res = self::chat(
			array( array( 'role' => 'user', 'content' => 'Jawab satu kata: siap' ) ),
			null
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$model = cc_openai_model();
		$out   = trim( (string) ( $res['message']['content'] ?? '' ) );
		return "Model {$model} merespons: " . ( $out ? mb_substr( $out, 0, 60 ) : '(kosong)' );
	}
}
