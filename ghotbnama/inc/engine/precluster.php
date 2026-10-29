<?php
/**
 * Free (no SERP) pre-clustering of Persian keywords.
 *
 * 1. Tokens are stemmed against the list's own vocabulary and spelling variants
 *    are folded into the common spelling.
 * 2. Soft words (خرید، قیمت، روش، علت…) and data-driven fillers (tokens that are
 *    optional in this list, e.g. «گل» in «گل پتوس» / «پتوس») are removed → the
 *    core of the query. Same core = same page.
 * 3. Long-tail queries join their parent: «نگهداری پتوس در زمستان» joins
 *    «نگهداری پتوس»; a commercial query with a rare extra word joins its parent,
 *    but a recurring facet («پتوس ابلق»، «پتوس حصیری») keeps its own landing.
 *
 * The SERP stage later confirms, merges (synonyms) or splits these groups.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Union-find helpers.
 */
function qtb_uf_find( &$parent, $x ) {
	while ( $parent[ $x ] !== $x ) {
		$parent[ $x ] = $parent[ $parent[ $x ] ];
		$x            = $parent[ $x ];
	}
	return $x;
}

/**
 * Join two sets.
 */
function qtb_uf_union( &$parent, $a, $b ) {
	$ra = qtb_uf_find( $parent, $a );
	$rb = qtb_uf_find( $parent, $b );
	if ( $ra !== $rb ) {
		$parent[ $rb ] = $ra;
	}
}

/**
 * Intent of one keyword from the lexicon: t transactional, c commercial,
 * i informational, l local, '' entity only (e.g. «پتوس»).
 */
function qtb_kw_intent_lex( $nk ) {
	if ( qtb_lex_hit( $nk, 'transactional' ) ) {
		return 't';
	}
	if ( qtb_lex_hit( $nk, 'local' ) ) {
		return 'l';
	}
	if ( qtb_lex_hit( $nk, 'informational' ) ) {
		return 'i';
	}
	if ( qtb_lex_hit( $nk, 'commercial' ) ) {
		return 'c';
	}
	return '';
}

/**
 * Annotate keywords with tokens, core and lexical intent.
 *
 * @param array $keywords Cleaned keywords (by reference).
 * @return array {fillers: string[]}
 */
function qtb_annotate( &$keywords ) {
	$vocab = array();
	foreach ( $keywords as $kw ) {
		foreach ( explode( ' ', $kw['nk'] ) as $t ) {
			$vocab[ $t ] = ( $vocab[ $t ] ?? 0 ) + 1;
		}
	}
	$stem    = qtb_stem_map( $vocab );
	$svocab  = array();
	foreach ( $vocab as $t => $f ) {
		$s            = $stem[ $t ] ?? $t;
		$svocab[ $s ] = ( $svocab[ $s ] ?? 0 ) + $f;
	}
	$variant = qtb_variant_map( $svocab );
	$syn     = qtb_lexicon()['synonyms'];
	$soft    = qtb_lex_set( 'soft' );
	$stop    = qtb_lex_set( 'stop' );
	$info    = qtb_lex_set( 'informational' );

	// Pass 1: tokens and core before fillers.
	$core_keys = array();
	foreach ( $keywords as &$kw ) {
		$toks = array();
		foreach ( explode( ' ', $kw['nk'] ) as $t ) {
			$t = $syn[ $t ] ?? $t;
			$t = $stem[ $t ] ?? $t;
			$t = $variant[ $t ] ?? $t;
			$t = $syn[ $t ] ?? $t;
			if ( '' !== $t ) {
				$toks[] = $t;
			}
		}
		$core = array_values( array_unique( array_filter( $toks, function ( $t ) use ( $soft, $stop ) {
			return ! isset( $soft[ $t ] ) && ! isset( $stop[ $t ] );
		} ) ) );
		if ( ! $core ) {
			$core = array_values( array_unique( array_filter( $toks, function ( $t ) use ( $stop ) {
				return ! isset( $stop[ $t ] );
			} ) ) ) ?: $toks;
		}
		$kw['toks']    = $toks;
		$kw['core0']   = $core;
		$kw['intent0'] = qtb_kw_intent_lex( $kw['nk'] );
		$sorted        = $core;
		sort( $sorted );
		$core_keys[ implode( ' ', $sorted ) ] = true;
	}
	unset( $kw );

	// Pass 2: data-driven fillers. A token is a filler when it is optional in this
	// list AND dropping it keeps the volume (same concept: «گل پتوس» / «پتوس»). A facet
	// that narrows the query («پتوس ابلق») has a much smaller volume than its parent.
	$vol_of = array();
	foreach ( $keywords as $kw ) {
		$s = $kw['core0'];
		sort( $s );
		$k = implode( ' ', $s );
		$vol_of[ $k ] = max( $vol_of[ $k ] ?? 0, (int) $kw['v'] );
	}
	$df     = array();
	$ratios = array();
	foreach ( $keywords as $kw ) {
		foreach ( $kw['core0'] as $t ) {
			$df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
		}
	}
	// Compare query groups (all wordings of one core), head level only: «گل پتوس» vs «پتوس».
	foreach ( $vol_of as $key => $vol ) {
		$core = explode( ' ', $key );
		if ( 2 !== count( $core ) ) {
			continue;
		}
		foreach ( $core as $t ) {
			$rk = implode( ' ', array_diff( $core, array( $t ) ) );
			if ( isset( $vol_of[ $rk ] ) && $vol_of[ $rk ] > 0 ) {
				$ratios[ $t ][] = $vol / $vol_of[ $rk ];
			}
		}
	}
	$fillers = array();
	foreach ( $ratios as $t => $list ) {
		$n = count( $list );
		// A token searched on its own («پتوس») is a topic, never a filler.
		if ( $n < 3 || isset( $vol_of[ $t ] ) || isset( $info[ $t ] ) || preg_match( '/\d/', $t ) ) {
			continue;
		}
		$gm = exp( array_sum( array_map( function ( $r ) {
			return log( max( 0.001, $r ) );
		}, $list ) ) / $n );
		if ( $gm >= 0.2 ) {
			$fillers[ $t ] = true;
		}
	}
	foreach ( $keywords as &$kw ) {
		$core = array_values( array_filter( $kw['core0'], function ( $t ) use ( $fillers ) {
			return ! isset( $fillers[ $t ] );
		} ) );
		if ( ! $core ) {
			$core = $kw['core0'];
		}
		$kw['cmp'] = implode( '', $core ); // Token order of the query.
		sort( $core );
		$kw['core'] = $core;
		$kw['ck']   = implode( ' ', $core );
		unset( $kw['core0'] );
	}
	unset( $kw );
	return array( 'fillers' => array_keys( $fillers ) );
}

/**
 * Pre-cluster annotated keywords.
 *
 * @return array clusters [{id, kws: int[], head: int, ck, core, vol}]
 */
function qtb_precluster( $keywords ) {
	$n      = count( $keywords );
	$parent = range( 0, max( 0, $n - 1 ) );
	$by_ck  = array();
	$by_cmp = array();
	foreach ( $keywords as $kw ) {
		$i = $kw['i'];
		if ( isset( $by_ck[ $kw['ck'] ] ) ) {
			qtb_uf_union( $parent, $by_ck[ $kw['ck'] ], $i );
		} else {
			$by_ck[ $kw['ck'] ] = $i;
		}
		// Same core written with / without spaces in a different order of joining («کود دهی» / «کوددهی»).
		$cmp = $kw['cmp'];
		if ( isset( $by_cmp[ $cmp ] ) ) {
			qtb_uf_union( $parent, $by_cmp[ $cmp ], $i );
		} else {
			$by_cmp[ $cmp ] = $i;
		}
	}
	$groups = qtb_groups_from_uf( $keywords, $parent );
	return qtb_merge_longtail( $keywords, $groups );
}

/**
 * Union-find → groups [{kws, head, ck, core, vol}].
 */
function qtb_groups_from_uf( $keywords, $parent ) {
	$sets = array();
	foreach ( $keywords as $kw ) {
		$sets[ qtb_uf_find( $parent, $kw['i'] ) ][] = $kw['i'];
	}
	$out = array();
	foreach ( $sets as $members ) {
		$out[] = qtb_group_make( $keywords, $members );
	}
	return $out;
}

/**
 * Build a group record from its members.
 */
function qtb_group_make( $keywords, $members ) {
	usort( $members, function ( $a, $b ) use ( $keywords ) {
		return ( $keywords[ $b ]['v'] <=> $keywords[ $a ]['v'] ) ?: ( mb_strlen( $keywords[ $a ]['k'] ) <=> mb_strlen( $keywords[ $b ]['k'] ) );
	} );
	$vol = 0;
	foreach ( $members as $m ) {
		$vol += $keywords[ $m ]['v'];
	}
	$head = $members[0];
	// The group's core: the shortest core among its members (the parent topic).
	$core = $keywords[ $head ]['core'];
	foreach ( $members as $m ) {
		if ( count( $keywords[ $m ]['core'] ) < count( $core ) ) {
			$core = $keywords[ $m ]['core'];
		}
	}
	return array( 'kws' => array_values( $members ), 'head' => $head, 'core' => $core, 'ck' => implode( ' ', $core ), 'vol' => $vol );
}

/**
 * Join long-tail groups into their parent group (see file header).
 */
function qtb_merge_longtail( $keywords, $groups ) {
	$info = qtb_lex_set( 'informational' );
	// Facet frequency: in how many groups does a token appear?
	$gdf = array();
	foreach ( $groups as $g ) {
		foreach ( $g['core'] as $t ) {
			$gdf[ $t ] = ( $gdf[ $t ] ?? 0 ) + 1;
		}
	}
	$by_ck = array();
	foreach ( $groups as $gi => $g ) {
		$by_ck[ $g['ck'] ] = $gi;
	}
	$order = array_keys( $groups );
	usort( $order, function ( $a, $b ) use ( $groups ) {
		return count( $groups[ $b ]['core'] ) <=> count( $groups[ $a ]['core'] );
	} );
	$parent = range( 0, max( 0, count( $groups ) - 1 ) );
	foreach ( $order as $gi ) {
		$g    = $groups[ $gi ];
		$core = $g['core'];
		if ( count( $core ) < 2 ) {
			continue;
		}
		$best = null;
		foreach ( array( 1, 2 ) as $drop ) {
			if ( count( $core ) - $drop < 1 ) {
				break;
			}
			foreach ( qtb_combinations( $core, count( $core ) - $drop ) as $sub ) {
				$ck = implode( ' ', $sub );
				if ( ! isset( $by_ck[ $ck ] ) || $by_ck[ $ck ] === $gi ) {
					continue;
				}
				$pi    = $by_ck[ $ck ];
				$extra = array_values( array_diff( $core, $sub ) );
				$p     = $groups[ $pi ];
				$p_info = (bool) array_intersect( $p['core'], array_keys( $info ) ) || 'i' === $keywords[ $p['head'] ]['intent0'];
				$rare   = true;
				foreach ( $extra as $t ) {
					if ( ( $gdf[ $t ] ?? 0 ) >= 3 || isset( $info[ $t ] ) ) {
						$rare = false;
					}
				}
				// Informational parent: long-tail questions join unless they add a new topic word.
				$ok = ( $p_info && ( $rare || 1 === count( $extra ) && ( $gdf[ $extra[0] ] ?? 0 ) < 6 ) && ! array_intersect( $extra, array_keys( $info ) ) )
					|| ( ! $p_info && $rare && count( $p['core'] ) >= 2 && $g['vol'] <= max( 30, $p['vol'] * 0.15 ) );
				if ( $ok && ( null === $best || count( $sub ) > $best[1] || ( count( $sub ) === $best[1] && $p['vol'] > $groups[ $best[0] ]['vol'] ) ) ) {
					$best = array( $pi, count( $sub ) );
				}
			}
			if ( $best ) {
				break;
			}
		}
		if ( $best ) {
			qtb_uf_union( $parent, $best[0], $gi );
		}
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
		$out[] = qtb_group_make( $keywords, $members );
	}
	return $out;
}

/**
 * All k-combinations of a (small) list, preserving order.
 */
function qtb_combinations( $list, $k ) {
	$list = array_values( $list );
	$n    = count( $list );
	if ( $k <= 0 || $k > $n ) {
		return array();
	}
	if ( $n > 8 ) {
		return array(); // Very long queries: not worth the combinations.
	}
	$out = array();
	$idx = range( 0, $k - 1 );
	while ( true ) {
		$out[] = array_map( function ( $i ) use ( $list ) {
			return $list[ $i ];
		}, $idx );
		$i = $k - 1;
		while ( $i >= 0 && $idx[ $i ] === $n - $k + $i ) {
			--$i;
		}
		if ( $i < 0 ) {
			break;
		}
		++$idx[ $i ];
		for ( $j = $i + 1; $j < $k; $j++ ) {
			$idx[ $j ] = $idx[ $j - 1 ] + 1;
		}
	}
	return $out;
}
