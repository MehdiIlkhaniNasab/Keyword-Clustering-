<?php
/**
 * The clustering pipeline: resumable steps, each run in short slices so a
 * 5,000-keyword project works on ordinary hosting (every REST call runs ~20 s,
 * the browser keeps calling until the job is done).
 *
 *   import → clean → ai_filter → precluster → serp → serp_cluster → inventory
 *   → structure → score → gap → calendar → finish (→ gsheet when auto-write is on)
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Steps and their labels.
 */
function qtb_pipeline_steps() {
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
function qtb_serp_mode( $project ) {
	$mode = (string) $project['cfg']['serp_mode'];
	if ( 'free' === $mode || ! qtb_serp_ready() ) {
		return 'free';
	}
	return 'full' === $mode ? 'full' : 'economy';
}

/**
 * Start a fresh job.
 *
 * @param string $source sheet|upload (upload: the input was stored already).
 */
function qtb_pipeline_start( $project, $source = 'sheet', $from = 'import', $extra = array() ) {
	$steps = array_keys( qtb_pipeline_steps() );
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
		'started' => qtb_now(),
		'done'    => false,
		'error'   => '',
		'stats'   => array( 'serp_new' => 0, 'serp_cache' => 0, 'ai_cost0' => qtb_ai_usage()['cost'] ),
	);
	$job = array_merge( $job, (array) $extra );
	qtb_data_put( $project['id'], 'job', $job );
	qtb_project_status( $project['id'], 'running' );
	return $job;
}

/**
 * Add a log line to the job.
 */
function qtb_job_log( &$job, $msg, $level = 'info' ) {
	$job['log'][] = array( 't' => current_time( 'H:i:s' ), 'm' => $msg, 'l' => $level );
	if ( count( $job['log'] ) > 80 ) {
		$job['log'] = array_slice( $job['log'], -80 );
	}
}

/**
 * Progress summary for the UI.
 */
function qtb_job_status( $job ) {
	$labels = qtb_pipeline_steps();
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
function qtb_pipeline_tick( $project_id, $budget = 20 ) {
	qtb_raise_limits( $budget + 120 );
	$project = qtb_project( $project_id );
	$job     = qtb_data_get( $project_id, 'job', array() );
	if ( ! $project || ! $job || ! empty( $job['done'] ) || ! empty( $job['error'] ) ) {
		return $job;
	}
	// One runner at a time per project.
	$lock = 'qtb_lock_' . $project_id;
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
			$fn  = 'qtb_step_' . $step;
			$res = $fn( $project, $job, $t0 + $budget );
			if ( is_wp_error( $res ) ) {
				$job['error'] = $res->get_error_message();
				qtb_job_log( $job, $job['error'], 'error' );
				qtb_project_status( $project_id, 'error' );
				break;
			}
			if ( true === $res ) {
				$job['at']++;
				$job['sub'] = array();
				if ( $job['at'] >= count( $job['steps'] ) ) {
					$job['done'] = true;
					qtb_project_status( $project_id, 'ready' );
				}
			}
			qtb_data_put( $project_id, 'job', $job );
		}
	} catch ( Throwable $e ) {
		$job['error'] = 'خطای داخلی: ' . $e->getMessage();
		qtb_job_log( $job, $job['error'] . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'error' );
		qtb_project_status( $project_id, 'error' );
	}
	qtb_data_put( $project_id, 'job', $job );
	delete_transient( $lock );
	return $job;
}

/**
 * Has a step's time slice run out?
 */
function qtb_out_of_time( $deadline ) {
	return microtime( true ) >= $deadline;
}

// ---------------------------------------------------------------------------
// Steps. Each returns true (done), false (call again) or WP_Error.
// ---------------------------------------------------------------------------

/**
 * Import the keywords.
 */
function qtb_step_import( $project, &$job ) {
	if ( 'upload' === $job['source'] ) {
		$input = qtb_data_get( $project['id'], 'input', array() );
		if ( ! $input ) {
			return new WP_Error( 'qtb_no_input', 'فایل کلمات بارگذاری نشده است.' );
		}
	} else {
		$input = qtb_sheet_read_keywords( $project );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		qtb_data_put( $project['id'], 'input', $input );
	}
	qtb_job_log( $job, sprintf( '%s کلمه دریافت شد.', qtb_num( count( $input ) ) ) );
	return true;
}

/**
 * Rule-based cleaning.
 */
function qtb_step_clean( $project, &$job ) {
	$res = qtb_clean_run( $project, qtb_data_get( $project['id'], 'input', array() ) );
	if ( ! $res['keywords'] ) {
		return new WP_Error( 'qtb_empty', 'بعد از پاک‌سازی کلمه‌ای باقی نماند. فهرست کلمات و تنظیمات حذف (حداقل حجم، کلمات منفی) را بررسی کنید.' );
	}
	qtb_data_put( $project['id'], 'keywords', $res['keywords'] );
	qtb_data_put( $project['id'], 'removed', $res['removed'] );
	$by = array_count_values( array_column( $res['removed'], 'r' ) );
	$parts = array();
	foreach ( $by as $r => $n ) {
		$parts[] = ( qtb_remove_reasons()[ $r ] ?? $r ) . ': ' . qtb_num( $n );
	}
	qtb_job_log( $job, sprintf( '%s کلمه ماند%s', qtb_num( count( $res['keywords'] ) ), $parts ? '؛ حذف‌شده‌ها — ' . implode( '، ', $parts ) : '' ) );
	return true;
}

/**
 * AI relevance / intent pass.
 */
function qtb_step_ai_filter( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ فیلتر هوشمند انجام نشد (قوانین پاک‌سازی اجرا شد).', 'warn' );
		return true;
	}
	$kws  = qtb_data_get( $project['id'], 'keywords', array() );
	$off  = (int) ( $job['sub']['off'] ?? 0 );
	$size = 220;
	while ( $off < count( $kws ) ) {
		$batch = array_slice( $kws, $off, $size );
		$res   = qtb_ai_filter_batch( $project, $batch );
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				qtb_job_log( $job, 'فیلتر هوشمند متوقف شد: ' . $res->get_error_message(), 'warn' );
				break;
			}
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 ) {
				qtb_job_log( $job, 'فیلتر هوشمند برای بخشی از کلمات انجام نشد: ' . $res->get_error_message(), 'warn' );
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
		qtb_data_put( $project['id'], 'keywords', $kws );
		if ( qtb_out_of_time( $deadline ) ) {
			return false;
		}
	}
	// Apply the drops and re-number.
	$removed = qtb_data_get( $project['id'], 'removed', array() );
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
	qtb_data_put( $project['id'], 'keywords', $keep );
	qtb_data_put( $project['id'], 'removed', $removed );
	qtb_job_log( $job, sprintf( 'فیلتر هوشمند: %s کلمه نامرتبط یا برند رقیب حذف شد.', qtb_num( $n ) ) );
	return true;
}

/**
 * Lexical pre-clustering.
 */
function qtb_step_precluster( $project, &$job ) {
	$kws    = qtb_data_get( $project['id'], 'keywords', array() );
	$ann    = qtb_annotate( $kws );
	$groups = qtb_precluster( $kws, array( 'full_serp' => 'full' === qtb_serp_mode( $project ), 'brands' => array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) ) );
	qtb_data_put( $project['id'], 'keywords', $kws );
	qtb_data_put( $project['id'], 'work', array( 'groups' => $groups, 'fillers' => $ann['fillers'] ) );
	qtb_job_log( $job, sprintf( '%s گروه اولیه ساخته شد (کلمات عمومی حذف‌شدنی: %s).', qtb_num( count( $groups ) ), implode( '، ', array_slice( $ann['fillers'], 0, 8 ) ) ?: '—' ) );
	return true;
}

/**
 * Look up Google's top 10.
 */
function qtb_step_serp( $project, &$job, $deadline ) {
	$mode = qtb_serp_mode( $project );
	if ( 'free' === $mode ) {
		qtb_data_put( $project['id'], 'serps', array() );
		qtb_job_log( $job, qtb_serp_ready() ? 'حالت رایگان پروژه: نتایج گوگل بررسی نشد.' : 'سرویس نتایج گوگل تنظیم نشده؛ خوشه‌بندی در حالت رایگان (زبانی + هوش مصنوعی) انجام شد.', 'warn' );
		return true;
	}
	$kws  = qtb_data_get( $project['id'], 'keywords', array() );
	$work = qtb_data_get( $project['id'], 'work', array() );
	if ( ! isset( $job['sub']['plan'] ) ) {
		$cap  = (int) ( $project['cfg']['serp_cap'] ?: qtb_setting( 'serp_max_project', 2000 ) );
		$plan = qtb_serp_plan( $kws, $work['groups'], $mode, $cap );
		// Cache first: free.
		$hit   = qtb_serp_cached_many( array_map( function ( $i ) use ( $kws ) {
			return $kws[ $i ]['k'];
		}, $plan ) );
		$serps = array();
		$todo  = array();
		foreach ( $plan as $i ) {
			$h = qtb_serp_hash( $kws[ $i ]['k'] );
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
		qtb_data_put( $project['id'], 'serps', $serps );
		qtb_job_log( $job, sprintf( '%s کلمه برای بررسی نتایج گوگل انتخاب شد (%s از کش، %s درخواست جدید).', qtb_num( count( $plan ) ), qtb_num( count( $serps ) ), qtb_num( count( $todo ) ) ) );
	}
	$serps = qtb_data_get( $project['id'], 'serps', array() );
	$todo  = $job['sub']['plan'];
	$pos   = (int) $job['sub']['pos'];
	while ( $pos < count( $todo ) ) {
		$i   = $todo[ $pos ];
		$res = qtb_serp_fetch( $kws[ $i ]['k'] );
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'qtb_serp_auth', 'qtb_serp_off' ), true ) ) {
				qtb_job_log( $job, $res->get_error_message() . ' — ادامه با نتایجی که گرفته شد.', 'warn' );
				break;
			}
			$job['sub']['fails']++;
			if ( $job['sub']['fails'] > 12 ) {
				qtb_job_log( $job, 'خطاهای پشت‌سرهم سرویس نتایج گوگل: ' . $res->get_error_message() . ' — ادامه با نتایجی که گرفته شد.', 'warn' );
				break;
			}
			++$pos;
			continue;
		}
		qtb_serp_store( $kws[ $i ]['k'], $res );
		$serps[ $i ] = $res;
		$job['stats']['serp_new']++;
		++$pos;
		if ( 0 === $pos % 10 ) {
			$job['sub']['pos']  = $pos;
			$job['sub']['frac'] = $pos / max( 1, count( $todo ) );
			qtb_data_put( $project['id'], 'serps', $serps );
		}
		if ( qtb_out_of_time( $deadline ) ) {
			$job['sub']['pos']  = $pos;
			$job['sub']['frac'] = $pos / max( 1, count( $todo ) );
			qtb_data_put( $project['id'], 'serps', $serps );
			return false;
		}
	}
	qtb_data_put( $project['id'], 'serps', $serps );
	qtb_job_log( $job, sprintf( 'نتایج گوگل %s کلمه آماده شد.', qtb_num( count( $serps ) ) ) );
	return true;
}

/**
 * SERP-overlap clustering → clusters.
 */
function qtb_step_serp_cluster( $project, &$job ) {
	$kws    = qtb_data_get( $project['id'], 'keywords', array() );
	$work   = qtb_data_get( $project['id'], 'work', array() );
	$serps  = qtb_data_get( $project['id'], 'serps', array() );
	$groups = $work['groups'];
	$before = count( $groups );
	if ( $serps && 'full' === qtb_serp_mode( $project ) ) {
		$groups = qtb_serp_cluster_kw( $kws, array_column( $kws, 'i' ), $serps, (int) $project['cfg']['strictness'] );
	} elseif ( $serps ) {
		$groups = qtb_serp_cluster( $kws, $groups, $serps, (int) $project['cfg']['strictness'] );
	}
	$clusters = qtb_clusters_build( $kws, $groups, $serps, qtb_project_domain( $project ) );
	$clusters = qtb_attach_orphans( $kws, $clusters );
	qtb_data_put( $project['id'], 'clusters_wip', $clusters );
	qtb_job_log( $job, $serps
		? sprintf( 'با نتایج گوگل %s گروه به %s کلاستر رسید (ادغام مترادف‌ها و تفکیک نیت‌های متفاوت).', qtb_num( $before ), qtb_num( count( $clusters ) ) )
		: sprintf( '%s کلاستر ساخته شد.', qtb_num( count( $clusters ) ) ) );
	return true;
}

/**
 * Site inventory.
 */
function qtb_step_inventory( $project, &$job, $deadline ) {
	$state = $job['sub']['store'] ?? array();
	while ( empty( $state['done'] ) ) {
		$state = qtb_store_step( $project, $state );
		$job['sub']['store'] = $state;
		if ( qtb_out_of_time( $deadline ) && empty( $state['done'] ) ) {
			return false;
		}
	}
	$items = (array) ( $state['items'] ?? array() );
	$sales = (array) ( $state['sales'] ?? array() );
	qtb_data_put( $project['id'], 'inventory', array( 'items' => $items, 'sales' => $sales, 'source' => $state['source'] ?? '', 'notes' => $state['notes'] ?? array(), 'at' => qtb_now() ) );
	if ( ! empty( $sales['orders'] ) ) {
		qtb_job_log( $job, sprintf( 'فروش ۹۰ روز اخیر: %s سفارش، %s، میانگین سبد %s؛ سهم ارگانیک %s.', qtb_num( $sales['orders'] ), qtb_money( $sales['revenue'] ), qtb_money( $sales['revenue'] / max( 1, $sales['orders'] ) ), qtb_pct( $sales['organic_orders'] / max( 1, $sales['orders'] ) ) ) );
	}
	foreach ( (array) ( $state['notes'] ?? array() ) as $note ) {
		qtb_job_log( $job, $note, 'warn' );
	}
	if ( $items ) {
		$by = array_count_values( array_column( $items, 'type' ) );
		qtb_job_log( $job, sprintf( 'صفحات سایت از %s: %s محصول، %s دسته، %s مقاله، %s برگه.', qtb_store_source_label( $state['source'] ), qtb_num( $by['product'] ?? 0 ), qtb_num( $by['category'] ?? 0 ), qtb_num( $by['post'] ?? 0 ), qtb_num( $by['page'] ?? 0 ) ) );
	} elseif ( 'none' === $project['cfg']['store_mode'] ) {
		qtb_job_log( $job, 'اتصال به صفحات سایت در تنظیمات پروژه خاموش است؛ همه کلاسترها «صفحه جدید» در نظر گرفته شدند.', 'warn' );
	} else {
		qtb_job_log( $job, 'به صفحات سایت دسترسی نبود؛ نگاشت به صفحات موجود و قیمت محصولات انجام نشد (بقیه تحلیل کامل است).', 'warn' );
	}
	return true;
}

/**
 * Pillars and sections (AI when available).
 */
function qtb_step_structure( $project, &$job, $deadline ) {
	$kws      = qtb_data_get( $project['id'], 'keywords', array() );
	$clusters = qtb_data_get( $project['id'], 'clusters_wip', array() ) ?: qtb_data_get( $project['id'], 'clusters', array() ); // «بازسازی ساختار» starts from the last result.
	// Workshop results come reviewed: pillars, sections and pages are the reviewer's.
	if ( qtb_ai_connected() && empty( $job['sub']['ai_off'] ) && empty( $job['ws'] ) ) {
		// Related clusters together: order by the free pillar.
		if ( ! isset( $job['sub']['order'] ) ) {
			$tmp   = qtb_pillars_free( $kws, $clusters );
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
			$res = qtb_ai_structure_batch( $project, $batch, $job['sub']['known'] );
			if ( is_wp_error( $res ) ) {
				$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
				if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
					qtb_job_log( $job, 'ساختار هوشمند انجام نشد (' . $res->get_error_message() . ')؛ ساختار از روی داده‌ها ساخته شد.', 'warn' );
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
			qtb_data_put( $project['id'], 'clusters_wip', $clusters );
			if ( qtb_out_of_time( $deadline ) && $job['sub']['off'] < count( $order ) ) {
				return false;
			}
		}
		if ( ! empty( $job['sub']['merge'] ) ) {
			$n        = count( $clusters );
			$clusters = qtb_clusters_merge_sets( $kws, $clusters, $job['sub']['merge'] );
			qtb_job_log( $job, sprintf( 'هوش مصنوعی %s کلاستر هم‌نیت (مترادف) را ادغام کرد.', qtb_num( $n - count( $clusters ) ) ) );
		}
	}
	$clusters = qtb_clusters_reindex( $kws, $clusters );
	$clusters = qtb_clusters_decide_pages( $project, $kws, $clusters );
	$built    = qtb_structure_build( $kws, $clusters );
	qtb_data_put( $project['id'], 'clusters_wip', $built['clusters'] );
	qtb_data_put( $project['id'], 'pillars_wip', $built['pillars'] );
	qtb_job_log( $job, sprintf( '%s پیلار و %s کلاستر.', qtb_num( count( $built['pillars'] ) ), qtb_num( count( $built['clusters'] ) ) ) );
	return true;
}

/**
 * Mapping, cannibalization and scoring.
 */
function qtb_step_score( $project, &$job ) {
	$kws      = qtb_data_get( $project['id'], 'keywords', array() );
	$work     = qtb_data_get( $project['id'], 'work', array() );
	$clusters = qtb_data_get( $project['id'], 'clusters_wip', array() );
	$pillars  = qtb_data_get( $project['id'], 'pillars_wip', array() );
	$inv      = (array) ( qtb_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$clusters = qtb_map_clusters( $kws, $clusters, $inv, (array) ( $work['fillers'] ?? array() ) );
	$sales    = (array) ( qtb_data_get( $project['id'], 'inventory', array() )['sales'] ?? array() );
	$clusters = qtb_score_clusters( $project, $clusters, $pillars, $inv, $sales );
	$pillars  = qtb_pillar_totals( $clusters, $pillars, $inv, $sales );
	qtb_data_put( $project['id'], 'clusters', $clusters );
	qtb_data_put( $project['id'], 'pillars', $pillars );
	// Only now the screens see the new result (never a half-built one).
	qtb_data_delete( $project['id'], array( 'clusters_wip', 'pillars_wip' ) );
	$cov = array_count_values( array_column( $clusters, 'coverage' ) );
	qtb_job_log( $job, sprintf( 'صفحه جدید لازم: %s، صفحه موجود برای بهینه‌سازی: %s، رتبه خوب: %s، برد سریع: %s.', qtb_num( $cov['new'] ?? 0 ), qtb_num( $cov['optimize'] ?? 0 ), qtb_num( $cov['ok'] ?? 0 ), qtb_num( count( array_filter( array_column( $clusters, 'quick_win' ) ) ) ) ) );
	return true;
}

/**
 * Content gap analysis of the best clusters.
 */
function qtb_step_gap( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ تحلیل شکاف محتوایی انجام نشد.', 'warn' );
		return true;
	}
	$kws      = qtb_data_get( $project['id'], 'keywords', array() );
	$clusters = qtb_data_get( $project['id'], 'clusters', array() );
	$serps    = qtb_data_get( $project['id'], 'serps', array() );
	if ( ! isset( $job['sub']['ids'] ) ) {
		$job['sub']['ids'] = qtb_gap_targets( $clusters, max( 0, (int) $project['cfg']['gap_limit'] ) );
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
			$batch[] = qtb_gap_input( $kws, $clusters[ $by_id[ $id ] ], $clusters, $serps );
		}
		$res = qtb_ai_gap_batch( $project, $batch );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				qtb_job_log( $job, 'تحلیل شکاف ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $res as $id => $gap ) {
			$clusters[ $by_id[ $id ] ]['gap'] = $gap;
		}
		$job['sub']['off'] += 3;
		$job['sub']['frac'] = $job['sub']['off'] / max( 1, count( $ids ) );
		qtb_data_put( $project['id'], 'clusters', $clusters );
		if ( qtb_out_of_time( $deadline ) && $job['sub']['off'] < count( $ids ) ) {
			return false;
		}
	}
	qtb_data_put( $project['id'], 'clusters', $clusters );
	qtb_job_log( $job, sprintf( 'تحلیل شکاف و بریف صفحه برای %s کلاستر برتر آماده شد.', qtb_num( count( array_filter( array_column( $clusters, 'gap' ) ) ) ) ) );
	return true;
}

/**
 * Content calendar (with briefs for the chosen articles).
 */
function qtb_step_calendar( $project, &$job, $deadline ) {
	$kws      = qtb_data_get( $project['id'], 'keywords', array() );
	$clusters = qtb_data_get( $project['id'], 'clusters', array() );
	$pillars  = qtb_data_get( $project['id'], 'pillars', array() );
	$serps    = qtb_data_get( $project['id'], 'serps', array() );
	$inv      = (array) ( qtb_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$cal      = qtb_calendar_build( $project, $kws, $clusters, $pillars, $inv );
	// Briefs for chosen articles that the gap step did not cover (light model).
	if ( qtb_ai_connected() && empty( $job['sub']['ai_off'] ) ) {
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
				$batch[] = qtb_gap_input( $kws, $clusters[ $by_id[ $id ] ], $clusters, $serps );
			}
			$res = qtb_ai_gap_batch( $project, $batch, 'light' );
			if ( is_wp_error( $res ) ) {
				$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
				if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
					qtb_job_log( $job, 'بریف بعضی مقاله‌ها ساخته نشد: ' . $res->get_error_message(), 'warn' );
					$job['sub']['ai_off'] = 1;
					break;
				}
				return false;
			}
			foreach ( array_slice( $need, 0, 4 ) as $id ) {
				$clusters[ $by_id[ $id ] ]['gap'] = $res[ $id ] ?? array( 'title' => '', 'none' => 1 );
			}
			$need = array_slice( $need, 4 );
			qtb_data_put( $project['id'], 'clusters', $clusters );
			$job['sub']['frac'] = 1 - count( $need ) / max( 1, count( $cal['articles'] ) );
			if ( qtb_out_of_time( $deadline ) && $need ) {
				return false;
			}
		}
		$cal = qtb_calendar_build( $project, $kws, $clusters, $pillars, $inv );
	}
	qtb_data_put( $project['id'], 'calendar', $cal );
	qtb_job_log( $job, sprintf( 'تقویم محتوا: %s مقاله در %s ماه (از %s ایده).', qtb_num( count( $cal['articles'] ) ), qtb_num( $cal['meta']['months'] ), qtb_num( $cal['meta']['candidates'] ) ) );
	return true;
}

/**
 * Run summary.
 */
function qtb_step_finish( $project, &$job ) {
	$kws      = qtb_data_get( $project['id'], 'keywords', array() );
	$clusters = qtb_data_get( $project['id'], 'clusters', array() );
	$pillars  = qtb_data_get( $project['id'], 'pillars', array() );
	$removed  = qtb_data_get( $project['id'], 'removed', array() );
	$input    = qtb_data_get( $project['id'], 'input', array() );
	$cost     = max( 0, qtb_ai_usage()['cost'] - (float) $job['stats']['ai_cost0'] );
	$meta     = qtb_meta_summary( $input, $kws, $removed, $clusters, $pillars );
	$meta['ai_cost']    = round( $cost, 3 );
	$meta['serp_new']   = (int) $job['stats']['serp_new'];
	$meta['serp_cache'] = (int) $job['stats']['serp_cache'];
	$meta['serp_mode']  = qtb_serp_mode( $project );
	$inv_data           = qtb_data_get( $project['id'], 'inventory', array() );
	$meta['store']      = array( 'source' => (string) ( $inv_data['source'] ?? '' ), 'items' => count( (array) ( $inv_data['items'] ?? array() ) ) );
	$sl                 = (array) ( $inv_data['sales'] ?? array() );
	$meta['sales']      = array( 'orders' => (int) ( $sl['orders'] ?? 0 ), 'revenue' => (float) ( $sl['revenue'] ?? 0 ), 'organic_orders' => (int) ( $sl['organic_orders'] ?? 0 ), 'organic_revenue' => (float) ( $sl['organic_revenue'] ?? 0 ) );
	$meta['built']      = qtb_now();
	qtb_data_put( $project['id'], 'meta', $meta );
	qtb_job_log( $job, sprintf( 'تمام شد: %s کلاستر در %s پیلار؛ هزینه هوش مصنوعی حدود %s دلار، %s درخواست جدید نتایج گوگل.', qtb_num( count( $clusters ) ), qtb_num( count( $pillars ) ), qtb_num( $cost, 3 ), qtb_num( $meta['serp_new'] ) ) );
	return true;
}

/**
 * Write the outputs into Google Sheets (resumable).
 */
function qtb_step_gsheet( $project, &$job, $deadline ) {
	if ( empty( $job['sub']['started'] ) ) {
		qtb_data_delete( $project['id'], array( 'gsheet_job' ) );
		$job['sub']['started'] = 1;
	}
	$res = qtb_gsheet_write( $project, $deadline );
	if ( is_wp_error( $res ) ) {
		qtb_job_log( $job, 'نوشتن در گوگل شیت ناموفق بود: ' . $res->get_error_message(), 'warn' );
		qtb_data_delete( $project['id'], array( 'gsheet_job' ) );
		return true;
	}
	$job['sub']['frac'] = $res['pct'] / 100;
	if ( ! $res['done'] ) {
		return false;
	}
	qtb_job_log( $job, sprintf( 'خروجی در گوگل شیت نوشته شد (%s تب).', qtb_num( $res['tabs'] ) ) );
	foreach ( $res['errors'] as $e ) {
		qtb_job_log( $job, 'هشدار گوگل شیت: ' . $e, 'warn' );
	}
	return true;
}

/**
 * Summary numbers of a run (dashboard / overview).
 */
function qtb_meta_summary( $input, $kws, $removed, $clusters, $pillars ) {
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
