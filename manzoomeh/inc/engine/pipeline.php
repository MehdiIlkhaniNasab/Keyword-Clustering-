<?php
/**
 * The clustering pipeline: resumable steps, each run in short slices so a
 * 5,000-keyword project works on ordinary hosting (every REST call runs ~20 s,
 * the browser keeps calling until the job is done).
 *
 *   import → clean → ai_filter → precluster → serp → serp_cluster → inventory
 *   → structure → score → gap → calendar → finish (→ gsheet when auto-write is on)
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Steps and their labels.
 */
function mzm_pipeline_steps() {
	return array(
		'import'       => 'دریافت کلمات',
		'clean'        => 'پاک‌سازی و حذف تکراری‌ها، برند رقبا و کلمات بی‌ارزش',
		'ai_filter'    => 'فیلتر هوشمند ارتباط و نیت (هوش مصنوعی)',
		'precluster'   => 'پیش‌خوشه‌بندی زبانی',
		'serp'         => 'بررسی ۱۰ نتیجه اول گوگل',
		'serp_cluster' => 'خوشه‌بندی بر اساس اشتراک نتایج گوگل',
		'inventory'    => 'خواندن صفحات و محصولات سایت',
		'structure'    => 'ساخت پیلارها و بخش‌ها',
		'score'        => 'نگاشت به صفحات، کنیبالیزیشن و امتیاز فروش',
		'gap'          => 'تحلیل شکاف محتوایی رقبا',
		'calendar'     => 'ساخت تقویم محتوا',
		'finish'       => 'جمع‌بندی',
		'gsheet'       => 'نوشتن خروجی در گوگل شیت',
	);
}

/**
 * Effective SERP mode of a project: free|economy|full.
 */
function mzm_serp_mode( $project ) {
	$mode = (string) $project['cfg']['serp_mode'];
	if ( 'free' === $mode || ! mzm_serp_ready() ) {
		return 'free';
	}
	return 'full' === $mode ? 'full' : 'economy';
}

/**
 * Start a fresh job.
 *
 * @param string $source sheet|upload (upload: the input was stored already).
 */
function mzm_pipeline_start( $project, $source = 'sheet', $from = 'import' ) {
	$steps = array_keys( mzm_pipeline_steps() );
	if ( empty( $project['cfg']['auto_write'] ) ) {
		$steps = array_values( array_diff( $steps, array( 'gsheet' ) ) );
	}
	$at  = array_search( $from, $steps, true );
	$job = array(
		'steps'   => $steps,
		'at'      => false === $at ? 0 : $at,
		'source'  => $source,
		'sub'     => array(),
		'log'     => array(),
		'started' => mzm_now(),
		'done'    => false,
		'error'   => '',
		'stats'   => array( 'serp_new' => 0, 'serp_cache' => 0, 'ai_cost0' => mzm_ai_usage()['cost'] ),
	);
	mzm_data_put( $project['id'], 'job', $job );
	mzm_project_status( $project['id'], 'running' );
	return $job;
}

/**
 * Add a log line to the job.
 */
function mzm_job_log( &$job, $msg, $level = 'info' ) {
	$job['log'][] = array( 't' => current_time( 'H:i:s' ), 'm' => $msg, 'l' => $level );
	if ( count( $job['log'] ) > 80 ) {
		$job['log'] = array_slice( $job['log'], -80 );
	}
}

/**
 * Progress summary for the UI.
 */
function mzm_job_status( $job ) {
	$labels = mzm_pipeline_steps();
	$n      = max( 1, count( $job['steps'] ?? array() ) );
	$at     = (int) ( $job['at'] ?? 0 );
	$step   = $job['steps'][ $at ] ?? '';
	$frac   = (float) ( $job['sub']['frac'] ?? 0 );
	return array(
		'done'  => ! empty( $job['done'] ),
		'error' => (string) ( $job['error'] ?? '' ),
		'step'  => $step,
		'label' => $labels[ $step ] ?? 'پایان',
		'pct'   => ! empty( $job['done'] ) ? 100 : (int) min( 99, round( 100 * ( $at + $frac ) / $n ) ),
		'log'   => array_slice( (array) ( $job['log'] ?? array() ), -12 ),
		'steps' => array_map( function ( $s, $i ) use ( $labels, $at, $job ) {
			return array( 'key' => $s, 'label' => $labels[ $s ] ?? $s, 'state' => $i < $at || ! empty( $job['done'] ) ? 'done' : ( $i === $at ? 'now' : 'todo' ) );
		}, $job['steps'] ?? array(), array_keys( $job['steps'] ?? array() ) ),
	);
}

/**
 * Run the job for a time slice.
 *
 * @return array job
 */
function mzm_pipeline_tick( $project_id, $budget = 20 ) {
	mzm_raise_limits( $budget + 120 );
	$project = mzm_project( $project_id );
	$job     = mzm_data_get( $project_id, 'job', array() );
	if ( ! $project || ! $job || ! empty( $job['done'] ) || ! empty( $job['error'] ) ) {
		return $job;
	}
	// One runner at a time per project.
	$lock = 'mzm_lock_' . $project_id;
	if ( get_transient( $lock ) ) {
		return $job;
	}
	set_transient( $lock, 1, $budget + 90 );
	$t0 = microtime( true );
	try {
		while ( empty( $job['done'] ) && empty( $job['error'] ) && microtime( true ) - $t0 < $budget ) {
			$step = $job['steps'][ $job['at'] ] ?? null;
			if ( null === $step ) {
				$job['done'] = true;
				break;
			}
			$fn  = 'mzm_step_' . $step;
			$res = $fn( $project, $job, $t0 + $budget );
			if ( is_wp_error( $res ) ) {
				$job['error'] = $res->get_error_message();
				mzm_job_log( $job, $job['error'], 'error' );
				mzm_project_status( $project_id, 'error' );
				break;
			}
			if ( true === $res ) {
				$job['at']++;
				$job['sub'] = array();
				if ( $job['at'] >= count( $job['steps'] ) ) {
					$job['done'] = true;
					mzm_project_status( $project_id, 'ready' );
				}
			}
			mzm_data_put( $project_id, 'job', $job );
		}
	} catch ( Throwable $e ) {
		$job['error'] = 'خطای داخلی: ' . $e->getMessage();
		mzm_job_log( $job, $job['error'] . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'error' );
		mzm_project_status( $project_id, 'error' );
	}
	mzm_data_put( $project_id, 'job', $job );
	delete_transient( $lock );
	return $job;
}

/**
 * Has a step's time slice run out?
 */
function mzm_out_of_time( $deadline ) {
	return microtime( true ) >= $deadline;
}

// ---------------------------------------------------------------------------
// Steps. Each returns true (done), false (call again) or WP_Error.
// ---------------------------------------------------------------------------

/**
 * Import the keywords.
 */
function mzm_step_import( $project, &$job ) {
	if ( 'upload' === $job['source'] ) {
		$input = mzm_data_get( $project['id'], 'input', array() );
		if ( ! $input ) {
			return new WP_Error( 'mzm_no_input', 'فایل کلمات بارگذاری نشده است.' );
		}
	} else {
		$input = mzm_sheet_read_keywords( $project );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		mzm_data_put( $project['id'], 'input', $input );
	}
	mzm_job_log( $job, sprintf( '%s کلمه دریافت شد.', mzm_num( count( $input ) ) ) );
	return true;
}

/**
 * Rule-based cleaning.
 */
function mzm_step_clean( $project, &$job ) {
	$res = mzm_clean_run( $project, mzm_data_get( $project['id'], 'input', array() ) );
	if ( ! $res['keywords'] ) {
		return new WP_Error( 'mzm_empty', 'بعد از پاک‌سازی کلمه‌ای باقی نماند. فهرست کلمات و تنظیمات حذف (حداقل حجم، کلمات منفی) را بررسی کنید.' );
	}
	mzm_data_put( $project['id'], 'keywords', $res['keywords'] );
	mzm_data_put( $project['id'], 'removed', $res['removed'] );
	$by = array_count_values( array_column( $res['removed'], 'r' ) );
	$parts = array();
	foreach ( $by as $r => $n ) {
		$parts[] = ( mzm_remove_reasons()[ $r ] ?? $r ) . ': ' . mzm_num( $n );
	}
	mzm_job_log( $job, sprintf( '%s کلمه ماند%s', mzm_num( count( $res['keywords'] ) ), $parts ? '؛ حذف‌شده‌ها — ' . implode( '، ', $parts ) : '' ) );
	return true;
}

/**
 * AI relevance / intent pass.
 */
function mzm_step_ai_filter( $project, &$job, $deadline ) {
	if ( ! mzm_ai_connected() ) {
		mzm_job_log( $job, 'هوش مصنوعی متصل نیست؛ فیلتر هوشمند انجام نشد (قوانین پاک‌سازی اجرا شد).', 'warn' );
		return true;
	}
	$kws  = mzm_data_get( $project['id'], 'keywords', array() );
	$off  = (int) ( $job['sub']['off'] ?? 0 );
	$size = 220;
	while ( $off < count( $kws ) ) {
		$batch = array_slice( $kws, $off, $size );
		$res   = mzm_ai_filter_batch( $project, $batch );
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'mzm_ai_budget', 'mzm_ai_auth', 'mzm_ai_key' ), true ) ) {
				mzm_job_log( $job, 'فیلتر هوشمند متوقف شد: ' . $res->get_error_message(), 'warn' );
				break;
			}
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 ) {
				mzm_job_log( $job, 'فیلتر هوشمند برای بخشی از کلمات انجام نشد: ' . $res->get_error_message(), 'warn' );
				$off += $size;
				$job['sub']['fail'] = 0;
			}
			$job['sub']['off'] = $off;
			return false;
		}
		foreach ( $batch as $j => $kw ) {
			$i = $kw['i'];
			if ( isset( $res['intent'][ $i ] ) ) {
				$kws[ $off + $j ]['ai_intent'] = $res['intent'][ $i ];
			}
			if ( isset( $res['drop'][ $i ] ) && empty( $kw['brand'] ) ) {
				$kws[ $off + $j ]['drop'] = $res['drop'][ $i ];
			}
		}
		$off += $size;
		$job['sub']['off']  = $off;
		$job['sub']['frac'] = min( 1, $off / max( 1, count( $kws ) ) );
		mzm_data_put( $project['id'], 'keywords', $kws );
		if ( mzm_out_of_time( $deadline ) ) {
			return false;
		}
	}
	// Apply the drops and re-number.
	$removed = mzm_data_get( $project['id'], 'removed', array() );
	$keep    = array();
	$n       = 0;
	foreach ( $kws as $kw ) {
		if ( ! empty( $kw['drop'] ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => $kw['drop'][0], 'd' => $kw['drop'][1], 'ai' => 1 );
			++$n;
			continue;
		}
		$kw['i'] = count( $keep );
		$keep[]  = $kw;
	}
	mzm_data_put( $project['id'], 'keywords', $keep );
	mzm_data_put( $project['id'], 'removed', $removed );
	mzm_job_log( $job, sprintf( 'فیلتر هوشمند: %s کلمه نامرتبط یا برند رقیب حذف شد.', mzm_num( $n ) ) );
	return true;
}

/**
 * Lexical pre-clustering.
 */
function mzm_step_precluster( $project, &$job ) {
	$kws    = mzm_data_get( $project['id'], 'keywords', array() );
	$ann    = mzm_annotate( $kws );
	$groups = mzm_precluster( $kws );
	mzm_data_put( $project['id'], 'keywords', $kws );
	mzm_data_put( $project['id'], 'work', array( 'groups' => $groups, 'fillers' => $ann['fillers'] ) );
	mzm_job_log( $job, sprintf( '%s گروه اولیه ساخته شد (کلمات عمومی حذف‌شدنی: %s).', mzm_num( count( $groups ) ), implode( '، ', array_slice( $ann['fillers'], 0, 8 ) ) ?: '—' ) );
	return true;
}

/**
 * Look up Google's top 10.
 */
function mzm_step_serp( $project, &$job, $deadline ) {
	$mode = mzm_serp_mode( $project );
	if ( 'free' === $mode ) {
		mzm_data_put( $project['id'], 'serps', array() );
		mzm_job_log( $job, mzm_serp_ready() ? 'حالت رایگان پروژه: نتایج گوگل بررسی نشد.' : 'سرویس نتایج گوگل تنظیم نشده؛ خوشه‌بندی در حالت رایگان (زبانی + هوش مصنوعی) انجام شد.', 'warn' );
		return true;
	}
	$kws  = mzm_data_get( $project['id'], 'keywords', array() );
	$work = mzm_data_get( $project['id'], 'work', array() );
	if ( ! isset( $job['sub']['plan'] ) ) {
		$cap  = (int) ( $project['cfg']['serp_cap'] ?: mzm_setting( 'serp_max_project', 2000 ) );
		$plan = mzm_serp_plan( $kws, $work['groups'], $mode, $cap );
		// Cache first: free.
		$hit   = mzm_serp_cached_many( array_map( function ( $i ) use ( $kws ) {
			return $kws[ $i ]['k'];
		}, $plan ) );
		$serps = array();
		$todo  = array();
		foreach ( $plan as $i ) {
			$h = mzm_serp_hash( $kws[ $i ]['k'] );
			if ( isset( $hit[ $h ] ) ) {
				$serps[ $i ] = $hit[ $h ];
			} else {
				$todo[] = $i;
			}
		}
		$job['stats']['serp_cache'] = count( $serps );
		$job['sub']['plan']         = $todo;
		$job['sub']['pos']          = 0;
		$job['sub']['fails']        = 0;
		mzm_data_put( $project['id'], 'serps', $serps );
		mzm_job_log( $job, sprintf( '%s کلمه برای بررسی نتایج گوگل انتخاب شد (%s از کش، %s درخواست جدید).', mzm_num( count( $plan ) ), mzm_num( count( $serps ) ), mzm_num( count( $todo ) ) ) );
	}
	$serps = mzm_data_get( $project['id'], 'serps', array() );
	$todo  = $job['sub']['plan'];
	$pos   = (int) $job['sub']['pos'];
	while ( $pos < count( $todo ) ) {
		$i   = $todo[ $pos ];
		$res = mzm_serp_fetch( $kws[ $i ]['k'] );
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'mzm_serp_auth', 'mzm_serp_off' ), true ) ) {
				mzm_job_log( $job, $res->get_error_message() . ' — ادامه با نتایجی که گرفته شد.', 'warn' );
				break;
			}
			$job['sub']['fails']++;
			if ( $job['sub']['fails'] > 12 ) {
				mzm_job_log( $job, 'خطاهای پشت‌سرهم سرویس نتایج گوگل: ' . $res->get_error_message() . ' — ادامه با نتایجی که گرفته شد.', 'warn' );
				break;
			}
			++$pos;
			continue;
		}
		mzm_serp_store( $kws[ $i ]['k'], $res );
		$serps[ $i ] = $res;
		$job['stats']['serp_new']++;
		++$pos;
		if ( 0 === $pos % 10 ) {
			$job['sub']['pos']  = $pos;
			$job['sub']['frac'] = $pos / max( 1, count( $todo ) );
			mzm_data_put( $project['id'], 'serps', $serps );
		}
		if ( mzm_out_of_time( $deadline ) ) {
			$job['sub']['pos']  = $pos;
			$job['sub']['frac'] = $pos / max( 1, count( $todo ) );
			mzm_data_put( $project['id'], 'serps', $serps );
			return false;
		}
	}
	mzm_data_put( $project['id'], 'serps', $serps );
	mzm_job_log( $job, sprintf( 'نتایج گوگل %s کلمه آماده شد.', mzm_num( count( $serps ) ) ) );
	return true;
}

/**
 * SERP-overlap clustering → clusters.
 */
function mzm_step_serp_cluster( $project, &$job ) {
	$kws    = mzm_data_get( $project['id'], 'keywords', array() );
	$work   = mzm_data_get( $project['id'], 'work', array() );
	$serps  = mzm_data_get( $project['id'], 'serps', array() );
	$groups = $work['groups'];
	$before = count( $groups );
	if ( $serps ) {
		$groups = mzm_serp_cluster( $kws, $groups, $serps, (int) $project['cfg']['strictness'] );
	}
	$clusters = mzm_clusters_build( $kws, $groups, $serps, mzm_project_domain( $project ) );
	$clusters = mzm_attach_orphans( $kws, $clusters );
	mzm_data_put( $project['id'], 'clusters', $clusters );
	mzm_job_log( $job, $serps
		? sprintf( 'با نتایج گوگل %s گروه به %s کلاستر رسید (ادغام مترادف‌ها و تفکیک نیت‌های متفاوت).', mzm_num( $before ), mzm_num( count( $clusters ) ) )
		: sprintf( '%s کلاستر ساخته شد.', mzm_num( count( $clusters ) ) ) );
	return true;
}

/**
 * Site inventory.
 */
function mzm_step_inventory( $project, &$job, $deadline ) {
	$state = $job['sub']['store'] ?? array();
	while ( empty( $state['done'] ) ) {
		$state = mzm_store_step( $project, $state );
		$job['sub']['store'] = $state;
		if ( mzm_out_of_time( $deadline ) && empty( $state['done'] ) ) {
			return false;
		}
	}
	$items = (array) ( $state['items'] ?? array() );
	mzm_data_put( $project['id'], 'inventory', array( 'items' => $items, 'source' => $state['source'] ?? '', 'notes' => $state['notes'] ?? array(), 'at' => mzm_now() ) );
	if ( $items ) {
		$by = array_count_values( array_column( $items, 'type' ) );
		mzm_job_log( $job, sprintf( 'صفحات سایت از %s: %s محصول، %s دسته، %s مقاله، %s برگه.', mzm_store_source_label( $state['source'] ), mzm_num( $by['product'] ?? 0 ), mzm_num( $by['category'] ?? 0 ), mzm_num( $by['post'] ?? 0 ), mzm_num( $by['page'] ?? 0 ) ) );
	} elseif ( 'none' === $project['cfg']['store_mode'] ) {
		mzm_job_log( $job, 'اتصال به صفحات سایت در تنظیمات پروژه خاموش است؛ همه کلاسترها «صفحه جدید» در نظر گرفته شدند.', 'warn' );
	} else {
		mzm_job_log( $job, 'به صفحات سایت دسترسی نبود؛ نگاشت به صفحات موجود و قیمت محصولات انجام نشد (بقیه تحلیل کامل است).', 'warn' );
	}
	return true;
}

/**
 * Pillars and sections (AI when available).
 */
function mzm_step_structure( $project, &$job, $deadline ) {
	$kws      = mzm_data_get( $project['id'], 'keywords', array() );
	$clusters = mzm_data_get( $project['id'], 'clusters', array() );
	if ( mzm_ai_connected() && empty( $job['sub']['ai_off'] ) ) {
		// Related clusters together: order by the free pillar.
		if ( ! isset( $job['sub']['order'] ) ) {
			$tmp   = mzm_pillars_free( $kws, $clusters );
			$order = array_keys( $tmp );
			usort( $order, function ( $a, $b ) use ( $tmp ) {
				return strcmp( $tmp[ $a ]['pillar_key'], $tmp[ $b ]['pillar_key'] ) ?: ( $tmp[ $b ]['vol'] <=> $tmp[ $a ]['vol'] );
			} );
			$job['sub']['order'] = $order;
			$job['sub']['off']   = 0;
			$job['sub']['known'] = array();
			$job['sub']['merge'] = array();
		}
		$order = $job['sub']['order'];
		$size  = 180;
		while ( $job['sub']['off'] < count( $order ) ) {
			$batch = array();
			foreach ( array_slice( $order, $job['sub']['off'], $size ) as $ci ) {
				$c       = $clusters[ $ci ];
				$batch[] = array(
					'id'     => $c['id'],
					'name'   => $c['name'],
					'vol'    => $c['vol'],
					'page'   => $c['page'],
					'intent' => $c['intent'],
					'kws'    => array_map( function ( $m ) use ( $kws ) {
						return $kws[ $m ]['k'];
					}, array_slice( $c['kws'], 0, 5 ) ),
				);
			}
			$res = mzm_ai_structure_batch( $project, $batch, $job['sub']['known'] );
			if ( is_wp_error( $res ) ) {
				$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
				if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'mzm_ai_budget', 'mzm_ai_auth', 'mzm_ai_key' ), true ) ) {
					mzm_job_log( $job, 'ساختار هوشمند انجام نشد (' . $res->get_error_message() . ')؛ ساختار از روی داده‌ها ساخته شد.', 'warn' );
					$job['sub']['ai_off'] = 1;
					break;
				}
				return false;
			}
			$by_id = array();
			foreach ( $clusters as $ci => $c ) {
				$by_id[ $c['id'] ] = $ci;
			}
			foreach ( $res['map'] as $id => $a ) {
				$clusters[ $by_id[ $id ] ]['ai'] = $a;
				if ( '' !== $a['pillar'] && ! in_array( $a['pillar'], $job['sub']['known'], true ) ) {
					$job['sub']['known'][] = $a['pillar'];
				}
			}
			$job['sub']['merge'] = array_merge( $job['sub']['merge'], $res['merge'] );
			$job['sub']['off']  += $size;
			$job['sub']['frac']  = min( 0.95, $job['sub']['off'] / max( 1, count( $order ) ) );
			mzm_data_put( $project['id'], 'clusters', $clusters );
			if ( mzm_out_of_time( $deadline ) && $job['sub']['off'] < count( $order ) ) {
				return false;
			}
		}
		if ( ! empty( $job['sub']['merge'] ) ) {
			$n        = count( $clusters );
			$clusters = mzm_clusters_merge_sets( $kws, $clusters, $job['sub']['merge'] );
			mzm_job_log( $job, sprintf( 'هوش مصنوعی %s کلاستر هم‌نیت (مترادف) را ادغام کرد.', mzm_num( $n - count( $clusters ) ) ) );
		}
	}
	$clusters = mzm_clusters_reindex( $kws, $clusters );
	$built    = mzm_structure_build( $kws, $clusters );
	mzm_data_put( $project['id'], 'clusters', $built['clusters'] );
	mzm_data_put( $project['id'], 'pillars', $built['pillars'] );
	mzm_job_log( $job, sprintf( '%s پیلار و %s کلاستر.', mzm_num( count( $built['pillars'] ) ), mzm_num( count( $built['clusters'] ) ) ) );
	return true;
}

/**
 * Mapping, cannibalization and scoring.
 */
function mzm_step_score( $project, &$job ) {
	$kws      = mzm_data_get( $project['id'], 'keywords', array() );
	$work     = mzm_data_get( $project['id'], 'work', array() );
	$clusters = mzm_data_get( $project['id'], 'clusters', array() );
	$pillars  = mzm_data_get( $project['id'], 'pillars', array() );
	$inv      = (array) ( mzm_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$clusters = mzm_map_clusters( $kws, $clusters, $inv, (array) ( $work['fillers'] ?? array() ) );
	$clusters = mzm_score_clusters( $project, $clusters, $pillars, $inv );
	$pillars  = mzm_pillar_totals( $clusters, $pillars );
	mzm_data_put( $project['id'], 'clusters', $clusters );
	mzm_data_put( $project['id'], 'pillars', $pillars );
	$cov = array_count_values( array_column( $clusters, 'coverage' ) );
	mzm_job_log( $job, sprintf( 'صفحه جدید لازم: %s، صفحه موجود برای بهینه‌سازی: %s، رتبه خوب: %s، برد سریع: %s.', mzm_num( $cov['new'] ?? 0 ), mzm_num( $cov['optimize'] ?? 0 ), mzm_num( $cov['ok'] ?? 0 ), mzm_num( count( array_filter( array_column( $clusters, 'quick_win' ) ) ) ) ) );
	return true;
}

/**
 * Content gap analysis of the best clusters.
 */
function mzm_step_gap( $project, &$job, $deadline ) {
	if ( ! mzm_ai_connected() ) {
		mzm_job_log( $job, 'هوش مصنوعی متصل نیست؛ تحلیل شکاف محتوایی انجام نشد.', 'warn' );
		return true;
	}
	$kws      = mzm_data_get( $project['id'], 'keywords', array() );
	$clusters = mzm_data_get( $project['id'], 'clusters', array() );
	$serps    = mzm_data_get( $project['id'], 'serps', array() );
	if ( ! isset( $job['sub']['ids'] ) ) {
		$job['sub']['ids'] = mzm_gap_targets( $clusters, max( 0, (int) $project['cfg']['gap_limit'] ) );
		$job['sub']['off'] = 0;
	}
	$by_id = array();
	foreach ( $clusters as $ci => $c ) {
		$by_id[ $c['id'] ] = $ci;
	}
	$ids = $job['sub']['ids'];
	while ( $job['sub']['off'] < count( $ids ) ) {
		$batch = array();
		foreach ( array_slice( $ids, $job['sub']['off'], 3 ) as $id ) {
			$batch[] = mzm_gap_input( $kws, $clusters[ $by_id[ $id ] ], $clusters, $serps );
		}
		$res = mzm_ai_gap_batch( $project, $batch );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'mzm_ai_budget', 'mzm_ai_auth', 'mzm_ai_key' ), true ) ) {
				mzm_job_log( $job, 'تحلیل شکاف ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $res as $id => $gap ) {
			$clusters[ $by_id[ $id ] ]['gap'] = $gap;
		}
		$job['sub']['off'] += 3;
		$job['sub']['frac'] = $job['sub']['off'] / max( 1, count( $ids ) );
		mzm_data_put( $project['id'], 'clusters', $clusters );
		if ( mzm_out_of_time( $deadline ) && $job['sub']['off'] < count( $ids ) ) {
			return false;
		}
	}
	mzm_data_put( $project['id'], 'clusters', $clusters );
	mzm_job_log( $job, sprintf( 'تحلیل شکاف و بریف صفحه برای %s کلاستر برتر آماده شد.', mzm_num( count( array_filter( array_column( $clusters, 'gap' ) ) ) ) ) );
	return true;
}

/**
 * Content calendar (with briefs for the chosen articles).
 */
function mzm_step_calendar( $project, &$job, $deadline ) {
	$kws      = mzm_data_get( $project['id'], 'keywords', array() );
	$clusters = mzm_data_get( $project['id'], 'clusters', array() );
	$pillars  = mzm_data_get( $project['id'], 'pillars', array() );
	$serps    = mzm_data_get( $project['id'], 'serps', array() );
	$inv      = (array) ( mzm_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$cal      = mzm_calendar_build( $project, $kws, $clusters, $pillars, $inv );
	// Briefs for chosen articles that the gap step did not cover (light model).
	if ( mzm_ai_connected() && empty( $job['sub']['ai_off'] ) ) {
		$by_id = array();
		foreach ( $clusters as $ci => $c ) {
			$by_id[ $c['id'] ] = $ci;
		}
		$need = array();
		foreach ( $cal['articles'] as $a ) {
			if ( empty( $clusters[ $by_id[ $a['cluster'] ] ]['gap'] ) ) {
				$need[] = $a['cluster'];
			}
		}
		while ( $need ) {
			$batch = array();
			foreach ( array_slice( $need, 0, 4 ) as $id ) {
				$batch[] = mzm_gap_input( $kws, $clusters[ $by_id[ $id ] ], $clusters, $serps );
			}
			$res = mzm_ai_gap_batch( $project, $batch, 'light' );
			if ( is_wp_error( $res ) ) {
				$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
				if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'mzm_ai_budget', 'mzm_ai_auth', 'mzm_ai_key' ), true ) ) {
					mzm_job_log( $job, 'بریف بعضی مقاله‌ها ساخته نشد: ' . $res->get_error_message(), 'warn' );
					$job['sub']['ai_off'] = 1;
					break;
				}
				return false;
			}
			foreach ( array_slice( $need, 0, 4 ) as $id ) {
				$clusters[ $by_id[ $id ] ]['gap'] = $res[ $id ] ?? array( 'title' => '', 'none' => 1 );
			}
			$need = array_slice( $need, 4 );
			mzm_data_put( $project['id'], 'clusters', $clusters );
			$job['sub']['frac'] = 1 - count( $need ) / max( 1, count( $cal['articles'] ) );
			if ( mzm_out_of_time( $deadline ) && $need ) {
				return false;
			}
		}
		$cal = mzm_calendar_build( $project, $kws, $clusters, $pillars, $inv );
	}
	mzm_data_put( $project['id'], 'calendar', $cal );
	mzm_job_log( $job, sprintf( 'تقویم محتوا: %s مقاله در %s ماه (از %s ایده).', mzm_num( count( $cal['articles'] ) ), mzm_num( $cal['meta']['months'] ), mzm_num( $cal['meta']['candidates'] ) ) );
	return true;
}

/**
 * Run summary.
 */
function mzm_step_finish( $project, &$job ) {
	$kws      = mzm_data_get( $project['id'], 'keywords', array() );
	$clusters = mzm_data_get( $project['id'], 'clusters', array() );
	$pillars  = mzm_data_get( $project['id'], 'pillars', array() );
	$removed  = mzm_data_get( $project['id'], 'removed', array() );
	$input    = mzm_data_get( $project['id'], 'input', array() );
	$cost     = max( 0, mzm_ai_usage()['cost'] - (float) $job['stats']['ai_cost0'] );
	$meta     = mzm_meta_summary( $input, $kws, $removed, $clusters, $pillars );
	$meta['ai_cost']    = round( $cost, 3 );
	$meta['serp_new']   = (int) $job['stats']['serp_new'];
	$meta['serp_cache'] = (int) $job['stats']['serp_cache'];
	$meta['serp_mode']  = mzm_serp_mode( $project );
	$meta['built']      = mzm_now();
	mzm_data_put( $project['id'], 'meta', $meta );
	mzm_job_log( $job, sprintf( 'تمام شد: %s کلاستر در %s پیلار؛ هزینه هوش مصنوعی حدود %s دلار، %s درخواست جدید نتایج گوگل.', mzm_num( count( $clusters ) ), mzm_num( count( $pillars ) ), mzm_num( $cost, 3 ), mzm_num( $meta['serp_new'] ) ) );
	return true;
}

/**
 * Write the outputs into Google Sheets (resumable).
 */
function mzm_step_gsheet( $project, &$job, $deadline ) {
	if ( empty( $job['sub']['started'] ) ) {
		mzm_data_delete( $project['id'], array( 'gsheet_job' ) );
		$job['sub']['started'] = 1;
	}
	$res = mzm_gsheet_write( $project, $deadline );
	if ( is_wp_error( $res ) ) {
		mzm_job_log( $job, 'نوشتن در گوگل شیت ناموفق بود: ' . $res->get_error_message(), 'warn' );
		mzm_data_delete( $project['id'], array( 'gsheet_job' ) );
		return true;
	}
	$job['sub']['frac'] = $res['pct'] / 100;
	if ( ! $res['done'] ) {
		return false;
	}
	mzm_job_log( $job, sprintf( 'خروجی در گوگل شیت نوشته شد (%s تب).', mzm_num( $res['tabs'] ) ) );
	return true;
}

/**
 * Summary numbers of a run (dashboard / overview).
 */
function mzm_meta_summary( $input, $kws, $removed, $clusters, $pillars ) {
	$cov   = array_count_values( array_column( $clusters, 'coverage' ) );
	$pages = array_count_values( array_column( $clusters, 'page' ) );
	$prio  = array_count_values( array_column( $clusters, 'priority' ) );
	$rev   = 0;
	$traf  = 0;
	foreach ( $clusters as $c ) {
		$rev  += $c['value']['revenue'] ?? 0;
		$traf += $c['value']['traffic'] ?? 0;
	}
	return array(
		'input'     => count( $input ),
		'keywords'  => count( $kws ),
		'removed'   => count( $removed ),
		'clusters'  => count( $clusters ),
		'pillars'   => count( $pillars ),
		'volume'    => array_sum( array_column( $kws, 'v' ) ),
		'checked'   => count( array_filter( $clusters, function ( $c ) {
			return ! empty( $c['serp'] );
		} ) ),
		'coverage'  => $cov,
		'pages'     => $pages,
		'priority'  => $prio,
		'revenue'   => $rev,
		'traffic'   => round( $traf ),
		'quick'     => count( array_filter( array_column( $clusters, 'quick_win' ) ) ),
		'issues'    => count( array_filter( $clusters, function ( $c ) {
			return ! empty( $c['issues'] );
		} ) ),
	);
}
