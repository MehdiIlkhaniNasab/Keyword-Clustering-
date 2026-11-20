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
	register_rest_route( $ns, '/comp/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_comp', 'permission_callback' => $perm, 'args' => $id ) );
	register_rest_route( $ns, '/control/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_control', 'permission_callback' => $perm, 'args' => $id ) );
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
 * Competitors: upload Seo Signal files, delete, AI analysis, export option.
 */
function qtb_rest_comp( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$act = (string) $req['act'];
	if ( 'upload' === $act ) {
		$files = $req->get_file_params();
		$list  = array();
		if ( ! empty( $files['files'] ) && is_array( $files['files']['name'] ?? null ) ) {
			foreach ( $files['files']['name'] as $n => $name ) {
				$list[] = array( 'name' => $name, 'tmp_name' => $files['files']['tmp_name'][ $n ], 'error' => $files['files']['error'][ $n ], 'size' => $files['files']['size'][ $n ] );
			}
		} elseif ( ! empty( $files['file'] ) ) {
			$list[] = $files['file'];
		}
		if ( ! $list ) {
			return new WP_Error( 'qtb_comp', 'فایلی دریافت نشد.', array( 'status' => 400 ) );
		}
		$ok  = array();
		$bad = array();
		foreach ( $list as $f ) {
			if ( ! empty( $f['error'] ) || (int) $f['size'] > 30 * MB_IN_BYTES ) {
				$bad[] = $f['name'] . ': فایل خوانده نشد';
				continue;
			}
			$rec = qtb_comp_add( $p['id'], (string) file_get_contents( $f['tmp_name'] ) );
			if ( is_wp_error( $rec ) ) {
				$bad[] = $f['name'] . ': ' . $rec->get_error_message();
			} else {
				$ok[] = $rec['domain'] . ' (' . qtb_num( count( $rec['kws'] ) ) . ' کلمه)';
			}
		}
		if ( ! $ok ) {
			return new WP_Error( 'qtb_comp', implode( ' | ', $bad ), array( 'status' => 400 ) );
		}
		qtb_ws_mark_dirty( $p['id'] );
		return array( 'ok' => true, 'msg' => 'رقیب اضافه شد: ' . implode( '، ', $ok ) . ( $bad ? ' — خطا: ' . implode( ' | ', $bad ) : '' ) );
	}
	$domain = strtolower( (string) $req['domain'] );
	$comps  = qtb_comps( $p['id'] );
	$at     = null;
	foreach ( $comps as $n => $c ) {
		if ( $c['domain'] === $domain ) {
			$at = $n;
		}
	}
	if ( 'export' === $act ) {
		$cfg               = $p['cfg'];
		$cfg['export_comp'] = empty( $req['on'] ) ? 0 : 1;
		qtb_project_save( array( 'cfg' => $cfg ), $p['id'] );
		return array( 'ok' => true, 'msg' => $cfg['export_comp'] ? 'شیت «رقبا» در خروجی می‌آید.' : 'شیت «رقبا» از خروجی برداشته شد.' );
	}
	if ( null === $at ) {
		return new WP_Error( 'qtb_comp', 'رقیب پیدا نشد.', array( 'status' => 404 ) );
	}
	if ( 'delete' === $act ) {
		array_splice( $comps, $at, 1 );
		qtb_comps_put( $p['id'], $comps );
		return array( 'ok' => true, 'msg' => 'رقیب حذف شد.' );
	}
	if ( 'ai' === $act ) {
		qtb_raise_limits( 260 );
		$res = qtb_ai_comp_report( $p, $comps[ $at ] );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		$comps[ $at ]['ai']    = $res;
		$comps[ $at ]['ai_at'] = qtb_now();
		qtb_data_put( $p['id'], 'comps', $comps );
		return array( 'ok' => true, 'msg' => 'تحلیل ' . $domain . ' آماده شد.' );
	}
	return new WP_Error( 'qtb_comp', 'دستور نامعتبر.', array( 'status' => 400 ) );
}

/**
 * Stop / resume / skip / cancel the run.
 */
function qtb_rest_control( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$job = qtb_job_control( $p['id'], 'job', (string) $req['action'] );
	if ( is_wp_error( $job ) ) {
		$job->add_data( array( 'status' => 400 ) );
		return $job;
	}
	if ( ! empty( $job['error'] ) ) {
		qtb_project_status( $p['id'], 'error' );
	}
	return qtb_job_status( $job );
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
	$imp = qtb_import_file( $f['tmp_name'], (string) $f['name'] );
	if ( is_wp_error( $imp ) ) {
		$imp->add_data( array( 'status' => 400 ) );
		return $imp;
	}
	if ( ! $imp['rows'] ) {
		return new WP_Error( 'qtb_upload', 'در فایل کلمه‌ای پیدا نشد (ستون اول کلمه کلیدی، ستون دوم حجم جستجو؛ یا شیت‌هایی به سبک کیورد ریسرچ: عنوان بخش بالا و زیرش ستون‌های کلمه | حجم).', array( 'status' => 400 ) );
	}
	qtb_data_put( $p['id'], 'input', $imp['rows'] );
	return array( 'ok' => true, 'count' => count( $imp['rows'] ), 'clusters' => $imp['clusters'], 'msg' => qtb_import_summary( $imp ) );
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
		qtb_ws_publish_if_dirty( $p );
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
	qtb_raise_limits( 120 );
	qtb_ws_publish_if_dirty( $p );
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
	// Test what is on the screen, even before «ذخیره»: unsaved form values override the stored ones.
	$form = (array) ( $req->get_json_params()['form'] ?? array() );
	if ( $form ) {
		$s = qtb_settings();
		foreach ( array_keys( qtb_settings_defaults() ) as $k ) {
			if ( ! isset( $form[ $k ] ) || is_array( $form[ $k ] ) ) {
				continue;
			}
			$v = trim( (string) $form[ $k ] );
			if ( in_array( $k, QTB_SECRET_KEYS, true ) && ( '' === $v || false !== strpos( $v, '•' ) ) ) {
				continue; // Masked placeholder: keep the stored secret.
			}
			$s[ $k ] = $v;
		}
		$GLOBALS['qtb_settings_cache'] = $s;
	}
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
			return qtb_serp_ready() ? qtb_serp_test() : array( 'ok' => false, 'msg' => qtb_serp_off_reason() );
		default:
			$tok = qtb_google_token();
			return is_wp_error( $tok ) ? array( 'ok' => false, 'msg' => $tok->get_error_message() ) : array( 'ok' => true, 'msg' => 'اتصال گوگل برقرار است.' . ( qtb_google_service_email() ? ' شیت‌ها را با ' . qtb_google_service_email() . ' به‌اشتراک بگذارید (Editor).' : '' ) );
	}
}

// ---------------------------------------------------------------------------
// Clustering workshop.
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {
	$ns   = 'ghotbnama/v1';
	$rev  = function () {
		return qtb_can_review();
	};
	$mng  = function () {
		return qtb_can();
	};
	register_rest_route( $ns, '/ws/start/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_start', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/add/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_add', 'permission_callback' => $mng ) );
	register_rest_route( $ns, '/ws/control/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_control', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/tick/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_tick', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/edit/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_edit', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/comment/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_comment', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/evidence/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => 'qtb_rest_ws_evidence', 'permission_callback' => $rev ) );
	register_rest_route( $ns, '/ws/publish/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_publish', 'permission_callback' => $mng ) );
	register_rest_route( $ns, '/ws/finalize/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_finalize', 'permission_callback' => $mng ) );
} );

/**
 * Start a workshop job (one at a time per project).
 */
function qtb_rest_ws_start( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$cur = qtb_data_get( $p['id'], 'ws_job', array() );
	if ( $cur && empty( $cur['done'] ) && empty( $cur['error'] ) && empty( $cur['paused'] ) && ! empty( $cur['started'] ) && strtotime( $cur['started'] ) > time() - 1800 ) {
		return new WP_Error( 'qtb_ws_busy', 'یک کار دیگر در حال اجراست؛ صبر کنید تا تمام شود.', array( 'status' => 409 ) );
	}
	$action = (string) $req['action'];
	if ( ! in_array( $action, array( 'triage', 'topics', 'topic', 'add', 'facets' ), true ) ) {
		return new WP_Error( 'qtb_ws', 'کار نامعتبر.', array( 'status' => 400 ) );
	}
	// Rebuilding the keyword list or the topics resets the review: managers only.
	if ( ! in_array( $action, array( 'topic', 'facets' ), true ) && ! qtb_can() ) {
		return new WP_Error( 'qtb_ws_denied', 'شروع دوباره غربال یا ساخت دوباره موضوع‌ها با مدیر سئو است.', array( 'status' => 403 ) );
	}
	$args = array();
	if ( 'triage' === $action ) {
		$args['source'] = 'upload' === $req['source'] || '' === qtb_sheet_id( $p['cfg']['sheet_url'] ) ? 'upload' : 'sheet';
		if ( 'upload' === $args['source'] && ! qtb_data_get( $p['id'], 'input', array() ) ) {
			return new WP_Error( 'qtb_ws', 'فایل کلمات بارگذاری نشده و لینک گوگل شیت هم وارد نشده است.', array( 'status' => 400 ) );
		}
	}
	if ( 'topic' === $action ) {
		$ws = qtb_ws_get( $p['id'] );
		$ti = qtb_ws_topic_index( $ws, (int) $req['tid'] );
		if ( null === $ti ) {
			return new WP_Error( 'qtb_ws', 'موضوع پیدا نشد.', array( 'status' => 404 ) );
		}
		if ( in_array( $req['mode'], array( 'batch', 'single' ), true ) ) {
			$ws['topics'][ $ti ]['mode'] = $req['mode'];
			qtb_ws_put( $p['id'], $ws );
		}
		// «از نو»: approving locks every cluster, so a re-run would keep them all. Unlock
		// everything except the clusters of the reviewer's own file.
		if ( $req['fresh'] ) {
			foreach ( $ws['topics'][ $ti ]['clusters'] as &$c ) {
				if ( empty( $c['own'] ) ) {
					unset( $c['locked_section'], $c['locked_page'], $c['locked_name'], $c['page_ai'] );
					$c['locked'] = 0;
				}
			}
			unset( $c );
			if ( 'approved' === $ws['topics'][ $ti ]['status'] ) {
				$ws['topics'][ $ti ]['status'] = 'ready';
			}
			qtb_ws_put( $p['id'], $ws );
		}
		$args['tid'] = (int) $req['tid'];
	}
	qtb_ws_start( $p, $action, $args );
	return qtb_ws_job_status( qtb_ws_tick( $p['id'], 18 ) );
}

/**
 * New keywords for a running project (file or pasted lines) → triage and topics of only them.
 */
function qtb_rest_ws_add( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$cur = qtb_data_get( $p['id'], 'ws_job', array() );
	if ( $cur && empty( $cur['done'] ) && empty( $cur['error'] ) && empty( $cur['paused'] ) && ! empty( $cur['started'] ) && strtotime( $cur['started'] ) > time() - 1800 ) {
		return new WP_Error( 'qtb_ws_busy', 'یک کار دیگر در حال اجراست؛ صبر کنید تا تمام شود.', array( 'status' => 409 ) );
	}
	$files = $req->get_file_params();
	$f     = $files['file'] ?? null;
	if ( $f && empty( $f['error'] ) && ! empty( $f['tmp_name'] ) ) {
		$imp = qtb_import_file( $f['tmp_name'], (string) $f['name'] );
		if ( is_wp_error( $imp ) ) {
			$imp->add_data( array( 'status' => 400 ) );
			return $imp;
		}
		$rows = $imp['rows'];
	} else {
		$rows = qtb_text_to_rows( (string) $req['text'] );
	}
	if ( ! $rows ) {
		return new WP_Error( 'qtb_ws_add', 'کلمه‌ای پیدا نشد. هر خط یک کلمه، و حجم بعد از تب یا کاما.', array( 'status' => 400 ) );
	}
	$res = qtb_ws_add_rows( $p, $rows );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 400 ) );
		return $res;
	}
	$msg = sprintf( '%s کلمه جدید اضافه شد؛ %s تکراری کنار رفت؛ %s با قوانین حذف شد.', qtb_num( $res['added'] ), qtb_num( $res['dup'] ), qtb_num( $res['removed'] ) );
	if ( ! $res['added'] ) {
		return array( 'msg' => $msg, 'status' => null );
	}
	qtb_ws_start( $p, 'add' );
	return array( 'msg' => $msg, 'status' => qtb_ws_job_status( qtb_ws_tick( $p['id'], 18 ) ) );
}

/**
 * Stop / resume / skip / cancel the workshop job.
 */
function qtb_rest_ws_control( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$job = qtb_job_control( $p['id'], 'ws_job', (string) $req['action'] );
	if ( is_wp_error( $job ) ) {
		$job->add_data( array( 'status' => 400 ) );
		return $job;
	}
	// A cancelled topic goes back to its last state.
	if ( ! empty( $job['error'] ) && 'topic' === ( $job['action'] ?? '' ) ) {
		$ws = qtb_ws_get( $p['id'] );
		$ti = qtb_ws_topic_index( $ws, $job['tid'] );
		if ( null !== $ti && 'running' === $ws['topics'][ $ti ]['status'] ) {
			$ws['topics'][ $ti ]['status'] = $ws['topics'][ $ti ]['clusters'] ? 'ready' : 'new';
			qtb_ws_put( $p['id'], $ws );
		}
	}
	return qtb_ws_job_status( $job );
}

/**
 * Continue the workshop job.
 */
function qtb_rest_ws_tick( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	return qtb_ws_job_status( qtb_ws_tick( $p['id'], 20 ) );
}

/**
 * Reviewer action.
 */
function qtb_rest_ws_edit( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$res = qtb_ws_edit( $p, (array) $req->get_json_params() );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 400 ) );
		return $res;
	}
	return true === $res ? array( 'ok' => true ) : $res;
}

/**
 * Comment → proposed rules (saved separately after the reviewer confirms).
 */
function qtb_rest_ws_comment( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$text = trim( (string) $req['text'] );
	if ( '' === $text ) {
		return new WP_Error( 'qtb_ws', 'نظر خالی است.', array( 'status' => 400 ) );
	}
	$ws = qtb_ws_get( $p['id'] );
	$ti = qtb_ws_topic_index( $ws, (int) $req['tid'] );
	if ( null !== $ti ) {
		qtb_ws_edit( $p, array( 'op' => 'comment', 'tid' => (int) $req['tid'], 'text' => $text ) );
	}
	if ( ! qtb_ai_connected() ) {
		return array( 'reply' => 'هوش مصنوعی متصل نیست؛ نظر ذخیره شد. قانون را دستی اضافه کنید.', 'rules' => array( array( 'kind' => 'note', 'match' => '', 'page' => '', 'archive' => '', 'text' => qtb_mb_cut( $text, 240 ) ) ) );
	}
	$res = qtb_ai_comment_rules( $p, $ws, null !== $ti ? $ws['topics'][ $ti ] : null, $text );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 400 ) );
		return $res;
	}
	return array( 'reply' => (string) ( $res['reply'] ?? '' ), 'rules' => array_values( (array) ( $res['rules'] ?? array() ) ) );
}

/**
 * Evidence of a keyword (its top 10 and shared URLs).
 */
function qtb_rest_ws_evidence( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$res = qtb_ws_evidence( $p, (int) $req['tid'], (int) $req['kw'] );
	if ( is_wp_error( $res ) ) {
		$res->add_data( array( 'status' => 404 ) );
	}
	return $res;
}

/**
 * Bring the board's edits into the output now.
 */
function qtb_rest_ws_publish( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	qtb_raise_limits( 120 );
	$st = qtb_ws_publish_sync( $p );
	if ( is_wp_error( $st ) ) {
		$st->add_data( array( 'status' => 400 ) );
		return $st;
	}
	return array( 'ok' => (bool) $st['done'], 'msg' => $st['done'] ? 'خروجی به‌روز شد.' : ( $st['error'] ? $st['error'] : 'به‌روزرسانی خروجی ادامه دارد…' ), 'status' => $st );
}

/**
 * Finalize the workshop into the research (the pipeline continues from «structure»).
 */
function qtb_rest_ws_finalize( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	qtb_raise_limits( 120 );
	$st = qtb_ws_publish_sync( $p, 45, ! empty( $req['include_ready'] ) );
	if ( is_wp_error( $st ) ) {
		$st->add_data( array( 'status' => 400 ) );
		return $st;
	}
	$res           = qtb_data_get( $p['id'], 'ws_final', array() );
	$res['status'] = $st;
	return $res;
}

// ---------------------------------------------------------------------------
// Serper connection guide and the browser route.
// ---------------------------------------------------------------------------

add_action( 'rest_api_init', function () {
	$ns = 'ghotbnama/v1';
	register_rest_route( $ns, '/serp/setup', array( 'methods' => 'POST', 'callback' => 'qtb_rest_serp_setup', 'permission_callback' => function () {
		return qtb_can();
	} ) );
	register_rest_route( $ns, '/serp/browser-key', array( 'methods' => 'POST', 'callback' => 'qtb_rest_serp_browser_key', 'permission_callback' => function () {
		return qtb_can_review();
	} ) );
	register_rest_route( $ns, '/ws/serp/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => 'qtb_rest_ws_serp', 'permission_callback' => function () {
		return qtb_can_review();
	} ) );
} );

/**
 * State of the Serper guide (for the settings page).
 */
function qtb_serp_setup_state() {
	$s   = qtb_settings();
	$c   = qtb_serp_conf();
	$wz  = wp_parse_args( (array) $s['serp_wizard'], array( 'step' => 'account', 'done' => array(), 'tests' => array() ) );
	return array(
		'has_key'    => 'serper' === $c['provider'] && '' !== (string) $c['key'],
		'key_mask'   => 'serper' === $c['provider'] ? qtb_mask( $c['key'] ) : '',
		'key_from'   => $c['source'],
		'provider'   => $c['provider'],
		'route'      => qtb_serp_route(),
		'worker_url' => (string) $s['serp_worker_url'],
		'step'       => (string) $wz['step'],
		'done'       => array_values( (array) $wz['done'] ),
		'tests'      => (array) $wz['tests'],
	);
}

/**
 * Save the guide's progress.
 */
function qtb_serp_setup_save( $changes ) {
	$wz = wp_parse_args( (array) qtb_setting( 'serp_wizard', array() ), array( 'step' => 'account', 'done' => array(), 'tests' => array() ) );
	foreach ( $changes as $k => $v ) {
		$wz[ $k ] = $v;
	}
	qtb_update_settings( array( 'serp_wizard' => $wz ) );
}

/**
 * The Serper connection guide: one operation per step.
 */
function qtb_rest_serp_setup( $req ) {
	qtb_raise_limits( 90 );
	$op    = (string) $req['op'];
	$steps = array( 'account', 'key', 'test', 'cf_account', 'cf_worker', 'cf_code', 'cf_url', 'done' );
	switch ( $op ) {
		case 'state':
			break;
		case 'step': // {step, done?}: mark a step done and open the next one.
			$step = (string) $req['step'];
			if ( ! in_array( $step, $steps, true ) ) {
				return new WP_Error( 'qtb_serp', 'مرحله نامعتبر.', array( 'status' => 400 ) );
			}
			$st   = qtb_serp_setup_state();
			$done = $st['done'];
			if ( $req['done'] && ! in_array( $step, $done, true ) ) {
				$done[] = $step;
			}
			$next = (string) ( $req['next'] ?: $step );
			qtb_serp_setup_save( array( 'done' => $done, 'step' => in_array( $next, $steps, true ) ? $next : $step ) );
			break;
		case 'save_key': // {key}.
			$key = trim( sanitize_text_field( (string) $req['key'] ) );
			if ( '' === $key || false !== strpos( $key, '•' ) ) {
				return new WP_Error( 'qtb_serp', 'کلید API را کامل از داشبورد Serper کپی و اینجا جای‌گذاری کنید.', array( 'status' => 400 ) );
			}
			if ( ! preg_match( '/^[A-Za-z0-9]{20,80}$/', $key ) ) {
				return new WP_Error( 'qtb_serp', 'این شبیه کلید Serper نیست (کلید فقط حروف انگلیسی و عدد است، حدود ۴۰ نویسه). دوباره از دکمه کپی داشبورد استفاده کنید.', array( 'status' => 400 ) );
			}
			qtb_update_settings( array( 'serp_source' => 'own', 'serp_provider' => 'serper', 'serp_key' => $key ) );
			$st = qtb_serp_setup_state();
			qtb_serp_setup_save( array( 'done' => array_values( array_unique( array_merge( $st['done'], array( 'key' ) ) ) ), 'step' => 'test' ) );
			break;
		case 'test': // {route: direct|worker, worker_url?} — server-side routes.
			$route = 'worker' === $req['route'] ? 'worker' : 'direct';
			$over  = array();
			if ( 'worker' === $route && $req['worker_url'] ) {
				$over['worker_url'] = esc_url_raw( trim( (string) $req['worker_url'] ) );
			}
			$r     = qtb_serp_test_route( $route, $over );
			$tests = qtb_serp_setup_state()['tests'];
			$tests[ $route ] = array( 'ok' => (bool) $r['ok'], 'msg' => $r['msg'], 'at' => qtb_now() );
			qtb_serp_setup_save( array( 'tests' => $tests ) );
			return array_merge( $r, array( 'state' => qtb_serp_setup_state() ) );
		case 'browser_result': // {ok, msg}: the browser reports its own test.
			$tests            = qtb_serp_setup_state()['tests'];
			$tests['browser'] = array( 'ok' => (bool) $req['ok'], 'msg' => sanitize_text_field( (string) $req['msg'] ), 'at' => qtb_now() );
			qtb_serp_setup_save( array( 'tests' => $tests ) );
			break;
		case 'save_worker': // {url}.
			$url = esc_url_raw( trim( (string) $req['url'] ) );
			if ( ! preg_match( '#^https://[a-z0-9.-]+(/.*)?$#i', $url ) ) {
				return new WP_Error( 'qtb_serp', 'آدرس Worker باید با https:// شروع شود؛ مثل https://ghotbnama-serp.نام-شما.workers.dev', array( 'status' => 400 ) );
			}
			qtb_update_settings( array( 'serp_worker_url' => untrailingslashit( preg_replace( '#/search$#', '', $url ) ) ) );
			break;
		case 'set_route': // {route}.
			$route = in_array( $req['route'], array( 'direct', 'worker', 'browser' ), true ) ? (string) $req['route'] : 'direct';
			qtb_update_settings( array( 'serp_route' => $route ) );
			$st = qtb_serp_setup_state();
			qtb_serp_setup_save( array( 'step' => 'done', 'done' => array_values( array_unique( array_merge( $st['done'], array( 'test' ) ) ) ) ) );
			break;
		case 'worker_code':
			return array( 'code' => qtb_serp_worker_code( qtb_serp_worker_secret() ), 'state' => qtb_serp_setup_state() );
		case 'reset':
			qtb_update_settings( array( 'serp_wizard' => array() ) );
			break;
		default:
			return new WP_Error( 'qtb_serp', 'دستور نامعتبر.', array( 'status' => 400 ) );
	}
	return array( 'ok' => true, 'state' => qtb_serp_setup_state() );
}

/**
 * The Serper key for the browser route (the reviewer's browser calls Serper itself).
 */
function qtb_rest_serp_browser_key( $req ) {
	$c = qtb_serp_conf();
	if ( 'serper' !== $c['provider'] || '' === (string) $c['key'] ) {
		return new WP_Error( 'qtb_serp', 'کلید Serper در تنظیمات ذخیره نشده است.', array( 'status' => 400 ) );
	}
	return array( 'key' => $c['key'], 'gl' => $c['gl'], 'hl' => $c['hl'] );
}

/**
 * Results fetched by the browser: {items: [{i, k, data}]} → cache and the project's SERPs.
 */
function qtb_rest_ws_serp( $req ) {
	$p = qtb_rest_project( $req );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$kws   = qtb_data_get( $p['id'], 'keywords', array() );
	$serps = qtb_data_get( $p['id'], 'serps', array() );
	$n     = 0;
	foreach ( array_slice( (array) $req['items'], 0, 60 ) as $it ) {
		$i = (int) ( $it['i'] ?? -1 );
		$d = $it['data'] ?? null;
		if ( ! isset( $kws[ $i ] ) || ! is_array( $d ) || ! isset( $d['organic'] ) ) {
			continue;
		}
		// The keyword must be the one the server asked for.
		if ( qtb_key( (string) ( $it['k'] ?? '' ) ) !== qtb_key( $kws[ $i ]['k'] ) ) {
			continue;
		}
		$res = qtb_serp_from_serper( $d );
		qtb_serp_store( $kws[ $i ]['k'], $res );
		$serps[ $i ] = $res;
		++$n;
	}
	qtb_data_put( $p['id'], 'serps', $serps );
	return array( 'ok' => true, 'n' => $n );
}
