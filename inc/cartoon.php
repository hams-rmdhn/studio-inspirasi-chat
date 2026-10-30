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
 * Transform karakter dengan semantik engine yang benar:
 *   - offset  = fraksi frame DIGESER dari pusat (bukan posisi absolut)
 *   - scale   = pengali ukuran "fitted" (media di-fit ke frame)
 *   - fitted_h = fraksi tinggi frame yang ditempati klip sebelum scale
 * Tinggi terlihat = fitted_h * scale * vis_h = height → kaki menjejak
 * feet_line lewat offsetY.
 */
function cc_cartoon_char_tf( $file, $height, $feet_line, $ox, $ai_frame = 0.5625 ) {
	$a   = cc_cartoon_norm_anchor( $file );
	$vis = max( 0.05, (float) $a['vis_h'] );
	$pr  = function_exists( 'cc_assembly_probe_local' ) ? cc_assembly_probe_local( $file ) : array();
	$iw  = (float) ( $pr['width'] ?? 0 );
	$ih  = (float) ( $pr['height'] ?? 0 );
	$fit = 1.0;
	if ( $iw > 0 && $ih > 0 ) {
		$fit = min( 1.0, $ai_frame / ( $iw / $ih ) ); // lebar terpenuhi bila gambar lebih lebar dari frame
	}
	$sc      = min( 4.0, $height / ( $fit * $vis ) );
	$clip_h  = $fit * $sc; // fraksi tinggi klip pasca-scale
	$oy      = $feet_line - 0.5 - $clip_h * ( (float) $a['feet'] - 0.5 );
	return array( 'scale' => $sc, 'offsetX' => $ox, 'offsetY' => $oy );
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
	$ap             = explode( ':', (string) ( $p['aspect'] ?? '9:16' ) );
	$ai_frame       = isset( $ap[1] ) && (float) $ap[1] > 0 ? (float) $ap[0] / (float) $ap[1] : 0.5625;
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
	// Track step per karakter — baris `par` membuat dua karakter jalan
	// bersamaan; satu track bersama membuat klip saling bertumpuk dan
	// digeser engine ke belakang blok (langkah terdengar saat sudah diam).
	$step_tracks = array();

	foreach ( $scenes as $si => $sc ) {
		$splan = (array) ( $plans[ $si ] ?? array() );
		$st0   = $t;
		$rows  = (array) ( $sc['rows'] ?? array() );
		// Durasi tiap baris: bicara = max(audio probe, dur); sisanya dur (default 2s).
		$spans     = cc_cartoon_row_spans( $rows );
		$scene_dur = 0.0;
		foreach ( $rows as $i => $r ) {
			if ( empty( $r['par'] ) ) {
				$scene_dur += $spans[ $i ];
			}
		}

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

		// Baris aksi — baris `par` berjalan bersamaan dengan baris sebelumnya.
		$rt      = $st0;
		$rt_prev = $st0;
		$cover   = array(); // cn => [[start,end],…] rentang aksi terpasang
		$xtl     = array(); // cn => [[t_end,x],…] riwayat posisi karakter
		foreach ( $rows as $i => $r ) {
			$cn     = (string) ( $r['char'] ?? '' );
			$act    = (string) ( $r['action'] ?? 'diam' );
			$row_t  = ! empty( $r['par'] ) ? $rt_prev : $rt;
			$dur    = (float) $spans[ $i ];
			$trk    = (string) ( $char_tracks[ $cn ] ?? '' );
			$rplan  = (array) ( ( $splan['rows'] ?? array() )[ $i ] ?? array() );
			$look   = (string) ( ( $splan['looks'] ?? array() )[ $cn ] ?? 'default' );
			$expr   = (string) ( $rplan['expr'] ?? 'netral' );
			$spr    = (array) ( $chars_cat['chars'][ $cn ]['sprites'] ?? array() );
			$height = (float) ( $spr[0]['height'] ?? 0.7 ) ?: 0.7;
			$pos_x  = (float) ( $r['x'] ?? ( $char_x[ $cn ] ?? 0.0 ) );

			// Karakter menghadap kiri — flip bila harus menghadap kanan:
			// posisi di sisi kiri (percakapan) atau aksi jalan ke kanan.
			$flip  = 'jalan' === $act ? 'kanan' === (string) ( $r['dir'] ?? 'kanan' ) : $pos_x < 0;
			$fpat  = $flip ? array( 'flipH' => true ) : null;

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
					$add_clip( $trk, $imp, $row_t + $k * $seg, $seg, cc_cartoon_char_tf( $spk['file'], $scale, $feet_line, $pos_x, $ai_frame ), $fpat );
				}
				// Audio dialog.
				if ( 'bicara' === $act && '' !== (string) ( $r['audio'] ?? '' ) && is_file( (string) $r['audio'] ) ) {
					$imp = $import_file( $r['audio'] );
					if ( $imp ) {
						if ( '' === $dlg_tr ) {
							$dlg_tr = $audio_track();
						}
						$add_clip( $dlg_tr, $imp, $row_t );
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
					$st_at = $row_t + $k * $seg;
					$ox    = $pos_x + ( $x1 - $pos_x ) * ( $k / max( 1, $n - 1 ) );
					$add_clip( $trk, $imp, $st_at, $seg, cc_cartoon_char_tf( $spk['file'], $height, $feet_line, $ox, $ai_frame ), $fpat );
					// SFX step sinkron tiap pergantian pose — track per karakter.
					if ( $step_sfx ) {
						if ( ! isset( $step_tracks[ $cn ] ) ) {
							$step_tracks[ $cn ] = $audio_track();
						}
						$st_trk = (string) $step_tracks[ $cn ];
						if ( '' !== $st_trk ) {
							$simp = $import_file( $step_sfx['file'] );
							if ( $simp ) {
								$add_clip( $st_trk, $simp, $st_at, min( (float) ( $simp['duration'] ?? $seg ), $seg ) );
							}
						}
					}
				}
			} elseif ( in_array( $act, array( 'masuk', 'keluar', 'zoom' ), true ) ) {
				$spk = cc_cartoon_pick_sprite( $spr, $look, $expr, 'diam' );
				if ( $spk && ( $imp = $import_file( $spk['file'] ) ) ) {
					$scale = 'zoom' === $act ? $height * 1.15 : $height;
					$add_clip( $trk, $imp, $row_t, $dur,
						cc_cartoon_char_tf( $spk['file'], $scale, $feet_line, $pos_x, $ai_frame ),
						array_merge( array( 'visualMotion' => array( 'entrance' => 'masuk' === $act ? 2 : 0, 'exit' => 'keluar' === $act ? 2 : 0, 'duration' => 0.35 ) ), $flip ? array( 'flipH' => true ) : array() ) );
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
						$add_clip( $sfx_tr, $imp, $row_t );
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
						$add_clip( $br_tr, $imp, $row_t, min( $dur, (float) ( $imp['duration'] ?? 3 ) ), null,
							array( 'muted' => true, 'visualMotion' => array( 'entrance' => 1, 'exit' => 1, 'duration' => 0.3 ) ) );
						break;
					}
				}
			}
			// Caption dari teks baris bicara.
			if ( 'bicara' === $act && '' !== trim( (string) ( $r['text'] ?? '' ) ) ) {
				$cap_items[] = array( $row_t, $row_t + $dur, (string) $r['text'] );
			}
			// Rekam rentang terpakai & posisi akhir karakter untuk gap-fill nanti.
			$cover[ $cn ][] = array( $row_t, $row_t + $dur );
			$xtl[ $cn ][]   = array( $row_t + $dur, 'jalan' === $act ? (float) ( $r['x2'] ?? -$pos_x ) : $pos_x );
			// Baris `par` tak memajukan timeline — berjalan bersama baris sebelumnya.
			if ( empty( $r['par'] ) ) {
				$rt_prev = $rt;
				$rt     += $dur;
			}
		}
		// Gap-fill diam: setiap karakter hadir sepanjang scene di celah yang
		// benar-benar kosong pada track-nya, di posisi x terakhir sebelum celah.
		$send = $st0 + $scene_dur;
		foreach ( $scene_chars as $cn2 ) {
			if ( '' === $cn2 ) {
				continue;
			}
			$spr2  = (array) ( $chars_cat['chars'][ $cn2 ]['sprites'] ?? array() );
			$look2 = (string) ( ( $splan['looks'] ?? array() )[ $cn2 ] ?? 'default' );
			$spd   = cc_cartoon_pick_sprite( $spr2, $look2, 'netral', 'diam' );
			if ( ! $spd || ! ( $imp2 = $import_file( $spd['file'] ) ) ) {
				continue;
			}
			$h2    = (float) ( $spr2[0]['height'] ?? 0.7 ) ?: 0.7;
			$trk2  = (string) ( $char_tracks[ $cn2 ] ?? '' );
			$hist  = (array) ( $xtl[ $cn2 ] ?? array() );
			$cur   = $st0;
			$spans2 = (array) ( $cover[ $cn2 ] ?? array() );
			usort( $spans2, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
			$gaps = array();
			foreach ( $spans2 as $sp2 ) {
				if ( $sp2[0] > $cur + 0.05 ) {
					$gaps[] = array( $cur, $sp2[0] );
				}
				$cur = max( $cur, $sp2[1] );
			}
			if ( $cur < $send - 0.05 ) {
				$gaps[] = array( $cur, $send );
			}
			foreach ( $gaps as $g ) {
				$gx = (float) ( $char_x[ $cn2 ] ?? 0.0 );
				foreach ( $hist as $hx ) {
					if ( $hx[0] <= $g[0] + 0.05 ) {
						$gx = $hx[1];
					}
				}
				$add_clip( $trk2, $imp2, $g[0], $g[1] - $g[0],
					cc_cartoon_char_tf( $spd['file'], $h2, $feet_line, $gx, $ai_frame ),
					$gx < 0 ? array( 'flipH' => true ) : null );
			}
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

/* ==================== STORYBOARD OTOMATIS (v2) ==================== */

/** Scan folder latar (gambar) — map 'bg1'.. terurut nama. */
function cc_cartoon_catalog_bg( $dir ) {
	$dir = wp_normalize_path( trim( (string) $dir ) );
	$out = array();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $out;
	}
	$exts = array( 'png', 'jpg', 'jpeg', 'webp' );
	$i    = 0;
	foreach ( (array) scandir( $dir ) as $f ) {
		$full = $dir . '/' . $f;
		if ( '.' === $f[0] || ! is_file( $full )
			|| ! in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), $exts, true ) ) {
			continue;
		}
		$i++;
		$out[ 'bg' . $i ] = array( 'id' => 'bg' . $i, 'file' => wp_normalize_path( $full ), 'name' => $f );
	}
	return $out;
}

/**
 * Katalog VO: semua file audio di folder — urut seq dari prefix NNN,
 * karakter dari sisa nama (`001-budi.mp3` → seq 1, char budi).
 * Map 'vo1'.. sesuai urutan dialog.
 */
function cc_cartoon_catalog_vo( $dir ) {
	$dir = wp_normalize_path( trim( (string) $dir ) );
	$items = array();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return $items;
	}
	$exts = array( 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac' );
	$rows = array();
	foreach ( (array) scandir( $dir ) as $f ) {
		$full = $dir . '/' . $f;
		if ( '.' === $f[0] || ! is_file( $full )
			|| ! in_array( strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ), $exts, true ) ) {
			continue;
		}
		$stem = pathinfo( $f, PATHINFO_FILENAME );
		$seq  = PHP_INT_MAX;
		$char = sanitize_key( $stem );
		if ( preg_match( '/^(\d+)[\-_\s\.]*(.*)$/', $stem, $m ) ) {
			$seq  = (int) $m[1];
			$char = sanitize_key( $m[2] );
		}
		$pr     = cc_assembly_probe_local( $full );
		$rows[] = array(
			'file'     => wp_normalize_path( $full ),
			'seq'      => $seq,
			'char'     => $char,
			'name'     => $f,
			'duration' => (float) ( $pr['duration'] ?? 0 ),
			'text'     => '',
		);
	}
	usort( $rows, function ( $a, $b ) { return $a['seq'] <=> $b['seq']; } );
	foreach ( $rows as $i => $r ) {
		$r['id']          = 'vo' . ( $i + 1 );
		$items[ $r['id'] ] = $r;
	}
	return $items;
}

/** Parse baris naskah `Nama: "teks"` dari prompt → [{char,text}]. */
function cc_cartoon_script_lines( $prompt ) {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) $prompt ) as $line ) {
		if ( preg_match( '/^\s*([A-Za-z0-9_\-]+)\s*[:：]\s*["\'“‘](.+?)["\'”’]\s*$/u', $line, $m ) ) {
			$out[] = array( 'char' => sanitize_key( $m[1] ), 'text' => trim( $m[2] ) );
		}
	}
	return $out;
}

/**
 * Isi text tiap file VO dari baris naskah — per karakter, file ke-i
 * (urut seq) ↔ baris naskah ke-i untuk karakter itu. Sisanya → needs_stt.
 */
function cc_cartoon_map_dialog( &$vo_cat, $script ) {
	$warns = array();
	$pool  = array();
	foreach ( (array) $script as $l ) {
		$pool[ $l['char'] ][] = $l['text'];
	}
	foreach ( $vo_cat as &$v ) {
		$cn = (string) $v['char'];
		if ( '' !== $cn && ! empty( $pool[ $cn ] ) ) {
			$v['text'] = array_shift( $pool[ $cn ] );
		} else {
			$v['needs_stt'] = true;
		}
	}
	unset( $v );
	foreach ( $pool as $cn => $left ) {
		if ( $left ) {
			$warns[] = 'Naskah ' . $cn . ': ' . count( $left ) . ' baris tanpa file VO.';
		}
	}
	return $warns;
}

/** Whisper untuk VO tanpa teks naskah — cache fingerprint via ensure_srt. */
function cc_cartoon_story_transcribe( &$vo_cat ) {
	$warns = array();
	if ( ! function_exists( 'cc_assembly_ensure_srt' ) ) {
		return $warns;
	}
	foreach ( $vo_cat as &$v ) {
		if ( empty( $v['needs_stt'] ) ) {
			continue;
		}
		$srt = cc_assembly_ensure_srt( $v['file'] );
		if ( is_wp_error( $srt ) ) {
			$warns[] = 'STT gagal ' . $v['name'] . ': ' . $srt->get_error_message();
			continue;
		}
		if ( preg_match_all( '/^\d+\s*\n[\d:,\.\s>\-]+\n(.+)$/m', (string) $srt, $mm ) ) {
			$v['text'] = trim( implode( ' ', array_map( 'trim', $mm[1] ) ) );
		}
	}
	unset( $v );
	return $warns;
}

/**
 * AI Director — satu panggil menyusun storyboard utuh:
 * jumlah scene, bg, karakter, look, urutan baris (vo/aksi), sfx & broll.
 * Return array scenes mentah AI, atau WP_Error untuk kegagalan transport.
 */
function cc_cartoon_plan_story( $prompt, $cat ) {
	$chars_brief = array();
	foreach ( (array) ( $cat['chars']['chars'] ?? array() ) as $cn => $ch ) {
		$lk = array();
		$ex = array();
		foreach ( (array) $ch['sprites'] as $sp ) {
			$lk[ $sp['look'] ]    = true;
			$ex[ $sp['ekspresi'] ] = true;
		}
		$chars_brief[ $cn ] = 'looks:' . implode( ',', array_keys( $lk ) ) . ' expr:' . implode( ',', array_keys( $ex ) );
	}
	$bg = array();
	foreach ( (array) ( $cat['bg'] ?? array() ) as $b ) {
		$bg[] = $b['id'] . '=' . $b['name'];
	}
	$vo = array();
	foreach ( (array) ( $cat['vo'] ?? array() ) as $v ) {
		$vo[] = $v['id'] . '=' . $v['char'] . ' ' . round( (float) $v['duration'], 1 ) . 's "' . mb_substr( (string) $v['text'], 0, 60 ) . '"';
	}
	$sfx = array();
	foreach ( (array) ( $cat['sfx'] ?? array() ) as $s ) {
		$sfx[] = $s['id'] . '=' . $s['name'] . ( ! empty( $s['desc'] ) ? '(' . $s['desc'] . ')' : '' );
	}
	$br = array();
	foreach ( array_slice( (array) ( $cat['brolls'] ?? array() ), 0, 40 ) as $b ) {
		$br[] = (string) ( $b['id'] ?? '' ) . '=' . (string) ( $b['name'] ?? '' );
	}

	$sys = 'Anda sutradara animasi kartun eksplainer. Susun storyboard utuh (1-6 scene) dari prompt user + katalog aset. '
		. 'Jawab HANYA JSON: {"scenes":[{"bg_id":"bg1","chars":["nama"],"looks":{"nama":"look"},"transition_out":"fade",'
		. '"rows":[{"kind":"vo","vo_id":"vo1","expr":"netral","sfx_id":"","broll_id":"","broll_start":0} ATAU '
		. '{"char":"nama","action":"jalan|zoom|masuk|keluar|diam","dir":"kanan|kiri","dur":2,"x":-0.6,"x2":0.25,"par":false,"expr":"netral","sfx_id":"","broll_id":"","broll_start":0}]}]}. '
		. 'x/x2 = posisi horizontal karakter sebagai offset fraksi frame dari pusat (-0.5 tepi kiri, 0 tengah, +0.5 tepi kanan); jalan menginterpolasi x→x2 — WAJIB diisi agar karakter benar-benar bergerak (mis. masuk dari kiri: x=-0.7 → x2=-0.25); '
		. 'par=true = aksi berjalan BERSAMAAN dengan baris sebelumnya (dua karakter masuk bersama); '
		. 'Aturan: tiap vo_id WAJIB muncul tepat sekali sebagai baris kind:vo (urutan dialog sesuai naskah); '
		. 'bg_id/chars/looks/expr/sfx_id/broll_id HANYA dari katalog; aksi non-dialog hanya jalan|zoom|masuk|keluar|diam; field kosong boleh dihilangkan.';
	$usr = "PROMPT:\n{$prompt}\n\nKARAKTER: " . wp_json_encode( $chars_brief )
		. "\nBG: " . implode( '; ', $bg )
		. "\nVO (urutan dialog): " . implode( '; ', $vo )
		. "\nSFX: " . implode( '; ', $sfx )
		. "\nBROLL: " . implode( '; ', $br );

	if ( ! function_exists( 'cc_ai_configured' ) || ! cc_ai_configured() ) {
		return new WP_Error( 'cc_story_ai', 'Provider AI belum dikonfigurasi — atur di Settings › Agen AI.' );
	}
	$res = CC_AI::chat( array( array( 'role' => 'system', 'content' => $sys ), array( 'role' => 'user', 'content' => $usr ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$raw = (string) ( $res['message']['content'] ?? '' );
	$j   = json_decode( trim( $raw ), true );
	if ( ! is_array( $j ) && preg_match( '/\{.*"scenes".*\}/s', $raw, $m ) ) {
		$j = json_decode( $m[0], true ); // model membungkus dengan ```json
	}
	return ( is_array( $j ) && ! empty( $j['scenes'] ) ) ? (array) $j['scenes'] : array();
}

/**
 * Materialize JSON plan_story → scenes[] + plans[] bentuk
 * cc_cartoon_generate / cc_cartoon_review_items. VO yang tidak dipakai AI
 * ditempel ke scene terakhir agar dialog tidak hilang.
 */
function cc_cartoon_story_scenes( $jscenes, $cat ) {
	$scenes  = array();
	$plans   = array();
	$warns   = array();
	$used_vo = array();
	$bg_ids  = array_values( (array) ( $cat['bg'] ?? array() ) );

	$prow = function ( $jr ) {
		return array(
			'expr'        => sanitize_key( (string) ( $jr['expr'] ?? 'netral' ) ) ?: 'netral',
			'sfx_id'      => sanitize_key( (string) ( $jr['sfx_id'] ?? '' ) ),
			'broll_id'    => sanitize_key( (string) ( $jr['broll_id'] ?? '' ) ),
			'broll_start' => (float) ( $jr['broll_start'] ?? 0 ),
		);
	};
	// Posisi x per karakter — bergantian kiri/kanan, konsisten lintas scene.
	$cxmap = array();
	$cx_of = function ( $cn ) use ( &$cxmap ) {
		if ( '' === $cn ) {
			return 0.0;
		}
		if ( ! isset( $cxmap[ $cn ] ) ) {
			$cxmap[ $cn ] = count( $cxmap ) % 2 ? 0.25 : -0.25;
		}
		return $cxmap[ $cn ];
	};
	$vo_row = function ( $v ) use ( $cx_of ) {
		return array(
			'char' => (string) $v['char'], 'action' => 'bicara',
			'dur'  => max( 0.5, (float) $v['duration'] ), 'audio' => (string) $v['file'],
			'text' => (string) $v['text'], 'dir' => 'kanan', 'x' => $cx_of( (string) $v['char'] ), 'x2' => 0,
		);
	};

	foreach ( (array) $jscenes as $js ) {
		if ( ! is_array( $js ) ) {
			continue;
		}
		$bg_id = (string) ( $js['bg_id'] ?? '' );
		$bg    = isset( $cat['bg'][ $bg_id ] ) ? $cat['bg'][ $bg_id ]['file'] : ( $bg_ids ? $bg_ids[0]['file'] : '' );
		$chars = array();
		foreach ( (array) ( $js['chars'] ?? array() ) as $cn ) {
			$cn = sanitize_key( (string) $cn );
			if ( isset( $cat['chars']['chars'][ $cn ] ) ) {
				$chars[] = $cn;
			}
		}
		$rows  = array();
		$prows = array();
		foreach ( (array) ( $js['rows'] ?? array() ) as $jr ) {
			if ( ! is_array( $jr ) ) {
				continue;
			}
			if ( 'vo' === ( $jr['kind'] ?? '' ) || ! empty( $jr['vo_id'] ) ) {
				$vid = (string) ( $jr['vo_id'] ?? '' );
				if ( ! isset( $cat['vo'][ $vid ] ) ) {
					$warns[] = 'vo_id tak dikenal: ' . $vid;
					continue;
				}
				if ( ! empty( $used_vo[ $vid ] ) ) {
					$warns[] = 'vo_id dipakai dua kali: ' . $vid;
					continue;
				}
				$used_vo[ $vid ] = true;
				$rows[]  = $vo_row( $cat['vo'][ $vid ] );
				$prows[] = $prow( $jr );
				continue;
			}
			$act = sanitize_key( (string) ( $jr['action'] ?? 'diam' ) );
			if ( ! in_array( $act, array( 'diam', 'jalan', 'zoom', 'masuk', 'keluar' ), true ) ) {
				$act = 'diam';
			}
			$cn2  = sanitize_key( (string) ( $jr['char'] ?? '' ) );
			$dirv = 'kiri' === ( $jr['dir'] ?? '' ) ? 'kiri' : 'kanan';
			$x2v  = isset( $jr['x2'] ) ? (float) $jr['x2'] : $cx_of( $cn2 );
			$xv   = isset( $jr['x'] ) ? (float) $jr['x'] : ( 'jalan' === $act ? $x2v - 0.45 * ( 'kanan' === $dirv ? 1 : -1 ) : $x2v );
			$rows[] = array(
				'char'   => $cn2,
				'action' => $act,
				'dur'    => max( 0.5, (float) ( $jr['dur'] ?? 2 ) ),
				'audio'  => '', 'text' => '',
				'dir'    => $dirv,
				'x'      => $xv,
				'x2'     => $x2v,
				'par'    => ! empty( $jr['par'] ),
			);
			$prows[] = $prow( $jr );
		}
		if ( ! $rows ) {
			continue;
		}
		foreach ( $rows as $r ) {
			if ( '' !== $r['char'] && ! in_array( $r['char'], $chars, true ) ) {
				$chars[] = $r['char'];
			}
		}
		$looks = array();
		foreach ( (array) ( $js['looks'] ?? array() ) as $cn => $lk ) {
			$looks[ sanitize_key( (string) $cn ) ] = sanitize_key( (string) $lk );
		}
		$scenes[] = array(
			'bg' => $bg, 'chars' => $chars, 'look' => $looks, 'prompt' => '',
			'rows' => $rows, 'transition_out' => sanitize_key( (string) ( $js['transition_out'] ?? '' ) ),
		);
		$plans[] = array( 'looks' => $looks, 'rows' => $prows );
	}

	// VO yang terlewat AI → tempel ke scene terakhir (dialog tidak hilang).
	foreach ( (array) ( $cat['vo'] ?? array() ) as $vid => $v ) {
		if ( empty( $used_vo[ $vid ] ) && $scenes ) {
			$warns[] = 'vo ' . $v['name'] . ' tidak dipilih AI — ditempel di akhir.';
			$li = count( $scenes ) - 1;
			$scenes[ $li ]['rows'][] = $vo_row( $v );
			$plans[ $li ]['rows'][] = array( 'expr' => 'netral' );
		}
	}

	// Fallback deterministik bila AI gagal → 1 scene, VO berurutan.
	if ( ! $scenes && ! empty( $cat['vo'] ) ) {
		$rows = array();
		$chars = array();
		foreach ( (array) $cat['vo'] as $v ) {
			$rows[] = $vo_row( $v );
			if ( '' !== $v['char'] && ! in_array( $v['char'], $chars, true ) ) {
				$chars[] = $v['char'];
			}
		}
		$scenes = array( array(
			'bg' => $bg_ids ? $bg_ids[0]['file'] : '', 'chars' => $chars, 'look' => array(),
			'prompt' => '', 'rows' => $rows, 'transition_out' => '',
		) );
		$plans  = array( array( 'looks' => array(), 'rows' => array_fill( 0, count( $rows ), array( 'expr' => 'netral' ) ) ) );
		$warns[] = 'AI tidak menghasilkan storyboard — dipakai susunan deterministik (VO berurutan).';
	}

	return array( 'scenes' => $scenes, 'plans' => $plans, 'warns' => $warns );
}

/* ============================ ORKESTRASI ============================ */

function cc_cartoon_run( $p, $job, $session_id, $cx, $step ) {
	$scenes    = (array) ( $p['scenes'] ?? array() );
	$chars_dir = (string) ( $p['chars_dir'] ?? '' );
	$sfx_dir   = (string) ( $p['sfx_dir'] ?? '' );
	$broll_dir = (string) ( $p['broll_dir'] ?? '' );
	$bg_dir    = (string) ( $p['bg_dir'] ?? '' );
	$vo_dir    = (string) ( $p['vo_dir'] ?? '' );
	$prompt_g  = (string) ( $p['prompt'] ?? '' );
	$story_mode = ! $scenes; // storyboard disusun AI dari prompt + aset

	// 1. Scrape seluruh aset: karakter + latar + VO + SFX + B-roll.
	$cat = $step(
		'Memindai aset (karakter, latar, VO, SFX, B-Roll)',
		function () use ( $chars_dir, $sfx_dir, $broll_dir, $bg_dir, $vo_dir ) {
			$broll = array();
			if ( '' !== $broll_dir && function_exists( 'cc_assembly_catalog_dir' ) ) {
				$br = cc_assembly_catalog_dir( $broll_dir, 'broll', false );
				if ( ! is_wp_error( $br ) ) {
					$broll = $br;
				}
			}
			return array(
				'chars'  => cc_cartoon_catalog_chars( $chars_dir ),
				'sfx'    => cc_cartoon_catalog_sfx( $sfx_dir ),
				'brolls' => $broll,
				'bg'     => cc_cartoon_catalog_bg( $bg_dir ),
				'vo'     => cc_cartoon_catalog_vo( $vo_dir ),
			);
		}
	);

	$story_warns = array();
	// 2a. Story mode: teks naskah → VO, lalu AI menyusun scenes + plans utuh.
	if ( $story_mode ) {
		$script      = cc_cartoon_script_lines( $prompt_g );
		$vo_cat      = (array) $cat['vo'];
		$story_warns = cc_cartoon_map_dialog( $vo_cat, $script );
		$story_warns = array_merge( $story_warns, cc_cartoon_story_transcribe( $vo_cat ) );
		$cat['vo']   = $vo_cat;
		if ( ! $cat['vo'] ) {
			$story_warns[] = 'Tidak ada file VO di vo_dir — baris bicara tanpa audio.';
		}
		$jscenes = $step(
			'AI menyusun storyboard (' . count( $cat['vo'] ) . ' dialog, ' . count( $cat['bg'] ) . ' latar)',
			function () use ( $prompt_g, $cat ) {
				return cc_cartoon_plan_story( $prompt_g, $cat );
			}
		);
		$built       = cc_cartoon_story_scenes( $jscenes, $cat );
		$scenes      = $built['scenes'];
		$plans       = $built['plans'];
		$story_warns = array_merge( $story_warns, $built['warns'] );
		if ( ! $scenes ) {
			throw new CC_Control_Abort( new WP_Error( 'cc_storyboard', 'AI tidak menghasilkan scene dan tidak ada VO sebagai fallback.' ) );
		}
	}

	// 2b. AI Director per scene (mode manual / scenes eksplisit).
	$resumed = ! empty( $p['_scene_plan'] );
	$plans   = $p['_scene_plan'] ?? $plans ?? null;
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
	}
	// 3. Gate review — hanya saat plan baru disusun (resume tidak mengulang).
	if ( ! $resumed && ! empty( $p['edl_review'] ) && function_exists( 'cc_edl_review_pause' ) ) {
			$rev_items = cc_cartoon_review_items( $scenes, $plans, $cat['chars'] );
			$sp_cat    = cc_cartoon_sprite_cat( $cat['chars'] );
			$sfxs      = array();
			foreach ( (array) $cat['sfx'] as $s ) {
				$sfxs[] = array( 'id' => $s['id'], 'path' => $s['file'], 'name' => $s['name'], 'duration' => $s['dur'] );
			}
			cc_edl_review_pause(
				$job, $session_id, $p,
				array( array( 'kind' => 'cartoon', 'scenes' => $scenes, 'plans' => $plans, 'items' => $rev_items['items'], 'dur' => $rev_items['dur'], 'label' => 'Storyboard' ) ),
				array( 'brolls' => array_values( $sp_cat ), 'sfxs' => $sfxs, 'chars' => $cat['chars'] )
			);
			return array( '_waiting' => true );
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
	$warns = array_merge( (array) $story_warns, (array) ( $gen['warns'] ?? array() ), (array) ( $cat['chars']['warns'] ?? array() ) );
	$warn  = $warns ? ' Catatan: ' . count( $warns ) . ' peringatan aset.' : '';
	return array(
		'body' => 'Proyek kartun **' . ( $p['name'] ?? 'kartun' ) . '** siap di `' . $project . '` — ' . count( $scenes ) . ' scene, ' . round( (float) ( $gen['duration'] ?? 0 ), 1 ) . 's.' . $warn,
		'meta' => array( 'workflow' => 'cartoon', 'scenes' => count( $scenes ), 'duration' => (float) ( $gen['duration'] ?? 0 ), 'warns' => $warns, 'project' => $project ),
	);
}
