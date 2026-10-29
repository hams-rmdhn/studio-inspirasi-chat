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
		return CC_AI::chat( $messages, $tools );
	}

	/** Cheap connectivity probe for the settings test button. */
	public static function ping() {
		return CC_AI::ping();
	}
}
