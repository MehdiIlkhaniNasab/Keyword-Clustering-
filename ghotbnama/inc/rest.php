<?php
/**
 * REST API of the app (namespace ghotbnama/v1). Cookie auth + wp_rest nonce;
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
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$ns   = 'ghotbnama/v1';
	$perm = function () {
		return qtb_can();
	};
	$id   = array( 'id' => array( 'validate_callback' => function ( $v ) {
		return is_numeric( $v );
	} ) );
	register_rest_route( $ns, '/run/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_run', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/tick/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_tick', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/status/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => 'qtb_rest_status', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/upload/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_upload', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/edit/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_edit', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/calendar/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_calendar', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/gsheet/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_gsheet', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/download/(?P<id>\d+)/(?P<kind>research|calendar|csv)', array( 'methods' => 'GET', 'callback' => 'qtb_rest_download', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/test/(?P<what>ai|serp|google)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_test', 'permission_callback' => $perm ) );
} );

/**
 * Project of a request or error.
 */
function qtb_rest_project( $req ) {
	// The URL id, never a body field of the same name (edits send cluster ids as «id»).
	$p = qtb_project( (int) ( $req->get_url_params()['id'] ?? 0 ) );
	return $p ?: new WP_Error( 'qtb_404', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
}

/**
 * Start a run.
 */
function qtb_rest_run( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$source = 'upload' === $req['source'] ? 'upload' : 'sheet';
	if ( 'sheet' === $source && '' === qtb_sheet_id( $p['cfg']['sheet_url'] ) ) {
		$has = (bool) qtb_data_get( $p['id'], 'input', array() );
		if ( ! $has ) {
			return new WP_Error( 'qtb_no_sheet', 'لینک گوگل شیت در تنظیمات پروژه وارد نشده؛ یا لینک را وارد کنید یا فایل کلمات را بارگذاری کنید.', array( 'status' => 400 ) );
		}
		$source = 'upload';
	}
	$from = sanitize_key( (string) ( $req['from'] ?? 'import' ) );
	if ( ! isset( qtb_pipeline_steps()[ $from ] ) ) {
		$from = 'import';
	}
	if ( 'import' !== $from && ! qtb_data_get( $p['id'], 'keywords', array() ) ) {
		$from = 'import';
	}
	qtb_pipeline_start( $p, $source, $from );
	return qtb_job_status( qtb_pipeline_tick( $p['id'], 18 ) );
}

/**
 * Continue a run.
 */
function qtb_rest_tick( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	return qtb_job_status( qtb_pipeline_tick( $p['id'], 20 ) );
}

/**
 * Job status.
 */
function qtb_rest_status( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	return qtb_job_status( qtb_data_get( $p['id'], 'job', array() ) );
}

/**
 * Upload a keyword file.
 */
function qtb_rest_upload( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$files = $req->get_file_params();
	$f     = $files['file'] ?? null;
	if ( ! $f || ! empty( $f['error'] ) || empty( $f['tmp_name'] ) ) {
		return new WP_Error( 'qtb_upload', 'فایلی دریافت نشد.', array( 'status' => 400 ) );
	}
	if ( (int) $f['size'] > 20 * MB_IN_BYTES ) {
		return new WP_Error( 'qtb_upload', 'حجم فایل بیش از ۲۰ مگابایت است.', array( 'status' => 400 ) );
	}
	$rows = qtb_read_keyword_file( $f['tmp_name'], (string) $f['name'], (string) $p['cfg']['input_tab'] );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$kws = qtb_rows_to_keywords( $rows );
	if ( ! $kws ) {
		return new WP_Error( 'qtb_upload', 'در فایل کلمه‌ای پیدا نشد (ستون اول کلمه کلیدی، ستون دوم حجم جستجو).', array( 'status' => 400 ) );
	}
	qtb_data_put( $p['id'], 'input', $kws );
	return array( 'ok' => true, 'count' => count( $kws ), 'msg' => sprintf( '%s کلمه از فایل خوانده شد.', qtb_num( count( $kws ) ) ) );
}

/**
 * Manual edit.
 */
function qtb_rest_edit( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$e   = (array) $req->get_json_params();
	$res = qtb_edit_apply( $p, $e );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 400 ) );
		return $res;
	}
	return array( 'ok' => true );
}

/**
 * Rebuild the calendar.
 */
function qtb_rest_calendar( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$cal = qtb_calendar_rebuild( $p );
	return array( 'ok' => true, 'msg' => sprintf( 'تقویم با %s مقاله بازسازی شد.', qtb_num( count( $cal['articles'] ) ) ) );
}

/**
 * Write to Google Sheets (one slice).
 */
function qtb_rest_gsheet( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	qtb_raise_limits( 120 );
	if ( ! empty( $req['restart'] ) ) {
		qtb_data_delete( $p['id'], array( 'gsheet_job' ) );
	}
	$res = qtb_gsheet_write( $p, microtime( true ) + 20 );
	if ( is_wp_error( $res ) ) {
		qtb_data_delete( $p['id'], array( 'gsheet_job' ) );
		$res->add_data( array( 'status' => 400 ) );
	}
	return $res;
}

/**
 * Stream an export.
 */
function qtb_rest_download( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	if ( ! qtb_data_get( $p['id'], 'clusters', array() ) ) {
		return new WP_Error( 'qtb_no_result', 'هنوز خوشه‌بندی اجرا نشده است.', array( 'status' => 400 ) );
	}
	if ( 'csv' === $req['kind'] ) {
		$R = qtb_result( $p );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="keyword-map-' . sanitize_title( qtb_host( $p['site_url'] ) ?: 'project' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'پیلار', 'بخش', 'کلاستر', 'کلمه کلیدی', 'حجم', 'نقش', 'نیت', 'نوع صفحه', 'اولویت', 'امتیاز', 'صفحه هدف', 'وضعیت' ) );
		foreach ( $R['clusters'] as $c ) {
			foreach ( $c['kws'] as $j => $m ) {
				fputcsv( $out, array( $R['pillars'][ $c['pillar'] ]['name'] ?? '', $c['section'] ?? '', $c['name'], $R['keywords'][ $m ]['k'], $R['keywords'][ $m ]['v'], 0 === $j ? 'اصلی' : 'فرعی', qtb_intents()[ $c['intent'] ] ?? '', qtb_page_types()[ $c['page'] ][0] ?? '', $c['priority'], $c['score'], $c['target']['url'] ?? '', qtb_coverage_label( $c['coverage'] ) ) );
			}
		}
		fclose( $out );
		exit;
	}
	$res = qtb_export_xlsx( $p, $req['kind'] );
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
function qtb_rest_test( $req ) {
	qtb_raise_limits( 120 );
	switch ( $req['what'] ) {
		case 'ai':
			if ( ! qtb_ai_connected() ) {
				return array( 'ok' => false, 'msg' => 'کلید هوش مصنوعی تنظیم نشده است.' );
			}
			$t = qtb_ai_test();
			$lines = array_map( function ( $s ) {
				return ( $s['ok'] ? '✓ ' : '✗ ' ) . $s['name'] . ': ' . $s['msg'];
			}, $t['steps'] );
			return array( 'ok' => $t['ok'], 'msg' => implode( "\n", $lines ) );
		case 'serp':
			return qtb_serp_ready() ? qtb_serp_test() : array( 'ok' => false, 'msg' => 'سرویس نتایج گوگل تنظیم نشده است (حالت رایگان).' );
		default:
			$tok = qtb_google_token();
			return is_wp_error( $tok ) ? array( 'ok' => false, 'msg' => $tok->get_error_message() ) : array( 'ok' => true, 'msg' => 'اتصال گوگل برقرار است.' . ( qtb_google_service_email() ? ' شیت‌ها را با ' . qtb_google_service_email() . ' به‌اشتراک بگذارید (Editor).' : '' ) );
	}
}
