<?php
/**
 * Google Sheets: read the keyword tab, write the output workbooks.
 *
 * Input tab (default «کلمات کلیدی»): column 1 = keyword, column 2 = monthly
 * search volume. A header row is optional; columns are also found by header
 * text («کلمه کلیدی» / «Keyword», «حجم» / «Search Volume» / «Avg. monthly searches»).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Spreadsheet id from a full Google Sheets URL (or the id itself).
 */
function qtb_sheet_id( $value ) {
	$value = trim( (string) $value );
	if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $value, $m ) ) {
		return $m[1];
	}
	return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value );
}

/**
 * Quoted A1 range for a tab name.
 */
function qtb_sheet_range( $tab, $cells = '' ) {
	return "'" . str_replace( "'", "''", $tab ) . "'" . ( $cells ? '!' . $cells : '' );
}

/**
 * Spreadsheet metadata: title and tabs [{id, title, index}].
 */
function qtb_sheet_meta( $sheet_id ) {
	$data = qtb_google_request( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . '?fields=properties.title,sheets.properties' );
	if ( is_wp_error( $data ) ) {
		$status = (int) ( $data->get_error_data()['status'] ?? 0 );
		return 404 === $status ? new WP_Error( 'qtb_sheet_404', 'گوگل شیت پیدا نشد؛ لینک شیت را بررسی کنید.' ) : $data;
	}
	$tabs = array();
	foreach ( (array) ( $data['sheets'] ?? array() ) as $s ) {
		$p      = (array) ( $s['properties'] ?? array() );
		$tabs[] = array( 'id' => (int) ( $p['sheetId'] ?? 0 ), 'title' => (string) ( $p['title'] ?? '' ), 'index' => (int) ( $p['index'] ?? 0 ) );
	}
	return array( 'title' => (string) ( $data['properties']['title'] ?? '' ), 'tabs' => $tabs );
}

/**
 * Raw values of a tab.
 */
function qtb_sheet_values( $sheet_id, $tab ) {
	$url  = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . '/values/' . rawurlencode( qtb_sheet_range( $tab ) ) . '?valueRenderOption=UNFORMATTED_VALUE&majorDimension=ROWS';
	$data = qtb_google_request( 'GET', $url );
	if ( is_wp_error( $data ) ) {
		$status = (int) ( $data->get_error_data()['status'] ?? 0 );
		if ( 400 === $status || 404 === $status ) {
			return new WP_Error( 'qtb_sheet_tab', sprintf( 'تب «%s» در گوگل شیت پیدا نشد. نام تب ورودی را در تنظیمات پروژه بررسی کنید.', $tab ) );
		}
		return $data;
	}
	return (array) ( $data['values'] ?? array() );
}

/**
 * Batch update (formatting, tabs, cells).
 */
function qtb_sheet_batch( $sheet_id, $requests ) {
	return qtb_google_request( 'POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $sheet_id ) . ':batchUpdate', array( 'requests' => array_values( $requests ) ), 180 );
}

/**
 * Parse a search volume cell: 1,200 · ۱٬۲۰۰ · 1.2K · «1K – 10K» (Keyword Planner ranges: the middle).
 */
function qtb_parse_volume( $cell ) {
	if ( is_int( $cell ) || is_float( $cell ) ) {
		return max( 0, (int) round( $cell ) );
	}
	$t = qtb_norm( (string) $cell );
	$t = str_replace( array( '٬', ',', '٫' ), array( '', '', '.' ), $t );
	if ( ! preg_match_all( '/(\d+(?:\.\d+)?)\s*(هزار|میلیون|[km])?/iu', $t, $m, PREG_SET_ORDER ) ) {
		return 0;
	}
	$vals = array();
	foreach ( array_slice( $m, 0, 2 ) as $one ) {
		$n   = (float) $one[1];
		$mul = strtolower( (string) ( $one[2] ?? '' ) );
		if ( in_array( $mul, array( 'k', 'هزار' ), true ) ) {
			$n *= 1000;
		} elseif ( in_array( $mul, array( 'm', 'میلیون' ), true ) ) {
			$n *= 1000000;
		}
		$vals[] = $n;
	}
	if ( 2 === count( $vals ) && preg_match( '/[–\-—~]|تا/u', $t ) && $vals[1] > $vals[0] ) {
		return (int) round( sqrt( $vals[0] * $vals[1] ) );
	}
	return (int) round( $vals[0] );
}

/**
 * Rows (arrays of cells) → keywords [{k, v}]. Finds the columns by header,
 * otherwise uses the first text column and the first numeric column.
 */
function qtb_rows_to_keywords( $rows ) {
	$rows = array_values( array_filter( (array) $rows, function ( $r ) {
		return is_array( $r ) && implode( '', array_map( 'strval', $r ) ) !== '';
	} ) );
	if ( ! $rows ) {
		return array();
	}
	$kcol  = null;
	$vcol  = null;
	$start = 0;
	foreach ( array_slice( $rows, 0, 3 ) as $ri => $row ) {
		foreach ( $row as $ci => $cell ) {
			$h = qtb_norm( (string) $cell );
			if ( null === $kcol && preg_match( '/^(کلمه|کلمات|کیورد|keyword|query|عبارت|کوئری)/u', $h ) ) {
				$kcol  = $ci;
				$start = $ri + 1;
			}
			if ( null === $vcol && preg_match( '/(حجم|جستجو|سرچ|volume|searches|sv$|^sv)/u', $h ) ) {
				$vcol  = $ci;
				$start = max( $start, $ri + 1 );
			}
		}
		if ( null !== $kcol && null !== $vcol ) {
			break;
		}
	}
	if ( null === $kcol ) {
		$kcol = 0;
	}
	if ( null === $vcol ) {
		$vcol = $kcol + 1;
	}
	$out = array();
	foreach ( array_slice( $rows, $start ) as $row ) {
		$k = trim( (string) ( $row[ $kcol ] ?? '' ) );
		if ( '' === $k || mb_strlen( $k ) > 160 ) {
			continue;
		}
		$out[] = array( 'k' => $k, 'v' => qtb_parse_volume( $row[ $vcol ] ?? 0 ) );
	}
	// A header row that was not recognised: first row text with no numeric volume.
	if ( $out && 0 === $start && 0 === $out[0]['v'] && ! is_numeric( $rows[0][ $vcol ] ?? '' ) && preg_match( '/[a-z]|کلمه|حجم/iu', $out[0]['k'] ) ) {
		array_shift( $out );
	}
	return $out;
}

/**
 * Read the project's input keywords from Google Sheets.
 *
 * @return array|WP_Error [{k, v}]
 */
function qtb_sheet_read_keywords( $project ) {
	$id = qtb_sheet_id( $project['cfg']['sheet_url'] );
	if ( '' === $id ) {
		return new WP_Error( 'qtb_sheet_none', 'لینک گوگل شیت پروژه وارد نشده است.' );
	}
	$tab  = (string) ( $project['cfg']['input_tab'] ?: 'کلمات کلیدی' );
	$rows = qtb_sheet_values( $id, $tab );
	if ( is_wp_error( $rows ) ) {
		// Tab missing: fall back to the first tab when the sheet has a single one.
		if ( 'qtb_sheet_tab' === $rows->get_error_code() ) {
			$meta = qtb_sheet_meta( $id );
			if ( ! is_wp_error( $meta ) && 1 === count( $meta['tabs'] ) ) {
				$rows = qtb_sheet_values( $id, $meta['tabs'][0]['title'] );
			}
		}
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
	}
	$kws = qtb_rows_to_keywords( $rows );
	return $kws ?: new WP_Error( 'qtb_sheet_empty', sprintf( 'در تب «%s» کلمه‌ای پیدا نشد (ستون اول کلمه کلیدی، ستون دوم حجم جستجو).', $tab ) );
}
