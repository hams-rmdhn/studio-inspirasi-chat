<?php
/**
 * Gerbang review EDL (human-in-the-loop).
 *
 * Pipeline broll/assembly berjeda SETELAH AI Director menyusun rencana
 * sisipan (EDL) dan SEBELUM proyek dibuat — "pause-before-project":
 * tidak ada proyek setengah jadi bila review di-abandon, dan resume
 * hanya me-replay langkah deterministik (AI tidak dipanggil ulang).
 *
 * Job ditahan pada status 'waiting' (skema sudah mendukung), kartu
 * interaktif disimpan sebagai pesan role 'review', dan keputusan user
 * masuk lewat REST /control/edl-review → job 'queued' → worker re-run
 * dengan _edl hasil edit user.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------
 * Signed media URL — <video src> tidak bisa mengirim X-WP-Nonce, jadi
 * autentikasi memakai HMAC(path|expiry) + whitelist ekstensi + read_roots.
 * ------------------------------------------------------------------- */

function cc_media_url( $path, $ttl = 86400 ) {
	$exp = time() + $ttl;
	$sig = hash_hmac( 'sha256', $path . '|' . $exp, wp_salt( 'nonce' ) );
	return rest_url( 'concat/v1/media/stream' )
		. '?f=' . rawurlencode( base64_encode( $path ) )
		. '&e=' . $exp
		. '&s=' . $sig;
}

/** Thumbnail satu frame via ffmpeg → jpg di _tmp (cache per file+detik). */
function cc_edl_thumb( $path, $t = 0.5 ) {
	if ( ! file_exists( (string) $path ) || ! function_exists( 'cc_control_ffmpeg_bin' ) ) {
		return '';
	}
	$ff = cc_control_ffmpeg_bin();
	if ( '' === $ff ) {
		return '';
	}
	$out = cc_ws_tmp() . 'thumb-' . md5( $path . '|' . $t ) . '.jpg';
	if ( ! file_exists( $out ) ) {
		$cmd = escapeshellarg( $ff ) . ' -hide_banner -loglevel error -ss '
			. escapeshellarg( (string) max( 0.0, (float) $t ) )
			. ' -i ' . escapeshellarg( $path )
			. ' -frames:v 1 -vf scale=320:-2 -q:v 4 -y ' . escapeshellarg( $out ) . ' 2>/dev/null';
		shell_exec( $cmd );
	}
	return file_exists( $out ) ? $out : '';
}

/**
 * Normalisasi katalog (list dari cc_assembly_catalog_dir) → map id => info.
 * Menerima bentuk list {id,file,name,duration,...} maupun map id=>info.
 */
function cc_edl_catalog_map( $list ) {
	$map = array();
	foreach ( (array) $list as $k => $it ) {
		if ( ! is_array( $it ) ) {
			continue;
		}
		$id   = (string) ( $it['id'] ?? ( is_string( $k ) ? $k : '' ) );
		$path = (string) ( $it['file'] ?? $it['path'] ?? '' );
		if ( '' === $id || '' === $path ) {
			continue;
		}
		$map[ $id ] = array(
			'id'       => $id,
			'path'     => $path,
			'name'     => (string) ( $it['name'] ?? basename( $path ) ),
			'duration' => round( (float) ( $it['duration'] ?? 0 ), 2 ),
			'desc'     => (string) ( $it['desc'] ?? '' ),
		);
	}
	return $map;
}

/* ---------------------------------------------------------------------
 * Pause gate — dipanggil pipeline SETELAH EDL tersusun, SEBELUM proyek
 * dibuat. $seg_plans: array seg_idx => {items, keeps, dur, label}.
 * Menyimpan konteks ke payload._review + kartu pesan role 'review'.
 * ------------------------------------------------------------------- */

function cc_edl_review_pause( $job, $session_id, &$p, $seg_plans, $catalog ) {
	$bmap     = cc_edl_catalog_map( $catalog['brolls'] ?? array() );
	$smap     = cc_edl_catalog_map( $catalog['sfxs'] ?? array() );
	$segs_meta = array();
	foreach ( $seg_plans as $gi => $plan ) {
		$items = array();
		foreach ( (array) ( $plan['items'] ?? array() ) as $it ) {
			$bid    = (string) ( $it['broll_id'] ?? '' );
			$binfo  = $bmap[ $bid ] ?? array();
			$bpath  = (string) ( $binfo['path'] ?? '' );
			$sid    = (string) ( $it['sfx_id'] ?? '' );
			$sinfo  = '' !== $sid ? ( $smap[ $sid ] ?? array() ) : array();
			$thumb  = '' !== $bpath ? cc_edl_thumb( $bpath, (float) ( $it['source_start'] ?? 0 ) + 0.5 ) : '';
			$items[] = array(
				'broll_id'  => $bid,
				'name'      => '' !== $bpath ? basename( $bpath ) : $bid,
				'tl_start'  => round( (float) ( $it['timeline_start'] ?? 0 ), 3 ),
				'dur'       => round( (float) ( $it['duration'] ?? 0 ), 3 ),
				'src_start' => round( (float) ( $it['source_start'] ?? 0 ), 3 ),
				'sfx_id'    => $sid,
				'sfx_name'  => ! empty( $sinfo['path'] ) ? basename( (string) $sinfo['path'] ) : '',
				'reason'    => (string) ( $it['reason'] ?? '' ),
				'video'     => '' !== $bpath ? cc_media_url( $bpath ) : '',
				'thumb'     => '' !== $thumb ? cc_media_url( $thumb ) : '',
			);
		}
		$segs_meta[] = array(
			'idx'   => (int) $gi,
			'label' => (string) ( $plan['label'] ?? 'Bagian ' . ( $gi + 1 ) ),
			'dur'   => round( (float) ( $plan['dur'] ?? 0 ), 2 ),
			'items' => $items,
		);
	}

	// Katalog untuk dropdown ganti-klip / SFX + audition di kartu.
	$cat_meta = array( 'brolls' => array(), 'sfxs' => array() );
	foreach ( $bmap as $bid => $b ) {
		$cat_meta['brolls'][] = array(
			'id'    => $bid,
			'name'  => $b['name'],
			'dur'   => $b['duration'],
			'video' => cc_media_url( $b['path'] ),
		);
	}
	foreach ( $smap as $sid => $s ) {
		$cat_meta['sfxs'][] = array(
			'id'    => $sid,
			'name'  => $s['name'],
			'dur'   => $s['duration'],
			'media' => cc_media_url( $s['path'] ),
		);
	}

	// Konteks resume — file/yt_srt/segs/katalog yang sudah resolve agar
	// resume tidak mengulang unduhan YouTube, whisper, scan, atau pick.
	$p['_review'] = array(
		'state'    => 'pending',
		'workflow' => (string) ( $p['workflow'] ?? 'broll' ),
		'file'     => (string) ( $p['file'] ?? '' ),
		'yt_srt'   => (string) ( $p['yt_srt'] ?? '' ),
		'catalog'  => $catalog,
		'segs'     => (array) ( $p['_segs'] ?? array() ),
		'seg_plans'=> $seg_plans,
	);

	$meta = array(
		'type'    => 'edl_review',
		'job_id'  => (int) $job->id,
		'state'   => 'pending',
		'title'   => 'Review rencana B-Roll — ' . (string) ( $p['name'] ?? 'proyek' ),
		'segs'    => $segs_meta,
		'catalog' => $cat_meta,
	);
	$n_items = 0;
	foreach ( $segs_meta as $sm ) {
		$n_items += count( $sm['items'] );
	}
	$card_id = cc_message_add(
		$session_id,
		'review',
		'Review rencana B-Roll (' . $n_items . ' sisipan)',
		$meta,
		'pending'
	);
	$p['_review']['card_id'] = (int) $card_id;

	// Settle placeholder + simpan konteks; status 'waiting' diset worker.
	cc_message_update(
		$job->message_id,
		array( 'body' => '⏸ Menunggu review rencana B-Roll — periksa kartu di atas.', 'status' => 'waiting' )
	);
	cc_job_update( $job->id, array( 'payload' => wp_json_encode( $p ), 'detail' => 'Menunggu review rencana B-Roll' ) );
	return true;
}

/**
 * Resume — bila payload punya _review.approved, injeksikan ke $p agar
 * pipeline melewati langkah AI (file/yt_srt/segs/katalog sudah resolve).
 * Mengembalikan true saat resume terdeteksi.
 */
function cc_edl_review_resume( &$p ) {
	$rev = (array) ( $p['_review'] ?? array() );
	if ( empty( $rev['approved'] ) ) {
		return false;
	}
	if ( ! empty( $rev['file'] ) && file_exists( (string) $rev['file'] ) ) {
		$p['file'] = (string) $rev['file'];
	}
	if ( ! empty( $rev['yt_srt'] ) && file_exists( (string) $rev['yt_srt'] ) ) {
		$p['yt_srt'] = (string) $rev['yt_srt'];
	}
	if ( ! empty( $rev['catalog'] ) ) {
		$p['_broll_catalog'] = $rev['catalog'];
	}
	if ( ! empty( $rev['segs'] ) ) {
		$p['_segs_resolved'] = $rev['segs'];
	}
	$p['_edl_resume'] = true;
	return true;
}

/* ---------------------------------------------------------------------
 * Validasi EDL hasil edit user — bentuk sama seperti output plan_edl:
 * {broll_id, timeline_start, duration, source_start, sfx_id, reason}.
 * ------------------------------------------------------------------- */

function cc_edl_validate_items( $items, $catalog, $seg_dur ) {
	$bmap = cc_edl_catalog_map( $catalog['brolls'] ?? array() );
	$smap = cc_edl_catalog_map( $catalog['sfxs'] ?? array() );
	$seg_dur = max( 0.5, (float) $seg_dur );
	$out = array();
	foreach ( array_slice( (array) $items, 0, 120 ) as $it ) {
		if ( ! is_array( $it ) ) {
			continue;
		}
		$bid = (string) ( $it['broll_id'] ?? '' );
		if ( ! isset( $bmap[ $bid ] ) ) {
			continue;
		}
		$bdur  = max( 0.5, (float) ( $bmap[ $bid ]['duration'] ?? 1 ) );
		$t_st  = min( max( 0.0, (float) ( $it['timeline_start'] ?? 0 ) ), $seg_dur - 0.3 );
		$s_st  = min( max( 0.0, (float) ( $it['source_start'] ?? 0 ) ), $bdur - 0.5 );
		$dur   = min( max( 0.3, (float) ( $it['duration'] ?? 3 ) ), $bdur - $s_st, $seg_dur - $t_st );
		if ( $dur < 0.3 ) {
			continue;
		}
		$sid = (string) ( $it['sfx_id'] ?? '' );
		if ( ! isset( $smap[ $sid ] ) ) {
			$sid = '';
		}
		$out[] = array(
			'broll_id'       => $bid,
			'timeline_start' => round( $t_st, 3 ),
			'duration'       => round( $dur, 3 ),
			'source_start'   => round( $s_st, 3 ),
			'sfx_id'         => $sid,
			'reason'         => sanitize_text_field( (string) ( $it['reason'] ?? '' ) ),
		);
	}
	usort( $out, function ( $a, $b ) { return $a['timeline_start'] <=> $b['timeline_start']; } );
	return $out;
}

/* ---------------------------------------------------------------------
 * REST handlers
 * ------------------------------------------------------------------- */

/**
 * POST /control/edl-review — keputusan user atas kartu review.
 * action: apply (items hasil edit), replan (susun ulang via AI), cancel.
 */
function cc_rest_edl_review( WP_REST_Request $request ) {
	$job = cc_job_get( absint( $request->get_param( 'job_id' ) ) );
	if ( ! $job || 'control_run' !== $job->kind || 'waiting' !== $job->status ) {
		return new WP_Error( 'cc_state', 'Job tidak sedang menunggu review.', array( 'status' => 409 ) );
	}
	$session = cc_session_get( $job->session_id );
	if ( ! cc_session_can_view( $session, get_current_user_id() ) ) {
		return new WP_Error( 'cc_forbidden', 'Job tidak ditemukan.', array( 'status' => 404 ) );
	}
	$p   = json_decode( (string) $job->payload, true );
	$rev = (array) ( $p['_review'] ?? array() );
	if ( empty( $rev['seg_plans'] ) ) {
		return new WP_Error( 'cc_state', 'Job ini tidak punya konteks review.', array( 'status' => 409 ) );
	}
	$action  = (string) $request->get_param( 'action' );
	$card_id = (int) ( $rev['card_id'] ?? 0 );

	if ( 'cancel' === $action ) {
		cc_job_cancel( $job->id );
		cc_message_update( $job->message_id, array( 'body' => 'Perintah dibatalkan oleh pengguna.', 'status' => 'failed' ) );
		if ( $card_id ) {
			cc_message_update( $card_id, array( 'status' => 'cancelled' ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'state' => 'cancelled' ) );
	}

	if ( 'replan' === $action ) {
		if ( 'cartoon' === (string) ( $rev['seg_plans'][0]['kind'] ?? '' ) && function_exists( 'cc_cartoon_plan_scene' ) ) {
			$note    = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
			$cat0    = (array) ( $rev['seg_plans'][0]['catalog'] ?? $rev['catalog'] ?? array() );
			$np      = array();
			foreach ( (array) $rev['seg_plans'][0]['scenes'] as $si => $sc ) {
				$sc['prompt'] = trim( (string) ( $sc['prompt'] ?? '' ) . ' ' . $note );
				$np[ $si ]    = cc_cartoon_plan_scene( $sc, $cat0['chars'], $cat0['sfx'], $cat0['brolls'], (string) ( $p['prompt'] ?? '' ) );
			}
			$rev['seg_plans'][0]['plans'] = $np;
			$ri = cc_cartoon_review_items( (array) $rev['seg_plans'][0]['scenes'], $np, $cat0['chars'] );
			$rev['seg_plans'][0]['items'] = $ri['items'];
			$p['_review'] = $rev;
			cc_job_update( $job->id, array( 'payload' => wp_json_encode( $p ) ) );
			cc_edl_review_pause( $job, $job->session_id, $p, $rev['seg_plans'], (array) $rev['catalog'] );
			return rest_ensure_response( array( 'ok' => true, 'state' => 'pending' ) );
		}
		if ( ! function_exists( 'cc_assembly_plan_edl' ) ) {
			return new WP_Error( 'cc_replan', 'Modul assembly tidak tersedia.', array( 'status' => 500 ) );
		}
		$note    = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$new_plans = array();
		foreach ( (array) $rev['seg_plans'] as $gi => $plan ) {
			$edl = cc_assembly_plan_edl(
				(string) ( $plan['srt'] ?? '' ),
				(float) ( $plan['dur'] ?? 10 ),
				(array) ( $rev['catalog']['brolls'] ?? array() ),
				(array) ( $rev['catalog']['sfxs'] ?? array() ),
				array(
					'workflow' => (string) ( $rev['workflow'] ?? 'broll' ),
					'pacing'   => (string) ( $p['broll_pacing'] ?? 'calm' ),
					'prompt'   => trim( (string) ( $p['broll_prompt'] ?? '' ) . ' ' . $note ),
				)
			);
			$plan['items'] = is_array( $edl ) ? $edl : array();
			$new_plans[ $gi ] = $plan;
		}
		$p['_review']['seg_plans'] = $new_plans;
		cc_job_update( $job->id, array( 'payload' => wp_json_encode( $p ) ) );
		// Segarkan kartu — bangun ulang meta items dari plan terbaru
		// (kartu baru diposting; kartu lama ditandai digantikan).
		cc_edl_review_pause( $job, $job->session_id, $p, $new_plans, (array) $rev['catalog'] );
		if ( $card_id && (int) $p['_review']['card_id'] !== $card_id ) {
			cc_message_update( $card_id, array( 'status' => 'done', 'meta' => array( 'state' => 'replaced' ) ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'state' => 'pending' ) );
	}

	// action 'apply' — validasi tiap item per segmen, lalu resume job.
	$submitted = $request->get_param( 'items' );
	if ( is_string( $submitted ) && '' !== trim( $submitted ) ) {
		$submitted = json_decode( wp_unslash( $submitted ), true );
	}
	if ( ! is_array( $submitted ) ) {
		$submitted = array();
	}
	$approved = array();
	$is_cartoon = 'cartoon' === (string) ( $rev['seg_plans'][0]['kind'] ?? '' );
	foreach ( (array) $rev['seg_plans'] as $gi => $plan ) {
		if ( $is_cartoon ) {
			// Item kartu hanya preview — plans/scenes dipakai apa adanya.
			$approved[ $gi ] = $plan;
			continue;
		}
		$seg_items    = isset( $submitted[ $gi ] ) ? (array) $submitted[ $gi ] : array();
		$plan['items'] = cc_edl_validate_items( $seg_items, (array) $rev['catalog'], (float) ( $plan['dur'] ?? 10 ) );
		$approved[ $gi ] = $plan;
	}
	$p['_review']['seg_plans'] = $approved;
	$p['_review']['approved']  = true;
	$p['_review']['state']     = 'applied';

	$total = 0;
	foreach ( $approved as $pl ) {
		$total += count( (array) $pl['items'] );
	}
	cc_message_update( $job->message_id, array( 'body' => '', 'status' => 'queued' ) );
	if ( $card_id ) {
		cc_message_update( $card_id, array( 'status' => 'done', 'body' => 'Rencana B-Roll disetujui (' . $total . ' sisipan)' ) );
	}
	cc_job_update( $job->id, array( 'status' => 'queued', 'payload' => wp_json_encode( $p ), 'detail' => 'Melanjutkan rakitan setelah review' ) );
	wp_schedule_single_event( time() + 1, 'cc_job_run', array( (int) $job->id ) );
	spawn_cron();
	return rest_ensure_response( array( 'ok' => true, 'state' => 'applied', 'items' => $total ) );
}

/** GET /media/stream — stream file media dengan Range support + HMAC. */
function cc_rest_media_stream( WP_REST_Request $request ) {
	$path = (string) base64_decode( (string) $request->get_param( 'f' ), true );
	$exp  = (int) $request->get_param( 'e' );
	$sig  = (string) $request->get_param( 's' );
	$norm = wp_normalize_path( $path );

	if ( $exp < time() ) {
		status_header( 410 );
		exit( 'Link kedaluwarsa.' );
	}
	if ( ! hash_equals( hash_hmac( 'sha256', $path . '|' . $exp, wp_salt( 'nonce' ) ), $sig )
		|| ! file_exists( $norm )
		|| ! CC_Tools::read_allowed( $norm ) ) {
		status_header( 403 );
		exit( 'Akses ditolak.' );
	}
	$mimes = array(
		'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
		'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg',
		'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
	);
	$ext = strtolower( pathinfo( $norm, PATHINFO_EXTENSION ) );
	if ( ! isset( $mimes[ $ext ] ) ) {
		status_header( 403 );
		exit( 'Tipe file tidak diizinkan.' );
	}

	$size  = filesize( $norm );
	$start = 0;
	$end   = $size - 1;
	$code  = 200;
	if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $rm ) ) {
		if ( '' !== $rm[1] ) {
			$start = (int) $rm[1];
		}
		if ( '' !== $rm[2] ) {
			$end = min( $end, (int) $rm[2] );
		} elseif ( '' === $rm[1] ) {
			$start = 0;
		}
		if ( $start > 0 || $end < $size - 1 ) {
			$code = 206;
		}
		$end = min( $end, $size - 1 );
	}
	$len = max( 0, $end - $start + 1 );

	while ( ob_get_level() ) {
		ob_end_clean();
	}
	status_header( $code );
	header( 'Content-Type: ' . $mimes[ $ext ] );
	header( 'Accept-Ranges: bytes' );
	header( 'Content-Length: ' . $len );
	header( 'Cache-Control: private, max-age=3600' );
	if ( 206 === $code ) {
		header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
	}
	$fh = fopen( $norm, 'rb' );
	if ( ! $fh ) {
		status_header( 500 );
		exit;
	}
	fseek( $fh, $start );
	$left = $len;
	while ( $left > 0 && ! feof( $fh ) ) {
		$chunk = fread( $fh, min( 8192, $left ) );
		if ( false === $chunk ) {
			break;
		}
		echo $chunk;
		$left -= strlen( $chunk );
		flush();
	}
	fclose( $fh );
	exit;
}
