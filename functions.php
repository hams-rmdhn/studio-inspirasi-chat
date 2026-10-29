<?php
/**
 * Studio Inspirasi Chat — theme bootstrap.
 *
 * Front page = full-page chat UI (admin only). Commands go to OpenAI
 * (function calling); tool calls reach the Studio Inspirasi video editor through
 * its JSON-RPC API (window Automation socket).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CC_VERSION', '0.1.0' );

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/concat.php';
require_once __DIR__ . '/inc/ai.php';
require_once __DIR__ . '/inc/openai.php';
require_once __DIR__ . '/inc/tools.php';
require_once __DIR__ . '/inc/jobs.php';
require_once __DIR__ . '/inc/control.php';
require_once __DIR__ . '/inc/assembly.php';
require_once __DIR__ . '/inc/render.php';
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/chat.php';

add_action(
	'after_setup_theme',
	function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
	}
);

/**
 * Workspace bootstrap — buat Assets/, Projects/, Exported/, _tmp/ saat tema
 * diaktifkan. cc_ws_ensure() juga dipanggil lazy dari helper cc_ws_*.
 */
add_action( 'after_switch_theme', 'cc_ws_ensure' );

/**
 * Favicon — inline SVG matching ikon clapperboard emerald di UI.
 */
add_action(
	'wp_head',
	function () {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<rect width="24" height="24" rx="5" fill="#059669"/>'
			. '<g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
			. '<path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3Z"/>'
			. '<path d="m6.2 5.3 3.1 3.9"/><path d="m12.4 3.4 3.1 4"/>'
			. '<path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>'
			. '</g></svg>';
		echo '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,' . rawurlencode( $svg ) . '">' . "\n";
	}
);

/**
 * Access guard — the app shell is for admins only.
 * Guests → wp-login; logged-in non-admins → plain notice.
 */
add_action(
	'template_redirect',
	function () {
		if ( ! is_front_page() && ! is_home() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				'Studio Inspirasi Chat hanya untuk administrator.',
				'Akses ditolak',
				array( 'response' => 403, 'back_link' => true )
			);
		}
	}
);

/* App shell — no admin bar chrome over the chat surface. */
add_filter( 'show_admin_bar', '__return_false' );

/**
 * Front-end assets. Version = theme version + filemtime so cached
 * copies bust on every deploy (same convention as sms-storefront).
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_front_page() && ! is_home() ) {
			return;
		}
		$app_css = get_template_directory() . '/assets/css/app.css';
		$chat_js = get_template_directory() . '/assets/js/chat.js';
		wp_enqueue_style( 'cc-app', get_template_directory_uri() . '/assets/css/app.css', array(), CC_VERSION . '.' . ( file_exists( $app_css ) ? filemtime( $app_css ) : '0' ) );
		wp_enqueue_script( 'cc-lucide', 'https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js', array(), '0.469.0', true );
		wp_enqueue_script( 'cc-chat', get_template_directory_uri() . '/assets/js/chat.js', array(), CC_VERSION . '.' . ( file_exists( $chat_js ) ? filemtime( $chat_js ) : '0' ), true );
		wp_enqueue_script( 'cc-alpine', 'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js', array( 'cc-chat' ), '3.14.1', true );

		wp_localize_script(
			'cc-chat',
			'CC_DATA',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'concat/v1' ) ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'settingsUrl'  => esc_url_raw( admin_url( 'admin.php?page=cc-settings' ) ),
				'userName'     => wp_get_current_user()->display_name,
				'workspace'    => esc_url_raw( cc_workspace() ),
				'copyRoots'    => array_values( cc_copy_roots() ),
			)
		);
	}
);

/* Alpine waits for DOMContentLoaded and needs chat.js defined first. */
add_filter(
	'script_loader_tag',
	function ( $tag, $handle ) {
		if ( 'cc-alpine' === $handle ) {
			return str_replace( ' src', ' defer src', $tag );
		}
		return $tag;
	},
	10,
	2
);
