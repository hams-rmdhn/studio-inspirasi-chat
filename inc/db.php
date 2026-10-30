<?php
/**
 * Data layer — cc_sessions / cc_messages / cc_jobs custom tables.
 * Created on after_switch_theme + self-healed on init (upgrades don't
 * re-run the activation hook). Same convention as Simple Checkout's
 * SC_DB.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CC_DB_VERSION', '1.0.3' );

function cc_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'cc_' . $name;
}

function cc_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$ses     = cc_table( 'sessions' );
	$msg     = cc_table( 'messages' );
	$job     = cc_table( 'jobs' );

	$sql = "CREATE TABLE {$ses} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		title varchar(200) NOT NULL DEFAULT '',
		project varchar(500) NOT NULL DEFAULT '',
		sort int(11) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		last_message_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY user_id (user_id)
	) {$charset};

	CREATE TABLE {$msg} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		session_id bigint(20) unsigned NOT NULL,
		role varchar(20) NOT NULL DEFAULT 'user',
		body longtext,
		meta longtext,
		status varchar(20) NOT NULL DEFAULT 'done',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY session_id (session_id),
		KEY status (status)
	) {$charset};

	CREATE TABLE {$job} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		session_id bigint(20) unsigned NOT NULL,
		message_id bigint(20) unsigned NOT NULL DEFAULT 0,
		kind varchar(30) NOT NULL DEFAULT 'agent_turn',
		status varchar(20) NOT NULL DEFAULT 'queued',
		payload longtext,
		progress tinyint(4) NOT NULL DEFAULT 0,
		detail varchar(255) NOT NULL DEFAULT '',
		attempts tinyint(4) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		started_at datetime DEFAULT NULL,
		updated_at datetime DEFAULT NULL,
		finished_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY session_id (session_id),
		KEY status (status)
	) {$charset};";

	dbDelta( $sql );
	update_option( 'cc_db_version', CC_DB_VERSION );
}

add_action( 'after_switch_theme', 'cc_install_tables' );

/* Self-heal — a site that already had the theme keeps working after
 * restores/upgrades that skip after_switch_theme. */
add_action(
	'init',
	function () {
		if ( CC_DB_VERSION !== get_option( 'cc_db_version' ) ) {
			cc_install_tables();
		}
	},
	5
);

/* ---------------------------------------------------------------------
 * Sessions
 * ------------------------------------------------------------------- */

function cc_session_create( $user_id, $title = '' ) {
	global $wpdb;
	$now = current_time( 'mysql' );
	$wpdb->insert(
		cc_table( 'sessions' ),
		array(
			'user_id'    => absint( $user_id ),
			'title'      => sanitize_text_field( $title ),
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%d', '%s', '%s', '%s' )
	);
	return (int) $wpdb->insert_id;
}

function cc_session_get( $id ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . cc_table( 'sessions' ) . ' WHERE id = %d', absint( $id ) )
	);
}

/** Sessions owned by a user, most recently active first. */
function cc_sessions_for_user( $user_id ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM ' . cc_table( 'sessions' ) . ' WHERE user_id = %d ORDER BY sort ASC, COALESCE(last_message_at, created_at) DESC, id DESC',
			absint( $user_id )
		)
	);
}

function cc_session_touch( $id, $last_message = true ) {
	global $wpdb;
	$data = array( 'updated_at' => current_time( 'mysql' ) );
	if ( $last_message ) {
		$data['last_message_at'] = $data['updated_at'];
	}
	$wpdb->update( cc_table( 'sessions' ), $data, array( 'id' => absint( $id ) ) );
}

function cc_session_rename( $id, $title ) {
	global $wpdb;
	$wpdb->update(
		cc_table( 'sessions' ),
		array( 'title' => sanitize_text_field( $title ), 'updated_at' => current_time( 'mysql' ) ),
		array( 'id' => absint( $id ) )
	);
}

/** Path proyek Studio Inspirasi yang terikat ke sesi ('' = belum ada). */
function cc_session_project_get( $id ) {
	$s = cc_session_get( $id );
	return $s ? (string) ( $s->project ?? '' ) : '';
}

/** Ikatkan proyek ke sesi — dipanggil worker setelah control_run non-clip selesai. */
function cc_session_project_set( $id, $path ) {
	global $wpdb;
	$wpdb->update(
		cc_table( 'sessions' ),
		array(
			'project'    => wp_normalize_path( (string) $path ),
			'updated_at' => current_time( 'mysql' ),
		),
		array( 'id' => absint( $id ) ),
		array( '%s', '%s' ),
		array( '%d' )
	);
}

/** Persist a manual sidebar order — ids in display order, positions spaced by 10. */
function cc_sessions_reorder( $user_id, $ordered_ids ) {
	global $wpdb;
	$pos = 10;
	foreach ( (array) $ordered_ids as $id ) {
		$wpdb->update(
			cc_table( 'sessions' ),
			array( 'sort' => $pos ),
			array( 'id' => absint( $id ), 'user_id' => absint( $user_id ) )
		);
		$pos += 10;
	}
}

function cc_session_delete( $id ) {
	global $wpdb;
	$id = absint( $id );
	$wpdb->delete( cc_table( 'messages' ), array( 'session_id' => $id ) );
	$wpdb->delete( cc_table( 'jobs' ), array( 'session_id' => $id ) );
	$wpdb->delete( cc_table( 'sessions' ), array( 'id' => $id ) );
}

/** Can this user open the session? Admin-only tool, but still scoped. */
function cc_session_can_view( $session, $user_id ) {
	return $session && (int) $session->user_id === (int) $user_id && user_can( $user_id, 'manage_options' );
}

/* ---------------------------------------------------------------------
 * Messages
 * ------------------------------------------------------------------- */

/**
 * $meta is stored as JSON (task details, video url, usage…).
 * $status: queued|running|done|failed — 'task' rows reuse it for their
 * step state.
 */
function cc_message_add( $session_id, $role, $body = '', $meta = null, $status = 'done' ) {
	global $wpdb;
	$ok = $wpdb->insert(
		cc_table( 'messages' ),
		array(
			'session_id' => absint( $session_id ),
			'role'       => in_array( $role, array( 'user', 'assistant', 'task', 'review' ), true ) ? $role : 'user',
			'body'       => (string) $body,
			'meta'       => null === $meta ? null : wp_json_encode( $meta ),
			'status'     => sanitize_key( $status ),
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( $ok ) {
		cc_session_touch( $session_id );
	}
	return $ok ? (int) $wpdb->insert_id : 0;
}

function cc_message_update( $id, $data ) {
	global $wpdb;
	if ( isset( $data['meta'] ) && ! is_string( $data['meta'] ) ) {
		$data['meta'] = wp_json_encode( $data['meta'] );
	}
	return $wpdb->update( cc_table( 'messages' ), $data, array( 'id' => absint( $id ) ) );
}

function cc_message_get( $id ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . cc_table( 'messages' ) . ' WHERE id = %d', absint( $id ) )
	);
}

/**
 * Latest page of messages (ascending order) + oldest id + has_more.
 * The UI windows the thread like the ticket system did.
 */
function cc_messages_page( $session_id, $before = 0, $limit = 40 ) {
	global $wpdb;
	$t  = cc_table( 'messages' );
	$w  = $before ? $wpdb->prepare( ' AND id < %d', absint( $before ) ) : '';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$t} WHERE session_id = %d {$w} ORDER BY id DESC LIMIT %d",
			absint( $session_id ),
			absint( $limit )
		)
	);
	$rows     = array_reverse( (array) $rows );
	$oldest   = $rows ? (int) $rows[0]->id : 0;
	$has_more = $oldest
		? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE session_id = %d AND id < %d", absint( $session_id ), $oldest ) )
		: false;
	return array( $rows, $oldest, $has_more );
}

/** Messages newer than $after_id (poll for fresh rows). */
function cc_messages_after( $session_id, $after_id ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT * FROM ' . cc_table( 'messages' ) . ' WHERE session_id = %d AND id > %d ORDER BY id ASC LIMIT 100',
			absint( $session_id ),
			absint( $after_id )
		)
	);
}

/** Specific message rows by id — for refreshing non-terminal cards. */
function cc_messages_by_ids( $ids ) {
	global $wpdb;
	$ids = array_filter( array_map( 'absint', (array) $ids ) );
	if ( ! $ids ) {
		return array();
	}
	$in = implode( ',', $ids );
	return $wpdb->get_results( 'SELECT * FROM ' . cc_table( 'messages' ) . " WHERE id IN ({$in}) ORDER BY id ASC" );
}

/** Full history for the worker's prompt (bounded). */
function cc_messages_for_session( $session_id, $limit = 300 ) {
	list( $rows ) = cc_messages_page( $session_id, 0, $limit );
	return $rows;
}

/* ---------------------------------------------------------------------
 * Jobs
 * ------------------------------------------------------------------- */

function cc_job_create( $session_id, $message_id, $payload = array(), $kind = 'agent_turn' ) {
	global $wpdb;
	$ok = $wpdb->insert(
		cc_table( 'jobs' ),
		array(
			'session_id' => absint( $session_id ),
			'message_id' => absint( $message_id ),
			'kind'       => in_array( $kind, array( 'agent_turn', 'control_run' ), true ) ? $kind : 'agent_turn',
			'status'     => 'queued',
			'payload'    => wp_json_encode( $payload ),
			'created_at' => current_time( 'mysql' ),
		),
		array( '%d', '%d', '%s', '%s', '%s', '%s' )
	);
	return $ok ? (int) $wpdb->insert_id : 0;
}

function cc_job_get( $id ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . cc_table( 'jobs' ) . ' WHERE id = %d', absint( $id ) )
	);
}

function cc_job_update( $id, $data ) {
	global $wpdb;
	if ( ! isset( $data['updated_at'] ) ) {
		$data['updated_at'] = current_time( 'mysql' );
	}
	return $wpdb->update( cc_table( 'jobs' ), $data, array( 'id' => absint( $id ) ) );
}

/**
 * Reap orphaned jobs: queued jobs never picked up (>10 min) or
 * running jobs with no progress write (>20 min) get failed, along
 * with their assistant placeholder — otherwise the UI locks forever.
 * Runs on init; it's a single indexed UPDATE.
 */
function cc_jobs_reap() {
	global $wpdb;
	$t = cc_table( 'jobs' );
	$m = cc_table( 'messages' );
	// Timestamps come from current_time('mysql') (WP timezone). MySQL NOW()
	// is server-local — with WP set to UTC that gap reaps fresh jobs
	// instantly. Compute cutoffs in PHP on the same clock instead.
	$cut_queued = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 600 );
	$cut_active = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 1200 );
	// Job 'waiting' (menunggu review EDL) diberi jendela lebih panjang —
	// 60 menit tanpa interaksi baru dianggap terbengkalai.
	$cut_wait   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 );
	$stale = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, session_id, message_id FROM {$t}
			 WHERE ( status = 'queued' AND created_at < %s )
			    OR ( status = 'running' AND COALESCE(updated_at, started_at, created_at) < %s )
			    OR ( status = 'waiting' AND COALESCE(updated_at, started_at, created_at) < %s )",
			$cut_queued,
			$cut_active,
			$cut_wait
		)
	);
	foreach ( (array) $stale as $j ) {
		$wpdb->update( $t, array( 'status' => 'failed', 'detail' => 'Job kedaluwarsa (tanpa kemajuan)', 'finished_at' => current_time( 'mysql' ) ), array( 'id' => $j->id ) );
		if ( $j->message_id ) {
			$wpdb->update( $m, array( 'status' => 'failed', 'body' => 'Maaf — proses terhenti di tengah jalan. Silakan coba lagi.' ), array( 'id' => $j->message_id, 'status' => 'queued' ) );
		}
		// Any still-spinning task cards in this session settle to failed —
		// both the column AND the meta the renderer reads. Kartu review
		// pending ikut digagalkan agar tidak menunggu selamanya.
		$wpdb->update( $m, array( 'status' => 'failed' ), array( 'session_id' => $j->session_id, 'role' => 'task', 'status' => 'running' ) );
		$wpdb->update( $m, array( 'status' => 'failed' ), array( 'session_id' => $j->session_id, 'role' => 'review', 'status' => 'pending' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$m} SET meta = REPLACE(meta, '\"status\":\"running\"', '\"status\":\"failed\"')
				 WHERE session_id = %d AND role = 'task' AND meta LIKE '%\"status\":\"running\"%'",
				(int) $j->session_id
			)
		);
	}
}

add_action( 'init', 'cc_jobs_reap', 8 );

/** Latest still-live job for a session (queued/running/waiting). */
function cc_job_active_for_session( $session_id ) {
	global $wpdb;
	return $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM " . cc_table( 'jobs' ) . " WHERE session_id = %d AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1",
			absint( $session_id )
		)
	);
}

/** Any live job at all — the composer locks while one is running. */
function cc_job_active_any() {
	global $wpdb;
	return $wpdb->get_row(
		"SELECT * FROM " . cc_table( 'jobs' ) . " WHERE status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1"
	);
}

function cc_job_cancel( $id ) {
	return cc_job_update(
		$id,
		array(
			'status'      => 'cancelled',
			'finished_at' => current_time( 'mysql' ),
		)
	);
}
