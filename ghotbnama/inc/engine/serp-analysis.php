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
 * full:    every keyword with ≥ 10 searches, by volume.
 *
 * @return int[] keyword indices, most valuable first, capped.
 */
function qtb_serp_plan( $keywords, $groups, $mode, $cap ) {
	$pick = array();
	if ( 'full' === $mode ) {
		foreach ( $keywords as $kw ) {
			if ( $kw['v'] >= 10 ) {
				$pick[ $kw['i'] ] = $kw['v'];
			}
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
	$hub_at = max( 6, (int) ceil( count( $urls ) * 0.08 ) );
	foreach ( $urls as $i => $list ) {
		$urls[ $i ] = array_values( array_filter( $list, function ( $u ) use ( $freq, $hub_at ) {
			return $freq[ $u ] < $hub_at;
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
	$page = 'i' === $intent ? 'blog' : ( ( $share['product'] ?? 0 ) > ( ( $share['category'] ?? 0 ) + ( $share['marketplace'] ?? 0 ) ) ? 'product' : 'category' );
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
