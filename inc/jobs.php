<?php
/**
 * Job queue + worker — one user message = one 'agent_turn' job run by
 * wp-cron (single event, +2s). The worker hosts the OpenAI
 * function-calling loop; every tool call is stamped into the thread as
 * a 'task' message so the UI shows each step live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue an agent turn for a session.
 * Creates the assistant placeholder message that the worker fills in.
 * Returns job id or WP_Error.
 */
function cc_job_enqueue( $session_id ) {
	// One live job at a time — Studio Inspirasi's dispatcher is serial anyway.
	if ( cc_job_active_any() ) {
		return new WP_Error( 'cc_busy', 'Masih ada perintah yang sedang diproses — tunggu atau batalkan dulu.' );
	}
	$placeholder = cc_message_add( $session_id, 'assistant', '', null, 'queued' );
	if ( ! $placeholder ) {
		return new WP_Error( 'cc_db', 'Gagal membuat pesan.' );
	}
	$job_id = cc_job_create( $session_id, $placeholder, array() );
	if ( ! $job_id ) {
		return new WP_Error( 'cc_db', 'Gagal membuat job.' );
	}
	wp_schedule_single_event( time() + 2, 'cc_job_run', array( $job_id ) );
	spawn_cron();
	return $job_id;
}

add_action( 'cc_job_run', 'cc_job_run_worker' );

function cc_job_run_worker( $job_id ) {
	$job = cc_job_get( $job_id );
	if ( ! $job || ! in_array( $job->status, array( 'queued', 'running' ), true ) ) {
		return;
	}
	ignore_user_abort( true );
	set_time_limit( 0 );
	cc_job_update(
		$job_id,
		array( 'status' => 'running', 'started_at' => current_time( 'mysql' ), 'attempts' => (int) $job->attempts + 1 )
	);

	$finish = function ( $status, $body, $meta = null ) use ( $job_id, $job ) {
		$upd = array( 'body' => $body, 'status' => 'done' === $status ? 'done' : 'failed' );
		if ( is_array( $meta ) && $meta ) {
			$upd['meta'] = $meta;
		}
		cc_message_update( $job->message_id, $upd );
		cc_job_update( $job_id, array( 'status' => $status, 'finished_at' => current_time( 'mysql' ) ) );
	};

	try {
		if ( 'control_run' === $job->kind ) {
			$res = cc_control_run( $job );
			if ( is_wp_error( $res ) ) {
				$finish( 'failed', 'Maaf — ' . $res->get_error_message() );
			} elseif ( ! empty( $res['_waiting'] ) ) {
				// Gerbang review EDL — payload + kartu sudah disiapkan;
				// tahan job sampai user memutuskan lewat /control/edl-review.
				cc_job_update( $job_id, array( 'status' => 'waiting' ) );
			} else {
				$finish( 'done', (string) ( $res['body'] ?? '' ), $res['meta'] ?? null );
				// Bind proyek hasil rakitan ke sesi — workflow 'clip' sengaja
				// tidak bind (multi-proyek per run, bukan 1 proyek kerja).
				$pl = json_decode( (string) $job->payload, true );
				$wf = is_array( $pl ) ? (string) ( $pl['workflow'] ?? 'clip' ) : 'clip';
				if ( 'clip' !== $wf && ! empty( $res['meta']['project'] ) && function_exists( 'cc_session_project_set' ) ) {
					cc_session_project_set( $job->session_id, (string) $res['meta']['project'] );
				}
			}
			return;
		}
		$body = cc_agent_turn( $job );
		is_wp_error( $body ) ? $finish( 'failed', 'Maaf — ' . $body->get_error_message() ) : $finish( 'done', $body );
	} catch ( Throwable $e ) {
		$finish( 'failed', 'Maaf — terjadi kesalahan: ' . $e->getMessage() );
	}
}

/**
 * The agent loop. Returns final assistant text or WP_Error.
 */
function cc_agent_turn( $job ) {
	if ( ! cc_openai_configured() ) {
		return new WP_Error( 'cc_no_key', 'API key OpenAI belum diatur — buka Studio Inspirasi Chat → Settings.' );
	}

	// Conversation = last non-task messages of the session, newest user
	// message last. Bodies are clipped so the prompt stays bounded.
	$prompt = cc_openai_prompt();
	// Sesi dengan proyek terikat (hasil control_run): lifecycle proyek
	// diurus sistem — AI tidak lagi memanggil open/save/close sendiri.
	$bound  = function_exists( 'cc_session_project_get' ) ? cc_session_project_get( $job->session_id ) : '';
	if ( '' !== $bound ) {
		$prompt .= "\n\nPROYEK TERIKAT: {$bound} — semua perintah edit di sesi ini menargetkan proyek ini. "
			. 'JANGAN panggil project_open/project_save/project_close — sistem membuka, menyimpan, dan menutupnya otomatis. '
			. 'Parameter project pada tool edit boleh dikosongkan; sistem mengisinya dengan proyek terikat.';
	}
	$messages = array( array( 'role' => 'system', 'content' => $prompt ) );
	$rows     = cc_messages_for_session( $job->session_id );
	$history  = array();
	foreach ( $rows as $m ) {
		// 'task' = kartu progres, 'review' = kartu EDL — bukan pesan untuk AI.
		if ( 'task' === $m->role || 'review' === $m->role || 'queued' === $m->status || 'waiting' === $m->status ) {
			continue;
		}
		$clip = mb_strlen( $m->body ) > 1500 ? mb_substr( $m->body, 0, 1500 ) . '…' : $m->body;
		if ( '' !== trim( $clip ) ) {
			$history[] = array( 'role' => $m->role, 'content' => $clip );
		}
	}
	$messages = array_merge( $messages, array_slice( $history, -20 ) );

	// Shared context for CC_Tools.
	$cx  = new CC_Concat();
	$ctx = array(
		'job_id'     => (int) $job->id,
		'session_id' => (int) $job->session_id,
		'cx'         => $cx,
		'task_cb'    => null, // bound per tool call below
		'_bound'     => array(), // proyek terikat yang dibuka turn ini
	);

	try {
		return cc_agent_turn_loop( $job, $messages, $ctx );
	} finally {
		// Proyek terikat yang dibuka wrapper tool — simpan lalu tutup
		// agar lock "Remote API" lepas, apa pun hasil turn ini.
		CC_Tools::bound_release( $ctx );
		$cx->close();
	}
}

/** Isi agent loop — dipisah agar caller bisa finally-release proyek terikat. */
function cc_agent_turn_loop( $job, $messages, &$ctx ) {
	$max    = cc_max_iter();
	$tokens = 0;
	$final  = '';

	for ( $i = 0; $i < $max; $i++ ) {
		// Honour a cancel request between rounds.
		$fresh = cc_job_get( $job->id );
		if ( ! $fresh || 'cancelled' === $fresh->status ) {
			return new WP_Error( 'cc_cancelled', 'Perintah dibatalkan.' );
		}

		$res = CC_OpenAI::chat( $messages, CC_Tools::definitions() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$tokens  += (int) ( $res['usage']['total_tokens'] ?? 0 );
		$msg      = $res['message'];
		$messages[] = cc_agent_assistant_row( $msg );

		$calls = $msg['tool_calls'] ?? array();
		if ( ! $calls ) {
			$final = trim( (string) ( $msg['content'] ?? '' ) );
			break;
		}

		foreach ( $calls as $tc ) {
			$name = $tc['function']['name'] ?? '';
			$args = json_decode( (string) ( $tc['function']['arguments'] ?? '' ), true );

			// Stamp a task card for this step.
			$label   = cc_task_label( $name, is_array( $args ) ? $args : array() );
			$task_id = cc_message_add(
				$job->session_id,
				'task',
				$label,
				array( 'tool' => $name, 'status' => 'running', 'progress' => 0 ),
				'running'
			);

			// Progress events from long-running tools (export) feed the card.
			$ctx['task_cb'] = function ( $kind, $params ) use ( $task_id, $job ) {
				if ( 'export_progress' === $kind ) {
					$pct = ! empty( $params['total'] ) ? min( 99, (int) round( 100 * $params['frame'] / max( 1, $params['total'] ) ) ) : 0;
					cc_message_update( $task_id, array( 'meta' => array( 'tool' => 'export_run', 'status' => 'running', 'progress' => $pct ) ) );
					cc_job_update( $job->id, array( 'progress' => $pct, 'detail' => 'Export ' . ( $params['stage'] ?? 'video' ) . ' ' . $pct . '%' ) );
				}
			};

			$out = CC_Tools::run( $name, is_array( $args ) ? $args : array(), $ctx );
			$bad = 0 === strpos( $out, 'ERROR' );

			cc_message_update(
				$task_id,
				array(
					'status' => $bad ? 'failed' : 'done',
					'meta'   => array( 'tool' => $name, 'status' => $bad ? 'failed' : 'done', 'progress' => 100 ),
				)
			);
			if ( ! empty( $job->id ) ) {
				cc_job_update( $job->id, array( 'detail' => mb_substr( $label, 0, 250 ) ) );
			}

			$messages[] = array(
				'role'         => 'tool',
				'tool_call_id' => $tc['id'],
				'content'      => (string) $out,
			);
		}
	}

	if ( '' === $final ) {
		return new WP_Error( 'cc_iter', "AI tidak menghasilkan jawaban akhir dalam {$max} iterasi — coba perintah yang lebih spesifik." );
	}
	return $final;
}

/** Wire-format assistant row (content + tool_calls preserved). */
function cc_agent_assistant_row( $msg ) {
	$row = array(
		'role'    => 'assistant',
		'content' => isset( $msg['content'] ) ? (string) $msg['content'] : '',
	);
	if ( ! empty( $msg['tool_calls'] ) ) {
		$row['tool_calls'] = $msg['tool_calls'];
	}
	return $row;
}

/** Human-readable label for a task card. */
function cc_task_label( $tool, $args ) {
	$map = array(
		'concat_status'  => 'Memeriksa koneksi Studio Inspirasi',
		'list_projects'  => 'Membaca daftar proyek',
		'project_open'   => 'Membuka proyek',
		'project_save'   => 'Menyimpan proyek',
		'project_close'  => 'Menutup proyek',
		'set_video'      => 'Mengatur ukuran video',
		'template_list'  => 'Membaca daftar template',
		'template_instantiate' => 'Membuat proyek dari template',
		'template_save'  => 'Menyimpan template',
		'replace_clip_media' => 'Mengganti media ' . basename( (string) ( $args['file'] ?? 'clip' ) ),
		'control_run'    => 'Menjalankan pipeline control (' . ( 'moments' === ( $args['mode'] ?? '' ) ? 'momen AI' : 'manual' ) . ')',
		'list_folder'    => 'Memindai folder ' . basename( (string) ( $args['path'] ?? '' ) ),
		'probe_file'     => 'Membaca info file ' . basename( (string) ( $args['path'] ?? '' ) ),
		'create_project' => 'Membuat proyek "' . ( $args['name'] ?? '' ) . '"',
		'import_media'   => 'Mengimpor ' . basename( (string) ( $args['file'] ?? 'media' ) ),
		'timeline_read'  => 'Membaca timeline',
		'edit_apply'     => 'Menerapkan ' . count( (array) ( $args['commands'] ?? array() ) ) . ' perintah edit',
		'export_run'     => 'Merender ' . ( $args['output_name'] ?? 'video' ),
		'export_status'  => 'Memeriksa status export',
		'copy_result'    => 'Menyalin hasil ke ' . basename( (string) ( $args['dest_dir'] ?? 'tujuan' ) ),
	);
	return $map[ $tool ] ?? 'Menjalankan ' . $tool;
}
