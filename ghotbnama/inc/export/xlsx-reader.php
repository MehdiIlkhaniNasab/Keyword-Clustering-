<?php
/**
 * Read an uploaded keyword file: XLSX (first sheet, or a sheet by name) or CSV
 * (comma / semicolon / tab, UTF-8 or UTF-16 as exported by Keyword Planner).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows of a keyword file.
 *
 * @return array|WP_Error rows of cells
 */
function qtb_read_keyword_file( $path, $name, $sheet = '' ) {
	$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
	if ( 'xlsx' === $ext ) {
		return qtb_xlsx_read( $path, $sheet );
	}
	if ( in_array( $ext, array( 'csv', 'tsv', 'txt' ), true ) ) {
		return qtb_csv_read( $path );
	}
	return new WP_Error( 'qtb_file', 'فقط فایل XLSX یا CSV پذیرفته می‌شود.' );
}

/**
 * CSV → rows.
 */
function qtb_csv_read( $path ) {
	$raw = (string) file_get_contents( $path );
	if ( "\xFF\xFE" === substr( $raw, 0, 2 ) || "\xFE\xFF" === substr( $raw, 0, 2 ) ) {
		$raw = mb_convert_encoding( $raw, 'UTF-8', 'UTF-16' );
	}
	$raw   = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
	$lines = preg_split( '/\r\n|\r|\n/', $raw );
	$first = (string) ( $lines[0] ?? '' );
	$sep   = substr_count( $first, "\t" ) > 0 ? "\t" : ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',' );
	$rows  = array();
	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$rows[] = str_getcsv( $line, $sep );
	}
	return $rows;
}

/**
 * XLSX → rows (values only) of one sheet: by name, else the first.
 */
function qtb_xlsx_read( $path, $sheet = '' ) {
	$all = qtb_xlsx_read_all( $path, $sheet );
	if ( is_wp_error( $all ) ) {
		return $all;
	}
	return $all ? reset( $all ) : new WP_Error( 'qtb_xlsx', 'شیت داخل فایل اکسل پیدا نشد.' );
}

/**
 * XLSX → [sheet name => rows] of every sheet (or only the one named $only).
 *
 * @return array|WP_Error
 */
function qtb_xlsx_read_all( $path, $only = '' ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'qtb_zip', 'افزونه ZipArchive در PHP فعال نیست؛ فایل CSV بارگذاری کنید.' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'qtb_xlsx', 'فایل اکسل باز نشد.' );
	}
	$strings = array();
	$ss      = $zip->getFromName( 'xl/sharedStrings.xml' );
	if ( $ss ) {
		$xml = simplexml_load_string( $ss );
		if ( $xml ) {
			foreach ( $xml->si as $si ) {
				if ( isset( $si->t ) ) {
					$strings[] = (string) $si->t;
				} else {
					$t = '';
					foreach ( $si->r as $r ) {
						$t .= (string) $r->t;
					}
					$strings[] = $t;
				}
			}
		}
	}
	// Sheets in workbook order → their files.
	$files = array();
	$wb    = simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) );
	$rels  = simplexml_load_string( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
	if ( $wb && $rels ) {
		$targets = array();
		foreach ( $rels->Relationship as $r ) {
			$targets[ (string) $r['Id'] ] = 'xl/' . ltrim( preg_replace( '#^/?xl/#', '', (string) $r['Target'] ), '/' );
		}
		foreach ( $wb->sheets->sheet as $s ) {
			$attrs = $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
			$name  = (string) $s['name'];
			if ( 'hidden' === (string) $s['state'] || 'veryHidden' === (string) $s['state'] ) {
				continue;
			}
			if ( isset( $targets[ (string) $attrs['id'] ] ) ) {
				$files[ $name ] = $targets[ (string) $attrs['id'] ];
			}
		}
	}
	if ( ! $files ) {
		$files = array( 'Sheet1' => 'xl/worksheets/sheet1.xml' );
	}
	if ( '' !== $only ) {
		foreach ( $files as $name => $f ) {
			if ( qtb_key( $name ) === qtb_key( $only ) ) {
				$files = array( $name => $f );
				break;
			}
		}
	}
	$out = array();
	foreach ( $files as $name => $target ) {
		$sx = $zip->getFromName( $target );
		$xml = $sx ? simplexml_load_string( $sx ) : null;
		if ( ! $xml ) {
			continue;
		}
		$out[ $name ] = qtb_xlsx_sheet_rows( $xml, $strings );
	}
	$zip->close();
	return $out ?: new WP_Error( 'qtb_xlsx', 'شیت اکسل قابل خواندن نیست.' );
}

/**
 * Rows of one parsed sheet. Rows keep their position (empty rows stay empty arrays),
 * so blank lines between clusters are not lost.
 */
function qtb_xlsx_sheet_rows( $xml, $strings ) {
	$rows = array();
	foreach ( $xml->sheetData->row as $row ) {
		$rn    = (int) $row['r'];
		$cells = array();
		foreach ( $row->c as $c ) {
			$ref = (string) $c['r'];
			$col = 0;
			if ( preg_match( '/^([A-Z]+)/', $ref, $m ) ) {
				foreach ( str_split( $m[1] ) as $ch ) {
					$col = $col * 26 + ( ord( $ch ) - 64 );
				}
				--$col;
			} else {
				$col = count( $cells );
			}
			$t = (string) $c['t'];
			if ( 's' === $t ) {
				$v = $strings[ (int) $c->v ] ?? '';
			} elseif ( 'inlineStr' === $t ) {
				$v = (string) $c->is->t;
			} else {
				$v = (string) $c->v;
				if ( 'b' !== $t && is_numeric( $v ) ) {
					$v = $v + 0;
				}
			}
			if ( '' !== trim( (string) $v ) ) {
				$cells[ $col ] = $v;
			}
		}
		$full = array();
		if ( $cells ) {
			for ( $i = 0; $i <= max( array_keys( $cells ) ); $i++ ) {
				$full[] = $cells[ $i ] ?? '';
			}
		}
		if ( $rn > 0 ) {
			while ( count( $rows ) < $rn - 1 ) {
				$rows[] = array();
			}
		}
		$rows[] = $full;
	}
	// A title merged across columns (a section over its clusters) is copied to every column it covers.
	if ( isset( $xml->mergeCells ) ) {
		foreach ( $xml->mergeCells->mergeCell as $mc ) {
			if ( ! preg_match( '/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', (string) $mc['ref'], $m ) ) {
				continue;
			}
			$c1 = qtb_xlsx_col_index( $m[1] );
			$c2 = qtb_xlsx_col_index( $m[3] );
			$r1 = (int) $m[2] - 1;
			$r2 = (int) $m[4] - 1;
			$v  = $rows[ $r1 ][ $c1 ] ?? '';
			if ( '' === trim( (string) $v ) || $c2 <= $c1 || $r2 - $r1 > 2 ) {
				continue;
			}
			for ( $c = $c1 + 1; $c <= $c2; $c++ ) {
				while ( count( $rows[ $r1 ] ) <= $c ) {
					$rows[ $r1 ][] = '';
				}
				if ( '' === trim( (string) $rows[ $r1 ][ $c ] ) ) {
					$rows[ $r1 ][ $c ] = $v;
				}
			}
		}
	}
	// Trailing empty rows (formatting only).
	while ( $rows && ! end( $rows ) ) {
		array_pop( $rows );
	}
	return $rows;
}

/**
 * «BZ» → 0-based column index.
 */
function qtb_xlsx_col_index( $letters ) {
	$col = 0;
	foreach ( str_split( $letters ) as $ch ) {
		$col = $col * 26 + ( ord( $ch ) - 64 );
	}
	return $col - 1;
}
