<?php
/**
 * Workflow 'cartoon' — rakit video animasi karakter dari storyboard.
 *
 * Katalog sprite PNG per karakter (CSV opsional), AI Director memilih
 * look/ekspresi/SFX/B-roll per scene, lalu generator koreografi memasang
 * mouth-flap, walk-cycle, zoom, audio dialog, BGM, dan caption — semuanya
 * command resmi (addClip/setClipTransform/updateClip/addTrack/batch) pada
 * project.create kosong. Terisolasi dari clip/broll/assembly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================== KATALOG ============================== */

/**
 * Scan folder characters/<nama>/ — parse <nama>.csv (kolom bebas; `file`
 * wajib, sisanya jadi metadata) + fallback nama file `<nama>-<varian>.png`.
 * Returns array{ chars: array<string,array{dir:string,sprites:array, warns:array}>, warns: array }.
 */
function cc_cartoon_catalog_chars( $dir ) {
	$chars = array();
	$warns = array();
	if ( ! is_dir( $dir ) ) {
		return array( 'chars' => $chars, 'warns' => array( 'Folder karakter tidak ditemukan: ' . $dir ) );
	}
	foreach ( scandir( $dir ) as $name ) {
		if ( '.' === $name[0] || ! is_dir( $dir . '/' . $name ) ) {
			continue;
		}
		$cdir    = $dir . '/' . $name;
		$sprites = array();
		$seen    = array();
		$w       = array();
		// CSV opsional: <nama>.csv — file,look,ekspresi,varian,height[,...].
		$csv = $cdir . '/' . $name . '.csv';
		if ( is_file( $csv ) && ( $fh = fopen( $csv, 'r' ) ) ) {
			$hdr = fgetcsv( $fh );
			$idx = array();
			foreach ( (array) $hdr as $i => $h ) {
				$idx[ strtolower( trim( (string) $h ) ) ] = $i;
			}
			$fidx = isset( $idx['file'] ) ? $idx['file'] : ( isset( $idx['nama_file'] ) ? $idx['nama_file'] : 1 );
			while ( ( $row = fgetcsv( $fh ) ) !== false ) {
				$f = isset( $row[ $fidx ] ) ? trim( (string) $row[ $fidx ] ) : '';
				if ( '' === $f || ! is_file( $cdir . '/' . $f ) ) {
					$w[] = 'baris CSV tanpa file: ' . ( '' !== $f ? $f : wp_json_encode( $row ) );
					continue;
				}
				$sp = array(
					'file'     => $cdir . '/' . $f,
					'look'     => trim( (string) ( $row[ $idx['look'] ?? $idx['baju'] ?? -1 ] ?? '' ) ) ?: 'default',
					'ekspresi' => trim( (string) ( $row[ $idx['ekspresi'] ?? -1 ] ?? '' ) ) ?: 'netral',
					'varian'   => trim( (string) ( $row[ $idx['varian'] ?? $idx['mouth'] ?? $idx['pose'] ?? -1 ] ?? '' ) ) ?: 'diam',
					'height'   => (float) ( $row[ $idx['height'] ?? -1 ] ?? 0.7 ) ?: 0.7,
					'desc'     => '',
				);
				// kolom sisa → desc semantik untuk AI.
				foreach ( $idx as $k => $i ) {
					if ( ! in_array( $k, array( 'no', 'file', 'nama_file', 'look', 'baju', 'ekspresi', 'varian', 'mouth', 'pose', 'height' ), true ) && isset( $row[ $i ] ) ) {
						$sp['desc'] .= ' ' . trim( (string) $row[ $i ] );
					}
				}
				$sp['desc'] = trim( $sp['desc'] );
				$sprites[]  = $sp;
				$seen[ $f ] = true;
			}
			fclose( $fh );
		}
		// Fallback: PNG tanpa baris CSV → varian dari nama file.
		foreach ( glob( $cdir . '/*.png' ) ?: array() as $p ) {
			$base = basename( $p );
			if ( isset( $seen[ $base ] ) ) {
				continue;
			}
			$stem = strtolower( preg_replace( '/\.png$/i', '', $base ) );
			$toks = preg_split( '/[\-_ ]+/', $stem );
			$var  = end( $toks );
			if ( in_array( $var, array( 'kanan', 'kiri' ), true ) && count( $toks ) > 1 ) {
				$var = $toks[ count( $toks ) - 2 ] . '_' . $var;
			}
			// Token tak dikenal (mis. nama karakter saja) → anggap 'diam'.
			$known = array( 'diam', 'bicara', 'jalan', 'jalan_kanan', 'jalan_kiri', 'masuk', 'keluar', 'zoom', 'kaget', 'senang', 'sedih', 'marah', 'netral' );
			if ( ! in_array( $var, $known, true ) ) {
				$var = 'diam';
			}
			$w[]        = 'PNG tak tercatat di CSV: ' . $base;
			$sprites[]  = array( 'file' => $p, 'look' => 'default', 'ekspresi' => 'netral', 'varian' => $var ?: 'diam', 'height' => 0.7, 'desc' => $stem );
		}
		$chars[ $name ] = array( 'dir' => $cdir, 'sprites' => $sprites, 'warns' => $w );
		if ( $w ) {
			$warns[] = $name . ': ' . count( $w ) . ' catatan';
		}
	}
	return array( 'chars' => $chars, 'warns' => $warns );
}

/** Katalog SFX dari folder + sfx.csv opsional (file,jenis,deskripsi,cocok_untuk). */
function cc_cartoon_catalog_sfx( $dir ) {
	$items = array();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $items;
	}
	$csv = rtrim( $dir, '/' ) . '/sfx.csv';
	$map = array();
	if ( is_file( $csv ) && ( $fh = fopen( $csv, 'r' ) ) ) {
		$hdr = fgetcsv( $fh );
		$idx = array();
		foreach ( (array) $hdr as $i => $h ) {
			$idx[ strtolower( trim( (string) $h ) ) ] = $i;
		}
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$f = trim( (string) ( $row[ $idx['file'] ?? 0 ] ?? '' ) );
			if ( '' !== $f ) {
				$map[ $f ] = $row;
			}
		}
		fclose( $fh );
	}
	foreach ( glob( rtrim( $dir, '/' ) . '/*.{mp3,wav,m4a,ogg}', GLOB_BRACE ) ?: array() as $p ) {
		$base  = basename( $p );
		$row   = $map[ $base ] ?? array();
		$items[] = array(
			'file' => $p,
			'id'   => 's' . substr( md5( $p ), 0, 6 ),
			'name' => $base,
			'jenis' => strtolower( trim( (string) ( $row[ $idx['jenis'] ?? 1 ] ?? '' ) ) ) ?: 'sfx',
			'desc' => trim( (string) ( $row[ $idx['deskripsi'] ?? 2 ] ?? '' ) ) . ' ' . trim( (string) ( $row[ $idx['cocok_untuk'] ?? 3 ] ?? '' ) ),
			'dur'  => function_exists( 'cc_assembly_probe_local' ) ? (float) ( cc_assembly_probe_local( $p )['duration'] ?? 0 ) : 0.0,
		);
	}
	return $items;
}

/* ========================== NORMALISASI PNG ========================== */

/**
 * Jangkar kaki: cropdetect ffmpeg → fraksi kaki (bawah-tengah) pada kanvas.
 * Feet dijadikan offsetY sehingga semua pose menjejak titik sama.
 * Returns float fraksi 0..1 (titik kaki relatif tinggi kanvas), 1.0 default.
 */
function cc_cartoon_norm_anchor( $file ) {
	static $cache = array();
	if ( isset( $cache[ $file ] ) ) {
		return $cache[ $file ];
	}
	$ff  = function_exists( 'cc_control_ffmpeg_bin' ) ? cc_control_ffmpeg_bin() : 'ffmpeg';
	$out = @shell_exec( sprintf( '%s -i %s -vf cropdetect -f null - 2>&1', escapeshellarg( $ff ), escapeshellarg( $file ) ) );
	$frac = array( 'feet' => 1.0, 'vis_h' => 1.0 );
	if ( $out && preg_match( '/crop=(\d+):(\d+):(\d+):(\d+)/', $out, $m ) ) {
		$crop_h   = (int) $m[2];
		$crop_y   = (int) $m[4];
		$img_h    = 0;
		if ( preg_match( '/Stream.*Video:.*?\s(\d+)x(\d+)[,\s]/', $out, $d ) ) {
			$img_h = (int) $d[2];
		}
		if ( $img_h > 0 ) {
			$frac['feet']  = min( 1.0, max( 0.0, ( $crop_y + $crop_h ) / $img_h ) );
			$frac['vis_h'] = min( 1.0, max( 0.05, $crop_h / $img_h ) );
		}
	}
	$cache[ $file ] = $frac;
	return $frac;
}

/** Fraksi kaki pada kanvas (kompat lama). */
function cc_cartoon_feet( $file ) {
	return (float) cc_cartoon_norm_anchor( $file )['feet'];
}

/**
 * Transform karakter: scale = height/vis_h (bagian terlihat PNG setinggi
 * `height` fraksi frame, ruang transparan tak ikut membesar), offsetY agar
 * kaki menjejak $feet_line, offsetX posisi horizontal.
 */
function cc_cartoon_char_tf( $file, $height, $feet_line, $ox ) {
	$a     = cc_cartoon_norm_anchor( $file );
	$vis   = max( 0.05, (float) $a['vis_h'] );
	$scale = min( 4.0, $height / $vis );
	return array( 'scale' => $scale, 'offsetX' => $ox, 'offsetY' => $feet_line - $scale * ( (float) $a['feet'] - 0.5 ) );
}

/* ============================ AI PLANNER ============================ */

/**
 * Pilih sprite untuk aksi. Rantai fallback: look+expr+varian →
 * look+netral+varian → varian apa pun → null.
 */
function cc_cartoon_pick_sprite( $sprites, $look, $expr, $varian ) {
	$cands = array(
		array( $look, $expr, $varian ),
		array( $look, 'netral', $varian ),
		array( 'default', $expr, $varian ),
		array( 'default', 'netral', $varian ),
		array( null, null, $varian ),
	);
	foreach ( $cands as $c ) {
		foreach ( (array) $sprites as $sp ) {
			if ( null !== $c[0] && $sp['look'] !== $c[0] ) {
				continue;
			}
			if ( null !== $c[1] && $sp['ekspresi'] !== $c[1] ) {
				continue;
			}
			if ( $sp['varian'] !== $c[2] ) {
				continue;
			}
			return $sp;
		}
	}
	// Fallback terakhir: sprite apa pun — karakter satu PNG tetap tampil.
	return $sprites[0] ?? null;
}

/** Pilihan AI per scene: look lock per karakter + ekspresi/sfx/broll per baris. */
function cc_cartoon_plan_scene( $scene, $chars_cat, $sfx_cat, $broll_cat, $global_prompt ) {
	$rows = (array) ( $scene['rows'] ?? array() );
	$plan = array( 'looks' => array(), 'rows' => array() );
	// Ringkas katalog untuk prompt.
	$chars_brief = array();
	foreach ( (array) ( $scene['chars'] ?? array() ) as $cn ) {
		if ( ! isset( $chars_cat['chars'][ $cn ] ) ) {
			continue;
		}
		$looks = array();
		foreach ( $chars_cat['chars'][ $cn ]['sprites'] as $sp ) {
			$looks[ $sp['look'] ] = true;
		}
		$exprs = array();
		foreach ( $chars_cat['chars'][ $cn ]['sprites'] as $sp ) {
			$exprs[ $sp['ekspresi'] ] = true;
		}
		$chars_brief[ $cn ] = 'looks:' . implode( ',', array_keys( $looks ) ) . ' expr:' . implode( ',', array_keys( $exprs ) );
	}
	$sfx_brief = array();
	foreach ( (array) $sfx_cat as $s ) {
		$sfx_brief[] = $s['id'] . '=' . $s['name'] . ( '' !== $s['desc'] ? '(' . $s['desc'] . ')' : '' );
	}
	$rows_brief = array();
	foreach ( $rows as $i => $r ) {
		$rows_brief[] = ( $i + 1 ) . '. ' . ( $r['char'] ?? '?' ) . ' ' . ( $r['action'] ?? '?' ) .
			( '' !== (string) ( $r['text'] ?? '' ) ? ' "' . mb_substr( $r['text'], 0, 40 ) . '"' : '' );
	}
	$prompt_scene = (string) ( $scene['prompt'] ?? '' );
	$sys = 'Anda sutradara animasi kartun eksplainer. Tugas: pilih look (baju/pose) per karakter — SATU look per scene (kontinuitas), ekspresi per baris bicara (dari daftar ekspresi valid), sfx_id yang cocok (opsional), dan broll_id + waktu sisipan bila prompt memintanya. Jawab HANYA JSON: {"looks":{"char":"look"},"rows":[{"expr":"..","sfx_id":"..","broll_id":"..","broll_start":0.0}]} — satu objek rows per baris, tanpa field selain yang diminta, null bila tidak perlu.';
	$usr = "PROMPT GLOBAL: {$global_prompt}\nPROMPT SCENE: {$prompt_scene}\nKARAKTER: " . wp_json_encode( $chars_brief ) . "\nSFX: " . implode( '; ', $sfx_brief ) . "\nBARIS:\n" . implode( "\n", $rows_brief );
	if ( function_exists( 'cc_ai_configured' ) && cc_ai_configured() ) {
		$res = CC_AI::chat( array( array( 'role' => 'system', 'content' => $sys ), array( 'role' => 'user', 'content' => $usr ) ) );
		if ( ! is_wp_error( $res ) ) {
			$j = json_decode( trim( (string) ( $res['message']['content'] ?? '' ) ), true );
			if ( is_array( $j ) ) {
				if ( isset( $j['looks'] ) && is_array( $j['looks'] ) ) {
					$plan['looks'] = $j['looks'];
				}
				if ( isset( $j['rows'] ) && is_array( $j['rows'] ) ) {
					foreach ( array_values( $j['rows'] ) as $i => $pr ) {
						if ( isset( $rows[ $i ] ) && is_array( $pr ) ) {
							$plan['rows'][ $i ] = $pr;
						}
					}
				}
			}
		}
	}
	// Default deterministik: look pertama tiap karakter, expr netral.
	foreach ( (array) ( $scene['chars'] ?? array() ) as $cn ) {
		if ( ! isset( $plan['looks'][ $cn ] ) && isset( $chars_cat['chars'][ $cn ]['sprites'][0] ) ) {
			$plan['looks'][ $cn ] = $chars_cat['chars'][ $cn ]['sprites'][0]['look'];
		}
	}
	foreach ( $rows as $i => $r ) {
		if ( ! isset( $plan['rows'][ $i ] ) ) {
			$plan['rows'][ $i ] = array();
		}
		if ( empty( $plan['rows'][ $i ]['expr'] ) ) {
			$plan['rows'][ $i ]['expr'] = 'netral';
		}
	}
	return $plan;
}

/* ============================ GENERATOR ============================ */

/** SRT timestamp dari detik → "HH:MM:SS,mmm". */
function cc_cartoon_srt_ts( $t ) {
	$ms = (int) round( ( $t - floor( $t ) ) * 1000 );
	$s  = (int) floor( $t ) % 60;
	$m  = (int) ( floor( $t ) / 60 ) % 60;
	$h  = (int) ( floor( $t ) / 3600 );
	return sprintf( '%02d:%02d:%02d,%03d', $h, $m, $s, $ms );
}

/**
 * Dari scene plan → proyek Studio Inspirasi.
 * Dua fase: (1) batch addClip semua elemen, (2) project.get →
 * setClipTransform per klip karakter (transform bukan bagian ClipPatch
 * dan id klip baru diketahui setelah penempatan).
 * Track layout (atas→bawah): captions · karakter N..1 · B-roll · T1 latar · Dialog/SFX/BGM.
 */
/** Durasi tiap baris: bicara = max(audio probe, dur); sisanya dur (default 2s). */
function cc_cartoon_row_spans( $rows ) {
	$spans = array();
	foreach ( (array) $rows as $i => $r ) {
		$d = (float) ( $r['dur'] ?? 0 );
		if ( 'bicara' === ( $r['action'] ?? '' ) && '' !== (string) ( $r['audio'] ?? '' ) && is_file( (string) $r['audio'] ) ) {
			$ap = cc_assembly_probe_local( $r['audio'] );
			$ad = (float) ( $ap['duration'] ?? 0 );
			$d  = max( $d, $ad > 0 ? $ad : $d );
		}
		$spans[ $i ] = $d > 0 ? $d : 2.0;
	}
	return $spans;
}

/** Bangun katalog sprite utk kartu review: id → {path,name,duration}. */
function cc_cartoon_sprite_cat( $chars_cat ) {
	$out = array();
	foreach ( (array) ( $chars_cat['chars'] ?? array() ) as $cn => $ch ) {
		foreach ( (array) $ch['sprites'] as $sp ) {
			$id       = 'sp' . substr( md5( $sp['file'] ), 0, 8 );
			$out[ $id ] = array( 'id' => $id, 'path' => $sp['file'], 'name' => $cn . '/' . $sp['look'] . '/' . $sp['ekspresi'] . '/' . $sp['varian'], 'duration' => 5.0 );
		}
	}
	return $out;
}

/** Item kartu review per baris — sprite pilihan look/expr jadi preview. */
function cc_cartoon_review_items( $scenes, $plans, $chars_cat ) {
	$items = array();
	$t     = 0.0;
	foreach ( (array) $scenes as $si => $sc ) {
		$spans = cc_cartoon_row_spans( (array) ( $sc['rows'] ?? array() ) );
		foreach ( (array) ( $sc['rows'] ?? array() ) as $i => $r ) {
			$cn   = (string) ( $r['char'] ?? '' );
			$look = (string) ( $plans[ $si ]['looks'][ $cn ] ?? 'default' );
			$expr = (string) ( $plans[ $si ]['rows'][ $i ]['expr'] ?? 'netral' );
			$act  = (string) ( $r['action'] ?? 'diam' );
			$var  = 'bicara' === $act ? 'diam' : ( 'jalan' === $act ? 'jalan_kanan' : 'diam' );
			$spk  = cc_cartoon_pick_sprite( (array) ( $chars_cat['chars'][ $cn ]['sprites'] ?? array() ), $look, $expr, $var );
			$items[] = array(
				'broll_id'       => $spk ? 'sp' . substr( md5( $spk['file'] ), 0, 8 ) : '',
				'timeline_start' => round( $t, 3 ),
				'duration'       => round( (float) $spans[ $i ], 3 ),
				'source_start'   => 0,
				'sfx_id'         => (string) ( $plans[ $si ]['rows'][ $i ]['sfx_id'] ?? '' ),
				'reason'         => trim( $cn . ' ' . $act . ' · ' . $look . '/' . $expr . ' ' . (string) ( $r['text'] ?? '' ) ),
			);
			$t += (float) $spans[ $i ];
		}
	}
	return array( 'items' => $items, 'dur' => $t );
}

function cc_cartoon_generate( $cx, $project, $scenes, $plans, $chars_cat, $sfx_cat, $brolls, $p ) {
	$flap_rate_fast = 0.25; $flap_rate_slow = 0.33; $flap_cap = 200;
	$walk_step      = 0.3;
	$feet_line      = 0.92; // fraksi tinggi frame — kaki karakter menjejak sini.
	$imported       = array();
	$warns          = array();
	$t              = 0.0;
	$cap_items      = array(); // untuk SRT.
	$char_tracks    = array();
	$fix            = array(); // clipId => {dur, actual, tf, patch} — fix pasca-pasang.
	$bgm_clip       = null;
	$dlg_tr = $sfx_tr = $br_tr = '';

	$import_file = function ( $f ) use ( $cx, $project, &$imported, &$warns ) {
		if ( isset( $imported[ $f ] ) ) {
			return $imported[ $f ];
		}
		$r = cc_assembly_import_media( $cx, $project, $f, $imported );
		if ( is_wp_error( $r ) ) {
			$warns[] = 'impor gagal: ' . basename( $f );
			return null;
		}
		return $r;
	};
	$audio_track = function () use ( $cx, $project ) {
		$at = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'audio', 'bottom' => true ) ), 20 );
		return is_wp_error( $at ) ? '' : (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
	};
	// addClip individual → createdId. addClip tidak menerima duration/patch —
	// stills selalu 5.0s, dan klip yang overlap digeser engine ke ujung klip
	// sebelumnya, jadi trimClip harus LANGSUNG setelah tiap penempatan agar
	// posisi klip berikutnya benar. Transform & patch didefer ke fase-2.
	$add_clip = function ( $trk, $imp, $start, $want_dur = null, $tf = null, $patch = null ) use ( $cx, $project, &$fix, &$warns ) {
		$r = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addClip', 'trackId' => $trk, 'mediaId' => $imp['id'], 'start' => $start ) ), 20 );
		if ( is_wp_error( $r ) ) {
			$warns[] = 'addClip: ' . $r->get_error_message();
			return null;
		}
		$cid = (string) ( $r['createdId'] ?? $r['created_id'] ?? '' );
		if ( '' === $cid ) {
			return null;
		}
		$actual = (float) ( $imp['duration'] ?? 0 ) > 0 ? (float) $imp['duration'] : 5.0;
		if ( null !== $want_dur && abs( (float) $want_dur - $actual ) > 0.05 ) {
			$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'end', 'delta' => (float) $want_dur - $actual ) ), 20 );
		}
		$fix[ $cid ] = array( 'tf' => $tf, 'patch' => $patch );
		return $cid;
	};

	// BGM di bawah segalanya.
	$bgm = (string) ( $p['bgm_file'] ?? '' );
	if ( '' !== $bgm && is_file( $bgm ) ) {
		$bgm_imp = $import_file( $bgm );
		if ( $bgm_imp ) {
			$bgm_tr = $audio_track();
			if ( '' !== $bgm_tr ) {
				$bgm_clip = $add_clip( $bgm_tr, $bgm_imp, 0.0, null, null, array( 'volume' => max( 0.0, min( 1.0, (float) ( $p['bgm_vol'] ?? 0.2 ) ) ) ) );
			}
		}
	}

	// SFX step (jenis=step) — satu item dipakai untuk semua langkah.
	$step_sfx = null;
	foreach ( (array) $sfx_cat as $s ) {
		if ( 'step' === ( $s['jenis'] ?? '' ) ) {
			$step_sfx = $s;
			break;
		}
	}

	foreach ( $scenes as $si => $sc ) {
		$splan = (array) ( $plans[ $si ] ?? array() );
		$st0   = $t;
		$rows  = (array) ( $sc['rows'] ?? array() );
		// Durasi tiap baris: bicara = max(audio probe, dur); sisanya dur (default 2s).
		$spans     = cc_cartoon_row_spans( $rows );
		$scene_dur = array_sum( $spans );

		// Latar di T1 sepanjang scene — frameClip fill memenuhi kanvas.
		$bg = (string) ( $sc['bg'] ?? '' );
		if ( '' !== $bg && is_file( $bg ) ) {
			$bgimp = $import_file( $bg );
			if ( $bgimp ) {
				$bgc = $add_clip( 'T1', $bgimp, $st0, $scene_dur, null, array( 'visualMotion' => array( 'entrance' => 1, 'exit' => 1, 'duration' => 0.4 ) ) );
				if ( $bgc ) {
					$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'frameClip', 'clipId' => $bgc, 'framing' => 'fill', 'at' => 0.0 ) ), 20 );
				}
			}
		}

		// Track per karakter — dari sc.chars MAUPUN char yang muncul di rows.
		$scene_chars = array_unique( array_merge( (array) ( $sc['chars'] ?? array() ), array_map( function ( $r ) { return (string) ( $r['char'] ?? '' ); }, $rows ) ) );
		foreach ( $scene_chars as $ci => $cn ) {
			if ( '' === $cn || isset( $char_tracks[ $cn ] ) ) {
				continue;
			}
			$at = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'video', 'top' => true ) ), 20 );
			if ( is_wp_error( $at ) ) {
				return $at;
			}
			$char_tracks[ $cn ] = (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
		}
		$char_x = array();
		$xi     = 0;
		foreach ( $scene_chars as $cn ) {
			if ( '' === $cn ) {
				continue;
			}
			$char_x[ $cn ] = 0 === $xi % 2 ? -0.22 : 0.22; // kiri, kanan, kiri, …
			$xi++;
		}

		// Baris aksi.
		$rt = $st0;
		foreach ( $rows as $i => $r ) {
			$cn     = (string) ( $r['char'] ?? '' );
			$act    = (string) ( $r['action'] ?? 'diam' );
			$dur    = (float) $spans[ $i ];
			$trk    = (string) ( $char_tracks[ $cn ] ?? '' );
			$rplan  = (array) ( ( $splan['rows'] ?? array() )[ $i ] ?? array() );
			$look   = (string) ( ( $splan['looks'] ?? array() )[ $cn ] ?? 'default' );
			$expr   = (string) ( $rplan['expr'] ?? 'netral' );
			$spr    = (array) ( $chars_cat['chars'][ $cn ]['sprites'] ?? array() );
			$height = (float) ( $spr[0]['height'] ?? 0.7 ) ?: 0.7;
			$pos_x  = (float) ( $r['x'] ?? ( $char_x[ $cn ] ?? 0.0 ) );

			if ( 'bicara' === $act || 'diam' === $act ) {
				$diam   = cc_cartoon_pick_sprite( $spr, $look, $expr, 'diam' );
				$bicara = cc_cartoon_pick_sprite( $spr, $look, $expr, 'bicara' );
				if ( ! $diam ) {
					$warns[] = "sprite diam hilang: {$cn}/{$look}/{$expr}";
				}
				$rate  = $dur >= 15 ? $flap_rate_slow : $flap_rate_fast;
				$n     = 'bicara' === $act ? min( $flap_cap, max( 1, (int) ceil( $dur / $rate ) ) ) : 1;
				$seg   = $dur / $n;
				$scale = 'bicara' === $act ? $height * 1.08 : $height;
				for ( $k = 0; $k < $n; $k++ ) {
					$spk = ( 'bicara' === $act && $bicara && $diam ) ? ( $k % 2 ? $bicara : $diam ) : ( $diam ?: $bicara );
					if ( ! $spk ) {
						break;
					}
					$imp = $import_file( $spk['file'] );
					if ( ! $imp ) {
						continue;
					}
					$add_clip( $trk, $imp, $rt + $k * $seg, $seg, cc_cartoon_char_tf( $spk['file'], $scale, $feet_line, $pos_x ) );
				}
				// Audio dialog.
				if ( 'bicara' === $act && '' !== (string) ( $r['audio'] ?? '' ) && is_file( (string) $r['audio'] ) ) {
					$imp = $import_file( $r['audio'] );
					if ( $imp ) {
						if ( '' === $dlg_tr ) {
							$dlg_tr = $audio_track();
						}
						$add_clip( $dlg_tr, $imp, $rt );
					}
				}
			} elseif ( 'jalan' === $act ) {
				$dir = (string) ( $r['dir'] ?? 'kanan' );
				$p1  = cc_cartoon_pick_sprite( $spr, $look, $expr, 'jalan_' . $dir )
					?: cc_cartoon_pick_sprite( $spr, $look, $expr, 'jalan' )
					?: cc_cartoon_pick_sprite( $spr, $look, $expr, 'diam' );
				$p2  = cc_cartoon_pick_sprite( $spr, $look, $expr, 'jalan_' . ( 'kanan' === $dir ? 'kiri' : 'kanan' ) ) ?: $p1;
				$n   = max( 1, (int) ceil( $dur / $walk_step ) );
				$seg = $dur / $n;
				$x1  = (float) ( $r['x2'] ?? -$pos_x ); // default: menyeberang ke sisi lain
				for ( $k = 0; $k < $n; $k++ ) {
					$spk = $k % 2 ? $p2 : $p1;
					if ( ! $spk ) {
						break;
					}
					$imp = $import_file( $spk['file'] );
					if ( ! $imp ) {
						continue;
					}
					$st_at = $rt + $k * $seg;
					$ox    = $pos_x + ( $x1 - $pos_x ) * ( $k / max( 1, $n - 1 ) );
					$add_clip( $trk, $imp, $st_at, $seg, cc_cartoon_char_tf( $spk['file'], $height, $feet_line, $ox ) );
					// SFX step sinkron tiap pergantian pose.
					if ( $step_sfx ) {
						if ( '' === $sfx_tr ) {
							$sfx_tr = $audio_track();
						}
						$simp = $import_file( $step_sfx['file'] );
						if ( $simp ) {
							$add_clip( $sfx_tr, $simp, $st_at, min( (float) ( $simp['duration'] ?? $seg ), $seg ) );
						}
					}
				}
			} elseif ( in_array( $act, array( 'masuk', 'keluar', 'zoom' ), true ) ) {
				$spk = cc_cartoon_pick_sprite( $spr, $look, $expr, 'diam' );
				if ( $spk && ( $imp = $import_file( $spk['file'] ) ) ) {
					$scale = 'zoom' === $act ? $height * 1.15 : $height;
					$add_clip( $trk, $imp, $rt, $dur,
						cc_cartoon_char_tf( $spk['file'], $scale, $feet_line, $pos_x ),
						array( 'visualMotion' => array( 'entrance' => 'masuk' === $act ? 2 : 0, 'exit' => 'keluar' === $act ? 2 : 0, 'duration' => 0.35 ) ) );
				}
			}
			// SFX non-step di awal baris (pilihan AI atau manual).
			$sfx_id = (string) ( $r['sfx_id'] ?? $rplan['sfx_id'] ?? '' );
			if ( '' !== $sfx_id ) {
				foreach ( (array) $sfx_cat as $s ) {
					if ( $s['id'] === $sfx_id && 'step' !== $s['jenis'] && ( $imp = $import_file( $s['file'] ) ) ) {
						if ( '' === $sfx_tr ) {
							$sfx_tr = $audio_track();
						}
						$add_clip( $sfx_tr, $imp, $rt );
						break;
					}
				}
			}
			// B-roll pilihan AI/manual di baris ini.
			$br_id = (string) ( $r['broll_id'] ?? $rplan['broll_id'] ?? '' );
			if ( '' !== $br_id ) {
				foreach ( (array) $brolls as $b ) {
					if ( ( $b['id'] ?? '' ) === $br_id && ( $imp = $import_file( $b['file'] ) ) ) {
						if ( '' === $br_tr ) {
							$at    = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'video', 'insertAt' => 1 ) ), 20 );
							$br_tr = is_wp_error( $at ) ? '' : (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
						}
						$add_clip( $br_tr, $imp, $rt, min( $dur, (float) ( $imp['duration'] ?? 3 ) ), null,
							array( 'muted' => true, 'visualMotion' => array( 'entrance' => 1, 'exit' => 1, 'duration' => 0.3 ) ) );
						break;
					}
				}
			}
			// Caption dari teks baris bicara.
			if ( 'bicara' === $act && '' !== trim( (string) ( $r['text'] ?? '' ) ) ) {
				$cap_items[] = array( $rt, $rt + $dur, (string) $r['text'] );
			}
			$rt += $dur;
		}
		$t += $scene_dur;
	}

	// BGM dipotong tepat di durasi total (sekarang, setelah $t diketahui).
	if ( $bgm_clip ) {
		$bgm_d = (float) ( $imported[ (string) $bgm ]['duration'] ?? 0 );
		if ( $bgm_d > 0 && abs( $t - $bgm_d ) > 0.05 ) {
			$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'trimClip', 'clipId' => $bgm_clip, 'edge' => 'end', 'delta' => $t - $bgm_d ) ), 20 );
		}
	}

	// Fase 2: batch setClipTransform + updateClip per clipId yang tertangkap.
	$fix_cmds = array();
	foreach ( $fix as $cid => $f ) {
		if ( $f['tf'] ) {
			$fix_cmds[] = array( 'op' => 'setClipTransform', 'clipId' => $cid, 'scale' => $f['tf']['scale'], 'offsetX' => $f['tf']['offsetX'], 'offsetY' => $f['tf']['offsetY'] );
		}
		if ( $f['patch'] ) {
			$fix_cmds[] = array( 'op' => 'updateClip', 'clipId' => $cid, 'patch' => $f['patch'] );
		}
	}
	foreach ( array_chunk( $fix_cmds, 40 ) as $chunk ) {
		$r = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => count( $chunk ) > 1 ? array( 'op' => 'batch', 'commands' => $chunk ) : $chunk[0] ), 90 );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}

	// Caption dari teks baris bicara.
	if ( $cap_items ) {
		$srt = '';
		foreach ( $cap_items as $k => $ci ) {
			$srt .= ( $k + 1 ) . "\n" . cc_cartoon_srt_ts( $ci[0] ) . ' --> ' . cc_cartoon_srt_ts( $ci[1] ) . "\n" . $ci[2] . "\n\n";
		}
		$st = cc_control_caption_style( $cx, $project );
		if ( ! is_wp_error( $st ) ) {
			$cs = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addCaptionSource', 'name' => 'dialog', 'srt' => $srt, 'offsetY' => $st['offsetY'], 'style' => $st['style'] ) ), 30 );
			if ( ! is_wp_error( $cs ) ) {
				$sid = (string) ( $cs['createdId'] ?? $cs['created_id'] ?? '' );
				if ( '' !== $sid ) {
					$ct = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'captions', 'top' => true ) ), 20 );
					if ( ! is_wp_error( $ct ) ) {
						$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'placeCaptionSource', 'sourceId' => $sid, 'trackId' => (string) ( $ct['createdId'] ?? $ct['created_id'] ?? '' ), 'start' => 0.0 ) ), 30 );
					}
				}
			}
		}
	}
	return array( 'warns' => $warns, 'duration' => $t );
}

/* ============================ ORKESTRASI ============================ */

function cc_cartoon_run( $p, $job, $session_id, $cx, $step ) {
	$scenes    = (array) ( $p['scenes'] ?? array() );
	if ( ! $scenes ) {
		throw new CC_Control_Abort( 'Storyboard kosong — tambahkan minimal satu scene.' );
	}
	$chars_dir = (string) ( $p['chars_dir'] ?? '' );
	$sfx_dir   = (string) ( $p['sfx_dir'] ?? '' );
	$broll_dir = (string) ( $p['broll_dir'] ?? '' );
	$prompt_g  = (string) ( $p['prompt'] ?? '' );

	// 1. Katalog karakter + SFX + B-roll.
	$cat = $step(
		'Memindai karakter, SFX & B-Roll',
		function () use ( $chars_dir, $sfx_dir, $broll_dir, $cx ) {
			$cc    = cc_cartoon_catalog_chars( $chars_dir );
			$sfx   = cc_cartoon_catalog_sfx( $sfx_dir );
			$broll = '' !== $broll_dir && function_exists( 'cc_assembly_catalog_dir' ) ? (array) cc_assembly_catalog_dir( $broll_dir, 'broll', false )['brolls'] : array();
			return array( 'chars' => $cc, 'sfx' => $sfx, 'brolls' => (array) $broll );
		}
	);

	// 2. AI Director per scene.
	$plans = $p['_scene_plan'] ?? null;
	if ( ! is_array( $plans ) ) {
		$plans = $step(
			'AI menyusun rencana ' . count( $scenes ) . ' scene',
			function () use ( $scenes, $cat, $prompt_g ) {
				$out = array();
				foreach ( $scenes as $si => $sc ) {
					$out[ $si ] = cc_cartoon_plan_scene( $sc, $cat['chars'], $cat['sfx'], $cat['brolls'], $prompt_g );
				}
				return $out;
			}
		);
		// 3. Gate review — baris aksi + sprite pilihan jadi item kartu.
		if ( ! empty( $p['edl_review'] ) && function_exists( 'cc_edl_review_pause' ) ) {
			$rev_items = cc_cartoon_review_items( $scenes, $plans, $cat['chars'] );
			$sp_cat    = cc_cartoon_sprite_cat( $cat['chars'] );
			$sfxs      = array();
			foreach ( (array) $cat['sfx'] as $s ) {
				$sfxs[] = array( 'id' => $s['id'], 'path' => $s['file'], 'name' => $s['name'], 'duration' => $s['dur'] );
			}
			cc_edl_review_pause(
				$job, $session_id, $p,
				array( array( 'kind' => 'cartoon', 'scenes' => $scenes, 'plans' => $plans, 'items' => $rev_items['items'], 'dur' => $rev_items['dur'], 'label' => 'Storyboard' ) ),
				array( 'brolls' => array_values( $sp_cat ), 'sfxs' => $sfxs )
			);
			return true;
		}
	}

	// 4. Proyek kosong + koreografi.
	$project = $step(
		'Membuat proyek kartun',
		function () use ( $cx, $p ) {
			$dims = array_merge( (array) cc_aspect_dims( (string) ( $p['aspect'] ?? '9:16' ) ), array( 'rateNum' => 30, 'rateDen' => 1 ) );
			$res  = $cx->call( 'project.create', array( 'location' => untrailingslashit( cc_ws_projects() ), 'name' => sanitize_file_name( (string) ( $p['name'] ?? 'kartun' ) ), 'video' => $dims ), 30 );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return $res['path'] ?? $res['project']['path'] ?? ( cc_ws_projects() . sanitize_file_name( (string) ( $p['name'] ?? 'kartun' ) ) );
		}
	);
	$project = wp_normalize_path( (string) $project );
	cc_control_track_project( $project );

	$gen = $step(
		'Merakit koreografi ' . count( $scenes ) . ' scene',
		function () use ( $cx, $project, $scenes, $plans, $cat, $p ) {
			return cc_cartoon_generate( $cx, $project, $scenes, $plans, $cat['chars'], $cat['sfx'], $cat['brolls'], $p );
		}
	);

	// 5. Save → handoff ke UI.
	$step(
		'Menyimpan proyek',
		function () use ( $cx, $project ) {
			$r = $cx->call( 'project.save', array( 'path' => $project ), 30 );
			return is_wp_error( $r ) ? $r : true;
		}
	);
	$step(
		'Menutup proyek (serah ke Studio Inspirasi)',
		function () use ( $cx, $project ) {
			$r = $cx->call( 'project.close', array( 'path' => $project ), 15 );
			cc_control_track_project( '' );
			return is_wp_error( $r ) ? $r : true;
		}
	);
	$warns = array_merge( (array) ( $gen['warns'] ?? array() ), (array) ( $cat['chars']['warns'] ?? array() ) );
	$warn  = $warns ? ' Catatan: ' . count( $warns ) . ' peringatan aset.' : '';
	return array(
		'body' => 'Proyek kartun **' . ( $p['name'] ?? 'kartun' ) . '** siap di `' . $project . '` — ' . count( $scenes ) . ' scene, ' . round( (float) ( $gen['duration'] ?? 0 ), 1 ) . 's.' . $warn,
		'meta' => array( 'workflow' => 'cartoon', 'scenes' => count( $scenes ), 'duration' => (float) ( $gen['duration'] ?? 0 ), 'warns' => $warns ),
	);
}
