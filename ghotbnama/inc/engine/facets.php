<?php
/**
 * Facet engine: «first the structure, then the clusters».
 *
 * 1. Facet dictionary (one per project): every distinct word of the kept keywords
 *    gets a role — a facet type of THIS business (brand, material, city, service…,
 *    proposed by the AI from the profile and the words), «mod» (wording that does not
 *    change the page: خرید، قیمت، شیک…), «info» (the searcher wants to learn → blog) —
 *    and a canonical spelling (کارتیه → کارتیر, only when the business treats them as
 *    one page). The reviewer edits one small table instead of moving thousands of
 *    keywords. Nothing here is niche-specific: the dictionary is data of the project.
 * 2. Page tree of a topic: a keyword's key is the set of its facet words. Same key =
 *    same page. A node's parent is the node with one facet less (the biggest one).
 * 3. Split or merge, from the leaves up. Dynamic threshold: a node is a page of its
 *    own by default when its volume is at least «split_pct»% of its parent's biggest
 *    keyword (a project setting). Google decides the borderline cases: the top 10 of
 *    the node and of its parent — or, without a SERP, the competitors' ranking pages —
 *    confirm a merge or keep the node apart, both ways. A small node with no evidence
 *    merges into its parent and is flagged for review when it is close to the line.
 * 4. Sections: a fixed skeleton (main page, features, products / services, blog,
 *    landings) plus the facet types whose share of the topic's demand reaches
 *    «promote_pct»% (e.g. brands). «Park» and «delete» are states, never sections.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Dictionary.
// ---------------------------------------------------------------------------

/**
 * Reserved roles (every project has them).
 */
function qtb_fx_reserved() {
	return array(
		'mod'   => array( 'label' => 'بی‌اثر', 'plural' => '', 'hint' => 'صفحه را عوض نمی‌کند: خرید، قیمت، شیک، جدید…' ),
		'info'  => array( 'label' => 'آموزشی', 'plural' => 'بلاگ', 'hint' => 'جستجوگر می‌خواهد یاد بگیرد؛ کلمه به بلاگ می‌رود' ),
		'kind'  => array( 'label' => 'نوع محصول', 'plural' => 'دسته‌ها', 'hint' => 'نام محصول یا دسته اصلی' ),
		'other' => array( 'label' => 'سایر', 'plural' => 'ویژگی‌ها', 'hint' => 'ویژگی بدون نوع مشخص' ),
	);
}

/**
 * Facet dictionary of a project.
 *
 * @return array{types: array, tokens: array, built: string, ai: int, edited: string}
 */
function qtb_fx_get( $pid ) {
	return wp_parse_args( qtb_data_get( $pid, 'facets', array() ), array(
		'types'  => array(), // id → {label, plural}.
		'tokens' => array(), // token → {t: type id, c: canonical token, by: ai|rule|user}.
		'built'  => '',
		'ai'     => 0,
		'edited' => '',
	) );
}

/**
 * Save the dictionary.
 */
function qtb_fx_put( $pid, $fx ) {
	qtb_data_put( $pid, 'facets', $fx );
}

/**
 * All types: the project's own and the reserved roles.
 *
 * @return array id → {label, plural}
 */
function qtb_fx_types( $fx ) {
	$out = array();
	foreach ( (array) $fx['types'] as $id => $t ) {
		$out[ $id ] = array( 'label' => (string) $t['label'], 'plural' => (string) ( $t['plural'] ?? $t['label'] ) );
	}
	foreach ( qtb_fx_reserved() as $id => $t ) {
		if ( ! isset( $out[ $id ] ) ) {
			$out[ $id ] = array( 'label' => $t['label'], 'plural' => $t['plural'] );
		}
	}
	return $out;
}

/**
 * Word statistics of keywords: token → {n, v, ex[]} (core words only, see qtb_annotate).
 *
 * @param array $kws Keywords (annotated).
 */
function qtb_fx_vocab( $kws ) {
	$out = array();
	foreach ( $kws as $i => $kw ) {
		foreach ( array_unique( (array) ( $kw['core'] ?? array() ) ) as $t ) {
			$t = (string) $t;
			if ( '' === $t ) {
				continue;
			}
			if ( ! isset( $out[ $t ] ) ) {
				$out[ $t ] = array( 'n' => 0, 'v' => 0, 'ex' => array() );
			}
			$out[ $t ]['n']++;
			$out[ $t ]['v'] += (int) $kw['v'];
			if ( count( $out[ $t ]['ex'] ) < 3 ) {
				$out[ $t ]['ex'][] = (int) $i;
			}
		}
	}
	uasort( $out, function ( $a, $b ) {
		return $b['v'] <=> $a['v'];
	} );
	return $out;
}

/**
 * Rule labels before the AI (and the whole dictionary when no AI is connected):
 * learning words → info, cities → city, brand words of the triage and the profile → brand.
 *
 * @return array token → {t, c, by}
 */
function qtb_fx_seed( $project, $kws, $tokens ) {
	$info  = qtb_lex_set( 'informational' ) + qtb_lex_set( 'comparison' );
	$local = qtb_lex_set( 'local' );
	$trans = qtb_lex_set( 'transactional' ) + qtb_lex_set( 'soft' );
	$brand = array();
	foreach ( $kws as $kw ) {
		foreach ( qtb_tokens( (string) ( $kw['brand_tok'] ?? '' ) ) as $t ) {
			$brand[ $t ] = true;
		}
	}
	foreach ( array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) as $b ) {
		foreach ( qtb_tokens( $b ) as $t ) {
			$brand[ $t ] = true;
		}
	}
	$out = array();
	foreach ( $tokens as $t ) {
		$type = '';
		if ( isset( $info[ $t ] ) ) {
			$type = 'info';
		} elseif ( isset( $trans[ $t ] ) ) {
			$type = 'mod';
		} elseif ( isset( $local[ $t ] ) ) {
			$type = 'city';
		} elseif ( isset( $brand[ $t ] ) ) {
			$type = 'brand';
		}
		if ( '' !== $type ) {
			$out[ $t ] = array( 't' => $type, 'c' => $t, 'by' => 'rule' );
		}
	}
	return $out;
}

/**
 * Types the rule seed may use (added to the project when used).
 */
function qtb_fx_seed_types() {
	return array(
		'brand' => array( 'label' => 'برند', 'plural' => 'برندها' ),
		'city'  => array( 'label' => 'شهر', 'plural' => 'شهرها' ),
	);
}

/**
 * Words of the active keywords that the dictionary does not know yet.
 *
 * @return array token → {n, v, ex}
 */
function qtb_fx_missing( $fx, $kws ) {
	$vocab = qtb_fx_vocab( qtb_ws_active( $kws ) );
	return array_diff_key( $vocab, (array) $fx['tokens'] );
}

/**
 * Label a batch of words with the AI (the first batch also defines the facet types).
 *
 * @param array $batch token → {n, v, ex}
 * @return array|WP_Error {types: id → {label, plural}, tokens: token → {t, c}}
 */
function qtb_ai_facet_batch( $project, $ws, $fx, $batch, $kws ) {
	$lines = array();
	foreach ( $batch as $t => $s ) {
		$ex = array();
		foreach ( $s['ex'] as $i ) {
			if ( isset( $kws[ $i ] ) ) {
				$ex[] = $kws[ $i ]['k'];
			}
		}
		$lines[] = $t . '|' . $s['n'] . '|' . $s['v'] . '|' . implode( '، ', array_slice( $ex, 0, 2 ) );
	}
	$known = array();
	foreach ( (array) $fx['types'] as $id => $ty ) {
		$known[] = $id . ' = ' . $ty['label'] . ' (بخش: ' . ( $ty['plural'] ?? $ty['label'] ) . ')';
	}
	$system = 'تو مدیر ارشد سئو و معمار ساختار سایت هستی. واژه‌های تشکیل‌دهنده کلمات کلیدی یک پروژه را نقش‌گذاری می‌کنی تا ساختار صفحات (صفحه اصلی، صفحات ویژگی، بلاگ) از روی آن ساخته شود. فقط بر اساس همین کسب‌وکار و همین کلمات تصمیم بگیر؛ هیچ قالب از پیش آماده‌ای برای حوزه دیگری به کار نبر.';
	$user   = qtb_ws_memory_text( $project, $ws ) .
		"\n## نوع‌های ویژگی این پروژه\n" . ( $known ? implode( "\n", $known ) : '(هنوز تعریف نشده: از روی واژه‌های زیر ۳ تا ۱۰ نوع ویژگی مخصوص همین کسب‌وکار تعریف کن)' ) .
		"\n\n## کار\nهر خط: واژه|تعداد کلمات|حجم کل|نمونه. برای هر واژه بده:\n" .
		"- type: یکی از شناسه‌های نوع ویژگی پروژه، یا یکی از نقش‌های ثابت:\n" .
		"  mod = بی‌اثر؛ صفحه را عوض نمی‌کند (خرید، قیمت، شیک، جدید، ارزان، اصل…)\n" .
		"  info = نشانه نیت آموزشی و اطلاعاتی؛ کلمه مقاله بلاگ می‌شود (نگهداری، معنی، سایز، عکس، روش، تفاوت، بهترین…)\n" .
		"  kind = نام محصول یا دسته اصلی کسب‌وکار (نه ویژگی آن)\n" .
		"  other = ویژگی است ولی در هیچ نوعی جا نمی‌شود\n" .
		"- canon: اگر این واژه با واژه دیگری در همین فهرست یک مفهوم است، واژه اصلی (پرجستجوتر) را بنویس؛ وگرنه خود واژه. سه حالت: املای دیگر (کارتیه/کارتیر)، شکل دیگر همان واژه (چرمی/چرم)، و مترادف معنایی که مشتری با هر دو یک چیز را می‌خواهد (نازک/ظریف، اگر در این کسب‌وکار یک محصول‌اند). محتاط باش: دو ویژگی متفاوت (مثلاً دو رنگ، دو برند، زنانه/مردانه) هرگز یکی نمی‌شوند. گوگل هم بعداً مترادف‌ها را با نتایج بررسی می‌کند.\n" .
		"اگر نوع ویژگی تازه‌ای لازم است، آن را در types اضافه کن: id (انگلیسی کوتاه و کوچک)، label (نام مفرد فارسی)، plural (نام بخش در ساختار سایت، جمع فارسی).\n\n" .
		"## واژه‌ها\n" . implode( "\n", $lines );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'types', 'items' ),
		'properties'           => array(
			'types' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'label', 'plural' ),
					'properties'           => array(
						'id'     => array( 'type' => 'string' ),
						'label'  => array( 'type' => 'string' ),
						'plural' => array( 'type' => 'string' ),
					),
				),
			),
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 't', 'type', 'canon' ),
					'properties'           => array(
						't'     => array( 'type' => 'string' ),
						'type'  => array( 'type' => 'string' ),
						'canon' => array( 'type' => 'string' ),
					),
				),
			),
		),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 9000, 'timeout' => 240 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	// Models answer in more than one shape (objects, «واژه|نوع|اصلی» strings, a map
	// keyed by the word): read them all, skip what cannot be read.
	$types = array();
	foreach ( qtb_fx_rows( $res['types'] ?? array(), array( 'id', 'label', 'plural' ) ) as $ty ) {
		$id = sanitize_key( (string) ( $ty['id'] ?? '' ) );
		if ( '' === $id || isset( qtb_fx_reserved()[ $id ] ) ) {
			continue;
		}
		$label        = (string) ( $ty['label'] ?? $id );
		$types[ $id ] = array(
			'label'  => qtb_mb_cut( qtb_display( $label ), 30 ),
			'plural' => qtb_mb_cut( qtb_display( (string) ( ( $ty['plural'] ?? '' ) ?: $label ) ), 30 ),
		);
	}
	$valid  = array_merge( array_keys( (array) $fx['types'] ), array_keys( $types ), array_keys( qtb_fx_reserved() ) );
	$tokens = array();
	foreach ( qtb_fx_rows( $res['items'] ?? ( $res['tokens'] ?? array() ), array( 't', 'type', 'canon' ) ) as $it ) {
		$t = qtb_norm_key( (string) ( $it['t'] ?? ( $it['token'] ?? ( $it['word'] ?? '' ) ) ) );
		if ( '' === $t || ! isset( $batch[ $t ] ) ) {
			continue;
		}
		$type = sanitize_key( (string) ( $it['type'] ?? ( $it['role'] ?? '' ) ) );
		$c    = qtb_norm_key( (string) ( $it['canon'] ?? ( $it['canonical'] ?? '' ) ) );
		$tokens[ $t ] = array(
			't'  => in_array( $type, $valid, true ) ? $type : 'other',
			'c'  => '' !== $c && false === strpos( $c, ' ' ) ? $c : $t,
			'by' => 'ai',
		);
	}
	if ( ! $tokens && $batch ) {
		return new WP_Error( 'qtb_ai_parse', 'پاسخ هوش مصنوعی برای نقش واژه‌ها قابل خواندن نبود.' );
	}
	return array( 'types' => $types, 'tokens' => $tokens );
}

/**
 * Rows of an AI answer list in any shape: [{…}], ["a|b|c"], or {key: {…}} / {key: "b|c"}.
 *
 * @param mixed    $list
 * @param string[] $keys Field names, in the order of a «|»-separated string.
 * @return array[]
 */
function qtb_fx_rows( $list, $keys ) {
	if ( is_string( $list ) ) {
		$list = preg_split( '/\r?\n/', $list );
	}
	$out = array();
	foreach ( (array) $list as $k => $row ) {
		if ( is_string( $row ) ) {
			$parts = array_map( 'trim', preg_split( '/\s*[|=:،,]\s*/u', $row ) );
			if ( is_string( $k ) ) {
				array_unshift( $parts, $k );
			}
			$row = array();
			foreach ( $keys as $n => $f ) {
				if ( isset( $parts[ $n ] ) && '' !== $parts[ $n ] ) {
					$row[ $f ] = $parts[ $n ];
				}
			}
		} elseif ( is_array( $row ) ) {
			if ( is_string( $k ) && ! isset( $row[ $keys[0] ] ) ) {
				$row[ $keys[0] ] = $k;
			}
		} else {
			continue;
		}
		foreach ( $row as $f => $v ) {
			if ( ! is_scalar( $v ) ) {
				unset( $row[ $f ] );
			}
		}
		if ( $row ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * Bring the dictionary up to date with the active keywords: rules first, then the AI
 * in batches of 200 words (biggest first, so the first batch defines the types).
 * Resumable: returns false when the time slice ends.
 *
 * @return bool|WP_Error
 */
function qtb_fx_build( $project, &$job, $deadline ) {
	$pid  = $project['id'];
	$kws  = qtb_data_get( $pid, 'keywords', array() );
	$ws   = qtb_ws_get( $pid );
	$fx   = qtb_fx_get( $pid );
	$miss = qtb_fx_missing( $fx, $kws );
	if ( ! $miss ) {
		return true;
	}
	if ( ! isset( $job['sub']['fx_total'] ) ) {
		$job['sub']['fx_total'] = count( $miss );
		qtb_job_log( $job, sprintf( 'فرهنگ ویژگی‌ها: %s واژه تازه برای نقش‌گذاری.', qtb_num( count( $miss ) ) ) );
	}
	$seed = qtb_fx_seed( $project, $kws, array_keys( $miss ) );
	if ( ! qtb_ai_connected() ) {
		foreach ( $miss as $t => $s ) {
			$fx['tokens'][ $t ] = $seed[ $t ] ?? array( 't' => 'other', 'c' => $t, 'by' => 'rule' );
		}
		foreach ( qtb_fx_seed_types() as $id => $ty ) {
			if ( ! isset( $fx['types'][ $id ] ) && in_array( $id, array_column( $fx['tokens'], 't' ), true ) ) {
				$fx['types'][ $id ] = $ty;
			}
		}
		$fx['built'] = qtb_now();
		qtb_fx_put( $pid, $fx );
		qtb_job_log( $job, 'هوش مصنوعی متصل نیست؛ فرهنگ ویژگی‌ها با قوانین ساخته شد. نوع واژه‌ها را در «ویژگی‌ها» کامل کنید.', 'warn' );
		return true;
	}
	// Learning words and cities need no AI call.
	foreach ( $seed as $t => $row ) {
		if ( in_array( $row['t'], array( 'info', 'city' ), true ) ) {
			$fx['tokens'][ $t ] = $row;
			if ( 'city' === $row['t'] && ! isset( $fx['types']['city'] ) ) {
				$fx['types']['city'] = qtb_fx_seed_types()['city'];
			}
			unset( $miss[ $t ] );
		}
	}
	while ( $miss ) {
		$batch = array_slice( $miss, 0, 200, true );
		$res   = qtb_ai_facet_batch( $project, $ws, $fx, $batch, $kws );
		if ( is_wp_error( $res ) ) {
			$job['sub']['fx_fail'] = (int) ( $job['sub']['fx_fail'] ?? 0 ) + 1;
			if ( $job['sub']['fx_fail'] > 2 || in_array( $res->get_error_code(), array( 'qtb_ai_budget', 'qtb_ai_auth', 'qtb_ai_key' ), true ) ) {
				// Whatever is left gets the rule labels; the tree still works.
				foreach ( $miss as $t => $s ) {
					$fx['tokens'][ $t ] = $seed[ $t ] ?? array( 't' => 'other', 'c' => $t, 'by' => 'rule' );
				}
				qtb_fx_put( $pid, $fx );
				qtb_job_log( $job, 'نقش‌گذاری هوشمند واژه‌ها ناقص ماند: ' . $res->get_error_message() . ' — بقیه با قوانین برچسب خورد.', 'warn' );
				return true;
			}
			qtb_fx_put( $pid, $fx );
			return false;
		}
		$fx['types'] = array_merge( (array) $fx['types'], $res['types'] );
		foreach ( $batch as $t => $s ) {
			$row = $res['tokens'][ $t ] ?? ( $seed[ $t ] ?? array( 't' => 'other', 'c' => $t, 'by' => 'rule' ) );
			// The profile's own brands / competitors stay brands.
			if ( isset( $seed[ $t ] ) && 'brand' === $seed[ $t ]['t'] && in_array( $row['t'], array( 'other', 'mod' ), true ) ) {
				$row['t'] = isset( $fx['types']['brand'] ) ? 'brand' : $row['t'];
			}
			$fx['tokens'][ $t ] = $row;
			unset( $miss[ $t ] );
		}
		$fx['ai']++;
		$job['sub']['frac'] = min( 0.99, 1 - count( $miss ) / max( 1, (int) $job['sub']['fx_total'] ) );
		qtb_fx_put( $pid, $fx );
		if ( $miss && microtime( true ) >= $deadline ) {
			return false;
		}
	}
	// A canonical word must itself be known (an AI canon outside the list stays as written).
	foreach ( $fx['tokens'] as $t => &$row ) {
		if ( $row['c'] !== $t && ! isset( $fx['tokens'][ $row['c'] ] ) ) {
			$row['c'] = $t;
		}
	}
	unset( $row );
	$fx['built'] = qtb_now();
	qtb_fx_put( $pid, $fx );
	$by = array_count_values( array_column( $fx['tokens'], 't' ) );
	qtb_job_log( $job, sprintf( 'فرهنگ ویژگی‌ها آماده شد: %s واژه، %s نوع ویژگی (%s بی‌اثر، %s آموزشی).', qtb_num( count( $fx['tokens'] ) ), qtb_num( count( $fx['types'] ) ), qtb_num( $by['mod'] ?? 0 ), qtb_num( $by['info'] ?? 0 ) ) );
	return true;
}

/**
 * Resolve a word: canonical form and type (canonical chains followed).
 *
 * @return array{0: string, 1: string} [canonical token, type]
 */
function qtb_fx_resolve( $fx, $t ) {
	$seen = 0;
	while ( isset( $fx['tokens'][ $t ] ) && $fx['tokens'][ $t ]['c'] !== $t && $seen++ < 4 ) {
		$t = $fx['tokens'][ $t ]['c'];
	}
	return array( $t, (string) ( $fx['tokens'][ $t ]['t'] ?? 'other' ) );
}

// ---------------------------------------------------------------------------
// Page tree.
// ---------------------------------------------------------------------------

/**
 * Minimum volume of a page of its own (below it a keyword never gets a page; it joins
 * the most similar or the nearest page): the project setting, or automatically half of
 * «split_pct» of the topic's biggest keyword (5% at the default), and at least a tenth of
 * «split_pct» of the project's biggest keyword (1%) so tiny topics stay small.
 */
function qtb_fx_floor( $project, $sub = null ) {
	$set = (int) ( $project['cfg']['min_page'] ?? 0 );
	if ( $set > 0 ) {
		return $set;
	}
	$pct  = max( 1, (float) ( $project['cfg']['split_pct'] ?? 10 ) ) / 100;
	$pmax = 0;
	foreach ( qtb_ws_active( qtb_data_get( $project['id'], 'keywords', array() ) ) as $kw ) {
		$pmax = max( $pmax, (int) $kw['v'] );
	}
	$tmax = 0;
	foreach ( null !== $sub ? $sub : array() as $kw ) {
		$tmax = max( $tmax, (int) $kw['v'] );
	}
	// Half the threshold of the topic's biggest keyword, but never below a tenth of the
	// threshold of the project's biggest one (tiny topics do not get pages of 25 searches).
	return (int) round( max( $tmax * $pct / 2, $pmax * $pct / 10 ) );
}

/**
 * Facet signature of a keyword inside a topic.
 *
 * @param array $base Topic base words (the pillar's own noun, e.g. the product of the topic).
 * @return array{key: string, facets: array, info: bool}
 */
function qtb_fx_sig( $fx, $kw, $base ) {
	$facets = array(); // canonical token → type.
	// Learning intent: the triage AI said so, a dictionary word is «info», or a learning
	// word outside the core (روش، چگونه، چیست… are not dictionary words). A core word the
	// dictionary gave another role («اسم» in «دستبند اسم» = a product) does not count.
	$info = 'i' === (string) ( $kw['ai_intent'] ?? '' );
	if ( ! $info ) {
		$lex  = qtb_lex_set( 'informational' ) + qtb_lex_set( 'comparison' );
		$core = array_flip( (array) ( $kw['core'] ?? array() ) );
		foreach ( (array) ( $kw['toks'] ?? array() ) as $t ) {
			if ( ! isset( $core[ $t ] ) && isset( $lex[ $t ] ) ) {
				$info = true;
				break;
			}
		}
	}
	foreach ( (array) ( $kw['core'] ?? array() ) as $t ) {
		list( $c, $type ) = qtb_fx_resolve( $fx, (string) $t );
		if ( 'mod' === $type || isset( $base[ $c ] ) ) {
			continue;
		}
		if ( 'info' === $type ) {
			$info = true;
		}
		$facets[ $c ] = $type;
	}
	ksort( $facets );
	return array( 'key' => ( $info ? 'i:' : 'c:' ) . implode( ' ', array_keys( $facets ) ), 'facets' => $facets, 'info' => $info );
}

/**
 * Base words of a topic: canonical words present in at least 40% of its keywords
 * (the product of the topic — «دستبند» in the topic «دستبند»).
 */
function qtb_fx_base( $fx, $sub ) {
	$df = array();
	foreach ( $sub as $kw ) {
		$seen = array();
		foreach ( (array) ( $kw['core'] ?? array() ) as $t ) {
			list( $c, $type ) = qtb_fx_resolve( $fx, (string) $t );
			if ( 'mod' !== $type && 'info' !== $type ) {
				$seen[ $c ] = true;
			}
		}
		foreach ( $seen as $c => $x ) {
			$df[ $c ] = ( $df[ $c ] ?? 0 ) + 1;
		}
	}
	$n   = max( 1, count( $sub ) );
	$out = array();
	foreach ( $df as $c => $f ) {
		if ( $f / $n >= 0.4 && $n >= 3 ) {
			$out[ $c ] = true;
		}
	}
	return $out;
}

/**
 * URLs that rank for almost everything in a topic (home / search pages): no evidence.
 */
function qtb_fx_hubs( $serps ) {
	$freq = array();
	foreach ( $serps as $s ) {
		$seen = array();
		foreach ( (array) ( $s['organic'] ?? array() ) as $o ) {
			$u = qtb_norm_url( $o['url'] );
			if ( '' !== $u && ! isset( $seen[ $u ] ) ) {
				$seen[ $u ]   = true;
				$freq[ $u ] = ( $freq[ $u ] ?? 0 ) + 1;
			}
		}
	}
	$hub_at = max( 6, (int) ceil( count( $serps ) * 0.15 ) );
	$out    = array();
	foreach ( $freq as $u => $f ) {
		if ( qtb_is_hub_url( $u, $f, $hub_at ) ) {
			$out[ $u ] = true;
		}
	}
	return $out;
}

/**
 * Shared top-10 URLs of two keywords (hubs excluded), or -1 without both SERPs.
 */
function qtb_fx_shared( $serps, $hubs, $a, $b ) {
	if ( empty( $serps[ $a ]['organic'] ) || empty( $serps[ $b ]['organic'] ) ) {
		return -1;
	}
	$n = 0;
	foreach ( qtb_serp_shared_urls( $serps[ $a ], $serps[ $b ] ) as $s ) {
		if ( ! isset( $hubs[ qtb_norm_url( $s['url'] ) ] ) ) {
			++$n;
		}
	}
	return $n;
}

/**
 * Competitor evidence between two keyword sets (Seo Signal files): the same competitor
 * page ranks for both (same page) or the competitor ranks two different pages (apart).
 *
 * @return array|null {v: same|diff, note, strong}
 */
function qtb_fx_comp( $idx, $comps, $kws, $set_a, $set_b ) {
	if ( ! $idx ) {
		return null;
	}
	$pages = function ( $set ) use ( $idx, $kws ) {
		$out = array(); // ci → page → best rank.
		foreach ( array_slice( $set, 0, 12 ) as $i ) {
			if ( ! isset( $kws[ $i ] ) ) {
				continue;
			}
			foreach ( qtb_comp_hits( $idx, $kws[ $i ] ) as $h ) {
				if ( $h[1] > 10 || '' === (string) $h[2] || 'home' === qtb_comp_url_type( $h[2] ) ) {
					continue;
				}
				$out[ $h[0] ][ $h[2] ] = min( $out[ $h[0] ][ $h[2] ] ?? 99, (int) $h[1] );
			}
		}
		return $out;
	};
	$pa   = $pages( $set_a );
	$pb   = $pages( $set_b );
	$same = null;
	$diff = null;
	foreach ( $pa as $ci => $list ) {
		if ( ! isset( $pb[ $ci ] ) ) {
			continue;
		}
		foreach ( $list as $page => $ra ) {
			if ( isset( $pb[ $ci ][ $page ] ) ) {
				$strong = $ra <= 4 && $pb[ $ci ][ $page ] <= 4;
				if ( ! $same || $strong ) {
					$same = array( 'v' => 'same', 'strong' => $strong, 'note' => ( $comps[ $ci ]['domain'] ?? '' ) . ' ' . $page );
				}
			}
		}
		if ( ! $same ) {
			$diff = array( 'v' => 'diff', 'strong' => false, 'note' => ( $comps[ $ci ]['domain'] ?? '' ) . ': ' . key( $list ) . ' ≠ ' . key( $pb[ $ci ] ) );
		}
	}
	return $same ?: $diff;
}

/**
 * Nodes of a topic: key → {key, facets, info, kws[], own[], vol, head, lock}.
 *
 * @param array $sub    Topic keywords (index → kw).
 * @param array $free   Indices to place.
 * @param array $locked Locked clusters [{kws}] (they keep their keywords; same-key words join them).
 */
function qtb_fx_nodes( $fx, $sub, $free, $locked = array() ) {
	$base  = qtb_fx_base( $fx, $sub );
	$nodes = array();
	$add   = function ( $sig ) use ( &$nodes ) {
		if ( ! isset( $nodes[ $sig['key'] ] ) ) {
			$nodes[ $sig['key'] ] = array( 'key' => $sig['key'], 'facets' => $sig['facets'], 'info' => $sig['info'], 'own' => array(), 'lock' => null );
		}
	};
	foreach ( $locked as $li => $c ) {
		$head = $c['kws'][0] ?? null;
		if ( null === $head || ! isset( $sub[ $head ] ) ) {
			continue;
		}
		$sig = qtb_fx_sig( $fx, $sub[ $head ], $base );
		$add( $sig );
		if ( null === $nodes[ $sig['key'] ]['lock'] ) {
			$nodes[ $sig['key'] ]['lock'] = $li;
		}
	}
	foreach ( $free as $i ) {
		if ( ! isset( $sub[ $i ] ) ) {
			continue;
		}
		$sig = qtb_fx_sig( $fx, $sub[ $i ], $base );
		$add( $sig );
		$nodes[ $sig['key'] ]['own'][] = (int) $i;
	}
	foreach ( $nodes as &$n ) {
		usort( $n['own'], function ( $a, $b ) use ( $sub ) {
			return $sub[ $b ]['v'] <=> $sub[ $a ]['v'];
		} );
		$n['kws']  = $n['own'];
		$n['vol']  = array_sum( array_map( function ( $i ) use ( $sub ) {
			return (int) $sub[ $i ]['v'];
		}, $n['own'] ) );
		$lk        = null !== $n['lock'] ? (array) $locked[ $n['lock'] ]['kws'] : array();
		$n['head'] = $n['own'][0] ?? ( $lk[0] ?? null );
		$n['hv']   = null !== $n['head'] ? (int) $sub[ $n['head'] ]['v'] : 0;
		if ( $lk ) {
			$n['hv']  = max( $n['hv'], (int) ( $sub[ $lk[0] ]['v'] ?? 0 ) );
			$n['vol'] += array_sum( array_map( function ( $i ) use ( $sub ) {
				return (int) ( $sub[ $i ]['v'] ?? 0 );
			}, $lk ) );
		}
	}
	unset( $n );
	return array( 'nodes' => $nodes, 'base' => $base );
}

/**
 * Parent of a node: the node with one facet less (two, three… when missing) — the
 * biggest one; the topic root («c:» — the base alone) or, for blog nodes without a
 * learning parent, none.
 */
function qtb_fx_parent( $nodes, $key ) {
	$n = $nodes[ $key ];
	$f = array_keys( $n['facets'] );
	$p = $n['info'] ? 'i:' : 'c:';
	for ( $drop = 1; $drop < count( $f ); $drop++ ) {
		$best = null;
		foreach ( qtb_combinations( $f, count( $f ) - $drop ) as $sub ) {
			$k = $p . implode( ' ', $sub );
			if ( isset( $nodes[ $k ] ) && ( null === $best || $nodes[ $k ]['vol'] > $nodes[ $best ]['vol'] ) ) {
				$best = $k;
			}
		}
		if ( null !== $best ) {
			return $best;
		}
	}
	if ( count( $f ) > 8 ) {
		return null;
	}
	if ( $n['info'] ) {
		// A blog node with no learning parent joins the topic's blog root (the commercial
		// node of the same facets is another intent, never its parent).
		return 'i:' !== $key && isset( $nodes['i:'] ) ? 'i:' : null;
	}
	return 'c:' !== $key ? 'c:' : null;
}

/**
 * Overlap of two keywords' top 10 (hubs excluded): [shared, shared within both top 3],
 * or null without both SERPs.
 */
function qtb_fx_overlap( $serps, $hubs, $a, $b ) {
	if ( empty( $serps[ $a ]['organic'] ) || empty( $serps[ $b ]['organic'] ) ) {
		return null;
	}
	$n  = 0;
	$t3 = 0;
	foreach ( qtb_serp_shared_urls( $serps[ $a ], $serps[ $b ] ) as $u ) {
		if ( isset( $hubs[ qtb_norm_url( $u['url'] ) ] ) ) {
			continue;
		}
		++$n;
		$t3 += $u['pa'] <= 3 && $u['pb'] <= 3 ? 1 : 0;
	}
	return array( $n, $t3 );
}

/**
 * Assign every node to a page, the way an SEO manager does it by hand: biggest first;
 * each node is compared with EVERY page it could belong to — all pages whose facets
 * are a subset of its own (for «دستبند استیل کارتیر مردانه»: دستبند مردانه، دستبند
 * کارتیر مردانه، دستبند استیل مردانه، دستبند استیل، دستبند کارتیر، دستبند) and the
 * same-level pages that differ by one word of the same type (synonyms: نازک / ظریف).
 *
 *  - Similar to a page (shared top-10 URLs, top-3 counted more; several keywords of
 *    each side compared, the best pair wins) → joins the most similar page, any volume.
 *  - Similar to nothing, volume at least the line (pct of the nearest page above it,
 *    never below the topic floor) → a page of its own.
 *  - Similar to nothing and smaller → never a page: joins the nearest page (most facets
 *    in common, then the biggest), flagged for review when it is close to the line.
 *  - No Google result: the competitors' ranking pages decide; nothing at all → the line.
 *
 * @param array $opts {pct, need, floor, serps, hubs, comp_idx, comps, plan_only}
 * @return array {groups: [...], want: int[] keyword indices whose SERP would settle a decision}
 */
function qtb_fx_decide( $fx, $sub, $tree, $opts ) {
	$nodes = $tree['nodes'];
	$pct   = max( 1, min( 60, (float) $opts['pct'] ) ) / 100;
	$floor = max( 0, (int) ( $opts['floor'] ?? 0 ) );
	$need  = max( 2, min( 7, (int) $opts['need'] ) );
	$base  = max( 2, $need - 1 ); // Shared URLs for «similar» (3 at the default strictness).
	$serps = (array) ( $opts['serps'] ?? array() );
	$hubs  = (array) ( $opts['hubs'] ?? array() );
	$cidx  = $opts['comp_idx'] ?? array();
	$comps = $opts['comps'] ?? array();
	$want  = array();
	$maxv  = function ( $info ) use ( $nodes, $sub ) {
		$m = 0;
		foreach ( $nodes as $n ) {
			if ( $n['info'] === $info ) {
				$m = max( $m, (int) $n['hv'] );
			}
		}
		return $m;
	};
	// Roots: the topic's main page, and the blog's general article when there are learning searches.
	if ( ! isset( $nodes['c:'] ) ) {
		$nodes['c:'] = array( 'key' => 'c:', 'facets' => array(), 'info' => false, 'own' => array(), 'kws' => array(), 'vol' => 0, 'head' => null, 'hv' => 0, 'lock' => null );
	}
	if ( null === $nodes['c:']['head'] ) {
		$nodes['c:']['hv'] = $maxv( false );
	}
	$info_hv = $maxv( true );
	if ( $info_hv && ! isset( $nodes['i:'] ) ) {
		$nodes['i:'] = array( 'key' => 'i:', 'facets' => array(), 'info' => true, 'own' => array(), 'kws' => array(), 'vol' => 0, 'head' => null, 'hv' => $info_hv, 'lock' => null );
	} elseif ( isset( $nodes['i:'] ) && null === $nodes['i:']['head'] ) {
		$nodes['i:']['hv'] = $info_hv;
	}
	// Static tree only to estimate the demand a node would gather (its small descendants).
	$parent = array();
	$kids   = array();
	foreach ( array_keys( $nodes ) as $key ) {
		$parent[ $key ] = 'c:' === $key || 'i:' === $key ? null : qtb_fx_parent( $nodes, $key );
		if ( null !== $parent[ $key ] ) {
			$kids[ $parent[ $key ] ][] = $key;
		}
	}
	$memo = array();
	$est  = function ( $key ) use ( &$est, &$memo, $nodes, $kids, $floor ) {
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		$v = $nodes[ $key ]['vol'];
		foreach ( (array) ( $kids[ $key ] ?? array() ) as $k ) {
			$sv = $est( $k );
			if ( $sv < $floor ) {
				$v += $sv;
			}
		}
		return $memo[ $key ] = $v;
	};
	$order = array_keys( $nodes );
	usort( $order, function ( $a, $b ) use ( $nodes, $est ) {
		$ra = 'c:' === $a || 'i:' === $a ? 0 : ( null !== $nodes[ $a ]['lock'] ? 1 : 2 );
		$rb = 'c:' === $b || 'i:' === $b ? 0 : ( null !== $nodes[ $b ]['lock'] ? 1 : 2 );
		return $ra <=> $rb ?: $est( $b ) <=> $est( $a ) ?: count( $nodes[ $a ]['facets'] ) <=> count( $nodes[ $b ]['facets'] );
	} );
	$page    = array(); // node key → page key.
	$members = array(); // page key → keyword indices (biggest first as they arrive).
	$meta    = array();
	$why     = array();
	$evs     = array();
	$review  = array();
	// Up to $n keywords of a set that have a SERP (biggest first).
	$reps = function ( $list, $n ) use ( $serps, $sub ) {
		$out = array();
		foreach ( $list as $i ) {
			if ( ! empty( $serps[ $i ]['organic'] ) ) {
				$out[] = $i;
				if ( count( $out ) >= $n ) {
					break;
				}
			}
		}
		return $out;
	};
	foreach ( $order as $key ) {
		$n = $nodes[ $key ];
		if ( 'c:' === $key || 'i:' === $key || null !== $n['lock'] ) {
			$page[ $key ]    = $key;
			$members[ $key ] = $n['own'];
			$why[ $key ]     = null !== $n['lock'] ? 'locked' : ( 'i:' === $key ? 'article' : 'root' );
			continue;
		}
		$nf   = array_keys( $n['facets'] );
		$pre  = $n['info'] ? 'i:' : 'c:';
		$anc  = array(); // page keys above this node.
		$sib  = array(); // same-level pages that differ by one word of the same type.
		foreach ( array_keys( $members ) as $pk ) {
			if ( 0 !== strpos( $pk, $pre ) ) {
				continue;
			}
			$pf = array_keys( $nodes[ $pk ]['facets'] );
			if ( count( $pf ) < count( $nf ) && ! array_diff( $pf, $nf ) ) {
				$anc[] = $pk;
			} elseif ( count( $pf ) === count( $nf ) && $nf && 1 === count( array_diff( $nf, $pf ) ) ) {
				$x = array_values( array_diff( $nf, $pf ) )[0];
				$y = array_values( array_diff( $pf, $nf ) )[0];
				if ( $n['facets'][ $x ] === $nodes[ $pk ]['facets'][ $y ] && ! in_array( $n['facets'][ $x ], array( 'other', 'info' ), true ) ) {
					$sib[] = $pk;
				}
			}
		}
		if ( ! $anc && ! $n['info'] ) {
			$anc[] = 'c:';
		}
		// Nearest page above: the most facets in common, then the biggest.
		usort( $anc, function ( $a, $b ) use ( $nodes ) {
			return count( $nodes[ $b ]['facets'] ) <=> count( $nodes[ $a ]['facets'] ) ?: $nodes[ $b ]['hv'] <=> $nodes[ $a ]['hv'];
		} );
		$near = $anc[0] ?? null;
		$vol  = $est( $key );
		$line = $near ? max( $pct * max( 1, (int) $nodes[ $near ]['hv'] ), $n['info'] ? $floor / 3 : $floor ) : 0;
		// Compare with every candidate.
		$mine   = $reps( $n['own'], 2 );
		// Above the line only near-identical results merge (two wordings of one search).
		$big    = $near && $vol >= $line;
		$best   = null; // [page key, score, shared, top3, by, sibling?].
		$closest = null; // Best non-similar SERP candidate (for the evidence note).
		$any_ev = false;
		foreach ( array_merge( array_map( function ( $k ) {
			return array( $k, false );
		}, $anc ), array_map( function ( $k ) {
			return array( $k, true );
		}, $sib ) ) as $cand ) {
			list( $pk, $is_sib ) = $cand;
			$theirs = $reps( (array) ( $members[ $pk ] ?? array() ), 3 );
			$ov     = null;
			foreach ( $mine as $a ) {
				foreach ( $theirs as $b ) {
					$o = qtb_fx_overlap( $serps, $hubs, $a, $b );
					if ( $o && ( null === $ov || $o[0] + 0.5 * $o[1] > $ov[0] + 0.5 * $ov[1] ) ) {
						$ov = $o;
					}
				}
			}
			if ( null !== $ov ) {
				$any_ev  = true;
				$similar = $is_sib || $big ? $ov[0] >= $base + 2 : ( $ov[0] >= $base || ( $ov[0] >= $base - 1 && $ov[1] >= 1 ) );
				$score   = $ov[0] + 0.5 * $ov[1];
				if ( $similar && ( null === $best || $score > $best[1] ) ) {
					$best = array( $pk, $score, $ov[0], $ov[1], 'serp', $is_sib );
				} elseif ( ! $similar && ( null === $closest || $score > $closest[1] ) ) {
					$closest = array( $pk, $score, $ov[0] );
				}
				continue;
			}
			// No pair of SERPs: the competitors' pages.
			if ( $is_sib ) {
				continue;
			}
			$c = qtb_fx_comp( $cidx, $comps, $sub, $n['own'], (array) ( $members[ $pk ] ?? array() ) );
			if ( $c ) {
				$any_ev = true;
				if ( 'same' === $c['v'] && ( ! $big || $c['strong'] ) && ( null === $best || 2.5 > $best[1] ) ) {
					$best = array( $pk, 2.5, -1, 0, 'comp', false, $c['note'] );
				}
			}
			if ( ! empty( $opts['plan_only'] ) ) {
				foreach ( array_slice( (array) ( $members[ $pk ] ?? array() ), 0, 2 ) as $h ) {
					if ( ! isset( $serps[ $h ] ) ) {
						$want[ $h ] = max( $want[ $h ] ?? 0, $vol );
					}
				}
			}
		}
		if ( ! empty( $opts['plan_only'] ) ) {
			foreach ( array_slice( $n['own'], 0, $vol >= $floor ? 2 : 1 ) as $h ) {
				if ( ! isset( $serps[ $h ] ) ) {
					$want[ $h ] = max( $want[ $h ] ?? 0, $vol );
				}
			}
		}
		$to   = null;
		$m    = null;
		$flag = false;
		if ( $best ) {
			$to = $best[0];
			$m  = 'serp' === $best[4]
				? array( 'conf' => $best[2] >= $base + 2 ? 'high' : 'mid', 'shared' => $best[2], 'alt' => null, 'alt_shared' => 0, 'why' => $best[5] ? 'syn' : 'serp' )
				: array( 'conf' => 'mid', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'comp', 'comp' => $best[6] ?? '' );
		} elseif ( $near && $vol < $line ) {
			// Too small to stand alone: never a page.
			$to   = $near;
			$flag = $vol >= 0.3 * $line;
			$m    = array( 'conf' => $flag ? 'low' : 'mid', 'shared' => $closest ? (int) $closest[2] : -1, 'alt' => null, 'alt_shared' => 0, 'why' => $any_ev ? 'near' : 'tail' );
		}
		if ( null === $to ) {
			$page[ $key ]    = $key;
			$members[ $key ] = $n['own'];
			$why[ $key ]     = $any_ev ? ( $closest ? 'serp_diff' : 'comp_diff' ) : ( $near ? 'threshold' : ( $n['info'] ? 'article' : 'top' ) );
			if ( $closest ) {
				$evs[ $key ] = array( 'by' => 'serp', 'v' => 'diff', 'shared' => (int) $closest[2], 'note' => 'شبیه‌ترین صفحه: ' . ( isset( $nodes[ $closest[0] ]['head'] ) && null !== $nodes[ $closest[0] ]['head'] ? $sub[ $nodes[ $closest[0] ]['head'] ]['k'] : '' ) );
			}
			continue;
		}
		$page[ $key ] = $to;
		foreach ( $n['own'] as $i ) {
			$meta[ $i ] = $m + array( 'from' => $n['head'], 'node' => $key ) + ( $flag ? array( 'review' => 1 ) : array() );
			$members[ $to ][] = (int) $i;
		}
		usort( $members[ $to ], function ( $a, $b ) use ( $sub ) {
			return $sub[ $b ]['v'] <=> $sub[ $a ]['v'];
		} );
		if ( $flag && null !== $n['head'] ) {
			$review[ $to ][] = (int) $n['head'];
		}
	}
	if ( ! empty( $opts['plan_only'] ) ) {
		arsort( $want );
		return array( 'groups' => array(), 'want' => array_keys( $want ) );
	}
	$groups = array();
	foreach ( $nodes as $key => $n ) {
		if ( ( $page[ $key ] ?? null ) !== $key ) {
			continue;
		}
		$list = array_values( array_unique( array_map( 'intval', (array) ( $members[ $key ] ?? array() ) ) ) );
		if ( ! $list && null === $n['lock'] ) {
			continue;
		}
		$g = $list ? qtb_group_make( $sub, $list ) : array( 'kws' => array(), 'head' => null, 'core' => array(), 'ck' => '', 'vol' => 0 );
		$g['meta'] = array();
		foreach ( $list as $i ) {
			$g['meta'][ $i ] = $meta[ $i ] ?? array( 'conf' => 'high', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'facet' );
		}
		$g['fx'] = array(
			'key'     => $key,
			'facets'  => $n['facets'],
			'info'    => $n['info'],
			'why'     => $why[ $key ] ?? '',
			'ev'      => $evs[ $key ] ?? null,
			'review'  => array_values( array_unique( $review[ $key ] ?? array() ) ),
			'virtual' => ( 'c:' === $key || 'i:' === $key ) && ! $n['own'] && null === $n['lock'],
		);
		$g['lock'] = $n['lock'];
		$groups[]  = $g;
	}
	return array( 'groups' => $groups, 'want' => array() );
}

/**
 * Page type from Google's top 3 (what Google prefers for this search): the top 3 of up to
 * three keywords of the cluster. Two thirds shop pages (product / category / marketplace)
 * → a commercial page (product when product pages lead and the store has one matching
 * product, otherwise a category archive); two thirds articles → an article. A reviewer's
 * choice or a project rule always wins.
 *
 * @return array clusters
 */
function qtb_fx_pages_by_top3( $clusters, $kws, $serps ) {
	$shop_t = array( 'product', 'category', 'marketplace' );
	$info_t = array( 'article', 'video', 'forum', 'wiki' );
	foreach ( $clusters as &$c ) {
		$cnt = array();
		$n   = 0;
		$used = 0;
		foreach ( $c['kws'] as $i ) {
			if ( empty( $serps[ $i ]['organic'] ) ) {
				continue;
			}
			foreach ( array_slice( (array) $serps[ $i ]['organic'], 0, 3 ) as $o ) {
				$t         = qtb_result_type( $o );
				$cnt[ $t ] = ( $cnt[ $t ] ?? 0 ) + 1;
				++$n;
			}
			if ( ++$used >= 3 ) {
				break;
			}
		}
		if ( ! $n ) {
			continue;
		}
		$shop = 0;
		$info = 0;
		foreach ( $cnt as $t => $v ) {
			$shop += in_array( $t, $shop_t, true ) ? $v : 0;
			$info += in_array( $t, $info_t, true ) ? $v : 0;
		}
		$labels = array( 'product' => 'محصول', 'category' => 'دسته', 'marketplace' => 'بازارگاه', 'article' => 'مقاله', 'video' => 'ویدیو', 'forum' => 'انجمن', 'wiki' => 'ویکی', 'home' => 'صفحه اصلی سایت', 'other' => 'سایر' );
		arsort( $cnt );
		$c['top3'] = implode( '، ', array_map( function ( $t, $v ) use ( $labels ) {
			return ( $labels[ $t ] ?? $t ) . ' ' . qtb_num( $v );
		}, array_keys( $cnt ), $cnt ) ) . ' از ' . qtb_num( $n );
		if ( ! empty( $c['locked_page'] ) || 0 === strpos( (string) ( $c['page_reason'] ?? '' ), 'قانون پروژه' ) ) {
			continue;
		}
		if ( $shop * 3 >= $n * 2 ) {
			$prod = (int) ( $cnt['product'] ?? 0 );
			$cat  = (int) ( $cnt['category'] ?? 0 ) + (int) ( $cnt['marketplace'] ?? 0 );
			if ( $prod > $cat && 1 === (int) ( $c['store_match'] ?? 0 ) ) {
				$c['page']    = 'product';
				$c['archive'] = '';
			} elseif ( 'archive' !== $c['page'] || in_array( $c['archive'] ?? '', array( '', null ), true ) ) {
				$c['page']    = 'archive';
				$c['archive'] = ( $c['archive'] ?? '' ) ?: 'category';
			}
			$c['page_reason'] = 'گوگل در ۳ نتیجه اول صفحات فروشگاهی نشان داده (' . $c['top3'] . ')؛ صفحه تجاری.';
			$c['top3_lock']   = 1;
		} elseif ( $info * 3 >= $n * 2 ) {
			$c['page']        = 'article';
			$c['archive']     = '';
			$c['page_reason'] = 'گوگل در ۳ نتیجه اول مقاله نشان داده (' . $c['top3'] . ')؛ صفحه مقاله.';
			$c['top3_lock']   = 1;
		} else {
			unset( $c['top3_lock'] );
		}
	}
	unset( $c );
	return $clusters;
}

// ---------------------------------------------------------------------------
// Sections.
// ---------------------------------------------------------------------------

/**
 * The fixed section skeleton of a project.
 *
 * @return array role → name
 */
function qtb_fx_skeleton( $project ) {
	$kind = (string) ( $project['cfg']['biz_kind'] ?? 'shop' );
	return array(
		'main'     => 'صفحه اصلی',
		'features' => 'ویژگی‌ها',
		'products' => 'service' === $kind ? 'خدمات' : 'محصولات',
		'blog'     => 'بلاگ',
		'landing'  => 'لندینگ',
	);
}

/**
 * Section names the board offers: the skeleton and the facet types' sections.
 *
 * @return string[]
 */
function qtb_fx_section_names( $project ) {
	$out = array_values( qtb_fx_skeleton( $project ) );
	$fx  = qtb_fx_get( $project['id'] );
	foreach ( (array) $fx['types'] as $ty ) {
		$p = trim( (string) ( $ty['plural'] ?? '' ) );
		if ( '' !== $p && ! in_array( qtb_key( $p ), array_map( 'qtb_key', $out ), true ) ) {
			$out[] = $p;
		}
	}
	return $out;
}

/**
 * «Park» and «delete» are keyword states, not sections.
 */
function qtb_section_is_state( $name ) {
	return (bool) preg_match( '/^\s*(پارک|پارکینگ|حذف|حذف\s*شده|کنار\s*گذاشته|park|remove|delete)/iu', (string) $name );
}

/**
 * One spelling per section: a name that matches a known one (ی/ي، نیم‌فاصله، فاصله)
 * becomes that one.
 *
 * @param string[] $known
 */
function qtb_section_norm( $name, $known ) {
	$name = qtb_display( (string) $name );
	$k    = qtb_key( $name );
	foreach ( $known as $o ) {
		if ( qtb_key( $o ) === $k ) {
			return $o;
		}
	}
	return $name;
}

/**
 * Sections of a topic's clusters: page type first (article → blog, product → products,
 * landing → landing), the root is the main page, and every other page goes to the
 * section of its main facet type — that type's own section when it is promoted (its
 * share of the topic's demand ≥ promote_pct), otherwise «ویژگی‌ها».
 *
 * @return array clusters
 */
function qtb_fx_assign_sections( $project, $sub, $clusters ) {
	$fx    = qtb_fx_get( $project['id'] );
	$types = qtb_fx_types( $fx );
	$sk    = qtb_fx_skeleton( $project );
	$pp    = max( 1, min( 100, (float) ( $project['cfg']['promote_pct'] ?? 15 ) ) ) / 100;
	// Demand of each facet type in the topic.
	$tot   = 0;
	$share = array();
	foreach ( $clusters as $c ) {
		$tot += (int) $c['vol'];
		foreach ( array_unique( array_values( (array) ( $c['fx']['facets'] ?? array() ) ) ) as $ty ) {
			$share[ $ty ] = ( $share[ $ty ] ?? 0 ) + (int) $c['vol'];
		}
	}
	$promoted = array();
	foreach ( $share as $ty => $v ) {
		if ( ! in_array( $ty, array( 'other', 'info', 'mod' ), true ) && $tot > 0 && $v / $tot >= $pp && '' !== ( $types[ $ty ]['plural'] ?? '' ) ) {
			$promoted[ $ty ] = $v;
		}
	}
	foreach ( $clusters as &$c ) {
		$facets = (array) ( $c['fx']['facets'] ?? array() );
		// Main facet type: a promoted one first, then the type with the most demand.
		$main = '';
		$best = -1;
		foreach ( array_unique( array_values( $facets ) ) as $ty ) {
			$score = ( isset( $promoted[ $ty ] ) ? 1e12 : 0 ) + ( $share[ $ty ] ?? 0 );
			if ( 'info' !== $ty && $score > $best ) {
				$best = $score;
				$main = $ty;
			}
		}
		$c['ftype'] = $main;
		if ( ! empty( $c['locked_section'] ) ) {
			continue;
		}
		if ( 'article' === $c['page'] ) {
			$c['section'] = $sk['blog'];
		} elseif ( 'product' === $c['page'] ) {
			$c['section'] = $sk['products'];
		} elseif ( 'landing' === $c['page'] && 'c:' !== ( $c['fx']['key'] ?? '' ) ) {
			$c['section'] = $sk['landing'];
		} elseif ( 'c:' === ( $c['fx']['key'] ?? '' ) || ! $facets ) {
			$c['section'] = $sk['main'];
		} elseif ( isset( $promoted[ $main ] ) ) {
			$c['section'] = $types[ $main ]['plural'];
		} else {
			$c['section'] = $sk['features'];
		}
	}
	unset( $c );
	return $clusters;
}

/**
 * Why a node is a page of its own (board label).
 */
function qtb_fx_why_labels() {
	return array(
		'root'      => 'صفحه اصلی موضوع',
		'top'       => 'ویژگی سطح اول',
		'article'   => 'مقاله مستقل',
		'locked'    => 'قفل شما',
		'threshold' => 'بالای آستانه حجم (بدون شاهد گوگل)',
		'serp_diff' => 'گوگل شبیه هیچ صفحه‌ای ندانست',
		'comp_diff' => 'رقیب صفحه جدا دارد',
	);
}

// ---------------------------------------------------------------------------
// Reviewer edits.
// ---------------------------------------------------------------------------

/**
 * Edit the dictionary: a word's type / canonical form, many words at once, or the types.
 *
 * @return array|WP_Error
 */
function qtb_fx_edit( $project, $e ) {
	$pid   = $project['id'];
	$fx    = qtb_fx_get( $pid );
	$types = qtb_fx_types( $fx );
	$op    = (string) ( $e['op'] ?? '' );
	$n     = 0;
	switch ( $op ) {
		case 'fx_token': // {tok, t?, c?}.
			$tok = qtb_norm_key( (string) ( $e['tok'] ?? '' ) );
			if ( ! isset( $fx['tokens'][ $tok ] ) ) {
				return new WP_Error( 'qtb_fx', 'این واژه در فرهنگ ویژگی‌ها نیست.' );
			}
			if ( isset( $e['t'] ) ) {
				$t = sanitize_key( (string) $e['t'] );
				if ( ! isset( $types[ $t ] ) ) {
					return new WP_Error( 'qtb_fx', 'نوع ویژگی نامعتبر.' );
				}
				$fx['tokens'][ $tok ]['t'] = $t;
			}
			if ( isset( $e['c'] ) ) {
				$c = qtb_norm_key( (string) $e['c'] );
				$c = '' === $c ? $tok : $c;
				if ( $c !== $tok && ! isset( $fx['tokens'][ $c ] ) ) {
					return new WP_Error( 'qtb_fx', '«' . $c . '» در فهرست واژه‌ها نیست؛ واژه اصلی باید یکی از واژه‌های همین پروژه باشد.' );
				}
				if ( $c !== $tok && qtb_fx_resolve( $fx, $c )[0] === $tok ) {
					return new WP_Error( 'qtb_fx', 'این دو واژه همدیگر را اصلی می‌دانند؛ یکی را انتخاب کنید.' );
				}
				$fx['tokens'][ $tok ]['c'] = $c;
				// The variant takes the type of its main word.
				if ( $c !== $tok ) {
					$fx['tokens'][ $tok ]['t'] = qtb_fx_resolve( $fx, $c )[1];
				}
			}
			$fx['tokens'][ $tok ]['by'] = 'user';
			$n = 1;
			break;
		case 'fx_bulk': // {toks: [], t}.
			$t = sanitize_key( (string) ( $e['t'] ?? '' ) );
			if ( ! isset( $types[ $t ] ) ) {
				return new WP_Error( 'qtb_fx', 'نوع ویژگی نامعتبر.' );
			}
			foreach ( (array) ( $e['toks'] ?? array() ) as $tok ) {
				$tok = qtb_norm_key( (string) $tok );
				if ( isset( $fx['tokens'][ $tok ] ) ) {
					$fx['tokens'][ $tok ]['t']  = $t;
					$fx['tokens'][ $tok ]['by'] = 'user';
					++$n;
				}
			}
			break;
		case 'fx_type': // {id?, label, plural}: a new type, or rename one.
			$label  = qtb_mb_cut( qtb_display( sanitize_text_field( (string) ( $e['label'] ?? '' ) ) ), 30 );
			$plural = qtb_mb_cut( qtb_display( sanitize_text_field( (string) ( $e['plural'] ?? '' ) ) ), 30 );
			if ( '' === $label ) {
				return new WP_Error( 'qtb_fx', 'نام نوع ویژگی خالی است.' );
			}
			if ( qtb_section_is_state( $plural ) ) {
				return new WP_Error( 'qtb_fx', 'پارکینگ و حذف بخش نیستند.' );
			}
			$id = sanitize_key( (string) ( $e['id'] ?? '' ) );
			if ( isset( qtb_fx_reserved()[ $id ] ) ) {
				return new WP_Error( 'qtb_fx', 'نقش‌های ثابت تغییر نام نمی‌دهند.' );
			}
			if ( '' === $id || ! isset( $fx['types'][ $id ] ) ) {
				$id = 't' . ( count( (array) $fx['types'] ) + 1 );
				while ( isset( $fx['types'][ $id ] ) ) {
					$id .= 'x';
				}
			}
			$fx['types'][ $id ] = array( 'label' => $label, 'plural' => '' !== $plural ? $plural : $label );
			$n = 1;
			break;
		case 'fx_type_del': // {id, to}.
			$id = sanitize_key( (string) ( $e['id'] ?? '' ) );
			$to = sanitize_key( (string) ( $e['to'] ?? 'other' ) );
			if ( ! isset( $fx['types'][ $id ] ) ) {
				return new WP_Error( 'qtb_fx', 'نوع ویژگی پیدا نشد.' );
			}
			unset( $fx['types'][ $id ] );
			$to = isset( qtb_fx_types( $fx )[ $to ] ) ? $to : 'other';
			foreach ( $fx['tokens'] as &$row ) {
				if ( $row['t'] === $id ) {
					$row['t'] = $to;
					++$n;
				}
			}
			unset( $row );
			break;
	}
	$fx['edited'] = qtb_now();
	qtb_fx_put( $pid, $fx );
	return array( 'ok' => true, 'n' => $n );
}

/**
 * Was the dictionary edited after this topic was processed?
 */
function qtb_fx_stale( $project, $t ) {
	$fx = qtb_fx_get( $project['id'] );
	return '' !== $fx['edited'] && ! empty( $t['metrics']['at'] ) && strtotime( $fx['edited'] ) > strtotime( $t['metrics']['at'] );
}

/**
 * Facets of a node key («c:a b» → [a, b]).
 *
 * @return string[]
 */
function qtb_fx_key_facets( $key ) {
	$f = trim( (string) substr( (string) $key, 2 ) );
	return '' === $f ? array() : explode( ' ', $f );
}

/**
 * Sub-groups merged into a cluster: node key → {head, kws, vol, review, ev}, biggest first.
 */
function qtb_fx_subgroups( $c, $kws ) {
	$out = array();
	foreach ( $c['kws'] as $i ) {
		$m = $c['meta'][ $i ] ?? array();
		if ( empty( $m['node'] ) || ( $c['fx']['key'] ?? '' ) === $m['node'] ) {
			continue;
		}
		$k = $m['node'];
		if ( ! isset( $out[ $k ] ) ) {
			$out[ $k ] = array( 'head' => (int) ( $m['from'] ?? $i ), 'kws' => array(), 'vol' => 0, 'review' => 0, 'why' => (string) ( $m['why'] ?? '' ), 'shared' => (int) ( $m['shared'] ?? -1 ) );
		}
		$out[ $k ]['kws'][] = (int) $i;
		$out[ $k ]['vol']  += (int) ( $kws[ $i ]['v'] ?? 0 );
		$out[ $k ]['review'] = max( $out[ $k ]['review'], (int) ! empty( $m['review'] ) );
	}
	uasort( $out, function ( $a, $b ) {
		return $b['vol'] <=> $a['vol'];
	} );
	return $out;
}
