<?php
/**
 * Competitors from Seo Signal exports (the JSON of a domain's ranking keywords):
 * up to five domains per project. They feed the competitor tab (market share, page
 * types that win, keywords we lack), the clustering (two keywords a competitor ranks
 * with the same page belong together), the page type, the AI memory and the overview.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_COMP_MAX = 5;

/**
 * Expected click-through rate of a Google position (organic, mobile-heavy Iranian SERPs).
 */
function qtb_ctr_at( $rank ) {
	$t = array( 1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018 );
	return $t[ (int) $rank ] ?? ( $rank <= 20 ? 0.006 : 0.002 );
}

/**
 * Seo Signal JSON → competitor record.
 *
 * @return array|WP_Error
 */
function qtb_comp_parse( $raw ) {
	$raw = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $raw );
	$j   = json_decode( $raw, true );
	if ( ! is_array( $j ) ) {
		return new WP_Error( 'qtb_comp', 'فایل JSON خوانده نشد.' );
	}
	$d = isset( $j['data'] ) && is_array( $j['data'] ) ? $j['data'] : $j;
	if ( empty( $d['Keywords'] ) || ! is_array( $d['Keywords'] ) ) {
		return new WP_Error( 'qtb_comp', 'این فایل خروجی کلمات دامنه از سئو سیگنال نیست (بخش Keywords پیدا نشد).' );
	}
	$domain = strtolower( (string) ( $d['domain'] ?? ( $d['Keywords'][0]['DomainName'] ?? '' ) ) );
	$domain = preg_replace( '/^www\./', '', $domain );
	$kws    = array();
	foreach ( $d['Keywords'] as $k ) {
		$w = trim( (string) ( $k['Word'] ?? '' ) );
		if ( '' === $w ) {
			continue;
		}
		$kws[] = array( qtb_display( $w ), (int) ( $k['RankId'] ?? 0 ), (int) ( $k['SearchVolume'] ?? 0 ), rawurldecode( (string) ( $k['Page'] ?? '' ) ) );
	}
	$pages = array();
	foreach ( (array) ( $d['Pages'] ?? array() ) as $pg ) {
		$pages[] = array( rawurldecode( (string) ( $pg['Page'] ?? '' ) ), (float) ( $pg['Avg'] ?? 0 ), (int) ( $pg['Count'] ?? 0 ) );
	}
	$share = array();
	$names = (array) ( $d['domainCTR']['Domain'] ?? array() );
	foreach ( $names as $n => $label ) {
		$share[] = array( trim( preg_replace( '/\s*\(.*\)$/u', '', (string) $label ) ), (int) ( $d['domainCTR']['CTR'][ $n ] ?? 0 ) );
	}
	return array(
		'domain'  => $domain,
		'url'     => (string) ( $d['url'] ?? 'https://' . $domain ),
		'added'   => qtb_now(),
		'kcount'  => (int) ( $d['keywordCount'] ?? count( $kws ) ),
		'pcount'  => (int) ( $d['pageCount'] ?? count( $pages ) ),
		'avg'     => (float) ( $d['avgRanks'] ?? 0 ),
		'share'   => $share,
		'rel'     => array_values( array_map( 'strval', (array) ( $d['RelDomains'] ?? array() ) ) ),
		'kws'     => $kws,
		'pages'   => $pages,
		'ai'      => null,
	);
}

/**
 * Competitors of a project.
 */
function qtb_comps( $pid ) {
	return (array) qtb_data_get( $pid, 'comps', array() );
}

/**
 * Save the competitors and rebuild the keyword index.
 */
function qtb_comps_put( $pid, $comps ) {
	qtb_data_put( $pid, 'comps', array_values( $comps ) );
	qtb_data_put( $pid, 'comp_index', qtb_comp_build_index( array_values( $comps ) ) );
}

/**
 * Add (or replace, same domain) a competitor.
 *
 * @return array|WP_Error the record
 */
function qtb_comp_add( $pid, $raw ) {
	$rec = qtb_comp_parse( $raw );
	if ( is_wp_error( $rec ) ) {
		return $rec;
	}
	$comps = qtb_comps( $pid );
	foreach ( $comps as $n => $c ) {
		if ( $c['domain'] === $rec['domain'] ) {
			$comps[ $n ] = $rec;
			qtb_comps_put( $pid, $comps );
			return $rec;
		}
	}
	if ( count( $comps ) >= QTB_COMP_MAX ) {
		return new WP_Error( 'qtb_comp', sprintf( 'حداکثر %s رقیب برای هر پروژه؛ اول یکی را حذف کنید.', qtb_num( QTB_COMP_MAX ) ) );
	}
	$comps[] = $rec;
	qtb_comps_put( $pid, $comps );
	return $rec;
}

/**
 * keyword key → [[competitor index, rank, page]] (top 20 only).
 */
function qtb_comp_build_index( $comps ) {
	$idx = array();
	foreach ( $comps as $ci => $c ) {
		foreach ( $c['kws'] as $k ) {
			if ( $k[1] < 1 || $k[1] > 20 ) {
				continue;
			}
			$idx[ str_replace( ' ', '', qtb_norm_key( $k[0] ) ) ][] = array( $ci, $k[1], $k[3] );
		}
	}
	return $idx;
}

/**
 * Hits of one keyword record in the index (its spelling variants too).
 */
function qtb_comp_hits( $idx, $kw ) {
	$out = (array) ( $idx[ $kw['key'] ] ?? array() );
	foreach ( (array) ( $kw['alts'] ?? array() ) as $a ) {
		$out = array_merge( $out, (array) ( $idx[ str_replace( ' ', '', qtb_norm_key( $a ) ) ] ?? array() ) );
	}
	return $out;
}

/**
 * Page type of a competitor URL: product|brand|tag|category|article|home|other.
 */
function qtb_comp_url_type( $path ) {
	$p    = trim( strtolower( (string) wp_parse_url( (string) $path, PHP_URL_PATH ) ), '/' );
	$segs = '' === $p ? array() : explode( '/', $p );
	if ( ! $segs ) {
		return 'home';
	}
	$s = $segs[0];
	if ( preg_match( '/^(product-tag|product_tag|tag|tags|برچسب)$/u', $s ) ) {
		return 'tag';
	}
	if ( preg_match( '/^(brand|brands|pa_brand|product-brand|برند|برندها)$/u', $s ) ) {
		return 'brand';
	}
	if ( preg_match( '/^(product-category|product_cat|category|categories|cat|c|دسته|دسته-بندی|search|s)$/u', $s ) ) {
		return 'category';
	}
	if ( preg_match( '/^(product|products|p|item|محصول)$/u', $s ) || preg_match( '/^dkp-\d+/', $s ) ) {
		return 'product';
	}
	if ( preg_match( '/^(shop|store|فروشگاه)$/u', $s ) ) {
		return count( $segs ) > 1 ? 'product' : 'category';
	}
	if ( preg_match( '/^(blog|mag|magazine|article|articles|news|weblog|learn|guide|wiki|مقاله|مقالات|مجله|بلاگ|وبلاگ)$/u', $s ) ) {
		return 'article';
	}
	$g = qtb_url_type_guess( '/' . $p . '/' );
	return 'post' === $g ? 'article' : ( 'product' === $g ? 'product' : 'other' );
}

/**
 * Labels of competitor page types.
 */
function qtb_comp_type_labels() {
	return array( 'product' => 'محصول', 'brand' => 'برند', 'tag' => 'برچسب', 'category' => 'دسته', 'article' => 'مقاله', 'home' => 'صفحه اصلی', 'other' => 'سایر صفحات' );
}

/**
 * Figures of one competitor: traffic, positions, page types, best pages, beatable keywords.
 */
function qtb_comp_stats( $c ) {
	$st    = array( 'traffic' => 0, 'top3' => 0, 'top10' => 0, 'types' => array(), 'types_traffic' => array(), 'pages' => array(), 'beatable' => array(), 'top' => array() );
	$brand = qtb_comp_brand_tokens( $c );
	foreach ( $c['kws'] as $k ) {
		list( $w, $rank, $vol, $page ) = $k;
		$tr = $vol * qtb_ctr_at( $rank );
		$ty = qtb_comp_url_type( $page );
		$st['traffic']                 += $tr;
		$st['top3']                    += $rank >= 1 && $rank <= 3 ? 1 : 0;
		$st['top10']                   += $rank >= 1 && $rank <= 10 ? 1 : 0;
		$st['types'][ $ty ]             = ( $st['types'][ $ty ] ?? 0 ) + 1;
		$st['types_traffic'][ $ty ]     = ( $st['types_traffic'][ $ty ] ?? 0 ) + $tr;
		if ( ! isset( $st['pages'][ $page ] ) ) {
			$st['pages'][ $page ] = array( 'page' => $page, 'type' => $ty, 'traffic' => 0, 'n' => 0, 'kws' => array() );
		}
		$st['pages'][ $page ]['traffic'] += $tr;
		$st['pages'][ $page ]['n']++;
		if ( count( $st['pages'][ $page ]['kws'] ) < 5 ) {
			$st['pages'][ $page ]['kws'][] = $w;
		}
		if ( $rank >= 4 && $rank <= 10 && $vol >= 50 && ! array_intersect_key( array_flip( explode( ' ', qtb_norm_key( $w ) ) ), $brand ) ) {
			$st['beatable'][] = array( 'k' => $w, 'rank' => $rank, 'v' => $vol, 'page' => $page );
		}
	}
	usort( $st['pages'], function ( $a, $b ) {
		return $b['traffic'] <=> $a['traffic'];
	} );
	$st['pages'] = array_slice( $st['pages'], 0, 25 );
	usort( $st['beatable'], function ( $a, $b ) {
		return $b['v'] <=> $a['v'];
	} );
	$st['beatable'] = array_slice( $st['beatable'], 0, 40 );
	arsort( $st['types'] );
	arsort( $st['types_traffic'] );
	$top = $c['kws'];
	usort( $top, function ( $a, $b ) {
		return $b[2] * qtb_ctr_at( $b[1] ) <=> $a[2] * qtb_ctr_at( $a[1] );
	} );
	$st['top']     = array_slice( $top, 0, 30 );
	$st['traffic'] = (int) round( $st['traffic'] );
	return $st;
}

/**
 * Words of a competitor's own brand: in the searches its home page ranks for, and
 * (almost) never in its other keywords — «لیلیوم» in «سایت لیلیوم», not «عطر».
 */
function qtb_comp_brand_tokens( $c ) {
	$home  = array();
	$other = array();
	$n     = 0;
	foreach ( $c['kws'] as $k ) {
		$toks = array_unique( explode( ' ', qtb_norm_key( $k[0] ) ) );
		if ( 'home' === qtb_comp_url_type( $k[3] ) ) {
			foreach ( $toks as $t ) {
				$home[ $t ] = 1;
			}
		} else {
			++$n;
			foreach ( $toks as $t ) {
				$other[ $t ] = ( $other[ $t ] ?? 0 ) + 1;
			}
		}
	}
	$generic = qtb_lex_set( 'soft' ) + qtb_lex_set( 'stop' ) + qtb_lex_set( 'transactional' ) + qtb_lex_set( 'commercial' ) + array_flip( array( 'سایت', 'وبسایت', 'فروشگاه', 'فروشگاهی', 'اصلی', 'اورجینال', 'اصل', 'انلاین', 'آنلاین', 'رسمی', 'shop', 'store', 'online', 'perfume', 'پرفیوم' ) );
	$out     = array();
	foreach ( array_keys( $home ) as $t ) {
		if ( isset( $generic[ $t ] ) ) {
			continue;
		}
		if ( mb_strlen( $t ) > 2 && ( $other[ $t ] ?? 0 ) <= max( 1, 0.01 * $n ) ) {
			$out[ $t ] = 1;
		}
	}
	foreach ( explode( '.', preg_replace( '/\.(com|ir|net|org|co|shop|store|online)$/', '', $c['domain'] ) ) as $t ) {
		if ( mb_strlen( $t ) > 2 ) {
			$out[ $t ] = 1;
		}
	}
	return $out;
}

/**
 * Keywords the competitors rank for (top 10) that the project does not have.
 *
 * @return array [{k, v, n (competitors), best, hits: [[domain, rank, page]], type}]
 */
function qtb_comp_gap( $pid, $min_vol = 0 ) {
	$comps = qtb_comps( $pid );
	$have  = array();
	foreach ( (array) qtb_data_get( $pid, 'keywords', array() ) as $kw ) {
		$have[ $kw['key'] ] = 1;
		foreach ( (array) ( $kw['alts'] ?? array() ) as $a ) {
			$have[ str_replace( ' ', '', qtb_norm_key( $a ) ) ] = 1;
		}
	}
	foreach ( (array) qtb_data_get( $pid, 'removed', array() ) as $r ) {
		$have[ str_replace( ' ', '', qtb_norm_key( $r['k'] ) ) ] = 1;
	}
	$gap   = array();
	$brand = array();
	foreach ( $comps as $c ) {
		$brand += qtb_comp_brand_tokens( $c );
	}
	foreach ( $comps as $c ) {
		foreach ( $c['kws'] as $k ) {
			if ( $k[1] < 1 || $k[1] > 10 || $k[2] < $min_vol ) {
				continue;
			}
			$nk  = qtb_norm_key( $k[0] );
			$key = str_replace( ' ', '', $nk );
			if ( '' === $key || isset( $have[ $key ] ) || array_intersect_key( array_flip( explode( ' ', $nk ) ), $brand ) ) {
				continue;
			}
			if ( ! isset( $gap[ $key ] ) ) {
				$gap[ $key ] = array( 'k' => $k[0], 'v' => 0, 'n' => 0, 'best' => 99, 'hits' => array(), 'types' => array() );
			}
			$g             = &$gap[ $key ];
			$g['v']        = max( $g['v'], $k[2] );
			$g['n']++;
			$g['best']     = min( $g['best'], $k[1] );
			$g['hits'][]   = array( $c['domain'], $k[1], $k[3] );
			$ty            = qtb_comp_url_type( $k[3] );
			$g['types'][ $ty ] = ( $g['types'][ $ty ] ?? 0 ) + 1;
			unset( $g );
		}
	}
	foreach ( $gap as $key => &$g ) {
		arsort( $g['types'] );
		$g['type'] = (string) key( $g['types'] );
		// Searches for a competitor's own name (its home page ranks): navigational, not ours.
		if ( 'home' === $g['type'] && 1 === count( $g['types'] ) ) {
			unset( $gap[ $key ] );
		}
	}
	unset( $g );
	uasort( $gap, function ( $a, $b ) {
		return ( $b['n'] <=> $a['n'] ) ?: ( $b['v'] <=> $a['v'] );
	} );
	return array_values( $gap );
}

/**
 * Share of each competitor in the project's own keywords (top 10 positions).
 *
 * @return array domain → {n, vol, traffic}
 */
function qtb_comp_coverage( $pid ) {
	$comps = qtb_comps( $pid );
	$idx   = qtb_data_get( $pid, 'comp_index', array() );
	$out   = array();
	foreach ( $comps as $c ) {
		$out[ $c['domain'] ] = array( 'n' => 0, 'vol' => 0, 'traffic' => 0 );
	}
	$total = array( 'n' => 0, 'vol' => 0 );
	foreach ( (array) qtb_data_get( $pid, 'keywords', array() ) as $kw ) {
		if ( ! in_array( $kw['ws'] ?? 'keep', array( 'keep', 'unsure' ), true ) ) {
			continue;
		}
		++$total['n'];
		$total['vol'] += (int) $kw['v'];
		$seen          = array();
		foreach ( qtb_comp_hits( $idx, $kw ) as $h ) {
			$d = $comps[ $h[0] ]['domain'] ?? '';
			if ( '' === $d || $h[1] > 10 || isset( $seen[ $d ] ) ) {
				continue;
			}
			$seen[ $d ] = 1;
			$out[ $d ]['n']++;
			$out[ $d ]['vol']     += (int) $kw['v'];
			$out[ $d ]['traffic'] += $kw['v'] * qtb_ctr_at( $h[1] );
		}
	}
	return array( 'by' => $out, 'total' => $total );
}

/**
 * Competitor evidence for clustering: groups whose keywords a competitor ranks with
 * the same page (top 10) are merged — unless an intent-shifting word (brand, city,
 * service, comparison) differs, or Google's own results clearly disagree.
 *
 * @param array $kws    Keywords of the topic (index → record).
 * @param array $groups Groups [{kws, meta, seed?}].
 * @return array groups
 */
function qtb_comp_merge_groups( $project, $kws, $groups, $serps = array(), $need = 4 ) {
	$idx   = qtb_data_get( $project['id'], 'comp_index', array() );
	$comps = qtb_comps( $project['id'] );
	if ( ! $idx || count( $groups ) < 2 ) {
		return $groups;
	}
	$shift = qtb_shift_words( $kws, array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) );
	$sig   = function ( $g ) use ( $kws, $shift ) {
		$s = array();
		foreach ( explode( ' ', $kws[ $g['kws'][0] ]['nk'] ?? '' ) as $t ) {
			if ( isset( $shift[ $t ] ) ) {
				$s[] = $t;
			}
		}
		sort( $s );
		return implode( ' ', $s );
	};
	$page_of = array(); // "ci|page" → [group index].
	foreach ( $groups as $gi => $g ) {
		foreach ( $g['kws'] as $m ) {
			if ( ! isset( $kws[ $m ] ) ) {
				continue;
			}
			foreach ( qtb_comp_hits( $idx, $kws[ $m ] ) as $h ) {
				if ( $h[1] <= 10 && '' !== $h[2] && 'home' !== qtb_comp_url_type( $h[2] ) ) {
					$page_of[ $h[0] . '|' . $h[2] ][ $gi ] = $m;
				}
			}
		}
	}
	$parent = array_combine( array_keys( $groups ), array_keys( $groups ) );
	$why    = array();
	foreach ( $page_of as $pk => $gs ) {
		if ( count( $gs ) < 2 ) {
			continue;
		}
		$list = array_keys( $gs );
		$a    = $list[0];
		foreach ( array_slice( $list, 1 ) as $b ) {
			$ra = qtb_uf_find( $parent, $a );
			$rb = qtb_uf_find( $parent, $b );
			if ( $ra === $rb || ( ! empty( $groups[ $ra ]['seed'] ) && ! empty( $groups[ $rb ]['seed'] ) ) ) {
				continue;
			}
			if ( $sig( $groups[ $a ] ) !== $sig( $groups[ $b ] ) ) {
				continue;
			}
			$ha = $groups[ $a ]['kws'][0];
			$hb = $groups[ $b ]['kws'][0];
			if ( isset( $serps[ $ha ], $serps[ $hb ] ) && count( qtb_serp_shared_urls( $serps[ $ha ], $serps[ $hb ] ) ) < max( 1, $need - 2 ) ) {
				continue;
			}
			// The seed (a locked cluster) keeps its identity.
			if ( ! empty( $groups[ $rb ]['seed'] ) ) {
				list( $ra, $rb ) = array( $rb, $ra );
			}
			$parent[ $rb ] = $ra;
			list( $ci, $page ) = explode( '|', $pk, 2 );
			$why[ $rb ]        = ( $comps[ $ci ]['domain'] ?? '' ) . ' ' . $page;
		}
	}
	$out = array();
	foreach ( $groups as $gi => $g ) {
		$r = qtb_uf_find( $parent, $gi );
		if ( ! isset( $out[ $r ] ) ) {
			$out[ $r ] = $groups[ $r ];
		}
		if ( $r !== $gi ) {
			$out[ $r ]['kws'] = array_merge( $out[ $r ]['kws'], $g['kws'] );
			foreach ( $g['kws'] as $m ) {
				$out[ $r ]['meta'][ $m ] = array( 'conf' => 'mid', 'shared' => -1, 'alt' => null, 'alt_shared' => 0, 'why' => 'comp', 'comp' => $why[ $gi ] ?? ( $why[ qtb_uf_find( $parent, $gi ) ] ?? '' ) );
			}
		}
	}
	foreach ( $out as &$g ) {
		$g['kws'] = array_values( array_unique( $g['kws'] ) );
		usort( $g['kws'], function ( $a, $b ) use ( $kws ) {
			return ( $kws[ $b ]['v'] ?? 0 ) <=> ( $kws[ $a ]['v'] ?? 0 );
		} );
		$g['vol']  = array_sum( array_map( function ( $m ) use ( $kws ) {
			return (int) ( $kws[ $m ]['v'] ?? 0 );
		}, $g['kws'] ) );
		$g['head'] = $g['kws'][0];
	}
	unset( $g );
	return array_values( $out );
}

/**
 * Page type the competitors use for a cluster: {type, domain, rank, page, n} or null.
 */
function qtb_comp_page_signal( $project, $kws, $c ) {
	$idx = qtb_data_get( $project['id'], 'comp_index', array() );
	if ( ! $idx ) {
		return null;
	}
	$comps = qtb_comps( $project['id'] );
	$votes = array();
	$best  = null;
	foreach ( array_slice( $c['kws'], 0, 5 ) as $j => $m ) {
		if ( ! isset( $kws[ $m ] ) ) {
			continue;
		}
		foreach ( qtb_comp_hits( $idx, $kws[ $m ] ) as $h ) {
			if ( $h[1] > 10 ) {
				continue;
			}
			$ty = qtb_comp_url_type( $h[2] );
			if ( in_array( $ty, array( 'home', 'other' ), true ) ) {
				continue;
			}
			$votes[ $ty ] = ( $votes[ $ty ] ?? 0 ) + ( 0 === $j ? 3 : 1 ) * ( 11 - $h[1] );
			if ( ! $best || $h[1] < $best['rank'] ) {
				$best = array( 'domain' => $comps[ $h[0] ]['domain'] ?? '', 'rank' => $h[1], 'page' => $h[2] );
			}
		}
	}
	if ( ! $votes ) {
		return null;
	}
	arsort( $votes );
	return array_merge( $best, array( 'type' => (string) key( $votes ), 'n' => count( $votes ) ) );
}

/**
 * Evidence of a keyword: where each competitor ranks it, and whether that page also ranks the head.
 */
function qtb_comp_evidence( $project, $kws, $i, $head = null ) {
	$idx   = qtb_data_get( $project['id'], 'comp_index', array() );
	$comps = qtb_comps( $project['id'] );
	if ( ! $idx || ! isset( $kws[ $i ] ) ) {
		return array();
	}
	$head_pages = array();
	if ( null !== $head && isset( $kws[ $head ] ) ) {
		foreach ( qtb_comp_hits( $idx, $kws[ $head ] ) as $h ) {
			$head_pages[ $h[0] . '|' . $h[2] ] = 1;
		}
	}
	$out = array();
	foreach ( qtb_comp_hits( $idx, $kws[ $i ] ) as $h ) {
		$out[] = array( 'domain' => $comps[ $h[0] ]['domain'] ?? '', 'rank' => $h[1], 'page' => $h[2], 'type' => qtb_comp_type_labels()[ qtb_comp_url_type( $h[2] ) ] ?? '', 'same' => isset( $head_pages[ $h[0] . '|' . $h[2] ] ) );
	}
	usort( $out, function ( $a, $b ) {
		return $a['rank'] <=> $b['rank'];
	} );
	return $out;
}

/**
 * Lines about the competitors for the AI memory.
 */
function qtb_comp_memory( $pid ) {
	$lines = array();
	foreach ( qtb_comps( $pid ) as $c ) {
		$st    = qtb_comp_stats( $c );
		$types = array();
		foreach ( array_slice( $st['types_traffic'], 0, 4, true ) as $ty => $tr ) {
			$types[] = ( qtb_comp_type_labels()[ $ty ] ?? $ty ) . ' ' . qtb_num( round( 100 * $tr / max( 1, $st['traffic'] ) ) ) . '٪';
		}
		$line = '- ' . $c['domain'] . ': ' . qtb_num( count( $c['kws'] ) ) . ' کلمه، ترافیک تخمینی ' . qtb_num( $st['traffic'] ) . '؛ ترافیک از صفحات: ' . implode( '، ', $types );
		if ( ! empty( $c['ai']['structure'] ) ) {
			$line .= '. ' . qtb_mb_cut( $c['ai']['structure'], 220 );
		}
		$lines[] = $line;
	}
	return $lines;
}

/**
 * AI analysis of one competitor.
 *
 * @return array|WP_Error {summary, strengths[], weaknesses[], structure, top_pages[{page, why}], opportunities[{target, action}], actions[]}
 */
function qtb_ai_comp_report( $project, $c ) {
	$st  = qtb_comp_stats( $c );
	$lab = qtb_comp_type_labels();
	$ty  = array();
	foreach ( $st['types'] as $k => $n ) {
		$ty[] = ( $lab[ $k ] ?? $k ) . ': ' . $n . ' کلمه، ' . round( 100 * ( $st['types_traffic'][ $k ] ?? 0 ) / max( 1, $st['traffic'] ) ) . '٪ ترافیک';
	}
	$top = array();
	foreach ( $st['top'] as $k ) {
		$top[] = $k[0] . ' | جایگاه ' . $k[1] . ' | حجم ' . $k[2] . ' | ' . $k[3];
	}
	$pages = array();
	foreach ( array_slice( $st['pages'], 0, 15 ) as $pg ) {
		$pages[] = $pg['page'] . ' (' . ( $lab[ $pg['type'] ] ?? '' ) . '، ' . $pg['n'] . ' کلمه، ترافیک ' . round( $pg['traffic'] ) . '): ' . implode( '، ', $pg['kws'] );
	}
	$beat = array();
	foreach ( array_slice( $st['beatable'], 0, 25 ) as $b ) {
		$beat[] = $b['k'] . ' | جایگاه ' . $b['rank'] . ' | حجم ' . $b['v'];
	}
	$share = array();
	foreach ( $c['share'] as $s ) {
		$share[] = $s[0] . ' ' . $s[1] . '٪';
	}
	$ws     = qtb_ws_get( $project['id'] );
	$system = 'تو استراتژیست ارشد سئو در بازار ایران هستی و یک رقیب را از روی کلمات و صفحاتی که در گوگل رتبه دارد تحلیل می‌کنی تا کسب‌وکار ما بداند کجا و با چه صفحه‌ای از او جلو بزند. دقیق، عددی و عملی بنویس؛ کلی‌گویی نکن.';
	$user   = qtb_ws_memory_text( $project, $ws ) .
		"\n## رقیب: " . $c['domain'] . "\n" .
		sprintf( "%s کلمه رتبه‌دار، %s صفحه، میانگین جایگاه %s، %s کلمه در ۳ نتیجه اول، ترافیک تخمینی ماهانه %s\n", count( $c['kws'] ), $c['pcount'], $c['avg'], $st['top3'], $st['traffic'] ) .
		'سهم دامنه‌ها از کلیک این بازار: ' . implode( '، ', $share ) . "\n" .
		"### نوع صفحات رتبه‌دار\n" . implode( "\n", $ty ) .
		"\n### پرترافیک‌ترین کلمات\n" . implode( "\n", $top ) .
		"\n### پرترافیک‌ترین صفحات\n" . implode( "\n", $pages ) .
		"\n### کلمات پرحجمی که رقیب در جایگاه ۴ تا ۱۰ است (قابل سبقت)\n" . implode( "\n", $beat ) .
		"\n\n## کار\nفارسی و مشخص بده: summary (۲ تا ۳ جمله)، strengths (۳ تا ۵ مورد)، weaknesses (۳ تا ۵)، structure (یک جمله: معماری سایت رقیب و نوع صفحه‌ای که در این بازار رتبه می‌گیرد؛ این جمله در خوشه‌بندی ما استفاده می‌شود)، top_pages (۵ صفحه و چرا موفق‌اند)، opportunities (۵ تا ۸ فرصت مشخص برای ما: target یک کلمه یا موضوع، action اقدام دقیق)، actions (۳ تا ۵ اقدام اولویت‌دار).";
	$arr    = array( 'type' => 'array', 'items' => array( 'type' => 'string' ) );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'summary', 'strengths', 'weaknesses', 'structure', 'top_pages', 'opportunities', 'actions' ),
		'properties'           => array(
			'summary'       => array( 'type' => 'string' ),
			'strengths'     => $arr,
			'weaknesses'    => $arr,
			'structure'     => array( 'type' => 'string' ),
			'top_pages'     => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'page', 'why' ),
					'properties'           => array( 'page' => array( 'type' => 'string' ), 'why' => array( 'type' => 'string' ) ),
				),
			),
			'opportunities' => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'target', 'action' ),
					'properties'           => array( 'target' => array( 'type' => 'string' ), 'action' => array( 'type' => 'string' ) ),
				),
			),
			'actions'       => $arr,
		),
	);
	return qtb_ai_request( $system, $user, $schema, array( 'purpose' => 'analysis', 'max_tokens' => 5000, 'timeout' => 200 ) );
}
