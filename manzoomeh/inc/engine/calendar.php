<?php
/**
 * Content calendar (blog) in the agency structure of the Delna samples:
 * monthly blocks M1, M2… with Writing Guide (Main Idea, KP, Title, Desc,
 * Source), five Out Links (L/K) to money pages, five In Links (L/K) from
 * related pages, Status, Publish Date and Link; plus the Ideas sheet (scoring
 * of every blog candidate) and the keyword cloud of each article.
 *
 * Order: blog clusters that support the most valuable pillars first (an article
 * is there to send buyers to a landing), with variety per month (no more than
 * half of a month from one pillar).
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expertise a topic needs (1 general, 2 practical, 3 specialist).
 */
function mzm_expertise( $text ) {
	if ( preg_match( '/(درمان|بیماری|افات|سم|سمی|شیمیایی|پزشک|دارو|حقوقی|قانون|مالیات|فنی|تعمیر|نصب|برق|ایمنی|حساسیت|مسمومیت)/u', $text ) ) {
		return 3;
	}
	if ( preg_match( '/(علت|دلیل|مشکل|تکثیر|پیوند|قلمه|هرس|کود|تنظیم|مقایسه|تفاوت|روش)/u', $text ) ) {
		return 2;
	}
	return 1;
}

/**
 * Publish dates: from the start date, on the chosen weekdays.
 *
 * @return string[] Gregorian Y-m-d
 */
function mzm_publish_dates( $start, $days, $count ) {
	$days = array_values( array_unique( array_map( 'intval', (array) $days ) ) ) ?: array( 6, 2 );
	$ts   = $start ? strtotime( $start ) : strtotime( 'next saturday', current_time( 'timestamp' ) ); // phpcs:ignore
	$out  = array();
	for ( $i = 0; count( $out ) < $count && $i < 800; $i++ ) {
		$d = $ts + $i * DAY_IN_SECONDS;
		if ( in_array( (int) gmdate( 'w', $d ), $days, true ) ) {
			$out[] = gmdate( 'Y-m-d', $d );
		}
	}
	return $out;
}

/**
 * Build the calendar.
 *
 * @return array {articles: [...], ideas: [...], meta}
 */
function mzm_calendar_build( $project, $keywords, $clusters, $pillars, $inventory ) {
	$cfg    = $project['cfg'];
	$months = max( 1, min( 12, (int) $cfg['months'] ) );
	$per    = max( 1, min( 40, (int) $cfg['per_month'] ) );
	$total  = $months * $per;
	$by_id  = array();
	foreach ( $clusters as $c ) {
		$by_id[ $c['id'] ] = $c;
	}
	// Candidates: blog clusters without a page that ranks. Calendar order = search
	// demand of the article + value of the pillar it feeds + ease.
	$cands   = array();
	$max_vol = 1;
	foreach ( $clusters as $c ) {
		if ( 'blog' === $c['page'] ) {
			$max_vol = max( $max_vol, $c['vol'] );
		}
	}
	foreach ( $clusters as $c ) {
		if ( 'blog' !== $c['page'] || 'ok' === $c['coverage'] || $c['vol'] <= 0 ) {
			continue;
		}
		$demand         = log( 1 + $c['vol'] ) / log( 1 + $max_vol );
		$c['cal_score'] = round( 100 * ( 0.55 * $demand + 0.30 * (float) ( $c['pillar_share'] ?? 0 ) + 0.15 * ( 100 - $c['diff'] ) / 100 ) - ( 'optimize' === $c['coverage'] ? 3 : 0 ), 1 );
		$cands[]        = $c;
	}
	$cands = mzm_sort( $cands, 'cal_score' );
	// Greedy pick with variety: ≤ half a month from one pillar, at most 2 from one section per month.
	$picked = array();
	$pool   = $cands;
	for ( $m = 1; $m <= $months && $pool; $m++ ) {
		$per_pillar  = array();
		$per_section = array();
		$month       = array();
		foreach ( array( 0, 1 ) as $relax ) {
			foreach ( $pool as $pi => $c ) {
				if ( count( $month ) >= $per ) {
					break;
				}
				$pk = $c['pillar'];
				$sk = $pk . '|' . $c['section'];
				if ( ! $relax && ( ( $per_pillar[ $pk ] ?? 0 ) >= max( 1, (int) ceil( $per / 2 ) ) || ( $per_section[ $sk ] ?? 0 ) >= 2 ) ) {
					continue;
				}
				$month[]            = $c;
				$per_pillar[ $pk ]  = ( $per_pillar[ $pk ] ?? 0 ) + 1;
				$per_section[ $sk ] = ( $per_section[ $sk ] ?? 0 ) + 1;
				unset( $pool[ $pi ] );
			}
		}
		foreach ( $month as $c ) {
			$picked[] = array( $m, $c );
		}
	}
	$dates = mzm_publish_dates( $cfg['start_date'], $cfg['publish_days'], count( $picked ) );
	$posts = array_values( array_filter( (array) $inventory, function ( $it ) {
		return 'post' === $it['type'];
	} ) );

	$articles = array();
	foreach ( $picked as $n => $pair ) {
		list( $m, $c ) = $pair;
		$gap  = (array) ( $c['gap'] ?? array() );
		$kws  = array_map( function ( $i ) use ( $keywords ) {
			return array( $keywords[ $i ]['k'], $keywords[ $i ]['v'] );
		}, $c['kws'] );
		$src  = '';
		if ( $c['serp'] && ! empty( $c['serp']['sources'] ) ) {
			$src = $c['serp']['sources'][0]['url'];
		}
		$articles[] = array(
			'n'        => $n + 1,
			'month'    => $m,
			'cluster'  => $c['id'],
			'pillar'   => $pillars[ $c['pillar'] ]['name'] ?? '',
			'idea'     => $c['name'],
			'kp'       => $c['vol'],
			'title'    => $gap['title'] ?? mzm_default_title( $c ),
			'desc'     => $gap['angle'] ?? ( 'optimize' === $c['coverage'] ? 'به‌روزرسانی و تکمیل مقاله فعلی' : '' ),
			'source'   => $src,
			'out'      => mzm_calendar_out_links( $c, $clusters, $pillars, $posts, $articles ),
			'in'       => array(),
			'status'   => 'optimize' === $c['coverage'] ? 'نیاز به آپدیت' : 'ایده',
			'date'     => $dates[ $n ] ?? '',
			'cloud'    => $kws,
			'outline'  => $gap['outline'] ?? array(),
			'faq'      => $gap['faq'] ?? array(),
			'words'    => (int) ( $gap['words'] ?? max( 900, min( 2500, 700 + 18 * count( $kws ) ) ) ),
			'existing' => 'optimize' === $c['coverage'] && $c['target'] ? $c['target']['url'] : '',
		);
	}
	// In links: related existing posts, then other calendar articles of the pillar.
	foreach ( $articles as $ai => &$a ) {
		$c     = $by_id[ $a['cluster'] ];
		$in    = array();
		$alias = array_values( array_unique( array_map( function ( $kv ) {
			return $kv[0];
		}, $a['cloud'] ) ) );
		$near  = array();
		foreach ( $posts as $p ) {
			$sim = mzm_jaccard( $c['core'], mzm_title_core( $p['title'], array() ) );
			if ( $sim > 0.15 && mzm_norm_url( $p['url'] ) !== mzm_norm_url( $a['existing'] ) ) {
				$near[] = array( 'url' => $p['url'], 'sim' => $sim );
			}
		}
		$near = mzm_sort( $near, 'sim' );
		foreach ( array_slice( $near, 0, 3 ) as $p ) {
			$in[] = array( 'url' => $p['url'], 'anchor' => '' );
		}
		foreach ( $articles as $bi => $b ) {
			if ( count( $in ) >= 5 ) {
				break;
			}
			if ( $bi !== $ai && $b['pillar'] === $a['pillar'] ) {
				$in[] = array( 'url' => 'مقاله: ' . $b['idea'], 'anchor' => '' );
			}
		}
		foreach ( $in as $k => &$l ) {
			$l['anchor'] = $alias[ $k % max( 1, count( $alias ) ) ] ?? $a['idea'];
		}
		unset( $l );
		$a['in'] = $in;
	}
	unset( $a );

	// Ideas: every blog candidate, scored like the agency «Ideas» sheet.
	$chosen = array_fill_keys( array_column( $articles, 'cluster' ), true );
	$ideas  = array();
	foreach ( $cands as $c ) {
		$text    = implode( ' ', array_map( function ( $i ) use ( $keywords ) {
			return $keywords[ $i ]['nk'];
		}, array_slice( $c['kws'], 0, 6 ) ) );
		$ideas[] = array(
			'cluster'   => $c['id'],
			'k'         => $c['name'],
			'v'         => $c['vol'],
			'kind'      => 'مقاله',
			'clarity'   => $c['serp'] ? (int) max( 1, min( 3, round( $c['serp']['clarity'] * 3 ) ) ) : '',
			'expertise' => mzm_expertise( $text ),
			'journey'   => mzm_journey( $c['intent'] ),
			'cannibal'  => count( $c['issues'] ?? array() ),
			'diff'      => $c['diff'],
			'score'     => $c['score'],
			'cal_score' => $c['cal_score'],
			'pick'      => isset( $chosen[ $c['id'] ] ),
			'pillar'    => $pillars[ $c['pillar'] ]['name'] ?? '',
		);
	}
	return array(
		'articles' => $articles,
		'ideas'    => $ideas,
		'meta'     => array( 'months' => $months, 'per_month' => $per, 'built' => mzm_now(), 'candidates' => count( $cands ) ),
	);
}

/**
 * Default SEO title of an article.
 */
function mzm_default_title( $c ) {
	$n = $c['name'];
	if ( preg_match( '/(چیست|چیه|چگونه|چطور|روش|علت|دلیل|راهنما|آموزش)/u', $n ) ) {
		return $n . ' | راهنمای کامل';
	}
	return $n . '؛ هر آنچه باید بدانید';
}

/**
 * Five out links of an article: the pillar's main landing, related category /
 * product landings, a related post. Planned pages are written as «لندینگ جدید: …».
 */
function mzm_calendar_out_links( $c, $clusters, $pillars, $posts, $articles ) {
	$links = array();
	$rel   = array();
	foreach ( $clusters as $o ) {
		if ( $o['pillar'] !== $c['pillar'] || 'blog' === $o['page'] || $o['id'] === $c['id'] ) {
			continue;
		}
		$sim   = mzm_jaccard( $c['core'], $o['core'] );
		$main  = 'main' === $o['page'] || 'لندینگ‌های اصلی' === ( $o['section'] ?? '' );
		$rel[] = array( 'c' => $o, 'w' => ( $main ? 2 : 0 ) + $sim + 0.3 * log10( 1 + $o['vol'] ) / 4 + ( $o['target'] ? 0.2 : 0 ) );
	}
	$rel = mzm_sort( $rel, 'w' );
	foreach ( array_slice( $rel, 0, 4 ) as $r ) {
		$o       = $r['c'];
		$links[] = array( 'url' => $o['target'] ? $o['target']['url'] : 'لندینگ جدید: ' . $o['name'], 'anchor' => $o['name'] );
	}
	// A related article (existing or planned).
	foreach ( $posts as $p ) {
		if ( count( $links ) >= 5 ) {
			break;
		}
		if ( mzm_jaccard( $c['core'], mzm_title_core( $p['title'], array() ) ) > 0.2 ) {
			$links[] = array( 'url' => $p['url'], 'anchor' => $p['title'] );
		}
	}
	foreach ( array_reverse( $articles ) as $b ) {
		if ( count( $links ) >= 5 ) {
			break;
		}
		if ( $b['pillar'] === ( $pillars[ $c['pillar'] ]['name'] ?? '' ) ) {
			$links[] = array( 'url' => 'BLOG Post', 'anchor' => $b['idea'] );
		}
	}
	// Other pillars' main landings fill the rest.
	foreach ( $clusters as $o ) {
		if ( count( $links ) >= 5 ) {
			break;
		}
		if ( 'main' === $o['page'] && $o['pillar'] !== $c['pillar'] ) {
			$links[] = array( 'url' => $o['target'] ? $o['target']['url'] : 'لندینگ جدید: ' . $o['name'], 'anchor' => $o['name'] );
		}
	}
	return array_slice( $links, 0, 5 );
}
