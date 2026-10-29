<?php
/**
 * From keyword groups to the keyword research structure:
 *   cluster  = one page (main landing, category/product landing, or blog post)
 *   section  = a column group of a pillar sheet (لندینگ‌های اصلی، انواع، نگهداری…)
 *   pillar   = one sheet of the research (a product line / topic, e.g. «پتوس»)
 *
 * Intent and page type come from the SERP when the cluster was looked up,
 * otherwise from the lexicon and the AI intent of the keywords. Pillars and
 * sections come from the AI pass (mzm_ai_structure_batch) or, without AI, from
 * the data: the topic word of a cluster is the standalone word with the most
 * searches that is not an attribute shared by many topics.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page types (colors match the Delna research sheets).
 */
function mzm_page_types() {
	return array(
		'main'     => array( 'لندینگ اصلی', 'D9EAD3' ),
		'category' => array( 'لندینگ دسته', 'C9DAF8' ),
		'product'  => array( 'صفحه محصول', 'C9DAF8' ),
		'blog'     => array( 'مقاله بلاگ', 'FFF2CC' ),
	);
}

/**
 * Intent labels.
 */
function mzm_intents() {
	return array(
		't' => 'خرید (تراکنشی)',
		'm' => 'ترکیبی (خرید و بررسی)',
		'c' => 'بررسی و مقایسه',
		'i' => 'آموزشی و اطلاعاتی',
		'l' => 'محلی',
		'n' => 'برند خودمان',
	);
}

/**
 * Customer journey stage of an intent.
 */
function mzm_journey( $intent ) {
	$j = array( 't' => 'خرید', 'm' => 'بررسی', 'c' => 'بررسی', 'i' => 'آگاهی', 'l' => 'خرید', 'n' => 'وفاداری' );
	return $j[ $intent ] ?? 'آگاهی';
}

/**
 * Lexical / AI intent of a group (volume-weighted vote of its keywords).
 */
function mzm_group_intent( $keywords, $members ) {
	$s = array( 't' => 0, 'c' => 0, 'i' => 0, 'l' => 0, 'n' => 0 );
	foreach ( $members as $m ) {
		$kw = $keywords[ $m ];
		$in = (string) ( $kw['ai_intent'] ?? '' );
		if ( '' === $in ) {
			$in = '' === $kw['intent0'] ? 't' : $kw['intent0']; // A bare product name is a buying query in a store.
		}
		if ( ! empty( $kw['brand'] ) ) {
			$in = 'n';
		}
		$s[ $in ] = ( $s[ $in ] ?? 0 ) + max( 1, $kw['v'] );
	}
	$total = array_sum( $s ) ?: 1;
	if ( $s['n'] / $total >= 0.5 ) {
		return 'n';
	}
	if ( $s['i'] / $total >= 0.55 ) {
		return 'i';
	}
	if ( ( $s['t'] + $s['l'] ) / $total >= 0.55 ) {
		return $s['l'] > $s['t'] ? 'l' : 't';
	}
	if ( $s['c'] / $total >= 0.5 ) {
		return 'c';
	}
	return 'm';
}

/**
 * Build clusters from final groups.
 *
 * @param array $serps keyword index → SERP.
 */
function mzm_clusters_build( $keywords, $groups, $serps, $own_domain ) {
	$clusters = array();
	$groups   = mzm_sort( $groups, 'vol' );
	foreach ( $groups as $g ) {
		$head = $keywords[ $g['head'] ];
		$c    = array(
			'id'      => count( $clusters ),
			'name'    => $head['k'],
			'head'    => $g['head'],
			'kws'     => $g['kws'],
			'vol'     => $g['vol'],
			'core'    => $g['core'],
			'checked' => (int) ( $g['checked'] ?? 0 ),
			'serp'    => null,
		);
		foreach ( $g['kws'] as $m ) {
			if ( isset( $serps[ $m ] ) && ! empty( $serps[ $m ]['organic'] ) ) {
				$c['serp']     = mzm_serp_metrics( $serps[ $m ], $keywords[ $m ]['k'], $own_domain );
				$c['serp_kw']  = $m;
				break;
			}
		}
		$lex = mzm_group_intent( $keywords, $g['kws'] );
		if ( $c['serp'] ) {
			$c['intent'] = 'n' === $lex ? 'n' : $c['serp']['intent'];
			$c['page']   = $c['serp']['page'];
			// SERP says «mixed» but the queries are clearly informational: a blog post wins.
			if ( 'm' === $c['intent'] && 'i' === $lex ) {
				$c['page'] = 'blog';
			}
		} else {
			$c['intent'] = $lex;
			$c['page']   = mzm_page_from_intent( $lex, $keywords, $g );
		}
		$clusters[] = $c;
	}
	return $clusters;
}

/**
 * Page type from a (lexical) intent.
 */
function mzm_page_from_intent( $intent, $keywords, $g ) {
	switch ( $intent ) {
		case 'i':
			return 'blog';
		case 'c':
			$list = false;
			foreach ( $g['kws'] as $m ) {
				if ( preg_match( '/(^| )(انواع|مدل|مدلهای|لیست|نوع)( |$)/u', $keywords[ $m ]['nk'] ) ) {
					$list = true;
					break;
				}
			}
			return $list ? 'category' : 'blog';
		case 'n':
			return 'main';
		default:
			// Product vs category needs the SERP; a landing is the safe default.
			return 'category';
	}
}

/**
 * Attach tiny single-keyword clusters to the most similar bigger cluster of the
 * same page kind, so the research is not cluttered with one-word columns.
 */
function mzm_attach_orphans( $keywords, $clusters ) {
	$big = array();
	foreach ( $clusters as $ci => $c ) {
		if ( count( $c['kws'] ) >= 2 || $c['vol'] >= 50 ) {
			foreach ( $c['core'] as $t ) {
				$big[ $t ][] = $ci;
			}
		}
	}
	$blog = function ( $c ) {
		return 'blog' === $c['page'];
	};
	$drop = array();
	foreach ( $clusters as $ci => $c ) {
		if ( count( $c['kws'] ) > 1 || $c['vol'] >= 50 || $c['checked'] ) {
			continue;
		}
		$best = null;
		foreach ( $c['core'] as $t ) {
			foreach ( $big[ $t ] ?? array() as $bi ) {
				if ( $bi === $ci || isset( $drop[ $bi ] ) || $blog( $clusters[ $bi ] ) !== $blog( $c ) ) {
					continue;
				}
				$sim = mzm_jaccard( $c['core'], $clusters[ $bi ]['core'] );
				// Must contain the whole parent topic (no jumping to a sibling product).
				if ( array_diff( $clusters[ $bi ]['core'], $c['core'] ) ) {
					continue;
				}
				if ( $sim >= 0.34 && ( null === $best || $sim > $best[1] ) ) {
					$best = array( $bi, $sim );
				}
			}
		}
		if ( $best ) {
			$clusters[ $best[0] ]['kws'][] = $c['kws'][0];
			$clusters[ $best[0] ]['vol']  += $c['vol'];
			$drop[ $ci ]                   = true;
		}
	}
	$out = array();
	foreach ( $clusters as $ci => $c ) {
		if ( ! isset( $drop[ $ci ] ) ) {
			$out[] = $c;
		}
	}
	return mzm_clusters_reindex( $keywords, $out );
}

/**
 * Re-sort members by volume, recompute totals and give clusters sequential ids.
 */
function mzm_clusters_reindex( $keywords, $clusters ) {
	foreach ( $clusters as &$c ) {
		$kws = array_values( array_unique( $c['kws'] ) );
		usort( $kws, function ( $a, $b ) use ( $keywords ) {
			return $keywords[ $b ]['v'] <=> $keywords[ $a ]['v'];
		} );
		$c['kws']  = $kws;
		$c['vol']  = array_sum( array_map( function ( $m ) use ( $keywords ) {
			return $keywords[ $m ]['v'];
		}, $kws ) );
		if ( empty( $c['locked_name'] ) && $kws ) {
			$c['head'] = $kws[0];
			$c['name'] = $keywords[ $kws[0] ]['k'];
		}
	}
	unset( $c );
	$clusters = mzm_sort( $clusters, 'vol' );
	foreach ( $clusters as $i => &$c ) {
		$c['id'] = $i;
	}
	unset( $c );
	return $clusters;
}

/**
 * Data-driven pillars (no AI): topic word of each cluster.
 *
 * @return array clusters with 'pillar_key'
 */
function mzm_pillars_free( $keywords, $clusters ) {
	$info  = mzm_lex_set( 'informational' );
	$cdf   = array();
	$alone = array(); // token → volume of the cluster whose core is just that token.
	foreach ( $clusters as $c ) {
		foreach ( array_unique( $c['core'] ) as $t ) {
			$cdf[ $t ] = ( $cdf[ $t ] ?? 0 ) + 1;
		}
		if ( 1 === count( $c['core'] ) ) {
			$alone[ $c['core'][0] ] = max( $alone[ $c['core'][0] ] ?? 0, $c['vol'] );
		}
	}
	// Attribute-like words appear next to many other topics («آپارتمانی» with پتوس، سانسوریا…).
	$partners = array();
	foreach ( $clusters as $c ) {
		foreach ( $c['core'] as $t ) {
			foreach ( $c['core'] as $u ) {
				if ( $u !== $t && isset( $alone[ $u ] ) ) {
					$partners[ $t ][ $u ] = true;
				}
			}
		}
	}
	// Adjectives («آپارتمانی» → آپارتمان) describe a product; they are rarely the product line.
	$adj = array();
	$occ = array();
	foreach ( $keywords as $kw ) {
		$raw = explode( ' ', $kw['nk'] );
		foreach ( $kw['toks'] as $j => $t ) {
			$occ[ $t ] = ( $occ[ $t ] ?? 0 ) + 1;
			if ( isset( $raw[ $j ] ) && $raw[ $j ] !== $t && 'ی' === mb_substr( $raw[ $j ], -1 ) ) {
				$adj[ $t ] = ( $adj[ $t ] ?? 0 ) + 1;
			}
		}
	}
	foreach ( $clusters as &$c ) {
		$best = null;
		foreach ( $c['core'] as $t ) {
			if ( isset( $info[ $t ] ) ) {
				continue;
			}
			$score = ( $alone[ $t ] ?? 0 ) / ( 1 + count( $partners[ $t ] ?? array() ) );
			if ( ( $adj[ $t ] ?? 0 ) > 0.5 * ( $occ[ $t ] ?? 1 ) ) {
				$score *= 0.3;
			}
			$score = $score > 0 ? $score : ( $cdf[ $t ] ?? 0 ) / 100;
			if ( null === $best || $score > $best[1] ) {
				$best = array( $t, $score );
			}
		}
		$key = $best ? $best[0] : ( $c['core'][0] ?? '' );
		if ( ( $cdf[ $key ] ?? 0 ) < 3 && $c['vol'] < 300 && ! isset( $alone[ $key ] ) ) {
			$key = '*';
		}
		$c['pillar_key'] = $key;
	}
	unset( $c );
	return $clusters;
}

/**
 * Section label of a cluster inside its pillar (no AI).
 */
function mzm_section_free( $keywords, $c, $pillar_key ) {
	if ( 'main' === $c['page'] || ( in_array( $c['page'], array( 'category', 'product' ), true ) && $c['core'] === array( $pillar_key ) ) ) {
		return 'لندینگ‌های اصلی';
	}
	if ( 'blog' !== $c['page'] ) {
		return 'انواع و مدل‌ها';
	}
	$text = '';
	foreach ( array_slice( $c['kws'], 0, 5 ) as $m ) {
		$text .= ' ' . $keywords[ $m ]['nk'];
	}
	$map = array(
		'نگهداری و مراقبت' => '/(نگهداری|مراقبت|ابیاری|ابدهی|نور|دما|رطوبت|خاک|کود|هرس|تعویض گلدان)/u',
		'تکثیر و پرورش'   => '/(تکثیر|قلمه|کاشت|بذر|پیوند|پرورش|ریشه دار)/u',
		'مشکلات و درمان'   => '/(علت|دلیل|چرا|درمان|رفع|افت|ریزش|زرد|خشک|سیاه|پوسیدگی|افات|بیماری|مشکل|سوختگی|لکه|قارچ)/u',
		'مقایسه و انتخاب'   => '/(بهترین|مقایسه|تفاوت|فرق|کدام|راهنمای خرید| یا )/u',
		'معرفی و دانستنی'   => '/(چیست|معنی|خواص|فواید|نماد|اسامی|اسم|انواع|عکس|ویژگی)/u',
	);
	foreach ( $map as $label => $re ) {
		if ( preg_match( $re, $text ) ) {
			return $label;
		}
	}
	return 'بلاگ';
}

/**
 * Pillars → sections → clusters, and the pillar / section of each cluster.
 *
 * @param array $ai Optional AI structure: cluster id → {pillar, section, page}.
 * @return array {clusters, pillars}
 */
function mzm_structure_build( $keywords, $clusters, $ai = array() ) {
	$clusters = mzm_pillars_free( $keywords, $clusters );
	// Display name of a free pillar: the head of its biggest cluster that is only that word.
	// Fallback: the head of the shortest (then biggest) cluster that contains the word
	// («گل بابا آدم» rather than «آدم»).
	$names = array();
	$best  = array();
	foreach ( $clusters as $c ) {
		$k = $c['pillar_key'];
		if ( ! in_array( $k, $c['core'], true ) ) {
			continue;
		}
		$rank = array( count( $c['core'] ), -$c['vol'] );
		if ( ! isset( $best[ $k ] ) || $rank < $best[ $k ] ) {
			$best[ $k ]  = $rank;
			$names[ $k ] = $c['name'];
		}
	}
	foreach ( $clusters as &$c ) {
		$k = $c['pillar_key'];
		$a = $c['ai'] ?? ( $ai[ $c['id'] ] ?? null );
		if ( $a && '' !== (string) $a['pillar'] ) {
			$c['pillar_name'] = mzm_display( $a['pillar'] );
		} else {
			$c['pillar_name'] = '*' === $k ? 'سایر کلمات' : ( $names[ $k ] ?? mzm_token_display( $keywords, $c, $k ) );
		}
		if ( $a && isset( mzm_page_types()[ $a['page'] ?? '' ] ) && empty( $c['locked_page'] ) ) {
			// The SERP outranks the model's guess between landing and blog.
			if ( ! $c['serp'] || ( 'blog' === $a['page'] ) === ( 'blog' === $c['page'] ) ) {
				$c['page'] = $a['page'];
			}
		}
		if ( empty( $c['locked_section'] ) ) {
			$c['section'] = $a && '' !== (string) ( $a['section'] ?? '' ) ? mzm_display( $a['section'] ) : mzm_section_free( $keywords, $c, $k );
		}
		// The pillar's own landing is the main landing (green in the research).
		if ( empty( $c['locked_page'] ) && 'blog' !== $c['page'] && 'لندینگ‌های اصلی' === $c['section'] ) {
			$c['page'] = 'main';
		}
	}
	unset( $c );
	return mzm_pillars_assemble( $clusters );
}

/**
 * Group clusters into pillars and sections (order: landings first, then blog).
 */
function mzm_pillars_assemble( $clusters ) {
	$pillars = array();
	foreach ( $clusters as $c ) {
		$pn = $c['pillar_name'];
		if ( ! isset( $pillars[ $pn ] ) ) {
			$pillars[ $pn ] = array( 'name' => $pn, 'vol' => 0, 'clusters' => array(), 'sections' => array() );
		}
		$pillars[ $pn ]['vol']       += $c['vol'];
		$pillars[ $pn ]['clusters'][] = $c['id'];
		$sec = $c['section'];
		if ( ! isset( $pillars[ $pn ]['sections'][ $sec ] ) ) {
			$pillars[ $pn ]['sections'][ $sec ] = array( 'name' => $sec, 'kind' => 'blog' === $c['page'] ? 'blog' : ( 'main' === $c['page'] || 'لندینگ‌های اصلی' === $sec ? 'main' : 'landing' ), 'clusters' => array(), 'vol' => 0 );
		}
		$pillars[ $pn ]['sections'][ $sec ]['clusters'][] = $c['id'];
		$pillars[ $pn ]['sections'][ $sec ]['vol']       += $c['vol'];
	}
	$rank = array( 'main' => 0, 'landing' => 1, 'blog' => 2 );
	foreach ( $pillars as &$p ) {
		$secs = array_values( $p['sections'] );
		usort( $secs, function ( $a, $b ) use ( $rank ) {
			return ( $rank[ $a['kind'] ] <=> $rank[ $b['kind'] ] ) ?: ( $b['vol'] <=> $a['vol'] );
		} );
		$p['sections'] = $secs;
	}
	unset( $p );
	$pillars = array_values( $pillars );
	usort( $pillars, function ( $a, $b ) {
		// «سایر کلمات» always last.
		return ( ( 'سایر کلمات' === $a['name'] ) <=> ( 'سایر کلمات' === $b['name'] ) ) ?: ( $b['vol'] <=> $a['vol'] );
	} );
	foreach ( $pillars as $pi => &$p ) {
		$p['id'] = $pi;
	}
	unset( $p );
	$pid = array();
	foreach ( $pillars as $p ) {
		foreach ( $p['clusters'] as $cid ) {
			$pid[ $cid ] = $p['id'];
		}
	}
	foreach ( $clusters as &$c ) {
		$c['pillar'] = $pid[ $c['id'] ] ?? 0;
	}
	unset( $c );
	return array( 'clusters' => $clusters, 'pillars' => $pillars );
}

/**
 * Display form of a topic token (as written in the keywords).
 */
function mzm_token_display( $keywords, $c, $token ) {
	foreach ( $c['kws'] as $m ) {
		foreach ( preg_split( '/[\s\x{200C}]+/u', $keywords[ $m ]['k'] ) as $w ) {
			if ( mzm_norm_key( $w ) === $token || 0 === mb_strpos( mzm_norm_key( $w ), $token ) ) {
				return $w;
			}
		}
	}
	return $token;
}

/**
 * AI structure pass for one batch of clusters.
 *
 * @param array $batch    [{id, name, vol, page, intent, kws: string[]}]
 * @param array $known    Pillar names already used (keep them consistent).
 * @return array|WP_Error {map: id → {pillar, section, page}, merge: [[id, id…]]}
 */
function mzm_ai_structure_batch( $project, $batch, $known ) {
	$types = array( 'main' => 'لندینگ اصلی یک دسته/محصول (صفحه دسته اصلی)', 'category' => 'لندینگ زیردسته/نوع', 'product' => 'صفحه محصول', 'blog' => 'مقاله بلاگ' );
	$lines = array();
	foreach ( $batch as $c ) {
		$lines[] = sprintf( '%d | %s | %d | %s | %s', $c['id'], $c['name'], $c['vol'], $c['page'], implode( '، ', array_slice( $c['kws'], 1, 4 ) ) );
	}
	$system = 'تو مدیر ارشد سئو و معمار اطلاعات (Information Architecture) یک سایت فروشگاهی/خدماتی فارسی هستی. خوشه‌های کلمات کلیدی را در ساختار کیورد ریسرچ قرار می‌دهی: هر پیلار یک شیت (یک خط محصول یا موضوع اصلی مثل «پتوس» یا «گیاه آپارتمانی»)، هر بخش یک گروه ستون (مثل «لندینگ‌های اصلی»، «انواع پتوس»، «ویژگی و ظاهر»، «نگهداری»، «مشکلات و درمان»، «بلاگ»). هدف ساختار: فروش و بازگشت سرمایه؛ لندینگ‌های پولساز جدا و واضح، و مقاله‌ها پشتیبان همان لندینگ‌ها.';
	$user   = "کسب‌وکار: {$project['name']} — " . ( $project['cfg']['niche'] ?: 'از روی کلمات حدس بزن' ) .
		"\nپیلارهای موجود (اگر مناسب است همین نام‌ها را دوباره استفاده کن): " . ( $known ? implode( '، ', array_slice( $known, 0, 80 ) ) : '—' ) .
		"\n\nنوع صفحه‌ها: " . wp_json_encode( $types, JSON_UNESCAPED_UNICODE ) .
		"\n\nبرای هر خوشه (شناسه | کلمه اصلی | حجم | نوع صفحه فعلی | چند کلمه دیگر) بده: pillar (نام کوتاه پیلار، ۱ تا ۳ کلمه)، section (نام بخش در آن پیلار)، page (نوع صفحه درست).\n" .
		"قوانین: اسم برند/محصول اصلی پیلار است نه صفت (پتوس آپارتمانی ← پیلار پتوس). خوشه‌ای که فقط نام همان پیلار است (مثل «پتوس»، «گل پتوس») page=main و section=«لندینگ‌های اصلی». انواع و مدل‌ها لندینگ category هستند. آموزش، نگهداری، علت و مشکلات blog هستند. نام بخش‌ها را در هر پیلار یکسان و کوتاه بنویس.\n" .
		"اگر چند خوشه دقیقاً یک نیت جستجو و یک صفحه هستند (مترادف، املای دیگر، ترتیب دیگر کلمات) شناسه‌هایشان را در merge بیاور؛ در شک ادغام نکن.\n\nخوشه‌ها:\n" . implode( "\n", $lines );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items', 'merge' ),
		'properties'           => array(
			'items' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'pillar', 'section', 'page' ),
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'pillar'  => array( 'type' => 'string' ),
						'section' => array( 'type' => 'string' ),
						'page'    => array( 'type' => 'string', 'enum' => array_keys( $types ) ),
					),
				),
			),
			'merge' => array( 'type' => 'array', 'items' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) ),
		),
	);
	$res = mzm_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 9000, 'timeout' => 240 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_fill_keys( array_column( $batch, 'id' ), true );
	$map   = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$id = (int) ( $it['id'] ?? -1 );
		if ( isset( $valid[ $id ] ) ) {
			$map[ $id ] = array(
				'pillar'  => mb_substr( trim( (string) $it['pillar'] ), 0, 40 ),
				'section' => mb_substr( trim( (string) $it['section'] ), 0, 40 ),
				'page'    => (string) $it['page'],
			);
		}
	}
	$merge = array();
	foreach ( (array) ( $res['merge'] ?? array() ) as $set ) {
		$set = array_values( array_unique( array_filter( array_map( 'intval', (array) $set ), function ( $id ) use ( $valid ) {
			return isset( $valid[ $id ] );
		} ) ) );
		if ( count( $set ) >= 2 && count( $set ) <= 6 ) {
			$merge[] = $set;
		}
	}
	return array( 'map' => $map, 'merge' => $merge );
}

/**
 * Apply AI merges (clusters that are the same search intent).
 */
function mzm_clusters_merge_sets( $keywords, $clusters, $sets ) {
	$by_id = array();
	foreach ( $clusters as $ci => $c ) {
		$by_id[ $c['id'] ] = $ci;
	}
	$drop = array();
	foreach ( $sets as $set ) {
		$set = array_values( array_filter( $set, function ( $id ) use ( $by_id, $drop ) {
			return isset( $by_id[ $id ] ) && ! isset( $drop[ $id ] );
		} ) );
		if ( count( $set ) < 2 ) {
			continue;
		}
		// Do not merge a landing with a blog post.
		$kinds = array_unique( array_map( function ( $id ) use ( $clusters, $by_id ) {
			return 'blog' === $clusters[ $by_id[ $id ] ]['page'] ? 'b' : 'l';
		}, $set ) );
		if ( count( $kinds ) > 1 ) {
			continue;
		}
		$keep = $by_id[ $set[0] ];
		foreach ( array_slice( $set, 1 ) as $id ) {
			$ci                       = $by_id[ $id ];
			$clusters[ $keep ]['kws'] = array_merge( $clusters[ $keep ]['kws'], $clusters[ $ci ]['kws'] );
			$clusters[ $keep ]['checked'] += $clusters[ $ci ]['checked'];
			if ( ! $clusters[ $keep ]['serp'] && $clusters[ $ci ]['serp'] ) {
				$clusters[ $keep ]['serp'] = $clusters[ $ci ]['serp'];
			}
			$drop[ $id ] = true;
		}
	}
	$out = array();
	foreach ( $clusters as $c ) {
		if ( ! isset( $drop[ $c['id'] ] ) ) {
			$out[] = $c;
		}
	}
	return $out;
}
