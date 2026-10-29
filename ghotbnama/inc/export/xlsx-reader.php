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
 * XLSX → rows (values only).
 */
function qtb_xlsx_read( $path, $sheet = '' ) {
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
	// Sheet file: by name, else the first.
	$target = 'xl/worksheets/sheet1.xml';
	$wb     = simplexml_load_string( (string) $zip->getFromName( 'xl/workbook.xml' ) );
	$rels   = simplexml_load_string( (string) $zip->getFromName( 'xl/_rels/workbook.xml.rels' ) );
	if ( $wb && $rels ) {
		$rid = '';
		foreach ( $wb->sheets->sheet as $s ) {
			$attrs = $s->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
			if ( '' === $rid || ( '' !== $sheet && qtb_key( (string) $s['name'] ) === qtb_key( $sheet ) ) ) {
				$rid = (string) $attrs['id'];
				if ( '' !== $sheet && qtb_key( (string) $s['name'] ) === qtb_key( $sheet ) ) {
					break;
				}
			}
		}
		foreach ( $rels->Relationship as $r ) {
			if ( (string) $r['Id'] === $rid ) {
				$target = 'xl/' . ltrim( preg_replace( '#^/?xl/#', '', (string) $r['Target'] ), '/' );
			}
		}
	}
	$sx = $zip->getFromName( $target );
	$zip->close();
	if ( ! $sx ) {
		return new WP_Error( 'qtb_xlsx', 'شیت داخل فایل اکسل پیدا نشد.' );
	}
	$xml = simplexml_load_string( $sx );
	if ( ! $xml ) {
		return new WP_Error( 'qtb_xlsx', 'شیت اکسل قابل خواندن نیست.' );
	}
	$rows = array();
	foreach ( $xml->sheetData->row as $row ) {
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
			} elseif ( 'b' === $t ) {
				$v = (string) $c->v;
			} else {
				$v = (string) $c->v;
				if ( is_numeric( $v ) ) {
					$v = $v + 0;
				}
			}
			$cells[ $col ] = $v;
		}
		if ( $cells ) {
			$full = array();
			for ( $i = 0; $i <= max( array_keys( $cells ) ); $i++ ) {
				$full[] = $cells[ $i ] ?? '';
			}
			$rows[] = $full;
		}
	}
	return $rows;
}
