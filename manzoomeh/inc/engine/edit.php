<?php
/**
 * Manual edits of the result (the SEO manager has the last word): move a
 * keyword, merge clusters, rename, change page type / section / pillar,
 * remove a keyword or bring a removed one back. Cluster ids stay stable;
 * totals, pillars and scores are recomputed after every edit.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply one edit.
 *
 * @param array $e {op, ...}
 * @return true|WP_Error
 */
function mzm_edit_apply( $project, $e ) {
	$pid      = $project['id'];
	$kws      = mzm_data_get( $pid, 'keywords', array() );
	$clusters = mzm_data_get( $pid, 'clusters', array() );
	$removed  = mzm_data_get( $pid, 'removed', array() );
	if ( ! $clusters ) {
		return new WP_Error( 'mzm_no_result', 'هنوز خوشه‌بندی اجرا نشده است.' );
	}
	$by_id = array();
	foreach ( $clusters as $ci => $c ) {
		$by_id[ $c['id'] ] = $ci;
	}
	$cl = function ( $id ) use ( $by_id ) {
		return $by_id[ (int) $id ] ?? null;
	};
	$op = (string) ( $e['op'] ?? '' );
	switch ( $op ) {
		case 'move': // {kw, to} — to = cluster id or 'new'.
			$m    = (int) $e['kw'];
			$from = null;
			foreach ( $clusters as $ci => $c ) {
				if ( in_array( $m, $c['kws'], true ) ) {
					$from = $ci;
				}
			}
			if ( null === $from || ! isset( $kws[ $m ] ) ) {
				return new WP_Error( 'mzm_edit', 'کلمه پیدا نشد.' );
			}
			$clusters[ $from ]['kws'] = array_values( array_diff( $clusters[ $from ]['kws'], array( $m ) ) );
			if ( 'new' === (string) $e['to'] ) {
				$src        = $clusters[ $from ];
				$clusters[] = mzm_edit_new_cluster( $clusters, $kws, array( $m ), $src );
			} else {
				$to = $cl( $e['to'] );
				if ( null === $to ) {
					return new WP_Error( 'mzm_edit', 'کلاستر مقصد پیدا نشد.' );
				}
				$clusters[ $to ]['kws'][] = $m;
			}
			break;
		case 'merge': // {ids: []} into the first.
			$ids  = array_values( array_unique( array_map( 'intval', (array) $e['ids'] ) ) );
			$keep = $cl( $ids[0] ?? -1 );
			if ( null === $keep || count( $ids ) < 2 ) {
				return new WP_Error( 'mzm_edit', 'حداقل دو کلاستر برای ادغام انتخاب کنید.' );
			}
			foreach ( array_slice( $ids, 1 ) as $id ) {
				$ci = $cl( $id );
				if ( null !== $ci && $ci !== $keep ) {
					$clusters[ $keep ]['kws'] = array_merge( $clusters[ $keep ]['kws'], $clusters[ $ci ]['kws'] );
					$clusters[ $ci ]['kws']   = array();
				}
			}
			break;
		case 'rename': // {id, name}
			$ci = $cl( $e['id'] );
			if ( null === $ci ) {
				return new WP_Error( 'mzm_edit', 'کلاستر پیدا نشد.' );
			}
			$clusters[ $ci ]['name']        = sanitize_text_field( (string) $e['name'] );
			$clusters[ $ci ]['locked_name'] = 1;
			break;
		case 'set': // {id, page?, section?, pillar?}
			$ci = $cl( $e['id'] );
			if ( null === $ci ) {
				return new WP_Error( 'mzm_edit', 'کلاستر پیدا نشد.' );
			}
			if ( isset( $e['page'] ) && isset( mzm_page_types()[ $e['page'] ] ) ) {
				$clusters[ $ci ]['page']        = $e['page'];
				$clusters[ $ci ]['locked_page'] = 1;
			}
			if ( isset( $e['section'] ) && '' !== trim( (string) $e['section'] ) ) {
				$clusters[ $ci ]['section']        = sanitize_text_field( (string) $e['section'] );
				$clusters[ $ci ]['locked_section'] = 1;
			}
			if ( isset( $e['pillar'] ) && '' !== trim( (string) $e['pillar'] ) ) {
				$clusters[ $ci ]['pillar_name'] = sanitize_text_field( (string) $e['pillar'] );
			}
			break;
		case 'remove': // {kw}
			$m = (int) $e['kw'];
			foreach ( $clusters as &$c ) {
				if ( in_array( $m, $c['kws'], true ) ) {
					$c['kws']  = array_values( array_diff( $c['kws'], array( $m ) ) );
					$removed[] = array( 'k' => $kws[ $m ]['k'], 'v' => $kws[ $m ]['v'], 'r' => 'manual', 'd' => '', 'kw' => $m );
				}
			}
			unset( $c );
			break;
		case 'restore': // {idx: index in removed, to: cluster id|'new'}
			$ri = (int) $e['idx'];
			if ( ! isset( $removed[ $ri ] ) ) {
				return new WP_Error( 'mzm_edit', 'کلمه حذف‌شده پیدا نشد.' );
			}
			$r = $removed[ $ri ];
			array_splice( $removed, $ri, 1 );
			if ( isset( $r['kw'] ) && isset( $kws[ $r['kw'] ] ) ) {
				$m = (int) $r['kw'];
			} else {
				$m       = count( $kws );
				$nk      = mzm_norm_key( $r['k'] );
				$toks    = explode( ' ', $nk );
				$soft    = mzm_lex_set( 'soft' );
				$core    = array_values( array_filter( $toks, function ( $t ) use ( $soft ) {
					return ! isset( $soft[ $t ] );
				} ) ) ?: $toks;
				sort( $core );
				$kws[ $m ] = array( 'k' => $r['k'], 'v' => (int) $r['v'], 'nk' => $nk, 'key' => str_replace( ' ', '', $nk ), 'alts' => array(), 'i' => $m, 'toks' => $toks, 'core' => $core, 'ck' => implode( ' ', $core ), 'cmp' => implode( '', $core ), 'intent0' => mzm_kw_intent_lex( $nk ) );
			}
			$to = 'new' === (string) ( $e['to'] ?? 'new' ) ? null : $cl( $e['to'] );
			if ( null === $to ) {
				$clusters[] = mzm_edit_new_cluster( $clusters, $kws, array( $m ), null );
			} else {
				$clusters[ $to ]['kws'][] = $m;
			}
			break;
		default:
			return new WP_Error( 'mzm_edit', 'عملیات نامعتبر.' );
	}
	mzm_edit_finish( $project, $kws, $clusters, $removed );
	return true;
}

/**
 * A new cluster made of keywords (inherits pillar / section of $like).
 */
function mzm_edit_new_cluster( $clusters, $kws, $members, $like ) {
	$id   = 1 + max( array_column( $clusters, 'id' ) ?: array( 0 ) );
	$head = $members[0];
	$g    = array( 'kws' => $members, 'core' => $kws[ $head ]['core'] );
	$in   = mzm_group_intent( $kws, $members );
	return array(
		'id'          => $id,
		'name'        => $kws[ $head ]['k'],
		'head'        => $head,
		'kws'         => $members,
		'vol'         => 0,
		'core'        => $kws[ $head ]['core'],
		'checked'     => 0,
		'serp'        => null,
		'intent'      => $in,
		'page'        => $like['page'] ?? mzm_page_from_intent( $in, $kws, $g ),
		'pillar_name' => $like['pillar_name'] ?? 'سایر کلمات',
		'section'     => $like['section'] ?? 'بلاگ',
		'target'      => null,
		'coverage'    => 'new',
		'issues'      => array(),
		'manual'      => 1,
	);
}

/**
 * Recompute after an edit: totals, empty clusters, pillars, scores.
 */
function mzm_edit_finish( $project, $kws, $clusters, $removed ) {
	$pid = $project['id'];
	$out = array();
	foreach ( $clusters as $c ) {
		$c['kws'] = array_values( array_unique( array_map( 'intval', $c['kws'] ) ) );
		if ( ! $c['kws'] ) {
			continue;
		}
		usort( $c['kws'], function ( $a, $b ) use ( $kws ) {
			return $kws[ $b ]['v'] <=> $kws[ $a ]['v'];
		} );
		$c['vol'] = array_sum( array_map( function ( $m ) use ( $kws ) {
			return (int) $kws[ $m ]['v'];
		}, $c['kws'] ) );
		if ( empty( $c['locked_name'] ) ) {
			$c['head'] = $c['kws'][0];
			$c['name'] = $kws[ $c['kws'][0] ]['k'];
		}
		$out[] = $c;
	}
	$out = mzm_sort( $out, 'vol' );
	$asm = mzm_pillars_assemble( $out );
	$inv = (array) ( mzm_data_get( $pid, 'inventory', array() )['items'] ?? array() );
	$cls = mzm_score_clusters( $project, $asm['clusters'], $asm['pillars'], $inv );
	$pls = mzm_pillar_totals( $cls, $asm['pillars'] );
	mzm_data_put( $pid, 'keywords', $kws );
	mzm_data_put( $pid, 'clusters', $cls );
	mzm_data_put( $pid, 'pillars', $pls );
	mzm_data_put( $pid, 'removed', $removed );
	$meta = mzm_data_get( $pid, 'meta', array() );
	$meta = array_merge( $meta, mzm_meta_summary( mzm_data_get( $pid, 'input', array() ), $kws, $removed, $cls, $pls ), array( 'edited' => mzm_now() ) );
	mzm_data_put( $pid, 'meta', $meta );
}

/**
 * Rebuild only the content calendar (after edits or new calendar settings).
 */
function mzm_calendar_rebuild( $project ) {
	$pid = $project['id'];
	$cal = mzm_calendar_build( $project, mzm_data_get( $pid, 'keywords', array() ), mzm_data_get( $pid, 'clusters', array() ), mzm_data_get( $pid, 'pillars', array() ), (array) ( mzm_data_get( $pid, 'inventory', array() )['items'] ?? array() ) );
	mzm_data_put( $pid, 'calendar', $cal );
	return $cal;
}
