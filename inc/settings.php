<?php
/**
 * Settings — admin page + option accessors.
 *
 * Secrets (OpenAI key, Studio Inspirasi token) are stored AES-256-CTR encrypted,
 * keyed off the site salts — the DB alone never carries plaintext.
 * Studio Inspirasi credentials auto-resolve from the window's automation.json
 * when mode=auto.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------
 * Secret encryption helpers
 * ------------------------------------------------------------------- */

function cc_secret_key() {
	return hash( 'sha256', AUTH_KEY . '|' . SECURE_AUTH_KEY, true );
}

function cc_encrypt( $plain ) {
	$plain = (string) $plain;
	if ( '' === $plain ) {
		return '';
	}
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return 'plain::' . $plain; // degraded — OpenSSL missing
	}
	$iv = openssl_random_pseudo_bytes( 16 );
	$ct = openssl_encrypt( $plain, 'aes-256-ctr', cc_secret_key(), OPENSSL_RAW_DATA, $iv );
	return 'enc::' . base64_encode( $iv . $ct );
}

function cc_decrypt( $blob ) {
	$blob = (string) $blob;
	if ( '' === $blob ) {
		return '';
	}
	if ( 0 === strpos( $blob, 'plain::' ) ) {
		return substr( $blob, 7 );
	}
	if ( 0 !== strpos( $blob, 'enc::' ) ) {
		return $blob; // legacy/plaintext value
	}
	$raw = base64_decode( substr( $blob, 5 ), true );
	if ( false === $raw || strlen( $raw ) < 17 ) {
		return '';
	}
	$pt = openssl_decrypt( substr( $raw, 16 ), 'aes-256-ctr', cc_secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	return false === $pt ? '' : $pt;
}

/* ---------------------------------------------------------------------
 * Option accessors
 * ------------------------------------------------------------------- */

function cc_openai_key() {
	return cc_decrypt( get_option( 'cc_openai_key', '' ) );
}

function cc_openai_model() {
	$m = trim( (string) get_option( 'cc_openai_model', '' ) );
	return $m ? $m : 'gpt-4o-mini';
}

function cc_openai_configured() {
	return function_exists( 'cc_ai_configured' ) ? cc_ai_configured() : '' !== cc_openai_key();
}

function cc_openai_prompt() {
	$p = trim( (string) get_option( 'cc_openai_prompt', '' ) );
	return '' !== $p ? $p : cc_default_prompt();
}

function cc_default_prompt() {
	$home = wp_normalize_path( cc_workspace() );
	return 'Kamu adalah asisten editor video yang mengendalikan aplikasi desktop Studio Inspirasi di komputer user melalui tool yang tersedia. '
		. 'Jawab dalam Bahasa Indonesia yang sopan dan ringkas. '
		. "Semua proyek baru harus dibuat di folder kerja: {$home}. "
		. 'Sebelum mengedit: pahami dulu permintaan user, buat proyek bila belum ada (proyek lama dibuka dulu dengan project_open), import media, dan baca timeline_read untuk id clip — jangan menebak id. '
		. 'Setelah edit selesai selalu simpan dengan project_save; bila user ingin melanjutkan manual di aplikasi Studio Inspirasi, tutup dengan project_close. '
		. 'Bila user menyebut template atau struktur yang dipakai berulang, gunakan template_list + template_instantiate daripada menyusun timeline dari nol. '
		. 'Untuk mengganti video pada clip hasil template, pakai replace_clip_media — posisi dan trim clip dipertahankan. '
		. 'Untuk beberapa edit yang berkaitan, gabungkan dalam satu panggilan edit_apply. '
		. 'Untuk animasi masuk/keluar visual sebuah clip, patch visualMotion via edit_apply → updateClip {clipId, patch:{visualMotion:{entrance:0-3, exit:0-3, duration:detik}}} (0=none,1=fade,2=slide,3=pop). '
		. 'Hanya satu export dalam satu waktu; bila export ditolak busy, jelaskan ke user bahwa ada export lain berjalan. '
		. 'Hasil akhir selalu ditulis ke workspace lalu disalin ke folder tujuan yang user minta dengan copy_result — tolak sopan bila folder tujuan di luar daftar yang diizinkan. '
		. 'Untuk produksi video otomatis dari bahan, SELALU pakai control_run (jangan menyusun timeline manual dengan addClip berulang kali). Ada 3 workflow: '
		. '(1) workflow=clip — potong 1 video (file lokal atau URL YouTube) jadi klip pendek: mode manual (segments) atau mode moments (AI pilih momen dari transkrip, butuh moment_count/min/max). '
		. '(2) workflow=broll — video utama talking head + sisipan B-Roll otomatis: wajib file (video utama) + broll_dir (folder video B-Roll); opsional sfx_dir (folder audio transisi), silence_cut untuk rapatkan jeda, broll_pacing (calm/dynamic/sentence), broll_motion (none/fade/slide/pop — default slide, exit selalu fade 0.3s), broll_vision (scan visual tiap klip), broll_prompt (arahan bebas). '
		. '(3) workflow=assembly — rakit video penuh dari Voice Over: wajib vo_file (audio MP3/WAV penentu durasi) + broll_dir; SFX/pacing/motion/prompt sama seperti broll. '
		. 'Jika permintaan menyebut "b-roll", "sisipkan klip", "tampilkan visual pendukung" → workflow=broll; jika menyebut "voice over", "narasi MP3", "rakit dari bahan/audio" → workflow=assembly; jika hanya potong momen/segmen → workflow=clip. '
		. 'Bila user tidak menyebut folder B-Roll/VO, TANYA dulu path foldernya — jangan mengarang path. '
		. 'Laporkan langkah penting ke user secara singkat di jawaban akhir.';
}

function cc_concat_mode() {
	return 'manual' === get_option( 'cc_concat_mode' ) ? 'manual' : 'auto';
}

function cc_concat_addr_opt() {
	$a = trim( (string) get_option( 'cc_concat_addr', '' ) );
	return $a ? $a : '127.0.0.1:7420';
}

function cc_concat_token_opt() {
	return cc_decrypt( get_option( 'cc_concat_token', '' ) );
}

function cc_workspace() {
	$w = trim( (string) get_option( 'cc_workspace', '' ) );
	if ( '' === $w ) {
		$home = (string) getenv( 'USERPROFILE' ) ?: (string) getenv( 'HOME' );
		$w    = trailingslashit( wp_normalize_path( $home ) ) . 'concat-chat-projects';
	}
	return trailingslashit( wp_normalize_path( $w ) );
}

/**
 * Bootstrap struktur workspace — idempotent, sekali per request.
 * Dipanggil saat tema aktif (after_switch_theme) dan lazy dari helper cc_ws_*.
 */
function cc_ws_ensure() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	foreach ( array( 'Assets', 'Projects', 'Exported', '_tmp' ) as $d ) {
		wp_mkdir_p( cc_workspace() . $d );
	}
}

function cc_ws_assets() {
	cc_ws_ensure();
	return cc_workspace() . 'Assets/';
}

function cc_ws_projects() {
	cc_ws_ensure();
	return cc_workspace() . 'Projects/';
}

function cc_ws_exported() {
	cc_ws_ensure();
	return cc_workspace() . 'Exported/';
}

function cc_ws_tmp() {
	cc_ws_ensure();
	return cc_workspace() . '_tmp/';
}

function cc_bin_dir() {
	return get_template_directory() . '/bin/';
}

/** Whitelisted destination folders for copy_result (';'-separated). */
function cc_copy_roots() {
	$raw = (string) get_option( 'cc_copy_roots', '' );
	$out = array( untrailingslashit( cc_ws_exported() ) ); // Exported selalu diizinkan.
	foreach ( explode( ';', $raw ) as $p ) {
		$p = trim( $p );
		if ( '' !== $p ) {
			$out[] = untrailingslashit( wp_normalize_path( $p ) );
		}
	}
	return array_values( array_unique( $out ) );
}

function cc_max_iter() {
	$n = (int) get_option( 'cc_max_iter', 15 );
	return $n > 0 && $n <= 50 ? $n : 15;
}

/* ---------------------------------------------------------------------
 * automation.json — written by the Studio Inspirasi window while
 * Settings › Automation › Scripts and apps is on.
 * ------------------------------------------------------------------- */

/**
 * Candidate locations for automation.json — the web-server PHP may not
 * inherit APPDATA/USERPROFILE the way a shell does, so we try every
 * env source that could hold it, plus the Unix XDG path.
 */
function cc_automation_paths() {
	$base  = 'app.concat.editor/automation.json';
	$paths = array();
	// Studio Inspirasi / Clovercut (forks) di macOS menulis ke dir Application
	// Support miliknya sendiri — yang aktif didahulukan.
	if ( getenv( 'HOME' ) ) {
		foreach ( array( 'io.github.hams-rmdhn.studio-inspirasi', 'io.github.tulcoturo.clovercut' ) as $cfg ) {
			$paths[] = trailingslashit( getenv( 'HOME' ) ) . 'Library/Application Support/' . $cfg . '/automation.json';
		}
	}
	foreach ( array( getenv( 'APPDATA' ), $_SERVER['APPDATA'] ?? '' ) as $app ) {
		if ( $app ) {
			$paths[] = trailingslashit( $app ) . $base;
		}
	}
	foreach ( array( getenv( 'USERPROFILE' ), $_SERVER['USERPROFILE'] ?? '' ) as $home ) {
		if ( $home ) {
			$paths[] = trailingslashit( $home ) . 'AppData/Roaming/' . $base;
		}
	}
	$drive = getenv( 'HOMEDRIVE' ) . getenv( 'HOMEPATH' );
	if ( strlen( $drive ) > 2 ) {
		$paths[] = trailingslashit( $drive ) . 'AppData/Roaming/' . $base;
	}
	if ( getenv( 'HOME' ) ) {
		$paths[] = trailingslashit( getenv( 'HOME' ) ) . '.config/' . $base;
	}
	return array_unique( array_filter( $paths ) );
}

/** First existing candidate, else the first candidate (for display). */
function cc_automation_path() {
	$paths = cc_automation_paths();
	foreach ( $paths as $p ) {
		if ( file_exists( $p ) ) {
			return $p;
		}
	}
	return $paths ? $paths[0] : '';
}

/** Returns array{address,token} or WP_Error. */
function cc_automation_read() {
	$path = cc_automation_path();
	if ( ! $path || ! file_exists( $path ) ) {
		return new WP_Error(
			'cc_auto_off',
			'automation.json tidak ditemukan — buka Studio Inspirasi (Studio Inspirasi) dan aktifkan Settings › Automation › Scripts and apps. Dicari di: '
				. implode( ', ', cc_automation_paths() )
		);
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || empty( $data['address'] ) || empty( $data['token'] ) ) {
		return new WP_Error( 'cc_auto_bad', 'automation.json terbaca tapi tidak lengkap (address/token hilang).' );
	}
	return array( 'address' => $data['address'], 'token' => $data['token'], 'source' => 'automation.json' );
}

/** Resolve {address,token} per configured mode. WP_Error when not available. */
function cc_concat_credentials() {
	if ( 'auto' === cc_concat_mode() ) {
		return cc_automation_read();
	}
	$addr  = cc_concat_addr_opt();
	$token = cc_concat_token_opt();
	if ( strlen( $token ) < 20 ) {
		return new WP_Error( 'cc_token', 'Token Studio Inspirasi belum diisi / kurang dari 20 karakter.' );
	}
	return array( 'address' => $addr, 'token' => $token, 'source' => 'manual' );
}

/* ---------------------------------------------------------------------
 * Admin page
 * ------------------------------------------------------------------- */

add_action(
	'admin_menu',
	function () {
		add_menu_page(
			'Studio Inspirasi Chat',
			'Studio Inspirasi Chat',
			'manage_options',
			'cc-settings',
			'cc_settings_page',
			'dashicons-format-chat',
			30
		);
	}
);

add_action( 'admin_post_cc_settings_save', 'cc_handle_settings_save' );
add_action( 'admin_post_cc_test_openai', 'cc_handle_test_openai' );
add_action( 'admin_post_cc_test_concat', 'cc_handle_test_concat' );

/** Apply a settings payload — shared by the wp-admin form and the REST endpoint. */
function cc_settings_apply( $in ) {
	update_option( 'cc_openai_model', sanitize_text_field( $in['openai_model'] ?? '' ), false );
	update_option( 'cc_openai_prompt', sanitize_textarea_field( $in['openai_prompt'] ?? '' ), false );
	update_option( 'cc_concat_mode', 'manual' === ( $in['concat_mode'] ?? '' ) ? 'manual' : 'auto', false );
	update_option( 'cc_concat_addr', sanitize_text_field( $in['concat_addr'] ?? '' ), false );
	update_option( 'cc_workspace', sanitize_text_field( $in['workspace'] ?? '' ), false );
	update_option( 'cc_copy_roots', sanitize_textarea_field( $in['copy_roots'] ?? '' ), false );
	update_option( 'cc_max_iter', absint( $in['max_iter'] ?? 15 ), false );

	// Agen AI — provider + model/key/baseurl per provider.
	if ( isset( $in['ai_provider'] ) ) {
		$p = sanitize_key( (string) $in['ai_provider'] );
		if ( function_exists( 'cc_ai_providers' ) && array_key_exists( $p, cc_ai_providers() ) ) {
			update_option( 'cc_ai_provider', $p, false );
		}
	}
	$p = function_exists( 'cc_ai_provider' ) ? cc_ai_provider() : 'openai';
	if ( 'openai' === $p ) {
		if ( isset( $in['ai_model'] ) ) {
			update_option( 'cc_openai_model', sanitize_text_field( $in['ai_model'] ), false );
		}
	} elseif ( isset( $in['ai_model'] ) ) {
		update_option( 'cc_ai_model_' . $p, sanitize_text_field( $in['ai_model'] ), false );
	}
	if ( 'custom' === $p ) {
		update_option( 'cc_ai_custom_baseurl', sanitize_text_field( $in['ai_baseurl'] ?? '' ), false );
	}
	update_option( 'cc_ai_baseurl', sanitize_text_field( $in['ai_baseurl'] ?? '' ), false );

	// Secret fields: empty input = keep the stored value.
	$key_opt = 'openai' === $p ? 'cc_openai_key' : 'cc_ai_key_' . $p;
	foreach ( array( 'openai_key' => 'cc_openai_key', 'concat_token' => 'cc_concat_token', 'ai_key' => $key_opt ) as $field => $option ) {
		$val = trim( (string) ( $in[ $field ] ?? '' ) );
		if ( '' !== $val ) {
			update_option( $option, cc_encrypt( $val ), false );
		}
	}
}

function cc_handle_settings_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}
	check_admin_referer( 'cc_settings_save' );

	$in = array();
	foreach ( array( 'openai_model', 'openai_prompt', 'concat_mode', 'concat_addr', 'workspace', 'copy_roots', 'max_iter', 'openai_key', 'concat_token' ) as $f ) {
		$in[ $f ] = wp_unslash( $_POST[ $f ] ?? '' );
	}
	cc_settings_apply( $in );

	wp_safe_redirect( add_query_arg( 'cc_saved', '1', admin_url( 'admin.php?page=cc-settings' ) ) );
	exit;
}

function cc_handle_test_openai() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}
	check_admin_referer( 'cc_test_openai' );
	$res   = CC_OpenAI::ping();
	$state = is_wp_error( $res ) ? 'err:' . $res->get_error_message() : 'ok:' . $res;
	set_transient( 'cc_test_openai', $state, 120 );
	wp_safe_redirect( admin_url( 'admin.php?page=cc-settings#openai' ) );
	exit;
}

function cc_handle_test_concat() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}
	check_admin_referer( 'cc_test_concat' );
	$res   = CC_Concat::probe();
	$state = is_wp_error( $res )
		? 'err:' . $res->get_error_message()
		: 'ok:API ' . ( $res['apiVersion'] ?? '?' ) . ' · Studio Inspirasi ' . ( $res['concat'] ?? '?' ) . ' · caps: ' . implode( ',', (array) ( $res['capabilities'] ?? array() ) );
	set_transient( 'cc_test_concat', $state, 120 );
	wp_safe_redirect( admin_url( 'admin.php?page=cc-settings#concat' ) );
	exit;
}

function cc_test_notice( $key ) {
	$state = get_transient( $key );
	if ( ! $state ) {
		return;
	}
	delete_transient( $key );
	$ok = 0 === strpos( $state, 'ok:' );
	printf(
		'<div class="notice %s inline"><p><strong>%s</strong> %s</p></div>',
		$ok ? 'notice-success' : 'notice-error',
		$ok ? '✓' : '✗',
		esc_html( substr( $state, 3 ) )
	);
}

function cc_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Access denied.' );
	}
	$auto = cc_automation_read();
	?>
	<div class="wrap">
		<h1>Studio Inspirasi Chat — Settings</h1>
		<?php if ( ! empty( $_GET['cc_saved'] ) ) : ?>
			<div class="notice notice-success inline"><p>Pengaturan tersimpan.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cc_settings_save' ); ?>
			<input type="hidden" name="action" value="cc_settings_save">

			<h2 id="openai">OpenAI</h2>
			<?php cc_test_notice( 'cc_test_openai' ); ?>
			<table class="form-table">
				<tr>
					<th>API Key</th>
					<td><input type="password" name="openai_key" value="" class="regular-text" placeholder="<?php echo cc_openai_configured() ? '•••••••• (tersimpan — isi untuk mengganti)' : 'sk-…'; ?>" autocomplete="new-password">
						<p class="description">Disimpan terenkripsi. Kosongkan untuk mempertahankan nilai lama.</p></td>
				</tr>
				<tr>
					<th>Model</th>
					<td><input type="text" name="openai_model" value="<?php echo esc_attr( get_option( 'cc_openai_model', 'gpt-4o-mini' ) ); ?>" class="regular-text">
						<p class="description">Model chat completions dengan function calling — <code>gpt-4o-mini</code> disarankan.</p></td>
				</tr>
				<tr>
					<th>System prompt</th>
					<td><textarea name="openai_prompt" rows="7" class="large-text code" placeholder="<?php echo esc_attr( cc_default_prompt() ); ?>"><?php echo esc_textarea( get_option( 'cc_openai_prompt', '' ) ); ?></textarea>
						<p class="description">Kosongkan untuk memakai bawaan (terlihat di placeholder).</p></td>
				</tr>
			</table>

			<h2 id="concat">Studio Inspirasi (video editor)</h2>
			<?php cc_test_notice( 'cc_test_concat' ); ?>
			<table class="form-table">
				<tr>
					<th>Mode koneksi</th>
					<td>
						<select name="concat_mode">
							<option value="auto" <?php selected( cc_concat_mode(), 'auto' ); ?>>Auto — baca automation.json dari window Studio Inspirasi</option>
							<option value="manual" <?php selected( cc_concat_mode(), 'manual' ); ?>>Manual — pakai address + token di bawah</option>
						</select>
						<p class="description">
							automation.json: <code><?php echo esc_html( cc_automation_path() ); ?></code><br>
							<?php if ( is_wp_error( $auto ) ) : ?>
								<span style="color:#dba617">&#9679;</span> <?php echo esc_html( $auto->get_error_message() ); ?>
							<?php else : ?>
								<span style="color:#00a32a">&#9679;</span> Terdeteksi — address <code><?php echo esc_html( $auto['address'] ); ?></code>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th>Address (manual)</th>
					<td><input type="text" name="concat_addr" value="<?php echo esc_attr( get_option( 'cc_concat_addr', '127.0.0.1:7420' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th>Token (manual)</th>
					<td><input type="password" name="concat_token" value="" class="regular-text" placeholder="<?php echo cc_concat_token_opt() ? '•••••••• (tersimpan)' : 'min. 20 karakter — dari Settings › Automation di Studio Inspirasi'; ?>" autocomplete="new-password"></td>
				</tr>
			</table>

			<h2>Folder kerja</h2>
			<table class="form-table">
				<tr>
					<th>Workspace</th>
					<td><input type="text" name="workspace" value="<?php echo esc_attr( get_option( 'cc_workspace', cc_workspace() ) ); ?>" class="regular-text code">
						<p class="description">Proyek & export sementara Studio Inspirasi. Window Automation hanya boleh menulis di bawah home folder — simpan workspace di sana.</p></td>
				</tr>
				<tr>
					<th>Folder tujuan diizinkan</th>
					<td><textarea name="copy_roots" rows="3" class="large-text code" placeholder="D:\kegiatan;D:\kegiatan\dokumentasi"><?php echo esc_textarea( get_option( 'cc_copy_roots', '' ) ); ?></textarea>
						<p class="description">Daftar folder (pisahkan dengan <code>;</code>) yang boleh menjadi tujuan penyalinan hasil export oleh tool <code>copy_result</code>. Path di luar daftar ini ditolak.</p></td>
				</tr>
				<tr>
					<th>Maks. iterasi tool</th>
					<td><input type="number" name="max_iter" min="1" max="50" value="<?php echo esc_attr( cc_max_iter() ); ?>" class="small-text">
						<p class="description">Batas tool-call loop OpenAI per perintah (mencegah loop liar).</p></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<table class="form-table">
			<tr>
				<th></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
						<?php wp_nonce_field( 'cc_test_openai' ); ?>
						<input type="hidden" name="action" value="cc_test_openai">
						<button class="button">Test koneksi OpenAI</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
						<?php wp_nonce_field( 'cc_test_concat' ); ?>
						<input type="hidden" name="action" value="cc_test_concat">
						<button class="button">Test koneksi Studio Inspirasi</button>
					</form>
				</td>
			</tr>
		</table>
	</div>
	<?php
}
