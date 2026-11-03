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

		case 'review':
			$st = in_array( $m->status, array( 'done', 'cancelled', 'failed' ), true )
				? ( 'done' === $m->status ? (string) ( $meta['state'] ?? 'applied' ) : $m->status )
				: sanitize_key( $meta['state'] ?? 'pending' );
			if ( 'pending' !== $st ) {
				// Terminal — ringkas saja; data interaktif tidak lagi relevan.
				$lbl   = 'applied' === $st ? 'diterapkan' : ( 'replaced' === $st ? 'disusun ulang — lihat kartu baru' : ( 'cancelled' === $st ? 'dibatalkan' : 'kedaluwarsa' ) );
				$class = 'applied' === $st ? 'done' : 'failed';
				$html  = '<div class="cc-msg cc-msg-task"><div class="cc-task cc-task-' . esc_attr( $class ) . '">'
					. '<span class="cc-task-dot"></span>'
					. '<span class="cc-task-label">' . esc_html( $m->body . ' — ' . $lbl ) . '</span>'
					. '</div></div>';
				break;
			}
			// Kartu interaktif — markup Alpine dirender server-side; data
			// (items + katalog) menempel lewat JSON embed yang dibaca
			// ccEdlCard saat Alpine meng-inisialisasi node injeksi x-html.
			$json = (string) wp_json_encode( $meta );
			$json = str_replace( '</', '<\\/', $json );
			$html = '<div class="cc-msg cc-msg-review"><div class="cc-edl" x-data="ccEdlCard($el)">'
				. '<script type="application/json" class="cc-edl-data">' . $json . '</script>'
				. '<div class="cc-edl-head"><i data-lucide="scissors" class="w-4 h-4"></i>'
				. '<span class="cc-edl-title">' . esc_html( $m->body ) . '</span>'
				. '<span class="cc-edl-badge" x-show="\'pending\' === state">menunggu persetujuan</span></div>'
				. '<div x-show="\'pending\' === state">'
				. '<template x-for="(sg, si) in segs" :key="si"><div class="cc-edl-seg">'
				. '<div class="cc-edl-seg-t"><span x-text="sg.label"></span><span class="cc-edl-dur" x-text="fmtT(sg.dur)"></span></div>'
				. '<template x-for="(it, ii) in sg.items" :key="ii"><div class="cc-edl-row">'
				. '<button type="button" class="cc-edl-thumb" @click="togVid(si, ii)" :title="it.name">'
				. '<img x-show="it.thumb" :src="it.thumb" alt="" loading="lazy">'
				. '<i x-show="!it.thumb" data-lucide="film" class="w-4 h-4"></i>'
				. '<span class="cc-edl-play"><i data-lucide="play" class="w-3 h-3"></i></span></button>'
				. '<div class="cc-edl-info">'
				. '<div class="cc-edl-name" x-text="it.name"></div>'
				. '<div class="cc-edl-reason" x-show="it.reason" x-text="it.reason"></div>'
				. '<div class="cc-edl-times">'
				. '<label>mulai <input type="number" x-model.number="it.tl_start" step="0.1" min="0" class="cc-edl-num"></label>'
				. '<label>durasi <input type="number" x-model.number="it.dur" step="0.1" min="0.3" class="cc-edl-num"></label>'
				. '<label>sumber <input type="number" x-model.number="it.src_start" step="0.1" min="0" class="cc-edl-num"></label>'
				. '</div>'
				. '<template x-if="open === si + \'-\' + ii"><div class="cc-edl-vid"><video controls preload="metadata" :src="it.video + \'#t=\' + it.src_start"></video></div></template>'
				. '</div>'
				. '<div class="cc-edl-ops">'
				. '<select x-model="it.broll_id" @change="swapBroll(si, ii)" class="cc-edl-sel" title="Ganti klip B-Roll">'
				. '<template x-for="b in brolls" :key="b.id"><option :value="b.id" x-text="b.name"></option></template></select>'
				. '<select x-model="it.sfx_id" class="cc-edl-sel" title="Sound effect" x-show="sfxs.length">'
				. '<option value="">tanpa SFX</option>'
				. '<template x-for="s in sfxs" :key="s.id"><option :value="s.id" x-text="s.name"></option></template></select>'
				. '<button type="button" class="cc-edl-del" @click="delItem(si, ii)" title="Hapus sisipan"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>'
				. '</div>'
				. '</div></template>'
				. '<button type="button" class="cc-edl-add" @click="addItem(si)" x-show="brolls.length"><i data-lucide="plus" class="w-3.5 h-3.5"></i> sisipkan B-Roll</button>'
				. '</div></template>'
				. '<div class="cc-edl-foot">'
				. '<input type="text" x-model="note" class="cc-input cc-edl-note" placeholder="catatan untuk susun ulang (opsional)">'
				. '<button type="button" class="cc-btn cc-btn-ghost" @click="replan()" :disabled="busy"><i data-lucide="refresh-ccw" class="w-3.5 h-3.5"></i> Susun ulang</button>'
				. '<button type="button" class="cc-btn cc-btn-ghost" @click="cancelReview()" :disabled="busy">Batalkan</button>'
				. '<button type="button" class="cc-btn cc-edl-apply" @click="apply()" :disabled="busy"><i data-lucide="check" class="w-3.5 h-3.5"></i> Terapkan</button>'
				. '</div>'
				. '</div>'
				. '<div class="cc-edl-final" x-show="\'pending\' !== state" x-cloak>'
				. '<i :data-lucide="\'applied\' === state ? \'check-circle-2\' : (\'replaced\' === state ? \'refresh-ccw\' : \'x-circle\')" class="w-4 h-4"></i>'
				. '<span x-text="\'applied\' === state ? \'Rencana diterapkan — rakitan dilanjutkan\' : (\'replaced\' === state ? \'Rencana disusun ulang — lihat kartu baru\' : \'Review dibatalkan\')"></span>'
				. '</div>'
				. '<p x-show="err" x-text="err" class="cc-err" x-cloak></p>'
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
