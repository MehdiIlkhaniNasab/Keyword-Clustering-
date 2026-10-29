<?php
/**
 * REST API of the app (namespace manzoomeh/v1). Cookie auth + wp_rest nonce;
 * every route needs the tool capability.
 *
 *   POST run/{id}            start a run {source: sheet|upload, from?} and work one slice
 *   POST tick/{id}           continue the run
 *   GET  status/{id}         job status
 *   POST upload/{id}         keyword file (xlsx / csv) → stored as input
 *   POST edit/{id}           manual edit of the result {op, …}
 *   POST calendar/{id}       rebuild the content calendar
 *   POST gsheet/{id}         write to Google Sheets (repeat until done)
 *   GET  download/{id}/{kind} XLSX (kind: research|calendar), needs ?_wpnonce=
 *   POST test/{what}         settings tests (ai|serp|google)
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$ns   = 'manzoomeh/v1';
	$perm = function () {
		return mzm_can();
	};
	$id   = array( 'id' => array( 'validate_callback' => function ( $v ) {
		return is_numeric( $v );
	} ) );
	register_rest_route( $ns, '/run/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_run', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/tick/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_tick', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/status/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => 'mzm_rest_status', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/upload/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_upload', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/edit/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_edit', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/calendar/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_calendar', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/gsheet/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_gsheet', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/download/(?P<id>\d+)/(?P<kind>research|calendar|csv)', array( 'methods' => 'GET', 'callback' => 'mzm_rest_download', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/test/(?P<what>ai|serp|google)', array( 'methods' => 'POST', 'callback' => 'mzm_rest_test', 'permission_callback' => $perm ) );
} );

/**
 * Project of a request or error.
 */
function mzm_rest_project( $req ) {
	// The URL id, never a body field of the same name (edits send cluster ids as «id»).
	$p = mzm_project( (int) ( $req->get_url_params()['id'] ?? 0 ) );
	return $p ?: new WP_Error( 'mzm_404', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
}

/**
 * Start a run.
 */
function mzm_rest_run( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$source = 'upload' === $req['source'] ? 'upload' : 'sheet';
	if ( 'sheet' === $source && '' === mzm_sheet_id( $p['cfg']['sheet_url'] ) ) {
		$has = (bool) mzm_data_get( $p['id'], 'input', array() );
		if ( ! $has ) {
			return new WP_Error( 'mzm_no_sheet', 'لینک گوگل شیت در تنظیمات پروژه وارد نشده؛ یا لینک را وارد کنید یا فایل کلمات را بارگذاری کنید.', array( 'status' => 400 ) );
		}
		$source = 'upload';
	}
	$from = sanitize_key( (string) ( $req['from'] ?? 'import' ) );
	if ( ! isset( mzm_pipeline_steps()[ $from ] ) ) {
		$from = 'import';
	}
	if ( 'import' !== $from && ! mzm_data_get( $p['id'], 'keywords', array() ) ) {
		$from = 'import';
	}
	mzm_pipeline_start( $p, $source, $from );
	return mzm_job_status( mzm_pipeline_tick( $p['id'], 18 ) );
}

/**
 * Continue a run.
 */
function mzm_rest_tick( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	return mzm_job_status( mzm_pipeline_tick( $p['id'], 20 ) );
}

/**
 * Job status.
 */
function mzm_rest_status( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	return mzm_job_status( mzm_data_get( $p['id'], 'job', array() ) );
}

/**
 * Upload a keyword file.
 */
function mzm_rest_upload( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$files = $req->get_file_params();
	$f     = $files['file'] ?? null;
	if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) ) {
		return new WP_Error( 'mzm_upload', 'فایلی دریافت نشد.', array( 'status' => 400 ) );
	}
	if ( (int) $f['size'] > 20 * MB_IN_BYTES ) {
		return new WP_Error( 'mzm_upload', 'حجم فایل بیش از ۲۰ مگابایت است.', array( 'status' => 400 ) );
	}
	$rows = mzm_read_keyword_file( $f['tmp_name'], (string) $f['name'], (string) $p['cfg']['input_tab'] );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$kws = mzm_rows_to_keywords( $rows );
	if ( ! $kws ) {
		return new WP_Error( 'mzm_upload', 'در فایل کلمه‌ای پیدا نشد (ستون اول کلمه کلیدی، ستون دوم حجم جستجو).', array( 'status' => 400 ) );
	}
	mzm_data_put( $p['id'], 'input', $kws );
	return array( 'ok' => true, 'count' => count( $kws ), 'msg' => sprintf( '%s کلمه از فایل خوانده شد.', mzm_num( count( $kws ) ) ) );
}

/**
 * Manual edit.
 */
function mzm_rest_edit( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$e   = (array) $req->get_json_params();
	$res = mzm_edit_apply( $p, $e );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 400 ) );
		return $res;
	}
	return array( 'ok' => true );
}

/**
 * Rebuild the calendar.
 */
function mzm_rest_calendar( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$cal = mzm_calendar_rebuild( $p );
	return array( 'ok' => true, 'msg' => sprintf( 'تقویم با %s مقاله بازسازی شد.', mzm_num( count( $cal['articles'] ) ) ) );
}

/**
 * Write to Google Sheets (one slice).
 */
function mzm_rest_gsheet( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	mzm_raise_limits( 120 );
	if ( ! empty( $req['restart'] ) ) {
		mzm_data_delete( $p['id'], array( 'gsheet_job' ) );
	}
	$res = mzm_gsheet_write( $p, microtime( true ) + 20 );
	if ( is_wp_error( $res ) ) {
		mzm_data_delete( $p['id'], array( 'gsheet_job' ) );
		$res->add_data( array( 'status' => 400 ) );
	}
	return $res;
}

/**
 * Stream an export.
 */
function mzm_rest_download( $req ) {
	$p = mzm_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	if ( ! mzm_data_get( $p['id'], 'clusters', array() ) ) {
		return new WP_Error( 'mzm_no_result', 'هنوز خوشه‌بندی اجرا نشده است.', array( 'status' => 400 ) );
	}
	if ( 'csv' === $req['kind'] ) {
		$R = mzm_result( $p );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="keyword-map-' . sanitize_title( mzm_host( $p['site_url'] ) ?: 'project' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'پیلار', 'بخش', 'کلاستر', 'کلمه کلیدی', 'حجم', 'نقش', 'نیت', 'نوع صفحه', 'اولویت', 'امتیاز', 'صفحه هدف', 'وضعیت' ) );
		foreach ( $R['clusters'] as $c ) {
			foreach ( $c['kws'] as $j => $m ) {
				fputcsv( $out, array( $R['pillars'][ $c['pillar'] ]['name'] ?? '', $c['section'] ?? '', $c['name'], $R['keywords'][ $m ]['k'], $R['keywords'][ $m ]['v'], 0 === $j ? 'اصلی' : 'فرعی', mzm_intents()[ $c['intent'] ] ?? '', mzm_page_types()[ $c['page'] ][0] ?? '', $c['priority'], $c['score'], $c['target']['url'] ?? '', mzm_coverage_label( $c['coverage'] ) ) );
			}
		}
		fclose( $out );
		exit;
	}
	$res = mzm_export_xlsx( $p, $req['kind'] );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="' . $res['name'] . '"' );
	header( 'Content-Length: ' . filesize( $res['path'] ) );
	readfile( $res['path'] ); // phpcs:ignore
	@unlink( $res['path'] ); // phpcs:ignore
	exit;
}

/**
 * Settings tests.
 */
function mzm_rest_test( $req ) {
	mzm_raise_limits( 120 );
	switch ( $req['what'] ) {
		case 'ai':
			if ( ! mzm_ai_connected() ) {
				return array( 'ok' => false, 'msg' => 'کلید هوش مصنوعی تنظیم نشده است.' );
			}
			$t = mzm_ai_test();
			$lines = array_map( function ( $s ) {
				return ( $s['ok'] ? '✓ ' : '✗ ' ) . $s['name'] . ': ' . $s['msg'];
			}, $t['steps'] );
			return array( 'ok' => $t['ok'], 'msg' => implode( "\n", $lines ) );
		case 'serp':
			return mzm_serp_ready() ? mzm_serp_test() : array( 'ok' => false, 'msg' => 'سرویس نتایج گوگل تنظیم نشده است (حالت رایگان).' );
		default:
			$tok = mzm_google_token();
			return is_wp_error( $tok ) ? array( 'ok' => false, 'msg' => $tok->get_error_message() ) : array( 'ok' => true, 'msg' => 'اتصال گوگل برقرار است.' . ( mzm_google_service_email() ? ' شیت‌ها را با ' . mzm_google_service_email() . ' به‌اشتراک بگذارید (Editor).' : '' ) );
	}
}
