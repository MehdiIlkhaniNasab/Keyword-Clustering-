<?php
/**
 * The clustering workshop: a reviewed, step-by-step clustering.
 *
 *   1. Triage    — keep / remove / park every keyword (rules + AI + bulk review).
 *   2. Topics    — split the kept keywords by what the customer buys or learns.
 *   3. Per topic — SERP of every keyword → keyword-level SERP clustering → page
 *                  decision → AI review of every keyword (batch or one by one) and
 *                  of every cluster → the reviewer moves, merges, renames, comments
 *                  and approves. Comments become project rules; corrections become
 *                  examples the AI learns from in the next topics.
 *   4. Finalize  — approved topics become the research: structure, scores, gaps,
 *                  calendar and exports (the normal pipeline from «structure» on).
 *
 * State lives in the «ws» payload; long work runs as a resumable «ws_job».
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Workshop state of a project.
 */
function qtb_ws_get( $pid ) {
	return wp_parse_args( qtb_data_get( $pid, 'ws', array() ), array(
		'stage'       => 'start', // start|triage|topics|review.
		'topics'      => array(),
		'rules'       => array(),
		'corrections' => array(),
		'next_tid'    => 1,
		'next_cid'    => 1,
		'next_rid'    => 1,
		'finalized'   => '',
	) );
}

/**
 * Save the workshop state.
 */
function qtb_ws_put( $pid, $ws ) {
	qtb_data_put( $pid, 'ws', $ws );
}

/**
 * Index of a topic in the state (or null).
 */
function qtb_ws_topic_index( $ws, $tid ) {
	foreach ( $ws['topics'] as $ti => $t ) {
		if ( (int) $t['id'] === (int) $tid ) {
			return $ti;
		}
	}
	return null;
}

/**
 * Status labels.
 */
function qtb_ws_topic_states() {
	return array(
		'new'      => 'در انتظار پردازش',
		'running'  => 'در حال پردازش',
		'ready'    => 'آماده بازبینی',
		'approved' => 'تأیید شد',
	);
}

/**
 * Does a keyword match a rule phrase (whole words)?
 */
function qtb_ws_rule_hit( $kw, $match ) {
	$m = qtb_norm_key( $match );
	return '' !== $m && false !== mb_strpos( ' ' . $kw['nk'] . ' ', ' ' . $m . ' ' );
}

/**
 * Apply keep / remove / park rules to keywords the reviewer did not decide by hand.
 *
 * @return int keywords changed
 */
function qtb_ws_apply_rules( &$kws, $rules ) {
	$n = 0;
	foreach ( $kws as &$kw ) {
		if ( ! empty( $kw['tl'] ) ) {
			continue;
		}
		foreach ( $rules as $r ) {
			if ( ! in_array( $r['kind'], array( 'remove', 'keep', 'park' ), true ) || ! qtb_ws_rule_hit( $kw, $r['match'] ) ) {
				continue;
			}
			$st = 'remove' === $r['kind'] ? 'remove' : ( 'park' === $r['kind'] ? 'park' : 'keep' );
			if ( ( $kw['ws'] ?? '' ) !== $st ) {
				++$n;
			}
			$kw['ws'] = $st;
			$kw['tr'] = 'rule';
			$kw['tn'] = $r['text'];
		}
	}
	unset( $kw );
	return $n;
}

/**
 * Keywords that go on to clustering (kept or still undecided).
 */
function qtb_ws_active( $kws ) {
	return array_filter( $kws, function ( $kw ) {
		return in_array( $kw['ws'] ?? 'keep', array( 'keep', 'unsure' ), true );
	} );
}

// ---------------------------------------------------------------------------
// Jobs.
// ---------------------------------------------------------------------------

/**
 * Start a workshop job.
 *
 * @param string $action triage|topics|topic.
 */
function qtb_ws_start( $project, $action, $args = array() ) {
	$job = array_merge( array(
		'action'  => $action,
		'step'    => 0,
		'sub'     => array(),
		'log'     => array(),
		'done'    => false,
		'error'   => '',
		'started' => qtb_now(),
		'touched' => time(),
		't0'      => microtime( true ),
		'cost0'   => qtb_ai_usage()['cost'],
		'req0'    => qtb_ai_usage()['requests'],
	), $args );
	if ( 'topic' === $action ) {
		$job['engine'] = 'classic' === ( $project['cfg']['engine'] ?? 'facet' ) ? 'classic' : 'facet';
	}
	qtb_data_put( $project['id'], 'ws_job', $job );
	if ( 'topic' === $action ) {
		$ws = qtb_ws_get( $project['id'] );
		$ti = qtb_ws_topic_index( $ws, $args['tid'] );
		if ( null !== $ti ) {
			$ws['topics'][ $ti ]['status'] = 'running';
			qtb_ws_put( $project['id'], $ws );
		}
	}
	return $job;
}

/**
 * Steps of a job.
 */
function qtb_ws_steps( $action, $engine = '' ) {
	if ( 'topic' === $action && 'facet' === $engine ) {
		return array(
			'facets'   => 'فرهنگ ویژگی‌ها (واژه‌های تازه)',
			'plan'     => 'درخت صفحات و جفت‌های مرزی',
			'serp'     => 'نتایج گوگل جفت‌های مرزی',
			'fcluster' => 'ادغام یا جدا شدن (آستانه + گوگل + رقبا)',
			'ai_cl'    => 'بازبینی نوع صفحه و نام کلاسترها',
			'finish'   => 'جمع‌بندی',
		);
	}
	$s = array(
		'triage' => array( 'import' => 'دریافت کلمات', 'clean' => 'پاک‌سازی قانونی', 'inventory' => 'خواندن محصولات و صفحات سایت', 'ai' => 'غربال هوشمند (نگه دار / حذف / نیاز به تصمیم)' ),
		'topics' => array( 'groups' => 'گروه‌بندی زبانی', 'ai' => 'تقسیم موضوعی هوشمند', 'build' => 'ساخت موضوع‌ها' ),
		'add'    => array( 'ai' => 'غربال هوشمند کلمات جدید', 'assign' => 'پیدا کردن موضوع هر کلمه جدید' ),
		'facets' => array( 'build' => 'نقش‌گذاری واژه‌ها و نوع‌های ویژگی' ),
		'topic'  => array( 'serp' => 'بررسی ۱۰ نتیجه اول گوگل همه کلمات', 'cluster' => 'خوشه‌بندی بر اساس اشتراک نتایج', 'ai_kw' => 'بازبینی هوشمند تک‌تک کلمات', 'ai_cl' => 'بازبینی نوع صفحه و نام کلاسترها', 'finish' => 'جمع‌بندی' ),
	);
	return $s[ $action ] ?? array();
}

/**
 * Status of a job for the UI.
 */
function qtb_ws_job_status( $job ) {
	$steps = array_keys( qtb_ws_steps( $job['action'] ?? '', $job['engine'] ?? '' ) );
	$lab   = qtb_ws_steps( $job['action'] ?? '', $job['engine'] ?? '' );
	$at    = (int) ( $job['step'] ?? 0 );
	$frac  = (float) ( $job['sub']['frac'] ?? 0 );
	return array(
		'action' => (string) ( $job['action'] ?? '' ),
		'tid'    => (int) ( $job['tid'] ?? 0 ),
		'done'   => ! empty( $job['done'] ),
		'error'  => (string) ( $job['error'] ?? '' ),
		'paused' => ! empty( $job['paused'] ) ? $job['paused'] : null,
		'label'  => $lab[ $steps[ $at ] ?? '' ] ?? 'پایان',
		'pct'    => ! empty( $job['done'] ) ? 100 : (int) min( 99, round( 100 * ( $at + $frac ) / max( 1, count( $steps ) ) ) ),
		'log'    => array_slice( (array) ( $job['log'] ?? array() ), -10 ),
		'steps'  => array_map( function ( $k, $i ) use ( $lab, $at, $job ) {
			return array( 'label' => $lab[ $k ], 'state' => $i < $at || ! empty( $job['done'] ) ? 'done' : ( $i === $at ? 'now' : 'todo' ) );
		}, $steps, array_keys( $steps ) ),
	);
}

/**
 * Run the current workshop job for a time slice.
 */
function qtb_ws_tick( $pid, $budget = 20 ) {
	qtb_raise_limits( $budget + 120 );
	$project = qtb_project( $pid );
	$job     = qtb_data_get( $pid, 'ws_job', array() );
	if ( ! $project || ! $job || ! empty( $job['done'] ) || ! empty( $job['error'] ) || ! empty( $job['paused'] ) ) {
		return $job;
	}
	$lock = 'qtb_wslock_' . $pid;
	if ( get_transient( $lock ) ) {
		return $job;
	}
	set_transient( $lock, 1, $budget + 90 );
	$deadline = microtime( true ) + $budget;
	$steps    = array_keys( qtb_ws_steps( $job['action'], $job['engine'] ?? '' ) );
	try {
		while ( empty( $job['done'] ) && empty( $job['error'] ) && empty( $job['paused'] ) && microtime( true ) < $deadline ) {
			if ( qtb_job_stop_asked( $pid, 'ws_job', $job ) ) {
				break;
			}
			$step = $steps[ $job['step'] ] ?? null;
			if ( null === $step ) {
				$job['done'] = true;
				break;
			}
			$fn  = 'qtb_ws_' . $job['action'] . '_' . $step;
			$res = $fn( $project, $job, $deadline );
			if ( is_wp_error( $res ) ) {
				$job['error'] = $res->get_error_message();
				qtb_job_log( $job, $job['error'], 'error' );
				break;
			}
			if ( true === $res ) {
				$job['step']++;
				$job['sub'] = array();
				if ( $job['step'] >= count( $steps ) ) {
					$job['done'] = true;
				}
			}
			qtb_data_put( $pid, 'ws_job', $job );
		}
	} catch ( Throwable $e ) {
		$job['error'] = 'خطای داخلی: ' . $e->getMessage();
		qtb_job_log( $job, $job['error'] . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')', 'error' );
	}
	qtb_job_stop_asked( $pid, 'ws_job', $job );
	$job['touched'] = time();
	if ( ! empty( $job['error'] ) && 'topic' === $job['action'] ) {
		$ws = qtb_ws_get( $pid );
		$ti = qtb_ws_topic_index( $ws, $job['tid'] );
		if ( null !== $ti && 'running' === $ws['topics'][ $ti ]['status'] ) {
			$ws['topics'][ $ti ]['status'] = $ws['topics'][ $ti ]['clusters'] ? 'ready' : 'new';
			qtb_ws_put( $pid, $ws );
		}
	}
	qtb_data_put( $pid, 'ws_job', $job );
	delete_transient( $lock );
	return $job;
}

// ---------------------------------------------------------------------------
// 1. Triage.
// ---------------------------------------------------------------------------

/**
 * Import the keywords.
 */
function qtb_ws_triage_import( $project, &$job ) {
	$pj = array( 'source' => $job['source'] ?? 'sheet', 'log' => array() );
	$r  = qtb_step_import( $project, $pj );
	$job['log'] = array_merge( $job['log'], $pj['log'] );
	return $r;
}

/**
 * Rule cleaning (duplicates, marketplaces, junk, negatives) and annotation.
 */
function qtb_ws_triage_clean( $project, &$job ) {
	// SERPs already paid for are kept by keyword text: the new list numbers keywords anew.
	$old_kws   = qtb_data_get( $project['id'], 'keywords', array() );
	$old_serps = qtb_data_get( $project['id'], 'serps', array() );
	$by_text   = array();
	foreach ( $old_serps as $i => $s ) {
		if ( isset( $old_kws[ $i ] ) ) {
			$by_text[ qtb_key( $old_kws[ $i ]['k'] ) ] = $s;
		}
	}
	$pj = array( 'log' => array() );
	$r  = qtb_step_clean( $project, $pj );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$serps = array();
	foreach ( qtb_data_get( $project['id'], 'keywords', array() ) as $i => $kw ) {
		if ( isset( $by_text[ qtb_key( $kw['k'] ) ] ) ) {
			$serps[ $i ] = $by_text[ qtb_key( $kw['k'] ) ];
		}
	}
	qtb_data_put( $project['id'], 'serps', $serps );
	if ( $serps ) {
		qtb_job_log( $job, sprintf( 'نتایج گوگل %s کلمه از قبل موجود بود و نگه داشته شد (دوباره خریده نمی‌شود).', qtb_num( count( $serps ) ) ) );
	}
	$job['log'] = array_merge( $job['log'], $pj['log'] );
	$kws        = qtb_data_get( $project['id'], 'keywords', array() );
	qtb_annotate( $kws );
	$own = 0;
	foreach ( $kws as &$kw ) {
		$kw['ws'] = 'keep';
		$kw['tr'] = 'ok';
		$kw['tn'] = '';
		if ( ! empty( $kw['imp']['c'] ) ) {
			$kw['tl'] = 1;
			$kw['tn'] = 'در کلاستر شما';
			++$own;
		}
	}
	unset( $kw );
	if ( $own ) {
		qtb_job_log( $job, sprintf( '%s کلمه در کلاسترهای آماده شما بود؛ این‌ها غربال و خوشه‌بندی دوباره نمی‌شوند.', qtb_num( $own ) ) );
	}
	$ws = qtb_ws_get( $project['id'] );
	$n  = qtb_ws_apply_rules( $kws, $ws['rules'] );
	if ( $n ) {
		qtb_job_log( $job, sprintf( 'قوانین پروژه روی %s کلمه اعمال شد.', qtb_num( $n ) ) );
	}
	qtb_data_put( $project['id'], 'keywords', $kws );
	// A new keyword list: the old topics no longer apply.
	$ws['stage']  = 'triage';
	$ws['topics'] = array();
	qtb_ws_put( $project['id'], $ws );
	return true;
}

/**
 * Site inventory (for the business profile and the page decisions).
 */
function qtb_ws_triage_inventory( $project, &$job, $deadline ) {
	$pj = array( 'log' => array(), 'sub' => $job['sub'] );
	$r  = qtb_step_inventory( $project, $pj, $deadline );
	$job['sub'] = $pj['sub'];
	if ( true === $r ) {
		$job['log'] = array_merge( $job['log'], $pj['log'] );
	}
	return $r;
}

/**
 * AI triage in batches of 150.
 */
function qtb_ws_triage_ai( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ فقط قوانین اعمال شد. کلمات را در جدول غربال بررسی کنید.', 'warn' );
		return true;
	}
	$kws  = qtb_data_get( $project['id'], 'keywords', array() );
	$ws   = qtb_ws_get( $project['id'] );
	$todo = array_values( array_filter( $kws, function ( $kw ) {
		return empty( $kw['tl'] ) && 'rule' !== ( $kw['tr'] ?? '' ) && ( empty( $job['only_new'] ) || ! empty( $kw['fresh'] ) );
	} ) );
	$off  = (int) ( $job['sub']['off'] ?? 0 );
	while ( $off < count( $todo ) ) {
		$batch = array_slice( $todo, $off, 150 );
		$res   = qtb_ai_triage_batch( $project, $ws, array_map( function ( $kw ) {
			return array( 'i' => $kw['i'], 'k' => $kw['k'], 'v' => $kw['v'] );
		}, $batch ) );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				qtb_job_log( $job, 'غربال هوشمند متوقف شد: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $res as $i => $d ) {
			if ( ! isset( $kws[ $i ] ) ) {
				continue;
			}
			$kws[ $i ]['ws']        = 'remove' === $d['label'] ? 'remove' : ( 'unsure' === $d['label'] ? 'unsure' : 'keep' );
			$kws[ $i ]['tr']        = 'keep' === $d['label'] ? 'ok' : ( 'unsure' === $d['label'] && 'ok' === $d['reason'] ? 'unsure' : $d['reason'] );
			$kws[ $i ]['tn']        = $d['note'];
			$kws[ $i ]['ai_intent'] = $d['intent'];
			if ( '' !== $d['brand'] ) {
				$kws[ $i ]['brand_tok'] = $d['brand'];
			}
		}
		$off               += 150;
		$job['sub']['off']  = $off;
		$job['sub']['frac'] = min( 1, $off / max( 1, count( $todo ) ) );
		qtb_data_put( $project['id'], 'keywords', $kws );
		if ( microtime( true ) >= $deadline && $off < count( $todo ) ) {
			return false;
		}
	}
	$by = array_count_values( array_map( function ( $kw ) {
		return $kw['ws'] ?? 'keep';
	}, $kws ) );
	qtb_job_log( $job, sprintf( 'غربال: %s نگه داشته، %s حذف، %s نیاز به تصمیم شما.', qtb_num( $by['keep'] ?? 0 ), qtb_num( $by['remove'] ?? 0 ), qtb_num( $by['unsure'] ?? 0 ) ) );
	return true;
}

// ---------------------------------------------------------------------------
// 2. Topics.
// ---------------------------------------------------------------------------

/**
 * Lexical groups of the active keywords.
 */
function qtb_ws_topics_groups( $project, &$job ) {
	$kws    = qtb_data_get( $project['id'], 'keywords', array() );
	$active = qtb_ws_active( $kws );
	if ( ! $active ) {
		return new WP_Error( 'qtb_ws_empty', 'هیچ کلمه نگه‌داشته‌ای نیست؛ در مرحله غربال کلمات را بررسی کنید.' );
	}
	// The SEO manager's own clusters stay whole; the rest is grouped by words.
	$own  = array();
	$rest = array();
	foreach ( $active as $i => $kw ) {
		if ( ! empty( $kw['imp']['c'] ) ) {
			$own[ $kw['imp']['c'] ][] = $i;
		} else {
			$rest[ $i ] = $kw;
		}
	}
	$groups = $rest ? qtb_precluster( $rest, array( 'brands' => array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) ) ) : array();
	foreach ( $own as $ck => $members ) {
		$g        = qtb_group_make( $active, $members );
		$g['imp'] = $ck;
		$groups[] = $g;
	}
	// New keywords that share words with the manager's clusters belong to the same pillar.
	$hint = array();
	if ( $own && $rest ) {
		foreach ( qtb_precluster( $active, array( 'brands' => array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) ) ) as $lg ) {
			$ps = array();
			foreach ( $lg['kws'] as $m ) {
				if ( ! empty( $kws[ $m ]['imp']['p'] ) ) {
					$ps[ $kws[ $m ]['imp']['p'] ] = ( $ps[ $kws[ $m ]['imp']['p'] ] ?? 0 ) + 1;
				}
			}
			if ( $ps ) {
				arsort( $ps );
				foreach ( $lg['kws'] as $m ) {
					$hint[ $m ] = (string) key( $ps );
				}
			}
		}
	}
	// Pillar from the file (the most common one among the members).
	foreach ( $groups as &$g ) {
		$ps = array();
		foreach ( $g['kws'] as $m ) {
			$mp = (string) ( $kws[ $m ]['imp']['p'] ?? ( $hint[ $m ] ?? '' ) );
			if ( '' !== $mp ) {
				$ps[ $mp ] = ( $ps[ $mp ] ?? 0 ) + 1;
			}
		}
		arsort( $ps );
		$g['p'] = $ps ? (string) key( $ps ) : '';
	}
	unset( $g );
	$groups = qtb_sort( $groups, 'vol' );
	foreach ( $groups as $gi => &$g ) {
		$g['gid'] = $gi;
	}
	unset( $g );
	// Free topic of each group (fallback and ordering).
	$free = qtb_pillars_free( $kws, array_map( function ( $g ) use ( $kws ) {
		return array( 'core' => $g['core'], 'vol' => $g['vol'], 'kws' => $g['kws'], 'name' => $kws[ $g['head'] ]['k'], 'page' => 'archive' );
	}, $groups ) );
	foreach ( $groups as $gi => &$g ) {
		$g['free'] = $free[ $gi ]['pillar_key'];
	}
	unset( $g );
	qtb_data_put( $project['id'], 'ws_groups', $groups );
	qtb_job_log( $job, sprintf( '%s کلمه در %s گروه زبانی.', qtb_num( count( $active ) ), qtb_num( count( $groups ) ) ) );
	return true;
}

/**
 * AI topic of each group (batches of 150 groups, related groups together).
 */
function qtb_ws_topics_ai( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ موضوع‌ها از روی داده‌ها ساخته شد.', 'warn' );
		return true;
	}
	$kws    = qtb_data_get( $project['id'], 'keywords', array() );
	$groups = qtb_data_get( $project['id'], 'ws_groups', array() );
	$ws     = qtb_ws_get( $project['id'] );
	if ( ! isset( $job['sub']['order'] ) ) {
		$order = array_values( array_filter( array_keys( $groups ), function ( $gi ) use ( $groups ) {
			return '' === ( $groups[ $gi ]['p'] ?? '' );
		} ) );
		usort( $order, function ( $a, $b ) use ( $groups ) {
			return strcmp( $groups[ $a ]['free'], $groups[ $b ]['free'] ) ?: ( $groups[ $b ]['vol'] <=> $groups[ $a ]['vol'] );
		} );
		$job['sub']['order'] = $order;
		$job['sub']['off']   = 0;
		$job['sub']['known'] = array_values( array_unique( array_filter( array_column( $groups, 'p' ) ) ) );
		$job['sub']['map']   = array();
	}
	$order = $job['sub']['order'];
	while ( $job['sub']['off'] < count( $order ) ) {
		$batch = array();
		foreach ( array_slice( $order, $job['sub']['off'], 150 ) as $gi ) {
			$g       = $groups[ $gi ];
			$batch[] = array(
				'gid'  => $g['gid'],
				'head' => $kws[ $g['head'] ]['k'],
				'vol'  => $g['vol'],
				'more' => array_map( function ( $m ) use ( $kws ) {
					return $kws[ $m ]['k'];
				}, array_slice( $g['kws'], 1, 4 ) ),
			);
		}
		$res = qtb_ai_topics_batch( $project, $ws, $batch, $job['sub']['known'] );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				qtb_job_log( $job, 'تقسیم موضوعی هوشمند ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $res as $gid => $topic ) {
			$job['sub']['map'][ $gid ] = $topic;
			if ( ! in_array( $topic, $job['sub']['known'], true ) ) {
				$job['sub']['known'][] = $topic;
			}
		}
		$job['sub']['off'] += 150;
		$job['sub']['frac'] = min( 1, $job['sub']['off'] / max( 1, count( $order ) ) );
		if ( microtime( true ) >= $deadline && $job['sub']['off'] < count( $order ) ) {
			return false;
		}
	}
	qtb_data_put( $project['id'], 'ws_topic_map', $job['sub']['map'] );
	return true;
}

/**
 * Build the topics.
 */
function qtb_ws_topics_build( $project, &$job ) {
	$kws    = qtb_data_get( $project['id'], 'keywords', array() );
	$groups = qtb_data_get( $project['id'], 'ws_groups', array() );
	$map    = qtb_data_get( $project['id'], 'ws_topic_map', array() );
	$ws     = qtb_ws_get( $project['id'] );
	// Free names: the biggest group of each free key.
	$fname = array();
	foreach ( $groups as $g ) {
		if ( ! isset( $fname[ $g['free'] ] ) ) {
			$fname[ $g['free'] ] = '*' === $g['free'] ? 'متفرقه' : $kws[ $g['head'] ]['k'];
		}
	}
	$topics = array();
	$fixed = array(); // Pillars from the file are never folded away.
	foreach ( $groups as $g ) {
		$name = '' !== ( $g['p'] ?? '' ) ? $g['p'] : ( $map[ $g['gid'] ] ?? $fname[ $g['free'] ] );
		if ( ! isset( $topics[ $name ] ) ) {
			$topics[ $name ] = array( 'kws' => array(), 'vol' => 0, 'own' => array() );
		}
		$topics[ $name ]['kws'] = array_merge( $topics[ $name ]['kws'], $g['kws'] );
		$topics[ $name ]['vol'] += $g['vol'];
		if ( ! empty( $g['imp'] ) ) {
			$topics[ $name ]['own'][] = $g;
		}
		if ( '' !== ( $g['p'] ?? '' ) || ! empty( $g['imp'] ) ) {
			$fixed[ $name ] = 1;
		}
	}
	// Tiny topics (≤ 3 keywords) go to «متفرقه».
	foreach ( $topics as $name => $t ) {
		if ( count( $t['kws'] ) <= 3 && 'متفرقه' !== $name && count( $topics ) > 3 && ! isset( $fixed[ $name ] ) ) {
			if ( ! isset( $topics['متفرقه'] ) ) {
				$topics['متفرقه'] = array( 'kws' => array(), 'vol' => 0, 'own' => array() );
			}
			$topics['متفرقه']['kws'] = array_merge( $topics['متفرقه']['kws'], $t['kws'] );
			$topics['متفرقه']['vol'] += $t['vol'];
			unset( $topics[ $name ] );
		}
	}
	uasort( $topics, function ( $a, $b ) {
		return $b['vol'] <=> $a['vol'];
	} );
	$ws['topics'] = array();
	$own_n        = 0;
	foreach ( $topics as $name => $t ) {
		$topic = qtb_ws_new_topic( $ws, $name, $t['kws'], $project );
		if ( $t['own'] ) {
			$topic   = qtb_ws_topic_with_own( $project, $ws, $kws, $topic, $t['own'] );
			$own_n  += count( $t['own'] );
		}
		$ws['topics'][] = $topic;
	}
	if ( $own_n ) {
		qtb_job_log( $job, sprintf( '%s کلاستر آماده شما با همان بخش‌ها وارد موضوع‌ها شد (قفل).', qtb_num( $own_n ) ) );
	}
	$ws['stage'] = 'topics';
	qtb_ws_put( $project['id'], $ws );
	qtb_data_delete( $project['id'], array( 'ws_groups', 'ws_topic_map' ) );
	qtb_job_log( $job, sprintf( '%s موضوع ساخته شد. هر موضوع را جدا پردازش و بازبینی کنید.', qtb_num( count( $ws['topics'] ) ) ) );
	return true;
}

/**
 * A new topic record.
 */
function qtb_ws_new_topic( &$ws, $name, $kws, $project ) {
	return array(
		'id'       => $ws['next_tid']++,
		'name'     => $name,
		'kws'      => array_values( array_unique( array_map( 'intval', $kws ) ) ),
		'status'   => 'new',
		'mode'     => 'single' === ( $project['cfg']['review_mode'] ?? 'batch' ) ? 'single' : 'batch',
		'clusters' => array(),
		'metrics'  => array(),
		'history'  => array(),
		'comments' => array(),
	);
}

/**
 * The SEO manager's own clusters of a topic: locked, with their section; a topic made
 * only of them is ready for review right away.
 */
function qtb_ws_topic_with_own( $project, &$ws, $kws, $topic, $own ) {
	$in = array();
	foreach ( $own as $g ) {
		$sec  = '';
		$name = '';
		foreach ( $g['kws'] as $m ) {
			$sec  = $sec ?: (string) ( $kws[ $m ]['imp']['s'] ?? '' );
			$name = $name ?: (string) ( $kws[ $m ]['imp']['cn'] ?? '' );
		}
		$g['meta'] = array_fill_keys( $g['kws'], array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' ) );
		$c         = qtb_ws_make_cluster( $ws, $kws, $g, array(), '' );
		$c['locked'] = 1;
		$c['own']    = 1;
		if ( '' !== $name ) {
			$c['name']        = $name;
			$c['locked_name'] = 1;
		}
		if ( '' !== $sec ) {
			$c['section']        = $sec;
			$c['locked_section'] = 1;
			// The section title says the page: «وبلاگ / مقالات» → article, «لندینگ» → landing.
			$sp = preg_match( '/وبلاگ|بلاگ|مقاله|مقالات|blog|آموزش|راهنما/iu', $sec ) ? 'article' : ( preg_match( '/لندینگ|landing/iu', $sec ) ? 'landing' : '' );
			if ( '' !== $sp ) {
				$c['page']        = $sp;
				$c['archive']     = '';
				$c['locked_page'] = 1;
				$c['page_reason'] = 'بخش «' . $sec . '» در فایل شما';
			}
		}
		$topic['clusters'][] = $c;
		$in                  = array_merge( $in, $g['kws'] );
	}
	$topic['clusters'] = qtb_ws_decide_pages( $project, $ws, $kws, $topic['clusters'] );
	$topic['clusters'] = qtb_ws_tidy( $kws, $topic['clusters'] );
	if ( ! array_diff( $topic['kws'], $in ) ) {
		$topic['status'] = 'ready';
	}
	return $topic;
}

// ---------------------------------------------------------------------------
// 3. One topic.
// ---------------------------------------------------------------------------

/**
 * SERP of every keyword of the topic (cache first).
 */
function qtb_ws_topic_serp( $project, &$job, $deadline ) {
	if ( ! qtb_serp_ready() || 'free' === $project['cfg']['serp_mode'] ) {
		qtb_job_log( $job, 'سرویس نتایج گوگل وصل نیست؛ این موضوع فقط با معنا و هوش مصنوعی خوشه‌بندی می‌شود (دقت کمتر).', 'warn' );
		return true;
	}
	$ws  = qtb_ws_get( $project['id'] );
	$t   = $ws['topics'][ qtb_ws_topic_index( $ws, $job['tid'] ) ];
	$kws = qtb_data_get( $project['id'], 'keywords', array() );
	if ( ! isset( $job['sub']['todo'] ) ) {
		$serps = qtb_data_get( $project['id'], 'serps', array() );
		// Locked clusters (yours, or already reviewed) need only their top members, to compare new keywords with.
		$skip = array();
		foreach ( $t['clusters'] as $c ) {
			if ( ! empty( $c['locked'] ) ) {
				foreach ( array_slice( $c['kws'], 3 ) as $i ) {
					$skip[ $i ] = 1;
				}
			}
		}
		// Facet engine: only the keywords whose SERP settles a split / merge decision.
		$pool  = isset( $job['serp_want'] ) ? array_values( array_intersect( (array) $job['serp_want'], $t['kws'] ) ) : $t['kws'];
		$want  = array_values( array_filter( $pool, function ( $i ) use ( $serps, $kws, $skip ) {
			return isset( $kws[ $i ] ) && ! isset( $serps[ $i ] ) && ! isset( $skip[ $i ] ) && in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'keep', 'unsure' ), true );
		} ) );
		$hit   = qtb_serp_cached_many( array_map( function ( $i ) use ( $kws ) {
			return $kws[ $i ]['k'];
		}, $want ) );
		$todo  = array();
		foreach ( $want as $i ) {
			$h = qtb_serp_hash( $kws[ $i ]['k'] );
			if ( isset( $hit[ $h ] ) ) {
				$serps[ $i ] = $hit[ $h ];
			} else {
				$todo[] = $i;
			}
		}
		$cap = (int) ( $project['cfg']['serp_cap'] ?: qtb_setting( 'serp_max_project', 2000 ) );
		if ( ! isset( $job['serp_want'] ) ) {
			usort( $todo, function ( $a, $b ) use ( $kws ) {
				return $kws[ $b ]['v'] <=> $kws[ $a ]['v'];
			} );
		}
		$job['sub']['todo']  = array_slice( $todo, 0, max( 0, $cap ) );
		$job['sub']['pos']   = 0;
		$job['sub']['cache'] = count( $want ) - count( $todo );
		$job['sub']['new']   = 0;
		qtb_data_put( $project['id'], 'serps', $serps );
		qtb_job_log( $job, sprintf( '%s کلمه نیاز به نتایج گوگل دارد%s: %s از کش، %s درخواست جدید.', qtb_num( count( $want ) ), $skip ? sprintf( ' (%s کلمه کلاسترهای قفل نیازی ندارند)', qtb_num( count( $skip ) ) ) : '', qtb_num( $job['sub']['cache'] ), qtb_num( count( $job['sub']['todo'] ) ) ) );
	}
	$serps = qtb_data_get( $project['id'], 'serps', array() );
	$todo  = $job['sub']['todo'];
	$fails = 0;
	// Browser route: the reviewer's browser fetches the results (the server cannot reach
	// the SERP service) and sends them back; the step resumes when they are all here.
	if ( 'browser' === qtb_serp_route() ) {
		return qtb_ws_serp_browser_wait( $project, $job, $kws, $serps, array_slice( $todo, (int) $job['sub']['pos'] ) );
	}
	while ( $job['sub']['pos'] < count( $todo ) ) {
		$i   = $todo[ $job['sub']['pos'] ];
		$res = qtb_serp_fetch( $kws[ $i ]['k'] );
		$job['sub']['pos']++;
		if ( is_wp_error( $res ) ) {
			if ( in_array( $res->get_error_code(), array( 'qtb_serp_auth', 'qtb_serp_off' ), true ) || ++$fails > 8 ) {
				if ( ! empty( $job['skip_serp'] ) ) {
					qtb_job_log( $job, $res->get_error_message() . ' — ادامه با نتایجی که گرفته شد (به انتخاب شما).', 'warn' );
					break;
				}
				// Back to this keyword; resume after the key / proxy is fixed.
				$job['sub']['pos']--;
				qtb_data_put( $project['id'], 'serps', $serps );
				qtb_job_pause( $job, 'serp', 'نتایج گوگل متوقف شد: ' . $res->get_error_message() );
				return false;
			}
			continue;
		}
		qtb_serp_store( $kws[ $i ]['k'], $res );
		$serps[ $i ] = $res;
		$job['sub']['new']++;
		$job['sub']['frac'] = $job['sub']['pos'] / max( 1, count( $todo ) );
		if ( microtime( true ) >= $deadline ) {
			qtb_data_put( $project['id'], 'serps', $serps );
			return false;
		}
	}
	qtb_data_put( $project['id'], 'serps', $serps );
	$job['m_serp_new']   = (int) ( $job['sub']['new'] ?? 0 );
	$job['m_serp_cache'] = (int) ( $job['sub']['cache'] ?? 0 );
	return true;
}

/**
 * Browser route of the SERP step: what is still missing is handed to the browser.
 *
 * @return bool true when nothing is missing (or the reviewer chose to go on without it).
 */
function qtb_ws_serp_browser_wait( $project, &$job, $kws, $serps, $todo ) {
	$left = array_values( array_filter( $todo, function ( $i ) use ( $serps, $kws ) {
		return isset( $kws[ $i ] ) && ! isset( $serps[ $i ] );
	} ) );
	if ( $left ) {
		$hit = qtb_serp_cached_many( array_map( function ( $i ) use ( $kws ) {
			return $kws[ $i ]['k'];
		}, $left ) );
		foreach ( $left as $n => $i ) {
			$h = qtb_serp_hash( $kws[ $i ]['k'] );
			if ( isset( $hit[ $h ] ) ) {
				$serps[ $i ] = $hit[ $h ];
				unset( $left[ $n ] );
				$job['sub']['new'] = (int) ( $job['sub']['new'] ?? 0 ) + 1;
			}
		}
		qtb_data_put( $project['id'], 'serps', $serps );
	}
	if ( ! $left || ! empty( $job['skip_serp'] ) ) {
		if ( $left ) {
			qtb_job_log( $job, sprintf( 'ادامه بدون نتایج گوگل %s کلمه (به انتخاب شما).', qtb_num( count( $left ) ) ), 'warn' );
		}
		$job['m_serp_new']   = (int) ( $job['sub']['new'] ?? 0 );
		$job['m_serp_cache'] = (int) ( $job['sub']['cache'] ?? 0 );
		return true;
	}
	$c = qtb_serp_conf();
	qtb_job_pause( $job, 'serp_browser', sprintf( 'نتایج گوگل %s کلمه از مرورگر شما گرفته می‌شود؛ این صفحه را باز نگه دارید.', qtb_num( count( $left ) ) ) );
	$job['paused']['items'] = array_map( function ( $i ) use ( $kws ) {
		return array( (int) $i, $kws[ $i ]['k'] );
	}, array_values( $left ) );
	$job['paused']['gl']    = $c['gl'];
	$job['paused']['hl']    = $c['hl'];
	return false;
}

/**
 * Standalone dictionary build (from the «ویژگی‌ها» step, before processing topics).
 */
function qtb_ws_facets_build( $project, &$job, $deadline ) {
	return qtb_fx_build( $project, $job, $deadline );
}

/**
 * Facet engine, step 1: the dictionary knows every word of the active keywords.
 */
function qtb_ws_topic_facets( $project, &$job, $deadline ) {
	return qtb_fx_build( $project, $job, $deadline );
}

/**
 * Topic keywords that go on to clustering, and its locked clusters.
 *
 * @return array{0: array, 1: array, 2: int[]} [sub keywords, locked clusters, free indices]
 */
function qtb_ws_topic_pool( $t, $kws ) {
	$sub = array();
	foreach ( $t['kws'] as $i ) {
		if ( isset( $kws[ $i ] ) && in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'keep', 'unsure' ), true ) ) {
			$sub[ $i ] = $kws[ $i ];
		}
	}
	$locked = array();
	$placed = array();
	foreach ( $t['clusters'] as $c ) {
		if ( ! empty( $c['locked'] ) ) {
			$set = array_values( array_filter( $c['kws'], function ( $i ) use ( $sub ) {
				return isset( $sub[ $i ] );
			} ) );
			if ( $set ) {
				$c['kws']  = $set;
				$locked[]  = $c;
				$placed    = array_merge( $placed, $set );
			}
		}
	}
	return array( $sub, $locked, array_values( array_diff( array_keys( $sub ), $placed ) ) );
}

/**
 * Options of the facet decision for a project.
 */
function qtb_ws_fx_opts( $project, $serps, $sub = null ) {
	return array(
		'pct'      => (float) ( $project['cfg']['split_pct'] ?? 10 ),
		'floor'    => qtb_fx_floor( $project, $sub ),
		'need'     => (int) $project['cfg']['strictness'],
		'serps'    => $serps,
		'hubs'     => qtb_fx_hubs( $serps ),
		'comp_idx' => qtb_data_get( $project['id'], 'comp_index', array() ),
		'comps'    => qtb_comps( $project['id'] ),
	);
}

/**
 * Facet engine, step 2: build the page tree once without new SERPs and list the
 * keywords whose top 10 would settle a borderline decision (biggest first).
 */
function qtb_ws_topic_plan( $project, &$job ) {
	$pid  = $project['id'];
	$ws   = qtb_ws_get( $pid );
	$t    = $ws['topics'][ qtb_ws_topic_index( $ws, $job['tid'] ) ];
	$kws  = qtb_data_get( $pid, 'keywords', array() );
	$fx   = qtb_fx_get( $pid );
	list( $sub, $locked, $free ) = qtb_ws_topic_pool( $t, $kws );
	$serps = array_intersect_key( qtb_data_get( $pid, 'serps', array() ), $sub );
	$tree  = qtb_fx_nodes( $fx, $sub, $free, $locked );
	$res   = qtb_fx_decide( $fx, $sub, $tree, array_merge( qtb_ws_fx_opts( $project, $serps, $sub ), array( 'plan_only' => true ) ) );
	$job['serp_want'] = $res['want'];
	qtb_job_log( $job, sprintf( '%s کلمه در %s گره (ترکیب ویژگی)؛ %s کلمه برای تصمیم‌های مرزی نتیجه گوگل لازم دارد.', qtb_num( count( $sub ) ), qtb_num( count( $tree['nodes'] ) ), qtb_num( count( $res['want'] ) ) ) );
	return true;
}

/**
 * Facet engine, step 4: the decided tree → clusters, pages and sections.
 */
function qtb_ws_topic_fcluster( $project, &$job ) {
	$pid   = $project['id'];
	$ws    = qtb_ws_get( $pid );
	$ti    = qtb_ws_topic_index( $ws, $job['tid'] );
	$t     = $ws['topics'][ $ti ];
	$kws   = qtb_data_get( $pid, 'keywords', array() );
	$fx    = qtb_fx_get( $pid );
	list( $sub, $locked, $free ) = qtb_ws_topic_pool( $t, $kws );
	$serps = array_intersect_key( qtb_data_get( $pid, 'serps', array() ), $sub );
	$tree  = qtb_fx_nodes( $fx, $sub, $free, $locked );
	$res   = qtb_fx_decide( $fx, $sub, $tree, qtb_ws_fx_opts( $project, $serps, $sub ) );
	$own   = qtb_project_domain( $project );
	$clusters = array();
	$used     = array();
	foreach ( $res['groups'] as $g ) {
		$prev = null;
		if ( null !== $g['lock'] ) {
			$prev       = $locked[ $g['lock'] ];
			$used[]     = $g['lock'];
			// Locked keywords stay; same-facet newcomers join them.
			$members    = array_values( array_unique( array_merge( $prev['kws'], $g['kws'] ) ) );
			$meta       = (array) $prev['meta'] + (array) $g['meta'];
			$g          = array_merge( qtb_group_make( $sub, $members ), array( 'meta' => $meta, 'fx' => $g['fx'] ) );
			$g['seed']  = true;
		}
		if ( ! $g['kws'] ) {
			continue;
		}
		if ( null === $prev && ! empty( $g['fx']['virtual'] ) ) {
			// The main page (and the blog's general article) carry the topic's name when no
			// keyword is the base alone.
			$g['name_hint'] = 'i:' === $g['fx']['key'] ? 'راهنمای ' . $t['name'] : $t['name'];
		}
		$c = qtb_ws_make_cluster( $ws, $sub, $g, $serps, $own, $prev );
		if ( $prev && isset( $prev['fx'] ) && empty( $g['fx'] ) ) {
			$c['fx'] = $prev['fx'];
		}
		$clusters[] = $c;
	}
	// Locked clusters whose key moved elsewhere stay as they were.
	foreach ( $locked as $li => $lc ) {
		if ( ! in_array( $li, $used, true ) ) {
			$clusters[] = $lc;
		}
	}
	$clusters = qtb_ws_decide_pages( $project, $ws, $sub, $clusters );
	foreach ( $clusters as &$c ) {
		// Blog nodes are articles unless a reviewer or a rule decided otherwise.
		if ( ! empty( $c['fx']['info'] ) && empty( $c['locked_page'] ) && 0 !== strpos( (string) ( $c['page_reason'] ?? '' ), 'قانون پروژه' ) && in_array( $c['page'], array( 'archive', 'landing' ), true ) ) {
			$c['page']        = 'article';
			$c['archive']     = '';
			$c['page_reason'] = 'نیت آموزشی (واژه آموزشی در کلمه)';
		}
	}
	unset( $c );
	// What Google shows in its top 3 decides the page type when it is clear.
	$clusters = qtb_fx_pages_by_top3( $clusters, $sub, $serps );
	$clusters = qtb_fx_assign_sections( $project, $sub, $clusters );
	$ws['topics'][ $ti ]['clusters'] = qtb_sort( $clusters, 'vol' );
	qtb_ws_put( $pid, $ws );
	$by   = array_count_values( array_map( function ( $c ) {
		return (string) ( $c['fx']['why'] ?? '' );
	}, $clusters ) );
	$flag = 0;
	foreach ( $clusters as $c ) {
		$flag += count( (array) ( $c['fx']['review'] ?? array() ) );
	}
	qtb_job_log( $job, sprintf( '%s صفحه از %s کلمه: %s جدا با گوگل، %s جدا با رقیب، %s بالای آستانه؛ %s ادغام بدون شاهد برای بازبینی علامت خورد.', qtb_num( count( $clusters ) ), qtb_num( count( $sub ) ), qtb_num( $by['serp_diff'] ?? 0 ), qtb_num( $by['comp_diff'] ?? 0 ), qtb_num( $by['threshold'] ?? 0 ), qtb_num( $flag ) ) );
	return true;
}

/**
 * SERP clustering of the topic (locked clusters of an earlier run are kept as seeds).
 */
function qtb_ws_topic_cluster( $project, &$job ) {
	$pid   = $project['id'];
	$ws    = qtb_ws_get( $pid );
	$ti    = qtb_ws_topic_index( $ws, $job['tid'] );
	$t     = $ws['topics'][ $ti ];
	$kws   = qtb_data_get( $pid, 'keywords', array() );
	$serps = qtb_data_get( $pid, 'serps', array() );
	$sub   = array();
	foreach ( $t['kws'] as $i ) {
		if ( isset( $kws[ $i ] ) && in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'keep', 'unsure' ), true ) ) {
			$sub[ $i ] = $kws[ $i ];
		}
	}
	// Locked clusters (touched by the reviewer) survive a re-run.
	$seeds  = array();
	$locked = array();
	$placed = array();
	foreach ( $t['clusters'] as $c ) {
		if ( ! empty( $c['locked'] ) ) {
			$set = array_values( array_filter( $c['kws'], function ( $i ) use ( $sub ) {
				return isset( $sub[ $i ] );
			} ) );
			if ( $set ) {
				$seeds[]  = $set;
				$locked[] = $c;
				$placed   = array_merge( $placed, $set );
			}
		}
	}
	$free = array_diff( array_keys( $sub ), $placed );
	$tser = array_intersect_key( $serps, $sub );
	if ( $tser ) {
		$groups = qtb_serp_cluster_kw( $sub, $free, $tser, (int) $project['cfg']['strictness'], $seeds );
	} else {
		$groups = qtb_precluster( array_intersect_key( $sub, array_flip( $free ) ), array( 'brands' => array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) ) );
		foreach ( $groups as &$g ) {
			$g['meta'] = array_fill_keys( $g['kws'], array( 'conf' => 'low', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'lexical' ) );
		}
		unset( $g );
		foreach ( $seeds as $set ) {
			$g         = qtb_group_make( $sub, $set );
			$g['seed'] = true;
			$g['meta'] = array();
			$groups[]  = $g;
		}
	}
	// Same page in a competitor's site (Seo Signal files): free evidence that two groups are one page.
	$groups   = qtb_comp_merge_groups( $project, $sub, $groups, $tser, (int) $project['cfg']['strictness'] );
	$clusters = array();
	$own      = qtb_project_domain( $project );
	foreach ( $groups as $g ) {
		$prev = null;
		if ( ! empty( $g['seed'] ) ) {
			foreach ( $locked as $lc ) {
				if ( array_intersect( $lc['kws'], $g['kws'] ) ) {
					$prev = $lc;
					break;
				}
			}
		}
		$clusters[] = qtb_ws_make_cluster( $ws, $sub, $g, $serps, $own, $prev );
	}
	$clusters = qtb_ws_decide_pages( $project, $ws, $sub, $clusters );
	$ws['topics'][ $ti ]['clusters'] = qtb_sort( $clusters, 'vol' );
	qtb_ws_put( $pid, $ws );
	$by = array_count_values( array_map( function ( $m ) {
		return $m['conf'];
	}, array_merge( ...array_map( function ( $c ) {
		return array_values( $c['meta'] );
	}, $clusters ) ?: array( array() ) ) ) );
	qtb_job_log( $job, sprintf( '%s کلاستر؛ اطمینان سرپ: %s زیاد، %s متوسط، %s کم.', qtb_num( count( $clusters ) ), qtb_num( $by['high'] ?? 0 ), qtb_num( $by['mid'] ?? 0 ), qtb_num( $by['low'] ?? 0 ) ) );
	return true;
}

/**
 * A workshop cluster from a group.
 */
function qtb_ws_make_cluster( &$ws, $kws, $g, $serps, $own, $prev = null ) {
	$head = $g['kws'][0];
	$c    = array(
		'id'      => $prev ? $prev['id'] : $ws['next_cid']++,
		'name'    => $prev && ! empty( $prev['locked_name'] ) ? $prev['name'] : ( ! empty( $g['name_hint'] ) ? $g['name_hint'] : $kws[ $head ]['k'] ),
		'kws'     => $g['kws'],
		'vol'     => $g['vol'],
		'core'    => $kws[ $head ]['core'],
		'meta'    => (array) ( $g['meta'] ?? array() ),
		'serp'    => null,
		'serp_kw' => null,
		'intent'  => qtb_group_intent( $kws, $g['kws'] ),
		'page'    => 'archive',
		'archive' => 'category',
		'locked'  => $prev ? 1 : 0,
	);
	if ( isset( $g['fx'] ) ) {
		$c['fx'] = $g['fx'];
	}
	if ( ! empty( $g['name_hint'] ) && ! $prev ) {
		$c['locked_name'] = 1;
	}
	foreach ( array( 'locked_name', 'locked_page', 'locked_section', 'own', 'page', 'archive', 'page_reason', 'section', 'note' ) as $k ) {
		if ( $prev && isset( $prev[ $k ] ) && ( 'page' !== $k && 'archive' !== $k && 'page_reason' !== $k || ! empty( $prev['locked_page'] ) ) ) {
			$c[ $k ] = $prev[ $k ];
		}
	}
	foreach ( $g['kws'] as $m ) {
		if ( isset( $serps[ $m ] ) && ! empty( $serps[ $m ]['organic'] ) ) {
			$c['serp']    = qtb_serp_metrics( $serps[ $m ], $kws[ $m ]['k'], $own );
			$c['serp_kw'] = $m;
			if ( 'n' !== $c['intent'] ) {
				$c['intent'] = $c['serp']['intent'];
			}
			break;
		}
	}
	return $c;
}

/**
 * Page decision of the topic's clusters + page rules.
 */
function qtb_ws_decide_pages( $project, $ws, $kws, $clusters ) {
	$inv     = (array) ( qtb_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$idx     = qtb_store_index( $inv );
	$profile = qtb_project_profile( $project );
	foreach ( $clusters as &$c ) {
		if ( empty( $c['locked_page'] ) ) {
			$c['comp_sig']    = qtb_comp_page_signal( $project, $kws, $c );
			$d                = qtb_page_decide( $c, $kws, $idx, $profile );
			$c['page']        = $d['page'];
			$c['archive']     = 'archive' === $d['page'] ? $d['archive'] : '';
			$c['page_reason'] = $d['reason'];
			$c['page_hint']   = $d['term_url'];
			$c['store_match'] = $d['products'];
			foreach ( (array) $ws['rules'] as $r ) {
				if ( 'page' !== $r['kind'] || '' === (string) $r['match'] ) {
					continue;
				}
				foreach ( $c['kws'] as $m ) {
					if ( isset( $kws[ $m ] ) && qtb_ws_rule_hit( $kws[ $m ], $r['match'] ) ) {
						$pt = qtb_page_value_parse( $r['page'] . ( 'archive' === $r['page'] ? ':' . ( $r['archive'] ?: 'category' ) : '' ) );
						if ( $pt ) {
							$c['page']        = $pt[0];
							$c['archive']     = $pt[1];
							$c['page_reason'] = 'قانون پروژه: ' . $r['text'];
						}
						break 2;
					}
				}
			}
		}
	}
	unset( $c );
	return $clusters;
}

/**
 * AI review of every keyword: batches of 25 («batch») or one by one («single»).
 */
function qtb_ws_topic_ai_kw( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ بازبینی هوشمند انجام نشد.', 'warn' );
		return true;
	}
	$pid   = $project['id'];
	$ws    = qtb_ws_get( $pid );
	$ti    = qtb_ws_topic_index( $ws, $job['tid'] );
	$t     = $ws['topics'][ $ti ];
	$kws   = qtb_data_get( $pid, 'keywords', array() );
	$serps = qtb_data_get( $pid, 'serps', array() );
	$size  = 'single' === $t['mode'] ? 1 : 25;
	if ( ! isset( $job['sub']['items'] ) ) {
		$items = array();
		foreach ( $t['clusters'] as $c ) {
			if ( ! empty( $c['locked'] ) ) {
				continue;
			}
			foreach ( $c['kws'] as $i ) {
				$m       = $c['meta'][ $i ] ?? array( 'conf' => 'low', 'shared' => -1, 'alt' => null, 'alt_shared' => 0 );
				$items[] = array( 'i' => $i, 'cid' => $c['id'], 'conf' => $m['conf'], 'shared' => (int) $m['shared'], 'alt' => $m['alt'], 'alt_shared' => (int) $m['alt_shared'] );
			}
		}
		// Uncertain first (a stopped run has done the important part).
		$rank = array( 'low' => 0, 'mid' => 1, 'high' => 2 );
		usort( $items, function ( $a, $b ) use ( $rank ) {
			return $rank[ $a['conf'] ] <=> $rank[ $b['conf'] ];
		} );
		$job['sub']['items'] = $items;
		$job['sub']['off']   = 0;
		$job['sub']['dec']   = array();
	}
	// kw → cluster id (for the «alt» head keyword → cluster).
	$cid_of = array();
	foreach ( $t['clusters'] as $c ) {
		foreach ( $c['kws'] as $i ) {
			$cid_of[ $i ] = $c['id'];
		}
	}
	$compact = array();
	foreach ( $t['clusters'] as $c ) {
		$compact[] = array(
			'cid'     => $c['id'],
			'name'    => $c['name'],
			'page'    => qtb_page_label( $c ),
			'size'    => count( $c['kws'] ),
			'members' => array_map( function ( $i ) use ( $kws ) {
				return $kws[ $i ]['k'];
			}, array_slice( $c['kws'], 0, 8 ) ),
		);
	}
	$items = $job['sub']['items'];
	while ( $job['sub']['off'] < count( $items ) ) {
		$batch = array();
		foreach ( array_slice( $items, $job['sub']['off'], $size ) as $it ) {
			$s       = $serps[ $it['i'] ] ?? null;
			$batch[] = array(
				'i'          => $it['i'],
				'k'          => $kws[ $it['i'] ]['k'],
				'v'          => $kws[ $it['i'] ]['v'],
				'cid'        => $it['cid'],
				'conf'       => array( 'high' => 'زیاد', 'mid' => 'متوسط', 'low' => 'کم' )[ $it['conf'] ],
				'shared'     => $it['shared'],
				'alt'        => null !== $it['alt'] && isset( $cid_of[ $it['alt'] ] ) && $cid_of[ $it['alt'] ] !== $it['cid'] ? $cid_of[ $it['alt'] ] : null,
				'alt_shared' => $it['alt_shared'],
				'titles'     => $s ? array_map( function ( $o ) {
					return $o['title'];
				}, array_slice( (array) $s['organic'], 0, 6 ) ) : array(),
			);
		}
		$res = qtb_ai_review_batch( $project, $ws, $t, $batch, $compact, 1 === $size );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 3 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				qtb_job_log( $job, 'بازبینی هوشمند ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $res as $i => $d ) {
			$job['sub']['dec'][ $i ] = $d;
		}
		$job['sub']['off'] += $size;
		$job['sub']['frac'] = min( 1, $job['sub']['off'] / max( 1, count( $items ) ) );
		if ( microtime( true ) >= $deadline && $job['sub']['off'] < count( $items ) ) {
			return false;
		}
	}
	$n = qtb_ws_apply_ai( $project, $job['tid'], $job['sub']['dec'] );
	$job['m_ai_changes'] = $n;
	qtb_job_log( $job, sprintf( 'هوش مصنوعی %s کلمه را بررسی کرد و %s تصمیم سرپ را عوض کرد (فقط با اطمینان متوسط و زیاد؛ بقیه به‌صورت پیشنهاد).', qtb_num( count( $job['sub']['dec'] ) ), qtb_num( $n ) ) );
	return true;
}

/**
 * Apply the AI keyword decisions (confident ones; the rest stay as suggestions).
 *
 * @return int changes applied
 */
function qtb_ws_apply_ai( $project, $tid, $dec ) {
	$pid  = $project['id'];
	$ws   = qtb_ws_get( $pid );
	$ti   = qtb_ws_topic_index( $ws, $tid );
	$kws  = qtb_data_get( $pid, 'keywords', array() );
	$t    = $ws['topics'][ $ti ];
	$byid = array();
	foreach ( $t['clusters'] as $ci => $c ) {
		$byid[ $c['id'] ] = $ci;
	}
	$new  = array(); // name → cluster index.
	$n    = 0;
	foreach ( $dec as $i => $d ) {
		$from = null;
		foreach ( $t['clusters'] as $ci => $c ) {
			if ( in_array( $i, $c['kws'], true ) ) {
				$from = $ci;
				break;
			}
		}
		if ( null === $from ) {
			continue;
		}
		$t['clusters'][ $from ]['meta'][ $i ]['ai'] = $d;
		if ( 'keep' === $d['action'] || $d['conf'] < 2 ) {
			continue;
		}
		$to = null;
		if ( 'move' === $d['action'] && isset( $byid[ (int) preg_replace( '/\D/', '', $d['to'] ) ] ) ) {
			$to = $byid[ (int) preg_replace( '/\D/', '', $d['to'] ) ];
		} elseif ( 'new' === $d['action'] ) {
			$name = qtb_display( $d['to'] ) ?: $kws[ $i ]['k'];
			if ( ! isset( $new[ $name ] ) ) {
				$t['clusters'][] = array( 'id' => $ws['next_cid']++, 'name' => $name, 'kws' => array(), 'vol' => 0, 'core' => $kws[ $i ]['core'], 'meta' => array(), 'serp' => null, 'serp_kw' => null, 'intent' => qtb_group_intent( $kws, array( $i ) ), 'page' => 'archive', 'archive' => 'category', 'locked' => 0, 'locked_name' => 1 );
				$new[ $name ]    = count( $t['clusters'] ) - 1;
				$byid[ $t['clusters'][ $new[ $name ] ]['id'] ] = $new[ $name ];
			}
			$to = $new[ $name ];
		} elseif ( in_array( $d['action'], array( 'remove', 'park' ), true ) ) {
			$kws[ $i ]['ws'] = 'remove' === $d['action'] ? 'remove' : 'park';
			$kws[ $i ]['tr'] = 'remove' === $d['action'] ? 'irrelevant' : 'unsure';
			$kws[ $i ]['tn'] = 'بازبین هوش مصنوعی: ' . $d['reason'];
			$t['clusters'][ $from ]['kws'] = array_values( array_diff( $t['clusters'][ $from ]['kws'], array( $i ) ) );
			++$n;
			continue;
		}
		if ( null === $to || $to === $from ) {
			continue;
		}
		$t['clusters'][ $from ]['kws']           = array_values( array_diff( $t['clusters'][ $from ]['kws'], array( $i ) ) );
		$t['clusters'][ $to ]['kws'][]           = $i;
		$t['clusters'][ $to ]['meta'][ $i ]      = array_merge( $t['clusters'][ $from ]['meta'][ $i ] ?? array(), array( 'why' => 'ai', 'conf' => 3 === $d['conf'] ? 'high' : 'mid', 'ai' => $d ) );
		++$n;
	}
	$t['clusters']         = qtb_ws_tidy( $kws, $t['clusters'] );
	$ws['topics'][ $ti ]   = $t;
	qtb_ws_put( $pid, $ws );
	qtb_data_put( $pid, 'keywords', $kws );
	return $n;
}

/**
 * Recompute members order, head, volume; drop empty clusters.
 */
function qtb_ws_tidy( $kws, $clusters ) {
	$out = array();
	foreach ( $clusters as $c ) {
		$c['kws'] = array_values( array_unique( array_filter( array_map( 'intval', $c['kws'] ), function ( $i ) use ( $kws ) {
			return isset( $kws[ $i ] ) && in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'keep', 'unsure' ), true );
		} ) ) );
		if ( ! $c['kws'] ) {
			continue;
		}
		usort( $c['kws'], function ( $a, $b ) use ( $kws ) {
			return $kws[ $b ]['v'] <=> $kws[ $a ]['v'];
		} );
		$c['vol'] = array_sum( array_map( function ( $i ) use ( $kws ) {
			return (int) $kws[ $i ]['v'];
		}, $c['kws'] ) );
		if ( empty( $c['locked_name'] ) ) {
			$c['name'] = $kws[ $c['kws'][0] ]['k'];
		}
		$c['core'] = $kws[ $c['kws'][0] ]['core'];
		$out[]     = $c;
	}
	return qtb_sort( $out, 'vol' );
}

/**
 * AI review of the clusters: page type, name, section.
 */
function qtb_ws_topic_ai_cl( $project, &$job, $deadline ) {
	if ( ! qtb_ai_connected() ) {
		return true;
	}
	$pid   = $project['id'];
	$ws    = qtb_ws_get( $pid );
	$ti    = qtb_ws_topic_index( $ws, $job['tid'] );
	$t     = $ws['topics'][ $ti ];
	$kws   = qtb_data_get( $pid, 'keywords', array() );
	$facet = 'facet' === ( $job['engine'] ?? '' );
	// Pages of clusters the keyword review changed (the facet engine decided them a step ago).
	if ( ! $facet ) {
		$t['clusters'] = qtb_ws_decide_pages( $project, $ws, $kws, $t['clusters'] );
	}
	$off   = (int) ( $job['sub']['off'] ?? 0 );
	while ( $off < count( $t['clusters'] ) ) {
		$batch = array();
		foreach ( array_slice( $t['clusters'], $off, 40 ) as $c ) {
			$types = array();
			foreach ( (array) ( $c['serp']['types'] ?? array() ) as $k => $v ) {
				$types[] = $k . ' ' . round( 100 * $v ) . '٪';
			}
			$batch[] = array(
				'cid'      => $c['id'],
				'name'     => $c['name'],
				'size'     => count( $c['kws'] ),
				'vol'      => $c['vol'],
				'page'     => $c['page'],
				'archive'  => $c['archive'] ?? '',
				'reason'   => (string) ( $c['page_reason'] ?? '' ),
				'types'    => ( ! empty( $c['top3'] ) ? '۳ نتیجه اول: ' . $c['top3'] . ' | ' : '' ) . implode( '، ', array_slice( $types, 0, 4 ) ),
				'products' => (int) ( $c['store_match'] ?? 0 ),
				'members'  => array_map( function ( $i ) use ( $kws ) {
					return $kws[ $i ]['k'];
				}, array_slice( $c['kws'], 0, 6 ) ),
			);
		}
		// A cluster that did not change since its last review (same members, page, Google
		// signal and project rules) reuses that answer: no tokens spent twice.
		$memo  = (array) qtb_data_get( $pid, 'ai_cl_memo', array() );
		$rkey  = md5( wp_json_encode( array_column( (array) $ws['rules'], 'text' ) ) . '|' . $t['name'] );
		$res   = array();
		$fresh = array();
		$keys  = array();
		foreach ( $batch as $it ) {
			$copy = $it;
			unset( $copy['cid'] );
			$keys[ $it['cid'] ] = md5( wp_json_encode( $copy, JSON_UNESCAPED_UNICODE ) . $rkey );
			if ( isset( $memo[ $keys[ $it['cid'] ] ] ) ) {
				$res[ $it['cid'] ] = $memo[ $keys[ $it['cid'] ] ];
			} else {
				$fresh[] = $it;
			}
		}
		$job['m_ai_reused'] = (int) ( $job['m_ai_reused'] ?? 0 ) + count( $res );
		if ( $fresh ) {
			$got = qtb_ai_review_clusters( $project, $ws, $t, $fresh );
			if ( ! is_wp_error( $got ) ) {
				foreach ( $got as $cid => $a ) {
					$res[ $cid ] = $a;
					if ( isset( $keys[ $cid ] ) ) {
						$memo[ $keys[ $cid ] ] = $a;
					}
				}
				qtb_data_put( $pid, 'ai_cl_memo', array_slice( $memo, -4000, null, true ) );
			} else {
				$res = $got;
			}
		}
		if ( is_wp_error( $res ) ) {
			$job['sub']['fail'] = (int) ( $job['sub']['fail'] ?? 0 ) + 1;
			if ( $job['sub']['fail'] > 2 ) {
				qtb_job_log( $job, 'بازبینی نوع صفحه ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			return false;
		}
		foreach ( $t['clusters'] as &$c ) {
			$a = $res[ $c['id'] ] ?? null;
			if ( ! $a ) {
				continue;
			}
			if ( empty( $c['locked_name'] ) && '' !== $a['name'] ) {
				$c['name'] = $a['name'];
			}
			if ( ! $facet && '' !== $a['section'] && empty( $c['locked_section'] ) ) {
				$c['section'] = $a['section'];
			}
			$rule = 0 === strpos( (string) ( $c['page_reason'] ?? '' ), 'قانون پروژه' );
			if ( empty( $c['locked_page'] ) && empty( $c['top3_lock'] ) && ! $rule && isset( qtb_page_types()[ $a['page'] ] ) ) {
				if ( $a['page'] !== $c['page'] || ( 'archive' === $a['page'] && $a['archive'] && $a['archive'] !== ( $c['archive'] ?? '' ) ) ) {
					$c['page_ai'] = array( 'from' => qtb_page_value( $c ) );
					$c['page']    = $a['page'];
					$c['archive'] = 'archive' === $a['page'] ? ( $a['archive'] ?: 'category' ) : '';
				}
				$c['page_reason'] = $a['reason'];
			}
		}
		unset( $c );
		$off               += 40;
		$job['sub']['off']  = $off;
		$job['sub']['frac'] = min( 1, $off / max( 1, count( $t['clusters'] ) ) );
		if ( $facet ) {
			$t['clusters'] = qtb_fx_assign_sections( $project, $kws, $t['clusters'] );
		}
		$ws['topics'][ $ti ] = $t;
		qtb_ws_put( $pid, $ws );
		if ( microtime( true ) >= $deadline && $off < count( $t['clusters'] ) ) {
			return false;
		}
	}
	$ws['topics'][ $ti ] = $t;
	qtb_ws_put( $pid, $ws );
	if ( ! empty( $job['m_ai_reused'] ) ) {
		qtb_job_log( $job, sprintf( 'بازبینی هوشمند: %s کلاستر بدون تغییر بود و جواب قبلی‌اش دوباره استفاده شد (بدون مصرف توکن).', qtb_num( $job['m_ai_reused'] ) ) );
	}
	return true;
}

/**
 * Metrics of the run (for the batch / single comparison).
 */
function qtb_ws_topic_finish( $project, &$job ) {
	$pid  = $project['id'];
	$ws   = qtb_ws_get( $pid );
	$ti   = qtb_ws_topic_index( $ws, $job['tid'] );
	$t    = $ws['topics'][ $ti ];
	$u    = qtb_ai_usage();
	$nkw  = 0;
	$conf = array( 'high' => 0, 'mid' => 0, 'low' => 0 );
	foreach ( $t['clusters'] as $c ) {
		$nkw += count( $c['kws'] );
		foreach ( $c['kws'] as $i ) {
			$k = (string) ( $c['meta'][ $i ]['conf'] ?? 'low' );
			$conf[ isset( $conf[ $k ] ) ? $k : 'low' ]++;
		}
	}
	if ( $t['metrics'] ) {
		$t['history'][] = $t['metrics'];
		$t['history']   = array_slice( $t['history'], -6 );
	}
	$t['metrics'] = array(
		'mode'         => $t['mode'],
		'at'           => qtb_now(),
		'kws'          => $nkw,
		'clusters'     => count( $t['clusters'] ),
		'seconds'      => (int) round( microtime( true ) - (float) $job['t0'] ),
		'ai_cost'      => round( max( 0, $u['cost'] - (float) $job['cost0'] ), 4 ),
		'ai_calls'     => max( 0, (int) $u['requests'] - (int) $job['req0'] ),
		'ai_changes'   => (int) ( $job['m_ai_changes'] ?? 0 ),
		'serp_new'     => (int) ( $job['m_serp_new'] ?? 0 ),
		'serp_cache'   => (int) ( $job['m_serp_cache'] ?? 0 ),
		'conf'         => $conf,
		'user_changes' => 0,
	);
	$t['status']         = 'ready';
	$t['fresh']          = 0;
	$ws['dirty']         = 1;
	$ws['topics'][ $ti ] = $t;
	$ws['stage']         = 'review';
	qtb_ws_put( $pid, $ws );
	qtb_job_log( $job, sprintf( 'موضوع «%s» آماده بازبینی است: %s کلاستر، %s کلمه.', $t['name'], qtb_num( count( $t['clusters'] ) ), qtb_num( $nkw ) ) );
	return true;
}

// ---------------------------------------------------------------------------
// Reviewer actions.
// ---------------------------------------------------------------------------

/**
 * Apply a reviewer action.
 *
 * @return true|array|WP_Error
 */
function qtb_ws_edit( $project, $e ) {
	$pid = $project['id'];
	$ws  = qtb_ws_get( $pid );
	$kws = qtb_data_get( $pid, 'keywords', array() );
	$op  = (string) ( $e['op'] ?? '' );
	$who = wp_get_current_user()->display_name;
	$ti  = isset( $e['tid'] ) ? qtb_ws_topic_index( $ws, $e['tid'] ) : null;

	// Triage (no topic needed).
	if ( 'triage' === $op ) {
		$st = in_array( $e['st'] ?? '', array( 'keep', 'remove', 'park' ), true ) ? $e['st'] : 'keep';
		$n  = 0;
		foreach ( (array) ( $e['kws'] ?? array() ) as $i ) {
			$i = (int) $i;
			if ( isset( $kws[ $i ] ) ) {
				$kws[ $i ]['ws'] = $st;
				$kws[ $i ]['tr'] = 'keep' === $st ? 'ok' : 'manual';
				$kws[ $i ]['tl'] = 1;
				$kws[ $i ]['tn'] = 'تصمیم ' . $who;
				++$n;
			}
		}
		// Removed / parked keywords leave their clusters.
		foreach ( $ws['topics'] as &$t ) {
			$t['clusters'] = qtb_ws_tidy( $kws, $t['clusters'] );
		}
		unset( $t );
		qtb_data_put( $pid, 'keywords', $kws );
		qtb_ws_put( $pid, $ws );
		return array( 'ok' => true, 'n' => $n );
	}
	if ( 'rule_add' === $op ) {
		$pt = qtb_page_value_parse( (string) ( $e['page'] ?? '' ) . ( ! empty( $e['archive'] ) && false === strpos( (string) ( $e['page'] ?? '' ), ':' ) ? ':' . $e['archive'] : '' ) );
		if ( 'page' === ( $e['kind'] ?? '' ) && ! $pt ) {
			return new WP_Error( 'qtb_ws', 'برای قانون «نوع صفحه»، نوع صفحه را انتخاب کنید.' );
		}
		$r = array(
			'id'      => $ws['next_rid']++,
			'kind'    => in_array( $e['kind'] ?? '', array( 'remove', 'keep', 'park', 'page', 'note' ), true ) ? $e['kind'] : 'note',
			'match'   => qtb_mb_cut( sanitize_text_field( (string) ( $e['match'] ?? '' ) ), 60 ),
			'page'    => $pt ? $pt[0] : '',
			'archive' => $pt ? $pt[1] : '',
			'text'    => qtb_mb_cut( sanitize_text_field( (string) ( $e['text'] ?? '' ) ), 240 ),
			'by'      => $who,
			'at'      => qtb_now(),
		);
		if ( '' === $r['match'] && 'note' !== $r['kind'] ) {
			return new WP_Error( 'qtb_ws', 'کلمه یا عبارت قانون را بنویسید.' );
		}
		if ( '' === $r['text'] ) {
			$r['text'] = array( 'remove' => 'حذف کلمات شامل «%s»', 'keep' => 'نگه‌داشتن کلمات شامل «%s»', 'park' => 'کنار گذاشتن کلمات شامل «%s»', 'page' => 'کلمات شامل «%s» صفحه ' . ( $pt ? qtb_page_label( array( 'page' => $pt[0], 'archive' => $pt[1] ) ) : '' ), 'note' => '%s' )[ $r['kind'] ];
			$r['text'] = sprintf( $r['text'], $r['match'] );
		}
		$ws['rules'][] = $r;
		$n             = qtb_ws_apply_rules( $kws, array( $r ) );
		foreach ( $ws['topics'] as &$t ) {
			$t['clusters'] = qtb_ws_tidy( $kws, $t['clusters'] );
		}
		unset( $t );
		qtb_data_put( $pid, 'keywords', $kws );
		qtb_ws_put( $pid, $ws );
		return array( 'ok' => true, 'n' => $n, 'rule' => $r );
	}
	if ( 'rule_del' === $op ) {
		$ws['rules'] = array_values( array_filter( $ws['rules'], function ( $r ) use ( $e ) {
			return (int) $r['id'] !== (int) ( $e['id'] ?? 0 );
		} ) );
		qtb_ws_put( $pid, $ws );
		return true;
	}
	if ( 'topic_new' === $op ) {
		$name = qtb_mb_cut( sanitize_text_field( (string) ( $e['name'] ?? '' ) ), 40 );
		if ( '' === $name ) {
			return new WP_Error( 'qtb_ws', 'نام پیلار خالی است.' );
		}
		foreach ( $ws['topics'] as $x ) {
			if ( qtb_key( $x['name'] ) === qtb_key( $name ) ) {
				return array( 'ok' => true, 'tid' => (int) $x['id'] );
			}
		}
		$nt             = qtb_ws_new_topic( $ws, $name, array(), $project );
		$nt['status']   = 'ready';
		$ws['topics'][] = $nt;
		$ws['dirty']    = 1;
		qtb_ws_put( $pid, $ws );
		return array( 'ok' => true, 'tid' => (int) $nt['id'] );
	}
	if ( 'topic_merge' === $op ) {
		$ids = array_map( 'intval', (array) ( $e['ids'] ?? array() ) );
		$ki  = qtb_ws_topic_index( $ws, $ids[0] ?? 0 );
		if ( null === $ki || count( $ids ) < 2 ) {
			return new WP_Error( 'qtb_ws', 'حداقل دو موضوع انتخاب کنید.' );
		}
		$unproc = 'new' === $ws['topics'][ $ki ]['status'];
		foreach ( array_slice( $ids, 1 ) as $id ) {
			$oi = qtb_ws_topic_index( $ws, $id );
			if ( null !== $oi && $oi !== $ki ) {
				// Clusters (and their locks and sections) come along; nothing reviewed is lost.
				$ws['topics'][ $ki ]['kws']      = array_values( array_unique( array_merge( $ws['topics'][ $ki ]['kws'], $ws['topics'][ $oi ]['kws'] ) ) );
				$ws['topics'][ $ki ]['clusters'] = array_merge( $ws['topics'][ $ki ]['clusters'], $ws['topics'][ $oi ]['clusters'] );
				$ws['topics'][ $ki ]['fresh']    = (int) ( $ws['topics'][ $ki ]['fresh'] ?? 0 ) + (int) ( $ws['topics'][ $oi ]['fresh'] ?? 0 );
				$unproc                          = $unproc || 'new' === $ws['topics'][ $oi ]['status'];
				$ws['topics'][ $oi ]['kws']      = array();
			}
		}
		$ws['topics'][ $ki ]['clusters'] = qtb_ws_tidy( $kws, $ws['topics'][ $ki ]['clusters'] );
		// Keywords not in any cluster yet (an unprocessed topic) need a processing run.
		$ws['topics'][ $ki ]['status'] = $ws['topics'][ $ki ]['clusters'] && ! $unproc ? 'ready' : 'new';
		$ws['topics'] = array_values( array_filter( $ws['topics'], function ( $t ) {
			return ! empty( $t['kws'] );
		} ) );
		qtb_ws_put( $pid, $ws );
		return true;
	}
	// Facet dictionary (project-wide).
	if ( in_array( $op, array( 'fx_token', 'fx_bulk', 'fx_type', 'fx_type_del' ), true ) ) {
		return qtb_fx_edit( $project, $e );
	}
	if ( null === $ti ) {
		return new WP_Error( 'qtb_ws', 'موضوع پیدا نشد.' );
	}
	$t    = &$ws['topics'][ $ti ];
	$known_secs = array_merge( qtb_fx_section_names( $project ), array_filter( array_map( function ( $c ) {
		return trim( (string) ( $c['section'] ?? '' ) );
	}, $t['clusters'] ), 'strlen' ) );
	$sec_clean  = function ( $name ) use ( $known_secs ) {
		$name = qtb_mb_cut( sanitize_text_field( (string) $name ), 50 );
		if ( qtb_section_is_state( $name ) ) {
			return new WP_Error( 'qtb_ws', 'پارکینگ و حذف بخش نیستند، وضعیت کلمه‌اند: کلمه را روی خانه «پارکینگ» یا «حذف» بکشید.' );
		}
		return qtb_section_norm( $name, $known_secs );
	};
	$byid = array();
	foreach ( $t['clusters'] as $ci => $c ) {
		$byid[ $c['id'] ] = $ci;
	}
	$cl = function ( $id ) use ( $byid ) {
		return $byid[ (int) $id ] ?? null;
	};
	switch ( $op ) {
		case 'move': // {kw, to: cid|new|park|remove|topic:<tid>}.
			$i    = (int) $e['kw'];
			$to   = (string) $e['to'];
			$from = null;
			foreach ( $t['clusters'] as $ci => $c ) {
				if ( in_array( $i, $c['kws'], true ) ) {
					$from = $ci;
				}
			}
			if ( ! isset( $kws[ $i ] ) ) {
				return new WP_Error( 'qtb_ws', 'کلمه پیدا نشد.' );
			}
			$from_name = null !== $from ? $t['clusters'][ $from ]['name'] : ( array( 'remove' => 'حذف', 'park' => 'پارکینگ' )[ $kws[ $i ]['ws'] ?? '' ] ?? 'بدون کلاستر' );
			// A parked / removed keyword dropped on a cluster comes back.
			if ( 'park' !== $to && 'remove' !== $to && in_array( $kws[ $i ]['ws'] ?? 'keep', array( 'park', 'remove' ), true ) ) {
				$kws[ $i ]['ws'] = 'keep';
				$kws[ $i ]['tr'] = 'ok';
				$kws[ $i ]['tl'] = 1;
				$kws[ $i ]['tn'] = 'تصمیم ' . $who;
			}
			if ( null !== $from ) {
				$t['clusters'][ $from ]['kws']    = array_values( array_diff( $t['clusters'][ $from ]['kws'], array( $i ) ) );
				$t['clusters'][ $from ]['locked'] = 1;
			}
			if ( 'park' === $to || 'remove' === $to ) {
				$kws[ $i ]['ws'] = $to;
				$kws[ $i ]['tr'] = 'manual';
				$kws[ $i ]['tl'] = 1;
				$kws[ $i ]['tn'] = 'تصمیم ' . $who;
				$to_name         = 'park' === $to ? 'پارکینگ' : 'حذف';
			} elseif ( preg_match( '/^c:(\d+):(\d+)$/', $to, $mt ) && (int) $mt[1] !== (int) $t['id'] ) {
				$oi = qtb_ws_topic_index( $ws, (int) $mt[1] );
				$oc = null;
				foreach ( null !== $oi ? $ws['topics'][ $oi ]['clusters'] : array() as $k => $c ) {
					if ( (int) $c['id'] === (int) $mt[2] ) {
						$oc = $k;
					}
				}
				if ( null === $oc ) {
					return new WP_Error( 'qtb_ws', 'کلاستر مقصد پیدا نشد.' );
				}
				$t['kws']                                            = array_values( array_diff( $t['kws'], array( $i ) ) );
				$ws['topics'][ $oi ]['kws'][]                        = $i;
				$ws['topics'][ $oi ]['clusters'][ $oc ]['kws'][]     = $i;
				$ws['topics'][ $oi ]['clusters'][ $oc ]['meta'][ $i ] = array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' );
				$ws['topics'][ $oi ]['clusters'][ $oc ]['locked']    = 1;
				$ws['topics'][ $oi ]['clusters']                     = qtb_ws_tidy( $kws, $ws['topics'][ $oi ]['clusters'] );
				$to_name = $ws['topics'][ $oi ]['clusters'][ $oc ]['name'] ?? '';
				$to_name = '«' . $ws['topics'][ $oi ]['name'] . '» › ' . $to_name;
			} elseif ( 0 === strpos( $to, 'topic:' ) ) {
				$oi = qtb_ws_topic_index( $ws, (int) substr( $to, 6 ) );
				if ( null === $oi ) {
					return new WP_Error( 'qtb_ws', 'موضوع مقصد پیدا نشد.' );
				}
				$t['kws']                     = array_values( array_diff( $t['kws'], array( $i ) ) );
				$ws['topics'][ $oi ]['kws'][] = $i;
				$to_name                      = 'موضوع «' . $ws['topics'][ $oi ]['name'] . '»';
				// Visible on the other board right away (as its own cluster until that topic is processed again).
				if ( $ws['topics'][ $oi ]['clusters'] ) {
					$nc = qtb_ws_decide_pages( $project, $ws, $kws, array( array( 'id' => $ws['next_cid']++, 'name' => $kws[ $i ]['k'], 'kws' => array( $i ), 'vol' => 0, 'core' => $kws[ $i ]['core'], 'meta' => array( $i => array( 'conf' => 'low', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' ) ), 'serp' => null, 'serp_kw' => null, 'intent' => qtb_group_intent( $kws, array( $i ) ), 'page' => 'archive', 'archive' => 'category', 'locked' => 0 ) ) );
					$ws['topics'][ $oi ]['clusters'] = qtb_ws_tidy( $kws, array_merge( $ws['topics'][ $oi ]['clusters'], $nc ) );
				}
			} elseif ( 'new' === $to ) {
				$t['clusters'][] = array( 'id' => $ws['next_cid']++, 'name' => $kws[ $i ]['k'], 'kws' => array( $i ), 'vol' => 0, 'core' => $kws[ $i ]['core'], 'meta' => array( $i => array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' ) ), 'serp' => null, 'serp_kw' => null, 'intent' => qtb_group_intent( $kws, array( $i ) ), 'page' => 'archive', 'archive' => 'category', 'locked' => 1 );
				$to_name         = 'کلاستر جدید';
				$new_ci          = count( $t['clusters'] ) - 1;
				$t['clusters'][ $new_ci ] = qtb_ws_decide_pages( $project, $ws, $kws, array( $t['clusters'][ $new_ci ] ) )[0];
				if ( isset( $kws[ $i ] ) && ( $s = qtb_data_get( $pid, 'serps', array() )[ $i ] ?? null ) ) {
					$t['clusters'][ $new_ci ]['serp']    = qtb_serp_metrics( $s, $kws[ $i ]['k'], qtb_project_domain( $project ) );
					$t['clusters'][ $new_ci ]['serp_kw'] = $i;
				}
			} else {
				$ci = $cl( $to );
				if ( null === $ci ) {
					return new WP_Error( 'qtb_ws', 'کلاستر مقصد پیدا نشد.' );
				}
				$t['clusters'][ $ci ]['kws'][]       = $i;
				$t['clusters'][ $ci ]['meta'][ $i ]  = array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' );
				$t['clusters'][ $ci ]['locked']      = 1;
				$to_name                             = $t['clusters'][ $ci ]['name'];
			}
			$ws['corrections'][] = array( 'k' => $kws[ $i ]['k'], 'from' => $from_name, 'to' => $to_name, 'by' => $who, 'at' => qtb_now() );
			$ws['corrections']   = array_slice( $ws['corrections'], -80 );
			$t['metrics']['user_changes'] = (int) ( $t['metrics']['user_changes'] ?? 0 ) + 1;
			break;
		case 'merge': // {ids: [cid…]} into the first.
			$ids  = array_map( 'intval', (array) ( $e['ids'] ?? array() ) );
			$keep = $cl( $ids[0] ?? -1 );
			if ( null === $keep || count( $ids ) < 2 ) {
				return new WP_Error( 'qtb_ws', 'حداقل دو کلاستر انتخاب کنید.' );
			}
			foreach ( array_slice( $ids, 1 ) as $id ) {
				$oi = $cl( $id );
				if ( null !== $oi && $oi !== $keep ) {
					foreach ( $t['clusters'][ $oi ]['kws'] as $i ) {
						$ws['corrections'][] = array( 'k' => $kws[ $i ]['k'], 'from' => $t['clusters'][ $oi ]['name'], 'to' => $t['clusters'][ $keep ]['name'], 'by' => $who, 'at' => qtb_now() );
					}
					$t['metrics']['user_changes']   = (int) ( $t['metrics']['user_changes'] ?? 0 ) + count( $t['clusters'][ $oi ]['kws'] );
					$t['clusters'][ $keep ]['kws']  = array_merge( $t['clusters'][ $keep ]['kws'], $t['clusters'][ $oi ]['kws'] );
					$t['clusters'][ $keep ]['meta'] = $t['clusters'][ $keep ]['meta'] + $t['clusters'][ $oi ]['meta'];
					$t['clusters'][ $oi ]['kws']    = array();
				}
			}
			$t['clusters'][ $keep ]['locked'] = 1;
			$ws['corrections'] = array_slice( $ws['corrections'], -80 );
			break;
		case 'split_node': // {cid, node}: a sub-group merged into the cluster becomes its own page.
			$ci   = $cl( $e['cid'] ?? -1 );
			$node = (string) ( $e['node'] ?? '' );
			if ( null === $ci || '' === $node ) {
				return new WP_Error( 'qtb_ws', 'کلاستر یا زیرگروه پیدا نشد.' );
			}
			$want = qtb_fx_key_facets( $node );
			$move = array();
			foreach ( $t['clusters'][ $ci ]['kws'] as $i ) {
				$mk = (string) ( $t['clusters'][ $ci ]['meta'][ $i ]['node'] ?? '' );
				// The sub-group and the smaller ones under it (their facets include its facets).
				if ( '' !== $mk && $mk[0] === $node[0] && ! array_diff( $want, qtb_fx_key_facets( $mk ) ) ) {
					$move[] = (int) $i;
				}
			}
			if ( ! $move ) {
				return new WP_Error( 'qtb_ws', 'این زیرگروه دیگر در این کلاستر نیست.' );
			}
			$fxd    = qtb_fx_get( $pid );
			$facets = array();
			foreach ( $want as $f ) {
				$facets[ $f ] = qtb_fx_resolve( $fxd, $f )[1];
			}
			$meta = array();
			foreach ( $move as $i ) {
				$meta[ $i ] = array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user', 'node' => $node );
			}
			$t['clusters'][ $ci ]['kws']    = array_values( array_diff( $t['clusters'][ $ci ]['kws'], $move ) );
			$t['clusters'][ $ci ]['locked'] = 1;
			$nc = array( 'id' => $ws['next_cid']++, 'name' => $kws[ $move[0] ]['k'], 'kws' => $move, 'vol' => 0, 'core' => $kws[ $move[0] ]['core'], 'meta' => $meta, 'serp' => null, 'serp_kw' => null, 'intent' => qtb_group_intent( $kws, $move ), 'page' => 'archive', 'archive' => 'category', 'locked' => 1, 'fx' => array( 'key' => $node, 'facets' => $facets, 'info' => 'i' === $node[0], 'why' => 'user', 'ev' => null, 'review' => array(), 'virtual' => false ) );
			$nc = qtb_ws_decide_pages( $project, $ws, $kws, array( qtb_ws_tidy( $kws, array( $nc ) )[0] ) )[0];
			$nc = qtb_fx_pages_by_top3( array( $nc ), $kws, qtb_data_get( $pid, 'serps', array() ) )[0];
			if ( 'i' === $node[0] && empty( $nc['locked_page'] ) && 'archive' === $nc['page'] ) {
				$nc['page']    = 'article';
				$nc['archive'] = '';
			}
			$nc = qtb_fx_assign_sections( $project, $kws, array_merge( $t['clusters'], array( $nc ) ) );
			$t['clusters'] = $nc;
			foreach ( $move as $i ) {
				$ws['corrections'][] = array( 'k' => $kws[ $i ]['k'], 'from' => $t['clusters'][ $ci ]['name'], 'to' => 'صفحه جدا: ' . $kws[ $move[0] ]['k'], 'by' => $who, 'at' => qtb_now() );
			}
			$ws['corrections']            = array_slice( $ws['corrections'], -80 );
			$t['metrics']['user_changes'] = (int) ( $t['metrics']['user_changes'] ?? 0 ) + 1;
			break;
		case 'rename':
			$ci = $cl( $e['cid'] ?? -1 );
			if ( null === $ci ) {
				return new WP_Error( 'qtb_ws', 'کلاستر پیدا نشد.' );
			}
			$t['clusters'][ $ci ]['name']        = qtb_mb_cut( sanitize_text_field( (string) $e['name'] ), 80 );
			$t['clusters'][ $ci ]['locked_name'] = 1;
			$t['clusters'][ $ci ]['locked']      = 1;
			break;
		case 'page':
			$ci = $cl( $e['cid'] ?? -1 );
			$pt = qtb_page_value_parse( (string) ( $e['page'] ?? '' ) );
			if ( null === $ci || ! $pt ) {
				return new WP_Error( 'qtb_ws', 'نوع صفحه نامعتبر.' );
			}
			$t['clusters'][ $ci ]['page']        = $pt[0];
			$t['clusters'][ $ci ]['archive']     = $pt[1];
			$t['clusters'][ $ci ]['locked_page'] = 1;
			$t['clusters'][ $ci ]['locked']      = 1;
			$t['clusters'][ $ci ]['page_reason'] = 'انتخاب ' . $who;
			$t['metrics']['user_changes']        = (int) ( $t['metrics']['user_changes'] ?? 0 ) + 1;
			break;
		case 'lock':
			$ci = $cl( $e['cid'] ?? -1 );
			if ( null !== $ci ) {
				$t['clusters'][ $ci ]['locked'] = empty( $e['off'] ) ? 1 : 0;
			}
			break;
		case 'approve':
			$t['status'] = 'approved';
			foreach ( $t['clusters'] as &$c ) {
				$c['locked'] = 1;
			}
			unset( $c );
			break;
		case 'reopen':
			$t['status'] = 'ready';
			break;
		case 'mode':
			$t['mode'] = 'single' === ( $e['mode'] ?? '' ) ? 'single' : 'batch';
			break;
		case 'cluster_move': // {cid, to_tid, section?}: the whole cluster goes to another pillar.
			$ci = $cl( $e['cid'] ?? -1 );
			$oi = qtb_ws_topic_index( $ws, (int) ( $e['to_tid'] ?? 0 ) );
			if ( null === $ci || null === $oi || $oi === $ti ) {
				return new WP_Error( 'qtb_ws', 'کلاستر یا پیلار مقصد پیدا نشد.' );
			}
			$c           = $t['clusters'][ $ci ];
			$c['locked'] = 1;
			if ( isset( $e['section'] ) && '' !== trim( (string) $e['section'] ) ) {
				$c['section']        = qtb_mb_cut( sanitize_text_field( (string) $e['section'] ), 50 );
				$c['locked_section'] = 1;
			}
			array_splice( $t['clusters'], $ci, 1 );
			$t['kws']                        = array_values( array_diff( $t['kws'], $c['kws'] ) );
			$ws['topics'][ $oi ]['kws']      = array_values( array_unique( array_merge( $ws['topics'][ $oi ]['kws'], $c['kws'] ) ) );
			$ws['topics'][ $oi ]['clusters'][] = $c;
			$ws['topics'][ $oi ]['clusters'] = qtb_ws_tidy( $kws, $ws['topics'][ $oi ]['clusters'] );
			if ( 'new' === $ws['topics'][ $oi ]['status'] && ! array_diff( $ws['topics'][ $oi ]['kws'], array_merge( ...array_map( function ( $x ) {
				return $x['kws'];
			}, $ws['topics'][ $oi ]['clusters'] ) ) ) ) {
				$ws['topics'][ $oi ]['status'] = 'ready';
			}
			foreach ( $c['kws'] as $i ) {
				$ws['corrections'][] = array( 'k' => $kws[ $i ]['k'], 'from' => $t['name'], 'to' => 'پیلار «' . $ws['topics'][ $oi ]['name'] . '»', 'by' => $who, 'at' => qtb_now() );
			}
			$ws['corrections'] = array_slice( $ws['corrections'], -80 );
			break;
		case 'cluster_merge_into': // {cid, to_tid, to_cid}: into a cluster of this or another pillar.
			$ci = $cl( $e['cid'] ?? -1 );
			$oi = qtb_ws_topic_index( $ws, (int) ( $e['to_tid'] ?? 0 ) );
			if ( null === $ci || null === $oi ) {
				return new WP_Error( 'qtb_ws', 'کلاستر مقصد پیدا نشد.' );
			}
			$oc = null;
			foreach ( $ws['topics'][ $oi ]['clusters'] as $k => $x ) {
				if ( (int) $x['id'] === (int) ( $e['to_cid'] ?? -1 ) ) {
					$oc = $k;
				}
			}
			if ( null === $oc || ( $oi === $ti && $oc === $ci ) ) {
				return new WP_Error( 'qtb_ws', 'کلاستر مقصد پیدا نشد.' );
			}
			$c = $t['clusters'][ $ci ];
			foreach ( $c['kws'] as $i ) {
				$ws['corrections'][] = array( 'k' => $kws[ $i ]['k'], 'from' => $c['name'], 'to' => $ws['topics'][ $oi ]['clusters'][ $oc ]['name'], 'by' => $who, 'at' => qtb_now() );
			}
			$ws['corrections'] = array_slice( $ws['corrections'], -80 );
			$t['metrics']['user_changes'] = (int) ( $t['metrics']['user_changes'] ?? 0 ) + count( $c['kws'] );
			$ws['topics'][ $oi ]['clusters'][ $oc ]['kws']    = array_merge( $ws['topics'][ $oi ]['clusters'][ $oc ]['kws'], $c['kws'] );
			$ws['topics'][ $oi ]['clusters'][ $oc ]['meta']   = (array) $ws['topics'][ $oi ]['clusters'][ $oc ]['meta'] + (array) $c['meta'];
			$ws['topics'][ $oi ]['clusters'][ $oc ]['locked'] = 1;
			$t['clusters'][ $ci ]['kws']                      = array();
			if ( $oi !== $ti ) {
				$t['kws']                   = array_values( array_diff( $t['kws'], $c['kws'] ) );
				$ws['topics'][ $oi ]['kws'] = array_values( array_unique( array_merge( $ws['topics'][ $oi ]['kws'], $c['kws'] ) ) );
				$ws['topics'][ $oi ]['clusters'] = qtb_ws_tidy( $kws, $ws['topics'][ $oi ]['clusters'] );
			}
			break;
		case 'section': // {cid, section}: the cluster moves to another (or a new) section.
			$ci = $cl( $e['cid'] ?? -1 );
			if ( null === $ci ) {
				return new WP_Error( 'qtb_ws', 'کلاستر پیدا نشد.' );
			}
			$sn = $sec_clean( $e['section'] ?? '' );
			if ( is_wp_error( $sn ) ) {
				return $sn;
			}
			$t['clusters'][ $ci ]['section']        = $sn;
			$t['clusters'][ $ci ]['locked_section'] = 1;
			$t['clusters'][ $ci ]['locked']         = 1;
			break;
		case 'section_bulk': // {cids: [], section}: several clusters at once.
			$sn = $sec_clean( $e['section'] ?? '' );
			if ( is_wp_error( $sn ) ) {
				return $sn;
			}
			if ( '' === $sn ) {
				return new WP_Error( 'qtb_ws', 'نام بخش خالی است.' );
			}
			foreach ( array_map( 'intval', (array) ( $e['cids'] ?? array() ) ) as $id ) {
				$ci = $cl( $id );
				if ( null !== $ci ) {
					$t['clusters'][ $ci ]['section']        = $sn;
					$t['clusters'][ $ci ]['locked_section'] = 1;
					$t['clusters'][ $ci ]['locked']         = 1;
				}
			}
			break;
		case 'section_rename': // {from, to}: every cluster of the section.
			$from = trim( (string) ( $e['from'] ?? '' ) );
			$to   = $sec_clean( $e['to'] ?? '' );
			if ( is_wp_error( $to ) ) {
				return $to;
			}
			if ( '' === $from || '' === $to ) {
				return new WP_Error( 'qtb_ws', 'نام بخش خالی است.' );
			}
			foreach ( $t['clusters'] as &$c ) {
				if ( trim( (string) ( $c['section'] ?? '' ) ) === $from ) {
					$c['section']        = $to;
					$c['locked_section'] = 1;
				}
			}
			unset( $c );
			break;
		case 'topic_rename':
			$t['name'] = qtb_mb_cut( sanitize_text_field( (string) $e['name'] ), 40 );
			break;
		case 'comment':
			$t['comments'][] = array( 'by' => $who, 'at' => qtb_now(), 'text' => qtb_mb_cut( sanitize_textarea_field( (string) $e['text'] ), 1500 ) );
			break;
		default:
			return new WP_Error( 'qtb_ws', 'عملیات نامعتبر.' );
	}
	unset( $t );
	if ( null !== $ti ) {
		$ws['topics'][ $ti ]['clusters'] = qtb_ws_tidy( $kws, $ws['topics'][ $ti ]['clusters'] );
	}
	$ws['dirty'] = 1;
	qtb_data_put( $pid, 'keywords', $kws );
	qtb_ws_put( $pid, $ws );
	return true;
}

/**
 * Publish the workshop into the result (research file, page map, tabs) and wait for it:
 * after edits on the board the output follows without a manual «build» step.
 *
 * @return array|WP_Error job status
 */
function qtb_ws_publish_sync( $project, $budget = 50, $include_ready = null ) {
	$ws  = qtb_ws_get( $project['id'] );
	$res = qtb_ws_finalize( $project, null !== $include_ready ? (bool) $include_ready : ( ! isset( $ws['final_ready'] ) || ! empty( $ws['final_ready'] ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$t0 = microtime( true );
	do {
		$job = qtb_pipeline_tick( $project['id'], 20 );
		$st  = qtb_job_status( $job );
	} while ( ! $st['done'] && ! $st['error'] && empty( $st['paused'] ) && microtime( true ) - $t0 < $budget );
	return $st;
}

/**
 * The output must be rebuilt (the board or the competitors changed).
 */
function qtb_ws_mark_dirty( $pid ) {
	$ws = qtb_ws_get( $pid );
	if ( ! empty( $ws['finalized'] ) ) {
		$ws['dirty'] = 1;
		qtb_ws_put( $pid, $ws );
	}
}

/**
 * Publish only when the board changed since the last output.
 */
function qtb_ws_publish_if_dirty( $project ) {
	$ws = qtb_ws_get( $project['id'] );
	if ( empty( $ws['dirty'] ) || empty( $ws['topics'] ) || empty( $ws['finalized'] ) ) {
		return null;
	}
	return qtb_ws_publish_sync( $project );
}

/**
 * A project built before the workshop (or by the quick run): its result becomes workshop
 * pillars and locked clusters, so there is one place to edit everything.
 */
function qtb_ws_from_result( $project ) {
	$pid      = $project['id'];
	$ws       = qtb_ws_get( $pid );
	$clusters = qtb_data_get( $pid, 'clusters', array() );
	$pillars  = qtb_data_get( $pid, 'pillars', array() );
	$kws      = qtb_data_get( $pid, 'keywords', array() );
	if ( $ws['topics'] || ! $clusters || ! $pillars ) {
		return false;
	}
	$by_id = array();
	foreach ( $clusters as $c ) {
		$by_id[ $c['id'] ] = $c;
	}
	foreach ( $kws as &$kw ) {
		if ( ! isset( $kw['ws'] ) ) {
			$kw['ws'] = 'keep';
			$kw['tr'] = 'ok';
			$kw['tn'] = '';
		}
	}
	unset( $kw );
	foreach ( $pillars as $p ) {
		$topic           = qtb_ws_new_topic( $ws, $p['name'], array(), $project );
		$topic['status'] = 'approved';
		foreach ( $p['sections'] as $s ) {
			foreach ( $s['clusters'] as $cid ) {
				$c = $by_id[ $cid ] ?? null;
				if ( ! $c || ! $c['kws'] ) {
					continue;
				}
				$topic['clusters'][] = array(
					'id'             => $ws['next_cid']++,
					'name'           => $c['name'],
					'kws'            => array_values( array_map( 'intval', $c['kws'] ) ),
					'vol'            => (int) $c['vol'],
					'core'           => $c['core'] ?? array(),
					'meta'           => array_fill_keys( $c['kws'], array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'user' ) ),
					'serp'           => $c['serp'] ?? null,
					'serp_kw'        => null,
					'intent'         => $c['intent'] ?? 'm',
					'page'           => $c['page'],
					'archive'        => $c['archive'] ?? '',
					'page_reason'    => (string) ( $c['page_reason'] ?? '' ),
					'section'        => $s['name'],
					'locked'         => 1,
					'locked_page'    => 1,
					'locked_section' => 1,
					'locked_name'    => ! empty( $c['locked_name'] ) ? 1 : 0,
				);
				$topic['kws'] = array_merge( $topic['kws'], array_map( 'intval', $c['kws'] ) );
			}
		}
		if ( $topic['clusters'] ) {
			$ws['topics'][] = $topic;
		}
	}
	$ws['stage']       = 'review';
	$ws['finalized']   = (string) ( qtb_data_get( $pid, 'meta', array() )['built'] ?? qtb_now() );
	$ws['final_ready'] = 1;
	$ws['dirty']       = 0;
	qtb_data_put( $pid, 'keywords', $kws );
	qtb_ws_put( $pid, $ws );
	return true;
}

/**
 * Evidence of one keyword: its top 10, and the URLs it shares with its cluster head and the runner-up.
 */
function qtb_ws_evidence( $project, $tid, $i ) {
	$ws    = qtb_ws_get( $project['id'] );
	$ti    = qtb_ws_topic_index( $ws, $tid );
	$kws   = qtb_data_get( $project['id'], 'keywords', array() );
	$serps = qtb_data_get( $project['id'], 'serps', array() );
	if ( null === $ti || ! isset( $kws[ $i ] ) ) {
		return new WP_Error( 'qtb_ws', 'پیدا نشد.' );
	}
	$own  = null;
	foreach ( $ws['topics'][ $ti ]['clusters'] as $c ) {
		if ( in_array( (int) $i, $c['kws'], true ) ) {
			$own = $c;
		}
	}
	$s    = $serps[ $i ] ?? null;
	$out  = array(
		'k'      => $kws[ $i ]['k'],
		'v'      => (int) $kws[ $i ]['v'],
		'serp'   => $s ? array_map( function ( $o ) {
			return array( 'p' => $o['p'], 'url' => $o['url'], 'domain' => $o['domain'], 'title' => $o['title'], 'type' => qtb_result_type( $o ) );
		}, array_slice( (array) $s['organic'], 0, 10 ) ) : array(),
		'meta'   => $own ? ( $own['meta'][ $i ] ?? null ) : null,
		'shared' => array(),
		'comp'   => qtb_comp_evidence( $project, $kws, (int) $i, $own ? ( $own['kws'][0] === (int) $i ? ( $own['kws'][1] ?? null ) : $own['kws'][0] ) : null ),
	);
	if ( $own && $s ) {
		$head = $own['kws'][0] === (int) $i ? ( $own['kws'][1] ?? null ) : $own['kws'][0];
		if ( null !== $head && isset( $serps[ $head ] ) ) {
			$out['shared'][] = array( 'with' => $kws[ $head ]['k'], 'urls' => qtb_serp_shared_urls( $s, $serps[ $head ] ) );
		}
		$alt = $own['meta'][ $i ]['alt'] ?? null;
		if ( null !== $alt && isset( $serps[ $alt ] ) && $alt !== $head ) {
			$out['shared'][] = array( 'with' => $kws[ $alt ]['k'], 'urls' => qtb_serp_shared_urls( $s, $serps[ $alt ] ) );
		}
	}
	return $out;
}

/**
 * Finalize: approved (and optionally ready) topics → the research pipeline.
 */
function qtb_ws_finalize( $project, $include_ready = false ) {
	$pid      = $project['id'];
	$ws       = qtb_ws_get( $pid );
	$kws      = qtb_data_get( $pid, 'keywords', array() );
	$clusters = array();
	$used     = 0;
	$aside    = array(); // keyword → park|remove (from state sections).
	foreach ( $ws['topics'] as $t ) {
		if ( ! ( 'approved' === $t['status'] || ( $include_ready && 'ready' === $t['status'] ) ) ) {
			continue;
		}
		++$used;
		foreach ( qtb_ws_tidy( $kws, $t['clusters'] ) as $c ) {
			// A section named «پارک» / «حذف» (older boards) is a state: no page in the output.
			if ( qtb_section_is_state( $c['section'] ?? '' ) ) {
				foreach ( $c['kws'] as $i ) {
					$aside[ $i ] = preg_match( '/حذف|remove|delete/iu', (string) $c['section'] ) && ! preg_match( '/پارک|park/iu', (string) $c['section'] ) ? 'remove' : 'park';
				}
				continue;
			}
			$clusters[] = array_merge( $c, array(
				'head'           => $c['kws'][0],
				'checked'        => count( array_filter( $c['meta'], function ( $m ) {
					return 'serp' === ( $m['why'] ?? '' );
				} ) ),
				'locked_page'    => 1,
				'locked_section' => ! empty( $c['section'] ) ? 1 : 0,
				'ai'             => array( 'pillar' => $t['name'], 'section' => (string) ( $c['section'] ?? '' ), 'page' => $c['page'] ),
				'topic'          => $t['id'],
			) );
		}
	}
	if ( ! $clusters ) {
		return new WP_Error( 'qtb_ws_none', 'هنوز موضوعی تأیید نشده است.' );
	}
	foreach ( $clusters as $n => &$c ) {
		$c['id'] = $n;
	}
	unset( $c );
	// Triage removals go to the removed list (with the reason); parked keywords stay aside.
	$removed = array_values( array_filter( (array) qtb_data_get( $pid, 'removed', array() ), function ( $r ) {
		return empty( $r['ws'] );
	} ) );
	foreach ( $kws as $kw ) {
		if ( isset( $aside[ $kw['i'] ] ) && ! in_array( $kw['ws'] ?? '', array( 'remove', 'park' ), true ) ) {
			$park      = 'park' === $aside[ $kw['i'] ];
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'manual', 'd' => $park ? 'در بخش «پارک» برد بازبینی' : 'در بخش «حذف» برد بازبینی', 'ws' => 1, 'kw' => $kw['i'], 'ai' => 0, 'park' => $park ? 1 : 0 );
		}
		if ( 'remove' === ( $kw['ws'] ?? '' ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => in_array( $kw['tr'], array_keys( qtb_remove_reasons() ), true ) ? $kw['tr'] : 'irrelevant', 'd' => (string) ( $kw['tn'] ?? '' ), 'ws' => 1, 'kw' => $kw['i'], 'ai' => 'rule' !== $kw['tr'] && 'manual' !== $kw['tr'] ? 1 : 0 );
		}
	}
	qtb_data_put( $pid, 'removed', $removed );
	qtb_data_put( $pid, 'clusters_wip', $clusters );
	qtb_data_put( $pid, 'ws_final', array( 'ok' => true, 'topics' => $used, 'clusters' => count( $clusters ) ) );
	$ws['finalized']   = qtb_now();
	$ws['final_ready'] = $include_ready ? 1 : 0;
	$ws['dirty']       = 0;
	qtb_ws_put( $pid, $ws );
	qtb_pipeline_start( $project, 'upload', 'structure', array( 'ws' => 1 ) );
	return array( 'ok' => true, 'topics' => $used, 'clusters' => count( $clusters ) );
}

/**
 * Comparison of the two review modes over all processed topics.
 */
function qtb_ws_mode_stats( $ws ) {
	$out = array();
	foreach ( $ws['topics'] as $t ) {
		foreach ( array_merge( (array) $t['history'], $t['metrics'] ? array( $t['metrics'] ) : array() ) as $m ) {
			if ( empty( $m['mode'] ) || empty( $m['kws'] ) ) {
				continue;
			}
			$k = $m['mode'];
			if ( ! isset( $out[ $k ] ) ) {
				$out[ $k ] = array( 'runs' => 0, 'kws' => 0, 'cost' => 0, 'seconds' => 0, 'calls' => 0, 'ai_changes' => 0, 'user_changes' => 0 );
			}
			$out[ $k ]['runs']++;
			$out[ $k ]['kws']          += $m['kws'];
			$out[ $k ]['cost']         += $m['ai_cost'];
			$out[ $k ]['seconds']      += $m['seconds'];
			$out[ $k ]['calls']        += $m['ai_calls'];
			$out[ $k ]['ai_changes']   += $m['ai_changes'];
			$out[ $k ]['user_changes'] += (int) ( $m['user_changes'] ?? 0 );
		}
	}
	return $out;
}
