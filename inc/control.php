<?php
/**
 * Control mode — deterministic pipeline worker.
 *
 * One form submit = one 'control_run' job in a fresh session. The worker
 * skips OpenAI entirely and drives Studio Inspirasi through the same CC_Concat
 * bridge the agent tools use:
 *   instantiate → project.get → replace media → trim segment → save
 *   → handoff (close for manual export) or export (+ optional copy).
 * Each step is stamped as a 'task' message so the session thread shows
 * live progress identically to an agent turn.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------
 * Enqueue
 * ------------------------------------------------------------------- */

/** Rasio → dimensi video; dipanggil tanpa arg mengembalikan map (untuk whitelist). */
function cc_aspect_dims( $ratio = null ) {
	$map = array(
		'9:16' => array( 'width' => 1080, 'height' => 1920 ),
		'16:9' => array( 'width' => 1920, 'height' => 1080 ),
		'1:1'  => array( 'width' => 1080, 'height' => 1080 ),
		'4:5'  => array( 'width' => 1080, 'height' => 1350 ),
	);
	return null === $ratio ? $map : ( $map[ $ratio ] ?? null );
}

/** Best-effort TextStyle untuk addCaptionSource — bentuk sebenarnya belum terpetakan. */
function cc_caption_style_default() {
	return array(
		'offsetY' => 0.7, 'fontSize' => 0.05, 'fontFamily' => '',
		'color' => '#FFFFFF', 'background' => '#00000080',
		'outline' => '#000000', 'outlineWidth' => 2,
		'bold' => false, 'align' => 'center',
	);
}

/* ---------------------------------------------------------------------
 * YouTube — unduh video + auto-caption (yt-dlp) dan potongan SRT
 * ------------------------------------------------------------------- */

function cc_control_is_yt( $s ) {
	return (bool) preg_match( '#^https?://(www\.)?(youtube\.com|youtu\.be)/#i', (string) $s );
}

function cc_control_yt_bin() {
	foreach ( array( cc_bin_dir() . 'yt-dlp', 'yt-dlp', '/usr/local/bin/yt-dlp', '/opt/homebrew/bin/yt-dlp', '/usr/bin/yt-dlp', cc_workspace() . 'yt-dlp' ) as $b ) {
		if ( file_exists( $b ) && is_executable( $b ) ) {
			return $b;
		}
	}
	return 'yt-dlp';
}

function cc_control_ffmpeg_dir() {
	foreach ( array( cc_bin_dir(), cc_workspace() ) as $d ) {
		if ( file_exists( $d . 'ffmpeg' ) && is_executable( $d . 'ffmpeg' ) ) {
			return $d;
		}
	}
	return '';
}

function cc_control_yt_id( $url ) {
	if ( preg_match( '#(?:youtu\.be/|[?&]v=|shorts/|embed/|live/)([\w-]{6,})#', $url, $m ) ) {
		return $m[1];
	}
	return 'u-' . substr( md5( (string) $url ), 0, 10 );
}

/**
 * Konversi .vtt → .srt di PHP saja (tanpa ffmpeg), dengan dedup rolling-window
 * YouTube: tiap cue adalah jendela 2 baris [baris lama, baris baru] — baris
 * pertama mengulang baris terakhir cue sebelumnya. Konten baru per cue = baris
 * terakhir; cue pengulang murni hanya memperpanjang `end` cue sebelumnya.
 * Hasil: satu baris teks per cue, tanpa overlap, cocok dengan parser SRT
 * Clovercut (tiap cue → satu clip text).
 */
function cc_control_vtt_to_srt( $vtt_path ) {
	$srt_path = preg_replace( '/\.vtt$/i', '.srt', (string) $vtt_path );
	if ( file_exists( $srt_path ) ) {
		return $srt_path;
	}
	$raw = (string) @file_get_contents( $vtt_path );
	if ( '' === $raw ) {
		return '';
	}
	$raw  = preg_replace( '/^WEBVTT[^\n]*\n/', '', $raw );
	$raw  = preg_replace( '/(\d{2}):(\d{2}):(\d{2})\.(\d{3})/', '$1:$2:$3,$4', $raw );
	$raw  = preg_replace( '/(\d{2}):(\d{2})\.(\d{3})/', '00:$1:$2,$3', $raw );
	$cues = array(); // [{t1,t2,text}]
	foreach ( preg_split( '/\r?\n\r?\n/', trim( $raw ) ) as $b ) {
		if ( ! preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $m ) ) {
			continue;
		}
		$t1 = cc_control_srt_ts( $m[1], $m[2], $m[3], $m[4] );
		$t2 = cc_control_srt_ts( $m[5], $m[6], $m[7], $m[8] );
		// Ambil baris teks sesudah baris timing; buang markup karaoke/inline.
		$text = array();
		$hit  = false;
		foreach ( preg_split( '/\r?\n/', $b ) as $ln ) {
			if ( $hit ) {
				$ln = preg_replace( '/<\d{2}:\d{2}:\d{2}[,.]\d{3}>/', '', $ln );
				$ln = trim( str_replace( array( '<c>', '</c>' ), '', $ln ) );
				if ( '' !== $ln ) {
					$text[] = $ln;
				}
			} elseif ( strpos( $ln, '-->' ) !== false ) {
				$hit = true;
			}
		}
		if ( ! $text ) {
			continue;
		}
		// Rolling-window: konten baru = baris TERAKHIR. Bila identik dengan
		// konten cue sebelumnya → cue ini hanya memperpanjang tampilannya.
		$line = end( $text );
		$last = count( $cues ) - 1;
		if ( $last >= 0 && $cues[ $last ]['text'] === $line ) {
			$cues[ $last ]['t2'] = $t2;
		} else {
			$cues[] = array( 't1' => $t1, 't2' => $t2, 'text' => $line );
		}
	}
	// Tulis ulang cue (1 baris teks per cue, index dari 1).
	$out = '';
	foreach ( array_values( $cues ) as $i => $c ) {
		$out .= ( $i + 1 ) . "\n" . cc_control_srt_fmt( $c['t1'] ) . ' --> ' . cc_control_srt_fmt( $c['t2'] ) . "\n" . $c['text'] . "\n\n";
	}
	if ( '' === $out ) {
		return '';
	}
	file_put_contents( $srt_path, $out );
	return $srt_path;
}

/** Unduh video+caption YouTube ke Assets/yt-{id} (cache by video id). Returns array{file,srt}|WP_Error. */
function cc_control_yt_download( $url, $lang ) {
	$id  = cc_control_yt_id( $url );
	$dir = cc_ws_assets() . 'yt-' . $id . '/';
	wp_mkdir_p( $dir );
	$vid = $dir . 'yt-' . $id . '.mp4';
	$srt = '';
	foreach ( (array) glob( $dir . 'yt-' . $id . '.*.srt' ) as $f ) {
		$srt = $f;
		break;
	}
	foreach ( (array) glob( $dir . 'yt-' . $id . '.*.vtt' ) as $f ) {
		if ( '' === $srt ) {
			$srt = cc_control_vtt_to_srt( $f );
		}
	}
	if ( file_exists( $vid ) ) {
		return array( 'file' => $vid, 'srt' => $srt, 'cached' => true );
	}
	$bin = cc_control_yt_bin();
	$tpl = escapeshellarg( $dir . 'yt-' . $id . '.%(ext)s' );
	$ffl = cc_control_ffmpeg_dir();
	$ff  = '' !== $ffl ? ' --ffmpeg-location ' . escapeshellarg( $ffl ) : '';

	/* 1. Video — fatal bila gagal */
	$out = array();
	exec(
		escapeshellarg( $bin ) . ' --no-playlist --restrict-filenames --no-progress' . $ff
		. ' -f ' . escapeshellarg( 'bv*[height<=720]+ba/b[height<=720]/b' )
		. ' --merge-output-format mp4'
		. ' -o ' . $tpl . ' ' . escapeshellarg( $url ) . ' 2>&1',
		$out,
		$code
	);
	if ( ! file_exists( $vid ) ) {
		return new WP_Error( 'cc_yt', 'Unduhan yt-dlp gagal (kode ' . $code . '): ' . mb_substr( implode( ' ', array_slice( $out, -3 ) ), 0, 300 ) );
	}

	/* 2. Subtitle — terpisah supaya rate-limit/error tidak menggagalkan video */
	if ( 'none' !== $lang ) {
		$langs = 'auto' === $lang ? 'id.*,en.*' : $lang . '.*';
		$out   = array();
		exec(
			escapeshellarg( $bin ) . ' --no-playlist --restrict-filenames --no-progress --skip-download'
			. ' --write-auto-sub --write-sub --sub-format vtt --sub-langs ' . escapeshellarg( $langs )
			. ' -o ' . $tpl . ' ' . escapeshellarg( $url ) . ' 2>&1',
			$out
		);
		foreach ( (array) glob( $dir . 'yt-' . $id . '.*.vtt' ) as $f ) {
			if ( '' === $srt ) {
				$srt = cc_control_vtt_to_srt( $f );
			}
		}
	}
	return array( 'file' => $vid, 'srt' => $srt, 'cached' => false );
}

/* SRT helpers — potong rentang [start,end] lalu geser timestamp ke 00:00. */
function cc_control_srt_ts( $h, $m, $s, $ms ) {
	return ( (float) $h * 3600 ) + ( (float) $m * 60 ) + (float) $s + ( (float) $ms / 1000 );
}

function cc_control_srt_fmt( $t ) {
	$t   = max( 0, $t );
	$h   = (int) floor( $t / 3600 );
	$t  -= $h * 3600;
	$m   = (int) floor( $t / 60 );
	$t  -= $m * 60;
	$s   = (int) floor( $t );
	$ms  = (int) round( ( $t - $s ) * 1000 );
	return sprintf( '%02d:%02d:%02d,%03d', $h, $m, $s, $ms );
}

/** Parse file .srt → daftar cue [{t1,t2}] (detik). */
function cc_control_srt_cues( $path ) {
	if ( ! $path || ! file_exists( $path ) ) {
		return array();
	}
	$cues = array();
	foreach ( preg_split( '/\r?\n\r?\n/', trim( (string) file_get_contents( $path ) ) ) as $b ) {
		if ( preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $m ) ) {
			$cues[] = array(
				't1' => cc_control_srt_ts( $m[1], $m[2], $m[3], $m[4] ),
				't2' => cc_control_srt_ts( $m[5], $m[6], $m[7], $m[8] ),
			);
		}
	}
	return $cues;
}

/**
 * Snap batas segmen ke tepi cue transkrip:
 * start → awal cue yang mengandungnya; end → akhir cue (kalimat selesai),
 * overshoot >15s → mundur ke awal cue. Tanpa cue → batas apa adanya.
 */
function cc_control_snap_segment( $start, $end, $srt_path ) {
	$cues = cc_control_srt_cues( $srt_path );
	if ( ! $cues ) {
		return array( 'start' => $start, 'end' => $end );
	}
	$os = $start;
	$oe = $end;
	foreach ( $cues as $c ) {
		if ( $start >= $c['t1'] && $start < $c['t2'] ) {
			$start = $c['t1'];
		}
		if ( $end > $c['t1'] && $end <= $c['t2'] ) {
			$end = ( $c['t2'] - $oe > 15 ) ? $c['t1'] : $c['t2'];
		}
	}
	if ( $end - $start < 1 ) {
		return array( 'start' => $os, 'end' => $oe );
	}
	return array( 'start' => $start, 'end' => $end );
}

function cc_control_srt_slice( $srt, $start, $end ) {
	$out = array();
	foreach ( preg_split( '/\r?\n\r?\n/', trim( (string) $srt ) ) as $b ) {
		if ( ! preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $m ) ) {
			continue;
		}
		$t1 = cc_control_srt_ts( $m[1], $m[2], $m[3], $m[4] );
		$t2 = cc_control_srt_ts( $m[5], $m[6], $m[7], $m[8] );
		if ( $t2 <= $start || $t1 >= $end ) {
			continue;
		}
		// Clamp cue ke batas segmen supaya caption tidak overshoot.
		$t1 = max( $t1, $start );
		$t2 = min( $t2, $end );
		if ( $t2 - $t1 < 0.05 ) {
			continue;
		}
		$text = array();
		$hit  = false;
		foreach ( preg_split( '/\r?\n/', $b ) as $ln ) {
			if ( $hit ) {
				$text[] = $ln;
			} elseif ( strpos( $ln, '-->' ) !== false ) {
				$hit = true;
			}
		}
		if ( '' === trim( implode( ' ', $text ) ) ) {
			continue;
		}
		$out[] = cc_control_srt_fmt( max( 0, $t1 - $start ) ) . ' --> ' . cc_control_srt_fmt( max( 0, $t2 - $start ) ) . "\n" . implode( "\n", $text );
	}
	$blocks = array();
	foreach ( array_values( $out ) as $i => $block ) {
		$blocks[] = ( $i + 1 ) . "\n" . $block;
	}
	return $blocks ? implode( "\n\n", $blocks ) . "\n" : '';
}

/** Path ffmpeg executable — bin/ tema prioritas. */
function cc_control_ffmpeg_bin() {
	$d = cc_control_ffmpeg_dir();
	if ( $d && file_exists( $d . 'ffmpeg' ) && is_executable( $d . 'ffmpeg' ) ) {
		return $d . 'ffmpeg';
	}
	foreach ( array( 'ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg' ) as $c ) {
		if ( 'ffmpeg' === $c || file_exists( $c ) ) {
			return $c;
		}
	}
	return '';
}

/**
 * Deteksi keep-ranges (bagian bersuara) dalam [start,end] via ffmpeg
 * silencedetect. Return array{r1,r2,...}|WP_Error — caller soft-fail.
 */
function cc_control_silence_ranges( $file, $start, $end, $opts = array() ) {
	if ( ! $file || ! file_exists( $file ) ) {
		return new WP_Error( 'cc_sil', 'File tidak ditemukan.' );
	}
	$ff = cc_control_ffmpeg_bin();
	if ( ! $ff ) {
		return new WP_Error( 'cc_sil', 'ffmpeg tidak tersedia.' );
	}
	$db    = (float) ( $opts['db'] ?? -35 );
	$min   = (float) ( $opts['min'] ?? 0.5 );
	$pad   = (float) ( $opts['pad'] ?? 0.1 );
	$merge = (float) ( $opts['merge'] ?? 2.0 );

	$cmd = escapeshellarg( $ff ) . ' -hide_banner -nostats'
		. ' -ss ' . escapeshellarg( (string) max( 0, $start ) )
		. ' -t ' . escapeshellarg( (string) max( 0.5, $end - $start ) )
		. ' -i ' . escapeshellarg( $file )
		. ' -af silencedetect=noise=' . escapeshellarg( $db . 'dB' ) . ':d=' . escapeshellarg( (string) $min )
		. ' -f null - 2>&1';
	$out = shell_exec( $cmd );
	if ( ! $out ) {
		return new WP_Error( 'cc_sil', 'silencedetect gagal dijalankan.' );
	}
	$sils = array();
	$pend = null;
	foreach ( explode( "\n", $out ) as $ln ) {
		if ( preg_match( '/silence_start:\s*([\d.]+)/', $ln, $m ) ) {
			$pend = $start + (float) $m[1];
		} elseif ( preg_match( '/silence_end:\s*([\d.]+)/', $ln, $m ) && null !== $pend ) {
			$sils[] = array( max( $start, $pend ), min( $end, $start + (float) $m[1] ) );
			$pend = null;
		}
	}
	if ( null !== $pend ) {
		$sils[] = array( max( $start, $pend ), $end );
	}
	$keeps = array();
	$cur   = $start;
	foreach ( $sils as $s ) {
		if ( $s[0] - $cur > 0.05 ) {
			$keeps[] = array( $cur, $s[0] );
		}
		$cur = max( $cur, $s[1] );
	}
	if ( $end - $cur > 0.05 ) {
		$keeps[] = array( $cur, $end );
	}
	foreach ( $keeps as &$k ) {
		$k[0] = max( $start, $k[0] - $pad );
		$k[1] = min( $end, $k[1] + $pad );
	}
	unset( $k );
	$keeps = array_values( array_filter( $keeps, function ( $k ) { return $k[1] - $k[0] >= 0.8; } ) );
	$merged = array();
	foreach ( $keeps as $k ) {
		$last = count( $merged ) ? count( $merged ) - 1 : -1;
		if ( $last >= 0 && $k[0] - $merged[ $last ][1] < $merge ) {
			$merged[ $last ][1] = $k[1];
		} else {
			$merged[] = $k;
		}
	}
	while ( count( $merged ) > 120 ) {
		$bi = 0;
		$bg = PHP_FLOAT_MAX;
		for ( $i = 0; $i + 1 < count( $merged ); $i++ ) {
			$g = $merged[ $i + 1 ][0] - $merged[ $i ][1];
			if ( $g < $bg ) {
				$bg = $g;
				$bi = $i;
			}
		}
		$merged[ $bi ][1] = $merged[ $bi + 1 ][1];
		array_splice( $merged, $bi + 1, 1 );
	}
	return $merged;
}

/**
 * Remap cue SRT ke timeline terkompresi — out = in − totalRemovedSebelum(in);
 * cue di dalam jeda dibuang.
 */
function cc_control_srt_remap( $srt, $keeps ) {
	// outStart per keep = total keep sebelumnya.
	$out = array();
	$n   = 0;
	foreach ( preg_split( '/\r?\n\r?\n/', trim( (string) $srt ) ) as $b ) {
		if ( ! preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $m ) ) {
			continue;
		}
		$t1 = cc_control_srt_ts( $m[1], $m[2], $m[3], $m[4] );
		$t2 = cc_control_srt_ts( $m[5], $m[6], $m[7], $m[8] );
		$acc = 0;
		$n1  = null;
		$n2  = null;
		foreach ( $keeps as $k ) {
			$kin = $k[1] - $k[0];
			if ( $t1 >= $k[0] && $t1 < $k[1] ) {
				$n1 = $acc + ( $t1 - $k[0] );
				$n2 = $acc + min( $t2 - $k[0], $kin );
				break;
			}
			$acc += $kin;
		}
		if ( null === $n1 ) {
			continue;
		}
		$text = array();
		$hit  = false;
		foreach ( preg_split( '/\r?\n/', $b ) as $ln ) {
			if ( $hit ) {
				$text[] = $ln;
			} elseif ( strpos( $ln, '-->' ) !== false ) {
				$hit = true;
			}
		}
		if ( '' === trim( implode( ' ', $text ) ) ) {
			continue;
		}
		$n++;
		$out[] = $n . "\n" . cc_control_srt_fmt( max( 0, $n1 ) ) . ' --> ' . cc_control_srt_fmt( max( $n1 + 0.1, $n2 ) ) . "\n" . implode( "\n", $text );
	}
	return $out ? implode( "\n\n", $out ) . "\n" : '';
}

/** Lacak proyek yang sedang terbuka — untuk project.close saat gagal. */
function cc_control_track_project( $path = null ) {
	static $cur = '';
	if ( null !== $path ) {
		$cur = (string) $path;
	}
	return $cur;
}

/** Style + caption milik template di proyek hasil instantiate — untuk dihapus/replace. */
function cc_control_caption_style( $cx, $project ) {
	$g  = $cx->call( 'project.get', array( 'path' => $project ), 15 );
	$pr = is_wp_error( $g ) ? array() : (array) ( $g['project'] ?? array() );
	$cs = $pr['captionSources'][0] ?? null;
	$clips   = array();
	$sources = array();
	foreach ( (array) ( $pr['captionSources'] ?? array() ) as $s ) {
		if ( ! empty( $s['id'] ) ) {
			$sources[] = (string) $s['id'];
		}
	}
	$lane = '';
	foreach ( (array) ( $pr['timelines'][0]['clips'] ?? array() ) as $c ) {
		if ( 'text' === ( $c['kind'] ?? '' ) && ! empty( $c['text']['captionGroup'] ) && ! empty( $c['id'] ) ) {
			$clips[] = (string) $c['id'];
			if ( '' === $lane && ! empty( $c['trackId'] ) ) {
				$lane = (string) $c['trackId'];
			}
		}
	}
	// Lane khusus captions yang sudah ada (tanpa clip pun tetap bisa dipakai).
	if ( '' === $lane ) {
		foreach ( (array) ( $pr['timelines'][0]['tracks'] ?? array() ) as $tr ) {
			if ( 'captions' === ( $tr['kind'] ?? '' ) ) {
				$lane = (string) ( $tr['id'] ?? '' );
				break;
			}
		}
	}
	return array(
		'style'   => $cs['style'] ?? new stdClass(),
		'offsetY' => $cs && isset( $cs['offsetY'] ) ? (float) $cs['offsetY'] : 0.32,
		'clips'   => $clips,
		'sources' => $sources,
		'lane'    => $lane,
	);
}

/* ---------------------------------------------------------------------
 * Momen terbaik — metadata YouTube + seleksi segmen via AI
 * ------------------------------------------------------------------- */

/** info.json YouTube (unduh bila belum ada) → {title,duration,chapters,heatmap}. */
function cc_control_yt_meta( $id, $url ) {
	$dir = cc_ws_assets() . 'yt-' . $id . '/';
	$j   = $dir . 'yt-' . $id . '.info.json';
	if ( ! file_exists( $j ) ) {
		$bin = cc_control_yt_bin();
		exec( escapeshellarg( $bin ) . ' --no-playlist --skip-download --write-info-json -o ' . escapeshellarg( $dir . 'yt-' . $id . '.%(ext)s' ) . ' ' . escapeshellarg( $url ) . ' 2>&1' );
	}
	$m    = file_exists( $j ) ? json_decode( (string) file_get_contents( $j ), true ) : array();
	$chap = array();
	foreach ( (array) ( $m['chapters'] ?? array() ) as $c ) {
		$chap[] = array( 'title' => (string) ( $c['title'] ?? '' ), 'start' => (float) ( $c['start_time'] ?? 0 ), 'end' => (float) ( $c['end_time'] ?? 0 ) );
	}
	$heat = array();
	foreach ( (array) ( $m['heatmap'] ?? array() ) as $h ) {
		if ( isset( $h['start_time'], $h['value'] ) ) {
			$heat[] = array( 'start' => (float) $h['start_time'], 'value' => (float) $h['value'] );
		}
	}
	usort( $heat, function ( $a, $b ) { return $b['value'] <=> $a['value']; } );
	return array( 'title' => (string) ( $m['title'] ?? '' ), 'duration' => (float) ( $m['duration'] ?? 0 ), 'chapters' => $chap, 'heatmap' => array_slice( $heat, 0, 8 ) );
}

/** Pilih N segmen momen terbaik → array{start,end,title}[] | WP_Error. */
function cc_control_pick_moments( $srt_path, $meta, $count, $min, $max ) {
	$count = max( 1, min( 10, (int) $count ) );
	$min   = max( 5, (int) $min );
	$max   = max( $min + 5, (int) $max );

	// Transkrip dipadatkan: blok ~90s berisi timestamp + teks.
	$blocks = '';
	if ( $srt_path && file_exists( $srt_path ) ) {
		// Hitung skala: seluruh video harus muat ~12000 char → perkecil teks bila panjang.
		$all    = preg_split( '/\r?\n\r?\n/', trim( (string) file_get_contents( $srt_path ) ) );
		$scale  = count( $all ) > 300 ? 3 : 1; // padatkan cue jadi kelompok lebih besar utk video panjang
		$cap    = count( $all ) > 300 ? 320 : 900;
		$win    = count( $all ) > 300 ? 180 : 90;
		$acc = '';
		$ts  = 0.0;
		$k   = 0;
		foreach ( $all as $b ) {
			if ( ! preg_match( '#(\d+):(\d+):(\d+),(\d+)\s*-->\s*(\d+):(\d+):(\d+),(\d+)#', $b, $mm ) ) {
				continue;
			}
			$t1   = cc_control_srt_ts( $mm[1], $mm[2], $mm[3], $mm[4] );
			$t2   = cc_control_srt_ts( $mm[5], $mm[6], $mm[7], $mm[8] );
			$text = trim( preg_replace( '/\s+/', ' ', substr( $b, strlen( $mm[0] ) ) ) );
			if ( '' === $acc ) {
				$ts = $t1;
			}
			$acc .= ' ' . $text;
			$k++;
			if ( $t2 - $ts >= $win ) {
				$blocks .= '[' . round( $ts ) . '-' . round( $t2 ) . ']' . mb_substr( $acc, 0, $cap ) . "\n";
				$acc     = '';
			}
		}
		if ( '' !== trim( $acc ) ) {
			$blocks .= '[' . round( $ts ) . '-' . round( $t2 ) . ']' . mb_substr( $acc, 0, $cap ) . "\n";
		}
	}
	if ( '' === trim( $blocks ) ) {
		return new WP_Error( 'cc_moments', 'Transkrip kosong — tidak ada yang bisa dianalisis.' );
	}

	$hint = '';
	if ( ! empty( $meta['chapters'] ) ) {
		$hint .= "Chapter YouTube (waktu dalam detik):\n";
		foreach ( $meta['chapters'] as $c ) {
			$hint .= '- ' . round( $c['start'] ) . 's ' . $c['title'] . "\n";
		}
	}
	if ( ! empty( $meta['heatmap'] ) ) {
		$hint .= "Titik paling sering diulang penonton (detik): " . implode( ', ', array_map( function ( $h ) { return round( $h['start'] ); }, $meta['heatmap'] ) ) . "\n";
	}

	$custom = trim( (string) get_option( 'cc_moments_prompt', '' ) );
	$sys    = 'Kamu editor video. Balas HANYA JSON array [{"start":angka_detik,"end":angka_detik,"title":"judul-pendek"}]. Waktu SELALU dalam detik murni (contoh: 2 menit 12 detik = 132), bukan format menit.detik.';
	if ( '' !== $custom ) {
		$sys .= "\n\nKriteria pemilihan momen dari user:\n" . $custom;
	}
	$msgs = array(
		array( 'role' => 'system', 'content' => $sys ),
		array(
			'role'    => 'user',
			'content' => "Pilih {$count} momen terbaik dari video ini untuk reel vertikal. Tiap segmen {$min}-{$max} detik, tidak overlap, urut kronologis. start/end HARUS di batas kalimat selesai (jangan potong di tengah kalimat). Prioritaskan isi paling menarik/poin kuat"
				. ( $hint ? " (perhatikan hint di bawah)" : '' ) . ".\nDurasi video " . round( (float) $meta['duration'] ) . " detik — JANGAN pilih melewati batas itu.\n\n" . $hint . "\nTranskrip:\n" . mb_substr( $blocks, 0, 16000 ),
		),
	);

	$reply   = '';
	$api_err = '';
	if ( function_exists( 'cc_openai_configured' ) && cc_openai_configured() && class_exists( 'CC_OpenAI' ) ) {
		$res = CC_OpenAI::chat( $msgs );
		if ( is_wp_error( $res ) ) {
			$api_err = $res->get_error_message();
		} else {
			$reply = (string) ( $res['message']['content'] ?? '' );
		}
	}
	// DEBUG — simpan jawaban mentah AI.
	file_put_contents( cc_ws_tmp() . 'moments-last.json', $reply );
	// Parse JSON array dari reply (toleran terhadap teks di sekitarnya).
	$picks = array();
	if ( '' !== $reply && preg_match( '/\[.*\]/s', $reply, $jm ) ) {
		foreach ( (array) json_decode( $jm[0], true ) ?: array() as $it ) {
			$s = (float) ( $it['start'] ?? -1 );
			$e = (float) ( $it['end'] ?? -1 );
			$t = trim( (string) ( $it['title'] ?? '' ) );
			if ( $s >= 0 && $e > $s ) {
				$picks[] = array( 'start' => $s, 'end' => $e, 'title' => $t );
			}
		}
	}

	// Fallback deterministik: chapter (bila AI gagal/tidak dikonfigurasi).
	if ( ! $picks && ! empty( $meta['chapters'] ) ) {
		foreach ( $meta['chapters'] as $c ) {
			$picks[] = array( 'start' => $c['start'], 'end' => min( $c['end'] > $c['start'] ? $c['end'] : $c['start'] + $max, $c['start'] + $max ), 'title' => $c['title'] );
			if ( count( $picks ) >= $count ) {
				break;
			}
		}
	}
	if ( ! $picks ) {
		return new WP_Error(
			'cc_moments',
			'AI tidak mengembalikan segmen valid & tidak ada chapter fallback'
			. ( $api_err ? ' — OpenAI: ' . $api_err : '' )
			. ( '' !== $reply && ! $api_err ? ' — jawaban AI: ' . mb_substr( $reply, 0, 120 ) : '' )
		);
	}

	// Deteksi format m.ss (menit.detik) dari AI — mis. 2.12 = 2:12 = 132 detik.
	// Ciri: semua pick terjepit di <30% durasi video & fraksi < .60.
	if ( $picks && $meta['duration'] > 0 ) {
		$max_end = max( array_map( function ( $k ) { return $k['end']; }, $picks ) );
		$ms_like = $max_end < $meta['duration'] * 0.3;
		if ( $ms_like ) {
			foreach ( $picks as $k ) {
				foreach ( array( 'start', 'end' ) as $f ) {
					if ( $k[ $f ] - floor( $k[ $f ] ) >= 0.60 ) {
						$ms_like = false;
						break 2;
					}
				}
			}
		}
		if ( $ms_like ) {
			foreach ( $picks as &$k ) {
				foreach ( array( 'start', 'end' ) as $f ) {
					$k[ $f ] = floor( $k[ $f ] ) * 60 + ( $k[ $f ] - floor( $k[ $f ] ) ) * 100;
				}
			}
			unset( $k );
		}
	}
	// Validasi: clamp durasi, sort, batasi jumlah.
	$out = array();
	usort( $picks, function ( $a, $b ) { return $a['start'] <=> $b['start']; } );
	foreach ( $picks as $k ) {
		// Buang/clamp di luar durasi video.
		if ( $meta['duration'] > 0 ) {
			if ( $k['start'] >= $meta['duration'] ) {
				continue;
			}
			$k['end'] = min( $k['end'], $meta['duration'] );
		}
		$d = $k['end'] - $k['start'];
		$k['end']   = $k['start'] + min( $max, max( $min, $d ) );
		$k['title'] = $k['title'] ?: ( 'momen-' . ( count( $out ) + 1 ) );
		$out[]      = $k;
		if ( count( $out ) >= $count ) {
			break;
		}
	}
	return $out;
}

/**
 * Cari nama proyek berikutnya dengan format `{base}-001`, `{base}-002`, dst.
 * yang belum ada di workspace (aman terhadap filesystem case-insensitive macOS).
 */
function cc_control_next_name( $base, &$reserved = array() ) {
	$base = sanitize_file_name( (string) $base );
	$base = preg_replace( '/-\d{3}$/', '', $base );
	if ( '' === $base ) {
		$base = 'project';
	}
	$ws = trailingslashit( cc_ws_projects() );
	for ( $n = 1; $n <= 999; $n++ ) {
		$cand = $base . '-' . str_pad( (string) $n, 3, '0', STR_PAD_LEFT );
		$key  = strtolower( $cand );
		if ( isset( $reserved[ $key ] ) ) {
			continue;
		}
		if ( ! file_exists( $ws . $cand ) ) {
			$reserved[ $key ] = true;
			return $cand;
		}
	}
	return $base . '-' . time();
}

/** 62.4 detik → "1:02"; dipakai untuk label task + ringkasan. */
function cc_fmt_time( $sec ) {
	$sec  = max( 0, (float) $sec );
	$m    = (int) floor( $sec / 60 );
	$s    = $sec - $m * 60;
	$si   = (int) floor( $s );
	$frac = $s - $si;
	return $m . ':' . str_pad( (string) $si, 2, '0', STR_PAD_LEFT )
		. ( $frac > 0.001 ? '.' . (int) round( $frac * 10 ) : '' );
}

/**
 * Create a control_run job (+ its session). Returns
 * array{job_id,session_id} or WP_Error.
 */
function cc_control_enqueue( $params ) {
	if ( cc_job_active_any() ) {
		return new WP_Error( 'cc_busy', 'Masih ada perintah yang sedang diproses — tunggu atau batalkan dulu.' );
	}

	$name    = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
	$uid     = get_current_user_id();
	// Reuse sesi aktif milik user bila dikirim; selain itu buat sesi baru.
	$session = 0;
	$sid     = absint( $params['session_id'] ?? 0 );
	if ( $sid ) {
		$exist = cc_session_get( $sid );
		if ( $exist && cc_session_can_view( $exist, $uid ) ) {
			$session = $sid;
		}
	}
	if ( ! $session ) {
		$session = cc_session_create( $uid, $name );
		if ( ! $session ) {
			return new WP_Error( 'cc_db', 'Gagal membuat sesi.' );
		}
	}

	// A user-role row summarises the request so the thread reads naturally.
	if ( 'moments' === ( $params['mode'] ?? 'manual' ) ) {
		$segs_txt = sprintf(
			'momen AI: %d × %d–%ds',
			(int) ( $params['moment_count'] ?? 3 ),
			(int) ( $params['moment_min'] ?? 30 ),
			(int) ( $params['moment_max'] ?? 90 )
		);
	} else {
		$segs_txt = '';
		foreach ( (array) ( $params['segments'] ?? array() ) as $s ) {
			$segs_txt .= ( $segs_txt ? ', ' : '' ) . cc_fmt_time( $s['start'] ?? 0 ) . '–' . cc_fmt_time( $s['end'] ?? 0 );
		}
		if ( '' === $segs_txt ) {
			$segs_txt = cc_fmt_time( $params['start'] ?? 0 ) . '–' . cc_fmt_time( $params['end'] ?? 0 );
		}
	}
	$tpl_label = trim( (string) ( $params['template_label'] ?? '' ) );
	$wf        = (string) ( $params['workflow'] ?? 'clip' );
	$act_txt   = 'export' === ( $params['action'] ?? '' ) ? 'export' : 'simpan & serah ke Studio Inspirasi';
	if ( 'assembly' === $wf ) {
		$summary = 'Mode: Rakit dari VO — Template: ' . ( '' !== $tpl_label ? $tpl_label : '(tanpa template)' )
			. ' — VO: ' . basename( (string) ( $params['vo_file'] ?? $params['file'] ?? '' ) )
			. ' — B-Roll: ' . basename( (string) ( $params['broll_dir'] ?? '' ) )
			. ' — aksi: ' . $act_txt;
	} elseif ( 'broll' === $wf ) {
		$summary = 'Mode: Talking Head + B-Roll — Template: ' . ( '' !== $tpl_label ? $tpl_label : '(tanpa template)' )
			. ' — video: ' . basename( (string) ( $params['file'] ?? '' ) )
			. ' — B-Roll: ' . basename( (string) ( $params['broll_dir'] ?? '' ) )
			. ' — segmen: ' . $segs_txt
			. ' — aksi: ' . $act_txt;
	} else {
		$summary = 'Template: ' . ( '' !== $tpl_label ? $tpl_label : '(tanpa template)' )
			. ' — video: ' . basename( (string) ( $params['file'] ?? '' ) )
			. ' — segmen: ' . $segs_txt
			. ' — aksi: ' . $act_txt;
	}
	cc_message_add( $session, 'user', $summary );

	$placeholder = cc_message_add( $session, 'assistant', '', null, 'queued' );
	if ( ! $placeholder ) {
		return new WP_Error( 'cc_db', 'Gagal membuat pesan.' );
	}
	$job_id = cc_job_create( $session, $placeholder, $params, 'control_run' );
	if ( ! $job_id ) {
		return new WP_Error( 'cc_db', 'Gagal membuat job.' );
	}
	wp_schedule_single_event( time() + 2, 'cc_job_run', array( $job_id ) );
	spawn_cron();
	return array( 'job_id' => $job_id, 'session_id' => $session );
}

/* ---------------------------------------------------------------------
 * Worker — runs inside cc_job_run_worker like cc_agent_turn.
 * Returns array{body, meta?} on success or WP_Error.
 * ------------------------------------------------------------------- */

/** Exception used to bail mid-pipeline (wraps WP_Error). */
class CC_Control_Abort extends Exception {
	public $wp_error;
	public function __construct( WP_Error $e ) {
		parent::__construct( $e->get_error_message() );
		$this->wp_error = $e;
	}
}

function cc_control_run( $job ) {
	$p = json_decode( (string) $job->payload, true );
	if ( ! is_array( $p ) ) {
		return new WP_Error( 'cc_payload', 'Payload job tidak valid.' );
	}
	$session_id = (int) $job->session_id;
	$cx         = new CC_Concat();
	$ctx        = array( 'cx' => $cx );

	/** Stamp a task card, run $fn, settle the card; throws CC_Control_Abort on failure/cancel. */
	$prefix = '';
	$step   = function ( $label, $fn ) use ( $job, $session_id, &$prefix ) {
		$label = $prefix . $label;
		$fresh = cc_job_get( $job->id );
		if ( ! $fresh || 'cancelled' === $fresh->status ) {
			throw new CC_Control_Abort( new WP_Error( 'cc_cancelled', 'Perintah dibatalkan.' ) );
		}
		$tid = cc_message_add( $session_id, 'task', $label, array( 'tool' => 'control', 'status' => 'running', 'progress' => 0 ), 'running' );
		cc_job_update( $job->id, array( 'detail' => mb_substr( $label, 0, 250 ) ) );
		$out = $fn( $tid );
		if ( is_wp_error( $out ) ) {
			cc_message_update( $tid, array( 'status' => 'failed', 'meta' => array( 'tool' => 'control', 'status' => 'failed', 'progress' => 100 ) ) );
			throw new CC_Control_Abort( $out );
		}
		cc_message_update( $tid, array( 'status' => 'done', 'meta' => array( 'tool' => 'control', 'status' => 'done', 'progress' => 100 ) ) );
		return $out;
	};

	// Mode 'assembly' (VO MP3 + koleksi B-Roll + SFX → 1 proyek utuh) punya jalur sendiri.
	if ( 'assembly' === ( $p['workflow'] ?? 'clip' ) && function_exists( 'cc_assembly_run_vo' ) ) {
		try {
			$res = cc_assembly_run_vo( $cx, $ctx, $p, $step, $session_id );
			$cx->close();
			return $res;
		} catch ( CC_Control_Abort $e ) {
			$cp = cc_control_track_project();
			if ( '' !== $cp ) {
				$cx->call( 'project.close', array( 'path' => $cp ), 15 );
				cc_control_track_project( '' );
			}
			$cx->close();
			return $e->wp_error;
		} catch ( Throwable $e ) {
			$cp = cc_control_track_project();
			if ( '' !== $cp ) {
				$cx->call( 'project.close', array( 'path' => $cp ), 15 );
				cc_control_track_project( '' );
			}
			$cx->close();
			return new WP_Error( 'cc_assembly_err', $e->getMessage() );
		}
	}

	// YouTube: unduh video + caption SEKALI sebelum semua segmen.
	if ( cc_control_is_yt( $p['file'] ?? '' ) ) {
		try {
			$dl          = $step(
				'Mengunduh video + caption dari YouTube',
				function () use ( $p ) {
					return cc_control_yt_download( (string) $p['file'], (string) ( $p['cap_lang'] ?? 'id' ) );
				}
			);
			$p['file']   = $dl['file'];
			$p['yt_srt'] = $dl['srt'] ?? '';
		} catch ( CC_Control_Abort $e ) {
			$cx->close();
			return $e->wp_error;
		}
	}

	// Auto-Whisper untuk video lokal (atau YT tanpa caption) bila belum ada .srt:
	if ( empty( $p['srt'] ) && empty( $p['yt_srt'] ) && 'none' !== ( $p['cap_lang'] ?? 'id' ) && ! empty( $p['file'] ) && file_exists( (string) $p['file'] ) && function_exists( 'cc_assembly_ensure_srt' ) ) {
		$need_stt = ( 'moments' === ( $p['mode'] ?? 'manual' ) ) || ( 'broll' === ( $p['workflow'] ?? 'clip' ) );
		if ( $need_stt ) {
			$wsrt = cc_assembly_ensure_srt( (string) $p['file'], (string) ( $p['cap_lang'] ?? 'id' ), '', '' );
			if ( ! is_wp_error( $wsrt ) && '' !== trim( $wsrt ) ) {
				$tmp_srt = cc_ws_tmp() . 'auto-' . md5( (string) $p['file'] ) . '.srt';
				@file_put_contents( $tmp_srt, $wsrt );
				$p['yt_srt'] = $tmp_srt;
			}
		}
	}

	// Mode 'broll': pindai katalog B-Roll & SFX sekali sebelum loop segmen.
	$broll_catalog = array( 'brolls' => array(), 'sfxs' => array() );
	if ( 'broll' === ( $p['workflow'] ?? 'clip' ) && ! empty( $p['broll_dir'] ) && function_exists( 'cc_assembly_catalog_dir' ) ) {
		try {
			$broll_catalog = $step(
				'Memindai koleksi B-Roll' . ( ! empty( $p['broll_vision'] ) ? ' (+ AI Vision)' : '' ) . ( ! empty( $p['sfx_dir'] ) ? ' & SFX' : '' ),
				function () use ( $p ) {
					$br = cc_assembly_catalog_dir( (string) $p['broll_dir'], 'broll', ! empty( $p['broll_vision'] ) );
					if ( is_wp_error( $br ) ) {
						return $br;
					}
					if ( empty( $br ) ) {
						return new WP_Error( 'cc_broll_empty', 'Tidak ada file video di folder B-Roll: ' . $p['broll_dir'] );
					}
					$sf = array();
					if ( ! empty( $p['sfx_dir'] ) ) {
						$s = cc_assembly_catalog_dir( (string) $p['sfx_dir'], 'sfx', false );
						if ( ! is_wp_error( $s ) ) {
							$sf = $s;
						}
					}
					return array( 'brolls' => $br, 'sfxs' => $sf );
				}
			);
		} catch ( CC_Control_Abort $e ) {
			$cx->close();
			return $e->wp_error;
		}
		$p['_broll_catalog'] = $broll_catalog;
	}

	// Mode momen: AI memilih segmen terbaik dari transkrip + metadata.
	$seg_titles = array();
	if ( 'moments' === ( $p['mode'] ?? 'manual' ) ) {
		try {
			$seg_titles = $step(
				'Memilih momen terbaik (AI)',
				function () use ( $p ) {
					$meta = cc_control_is_yt( $p['file'] ?? '' )
						? cc_control_yt_meta( cc_control_yt_id( (string) $p['file'] ), (string) $p['file'] )
						: array( 'title' => '', 'duration' => 0, 'chapters' => array(), 'heatmap' => array() );
					$srt  = (string) ( $p['yt_srt'] ?? '' );
					return cc_control_pick_moments( $srt, $meta, (int) ( $p['moment_count'] ?? 3 ), (int) ( $p['moment_min'] ?? 30 ), (int) ( $p['moment_max'] ?? 90 ) );
				}
			);
		} catch ( CC_Control_Abort $e ) {
			$cx->close();
			return $e->wp_error;
		}
		// Tampilkan momen yang dipilih AI di thread.
		$list = array();
		foreach ( $seg_titles as $i => $t ) {
			$list[] = ( $i + 1 ) . '. ' . $t['title'] . ' (' . cc_fmt_time( $t['start'] ) . '–' . cc_fmt_time( $t['end'] ) . ')';
		}
		if ( $list ) {
			cc_message_add( $session_id, 'assistant', 'Momen terpilih: ' . implode( ' · ', $list ) );
		}
	}

	// Segmen: satu proyek per segmen (segments[] — fallback single start/end).
	$segs = array();
	if ( $seg_titles ) {
		// Mode momen — segmen dari hasil AI.
		foreach ( $seg_titles as $t ) {
			$segs[] = array( 'start' => (float) $t['start'], 'end' => (float) $t['end'], 'title' => (string) $t['title'] );
		}
	}
	foreach ( (array) ( $p['segments'] ?? array() ) as $s ) {
		if ( ! $seg_titles && isset( $s['start'], $s['end'] ) ) {
			$segs[] = array( 'start' => (float) $s['start'], 'end' => (float) $s['end'] );
		}
	}
	if ( ! $segs ) {
		$segs[] = array( 'start' => (float) ( $p['start'] ?? 0 ), 'end' => (float) ( $p['end'] ?? 0 ) );
	}
	// Snap batas ke tepi kalimat transkrip (semua mode, bila srt tersedia).
	$snap_src = ! empty( $p['srt'] ) ? (string) $p['srt'] : (string) ( $p['yt_srt'] ?? '' );
	if ( '' !== $snap_src ) {
		$segs = array_map(
			function ( $s ) use ( $snap_src ) {
				return array_merge( $s, cc_control_snap_segment( (float) $s['start'], (float) $s['end'], $snap_src ) );
			},
			$segs
		);
	}
	$total    = count( $segs );
	$bodies   = array();
	$errors   = array();
	$meta     = array();
	$reserved = array();
	foreach ( $segs as $i => $seg ) {
		$sp          = $p;
		$sp['start'] = $seg['start'];
		$sp['end']   = $seg['end'];
		$sp['name']  = cc_control_next_name( ! empty( $seg['title'] ) ? sanitize_title( $seg['title'] ) : ( $p['name'] ?? 'project' ), $reserved );
		if ( ! empty( $p['output_name'] ) ) {
			$oe = pathinfo( (string) $p['output_name'], PATHINFO_EXTENSION );
			$sp['output_name'] = $sp['name'] . ( $oe ? '.' . $oe : '.mp4' );
		}
		$prefix = $total > 1 ? '[' . ( $i + 1 ) . '/' . $total . '] ' : '';
		try {
			$r        = cc_control_run_one( $sp, $job, $session_id, $cx, $ctx, $step );
			$bodies[] = (string) ( $r['body'] ?? '' );
			if ( ! empty( $r['meta']['video_url'] ) ) {
				$meta['video_url'] = $r['meta']['video_url'];
			}
		} catch ( CC_Control_Abort $e ) {
			if ( 'cc_cancelled' === $e->wp_error->get_error_code() ) {
				$cx->close();
				return $e->wp_error;
			}
			$errors[] = '**' . $sp['name'] . '** gagal — ' . $e->wp_error->get_error_message();
		} catch ( Throwable $e ) {
			$errors[] = '**' . $sp['name'] . '** gagal — ' . $e->getMessage();
		}
		// Lepas lock Remote API — proyek yang gagal tidak tersangkut terbuka.
		$cp = cc_control_track_project();
		if ( '' !== $cp ) {
			$cx->call( 'project.close', array( 'path' => $cp ), 15 );
			cc_control_track_project( '' );
		}
	}
	$cx->close();
	if ( ! $bodies && $errors ) {
		return new WP_Error( 'cc_control_failed', implode( "\n", $errors ) );
	}
	$all = array_merge( $bodies, $errors );
	return array( 'body' => implode( "\n\n", $all ), 'meta' => $meta );
}

/**
 * Satu segmen → satu proyek. Pipeline penuh: instantiate/create → media ke
 * timeline → potong segmen → subtitle → save → handoff | export.
 * Returns array{body,meta}; throws CC_Control_Abort pada error/cancel.
 */
function cc_control_run_one( $sp, $job, $session_id, $cx, $ctx, $step ) {
	$p       = $sp;
	$project = '';
	$meta    = array();
	try {
		/* 1. Create/instantiate the project */
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
							'fills'    => is_array( $p['fills'] ?? null ) ? array_values( $p['fills'] ) : array(),
						),
						60
					);
				} else {
					wp_mkdir_p( cc_workspace() );
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
						// Timeline ikut disamakan (create hanya set project-level).
						$r    = $cx->call(
							'project.setVideo',
							array(
								'path'  => wp_normalize_path( (string) $path ),
								'video' => array_merge( $dims, array( 'rateNum' => 30, 'rateDen' => 1 ) ),
							),
							30
						);
						if ( is_wp_error( $r ) ) {
							return $r;
						}
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

		/* 2-3. Media masuk ke timeline — jalur template vs proyek baru */
		if ( ! empty( $p['template'] ) ) {
			$clip = $step(
				'Membaca timeline & mencari clip utama',
				function () use ( $cx, $project ) {
					$g = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					foreach ( (array) ( $g['project']['timelines'] ?? array() ) as $tl ) {
						if ( ( $tl['id'] ?? '' ) !== ( $g['project']['activeTimelineId'] ?? '' ) ) {
							continue;
						}
						$main = '';
						foreach ( (array) ( $tl['tracks'] ?? array() ) as $tr ) {
							if ( ! empty( $tr['isMain'] ) ) {
								$main = (string) $tr['id'];
							}
						}
						foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
							if ( 'video' === ( $c['kind'] ?? '' ) && ( '' === $main || ( $c['trackId'] ?? '' ) === $main ) ) {
								return array( 'clip' => $c, 'track' => $c['trackId'] );
							}
						}
					}
					return new WP_Error( 'cc_clip', 'Tidak menemukan clip video utama di timeline template.' );
				}
			);
			$step(
				'Mengganti media clip dengan ' . basename( (string) $p['file'] ),
				function () use ( $ctx, $project, $clip, $p ) {
					return CC_Tools::replace_clip_media( $ctx, $project, (string) $clip['clip']['id'], (string) $p['file'] );
				}
			);
		} else {
			$clip = $step(
				'Mengimpor & menambahkan ' . basename( (string) $p['file'] ),
				function () use ( $cx, $project, $p ) {
					$mi = $cx->call( 'media.import', array( 'path' => $project, 'file' => $p['file'] ), 60 );
					if ( is_wp_error( $mi ) ) {
						return $mi;
					}
					// media.import mengembalikan proyek utuh — id ada di media[] terakhir.
					$mid = '';
					foreach ( array_reverse( (array) ( $mi['project']['media'] ?? $mi['media'] ?? array() ) ) as $m ) {
						if ( basename( (string) ( $m['path'] ?? '' ) ) === basename( (string) $p['file'] ) || ( $m['kind'] ?? '' ) === 'video' ) {
							$mid = (string) ( $m['id'] ?? '' );
							break;
						}
					}
					if ( '' === $mid ) {
						return new WP_Error( 'cc_media', 'Import tidak mengembalikan id media.' );
					}
					$g = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					$main = '';
					foreach ( (array) ( $g['project']['timelines'] ?? array() ) as $tl ) {
						foreach ( (array) ( $tl['tracks'] ?? array() ) as $tr ) {
							if ( ! empty( $tr['isMain'] ) ) {
								$main = (string) $tr['id'];
							}
						}
					}
					if ( '' === $main ) {
						return new WP_Error( 'cc_track', 'Track utama tidak ditemukan.' );
					}
					$a = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addClipAtFirstFree', 'trackId' => $main, 'mediaId' => $mid, 'start' => 0 ) ), 30 );
					if ( is_wp_error( $a ) ) {
						return $a;
					}
					// Re-read untuk mendapatkan clip yang baru dibuat.
					$g2 = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $g2 ) ) {
						return $g2;
					}
					foreach ( (array) ( $g2['project']['timelines'] ?? array() ) as $tl ) {
						foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
							if ( ( $c['mediaId'] ?? '' ) === $mid ) {
								return array( 'clip' => $c, 'track' => $main );
							}
						}
					}
					return new WP_Error( 'cc_clip', 'Clip tidak ditemukan setelah addClip.' );
				}
			);
		}

		/* 4. Cut the segment (start/end in seconds, relative to the new media) */
		$step(
			'Memotong segmen ' . cc_fmt_time( $p['start'] ) . '–' . cc_fmt_time( $p['end'] ),
			function () use ( $cx, $project, $clip, $p ) {
				$start = (float) $p['start'];
				$dur   = (float) $p['end'] - $start;
				// Probe keeps us honest: reject segments beyond the media.
				$probe = $cx->call( 'media.probe', array( 'path' => $p['file'] ), 30 );
				if ( is_wp_error( $probe ) ) {
					return $probe;
				}
				$mlen = (float) ( $probe['duration'] ?? 0 );
				if ( $mlen > 0 && (float) $p['end'] > $mlen + 0.05 ) {
					return new WP_Error( 'cc_segment', 'Segmen melebihi durasi media (' . round( $mlen, 1 ) . ' detik).' );
				}

				$cid     = (string) $clip['clip']['id'];
				$trk     = (string) $clip['track'];
				// Re-read clip — replaceClipMedia mengubah duration/sourceStart
				// jadi $clip dari step sebelumnya stale (durasi = media penuh).
				$refetch = function () use ( $cx, $project, $cid ) {
					$gf = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $gf ) ) {
						return $gf;
					}
					foreach ( (array) ( $gf['project']['timelines'] ?? array() ) as $tl ) {
						foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
							if ( ( $c['id'] ?? '' ) === $cid ) {
								return $c;
							}
						}
					}
					return null;
				};
				$cur = $refetch();
				if ( ! $cur || is_wp_error( $cur ) ) {
					return new WP_Error( 'cc_clip', 'Clip hilang setelah ganti media.' );
				}
				$apply   = function ( $cmd ) use ( $cx, $project ) {
					$r = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => $cmd ), 30 );
					return is_wp_error( $r ) ? $r : true;
				};
				// trimClip edge=start hanya bisa memangkas — clip 10 detik tidak
				// bisa "digeser" 714 detik ke kanan. Urutan aman, di "parking
				// lot" kosong jauh di kanan supaya ekstensi sementara tidak
				// men-overlap clip lain: (a) kalau in-point baru di KIRI in-point
				// lama, rentangkan kiri dulu; (b) set right-edge ke E; (c) kalau
				// in-point baru di KANAN, rapatkan kiri sampai S. Hasil: clip
				// persis [S, E]. Lalu kembalikan ke posisi awal.
				$home  = (float) $cur['start'];
				$ss    = (float) ( $cur['sourceStart'] ?? 0 );
				$right = $ss + (float) $cur['duration'];
				if ( abs( $start - $ss ) > 0.001 || abs( $right - (float) $p['end'] ) > 0.001 ) {
					$park = $home + abs( $start - $ss ) + $dur + (float) $cur['duration'] + 30;
					$r = $apply( array( 'op' => 'moveClips', 'moves' => array( array( 'clipId' => $cid, 'trackId' => $trk, 'start' => $park ) ) ) );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					if ( $start < $ss - 0.001 ) {
						// In-point baru di kiri — rentangkan edge kiri.
						$r = $apply( array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'start', 'delta' => $start - $ss ) );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						$ss = $start;
					}
					// Right edge: delta = E - right_now — delta RELATIF terhadap
					// tepi kanan SEBELUM trim (left-extend tak mengubahnya).
					$edelta = (float) $p['end'] - $right;
					if ( abs( $edelta ) > 0.001 ) {
						$r = $apply( array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'end', 'delta' => $edelta ) );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
					}
					if ( $start > $ss + 0.001 ) {
						// In-point baru di kanan — rapatkan edge kiri (right edge tetap E).
						$r = $apply( array( 'op' => 'trimClip', 'clipId' => $cid, 'edge' => 'start', 'delta' => $start - $ss ) );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
					}
					$r = $apply( array( 'op' => 'moveClips', 'moves' => array( array( 'clipId' => $cid, 'trackId' => $trk, 'start' => $home ) ) ) );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				return true;
			}
		);

		/* 4a2. Potong jeda hening — split di batas keep, buang jeda ripple. */
		$warn  = '';
		$keeps = array();
		if ( ! empty( $p['silence_cut'] ) ) {
			$keeps = cc_control_silence_ranges(
				(string) $p['file'],
				(float) $p['start'],
				(float) $p['end'],
				array(
					'db'  => (float) ( $p['silence_db'] ?? -35 ),
					'min' => (float) ( $p['silence_min'] ?? 0.5 ),
					'pad' => (float) ( $p['silence_pad'] ?? 0.1 ),
				)
			);
			if ( is_wp_error( $keeps ) ) {
				$warn  = ' Deteksi hening gagal (' . $keeps->get_error_message() . ') — lanjut tanpa potong.';
				$keeps = array();
			}
		}
		if ( count( $keeps ) > 1 ) {
			$step(
				'Memotong ' . ( count( $keeps ) - 1 ) . ' jeda hening',
				function () use ( $cx, $project, $keeps, $p ) {
					$g = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $g ) ) {
						return $g;
					}
					// Clip utama (video) + posisi timeline-nya.
					$cid = '';
					$home = 0;
					foreach ( (array) ( $g['project']['timelines'] ?? array() ) as $tl ) {
						if ( ( $tl['id'] ?? '' ) !== ( $g['project']['activeTimelineId'] ?? '' ) ) {
							continue;
						}
						foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
							if ( 'video' === ( $c['kind'] ?? '' ) ) {
								$cid  = (string) $c['id'];
								$home = (float) $c['start'];
								break 2;
							}
						}
					}
					if ( '' === $cid ) {
						return new WP_Error( 'cc_clip', 'Clip utama tidak ditemukan untuk split.' );
					}
					$start = (float) $p['start'];
					// Gaps = komplemen keeps dalam [start,end]; split kanan→kiri
					// supaya posisi tetap valid (clip kiri mempertahankan cid).
					$gaps = array();
					$cur  = $start;
					foreach ( $keeps as $k ) {
						if ( $k[0] - $cur > 0.05 ) {
							$gaps[] = array( $cur, $k[0] );
						}
						$cur = max( $cur, $k[1] );
					}
					foreach ( array_reverse( $gaps ) as $g ) {
						foreach ( array( $g[1], $g[0] ) as $b ) {
							$r = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'splitClips', 'clipIds' => array( $cid ), 'time' => $home + ( $b - $start ) ) ), 30 );
							if ( is_wp_error( $r ) ) {
								return $r;
							}
						}
					}
					// Kumpulkan clip yang menempati jeda → removeClips ripple.
					$g2   = $cx->call( 'project.get', array( 'path' => $project ), 30 );
					if ( is_wp_error( $g2 ) ) {
						return $g2;
					}
					$rm = array();
					foreach ( (array) ( $g2['project']['timelines'] ?? array() ) as $tl ) {
						foreach ( (array) ( $tl['clips'] ?? array() ) as $c ) {
							if ( 'video' !== ( $c['kind'] ?? '' ) ) {
								continue;
							}
							$css = (float) ( $c['sourceStart'] ?? -1 );
							foreach ( $gaps as $g ) {
								if ( $css >= $g[0] - 0.02 && $css < $g[1] - 0.02 ) {
									$rm[] = (string) $c['id'];
									break;
								}
							}
						}
					}
					if ( ! $rm ) {
						return new WP_Error( 'cc_sil', 'Tidak ada clip jeda ditemukan setelah split.' );
					}
					$r = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'removeClips', 'clipIds' => $rm, 'ripple' => true ) ), 30 );
					return is_wp_error( $r ) ? $r : true;
				}
			);
		}

		/* 4b. Subtitle opsional — manual .srt prioritas; else caption YouTube — soft-fail */
		$srt_txt = '';
		if ( ! empty( $p['srt'] ) && file_exists( $p['srt'] ) && CC_Tools::read_allowed( $p['srt'] ) ) {
			$srt_txt = (string) file_get_contents( $p['srt'] );
		} elseif ( ! empty( $p['yt_srt'] ) && file_exists( $p['yt_srt'] ) && 'none' !== ( $p['cap_lang'] ?? '' ) ) {
			$raw     = (string) file_get_contents( $p['yt_srt'] );
			// Silence cut → remap cue absolut ke timeline terkompresi; else slice.
			$srt_txt = $keeps
				? cc_control_srt_remap( $raw, $keeps )
				: cc_control_srt_slice( $raw, (float) $p['start'], (float) $p['end'] );
		}

		/* 4a3. Mode 'broll' — AI Director menyusun & memasang B-Roll + SFX di atas talking head */
		if ( 'broll' === ( $p['workflow'] ?? 'clip' ) && ! empty( $p['_broll_catalog']['brolls'] ) && function_exists( 'cc_assembly_plan_edl' ) ) {
			$cat = $p['_broll_catalog'];
			$edl = $step(
				'AI Director menyusun B-Roll (' . count( $cat['brolls'] ) . ' bahan video)',
				function () use ( $cx, $project, $srt_txt, $cat, $p ) {
					$span = cc_assembly_timeline_main_span( $cx, $project );
					return cc_assembly_plan_edl(
						$srt_txt,
						(float) $span['duration'],
						$cat['brolls'],
						$cat['sfxs'],
						array(
							'workflow' => 'broll',
							'pacing'   => (string) ( $p['broll_pacing'] ?? 'calm' ),
							'prompt'   => (string) ( $p['broll_prompt'] ?? '' ),
						)
					);
				}
			);
			if ( ! empty( $edl ) ) {
				$step(
					'Memasang ' . count( $edl ) . ' klip B-Roll & SFX ke timeline',
					function () use ( $cx, $project, $edl, $cat ) {
						return cc_assembly_apply_edl( $cx, $project, $edl, $cat['brolls'], $cat['sfxs'], 'broll', (string) ( $p['broll_motion'] ?? 'slide' ) );
					}
				);
			}
		}
		if ( '' !== trim( $srt_txt ) ) {
			$tid = cc_message_add( $session_id, 'task', 'Menambahkan subtitle', array( 'tool' => 'control', 'status' => 'running', 'progress' => 0 ), 'running' );
			$st  = cc_control_caption_style( $cx, $project );
			// Replace caption template: hapus clip + sumber caption lama dulu.
			if ( $st['clips'] ) {
				$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'removeClips', 'clipIds' => $st['clips'], 'ripple' => false ) ), 30 );
			}
			foreach ( $st['sources'] as $sid ) {
				$cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'removeCaptionSource', 'sourceId' => $sid ) ), 30 );
			}
			// addCaptionSource TANPA placeAt (placeAt internal memakai
			// track_id:None → cue tersebar ke banyak lane). Lalu
			// placeCaptionSource dengan trackId eksplisit = satu lane.
			$r   = $cx->call(
				'edit.apply',
				array(
					'path'    => $project,
					'command' => array(
						'op'      => 'addCaptionSource',
						'name'    => 'subs',
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
					// Belum ada lane captions — buat track baru kind:captions.
					$at = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'addTrack', 'kind' => 'captions' ) ), 30 );
					$lane = is_wp_error( $at ) ? '' : (string) ( $at['createdId'] ?? $at['created_id'] ?? '' );
				}
				if ( '' !== $sid && '' !== $lane ) {
					$pr2 = $cx->call( 'edit.apply', array( 'path' => $project, 'command' => array( 'op' => 'placeCaptionSource', 'sourceId' => $sid, 'start' => 0.0, 'trackId' => $lane ) ), 60 );
					if ( is_wp_error( $pr2 ) ) {
						$warn = ' Catatan: penempatan caption gagal (' . $pr2->get_error_message() . ').';
					}
				}
			}
			cc_message_update( $tid, array( 'status' => $ok ? 'done' : 'failed', 'meta' => array( 'tool' => 'control', 'status' => $ok ? 'done' : 'failed', 'progress' => 100 ) ) );
			if ( ! $ok ) {
				$warn = ' Catatan: subtitle gagal ditambahkan (' . $r->get_error_message() . ').';
			}
		}

		/* 5. Persist */
		$step(
			'Menyimpan proyek',
			function () use ( $cx, $project ) {
				$r = $cx->call( 'project.save', array( 'path' => $project ), 30 );
				return is_wp_error( $r ) ? $r : true;
			}
		);

		/* 6a. Hand off to the Clovercut UI */
		if ( 'export' !== ( $p['action'] ?? '' ) ) {
			$step(
				'Menutup proyek (serah ke Studio Inspirasi)',
				function () use ( $cx, $project ) {
					$r = $cx->call( 'project.close', array( 'path' => $project ), 15 );
					cc_control_track_project( '' );
					return is_wp_error( $r ) ? $r : true;
				}
			);
			return array(
				'body' => 'Proyek **' . ( $p['name'] ?? 'project' ) . '** siap di `' . $project . '` — buka di Studio Inspirasi untuk ditinjau & diekspor manual.' . $warn,
				'meta' => $meta,
			);
		}

		/* 6b. Export via API, then optional copy to an allowed folder */
		$output = cc_ws_exported() . sanitize_file_name( (string) ( $p['output_name'] ?? 'export.mp4' ) );
		if ( ! preg_match( '/\.(mp4|mov|mkv|webm)$/i', $output ) ) {
			$output .= '.mp4';
		}
		wp_mkdir_p( dirname( $output ) );

		$step(
			'Merender ' . basename( $output ),
			function ( $tid ) use ( $cx, $project, $output, $job, $session_id ) {
				$started = $cx->call( 'export.run', array( 'path' => $project, 'output' => wp_normalize_path( $output ) ), 30 );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$j = $started['job'] ?? '';
				if ( ! $j ) {
					return new WP_Error( 'cc_export', 'Respons export.run tidak membawa nama job.' );
				}
				$done = $cx->wait_export(
					$j,
					function ( $ev, $params ) use ( $tid, $job ) {
						if ( 'export.progress' === $ev ) {
							$pct = ! empty( $params['total'] ) ? min( 99, (int) round( 100 * $params['frame'] / max( 1, $params['total'] ) ) ) : 0;
							cc_message_update( $tid, array( 'meta' => array( 'tool' => 'control', 'status' => 'running', 'progress' => $pct ) ) );
							cc_job_update( $job->id, array( 'progress' => $pct, 'detail' => 'Export ' . ( $params['stage'] ?? 'video' ) . ' ' . $pct . '%' ) );
						}
						$jrow = cc_job_get( $job->id );
						return ! ( $jrow && 'cancelled' === $jrow->status );
					}
				);
				return is_wp_error( $done ) ? $done : true;
			}
		);

		if ( ! empty( $p['dest_dir'] ) ) {
			$step(
				'Menyalin hasil ke ' . basename( (string) $p['dest_dir'] ),
				function () use ( $output, $p, &$meta ) {
					$r = CC_Tools::copy_result( wp_normalize_path( $output ), (string) $p['dest_dir'] );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					if ( is_array( $r ) && ! empty( $r['playable_url'] ) ) {
						$meta['video_url'] = $r['playable_url'];
					}
					return true;
				}
			);
		}

		return array(
			'body' => 'Selesai — hasil export ada di `' . wp_normalize_path( $output ) . '`'
				. ( ! empty( $meta['video_url'] ) ? ' (tersedia juga di bawah).' : '' ) . $warn,
			'meta' => $meta,
		);
	} catch ( Throwable $e ) {
		if ( $e instanceof CC_Control_Abort ) {
			throw $e;
		}
		throw new CC_Control_Abort( new WP_Error( 'cc_control', 'Kesalahan tak terduga: ' . $e->getMessage() ) );
	}
}

/* ---------------------------------------------------------------------
 * REST-facing helpers
 * ------------------------------------------------------------------- */

/** Template list, cached — socket is single-client so don't probe while busy. */
function cc_control_templates() {
	$cached = get_transient( 'cc_tpl_probe' );
	$busy   = (bool) cc_job_active_any();
	if ( is_array( $cached ) && ( $busy || ( $cached['ts'] ?? 0 ) > time() - 60 ) ) {
		unset( $cached['ts'] );
		return $cached;
	}
	$cx   = new CC_Concat();
	$con  = $cx->connect();
	if ( is_wp_error( $con ) ) {
		// Socket sibuk/gagal — sajikan cache terakhir alih-alih list kosong.
		return is_array( $cached ) ? $cached : $con;
	}
	$out = $cx->call( 'template.list', array(), 15 );
	$cx->close();
	if ( is_wp_error( $out ) ) {
		return is_array( $cached ) ? $cached : $out;
	}
	$out['ts'] = time();
	set_transient( 'cc_tpl_probe', $out, 300 );
	unset( $out['ts'] );
	return $out;
}

/** Folder browser: subdirs + media files, read-guarded like list_folder. */
function cc_control_folder( $path ) {
	$path = wp_normalize_path( trim( (string) $path ) );
	if ( ! CC_Tools::read_allowed( $path ) ) {
		return new WP_Error(
			'cc_path',
			'Folder di luar daftar yang diizinkan. Boleh dibaca: ' . implode( '; ', CC_Tools::read_roots() )
		);
	}
	if ( ! is_dir( $path ) ) {
		return new WP_Error( 'cc_path', 'Folder tidak ditemukan.' );
	}
	$exts  = array( 'mp4', 'mov', 'mkv', 'webm', 'avi', 'm4v', 'mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'srt', 'jpg', 'jpeg', 'png' );
	$dirs  = array();
	$files = array();
	foreach ( (array) scandir( $path ) as $f ) {
		if ( '' === $f || '.' === $f[0] ) {
			continue;
		}
		$full = $path . '/' . $f;
		if ( is_dir( $full ) ) {
			$dirs[] = array( 'name' => $f, 'path' => $full );
		} elseif ( is_file( $full ) ) {
			$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, $exts, true ) ) {
				$files[] = array( 'name' => $f, 'path' => $full, 'size' => filesize( $full ) );
			}
		}
	}
	$parent = dirname( $path );
	return array(
		'path'   => $path,
		'parent' => ( $parent !== $path && CC_Tools::read_allowed( $parent ) ) ? $parent : '',
		'dirs'   => $dirs,
		'files'  => $files,
	);
}
