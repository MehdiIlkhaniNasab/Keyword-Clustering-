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
		"- canon: اگر این واژه املای دیگر یا مترادف واژه دیگری در همین فهرست است و برای این کسب‌وکار باید یک صفحه باشند، همان واژه اصلی را بنویس؛ وگرنه خود واژه. محتاط باش: فقط وقتی یکی کن که واقعاً یک صفحه باشند.\n" .
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
	$types = array();
	foreach ( (array) ( $res['types'] ?? array() ) as $ty ) {
		$id = sanitize_key( (string) $ty['id'] );
		if ( '' === $id || isset( qtb_fx_reserved()[ $id ] ) ) {
			continue;
		}
		$types[ $id ] = array(
			'label'  => qtb_mb_cut( qtb_display( (string) $ty['label'] ), 30 ),
			'plural' => qtb_mb_cut( qtb_display( (string) ( $ty['plural'] ?: $ty['label'] ) ), 30 ),
		);
	}
	$valid  = array_merge( array_keys( (array) $fx['types'] ), array_keys( $types ), array_keys( qtb_fx_reserved() ) );
	$tokens = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$t = qtb_norm_key( (string) $it['t'] );
		if ( ! isset( $batch[ $t ] ) ) {
			continue;
		}
		$type = sanitize_key( (string) $it['type'] );
		$c    = qtb_norm_key( (string) $it['canon'] );
		$tokens[ $t ] = array(
			't'  => in_array( $type, $valid, true ) ? $type : 'other',
			'c'  => '' !== $c && false === strpos( $c, ' ' ) ? $c : $t,
			'by' => 'ai',
		);
	}
	return array( 'types' => $types, 'tokens' => $tokens );
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
 * Minimum volume of a page of its own (below it a node merges unless Google says it is
 * another search): the project setting, or automatically a quarter of «split_pct» of the
 * project's biggest keyword — small projects get a small floor, big ones a big one.
 */
function qtb_fx_floor( $project ) {
	$set = (int) ( $project['cfg']['min_page'] ?? 0 );
	if ( $set > 0 ) {
		return $set;
	}
	$max = 0;
	foreach ( qtb_ws_active( qtb_data_get( $project['id'], 'keywords', array() ) ) as $kw ) {
		$max = max( $max, (int) $kw['v'] );
	}
	return (int) round( $max * max( 1, (float) ( $project['cfg']['split_pct'] ?? 10 ) ) / 100 / 4 );
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
 * Decide the tree, top-down: the main page first, then each node — fewer facets first —
 * against the page it would join (its parent's page: the parent itself when the parent
 * stayed a page, or the page the parent merged into). Default by the dynamic threshold;
 * Google (or, without a SERP, the competitors' ranking pages) decides both ways.
 *
 * @param array $opts {pct, need, serps, hubs, comp_idx, comps, plan_only}
 * @return array {groups: [...], want: int[] keyword indices whose SERP would settle a decision}
 */
function qtb_fx_decide( $fx, $sub, $tree, $opts ) {
	$nodes = $tree['nodes'];
	$pct   = max( 1, min( 60, (float) $opts['pct'] ) ) / 100;
	$floor = max( 0, (int) ( $opts['floor'] ?? 0 ) );
	$need  = max( 2, min( 7, (int) $opts['need'] ) );
	$serps = (array) ( $opts['serps'] ?? array() );
	$hubs  = (array) ( $opts['hubs'] ?? array() );
	$want  = array();
	// Topic root: always a node (the main page), even without a keyword of its own.
	if ( ! isset( $nodes['c:'] ) ) {
		$nodes['c:'] = array( 'key' => 'c:', 'facets' => array(), 'info' => false, 'own' => array(), 'kws' => array(), 'vol' => 0, 'head' => null, 'hv' => 0, 'lock' => null );
	}
	if ( null === $nodes['c:']['head'] ) {
		// Without a keyword equal to the base alone, the biggest keyword of the topic sets the scale.
		$nodes['c:']['hv'] = $sub ? max( array_map( function ( $kw ) {
			return (int) $kw['v'];
		}, $sub ) ) : 0;
	}
	// Blog root: the general article of the topic, when the topic has learning searches.
	$info_hv = 0;
	foreach ( $nodes as $n ) {
		if ( $n['info'] ) {
			$info_hv = max( $info_hv, (int) $n['hv'] );
		}
	}
	if ( $info_hv && ! isset( $nodes['i:'] ) ) {
		$nodes['i:'] = array( 'key' => 'i:', 'facets' => array(), 'info' => true, 'own' => array(), 'kws' => array(), 'vol' => 0, 'head' => null, 'hv' => $info_hv, 'lock' => null );
	} elseif ( isset( $nodes['i:'] ) && null === $nodes['i:']['head'] ) {
		$nodes['i:']['hv'] = $info_hv;
	}
	// Static tree: parent of each node and the descendants that would ride along with it.
	$parent = array();
	$kids   = array();
	foreach ( array_keys( $nodes ) as $key ) {
		$parent[ $key ] = 'c:' === $key || 'i:' === $key ? null : qtb_fx_parent( $nodes, $key );
		if ( null !== $parent[ $key ] ) {
			$kids[ $parent[ $key ] ][] = $key;
		}
	}
	// Estimated page volume of a node: its own keywords and the descendants that are too
	// small to stand alone under it (they would merge into it).
	$est = function ( $key ) use ( &$est, $nodes, $kids, $pct ) {
		$v = $nodes[ $key ]['vol'];
		foreach ( (array) ( $kids[ $key ] ?? array() ) as $k ) {
			$sv = $est( $k );
			if ( $sv < $pct * max( 1, $nodes[ $key ]['hv'] ) ) {
				$v += $sv;
			}
		}
		return $v;
	};
	$order = array_keys( $nodes );
	usort( $order, function ( $a, $b ) use ( $nodes ) {
		return count( $nodes[ $a ]['facets'] ) <=> count( $nodes[ $b ]['facets'] ) ?: $nodes[ $b ]['vol'] <=> $nodes[ $a ]['vol'];
	} );
	$page   = array(); // node key → key of the page it ends up in.
	$meta   = array(); // keyword index → meta.
	$why    = array(); // page key → why it is a page of its own.
	$evs    = array(); // page key → evidence of the split.
	$review = array(); // page key → node heads merged without evidence, close to the line.
	foreach ( $order as $key ) {
		$n = $nodes[ $key ];
		$pk = $parent[ $key ];
		if ( 'c:' === $key || null !== $n['lock'] || null === $pk ) {
			$page[ $key ] = $key;
			$why[ $key ]  = 'c:' === $key ? 'root' : ( null !== $n['lock'] ? 'locked' : ( $n['info'] ? 'article' : 'top' ) );
			continue;
		}
		$to    = $page[ $pk ] ?? $pk; // The page the parent is in.
		$p     = $nodes[ $to ];
		$vol   = $est( $key );
		// The line: «pct» of the page it would join, and never below the project's floor.
		$line  = max( $pct * max( 1, (int) $p['hv'] ), $n['info'] ? $floor / 3 : $floor );
		$ratio = $vol / max( 1, $line );
		$above = $ratio >= 1;
		$ev    = null;
		$hn    = $n['head'];
		$hp    = $p['head'];
		if ( null !== $hn ) {
			$sh = null !== $hp ? qtb_fx_shared( $serps, $hubs, $hn, $hp ) : -1;
			if ( $sh >= 0 ) {
				// Above the line only near-identical results merge (two spellings of one search).
				$same = $above ? $sh >= min( 9, $need + 2 ) : $sh >= $need;
				$ev   = array( 'by' => 'serp', 'v' => $same ? 'same' : 'diff', 'shared' => $sh, 'note' => '' );
			} else {
				foreach ( array_filter( array( $hn, $hp, $nodes[ $pk ]['head'] ), 'is_int' ) as $h ) {
					if ( ! isset( $serps[ $h ] ) ) {
						$want[ $h ] = max( $want[ $h ] ?? 0, $vol );
					}
				}
				$c = qtb_fx_comp( $opts['comp_idx'] ?? array(), $opts['comps'] ?? array(), $sub, $n['own'], null !== $hp ? array_merge( array( $hp ), $p['own'] ) : $p['own'] );
				if ( $c && ( 'diff' === $c['v'] || ! $above || $c['strong'] ) ) {
					$ev = array( 'by' => 'comp', 'v' => $c['v'], 'shared' => -1, 'note' => $c['note'] );
				}
			}
		}
		$merge = $ev ? 'same' === $ev['v'] : ! $above;
		if ( ! $merge ) {
			$page[ $key ] = $key;
			$why[ $key ]  = $ev ? ( 'serp' === $ev['by'] ? 'serp_diff' : 'comp_diff' ) : 'threshold';
			$evs[ $key ]  = $ev;
			continue;
		}
		$page[ $key ] = $to;
		$flag         = ! $ev && $ratio >= 0.3;
		foreach ( $n['own'] as $i ) {
			if ( $ev ) {
				$m = array( 'conf' => 'serp' === $ev['by'] ? ( $ev['shared'] >= $need + 2 ? 'high' : 'mid' ) : 'mid', 'shared' => (int) $ev['shared'], 'alt' => null, 'alt_shared' => 0, 'why' => 'serp' === $ev['by'] ? 'serp' : 'comp' );
				if ( 'comp' === $ev['by'] ) {
					$m['comp'] = $ev['note'];
				}
			} else {
				$m = array( 'conf' => $flag ? 'low' : 'mid', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'tail' );
			}
			$m['from'] = $hn;
			$m['node'] = $key;
			if ( $flag ) {
				$m['review'] = 1;
			}
			$meta[ $i ] = $m;
		}
		if ( $flag && null !== $hn ) {
			$review[ $to ][] = (int) $hn;
		}
	}
	if ( ! empty( $opts['plan_only'] ) ) {
		arsort( $want );
		return array( 'groups' => array(), 'want' => array_keys( $want ) );
	}
	$members = array();
	foreach ( $nodes as $key => $n ) {
		foreach ( $n['own'] as $i ) {
			$members[ $page[ $key ] ][] = (int) $i;
		}
	}
	$groups = array();
	foreach ( $nodes as $key => $n ) {
		if ( $page[ $key ] !== $key ) {
			continue;
		}
		$list = array_values( array_unique( (array) ( $members[ $key ] ?? array() ) ) );
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
		'threshold' => 'بالای آستانه حجم',
		'serp_diff' => 'گوگل نتایج متفاوت نشان داد',
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
