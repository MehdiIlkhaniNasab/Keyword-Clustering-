<?php
/**
 * Write the workbook models into Google Sheets with the same formatting
 * (colors, merges, widths, frozen rows, filters, dropdowns, checkboxes, RTL).
 *
 * Resumable: all batchUpdate requests are prepared up front (sheet ids are
 * chosen by us, so content requests do not wait for Google's replies) and sent
 * a few batches per call. Tabs written by Ghotbnama are remembered and replaced
 * on the next write; tabs the team created are never deleted (a new tab with a
 * «(قطب‌نما)» suffix is written instead).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Output spreadsheet of a project.
 */
function qtb_gsheet_target( $project ) {
	return qtb_sheet_id( $project['cfg']['output_url'] ?: $project['cfg']['sheet_url'] );
}

/**
 * Hex → Google color.
 */
function qtb_gcolor( $hex ) {
	$hex = ltrim( (string) $hex, '#' );
	return array(
		'red'   => round( hexdec( substr( $hex, 0, 2 ) ) / 255, 4 ),
		'green' => round( hexdec( substr( $hex, 2, 2 ) ) / 255, 4 ),
		'blue'  => round( hexdec( substr( $hex, 4, 2 ) ) / 255, 4 ),
	);
}

/**
 * Model cell → CellData.
 */
function qtb_gcell( $cell ) {
	if ( null === $cell ) {
		return new stdClass();
	}
	$v = is_array( $cell ) ? ( $cell['v'] ?? null ) : $cell;
	$s = is_array( $cell ) ? (array) ( $cell['s'] ?? array() ) : array();
	$d = array();
	if ( is_array( $cell ) && ! empty( $cell['link'] ) && preg_match( '#^https?://#', $cell['link'] ) ) {
		$d['userEnteredValue'] = array( 'formulaValue' => '=HYPERLINK("' . str_replace( '"', '%22', $cell['link'] ) . '","' . str_replace( '"', '""', (string) $v ) . '")' );
	} elseif ( is_bool( $v ) ) {
		$d['userEnteredValue'] = array( 'boolValue' => $v );
	} elseif ( is_int( $v ) || is_float( $v ) ) {
		$d['userEnteredValue'] = array( 'numberValue' => $v );
	} elseif ( null !== $v && '' !== $v ) {
		$d['userEnteredValue'] = array( 'stringValue' => (string) $v );
	}
	$f  = array( 'textFormat' => array( 'fontFamily' => 'Vazirmatn', 'fontSize' => (int) round( $s['size'] ?? 10 ) ) );
	if ( ! empty( $s['b'] ) ) {
		$f['textFormat']['bold'] = true;
	}
	if ( ! empty( $s['i'] ) ) {
		$f['textFormat']['italic'] = true;
	}
	if ( ! empty( $s['color'] ) ) {
		$f['textFormat']['foregroundColor'] = qtb_gcolor( $s['color'] );
	}
	if ( ! empty( $s['bg'] ) ) {
		$f['backgroundColor'] = qtb_gcolor( $s['bg'] );
	}
	if ( ! empty( $s['h'] ) ) {
		$f['horizontalAlignment'] = strtoupper( $s['h'] );
	}
	$f['verticalAlignment'] = 'top' === ( $s['v'] ?? '' ) ? 'TOP' : 'MIDDLE';
	if ( ! empty( $s['wrap'] ) ) {
		$f['wrapStrategy'] = 'WRAP';
	}
	if ( ! empty( $s['fmt'] ) ) {
		$f['numberFormat'] = '0%' === $s['fmt'] ? array( 'type' => 'PERCENT', 'pattern' => '0%' ) : array( 'type' => 'NUMBER', 'pattern' => $s['fmt'] );
	}
	if ( ! empty( $s['border'] ) ) {
		$b            = array( 'style' => 'SOLID', 'color' => qtb_gcolor( is_string( $s['border'] ) ? $s['border'] : 'D9D9D9' ) );
		$f['borders'] = array( 'top' => $b, 'bottom' => $b, 'left' => $b, 'right' => $b );
	}
	$d['userEnteredFormat'] = $f;
	return $d;
}

/**
 * A1 range → GridRange.
 */
function qtb_grange( $sheet_id, $a1 ) {
	$parse = function ( $ref ) {
		preg_match( '/^([A-Z]+)(\d+)$/', $ref, $m );
		$c = 0;
		foreach ( str_split( $m[1] ) as $ch ) {
			$c = $c * 26 + ( ord( $ch ) - 64 );
		}
		return array( (int) $m[2] - 1, $c - 1 );
	};
	$parts = explode( ':', $a1 );
	list( $r1, $c1 ) = $parse( $parts[0] );
	list( $r2, $c2 ) = $parse( $parts[1] ?? $parts[0] );
	return array( 'sheetId' => $sheet_id, 'startRowIndex' => $r1, 'endRowIndex' => $r2 + 1, 'startColumnIndex' => $c1, 'endColumnIndex' => $c2 + 1 );
}

/**
 * Content requests of one sheet.
 */
function qtb_gsheet_sheet_requests( $sh, $sid ) {
	$reqs  = array();
	$cells = (array) $sh['cells'];
	ksort( $cells );
	$maxc = 1;
	foreach ( $cells as $row ) {
		$maxc = max( $maxc, $row ? max( array_keys( $row ) ) : 1 );
	}
	$maxr = $cells ? max( array_keys( $cells ) ) : 1;
	// Rows in chunks (≈ 6000 cells per request).
	$per = max( 20, (int) floor( 6000 / max( 1, $maxc ) ) );
	for ( $r0 = 1; $r0 <= $maxr; $r0 += $per ) {
		$rows = array();
		for ( $r = $r0; $r < $r0 + $per && $r <= $maxr; $r++ ) {
			$vals = array();
			for ( $c = 1; $c <= $maxc; $c++ ) {
				$vals[] = isset( $cells[ $r ][ $c ] ) ? qtb_gcell( $cells[ $r ][ $c ] ) : new stdClass();
			}
			$rows[] = array( 'values' => $vals );
		}
		$reqs[] = array( 'updateCells' => array( 'start' => array( 'sheetId' => $sid, 'rowIndex' => $r0 - 1, 'columnIndex' => 0 ), 'rows' => $rows, 'fields' => 'userEnteredValue,userEnteredFormat' ) );
	}
	foreach ( (array) ( $sh['merges'] ?? array() ) as $m ) {
		$reqs[] = array( 'mergeCells' => array( 'range' => qtb_grange( $sid, $m ), 'mergeType' => 'MERGE_ALL' ) );
	}
	foreach ( (array) ( $sh['cols'] ?? array() ) as $c => $w ) {
		$reqs[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => $c - 1, 'endIndex' => $c ), 'properties' => array( 'pixelSize' => (int) round( $w * 7 + 5 ) ), 'fields' => 'pixelSize' ) );
	}
	foreach ( (array) ( $sh['heights'] ?? array() ) as $r => $h ) {
		$reqs[] = array( 'updateDimensionProperties' => array( 'range' => array( 'sheetId' => $sid, 'dimension' => 'ROWS', 'startIndex' => $r - 1, 'endIndex' => $r ), 'properties' => array( 'pixelSize' => (int) round( $h * 1.34 ) ), 'fields' => 'pixelSize' ) );
	}
	foreach ( (array) ( $sh['lists'] ?? array() ) as $dv ) {
		$reqs[] = array( 'setDataValidation' => array( 'range' => qtb_grange( $sid, $dv['range'] ), 'rule' => array( 'condition' => array( 'type' => 'ONE_OF_LIST', 'values' => array_map( function ( $v ) {
			return array( 'userEnteredValue' => $v );
		}, $dv['values'] ) ), 'showCustomUi' => true, 'strict' => false ) ) );
	}
	// Boolean columns → checkboxes.
	foreach ( $cells as $r => $row ) {
		foreach ( $row as $c => $cell ) {
			if ( is_array( $cell ) && is_bool( $cell['v'] ?? null ) ) {
				$reqs[] = array( 'setDataValidation' => array( 'range' => array( 'sheetId' => $sid, 'startRowIndex' => $r - 1, 'endRowIndex' => $maxr, 'startColumnIndex' => $c - 1, 'endColumnIndex' => $c ), 'rule' => array( 'condition' => array( 'type' => 'BOOLEAN' ) ) ) );
				break 2;
			}
		}
	}
	if ( ! empty( $sh['filter'] ) ) {
		$reqs[] = array( 'setBasicFilter' => array( 'filter' => array( 'range' => qtb_grange( $sid, qtb_filter_range( $sh ) ) ) ) );
	}
	return $reqs;
}

/**
 * Prepare the write job.
 *
 * @param string[] $kinds research|calendar
 */
function qtb_gsheet_prepare( $project, $kinds = array( 'research', 'calendar' ) ) {
	$sheet_id = qtb_gsheet_target( $project );
	if ( '' === $sheet_id ) {
		return new WP_Error( 'qtb_gs_none', 'لینک گوگل شیت خروجی (یا ورودی) در تنظیمات پروژه وارد نشده است.' );
	}
	$meta = qtb_sheet_meta( $sheet_id );
	if ( is_wp_error( $meta ) ) {
		return $meta;
	}
	$state   = qtb_data_get( $project['id'], 'gsheet', array() );
	$managed = ( $state['sheet_id'] ?? '' ) === $sheet_id ? (array) ( $state['tabs'] ?? array() ) : array();
	$existing = array();
	$ids      = array();
	foreach ( $meta['tabs'] as $t ) {
		$existing[ $t['title'] ] = $t['id'];
		$ids[ $t['id'] ]         = true;
	}
	$input_tab = (string) $project['cfg']['input_tab'];
	$sheets    = array();
	foreach ( $kinds as $kind ) {
		$book   = 'calendar' === $kind ? qtb_book_calendar( $project ) : qtb_book_research( $project );
		$sheets = array_merge( $sheets, $book['sheets'] );
	}
	$used    = array();
	$adds    = array();
	$finals  = array();
	$deletes = array();
	$content = array();
	$titles  = array();
	$next    = 910000000 + wp_rand( 0, 50000000 );
	foreach ( $sheets as $sh ) {
		$title = qtb_sheet_name( $sh['name'], $used );
		if ( isset( $existing[ $title ] ) ) {
			if ( in_array( $title, $managed, true ) && $title !== $input_tab ) {
				$deletes[ $existing[ $title ] ] = true;
			} else {
				$title = qtb_sheet_name( mb_substr( $sh['name'], 0, 22 ) . ' (قطب‌نما)', $used );
			}
		}
		while ( isset( $ids[ $next ] ) ) {
			++$next;
		}
		$sid          = $next++;
		$ids[ $sid ]  = true;
		$cells        = (array) $sh['cells'];
		$maxr         = $cells ? max( array_keys( $cells ) ) : 1;
		$maxc         = 1;
		foreach ( $cells as $row ) {
			$maxc = max( $maxc, $row ? max( array_keys( $row ) ) : 1 );
		}
		// The grid must also hold every column / row that gets a width, height, merge or dropdown.
		if ( ! empty( $sh['cols'] ) ) {
			$maxc = max( $maxc, max( array_keys( $sh['cols'] ) ) );
		}
		if ( ! empty( $sh['heights'] ) ) {
			$maxr = max( $maxr, max( array_keys( $sh['heights'] ) ) );
		}
		foreach ( array_merge( (array) ( $sh['merges'] ?? array() ), array_column( (array) ( $sh['lists'] ?? array() ), 'range' ) ) as $a1 ) {
			$g    = qtb_grange( 0, $a1 );
			$maxc = max( $maxc, $g['endColumnIndex'] );
			$maxr = max( $maxr, $g['endRowIndex'] );
		}
		$frow = 0;
		$fcol = 0;
		if ( ! empty( $sh['freeze'] ) && preg_match( '/^([A-Z]+)(\d+)$/', $sh['freeze'], $fm ) ) {
			$frow = (int) $fm[2] - 1;
			foreach ( str_split( $fm[1] ) as $ch ) {
				$fcol = $fcol * 26 + ( ord( $ch ) - 64 );
			}
			--$fcol;
		}
		// Google refuses a merge that crosses the frozen edge: unfreeze that direction.
		foreach ( (array) ( $sh['merges'] ?? array() ) as $m ) {
			$g = qtb_grange( 0, $m );
			if ( $fcol && $g['startColumnIndex'] < $fcol && $g['endColumnIndex'] > $fcol ) {
				$fcol = 0;
			}
			if ( $frow && $g['startRowIndex'] < $frow && $g['endRowIndex'] > $frow ) {
				$frow = 0;
			}
		}
		$props = array(
			'sheetId'        => $sid,
			'title'          => '~qtb~' . $sid,
			'rightToLeft'    => true,
			'gridProperties' => array( 'rowCount' => max( 50, $maxr + 20 ), 'columnCount' => max( 10, $maxc + 2 ), 'frozenRowCount' => $frow, 'frozenColumnCount' => $fcol, 'hideGridlines' => true ),
		);
		if ( ! empty( $sh['tab'] ) ) {
			$props['tabColor'] = qtb_gcolor( $sh['tab'] );
		}
		$adds[]   = array( 'addSheet' => array( 'properties' => $props ) );
		$finals[] = array( 'updateSheetProperties' => array( 'properties' => array( 'sheetId' => $sid, 'title' => $title ), 'fields' => 'title' ) );
		$content  = array_merge( $content, qtb_gsheet_sheet_requests( $sh, $sid ) );
		$titles[] = $title;
	}
	// Batches: add (temp names) → delete old managed + rename → content in chunks.
	$batches   = array( $adds );
	$second    = array();
	foreach ( array_keys( $deletes ) as $id ) {
		$second[] = array( 'deleteSheet' => array( 'sheetId' => $id ) );
	}
	$batches[] = array_merge( $second, $finals );
	$chunk     = array();
	$weight    = 0;
	foreach ( $content as $rq ) {
		$w = isset( $rq['updateCells'] ) ? count( $rq['updateCells']['rows'] ) * count( $rq['updateCells']['rows'][0]['values'] ?? array() ) : 5;
		if ( $chunk && $weight + $w > 30000 ) {
			$batches[] = $chunk;
			$chunk     = array();
			$weight    = 0;
		}
		$chunk[] = $rq;
		$weight += $w;
	}
	if ( $chunk ) {
		$batches[] = $chunk;
	}
	$job = array( 'sheet_id' => $sheet_id, 'batches' => $batches, 'pos' => 0, 'tabs' => $titles, 'started' => qtb_now(), 'errors' => array() );
	qtb_data_put( $project['id'], 'gsheet_job', $job );
	return $job;
}

/**
 * Send prepared batches until done or out of time.
 *
 * @return array|WP_Error {done, pct, tabs, url}
 */
function qtb_gsheet_tick( $project, $deadline ) {
	$job = qtb_data_get( $project['id'], 'gsheet_job', array() );
	if ( ! $job || empty( $job['batches'] ) ) {
		return new WP_Error( 'qtb_gs_job', 'کار نوشتن در گوگل شیت آماده نیست.' );
	}
	$n = count( $job['batches'] );
	while ( $job['pos'] < $n ) {
		$batch = $job['batches'][ $job['pos'] ];
		$res   = qtb_sheet_batch( $job['sheet_id'], $batch );
		if ( is_wp_error( $res ) ) {
			// Tabs could not be created / renamed: nothing else can be written.
			if ( $job['pos'] < 2 || 'qtb_google_http' !== $res->get_error_code() ) {
				return $res;
			}
			// One bad formatting request must not leave the tabs empty: write the
			// values and widths of this batch without merges / validations / filters.
			$plain = array_values( array_filter( $batch, function ( $rq ) {
				return isset( $rq['updateCells'] ) || isset( $rq['updateDimensionProperties'] );
			} ) );
			$retry = $plain ? qtb_sheet_batch( $job['sheet_id'], $plain ) : $res;
			$job['errors'][] = mb_substr( $res->get_error_message(), 0, 200 ) . ( is_wp_error( $retry ) ? '' : ' (محتوا بدون بخشی از قالب‌بندی نوشته شد)' );
		}
		$job['pos']++;
		if ( 2 === $job['pos'] ) {
			// From now on these tabs are ours (replaced on the next write).
			qtb_data_put( $project['id'], 'gsheet', array( 'sheet_id' => $job['sheet_id'], 'tabs' => $job['tabs'], 'at' => qtb_now() ) );
		}
		qtb_data_put( $project['id'], 'gsheet_job', $job );
		if ( microtime( true ) >= $deadline ) {
			break;
		}
	}
	$done = $job['pos'] >= $n;
	if ( $done ) {
		qtb_data_put( $project['id'], 'gsheet', array( 'sheet_id' => $job['sheet_id'], 'tabs' => $job['tabs'], 'at' => qtb_now() ) );
		qtb_data_delete( $project['id'], array( 'gsheet_job' ) );
	}
	return array(
		'errors' => (array) ( $job['errors'] ?? array() ),
		'done' => $done,
		'pct'  => (int) round( 100 * $job['pos'] / max( 1, $n ) ),
		'tabs' => count( $job['tabs'] ),
		'url'  => 'https://docs.google.com/spreadsheets/d/' . $job['sheet_id'] . '/edit',
	);
}

/**
 * Write everything (used by the pipeline step; resumable through the job).
 *
 * @return array|WP_Error
 */
function qtb_gsheet_write( $project, $deadline = null ) {
	$deadline = $deadline ?: microtime( true ) + 25;
	$job      = qtb_data_get( $project['id'], 'gsheet_job', array() );
	if ( ! $job ) {
		$job = qtb_gsheet_prepare( $project );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
	}
	return qtb_gsheet_tick( $project, $deadline );
}
