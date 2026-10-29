<?php
/**
 * Site mapping and sales-driven scoring.
 *
 * Mapping: every cluster is matched to the best existing page of the site
 * (product / category for landings, post for blog clusters) by the words of
 * its core and the page title; the SERP's own ranking URL wins when present.
 *   coverage ok        — we rank in the top 10 for the head keyword
 *   coverage optimize  — a page exists (or ranks below 10): improve it
 *   coverage new       — no page: create one
 *
 * Score (0–100) = commercial value^0.8 × (0.55 + 0.30 ease + 0.15 opportunity):
 *   value       traffic potential × intent weight (× conversion rate × average
 *               order value for revenue), log-scaled to the best cluster
 *   ease        100 − difficulty
 *   opportunity quick win at position 4–20, page to optimize, or a new page
 * Blog clusters earn a share of their pillar's commercial value (internal
 * links from articles feed the money pages).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Expected CTR at the realistic target position (≈ 3 for landings, ≈ 4 for blog).
 */
function qtb_target_ctr( $page ) {
	return 'blog' === $page ? 0.07 : 0.10;
}

/**
 * Share of buying value by intent.
 */
function qtb_intent_weight( $intent ) {
	$w = array( 't' => 1.0, 'l' => 0.9, 'n' => 0.9, 'm' => 0.6, 'c' => 0.45, 'i' => 0.08 );
	return $w[ $intent ] ?? 0.3;
}

/**
 * Tokens of a page title (same normalisation as the keywords, soft words removed).
 */
function qtb_title_core( $title, $stem ) {
	$soft = qtb_lex_set( 'soft' );
	$stop = qtb_lex_set( 'stop' );
	$out  = array();
	foreach ( qtb_tokens( $title ) as $t ) {
		$t = $stem[ $t ] ?? $t;
		if ( ! isset( $soft[ $t ] ) && ! isset( $stop[ $t ] ) ) {
			$out[] = $t;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Map clusters to site pages and flag cannibalization.
 *
 * @param array $inventory [{type, url, title, price?, cats?}]
 */
function qtb_map_clusters( $keywords, $clusters, $inventory, $fillers = array() ) {
	$vocab = array();
	foreach ( $keywords as $kw ) {
		foreach ( $kw['toks'] as $t ) {
			$vocab[ $t ] = ( $vocab[ $t ] ?? 0 ) + 1;
		}
	}
	foreach ( $inventory as $it ) {
		foreach ( qtb_tokens( $it['title'] ) as $t ) {
			$vocab[ $t ] = ( $vocab[ $t ] ?? 0 ) + 1;
		}
	}
	$stem  = qtb_stem_map( $vocab );
	$fill  = array_fill_keys( (array) $fillers, true );
	$pages = array();
	$index = array();
	foreach ( array_values( (array) $inventory ) as $pi => $it ) {
		$core = array_values( array_filter( qtb_title_core( $it['title'], $stem ), function ( $t ) use ( $fill ) {
			return ! isset( $fill[ $t ] );
		} ) );
		if ( ! $core ) {
			continue;
		}
		$pages[ $pi ] = array_merge( $it, array( 'core' => $core ) );
		foreach ( $core as $t ) {
			$index[ $t ][] = $pi;
		}
	}
	$used = array(); // url → cluster ids.
	foreach ( $clusters as &$c ) {
		$want = 'blog' === $c['page'] ? array( 'post' ) : ( 'product' === $c['page'] ? array( 'product', 'category' ) : array( 'category', 'product', 'page' ) );
		$cand = array();
		foreach ( $c['core'] as $t ) {
			foreach ( $index[ $t ] ?? array() as $pi ) {
				$cand[ $pi ] = true;
			}
		}
		$best   = null;
		$second = null;
		foreach ( array_keys( $cand ) as $pi ) {
			$p   = $pages[ $pi ];
			$sim = qtb_jaccard( $c['core'], $p['core'] );
			if ( ! array_diff( $c['core'], $p['core'] ) ) {
				$sim += 0.25; // The page title contains the whole topic.
			}
			$rank = array_search( $p['type'], $want, true );
			if ( false === $rank ) {
				$sim -= 0.35;
			} else {
				$sim -= 0.05 * $rank;
			}
			if ( null === $best || $sim > $best[1] ) {
				$second = $best;
				$best   = array( $pi, $sim );
			} elseif ( null === $second || $sim > $second[1] ) {
				$second = array( $pi, $sim );
			}
		}
		$c['target'] = null;
		$c['issues'] = array();
		if ( $best && $best[1] >= 0.55 ) {
			$p           = $pages[ $best[0] ];
			$c['target'] = array( 'url' => $p['url'], 'title' => $p['title'], 'type' => $p['type'], 'match' => round( min( 1, $best[1] ), 2 ) );
			if ( $second && $second[1] >= 0.75 && $pages[ $second[0] ]['type'] === $p['type'] ) {
				$c['issues'][] = array( 'type' => 'cannibal_pages', 'text' => 'دو صفحه سایت برای این کلاستر رقابت می‌کنند', 'urls' => array( $p['url'], $pages[ $second[0] ]['url'] ) );
			}
		}
		$serp = $c['serp'];
		if ( $serp && $serp['own_url'] ) {
			if ( $c['target'] && qtb_norm_url( $c['target']['url'] ) !== qtb_norm_url( $serp['own_url'] ) ) {
				$c['issues'][] = array( 'type' => 'wrong_page', 'text' => 'گوگل صفحه دیگری از سایت را برای این کلاستر نشان می‌دهد', 'urls' => array( $serp['own_url'], $c['target']['url'] ) );
			}
			if ( ! $c['target'] ) {
				$c['target'] = array( 'url' => $serp['own_url'], 'title' => '', 'type' => 'ranking', 'match' => 1 );
			}
		}
		$pos            = $serp ? (int) $serp['own_pos'] : 0;
		$c['coverage']  = $pos && $pos <= 10 ? 'ok' : ( $c['target'] ? 'optimize' : 'new' );
		if ( $c['target'] ) {
			$used[ qtb_norm_url( $c['target']['url'] ) ][] = $c['id'];
		}
		// Two of our URLs in one top 10: Google cannot decide which page answers the query.
		if ( $serp && count( (array) ( $serp['own_urls'] ?? array() ) ) >= 2 ) {
			$c['issues'][] = array( 'type' => 'serp_double', 'text' => 'دو صفحه از سایت در نتایج همین کلمه هستند (کنیبالیزیشن)', 'urls' => array_slice( $serp['own_urls'], 0, 3 ) );
		}
	}
	unset( $c );
	// One page targeted by clusters with different page kinds = keyword cannibalization.
	$by_id = array();
	foreach ( $clusters as $ci => $c ) {
		$by_id[ $c['id'] ] = $ci;
	}
	foreach ( $used as $url => $ids ) {
		if ( count( $ids ) < 2 ) {
			continue;
		}
		$names = array_map( function ( $id ) use ( $clusters, $by_id ) {
			return $clusters[ $by_id[ $id ] ]['name'];
		}, $ids );
		foreach ( $ids as $id ) {
			$ci = $by_id[ $id ];
			if ( $clusters[ $ci ]['target']['match'] < 0.8 && 'ranking' !== $clusters[ $ci ]['target']['type'] ) {
				// Weak match shared with another cluster: that page belongs to the other one.
				$clusters[ $ci ]['target']   = null;
				$clusters[ $ci ]['coverage'] = 'new';
				continue;
			}
			$clusters[ $ci ]['issues'][] = array( 'type' => 'shared_page', 'text' => 'این صفحه هدف چند کلاستر است: ' . implode( '، ', array_slice( $names, 0, 4 ) ), 'urls' => array( $url ) );
		}
	}
	return $clusters;
}

/**
 * Average product price of a pillar (from the mapped / matching products), else the project AOV.
 */
function qtb_pillar_prices( $clusters, $pillars, $inventory ) {
	$prices = array();
	foreach ( $pillars as $p ) {
		$sum = 0;
		$n   = 0;
		$name = qtb_norm_key( $p['name'] );
		foreach ( (array) $inventory as $it ) {
			if ( 'product' !== $it['type'] || empty( $it['price'] ) ) {
				continue;
			}
			$hay = qtb_norm_key( $it['title'] . ' ' . implode( ' ', (array) ( $it['cats'] ?? array() ) ) );
			if ( '' !== $name && false !== mb_strpos( $hay, $name ) ) {
				$sum += (float) $it['price'];
				++$n;
			}
		}
		$prices[ $p['id'] ] = $n ? $sum / $n : 0;
	}
	return $prices;
}

/**
 * Real sales of each pillar from the store: revenue and units of the last 90
 * days (orders), or lifetime units × price when orders are not available.
 *
 * @return array pillar id → {rev, qty, lifetime}
 */
function qtb_pillar_sales( $pillars, $inventory, $sales ) {
	$out  = array();
	$prod = (array) ( $sales['products'] ?? array() );
	foreach ( $pillars as $p ) {
		$name = qtb_norm_key( $p['name'] );
		$row  = array( 'rev' => 0, 'qty' => 0, 'lifetime' => 0 );
		foreach ( (array) $inventory as $it ) {
			if ( 'product' !== $it['type'] || '' === $name ) {
				continue;
			}
			$hay = qtb_norm_key( $it['title'] . ' ' . implode( ' ', (array) ( $it['cats'] ?? array() ) ) );
			if ( false === mb_strpos( $hay, $name ) ) {
				continue;
			}
			$pid = (int) ( $it['pid'] ?? 0 );
			if ( $pid && isset( $prod[ $pid ] ) ) {
				$row['qty'] += $prod[ $pid ][0];
				$row['rev'] += $prod[ $pid ][1];
			}
			$row['lifetime'] += (int) ( $it['sold'] ?? 0 ) * (float) ( $it['price'] ?? 0 );
		}
		$out[ $p['id'] ] = $row;
	}
	return $out;
}

/**
 * Score clusters (value, ease, opportunity, priority).
 *
 * @param array $sales Store sales summary (see qtb_sales_add), optional.
 */
function qtb_score_clusters( $project, $clusters, $pillars, $inventory, $sales = array() ) {
	$cr     = max( 0.05, (float) $project['cfg']['cr'] ) / 100;
	$prices = qtb_pillar_prices( $clusters, $pillars, $inventory );
	$psales = qtb_pillar_sales( $pillars, $inventory, $sales );
	// Proven demand: a pillar that already sells is a safer investment.
	$smax   = 0;
	foreach ( $psales as $ps ) {
		$smax = max( $smax, $ps['rev'] ?: $ps['lifetime'] );
	}
	$real_aov = ( (int) ( $sales['orders'] ?? 0 ) >= 5 ) ? (float) $sales['revenue'] / (int) $sales['orders'] : 0;
	$all    = array_filter( array_map( function ( $it ) {
		return 'product' === $it['type'] ? (float) ( $it['price'] ?? 0 ) : 0;
	}, (array) $inventory ) );
	$store_aov = $all ? array_sum( $all ) / count( $all ) : 0;
	$aov_cfg   = (float) $project['cfg']['aov'];
	// Commercial value of each pillar (for the blog support share).
	$pillar_val = array();
	foreach ( $clusters as &$c ) {
		$aov       = $aov_cfg > 0 ? $aov_cfg : ( $real_aov ?: ( ( $prices[ $c['pillar'] ] ?? 0 ) ?: $store_aov ) );
		$traffic   = $c['vol'] * qtb_target_ctr( $c['page'] );
		$w         = qtb_intent_weight( $c['intent'] );
		$orders    = $traffic * $cr * $w;
		$diff      = $c['serp'] ? (int) $c['serp']['difficulty'] : qtb_difficulty_free( $c );
		$c['diff'] = $diff;
		$c['value'] = array(
			'traffic' => round( $traffic, 1 ),
			'orders'  => round( $orders, 2 ),
			'revenue' => $aov > 0 ? round( $orders * $aov ) : 0,
			'aov'     => round( $aov ),
			'units'   => $traffic * $w, // Value without prices (for ranking).
		);
		if ( $smax > 0 && 'blog' !== $c['page'] ) {
			$ps                   = $psales[ $c['pillar'] ] ?? array( 'rev' => 0, 'lifetime' => 0 );
			$c['value']['units'] *= 1 + 0.6 * ( ( $ps['rev'] ?: $ps['lifetime'] ) / $smax );
		}
		if ( 'blog' !== $c['page'] ) {
			$pillar_val[ $c['pillar'] ] = ( $pillar_val[ $c['pillar'] ] ?? 0 ) + $c['value']['units'];
		}
	}
	unset( $c );
	$max_units  = max( 1, max( array_map( function ( $c ) {
		return $c['value']['units'];
	}, $clusters ) ?: array( 1 ) ) );
	$max_pillar = max( 1, max( $pillar_val ?: array( 1 ) ) );
	foreach ( $clusters as &$c ) {
		$share = ( $pillar_val[ $c['pillar'] ] ?? 0 ) / $max_pillar;
		$units = $c['value']['units'];
		if ( 'blog' === $c['page'] ) {
			// An article earns through the money pages it links to.
			$units = $c['value']['traffic'] * ( qtb_intent_weight( $c['intent'] ) + 0.25 * $share );
		}
		$value = min( 1, log( 1 + $units ) / log( 1 + $max_units ) );
		$ease  = ( 100 - $c['diff'] ) / 100;
		$pos   = $c['serp'] ? (int) $c['serp']['own_pos'] : 0;
		$quick = $pos >= 4 && $pos <= 20;
		if ( $quick ) {
			$opp = 1.0;
		} elseif ( 'optimize' === $c['coverage'] ) {
			$opp = 0.7;
		} elseif ( 'new' === $c['coverage'] ) {
			$opp = 0.45;
		} else {
			$opp = 0.1;
		}
		// Multiplicative: an easy keyword nobody buys from is not a priority.
		$c['score']        = (int) round( 100 * pow( $value, 0.8 ) * ( 0.55 + 0.30 * $ease + 0.15 * $opp ) );
		$c['quick_win']    = $quick;
		$c['pillar_share'] = round( $share, 3 );
	}
	unset( $c );
	// Priority by rank: top 20% P1, next 35% P2, the rest P3.
	$scores = array_column( $clusters, 'score' );
	rsort( $scores );
	$n  = count( $scores );
	$p1 = $n ? $scores[ (int) floor( ( $n - 1 ) * 0.2 ) ] : 0;
	$p2 = $n ? $scores[ (int) floor( ( $n - 1 ) * 0.55 ) ] : 0;
	foreach ( $clusters as &$c ) {
		$c['priority'] = ( $c['score'] >= $p1 || ( $c['quick_win'] && $c['score'] >= $p2 ) ) ? 'P1' : ( $c['score'] >= $p2 ? 'P2' : 'P3' );
	}
	unset( $c );
	return $clusters;
}

/**
 * Difficulty estimate without SERP: head terms are harder than long-tail.
 */
function qtb_difficulty_free( $c ) {
	$d = 18 + 9 * log10( 1 + $c['vol'] ) - 4 * ( count( $c['core'] ) - 1 );
	if ( 'blog' === $c['page'] ) {
		$d -= 5;
	}
	return (int) max( 5, min( 90, round( $d ) ) );
}

/**
 * Pillar totals for the dashboard.
 */
function qtb_pillar_totals( $clusters, $pillars, $inventory = array(), $sales = array() ) {
	$psales = qtb_pillar_sales( $pillars, $inventory, $sales );
	$by = array();
	foreach ( $clusters as $c ) {
		$by[ $c['id'] ] = $c;
	}
	foreach ( $pillars as &$p ) {
		$p['revenue']  = 0;
		$p['traffic']  = 0;
		$p['p1']       = 0;
		$p['new']      = 0;
		$p['landings'] = 0;
		$p['blogs']    = 0;
		$p['score']    = 0;
		$p['sales_rev'] = (int) round( $psales[ $p['id'] ]['rev'] ?? 0 );
		$p['sales_qty'] = (int) ( $psales[ $p['id'] ]['qty'] ?? 0 );
		foreach ( $p['clusters'] as $cid ) {
			$c = $by[ $cid ] ?? null;
			if ( ! $c ) {
				continue;
			}
			$p['revenue'] += $c['value']['revenue'];
			$p['traffic'] += $c['value']['traffic'];
			$p['p1']      += 'P1' === $c['priority'] ? 1 : 0;
			$p['new']     += 'new' === $c['coverage'] ? 1 : 0;
			$p['score']    = max( $p['score'], $c['score'] );
			if ( 'blog' === $c['page'] ) {
				++$p['blogs'];
			} else {
				++$p['landings'];
			}
		}
	}
	unset( $p );
	return $pillars;
}
