<?php
/**
 * REST API — namespace concat/v1. Every route requires manage_options
 * + X-WP-Nonce (wp_rest). Admin-only app, but sessions stay scoped to
 * their owner anyway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'cc_register_routes' );

function cc_rest_perm() {
	return current_user_can( 'manage_options' );
}

function cc_register_routes() {
	$routes = array(
		array( '/status', 'GET', 'cc_rest_status' ),
		array( '/sessions', 'GET', 'cc_rest_sessions' ),
		array( '/sessions', 'POST', 'cc_rest_session_create' ),
		array( '/sessions/reorder', 'POST', 'cc_rest_sessions_reorder' ),
		array( '/sessions/(?P<id>\d+)/rename', 'POST', 'cc_rest_session_rename' ),
		array( '/sessions/(?P<id>\d+)', 'DELETE', 'cc_rest_session_delete' ),
		array( '/sessions/(?P<id>\d+)/messages', 'GET', 'cc_rest_messages' ),
		array( '/sessions/(?P<id>\d+)/messages', 'POST', 'cc_rest_message_send' ),
		array( '/jobs/(?P<id>\d+)/cancel', 'POST', 'cc_rest_job_cancel' ),
		array( '/control/templates', 'GET', 'cc_rest_control_templates' ),
		array( '/control/folder', 'GET', 'cc_rest_control_folder' ),
		array( '/control/run', 'POST', 'cc_rest_control_run' ),
		array( '/settings', 'GET', 'cc_rest_settings_get' ),
		array( '/settings', 'POST', 'cc_rest_settings_save' ),
		array( '/settings/test', 'POST', 'cc_rest_settings_test' ),
		array( '/settings/templates', 'GET', 'cc_rest_templates_list' ),
		array( '/settings/templates/rename', 'POST', 'cc_rest_templates_rename' ),
		array( '/settings/templates/delete', 'POST', 'cc_rest_templates_delete' ),
	);
	foreach ( $routes as $r ) {
		register_rest_route(
			'concat/v1',
			$r[0],
			array(
				'methods'             => $r[1],
				'callback'            => $r[2],
				'permission_callback' => 'cc_rest_perm',
			)
		);
	}
}

/** Owned session or 404-shaped error (don't leak existence). */
function cc_rest_session( WP_REST_Request $request ) {
	$session = cc_session_get( $request['id'] );
	if ( ! cc_session_can_view( $session, get_current_user_id() ) ) {
		return new WP_Error( 'cc_forbidden', 'Sesi tidak ditemukan.', array( 'status' => 404 ) );
	}
	return $session;
}

/* --------------------------------------------------------------------- */

function cc_rest_status() {
	// Cache the probe briefly — Studio Inspirasi's socket accepts a single client,
	// and while the export worker holds it every probe() here would block
	// ~15s, saturating the remaining php-cgi worker and freezing the UI.
	global $wpdb;
	$busy   = (bool) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . cc_table( 'jobs' ) . " WHERE status IN ('running','waiting')" );
	$cached = get_transient( 'cc_status_probe' );
	if ( is_array( $cached ) && ( $busy || ( $cached['ts'] ?? 0 ) > time() - 15 ) ) {
		unset( $cached['ts'] );
		return rest_ensure_response( $cached );
	}
	$concat = CC_Concat::probe();
	$out    = array(
		'openai' => array(
			'configured' => cc_openai_configured(),
			'model'      => cc_openai_model(),
		),
		'concat' => array( 'ok' => false ),
	);
	if ( is_wp_error( $concat ) ) {
		$out['concat']['error'] = $concat->get_error_message();
	} else {
		$out['concat'] = array(
			'ok'           => true,
			'apiVersion'   => $concat['apiVersion'] ?? '',
			'concat'       => $concat['concat'] ?? '',
			'capabilities' => $concat['capabilities'] ?? array(),
			'mode'         => cc_concat_mode(),
		);
	}
	$out['ts'] = time();
	set_transient( 'cc_status_probe', $out, $busy ? 1800 : 60 );
	unset( $out['ts'] );
	return rest_ensure_response( $out );
}

function cc_rest_sessions() {
	$items = array();
	foreach ( cc_sessions_for_user( get_current_user_id() ) as $s ) {
		$active  = cc_job_active_for_session( $s->id );
		$items[] = array(
			'id'              => (int) $s->id,
			'title'           => $s->title ?: 'Percakapan baru',
			'last_message_at' => $s->last_message_at,
			'active'          => (bool) $active,
		);
	}
	return rest_ensure_response( array( 'items' => $items ) );
}

function cc_rest_session_create( WP_REST_Request $request ) {
	$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
	$id    = cc_session_create( get_current_user_id(), $title );
	return rest_ensure_response( array( 'id' => $id, 'title' => $title ?: 'Percakapan baru' ) );
}

function cc_rest_sessions_reorder( WP_REST_Request $request ) {
	$ids = $request->get_param( 'ids' );
	if ( ! is_array( $ids ) || ! $ids ) {
		return new WP_Error( 'cc_order', 'Parameter ids wajib berupa array.', array( 'status' => 400 ) );
	}
	cc_sessions_reorder( get_current_user_id(), array_map( 'absint', $ids ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

function cc_rest_session_rename( WP_REST_Request $request ) {
	$session = cc_rest_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );
	if ( '' === $title || strlen( $title ) > 200 ) {
		return new WP_Error( 'cc_title', 'Judul wajib diisi (maks. 200 karakter).', array( 'status' => 400 ) );
	}
	cc_session_rename( $session->id, $title );
	return rest_ensure_response( array( 'ok' => true, 'title' => $title ) );
}

function cc_rest_session_delete( WP_REST_Request $request ) {
	$session = cc_rest_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	if ( cc_job_active_for_session( $session->id ) ) {
		return new WP_Error( 'cc_busy', 'Masih ada pekerjaan berjalan di sesi ini — batalkan dulu.', array( 'status' => 409 ) );
	}
	cc_session_delete( $session->id );
	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Windowed message list:
 *   default            → latest page (40) + oldest_id + has_more
 *   ?before={id}       → older page above it (lazy load on scroll-up)
 *   ?after={id}&watch={csv} → new rows + refreshed rows for live cards
 */
function cc_rest_messages( WP_REST_Request $request ) {
	$session = cc_rest_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$before = absint( $request->get_param( 'before' ) );
	$after  = absint( $request->get_param( 'after' ) );
	$watch  = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'watch' ) ) ) );

	$items    = array();
	$updates  = array();
	$oldest   = 0;
	$has_more = false;

	if ( $before ) {
		list( $rows, $oldest, $has_more ) = cc_messages_page( $session->id, $before );
		foreach ( $rows as $m ) {
			$items[] = cc_message_to_json( $m );
		}
	} elseif ( $after ) {
		foreach ( cc_messages_after( $session->id, $after ) as $m ) {
			$items[] = cc_message_to_json( $m );
		}
		foreach ( cc_messages_by_ids( $watch ) as $m ) {
			$updates[] = cc_message_to_json( $m );
		}
	} else {
		list( $rows, $oldest, $has_more ) = cc_messages_page( $session->id );
		foreach ( $rows as $m ) {
			$items[] = cc_message_to_json( $m );
		}
	}

	$active = cc_job_active_for_session( $session->id );
	return rest_ensure_response(
		array(
			'items'         => $items,
			'updates'       => $updates,
			'oldest_id'     => $oldest,
			'has_more'      => $has_more,
			'title'         => $session->title,
			'has_active'    => (bool) $active,
			'active_job_id' => $active ? (int) $active->id : 0,
			'job_detail'    => $active ? (string) $active->detail : '',
		)
	);
}

function cc_rest_message_send( WP_REST_Request $request ) {
	$session = cc_rest_session( $request );
	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$body = cc_clean_body( $request->get_param( 'body' ) ?? '' );
	if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
		return new WP_Error( 'cc_empty', 'Pesan tidak boleh kosong.', array( 'status' => 400 ) );
	}
	if ( strlen( $body ) > 20000 ) {
		return new WP_Error( 'cc_long', 'Pesan terlalu panjang.', array( 'status' => 400 ) );
	}

	$msg_id = cc_message_add( $session->id, 'user', $body );
	if ( ! $msg_id ) {
		return new WP_Error( 'cc_db', 'Gagal menyimpan pesan.', array( 'status' => 500 ) );
	}

	// Auto-title a fresh session from its first command.
	if ( '' === trim( (string) $session->title ) ) {
		cc_session_rename( $session->id, mb_substr( trim( wp_strip_all_tags( $body ) ), 0, 60 ) );
	}

	$job = cc_job_enqueue( $session->id );
	if ( is_wp_error( $job ) ) {
		return new WP_Error( $job->get_error_code(), $job->get_error_message(), array( 'status' => 429 ) );
	}

	return rest_ensure_response(
		array(
			'ok'         => true,
			'message_id' => $msg_id,
			'job_id'     => $job,
		)
	);
}

/**
 * Safe settings view for the front-end modal — secrets never leave the
 * server; only *_set flags travel.
 */
function cc_rest_settings_get() {
	$auto = cc_automation_read();
	return rest_ensure_response(
		array(
			'ai_provider'     => function_exists( 'cc_ai_provider' ) ? cc_ai_provider() : 'openai',
			'ai_providers'    => function_exists( 'cc_ai_providers' ) ? cc_ai_providers() : array(),
			'ai_model'        => function_exists( 'cc_ai_model' ) ? cc_ai_model() : cc_openai_model(),
			'ai_baseurl'      => get_option( 'cc_ai_baseurl', '' ),
			'ai_key_set'      => cc_openai_configured(),
			'openai_model'    => get_option( 'cc_openai_model', 'gpt-4o-mini' ),
			'openai_prompt'   => get_option( 'cc_openai_prompt', '' ),
			'openai_key_set'  => cc_openai_configured(),
			'concat_mode'     => cc_concat_mode(),
			'concat_addr'     => get_option( 'cc_concat_addr', '127.0.0.1:7420' ),
			'concat_token_set' => '' !== cc_concat_token_opt(),
			'workspace'       => get_option( 'cc_workspace', cc_workspace() ),
			'copy_roots'      => get_option( 'cc_copy_roots', '' ),
			'max_iter'        => cc_max_iter(),
			'automation'      => is_wp_error( $auto )
				? array( 'ok' => false, 'error' => $auto->get_error_message() )
				: array( 'ok' => true, 'address' => $auto['address'] ),
		)
	);
}

function cc_rest_settings_save( WP_REST_Request $request ) {
	$in = array();
	foreach ( array( 'openai_model', 'openai_prompt', 'concat_mode', 'concat_addr', 'workspace', 'copy_roots', 'max_iter', 'openai_key', 'concat_token', 'ai_provider', 'ai_model', 'ai_key', 'ai_baseurl' ) as $f ) {
		$v = $request->get_param( $f );
		if ( null !== $v ) {
			$in[ $f ] = $v;
		}
	}
	cc_settings_apply( $in );
	return rest_ensure_response( array( 'ok' => true ) );
}

function cc_rest_settings_test( WP_REST_Request $request ) {
	$which = sanitize_key( $request->get_param( 'which' ) );
	if ( 'openai' === $which ) {
		$res = CC_OpenAI::ping();
	} elseif ( 'concat' === $which ) {
		$res = CC_Concat::probe();
	} else {
		return new WP_Error( 'cc_which', 'Parameter which wajib openai|concat.', array( 'status' => 400 ) );
	}
	if ( is_wp_error( $res ) ) {
		return rest_ensure_response( array( 'ok' => false, 'message' => $res->get_error_message() ) );
	}
	$msg = 'concat' === $which
		? 'API ' . ( $res['apiVersion'] ?? '?' ) . ' · Studio Inspirasi ' . ( $res['concat'] ?? '?' ) . ' · caps: ' . implode( ',', (array) ( $res['capabilities'] ?? array() ) )
		: (string) $res;
	return rest_ensure_response( array( 'ok' => true, 'message' => $msg ) );
}

/* ---------------------------------------------------------------------
 * Template management (rename/delete folder di disk app)
 * ------------------------------------------------------------------- */

/** Folder templates milik app aktif (dari automation.json dir). */
function cc_templates_dir() {
	$auto = cc_automation_path();
	if ( ! $auto ) {
		return '';
	}
	$dir = trailingslashit( dirname( $auto ) ) . 'templates';
	return is_dir( $dir ) ? trailingslashit( $dir ) : '';
}

function cc_rest_templates_list() {
	$dir  = cc_templates_dir();
	$out  = array();
	$list = cc_control_templates();
	if ( ! is_wp_error( $list ) ) {
		foreach ( (array) $list as $t ) {
			$out[] = array( 'name' => basename( (string) ( $t['path'] ?? $t ) ), 'path' => (string) ( $t['path'] ?? $t ) );
		}
	}
	return rest_ensure_response( array( 'templates' => $out, 'dir' => $dir ) );
}

/** Validasi path benar-benar di dalam folder templates app. */
function cc_template_safe( $path ) {
	$dir  = cc_templates_dir();
	$path = untrailingslashit( wp_normalize_path( (string) $path ) );
	if ( '' === $dir || '' === $path || 0 !== strpos( $path, untrailingslashit( $dir ) . '/' ) || false !== strpos( $path, '..' ) ) {
		return new WP_Error( 'cc_tpl_safe', 'Path template tidak valid.' );
	}
	return $path;
}

function cc_rest_templates_rename( WP_REST_Request $request ) {
	$path = cc_template_safe( $request->get_param( 'path' ) );
	$name = sanitize_file_name( (string) $request->get_param( 'name' ) );
	if ( is_wp_error( $path ) ) {
		return $path;
	}
	if ( '' === $name || basename( $path ) === $name ) {
		return new WP_Error( 'cc_tpl_name', 'Nama template kosong/sama.' );
	}
	$new = untrailingslashit( cc_templates_dir() ) . '/' . $name;
	if ( file_exists( $new ) ) {
		return new WP_Error( 'cc_tpl_dup', 'Template dengan nama itu sudah ada.' );
	}
	if ( ! @rename( $path, $new ) ) {
		return new WP_Error( 'cc_tpl_rename', 'Gagal rename template.' );
	}
	return rest_ensure_response( array( 'ok' => true, 'path' => $new ) );
}

function cc_rest_templates_delete( WP_REST_Request $request ) {
	$path = cc_template_safe( $request->get_param( 'path' ) );
	if ( is_wp_error( $path ) ) {
		return $path;
	}
	// Hapus rekursif — hanya di dalam folder templates (sudah divalidasi).
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
	@rmdir( $path );
	return rest_ensure_response( array( 'ok' => ! file_exists( $path ) ) );
}

/* ---------------------------------------------------------------------
 * Control mode
 * ------------------------------------------------------------------- */

function cc_rest_control_templates() {
	$tpl = cc_control_templates();
	if ( is_wp_error( $tpl ) ) {
		return new WP_Error( $tpl->get_error_code(), $tpl->get_error_message(), array( 'status' => 502 ) );
	}
	return rest_ensure_response( array( 'items' => array_values( (array) $tpl ) ) );
}

function cc_rest_control_folder( WP_REST_Request $request ) {
	$path = (string) $request->get_param( 'path' );
	if ( '' === trim( $path ) ) {
		$path = cc_workspace();
	}
	$out = cc_control_folder( $path );
	if ( is_wp_error( $out ) ) {
		return new WP_Error( $out->get_error_code(), $out->get_error_message(), array( 'status' => 400 ) );
	}
	return rest_ensure_response( $out );
}

function cc_rest_control_run( WP_REST_Request $request ) {
	$p = array(
		'name'          => sanitize_file_name( (string) $request->get_param( 'name' ) ),
		'template'      => (string) $request->get_param( 'template' ),
		'template_label' => sanitize_text_field( (string) $request->get_param( 'template_label' ) ),
		'file'          => wp_normalize_path( (string) $request->get_param( 'file' ) ),
		'start'         => (float) $request->get_param( 'start' ),
		'end'           => (float) $request->get_param( 'end' ),
		'action'        => 'export' === $request->get_param( 'action' ) ? 'export' : 'handoff',
		'output_name'   => sanitize_file_name( (string) $request->get_param( 'output_name' ) ),
		'dest_dir'      => wp_normalize_path( (string) $request->get_param( 'dest_dir' ) ),
		'aspect'        => (string) $request->get_param( 'aspect' ),
		'srt'           => wp_normalize_path( (string) $request->get_param( 'srt' ) ),
		'cap_lang'      => (string) $request->get_param( 'cap_lang' ),
		'mode'          => (string) $request->get_param( 'mode' ),
		'session_id'    => (int) $request->get_param( 'session_id' ),
		'moment_count'  => (int) $request->get_param( 'moment_count' ),
		'moment_min'    => (int) $request->get_param( 'moment_min' ),
		'moment_max'    => (int) $request->get_param( 'moment_max' ),
		'segments'      => array(),
	);
	if ( ! in_array( $p['cap_lang'], array( 'id', 'en', 'auto', 'none' ), true ) ) {
		$p['cap_lang'] = 'id';
	}
	if ( ! in_array( $p['mode'], array( 'manual', 'moments' ), true ) ) {
		$p['mode'] = 'manual';
	}
	$p['moment_count'] = max( 1, min( 10, $p['moment_count'] ?: 3 ) );
	$p['moment_min']   = max( 5, $p['moment_min'] ?: 30 );
	$p['moment_max']   = max( $p['moment_min'] + 5, $p['moment_max'] ?: 90 );
	$raw_segs = $request->get_param( 'segments' );
	if ( is_string( $raw_segs ) && '' !== trim( $raw_segs ) ) {
		$decoded = json_decode( wp_unslash( $raw_segs ), true );
		if ( is_array( $decoded ) ) {
			$raw_segs = $decoded;
		}
	}
	foreach ( (array) $raw_segs as $s ) {
		if ( ! is_array( $s ) ) {
			continue;
		}
		$s_start = (float) ( $s['start'] ?? -1 );
		$s_end   = (float) ( $s['end'] ?? -1 );
		if ( $s_start >= 0 && $s_end > $s_start ) {
			$p['segments'][] = array( 'start' => $s_start, 'end' => $s_end );
		}
	}
	if ( ! $p['segments'] ) {
		$p['segments'][] = array( 'start' => $p['start'], 'end' => $p['end'] );
	}

	if ( '' === $p['name'] ) {
		return new WP_Error( 'cc_name', 'Nama proyek wajib diisi.', array( 'status' => 400 ) );
	}
	if ( '' !== $p['template'] ) {
		$tpl   = cc_control_templates();
		$valid = false;
		if ( ! is_wp_error( $tpl ) ) {
			foreach ( (array) $tpl as $t ) {
				if ( ( $t['path'] ?? '' ) === $p['template'] ) {
					$valid = true;
					if ( '' === $p['template_label'] ) {
						$p['template_label'] = (string) ( $t['name'] ?? '' );
					}
				}
			}
		}
		if ( ! $valid ) {
			return new WP_Error( 'cc_template', 'Template tidak ditemukan di daftar Studio Inspirasi.', array( 'status' => 400 ) );
		}
	}
	if ( cc_control_is_yt( $p['file'] ) ) {
		// URL YouTube — file di-resolve saat worker (download ke inbox).
	} elseif ( '' === $p['file'] || ! file_exists( $p['file'] ) ) {
		return new WP_Error( 'cc_file', 'File video tidak ditemukan: ' . $p['file'], array( 'status' => 400 ) );
	} elseif ( ! CC_Tools::read_allowed( $p['file'] ) ) {
		return new WP_Error( 'cc_path', 'File di luar folder yang diizinkan.', array( 'status' => 400 ) );
	}
	if ( ! array_key_exists( $p['aspect'], cc_aspect_dims() ) ) {
		$p['aspect'] = '9:16';
	}
	if ( '' !== $p['srt'] ) {
		if ( ! file_exists( $p['srt'] ) || ! CC_Tools::read_allowed( $p['srt'] ) ) {
			return new WP_Error( 'cc_srt', 'File .srt tidak ditemukan atau di luar folder yang diizinkan.', array( 'status' => 400 ) );
		}
		if ( 'srt' !== strtolower( pathinfo( $p['srt'], PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'cc_srt', 'Subtitle harus berupa file .srt.', array( 'status' => 400 ) );
		}
	}
	if ( $p['start'] < 0 || $p['end'] <= $p['start'] ) {
		return new WP_Error( 'cc_segment', 'Segmen tidak valid — selesai harus lebih besar dari mulai.', array( 'status' => 400 ) );
	}
	if ( '' !== $p['dest_dir'] && ! CC_Tools::write_allowed_dir( $p['dest_dir'] ) ) {
		return new WP_Error( 'cc_path', 'Folder tujuan di luar daftar yang diizinkan — atur di Settings.', array( 'status' => 400 ) );
	}
	if ( 'export' === $p['action'] && '' === $p['output_name'] ) {
		$p['output_name'] = $p['name'] . '.mp4';
	}

	$res = cc_control_enqueue( $p );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 429 ) );
	}
	return rest_ensure_response( array( 'ok' => true ) + $res );
}

function cc_rest_job_cancel( WP_REST_Request $request ) {
	$job = cc_job_get( $request['id'] );
	if ( ! $job ) {
		return new WP_Error( 'cc_missing', 'Job tidak ditemukan.', array( 'status' => 404 ) );
	}
	$session = cc_session_get( $job->session_id );
	if ( ! cc_session_can_view( $session, get_current_user_id() ) ) {
		return new WP_Error( 'cc_forbidden', 'Job tidak ditemukan.', array( 'status' => 404 ) );
	}
	if ( ! in_array( $job->status, array( 'queued', 'running', 'waiting' ), true ) ) {
		return rest_ensure_response( array( 'ok' => true, 'status' => $job->status ) );
	}
	cc_job_cancel( $job->id );
	cc_message_update( $job->message_id, array( 'body' => 'Perintah dibatalkan oleh pengguna.', 'status' => 'failed' ) );
	return rest_ensure_response( array( 'ok' => true, 'status' => 'cancelled' ) );
}
