<?php
/**
 * Front-page helpers — anything the template needs that isn't REST.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Quick connectivity summary for the sidebar badge (cached 20s). */
function cc_sidebar_status() {
	$cached = get_transient( 'cc_side_status' );
	if ( false !== $cached ) {
		return $cached;
	}
	$status = array(
		'openai' => cc_openai_configured(),
		'concat' => false,
	);
	if ( $status['openai'] ) {
		$probe            = CC_Concat::probe();
		$status['concat'] = ! is_wp_error( $probe );
	}
	set_transient( 'cc_side_status', $status, 20 );
	return $status;
}
