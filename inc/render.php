<?php
/**
 * Render helpers — markdown subset → safe HTML for bubbles, plus the
 * REST-facing message serializer. Ported from sf_ticket_render_body.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Allowed HTML in message bodies — chat-safe subset. */
function cc_clean_body( $body ) {
	return wp_kses(
		(string) $body,
		array(
			'a'          => array( 'href' => true, 'target' => true, 'rel' => true ),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'code'       => array(),
			'pre'        => array(),
			'br'         => array(),
			'p'          => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
		)
	);
}

/** markdown subset → safe HTML (links, bold, italic, code, autolink). */
function cc_render_body( $body ) {
	$body = (string) $body;
	$body = preg_replace_callback(
		'/\[([^\]\n]{1,200})\]\(\s*(https?:\/\/[^\s\)]+)\s*\)/',
		function ( $m ) {
			return '<a href="' . esc_url( $m[2] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $m[1] ) . '</a>';
		},
		$body
	);
	$body = preg_replace( '/`([^`\n]+)`/', '<code>$1</code>', $body );
	$body = preg_replace( '/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $body );
	$body = preg_replace( '/\*(?=\S)([^*\n]*?\S)\*/', '<em>$1</em>', $body );
	return wp_kses_post( wpautop( make_clickable( $body ) ) );
}

/**
 * Serialize one message row for REST. 'html' is ready to inject;
 * 'meta' is decoded JSON for the UI (video_url, progress, tool).
 */
function cc_message_to_json( $m ) {
	$meta = array();
	if ( $m->meta ) {
		$decoded = json_decode( $m->meta, true );
		$meta    = is_array( $decoded ) ? $decoded : array();
	}

	$html = '';
	switch ( $m->role ) {
		case 'user':
			$html = '<div class="cc-msg cc-msg-user"><div class="cc-bubble cc-bubble-user">'
				. '<div class="cc-body">' . cc_render_body( $m->body ) . '</div></div></div>';
			break;

		case 'assistant':
			$inner = '<div class="cc-body">' . cc_render_body( $m->body ) . '</div>';
			if ( ! empty( $meta['video_url'] ) ) {
				$inner .= '<video controls preload="metadata" src="' . esc_url( $meta['video_url'] ) . '" class="cc-video"></video>'
					. '<a class="cc-dl" href="' . esc_url( $meta['video_url'] ) . '" download>Unduh video</a>';
			}
			$html = '<div class="cc-msg cc-msg-ai"><div class="cc-bubble cc-bubble-ai">' . $inner . '</div></div>';
			break;

		case 'task':
			$tool = sanitize_text_field( $meta['tool'] ?? '' );
			// Terminal row status wins — a reaped/failed task must not
			// keep rendering its stale "running" meta forever.
			$st = in_array( $m->status, array( 'failed', 'done', 'cancelled' ), true )
				? $m->status
				: sanitize_key( $meta['status'] ?? $m->status );
			$pct  = isset( $meta['progress'] ) ? (int) $meta['progress'] : 0;
			$html = '<div class="cc-msg cc-msg-task"><div class="cc-task cc-task-' . esc_attr( $st ) . '">'
				. '<span class="cc-task-dot"></span>'
				. '<span class="cc-task-label">' . esc_html( $m->body ) . '</span>'
				. ( $pct ? '<span class="cc-task-pct">' . $pct . '%</span>' : '' )
				. '</div></div>';
			break;
	}

	// Surface the playable URL to the assistant bubble when a copy_result
	// landed — the worker stores it on the message meta directly, but
	// the latest copy also rides along for the UI to embed.
	if ( 'assistant' === $m->role && empty( $meta['video_url'] ) ) {
		$meta['video_url'] = '';
	}

	return array(
		'id'         => (int) $m->id,
		'role'       => $m->role,
		'status'     => $m->status,
		'html'       => $html,
		'text'       => wp_strip_all_tags( $m->body ),
		'meta'       => $meta,
		'created_at' => $m->created_at,
	);
}
