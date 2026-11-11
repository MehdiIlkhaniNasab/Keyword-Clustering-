<?php
/**
 * Keyword files that already carry the SEO manager's own work.
 *
 * Two shapes are understood, in any sheet of an XLSX (each sheet can be a pillar):
 *
 * 1. Research layout (like the Delna research): section titles on top (merged
 *    across their columns), under them one «keyword | volume» column pair per
 *    cluster; inside one column pair a blank row starts another cluster; empty
 *    columns separate the sections.
 * 2. Flat list: keyword | volume, with optional «cluster», «section» and «pillar»
 *    columns (found by their header names).
 *
 * Result rows: {k, v, p?, s?, c?} — pillar, section, cluster key. Keywords with
 * a cluster become locked clusters of the workshop; the rest is clustered as usual.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sheets → keyword rows.
 *
 * @param array $sheets [sheet name => rows of cells].
 * @return array {rows, pillars: name → n, clusters, layout: sheets read as research layout}
 */
function qtb_import_sheets( $sheets ) {
	$out     = array( 'rows' => array(), 'pillars' => array(), 'clusters' => 0, 'layout' => 0 );
	$several = count( $sheets ) > 1;
	$sid     = 0;
	foreach ( $sheets as $name => $rows ) {
		++$sid;
		$generic = (bool) preg_match( '/^(sheet|برگه|کلمات|کلمه|keyword|کیورد|data|input|ورودی|خروجی|export|list|لیست)/iu', trim( (string) $name ) );
		$pillar  = $generic ? '' : qtb_display( (string) $name );
		$lay     = qtb_import_layout( (array) $rows, $sid );
		if ( $lay ) {
			++$out['layout'];
			$res = $lay;
		} else {
			$res = qtb_import_flat( (array) $rows, $sid );
			// A plain list on a named sheet of a multi-sheet file: the sheet is the pillar.
			if ( ! $several ) {
				$pillar = '';
			}
		}
		$cl = array();
		foreach ( $res as $r ) {
			if ( '' === ( $r['p'] ?? '' ) && '' !== $pillar ) {
				$r['p'] = $pillar;
			}
			if ( ! empty( $r['c'] ) ) {
				$cl[ $r['c'] ] = 1;
			}
			if ( ! empty( $r['p'] ) ) {
				$out['pillars'][ $r['p'] ] = ( $out['pillars'][ $r['p'] ] ?? 0 ) + 1;
			}
			$out['rows'][] = array_filter( $r, function ( $x ) {
				return '' !== $x && null !== $x;
			} ) + array( 'v' => 0 );
		}
		$out['clusters'] += count( $cl );
	}
	return $out;
}

/**
 * A numeric volume cell (Persian digits, separators, «1.2K»).
 */
function qtb_import_is_num( $s ) {
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return false;
	}
	if ( is_int( $s ) || is_float( $s ) || is_numeric( $s ) ) {
		return true;
	}
	return (bool) preg_match( '/^[\d۰-۹٠-٩][\d۰-۹٠-٩٬٫,.\s]*\s*[kKmM]?$|^[\d۰-۹]+\s*[-–]\s*[\d۰-۹]+[kK]?$/u', $s );
}

/**
 * Research layout → rows, or null when the sheet is not one.
 */
function qtb_import_layout( $rows, $sid ) {
	$nrow = count( $rows );
	$ncol = 0;
	foreach ( $rows as $r ) {
		$ncol = max( $ncol, count( (array) $r ) );
	}
	if ( $nrow < 2 || $ncol < 3 ) {
		return null;
	}
	$cell = function ( $r, $c ) use ( $rows ) {
		return trim( (string) ( $rows[ $r ][ $c ] ?? '' ) );
	};
	$is_text = function ( $s ) {
		return '' !== $s && ! qtb_import_is_num( $s ) && preg_match( '/\p{L}/u', $s );
	};
	// Keyword columns: text cells, the next column numeric (or empty) wherever the text is.
	$first = array(); // col → first data row.
	for ( $c = 0; $c < $ncol; $c++ ) {
		$pairs = 0;
		$bad   = 0;
		$f     = null;
		for ( $r = 0; $r < $nrow; $r++ ) {
			$t = $cell( $r, $c );
			if ( ! $is_text( $t ) ) {
				continue;
			}
			$n = $cell( $r, $c + 1 );
			if ( qtb_import_is_num( $n ) ) {
				++$pairs;
				if ( null === $f ) {
					$f = $r;
				}
			} elseif ( '' !== $n && null !== $f ) {
				++$bad;
			}
		}
		if ( $pairs >= 1 && $bad <= max( 1, $pairs / 5 ) ) {
			$first[ $c ] = $f;
		}
	}
	if ( count( $first ) < 2 ) {
		return null;
	}
	// A keyword column right after another one is its volume column, not a pair.
	foreach ( array_keys( $first ) as $c ) {
		if ( isset( $first[ $c - 1 ] ) ) {
			unset( $first[ $c ] );
		}
	}
	if ( count( $first ) < 2 ) {
		return null;
	}
	$top = min( $first ); // Rows above hold the section titles.
	// Keyword-only columns inside the layout (a keyword without volume): text, next column empty.
	for ( $c = 0; $c < $ncol; $c++ ) {
		if ( isset( $first[ $c ] ) || isset( $first[ $c - 1 ] ) ) {
			continue;
		}
		$texts = 0;
		$other = 0;
		for ( $r = $top; $r < $nrow; $r++ ) {
			if ( $is_text( $cell( $r, $c ) ) ) {
				++$texts;
				if ( '' !== $cell( $r, $c + 1 ) ) {
					++$other;
				}
			}
		}
		if ( $texts && ! $other ) {
			$first[ $c ] = $top;
		}
	}
	ksort( $first );
	// Separator columns: empty in every row.
	$empty = array();
	for ( $c = 0; $c < $ncol; $c++ ) {
		$e = true;
		for ( $r = 0; $r < $nrow && $e; $r++ ) {
			$e = '' === $cell( $r, $c );
		}
		$empty[ $c ] = $e;
	}
	$out = array();
	foreach ( $first as $c => $f ) {
		// Section: the nearest title above, carried right from where its merged cell starts.
		$section = '';
		for ( $r = min( $f, $top ) - 1; $r >= 0 && '' === $section; $r-- ) {
			for ( $j = $c; $j >= 0; $j-- ) {
				if ( $empty[ $j ] ) {
					break;
				}
				$t = $cell( $r, $j );
				if ( '' !== $t && ! qtb_import_is_num( $t ) ) {
					$section = qtb_display( $t );
					break;
				}
			}
		}
		// Runs of keywords (a blank row starts a new cluster).
		$run = 0;
		$in  = false;
		for ( $r = $f; $r < $nrow; $r++ ) {
			$t = $cell( $r, $c );
			if ( ! $is_text( $t ) ) {
				$in = false;
				continue;
			}
			if ( ! $in ) {
				++$run;
				$in = true;
			}
			$out[] = array(
				'k' => qtb_display( $t ),
				'v' => qtb_parse_volume( $cell( $r, $c + 1 ) ),
				's' => $section,
				'c' => $sid . ':' . $c . ':' . $run,
			);
		}
	}
	return $out ?: null;
}

/**
 * Flat list → rows (keyword, volume and the optional cluster / section / pillar columns).
 */
function qtb_import_flat( $rows, $sid ) {
	$rows = array_values( array_filter( $rows, function ( $r ) {
		return is_array( $r ) && '' !== implode( '', array_map( 'strval', $r ) );
	} ) );
	$cols = array( 'k' => null, 'v' => null, 'c' => null, 's' => null, 'p' => null );
	$pats = array(
		'k' => '/^(کلمه|کلمات|کیورد|keyword|query|عبارت|کوئری)/iu',
		'v' => '/(حجم|جستجو|سرچ|volume|searches|^sv$)/iu',
		'c' => '/^(کلاستر|cluster|خوشه|گروه|صفحه|page)/iu',
		's' => '/^(بخش|سکشن|section)/iu',
		'p' => '/^(پیلار|pillar|موضوع|topic)/iu',
	);
	$start = 0;
	foreach ( array_slice( $rows, 0, 3 ) as $ri => $row ) {
		$hit = false;
		foreach ( $row as $ci => $cellv ) {
			$h = trim( qtb_norm( (string) $cellv ) );
			foreach ( $pats as $key => $re ) {
				if ( null === $cols[ $key ] && '' !== $h && preg_match( $re, $h ) && ! in_array( $ci, $cols, true ) ) {
					$cols[ $key ] = $ci;
					$hit          = true;
					break;
				}
			}
		}
		if ( $hit ) {
			$start = $ri + 1;
		}
		if ( null !== $cols['k'] ) {
			break;
		}
	}
	if ( null === $cols['c'] && null === $cols['s'] && null === $cols['p'] ) {
		return qtb_rows_to_keywords( $rows );
	}
	if ( null === $cols['k'] ) {
		$cols['k'] = 0;
	}
	if ( null === $cols['v'] ) {
		$cols['v'] = $cols['k'] + 1;
	}
	$out = array();
	foreach ( array_slice( $rows, $start ) as $row ) {
		$k = trim( (string) ( $row[ $cols['k'] ] ?? '' ) );
		if ( '' === $k || mb_strlen( $k ) > 160 ) {
			continue;
		}
		$p = null !== $cols['p'] ? qtb_display( (string) ( $row[ $cols['p'] ] ?? '' ) ) : '';
		$s = null !== $cols['s'] ? qtb_display( (string) ( $row[ $cols['s'] ] ?? '' ) ) : '';
		$c = null !== $cols['c'] ? qtb_display( (string) ( $row[ $cols['c'] ] ?? '' ) ) : '';
		$out[] = array(
			'k' => $k,
			'v' => qtb_parse_volume( $row[ $cols['v'] ] ?? 0 ),
			'p' => $p,
			's' => $s,
			'c' => '' !== $c ? $sid . ':' . qtb_key( $p . '|' . $c ) : '',
			'cn' => $c,
		);
	}
	return $out;
}

/**
 * Read an uploaded file (all sheets of an XLSX) into keyword rows.
 *
 * @return array|WP_Error {rows, pillars, clusters, layout}
 */
function qtb_import_file( $path, $name ) {
	$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	if ( 'xlsx' === $ext ) {
		$sheets = qtb_xlsx_read_all( $path );
	} elseif ( in_array( $ext, array( 'csv', 'tsv', 'txt' ), true ) ) {
		$sheets = array( 'Sheet1' => qtb_csv_read( $path ) );
	} else {
		return new WP_Error( 'qtb_file', 'فقط فایل XLSX یا CSV پذیرفته می‌شود.' );
	}
	if ( is_wp_error( $sheets ) ) {
		return $sheets;
	}
	return qtb_import_sheets( $sheets );
}

/**
 * One line about what was read.
 */
function qtb_import_summary( $imp ) {
	$msg = sprintf( '%s کلمه خوانده شد', qtb_num( count( $imp['rows'] ) ) );
	if ( $imp['clusters'] ) {
		$msg .= sprintf( '؛ %s کلاستر آماده شما', qtb_num( $imp['clusters'] ) );
	}
	if ( $imp['pillars'] ) {
		$msg .= sprintf( ' در %s پیلار (%s)', qtb_num( count( $imp['pillars'] ) ), implode( '، ', array_slice( array_keys( $imp['pillars'] ), 0, 6 ) ) );
	}
	return $msg . '.' . ( $imp['clusters'] ? ' کلاسترهای شما قفل می‌مانند و فقط بقیه کلمات خوشه‌بندی می‌شوند.' : '' );
}
