<?php
/**
 * SERP stage: which keywords to look up, SERP-overlap clustering (merge
 * synonyms, split groups Google treats as different), and per-cluster SERP
 * metrics (intent, page type, difficulty, our position, competitors, questions).
 *
 * Overlap = number of identical URLs in two keywords' top 10. Two keywords that
 * share ≥ N URLs (project strictness: 3 flexible, 4 balanced, 5 strict) are
 * answered by the same page → one cluster.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keywords to look up for a SERP mode.
 *
 * economy: the head of every group (with volume), a second member of big groups
 *          (the one least similar to the head, to test whether the group is one page),
 *          and every other keyword with ≥ 1000 searches.
 * full:    every keyword, by volume.
 *
 * @return int[] keyword indices, most valuable first, capped.
 */
function qtb_serp_plan( $keywords, $groups, $mode, $cap ) {
	$pick = array();
	if ( 'full' === $mode ) {
		foreach ( $keywords as $kw ) {
			$pick[ $kw['i'] ] = $kw['v'];
		}
	} else {
		foreach ( $groups as $g ) {
			$head = $keywords[ $g['head'] ];
			if ( $g['vol'] < 20 ) {
				continue;
			}
			$pick[ $g['head'] ] = $g['vol'] + 1000000; // Heads first.
			if ( count( $g['kws'] ) >= 6 ) {
				$best = null;
				foreach ( array_slice( $g['kws'], 1 ) as $m ) {
					if ( $keywords[ $m ]['v'] < 30 ) {
						continue;
					}
					$sim = qtb_jaccard( $keywords[ $m ]['core'], $head['core'] );
					if ( null === $best || $sim < $best[1] ) {
						$best = array( $m, $sim );
					}
				}
				if ( $best && $best[1] < 0.75 ) {
					$pick[ $best[0] ] = $keywords[ $best[0] ]['v'];
				}
			}
			foreach ( $g['kws'] as $m ) {
				if ( $keywords[ $m ]['v'] >= 1000 && ! isset( $pick[ $m ] ) ) {
					$pick[ $m ] = $keywords[ $m ]['v'];
				}
			}
		}
	}
	arsort( $pick );
	return array_slice( array_keys( $pick ), 0, max( 0, (int) $cap ) );
}

/**
 * Page type of a result: product, category, article, marketplace, video, forum, wiki, home, other.
 */
function qtb_result_type( $row ) {
	$domain = (string) ( $row['domain'] ?? '' );
	$path   = rawurldecode( (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH ) );
	$title  = qtb_norm_key( (string) ( $row['title'] ?? '' ) );
	if ( qtb_domain_in( $domain, 'video_domains' ) ) {
		return 'video';
	}
	if ( qtb_domain_in( $domain, 'forum_domains' ) ) {
		return 'forum';
	}
	if ( false !== strpos( $domain, 'wikipedia.org' ) || false !== strpos( $domain, 'wikifa' ) ) {
		return 'wiki';
	}
	$guess = qtb_url_type_guess( $path );
	if ( 'post' === $guess ) {
		return 'article';
	}
	if ( qtb_domain_in( $domain, 'market_domains' ) ) {
		return 'marketplace';
	}
	if ( 'product' === $guess || 'category' === $guess ) {
		return $guess;
	}
	if ( '' === trim( $path, '/' ) ) {
		return 'home';
	}
	$buy  = (bool) qtb_lex_hit( $title, 'transactional' );
	$info = (bool) qtb_lex_hit( $title, 'informational' ) || false !== strpos( (string) ( $row['title'] ?? '' ), '؟' );
	if ( $buy && ! $info ) {
		return 'category';
	}
	if ( $info ) {
		return 'article';
	}
	return 'other';
}

/**
 * Normalised URL keys of a SERP (top 10).
 */
function qtb_serp_urls( $serp ) {
	$out = array();
	foreach ( (array) ( $serp['organic'] ?? array() ) as $o ) {
		$out[] = qtb_norm_url( $o['url'] );
	}
	return array_values( array_unique( array_filter( $out ) ) );
}

/**
 * SERP-overlap clustering on top of the pre-clusters.
 *
 * @param array $keywords Annotated keywords.
 * @param array $groups   Pre-clusters.
 * @param array $serps    keyword index → normalised SERP.
 * @param int   $need     URLs to share for a merge.
 * @return array groups (with 'checked' = SERP-verified)
 */
function qtb_serp_cluster( $keywords, $groups, $serps, $need ) {
	$need = max( 2, min( 7, (int) $need ) );
	$urls = array();
	foreach ( $serps as $i => $s ) {
		$urls[ $i ] = qtb_serp_urls( $s );
	}
	// Hub URLs (a homepage ranking for everything) say nothing about intent.
	$freq = array();
	foreach ( $urls as $list ) {
		foreach ( $list as $u ) {
			$freq[ $u ] = ( $freq[ $u ] ?? 0 ) + 1;
		}
	}
	$hub_at = max( 6, (int) ceil( count( $urls ) * 0.15 ) );
	foreach ( $urls as $i => $list ) {
		$urls[ $i ] = array_values( array_filter( $list, function ( $u ) use ( $freq, $hub_at ) {
			return ! qtb_is_hub_url( $u, $freq[ $u ], $hub_at );
		} ) );
	}
	$overlap = function ( $a, $b ) use ( $urls ) {
		return ( isset( $urls[ $a ], $urls[ $b ] ) ) ? count( array_intersect( $urls[ $a ], $urls[ $b ] ) ) : -1;
	};

	// 1. Split groups whose two looked-up members do not share the top 10.
	$split = array();
	foreach ( $groups as $g ) {
		$reps = array_values( array_filter( $g['kws'], function ( $m ) use ( $urls ) {
			return isset( $urls[ $m ] );
		} ) );
		if ( count( $reps ) >= 2 && count( $urls[ $reps[0] ] ) >= 5 && count( $urls[ $reps[1] ] ) >= 5 && $overlap( $reps[0], $reps[1] ) <= 1 ) {
			$a = array();
			$b = array();
			foreach ( $g['kws'] as $m ) {
				if ( $m === $reps[1] ) {
					$b[] = $m;
				} elseif ( $m === $reps[0] ) {
					$a[] = $m;
				} elseif ( isset( $urls[ $m ] ) ) {
					if ( $overlap( $m, $reps[1] ) > $overlap( $m, $reps[0] ) ) {
						$b[] = $m;
					} else {
						$a[] = $m;
					}
				} else {
					$sa = qtb_jaccard( $keywords[ $m ]['core'], $keywords[ $reps[0] ]['core'] );
					$sb = qtb_jaccard( $keywords[ $m ]['core'], $keywords[ $reps[1] ]['core'] );
					if ( $sb > $sa ) {
						$b[] = $m;
					} else {
						$a[] = $m;
					}
				}
			}
			$split[] = qtb_group_make( $keywords, $a );
			$split[] = qtb_group_make( $keywords, $b );
		} else {
			$split[] = $g;
		}
	}
	$groups = $split;

	// 2. Merge groups whose looked-up members share the top 10.
	$owner = array(); // keyword → group index.
	foreach ( $groups as $gi => $g ) {
		foreach ( $g['kws'] as $m ) {
			$owner[ $m ] = $gi;
		}
	}
	$by_url = array();
	foreach ( $urls as $i => $list ) {
		foreach ( $list as $u ) {
			$by_url[ $u ][] = $i;
		}
	}
	$pairs = array();
	foreach ( $by_url as $list ) {
		$n = count( $list );
		for ( $x = 0; $x < $n; $x++ ) {
			for ( $y = $x + 1; $y < $n; $y++ ) {
				$a = min( $list[ $x ], $list[ $y ] );
				$b = max( $list[ $x ], $list[ $y ] );
				if ( $owner[ $a ] !== $owner[ $b ] ) {
					$pairs[ $a . ':' . $b ] = ( $pairs[ $a . ':' . $b ] ?? 0 ) + 1;
				}
			}
		}
	}
	arsort( $pairs );
	$parent = range( 0, max( 0, count( $groups ) - 1 ) );
	$head   = array(); // set root → head keyword (biggest-volume looked-up member).
	foreach ( $groups as $gi => $g ) {
		foreach ( $g['kws'] as $m ) {
			if ( isset( $urls[ $m ] ) ) {
				$head[ $gi ] = $m;
				break;
			}
		}
	}
	foreach ( $pairs as $pair => $shared ) {
		if ( $shared < $need ) {
			break;
		}
		list( $a, $b ) = array_map( 'intval', explode( ':', $pair ) );
		$ra = qtb_uf_find( $parent, $owner[ $a ] );
		$rb = qtb_uf_find( $parent, $owner[ $b ] );
		if ( $ra === $rb ) {
			continue;
		}
		// No chains: the two clusters' heads must agree too.
		$ha = $head[ $ra ] ?? $a;
		$hb = $head[ $rb ] ?? $b;
		if ( $overlap( $ha, $hb ) < $need - 1 ) {
			continue;
		}
		$keep          = $keywords[ $ha ]['v'] >= $keywords[ $hb ]['v'] ? $ra : $rb;
		$drop          = $keep === $ra ? $rb : $ra;
		$parent[ $drop ] = $keep;
		$head[ $keep ]   = $keep === $ra ? $ha : $hb;
	}
	$sets = array();
	foreach ( array_keys( $groups ) as $gi ) {
		$sets[ qtb_uf_find( $parent, $gi ) ][] = $gi;
	}
	$out = array();
	foreach ( $sets as $gis ) {
		$members = array();
		foreach ( $gis as $gi ) {
			$members = array_merge( $members, $groups[ $gi ]['kws'] );
		}
		$g            = qtb_group_make( $keywords, $members );
		$g['checked'] = count( array_filter( $members, function ( $m ) use ( $urls ) {
			return isset( $urls[ $m ] );
		} ) );
		$g['merged']  = count( $gis );
		$out[]        = $g;
	}
	return $out;
}

/**
 * A «hub» URL says nothing about intent: a site's home page or search page that
 * ranks for everything. A category page shared by many keywords of one topic is
 * exactly the signal we want, so only home / search pages can be hubs.
 */
function qtb_is_hub_url( $norm_url, $freq, $hub_at ) {
	if ( $freq < $hub_at ) {
		return false;
	}
	$path = (string) substr( $norm_url, (int) strpos( $norm_url, '/' ) );
	return '/' === $path || '' === $path || (bool) preg_match( '#^/(search|s|q|jostojo|جستجو)(/|$)#u', $path );
}

/**
 * Position weight (top results count more).
 */
function qtb_pos_weight( $p ) {
	return 1 / log( 1 + max( 1, (int) $p ), 2 );
}

/**
 * SERP metrics of a cluster from the SERP of its best looked-up member.
 *
 * @return array {types, intent, page, clarity, difficulty, own_pos, own_url, competitors, paa, related, features, sources}
 */
function qtb_serp_metrics( $serp, $head_kw, $own_domain ) {
	$w     = array();
	$total = 0;
	$strong = 0;
	$exact  = 0;
	$market = 0;
	$comp   = array();
	$src    = array();
	$own    = array( 0, '' );
	$owns   = array();
	$hk     = qtb_key( $head_kw );
	foreach ( (array) ( $serp['organic'] ?? array() ) as $o ) {
		$t        = qtb_result_type( $o );
		$wt       = qtb_pos_weight( $o['p'] );
		$w[ $t ]  = ( $w[ $t ] ?? 0 ) + $wt;
		$total   += $wt;
		$is_own   = $own_domain && ( $o['domain'] === $own_domain || substr( $o['domain'], -strlen( '.' . $own_domain ) ) === '.' . $own_domain );
		if ( $is_own ) {
			$owns[] = (string) $o['url'];
			if ( ! $own[0] ) {
				$own = array( (int) $o['p'], (string) $o['url'] );
			}
			continue;
		}
		if ( qtb_domain_in( $o['domain'], 'strong' ) ) {
			++$strong;
		}
		if ( qtb_domain_in( $o['domain'], 'market_domains' ) ) {
			++$market;
		}
		if ( '' !== $hk && false !== mb_strpos( qtb_key( $o['title'] ), $hk ) ) {
			++$exact;
		}
		$comp[] = $o['domain'];
		if ( 'article' === $t && count( $src ) < 3 ) {
			$src[] = array( 'url' => $o['url'], 'title' => $o['title'] );
		}
	}
	$share = array();
	foreach ( $w as $t => $v ) {
		$share[ $t ] = $total ? round( $v / $total, 3 ) : 0;
	}
	arsort( $share );
	$trans = ( $share['product'] ?? 0 ) + ( $share['category'] ?? 0 ) + ( $share['marketplace'] ?? 0 ) + 0.5 * ( $share['home'] ?? 0 );
	$info  = ( $share['article'] ?? 0 ) + ( $share['video'] ?? 0 ) + ( $share['forum'] ?? 0 ) + ( $share['wiki'] ?? 0 );
	if ( ! empty( $serp['features']['shopping'] ) ) {
		$trans += 0.15;
	}
	if ( $trans >= 0.6 ) {
		$intent = 't';
	} elseif ( $info >= 0.6 ) {
		$intent = 'i';
	} else {
		$intent = 'm';
	}
	$page = 'i' === $intent ? 'article' : ( ( $share['product'] ?? 0 ) > ( ( $share['category'] ?? 0 ) + ( $share['marketplace'] ?? 0 ) ) ? 'product' : 'archive' );
	$n    = max( 1, count( (array) ( $serp['organic'] ?? array() ) ) );
	$diff = 12 + 6 * $strong + 2 * $market + 2.5 * $exact + ( $own[0] && $own[0] <= 3 ? -10 : 0 );
	return array(
		'types'       => $share,
		'intent'      => $intent,
		'page'        => $page,
		'clarity'     => round( max( $trans, $info ), 2 ),
		'difficulty'  => (int) max( 5, min( 95, round( $diff * 10 / $n ) ) ),
		'own_pos'     => $own[0],
		'own_url'     => $own[1],
		'own_urls'    => array_values( array_unique( $owns ) ),
		'competitors' => array_values( array_unique( $comp ) ),
		'paa'         => array_slice( (array) ( $serp['paa'] ?? array() ), 0, 8 ),
		'related'     => array_slice( (array) ( $serp['related'] ?? array() ), 0, 8 ),
		'features'    => (array) ( $serp['features'] ?? array() ),
		'sources'     => $src,
		'titles'      => array_slice( array_map( function ( $o ) {
			return $o['title'];
		}, (array) ( $serp['organic'] ?? array() ) ), 0, 10 ),
	);
}

/**
 * Keyword-level SERP clustering (every keyword has its own top 10).
 *
 * Keywords are taken by volume; each one joins the cluster whose head shares
 * at least $need of its top-10 URLs AND whose biggest members share at least
 * $need − 1 (no chains of «A≈B, B≈C» pulling A and C together). Otherwise it
 * starts a cluster. Keywords without a SERP join by meaning only (low confidence).
 *
 * Every keyword gets: conf (high | mid | low), shared (URLs with the head),
 * alt (the runner-up cluster head and its shared URLs) and why (serp | lexical).
 *
 * @param array $keywords Annotated keywords.
 * @param int[] $members  Keyword indices to cluster.
 * @param array $serps    keyword index → SERP.
 * @param int   $need     Shared URLs for «same page».
 * @param array $seed     Optional fixed clusters [[kw indices]] (approved earlier): new keywords may join them.
 * @return array groups [{kws, head, core, vol, checked, meta: [i => {conf, shared, alt, alt_shared, why}], seed?}]
 */
function qtb_serp_cluster_kw( $keywords, $members, $serps, $need, $seed = array() ) {
	$need = max( 2, min( 7, (int) $need ) );
	$urls = array();
	$freq = array();
	foreach ( $serps as $i => $s ) {
		$list = array();
		foreach ( (array) ( $s['organic'] ?? array() ) as $o ) {
			$u = qtb_norm_url( $o['url'] );
			if ( '' !== $u && ! isset( $list[ $u ] ) ) {
				$list[ $u ] = qtb_pos_weight( $o['p'] );
			}
		}
		$urls[ $i ] = $list;
		foreach ( $list as $u => $w ) {
			$freq[ $u ] = ( $freq[ $u ] ?? 0 ) + 1;
		}
	}
	$hub_at = max( 6, (int) ceil( count( $urls ) * 0.15 ) );
	foreach ( $urls as $i => $list ) {
		$urls[ $i ] = array_filter( $list, function ( $u ) use ( $freq, $hub_at ) {
			return ! qtb_is_hub_url( $u, $freq[ $u ], $hub_at );
		}, ARRAY_FILTER_USE_KEY );
	}
	$shared = function ( $a, $b ) use ( $urls ) {
		if ( ! isset( $urls[ $a ], $urls[ $b ] ) ) {
			return -1;
		}
		return count( array_intersect_key( $urls[ $a ], $urls[ $b ] ) );
	};
	$weighted = function ( $a, $b ) use ( $urls ) {
		$w = 0;
		foreach ( array_intersect_key( $urls[ $a ], $urls[ $b ] ) as $u => $x ) {
			$w += $x * $urls[ $b ][ $u ];
		}
		return $w;
	};

	$clusters = array(); // [{head, kws[], meta[]}]
	$index    = array(); // url → cluster ids.
	$add_url  = function ( $ci, $i ) use ( &$index, $urls ) {
		foreach ( array_keys( $urls[ $i ] ?? array() ) as $u ) {
			$index[ $u ][ $ci ] = true;
		}
	};
	foreach ( (array) $seed as $set ) {
		$set = array_values( array_filter( (array) $set, function ( $i ) use ( $keywords ) {
			return isset( $keywords[ $i ] );
		} ) );
		if ( ! $set ) {
			continue;
		}
		$ci              = count( $clusters );
		$clusters[ $ci ] = array( 'head' => $set[0], 'kws' => $set, 'meta' => array(), 'seed' => true );
		foreach ( $set as $i ) {
			$add_url( $ci, $i );
		}
	}
	$order = array_values( array_filter( (array) $members, function ( $i ) use ( $keywords ) {
		return isset( $keywords[ $i ] );
	} ) );
	usort( $order, function ( $a, $b ) use ( $keywords ) {
		return $keywords[ $b ]['v'] <=> $keywords[ $a ]['v'];
	} );
	$no_serp = array();
	foreach ( $order as $i ) {
		if ( empty( $urls[ $i ] ) ) {
			$no_serp[] = $i;
			continue;
		}
		$cand = array();
		foreach ( array_keys( $urls[ $i ] ) as $u ) {
			foreach ( array_keys( $index[ $u ] ?? array() ) as $ci ) {
				$cand[ $ci ] = true;
			}
		}
		$best = null;
		$alt  = null;
		foreach ( array_keys( $cand ) as $ci ) {
			$c  = $clusters[ $ci ];
			$sh = $shared( $i, $c['head'] );
			if ( $sh < 0 ) {
				// Seeded head without a SERP: use its best member.
				$sh = 0;
				foreach ( $c['kws'] as $m ) {
					$sh = max( $sh, $shared( $i, $m ) );
				}
			}
			$ok = $sh >= $need;
			if ( $ok ) {
				foreach ( array_slice( $c['kws'], 1, 3 ) as $m ) {
					$x = $shared( $i, $m );
					if ( $x >= 0 && $x < $need - 1 ) {
						$ok = false;
						break;
					}
				}
			}
			$rank = array( $ok ? 1 : 0, $sh, isset( $urls[ $c['head'] ] ) ? $weighted( $i, $c['head'] ) : 0 );
			if ( null === $best || $rank > $best[1] ) {
				$alt  = $best;
				$best = array( $ci, $rank );
			} elseif ( null === $alt || $rank > $alt[1] ) {
				$alt = array( $ci, $rank );
			}
		}
		if ( $best && 1 === $best[1][0] ) {
			$ci                         = $best[0];
			$clusters[ $ci ]['kws'][]   = $i;
			$sh                         = $best[1][1];
			$clusters[ $ci ]['meta'][ $i ] = array(
				'conf'       => $sh >= $need + 2 ? 'high' : 'mid',
				'shared'     => $sh,
				'alt'        => $alt ? $clusters[ $alt[0] ]['head'] : null,
				'alt_shared' => $alt ? $alt[1][1] : 0,
				'why'        => 'serp',
			);
			$add_url( $ci, $i );
		} else {
			$ci              = count( $clusters );
			$clusters[ $ci ] = array(
				'head' => $i,
				'kws'  => array( $i ),
				'meta' => array( $i => array( 'conf' => $best && $best[1][1] >= $need - 1 ? 'mid' : 'high', 'shared' => 10, 'alt' => $best ? $clusters[ $best[0] ]['head'] : null, 'alt_shared' => $best ? $best[1][1] : 0, 'why' => 'serp' ) ),
			);
			$add_url( $ci, $i );
		}
	}
	// Keywords without a SERP: by meaning (all words of a cluster head, no intent-shifting extra word).
	$shift = qtb_shift_words( $keywords );
	foreach ( $no_serp as $i ) {
		$core = $keywords[ $i ]['core'];
		$best = null;
		foreach ( $clusters as $ci => $c ) {
			$hc = $keywords[ $c['head'] ]['core'];
			if ( array_diff( $hc, $core ) || array_intersect_key( array_flip( array_diff( $core, $hc ) ), $shift ) ) {
				continue;
			}
			$sim = qtb_jaccard( $core, $hc );
			if ( null === $best || $sim > $best[1] ) {
				$best = array( $ci, $sim );
			}
		}
		if ( $best && $best[1] >= 0.5 ) {
			$clusters[ $best[0] ]['kws'][]      = $i;
			$clusters[ $best[0] ]['meta'][ $i ] = array( 'conf' => 'low', 'shared' => 0, 'alt' => null, 'alt_shared' => 0, 'why' => 'lexical' );
		} else {
			$clusters[] = array( 'head' => $i, 'kws' => array( $i ), 'meta' => array( $i => array( 'conf' => 'low', 'shared' => 0, 'alt' => null, 'alt_shared' => 0, 'why' => 'lexical' ) ) );
		}
	}
	$out = array();
	foreach ( $clusters as $c ) {
		$g            = qtb_group_make( $keywords, $c['kws'] );
		$g['meta']    = $c['meta'];
		$g['seed']    = ! empty( $c['seed'] );
		$g['checked'] = count( array_filter( $c['kws'], function ( $i ) use ( $urls ) {
			return ! empty( $urls[ $i ] );
		} ) );
		$out[]        = $g;
	}
	return $out;
}

/**
 * URLs two keywords share in their top 10 (for the evidence panel).
 *
 * @return array [{url, pa, pb}]
 */
function qtb_serp_shared_urls( $serp_a, $serp_b ) {
	$pos = array();
	foreach ( (array) ( $serp_b['organic'] ?? array() ) as $o ) {
		$pos[ qtb_norm_url( $o['url'] ) ] = (int) $o['p'];
	}
	$out = array();
	foreach ( (array) ( $serp_a['organic'] ?? array() ) as $o ) {
		$u = qtb_norm_url( $o['url'] );
		if ( isset( $pos[ $u ] ) ) {
			$out[] = array( 'url' => $o['url'], 'pa' => (int) $o['p'], 'pb' => $pos[ $u ] );
		}
	}
	return $out;
}
