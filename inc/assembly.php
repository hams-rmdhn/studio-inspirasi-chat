<?php
/**
 * AI Auto B-Roll & Multi-Clip Assembly Engine.
 *
 * Mendukung dua genre baru di atas Clipper:
 * - 'broll'    : 1 video utama (talking head) + sisipan B-Roll & SFX otomatis di track atasnya
 * - 'assembly' : 1 audio Voice Over (MP3/WAV) + koleksi B-Roll & SFX dirakit penuh jadi 1 video
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Probe durasi & dimensi file media lokal secara cepat via ffmpeg header.
 * Returns array{duration: float, width: int, height: int}.
 */
function cc_assembly_probe_local( $file ) {
	$info = array( 'duration' => 5.0, 'width' => 0, 'height' => 0 );
	if ( ! $file || ! file_exists( $file ) ) {
		return $info;
	}
	$ff = function_exists( 'cc_control_ffmpeg_bin' ) ? cc_control_ffmpeg_bin() : '';
	if ( ! $ff ) {
		return $info;
	}
	$cmd = escapeshellarg( $ff ) . ' -hide_banner -i ' . escapeshellarg( $file ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	if ( preg_match( '/Duration:\s*(\d+):(\d+):(\d+(?:\.\d+)?)/', $out, $m ) ) {
		$info['duration'] = max( 0.2, (int) $m[1] * 3600 + (int) $m[2] * 60 + (float) $m[3] );
	}
	if ( preg_match( '/Stream\s+#\d+:\d+.*Video:.*?,\s*(\d{2,5})x(\d{2,5})/s', $out, $v ) ) {
		$info['width']  = (int) $v[1];
		$info['height'] = (int) $v[2];
	}
	return $info;
}

/**
 * Pastikan transkrip .srt tersedia untuk file video/audio.
 * Urutan: (1) manual .srt, (2) YouTube .srt, (3) Auto-Whisper via ffmpeg + CC_AI::transcribe() ber-cache.
 * Returns string isi SRT atau WP_Error.
 */
function cc_assembly_ensure_srt( $media_file, $lang = 'id', $manual_srt = '', $yt_srt = '' ) {
	if ( '' !== $manual_srt && file_exists( $manual_srt ) && CC_Tools::read_allowed( $manual_srt ) ) {
		$txt = trim( (string) file_get_contents( $manual_srt ) );
		if ( '' !== $txt ) {
			return $txt . "\n";
		}
	}
	if ( '' !== $yt_srt && file_exists( $yt_srt ) ) {
		$txt = trim( (string) file_get_contents( $yt_srt ) );
		if ( '' !== $txt ) {
			return $txt . "\n";
		}
	}
	if ( ! $media_file || ! file_exists( $media_file ) ) {
		return new WP_Error( 'cc_srt', 'File media tidak ditemukan untuk transkripsi.' );
	}

	$hash      = md5( $media_file . '|' . @filemtime( $media_file ) . '|' . $lang );
	$cache_srt = cc_ws_tmp() . 'whisper-' . $hash . '.srt';
	if ( file_exists( $cache_srt ) && filesize( $cache_srt ) > 10 ) {
		return (string) file_get_contents( $cache_srt );
	}

	$ff = function_exists( 'cc_control_ffmpeg_bin' ) ? cc_control_ffmpeg_bin() : '';
	if ( ! $ff ) {
		return new WP_Error( 'cc_srt', 'ffmpeg tidak tersedia untuk ekstraksi audio.' );
	}
	$tmp_mp3 = cc_ws_tmp() . 'whisper-' . $hash . '.mp3';
	$cmd     = escapeshellarg( $ff ) . ' -y -hide_banner -loglevel error -i ' . escapeshellarg( $media_file )
		. ' -vn -ac 1 -ar 16000 -b:a 32k ' . escapeshellarg( $tmp_mp3 ) . ' 2>&1';
	shell_exec( $cmd );
	if ( ! file_exists( $tmp_mp3 ) || filesize( $tmp_mp3 ) < 100 ) {
		return new WP_Error( 'cc_srt', 'Gagal mengekstrak audio dari file media untuk Whisper.' );
	}

	$srt = CC_AI::transcribe( $tmp_mp3, $lang );
	@unlink( $tmp_mp3 );
	if ( is_wp_error( $srt ) ) {
		return $srt;
	}
	@file_put_contents( $cache_srt, $srt );
	return $srt;
}

/**
 * Katalogisasi folder B-Roll (video) atau SFX (audio) dengan cache .cc-catalog.json.
 *
 * @param string $dir        Path folder lokal.
 * @param string $kind       'broll' | 'sfx'.
 * @param bool   $use_vision Ekstrak 1 frame tengah & deskripsikan via CC_AI::vision().
 * @return array<int,array{id:string,file:string,name:string,duration:float,width:int,height:int,desc:string}>|WP_Error
 */
function cc_assembly_catalog_dir( $dir, $kind = 'broll', $use_vision = false ) {
	$dir = wp_normalize_path( trim( (string) $dir ) );
	if ( '' === $dir ) {
		return array();
	}
	if ( ! is_dir( $dir ) ) {
		return new WP_Error( 'cc_cat_dir', 'Folder ' . $kind . ' tidak ditemukan: ' . $dir );
	}
	if ( ! CC_Tools::read_allowed( $dir ) ) {
		return new WP_Error( 'cc_cat_perm', 'Folder ' . $kind . ' di luar direktori yang diizinkan.' );
	}

	$exts = 'sfx' === $kind
		? array( 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac' )
		: array( 'mp4', 'mov', 'mkv', 'webm', 'm4v' );

	$cache_file = trailingslashit( $dir ) . '.cc-catalog.json';
	$cache      = array();
	if ( file_exists( $cache_file ) ) {
		$dec = json_decode( (string) file_get_contents( $cache_file ), true );
		if ( is_array( $dec ) ) {
			$cache = $dec;
		}
	}

	$entries = @scandir( $dir );
	if ( false === $entries ) {
		return new WP_Error( 'cc_cat_read', 'Tidak bisa membaca isi folder: ' . $dir );
	}
	natcasesort( $entries );

	$items  = array();
	$dirty  = false;
	$prefix = 'sfx' === $kind ? 's' : 'b';
	$idx    = 0;
	$ff     = function_exists( 'cc_control_ffmpeg_bin' ) ? cc_control_ffmpeg_bin() : '';

	foreach ( $entries as $fn ) {
		if ( '.' === $fn[0] ) {
			continue;
		}
		$full = wp_normalize_path( trailingslashit( $dir ) . $fn );
		if ( ! is_file( $full ) ) {
			continue;
		}
		$ext = strtolower( pathinfo( $fn, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $exts, true ) ) {
			continue;
		}

		$mtime  = (int) @filemtime( $full );
		$cached = $cache[ $fn ] ?? null;
		$base_desc = trim( preg_replace( '/[\-_]+/', ' ', preg_replace( '/^\d+[\s\-_.]*/', '', pathinfo( $fn, PATHINFO_FILENAME ) ) ) );
		if ( '' === $base_desc ) {
			$base_desc = pathinfo( $fn, PATHINFO_FILENAME );
		}

		if ( is_array( $cached ) && (int) ( $cached['mtime'] ?? 0 ) === $mtime && ! empty( $cached['duration'] ) ) {
			$meta = $cached;
		} else {
			$pr   = cc_assembly_probe_local( $full );
			$meta = array(
				'mtime'    => $mtime,
				'duration' => $pr['duration'],
				'width'    => $pr['width'],
				'height'   => $pr['height'],
				'desc'     => $base_desc,
				'vision'   => false,
			);
			$dirty = true;
		}

		// Jika Vision diminta untuk B-Roll dan belum pernah di-scan Vision:
		if ( 'broll' === $kind && $use_vision && empty( $meta['vision'] ) && $ff ) {
			$mid_t   = max( 0.2, min( $meta['duration'] * 0.35, $meta['duration'] - 0.2 ) );
			$tmp_jpg = cc_ws_tmp() . 'vis-' . md5( $full . '|' . $mtime ) . '.jpg';
			$cmd     = escapeshellarg( $ff ) . ' -y -hide_banner -loglevel error'
				. ' -ss ' . escapeshellarg( (string) round( $mid_t, 2 ) )
				. ' -i ' . escapeshellarg( $full )
				. ' -frames:v 1 -vf "scale=360:-2" -q:v 5 ' . escapeshellarg( $tmp_jpg ) . ' 2>&1';
			shell_exec( $cmd );
			if ( file_exists( $tmp_jpg ) && filesize( $tmp_jpg ) > 200 ) {
				$vdesc = CC_AI::vision(
					'Deskripsikan isi visual frame video ini dalam 6-12 kata bahasa Indonesia (subjek utama, aktivitas, suasana). Nama file: ' . $base_desc . '. Jawab langsung deskripsinya tanpa tanda kutip.',
					$tmp_jpg
				);
				@unlink( $tmp_jpg );
				if ( ! is_wp_error( $vdesc ) && '' !== trim( $vdesc ) ) {
					$meta['desc']   = $base_desc . ' — ' . trim( preg_replace( '/\s+/', ' ', $vdesc ) );
					$meta['vision'] = true;
					$dirty          = true;
				}
			}
		}

		$cache[ $fn ] = $meta;
		$idx++;
		$items[] = array(
			'id'       => $prefix . $idx,
			'file'     => $full,
			'name'     => $fn,
			'duration' => round( (float) $meta['duration'], 2 ),
			'width'    => (int) $meta['width'],
			'height'   => (int) $meta['height'],
			'desc'     => (string) ( $meta['desc'] ?? $base_desc ),
		);
	}

	if ( $dirty ) {
		@file_put_contents( $cache_file, wp_json_encode( $cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
	return $items;
}

/**
 * AI Director — menyusun Edit Decision List (EDL) dari transkrip + katalog B-Roll & SFX.
 *
 * @param string $srt_txt   Isi SRT yang sudah sesuai timeline target (0..total_dur).
 * @param float  $total_dur Durasi total timeline (detik).
 * @param array  $brolls    Hasil cc_assembly_catalog_dir(..., 'broll').
 * @param array  $sfxs      Hasil cc_assembly_catalog_dir(..., 'sfx').
 * @param array  $opts      {workflow:'broll'|'assembly', pacing:'calm'|'dynamic'|'sentence', prompt:string}.
 * @return array<int,array{broll_id:string,timeline_start:float,duration:float,source_start:float,sfx_id:string,reason:string}>
 */
function cc_assembly_plan_edl( $srt_txt, $total_dur, $brolls, $sfxs = array(), $opts = array() ) {
	$total_dur = max( 1.0, (float) $total_dur );
	if ( empty( $brolls ) ) {
		return array();
	}

	$workflow = ( $opts['workflow'] ?? 'broll' ) === 'assembly' ? 'assembly' : 'broll';
	$pacing   = (string) ( $opts['pacing'] ?? 'calm' );
	$u_prompt = trim( (string) ( $opts['prompt'] ?? '' ) );

	// Ringkas transkrip untuk prompt AI.
	$lines = array();
	foreach ( preg_split( '/\r?\n\r?\n/', trim( (string) $srt_txt ) ) as $b ) {
		if ( ! preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $m ) ) {
			continue;
		}
		$t1  = cc_control_srt_ts( $m[1], $m[2], $m[3], $m[4] );
		$t2  = cc_control_srt_ts( $m[5], $m[6], $m[7], $m[8] );
		$txt = trim( preg_replace( '/\s+/', ' ', preg_replace( '/^\d+\s*\r?\n[^\r\n]+\r?\n/', '', trim( $b ) ) ) );
		if ( '' !== $txt ) {
			$lines[] = sprintf( '[%.1f-%.1f] %s', $t1, $t2, $txt );
		}
	}
	$transcript_str = implode( "\n", array_slice( $lines, 0, 180 ) );
	if ( '' === $transcript_str ) {
		$transcript_str = sprintf( '[0.0-%.1f] (Tanpa transkrip teks — susun visual secara ritmis)', $total_dur );
	}

	$broll_list = array();
	$broll_map  = array();
	foreach ( $brolls as $b ) {
		$broll_map[ $b['id'] ] = $b;
		$broll_list[]          = sprintf( '- %s (dur %.1fs): %s', $b['id'], $b['duration'], $b['desc'] );
	}

	$sfx_list = array();
	$sfx_map  = array();
	foreach ( $sfxs as $s ) {
		$sfx_map[ $s['id'] ] = $s;
		$sfx_list[]          = sprintf( '- %s (dur %.1fs): %s', $s['id'], $s['duration'], $s['desc'] );
	}

	$pace_desc = 'calm' === $pacing
		? 'Santai (durasi tiap klip 4.0 sampai 6.5 detik)'
		: ( 'dynamic' === $pacing ? 'Dinamis & cepat (durasi tiap klip 2.0 sampai 4.0 detik)' : 'Mengikuti pergantian kalimat/topik di transkrip (3.0 sampai 6.0 detik)' );

	if ( 'assembly' === $workflow ) {
		$mode_inst = "MODE RAKIT PENUH (VO-DRIVEN):\n"
			. "- Susun klip B-Roll berurutan menutupi SELURUH timeline dari 0.0s hingga {$total_dur}s tanpa celah kosong.\n"
			. "- Klip pertama mulai di timeline_start=0.0, dan setiap klip berikutnya mulai tepat saat klip sebelumnya berakhir.\n"
			. "- Jangan melebihi durasi asli masing-masing B-Roll (duration <= durasi B-Roll).";
	} else {
		$mode_inst = "MODE TALKING HEAD + B-ROLL OVERLAY:\n"
			. "- Video utama adalah pembicara (talking head) berdurasi {$total_dur}s.\n"
			. "- Pilih momen kalimat di mana visual B-Roll akan memperkuat penjelasan (sekitar 40%–65% dari total durasi).\n"
			. "- Biarkan 0.0–1.5 detik pertama menampilkan wajah pembicara (jangan taruh B-Roll di detik 0), dan beri jeda minimal 1.5–3.5 detik antar B-Roll agar wajah pembicara tetap terlihat secara berkala.\n"
			. "- Jangan melebihi durasi asli B-Roll.";
	}

	$sys = "Kamu adalah AI Video Director profesional. Tugasmu memilih dan menyusun klip B-Roll serta Sound Effects (SFX) berdasarkan transkrip suara.\n"
		. "Kembalikan HANYA JSON array murni berisi objek:\n"
		. '[{"broll_id":"b1","timeline_start":2.5,"duration":4.0,"source_start":0.0,"sfx_id":"s1","reason":"singkat"}]' . "\n"
		. "Aturan wajib:\n"
		. "1. Semua waktu dalam DETIK desimal (mis. 12.5, BUKAN m:ss).\n"
		. "2. broll_id harus ada di daftar B-Roll; sfx_id boleh kosong \"\" bila tidak perlu SFX atau tidak ada daftar SFX.\n"
		. "3. source_start + duration TIDAK BOLEH melebihi durasi B-Roll tersebut.\n"
		. "4. Variasikan pilihan broll_id (hindari memakai klip yang sama berurutan).";

	$usr = "Durasi total timeline: {$total_dur} detik\n"
		. "Gaya potongan: {$pace_desc}\n\n"
		. $mode_inst . "\n\n"
		. ( '' !== $u_prompt ? "Instruksi khusus user: {$u_prompt}\n\n" : '' )
		. "DAFTAR B-ROLL:\n" . implode( "\n", $broll_list ) . "\n\n"
		. ( $sfx_list ? "DAFTAR SOUND EFFECTS (SFX):\n" . implode( "\n", $sfx_list ) . "\n\n" : "DAFTAR SFX: (kosong)\n\n" )
		. "TRANSKRIP SUARA [start-end]:\n" . $transcript_str;

	$cuts = array();
	if ( cc_ai_configured() ) {
		$res = CC_AI::chat(
			array(
				array( 'role' => 'system', 'content' => $sys ),
				array( 'role' => 'user', 'content' => $usr ),
			)
		);
		if ( ! is_wp_error( $res ) ) {
			$raw = (string) ( $res['message']['content'] ?? '' );
			if ( preg_match( '/\[.*\]/s', $raw, $m ) ) {
				$arr = json_decode( $m[0], true );
				if ( is_array( $arr ) ) {
					foreach ( $arr as $it ) {
						$bid = (string) ( $it['broll_id'] ?? '' );
						if ( ! isset( $broll_map[ $bid ] ) ) {
							continue;
						}
						$binfo = $broll_map[ $bid ];
						$t_st  = max( 0.0, (float) ( $it['timeline_start'] ?? 0.0 ) );
						$s_st  = max( 0.0, (float) ( $it['source_start'] ?? 0.0 ) );
						$dur   = max( 0.8, (float) ( $it['duration'] ?? 3.5 ) );
						if ( $s_st >= $binfo['duration'] - 0.5 ) {
							$s_st = 0.0;
						}
						$max_avail = max( 0.5, $binfo['duration'] - $s_st );
						$dur       = min( $dur, $max_avail );
						if ( $t_st >= $total_dur - 0.3 ) {
							continue;
						}
						if ( $t_st + $dur > $total_dur ) {
							$dur = $total_dur - $t_st;
						}
						$sid = (string) ( $it['sfx_id'] ?? '' );
						if ( ! isset( $sfx_map[ $sid ] ) ) {
							$sid = '';
						}
						$cuts[] = array(
							'broll_id'       => $bid,
							'timeline_start' => round( $t_st, 3 ),
							'duration'       => round( $dur, 3 ),
							'source_start'   => round( $s_st, 3 ),
							'sfx_id'         => $sid,
							'reason'         => (string) ( $it['reason'] ?? '' ),
						);
					}
				}
			}
		}
	}

	// Sort & normalisasi anti-overlap / penutup celah.
	usort( $cuts, function ( $a, $b ) { return $a['timeline_start'] <=> $b['timeline_start']; } );

	$default_sfx = ! empty( $sfxs ) ? $sfxs[0]['id'] : '';
	$target_len  = 'dynamic' === $pacing ? 3.0 : 4.5;

	if ( 'assembly' === $workflow ) {
		// Pastikan menutup penuh 0..total_dur secara rapat berurutan.
		$packed = array();
		$cursor = 0.0;
		$b_idx  = 0;
		$b_cnt  = count( $brolls );

		foreach ( $cuts as $c ) {
			if ( $cursor >= $total_dur - 0.15 ) {
				break;
			}
			$binfo = $broll_map[ $c['broll_id'] ];
			$dur   = min( $c['duration'], $binfo['duration'] - $c['source_start'], $total_dur - $cursor );
			if ( $dur < 0.4 ) {
				continue;
			}
			$c['timeline_start'] = round( $cursor, 3 );
			$c['duration']       = round( $dur, 3 );
			$packed[]            = $c;
			$cursor             += $dur;
		}
		// Jika AI belum menutup sampai akhir (atau fallback tanpa AI), isi sisanya dengan rotasi B-Roll.
		while ( $cursor < $total_dur - 0.15 ) {
			$b   = $brolls[ $b_idx % $b_cnt ];
			$b_idx++;
			$dur = min( $target_len, (float) $b['duration'], $total_dur - $cursor );
			if ( $dur < 0.25 ) {
				break;
			}
			$packed[] = array(
				'broll_id'       => $b['id'],
				'timeline_start' => round( $cursor, 3 ),
				'duration'       => round( $dur, 3 ),
				'source_start'   => 0.0,
				'sfx_id'         => $cursor > 0.1 ? $default_sfx : '',
				'reason'         => 'auto-fill',
			);
			$cursor += $dur;
		}
		return $packed;
	}

	// Mode 'broll': pastikan tidak ada overlap, dan bila AI kosong buat fallback interval rapi.
	$clean  = array();
	$last_e = 1.0; // sisakan minimal 1 detik pembuka talking head
	foreach ( $cuts as $c ) {
		$st = max( $last_e, $c['timeline_start'] );
		if ( $st >= $total_dur - 0.8 ) {
			break;
		}
		$dur = min( $c['duration'], $total_dur - $st );
		if ( $dur < 0.8 ) {
			continue;
		}
		$c['timeline_start'] = round( $st, 3 );
		$c['duration']       = round( $dur, 3 );
		$clean[]             = $c;
		$last_e              = $st + $dur + 1.5; // minimal 1.5s kembali ke talking head
	}

	if ( empty( $clean ) && $total_dur >= 4.0 ) {
		$cursor = min( 2.0, $total_dur * 0.15 );
		$b_idx  = 0;
		$b_cnt  = count( $brolls );
		while ( $cursor < $total_dur - 1.5 ) {
			$b   = $brolls[ $b_idx % $b_cnt ];
			$b_idx++;
			$dur = min( $target_len, (float) $b['duration'], $total_dur - $cursor - 0.5 );
			if ( $dur >= 1.0 ) {
				$clean[] = array(
					'broll_id'       => $b['id'],
					'timeline_start' => round( $cursor, 3 ),
					'duration'       => round( $dur, 3 ),
					'source_start'   => 0.0,
					'sfx_id'         => $default_sfx,
					'reason'         => 'interval B-roll',
				);
			}
			$cursor += $dur + 3.0;
		}
	}

	return $clean;
}

/**
 * Impor file media ke proyek dengan deduplikasi per path file.
 * Returns array{id:string, duration:float}|WP_Error.
 */
function cc_assembly_import_media( $cx, $project, $file, &$imported_map ) {
	$file = wp_normalize_path( (string) $file );
	if ( isset( $imported_map[ $file ] ) ) {
		return $imported_map[ $file ];
	}
	$mi = $cx->call( 'media.import', array( 'path' => $project, 'file' => $file ), 90 );
	if ( is_wp_error( $mi ) ) {
		return $mi;
	}
	$found = null;
	foreach ( array_reverse( (array) ( $mi['project']['media'] ?? $mi['media'] ?? array() ) ) as $m ) {
		if ( basename( (string) ( $m['path'] ?? '' ) ) === basename( $file ) ) {
			$found = $m;
			break;
		}
	}
	if ( ! $found ) {
		$list  = (array) ( $mi['project']['media'] ?? $mi['media'] ?? array() );
		$found = end( $list );
	}
	if ( ! is_array( $found ) || empty( $found['id'] ) ) {
		return new WP_Error( 'cc_import', 'Gagal mengimpor media: ' . basename( $file ) );
	}
	$entry = array(
		'id'       => (string) $found['id'],
		'duration' => max( 0.2, (float) ( $found['duration'] ?? 5.0 ) ),
	);
	$imported_map[ $file ] = $entry;
	return $entry;
}

/**
 * Pasang 1 klip ke track pada posisi $tl_start dengan trim [$src_start, $src_start + $dur],
 * serta terapkan frameClip fill (auto-fit cover) dan patch audio/visual.
 */
function cc_assembly_place_clip( $cx, $project, $track_id, $media_id, $media_dur, $tl_start, $src_start, $dur, $opts = array(), $park_base = 3600.0 ) {
	$park = $park_base + mt_rand( 10, 500 );
	$add  = $cx->call(
		'edit.apply',
		array(
			'path'    => $project,
			'command' => array(
				'op'      => 'addClip',
				'mediaId' => $media_id,
				'trackId' => $track_id,
				'start'   => (float) $park,
				'ripple'  => false,
			),
		),
		30
	);
	if ( is_wp_error( $add ) ) {
		return $add;
	}
	$cid = (string) ( $add['createdId'] ?? $add['created_id'] ?? '' );
	if ( '' === $cid ) {
		return new WP_Error( 'cc_addclip', 'addClip tidak mengembalikan createdId.' );
	}

	// Trim in & out pada posisi parkir agar tidak bertabrakan dengan klip lain di track.
	$src_start = max( 0.0, min( (float) $src_start, $media_dur - 0.2 ) );
	$src_end   = max( $src_start + 0.2, min( $media_dur, $src_start + (float) $dur ) );
	$edelta    = $src_end - $media_dur;
	if ( abs( $edelta ) > 0.005 ) {
		$cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'end', 'delta' => (float) $edelta ),
			),
			30
		);
	}
	if ( $src_start > 0.005 ) {
		$cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'start', 'delta' => (float) $src_start ),
			),
			30
		);
	}

	// Pindahkan dari posisi parkir ke timeline_start yang sebenarnya.
	$mv = $cx->call(
		'edit.apply',
		array(
			'path'    => $project,
			'command' => array(
				'op'    => 'moveClips',
				'moves' => array( array( 'clipId' => $cid, 'trackId' => $track_id, 'start' => max( 0.0, (float) $tl_start ) ) ),
			),
		),
		30
	);
	if ( is_wp_error( $mv ) ) {
		return $mv;
	}

	// Auto-fit cover (fill) untuk klip video agar memenuhi kanvas 9:16 / 16:9.
	if ( ! empty( $opts['fill'] ) ) {
		$cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'frameClip', 'clipId' => $cid, 'framing' => 'fill', 'at' => 0.0 ),
			),
			20
		);
	}

	// Patch mute / volume / fade / name.
	$patch = array();
	if ( isset( $opts['muted'] ) ) {
		$patch['muted'] = (bool) $opts['muted'];
	}
	if ( isset( $opts['volume'] ) ) {
		$patch['volume'] = (float) $opts['volume'];
	}
	if ( isset( $opts['fadeIn'] ) ) {
		$patch['fadeIn'] = (float) $opts['fadeIn'];
	}
	if ( isset( $opts['fadeOut'] ) ) {
		$patch['fadeOut'] = (float) $opts['fadeOut'];
	}
	if ( ! empty( $opts['name'] ) ) {
		$patch['name'] = (string) $opts['name'];
	}
	// Animasi entrance/exit visual: slide(2) masuk + fade(1) keluar, 0.3 detik.
	if ( ! empty( $opts['motion'] ) ) {
		$map = array( 'fade' => 1, 'slide' => 2, 'pop' => 3 );
		$mv  = $map[ $opts['motion'] ] ?? 0;
		if ( $mv > 0 ) {
			$patch['visualMotion'] = array( 'entrance' => $mv, 'exit' => 1, 'duration' => 0.3 );
		}
	}
	if ( $patch ) {
		$cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array( 'op' => 'updateClip', 'clipId' => $cid, 'patch' => $patch ),
			),
			20
		);
	}

	return $cid;
}

/**
 * Hitung durasi aktual video utama di timeline saat ini (setelah trim / silence-cut).
 */
function cc_assembly_timeline_main_span( $cx, $project ) {
	$g = $cx->call( 'project.get', array( 'path' => $project ), 20 );
	if ( is_wp_error( $g ) ) {
		return array( 'duration' => 10.0, 'main_idx' => 0, 'main_track' => 'T1' );
	}
	$active_tl  = (string) ( $g['project']['activeTimelineId'] ?? '' );
	$main_track = 'T1';
	$main_idx   = 0;
	$max_end    = 0.0;

	foreach ( (array) ( $g['project']['timelines'] ?? array() ) as $tl ) {
		if ( '' !== $active_tl && ( $tl['id'] ?? '' ) !== $active_tl ) {
			continue;
		}
		foreach ( array_values( (array) ( $tl['tracks'] ?? array() ) ) as $idx => $tr ) {
			if ( ! empty( $tr['isMain'] ) ) {
				$main_track = (string) $tr['id'];
				$main_idx   = (int) $idx;
				break;
			}
		}
		foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
			if ( ( $c['trackId'] ?? '' ) === $main_track && 'video' === ( $c['kind'] ?? '' ) ) {
				$end = (float) ( $c['start'] ?? 0 ) + (float) ( $c['duration'] ?? 0 );
				if ( $end > $max_end ) {
					$max_end = $end;
				}
			}
		}
	}
	return array(
		'duration'   => $max_end > 0.2 ? $max_end : 10.0,
		'main_idx'   => $main_idx,
		'main_track' => $main_track,
	);
}

/**
 * Terapkan EDL B-Roll & SFX ke proyek untuk mode 'broll' (di atas track utama, di bawah overlay template)
 * atau mode 'assembly' (di track utama).
 */
function cc_assembly_apply_edl( $cx, $project, $edl, $brolls, $sfxs, $workflow = 'broll', $motion = 'slide' ) {
	if ( empty( $edl ) ) {
		return true;
	}
	$span       = cc_assembly_timeline_main_span( $cx, $project );
	$broll_map  = array();
	foreach ( $brolls as $b ) {
		$broll_map[ $b['id'] ] = $b;
	}
	$sfx_map = array();
	foreach ( $sfxs as $s ) {
		$sfx_map[ $s['id'] ] = $s;
	}

	// Tentukan track video target untuk B-Roll.
	if ( 'broll' === $workflow ) {
		// Sisipkan tepat di atas track utama (main_idx + 1) agar tetap berada di bawah frame/logo/caption template!
		$at = $cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array(
					'op'       => 'addTrack',
					'kind'     => 'video',
					'insertAt' => (int) $span['main_idx'] + 1,
				),
			),
			20
		);
		if ( is_wp_error( $at ) ) {
			return $at;
		}
		$video_track = (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
	} else {
		$video_track = (string) $span['main_track'];
	}

	// Cek apakah ada SFX yang perlu dipasang; bila ya, siapkan 1 track audio khusus SFX.
	$sfx_track = '';
	foreach ( $edl as $cut ) {
		if ( ! empty( $cut['sfx_id'] ) && isset( $sfx_map[ $cut['sfx_id'] ] ) ) {
			$st = $cx->call(
				'edit.apply',
				array(
					'path'    => $project,
					'command' => array( 'op' => 'addTrack', 'kind' => 'audio', 'bottom' => true ),
				),
				20
			);
			if ( ! is_wp_error( $st ) ) {
				$sfx_track = (string) ( $st['createdId'] ?? $st['created_id'] ?? '' );
			}
			break;
		}
	}

	$imported = array();
	$park_pos = max( 3600.0, (float) $span['duration'] * 4 + 600.0 );

	foreach ( $edl as $i => $cut ) {
		$binfo = $broll_map[ $cut['broll_id'] ] ?? null;
		if ( ! $binfo ) {
			continue;
		}
		$imp = cc_assembly_import_media( $cx, $project, $binfo['file'], $imported );
		if ( is_wp_error( $imp ) ) {
			return $imp;
		}
		$res = cc_assembly_place_clip(
			$cx,
			$project,
			$video_track,
			$imp['id'],
			$imp['duration'],
			(float) $cut['timeline_start'],
			(float) $cut['source_start'],
			(float) $cut['duration'],
			array(
				'fill'    => true,
				'muted'   => true,
				'fadeIn'  => 'broll' === $workflow ? 0.12 : 0.0,
				'fadeOut' => 'broll' === $workflow ? 0.12 : 0.0,
				'motion'  => (string) $motion,
				'name'    => basename( $binfo['file'] ),
			),
			$park_pos + ( $i * 120.0 )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Pasang SFX transisi di awal kemunculan klip bila ada.
		$sid = (string) ( $cut['sfx_id'] ?? '' );
		if ( '' !== $sfx_track && '' !== $sid && isset( $sfx_map[ $sid ] ) ) {
			$sinfo = $sfx_map[ $sid ];
			$simp  = cc_assembly_import_media( $cx, $project, $sinfo['file'], $imported );
			if ( ! is_wp_error( $simp ) ) {
				$sfx_start = max( 0.0, (float) $cut['timeline_start'] - 0.1 );
				$sfx_dur   = min( 2.5, $simp['duration'] );
				cc_assembly_place_clip(
					$cx,
					$project,
					$sfx_track,
					$simp['id'],
					$simp['duration'],
					$sfx_start,
					0.0,
					$sfx_dur,
					array(
						'fill'    => false,
						'volume'  => 0.65,
						'fadeOut' => 0.15,
						'name'    => basename( $sinfo['file'] ),
					),
					$park_pos + 5000.0 + ( $i * 60.0 )
				);
			}
		}
	}

	return true;
}

/**
 * Jalankan workflow 'assembly' penuh (1 Voice Over MP3 + koleksi B-Roll + SFX → 1 proyek utuh).
 * Returns array{body:string, meta:array}|WP_Error.
 */
function cc_assembly_run_vo( $cx, &$ctx, $p, $step, $session_id ) {
	$vo_file   = wp_normalize_path( (string) ( $p['vo_file'] ?? $p['file'] ?? '' ) );
	$broll_dir = wp_normalize_path( (string) ( $p['broll_dir'] ?? '' ) );
	$sfx_dir   = wp_normalize_path( (string) ( $p['sfx_dir'] ?? '' ) );

	if ( '' === $vo_file || ! file_exists( $vo_file ) || ! CC_Tools::read_allowed( $vo_file ) ) {
		return new WP_Error( 'cc_vo', 'File Voice Over tidak ditemukan atau di luar folder yang diizinkan: ' . $vo_file );
	}
	if ( '' === $broll_dir || ! is_dir( $broll_dir ) ) {
		return new WP_Error( 'cc_broll', 'Folder B-Roll belum dipilih atau tidak ditemukan: ' . $broll_dir );
	}

	// 1. Katalogisasi B-Roll & SFX.
	$catalog = $step(
		'Memindai koleksi B-Roll' . ( ! empty( $p['broll_vision'] ) ? ' (+ AI Vision)' : '' ) . ( '' !== $sfx_dir ? ' & SFX' : '' ),
		function () use ( $broll_dir, $sfx_dir, $p ) {
			$brolls = cc_assembly_catalog_dir( $broll_dir, 'broll', ! empty( $p['broll_vision'] ) );
			if ( is_wp_error( $brolls ) ) {
				return $brolls;
			}
			if ( empty( $brolls ) ) {
				return new WP_Error( 'cc_broll_empty', 'Tidak ada file video (.mp4/.mov/.webm) di folder B-Roll: ' . $broll_dir );
			}
			$sfxs = array();
			if ( '' !== $sfx_dir ) {
				$s = cc_assembly_catalog_dir( $sfx_dir, 'sfx', false );
				if ( ! is_wp_error( $s ) ) {
					$sfxs = $s;
				}
			}
			return array( 'brolls' => $brolls, 'sfxs' => $sfxs );
		}
	);

	// 2. Probe durasi VO & transkripsi.
	$vo_info = cc_assembly_probe_local( $vo_file );
	$vo_dur  = max( 1.0, (float) $vo_info['duration'] );

	$srt_txt = '';
	if ( 'none' !== ( $p['cap_lang'] ?? 'id' ) ) {
		$srt_txt = $step(
			'Menganalisis & mentranskrip Voice Over',
			function () use ( $vo_file, $p ) {
				$s = cc_assembly_ensure_srt( $vo_file, (string) ( $p['cap_lang'] ?? 'id' ), (string) ( $p['srt'] ?? '' ), '' );
				// Jika pengguna belum mengatur key Whisper (mis. memakai Ollama/OpenRouter tanpa key OpenAI/Groq),
				// tetap lanjutkan perakitan visual secara ritmis tanpa gagal total.
				return is_wp_error( $s ) ? '' : $s;
			}
		);
	}

	// 3. AI Director menyusun EDL.
	$edl = $step(
		'AI Director menyusun storyboard (' . count( $catalog['brolls'] ) . ' bahan video)',
		function () use ( $srt_txt, $vo_dur, $catalog, $p ) {
			$plan = cc_assembly_plan_edl(
				$srt_txt,
				$vo_dur,
				$catalog['brolls'],
				$catalog['sfxs'],
				array(
					'workflow' => 'assembly',
					'pacing'   => (string) ( $p['broll_pacing'] ?? 'calm' ),
					'prompt'   => (string) ( $p['broll_prompt'] ?? '' ),
				)
			);
			if ( empty( $plan ) ) {
				return new WP_Error( 'cc_edl', 'Gagal menyusun jadwal klip B-Roll.' );
			}
			return $plan;
		}
	);

	// 4. Siapkan proyek dari template atau proyek baru.
	$project = $step(
		'Menyiapkan proyek "' . ( $p['name'] ?? 'project' ) . '"',
		function () use ( $cx, $p ) {
			if ( ! empty( $p['template'] ) ) {
				$res = $cx->call(
					'template.instantiate',
					array(
						'template' => $p['template'],
						'location' => untrailingslashit( cc_ws_projects() ),
						'name'     => sanitize_file_name( $p['name'] ?? 'project' ),
						'fills'    => array(),
					),
					60
				);
			} else {
				wp_mkdir_p( cc_ws_projects() );
				$dims = (array) cc_aspect_dims( $p['aspect'] ?? '9:16' );
				$res  = $cx->call(
					'project.create',
					array(
						'location' => untrailingslashit( cc_ws_projects() ),
						'name'     => sanitize_file_name( $p['name'] ?? 'project' ),
						'video'    => array_merge( $dims, array( 'rateNum' => 30, 'rateDen' => 1 ) ),
					),
					30
				);
				if ( ! is_wp_error( $res ) ) {
					$path = $res['path'] ?? $res['project']['path'] ?? ( cc_ws_projects() . sanitize_file_name( $p['name'] ?? 'project' ) );
					$cx->call(
						'project.setVideo',
						array(
							'path'  => wp_normalize_path( (string) $path ),
							'video' => array_merge( $dims, array( 'rateNum' => 30, 'rateDen' => 1 ) ),
						),
						30
					);
				}
			}
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return $res['path'] ?? $res['project']['path'] ?? ( cc_ws_projects() . sanitize_file_name( $p['name'] ?? 'project' ) );
		}
	);
	$project = wp_normalize_path( (string) $project );
	cc_control_track_project( $project );

	// 5. Bersihkan clip video placeholder di track utama (bila dari template), pasang VO, lalu susun B-Roll & SFX.
	$step(
		'Merakit Voice Over, ' . count( $edl ) . ' klip B-Roll & SFX di timeline',
		function () use ( $cx, $project, $vo_file, $vo_dur, $edl, $catalog ) {
			$g = $cx->call( 'project.get', array( 'path' => $project ), 20 );
			if ( is_wp_error( $g ) ) {
				return $g;
			}
			$active_tl  = (string) ( $g['project']['activeTimelineId'] ?? '' );
			$main_track = 'T1';
			$rm_clips   = array();
			foreach ( (array) ( $g['project']['timelines'] ?? array() ) as $tl ) {
				if ( '' !== $active_tl && ( $tl['id'] ?? '' ) !== $active_tl ) {
					continue;
				}
				foreach ( (array) ( $tl['tracks'] ?? array() ) as $tr ) {
					if ( ! empty( $tr['isMain'] ) ) {
						$main_track = (string) $tr['id'];
						break;
					}
				}
				foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
					if ( ( $c['trackId'] ?? '' ) === $main_track && 'video' === ( $c['kind'] ?? '' ) ) {
						$rm_clips[] = (string) $c['id'];
					}
				}
			}
			if ( $rm_clips ) {
				$cx->call(
					'edit.apply',
					array(
						'path'    => $project,
						'command' => array( 'op' => 'removeClips', 'clipIds' => $rm_clips, 'ripple' => false ),
					),
					20
				);
			}

			// Buat track audio untuk Voice Over & pasang di 0.0s.
			$at = $cx->call(
				'edit.apply',
				array(
					'path'    => $project,
					'command' => array( 'op' => 'addTrack', 'kind' => 'audio', 'bottom' => true ),
				),
				20
			);
			if ( is_wp_error( $at ) ) {
				return $at;
			}
			$vo_track = (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
			$imported = array();
			$vo_imp   = cc_assembly_import_media( $cx, $project, $vo_file, $imported );
			if ( is_wp_error( $vo_imp ) ) {
				return $vo_imp;
			}
			$vo_res = cc_assembly_place_clip(
				$cx,
				$project,
				$vo_track,
				$vo_imp['id'],
				$vo_imp['duration'],
				0.0,
				0.0,
				$vo_dur,
				array( 'fill' => false, 'volume' => 1.0, 'name' => basename( $vo_file ) ),
				7200.0
			);
			if ( is_wp_error( $vo_res ) ) {
				return $vo_res;
			}

			// Pasang seluruh B-Roll di track utama + SFX di track audio.
			return cc_assembly_apply_edl( $cx, $project, $edl, $catalog['brolls'], $catalog['sfxs'], 'assembly', (string) ( $p['broll_motion'] ?? 'slide' ) );
		}
	);

	// 6. Tambahkan subtitle dari VO bila tersedia.
	$warn = '';
	if ( '' !== trim( $srt_txt ) ) {
		$tid = cc_message_add( $session_id, 'task', 'Menambahkan subtitle Voice Over', array( 'tool' => 'control', 'status' => 'running', 'progress' => 0 ), 'running' );
		$st  = cc_control_caption_style( $cx, $project );
		if ( $st['clips'] ) {
			$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'removeClips', 'clipIds' => $st['clips'], 'ripple' => false ) ), 30 );
		}
		foreach ( $st['sources'] as $sid ) {
			$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'removeCaptionSource', 'sourceId' => $sid ) ), 30 );
		}
		$r  = $cx->call(
			'edit.apply',
			array(
				'path'    => $project,
				'command' => array(
					'op'      => 'addCaptionSource',
					'name'    => 'vo-subs',
					'srt'     => $srt_txt,
					'style'   => $st['style'],
					'offsetY' => $st['offsetY'],
				),
			),
			60
		);
		$ok = ! is_wp_error( $r );
		if ( $ok ) {
			$sid  = (string) ( $r['createdId'] ?? $r['created_id'] ?? '' );
			$lane = (string) ( $st['lane'] ?? '' );
			if ( '' === $lane ) {
				$at   = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'captions' ) ), 30 );
				$lane = is_wp_error( $at ) ? '' : (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
			}
			if ( '' !== $sid && '' !== $lane ) {
				$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'placeCaptionSource', 'sourceId' => $sid, 'start' => 0.0, 'trackId' => $lane ) ), 60 );
			}
		}
		cc_message_update( $tid, array( 'status' => $ok ? 'done' : 'failed', 'meta' => array( 'tool' => 'control', 'status' => $ok ? 'done' : 'failed', 'progress' => 100 ) ) );
	}

	// 7. Simpan & handoff / export.
	$step(
		'Menyimpan proyek',
		function () use ( $cx, $project ) {
			$r = $cx->call( 'project.save', array( 'path' => $project ), 30 );
			return is_wp_error( $r ) ? $r : true;
		}
	);

	if ( 'export' !== ( $p['action'] ?? 'handoff' ) ) {
		$step(
			'Menutup proyek (serah ke Studio Inspirasi)',
			function () use ( $cx, $project ) {
				$r = $cx->call( 'project.close', array( 'path' => $project ), 15 );
				cc_control_track_project( '' );
				return is_wp_error( $r ) ? $r : true;
			}
		);
		return array(
			'body' => 'Proyek **' . ( $p['name'] ?? 'project' ) . '** (' . count( $edl ) . ' klip B-Roll, durasi ' . cc_fmt_time( $vo_dur ) . ') siap di `' . $project . '` — buka di Studio Inspirasi untuk ditinjau & diekspor.' . $warn,
			'meta' => array( 'project' => $project ),
		);
	}

	$out_name = ! empty( $p['output_name'] ) ? $p['output_name'] : ( ( $p['name'] ?? 'export' ) . '.mp4' );
	$export   = $step(
		'Mengekspor video → ' . $out_name,
		function () use ( &$ctx, $project, $out_name ) {
			$res = CC_Tools::export_run( $ctx, $project, $out_name );
			if ( is_string( $res ) && 0 === strpos( $res, 'ERROR ' ) ) {
				return new WP_Error( 'cc_export', substr( $res, 6 ) );
			}
			return $res;
		}
	);
	$out_path = wp_normalize_path( (string) ( $export['output'] ?? '' ) );
	$cx->call( 'project.close', array( 'path' => $project ), 15 );
	cc_control_track_project( '' );

	return array(
		'body' => 'Video selesai dirakit & diekspor (' . count( $edl ) . ' klip B-Roll): `' . $out_path . '`.' . $warn,
		'meta' => array(
			'project'    => $project,
			'video_path' => $out_path,
			'video_url'  => cc_path_to_url( $out_path ),
		),
	);
}
