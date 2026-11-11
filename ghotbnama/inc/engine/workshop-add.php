<?php
/**
 * New keywords in a running project: they are cleaned, triaged and sent to the topic
 * they belong to, without touching what was already reviewed. Processing that topic
 * again clusters only them (locked clusters stay as they are and can take them in).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pasted text → rows: one keyword per line, the volume after a tab, comma or «|».
 */
function qtb_text_to_rows( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = array_map( 'trim', preg_split( '/\t|\||,|،|;/u', $line ) );
		$v     = 0;
		if ( count( $parts ) > 1 && qtb_import_is_num( end( $parts ) ) ) {
			$v = qtb_parse_volume( array_pop( $parts ) );
		}
		$k = trim( implode( ' ', $parts ) );
		if ( '' !== $k && mb_strlen( $k ) <= 160 ) {
			$out[] = array( 'k' => $k, 'v' => $v );
		}
	}
	return $out;
}

/**
 * Add keywords to the project: duplicates of what is there (kept or removed) are skipped,
 * the rest goes through the cleaning rules and is marked «fresh».
 *
 * @return array|WP_Error {added, dup, removed}
 */
function qtb_ws_add_rows( $project, $rows ) {
	$pid = $project['id'];
	$kws = qtb_data_get( $pid, 'keywords', array() );
	if ( ! $kws ) {
		return new WP_Error( 'qtb_ws_add', 'این پروژه هنوز فهرست کلمات ندارد؛ از «شروع: دریافت و غربال کلمات» استفاده کنید.' );
	}
	$removed = (array) qtb_data_get( $pid, 'removed', array() );
	$seen    = array();
	foreach ( $kws as $kw ) {
		$seen[ $kw['key'] ] = 1;
		foreach ( (array) ( $kw['alts'] ?? array() ) as $a ) {
			$seen[ str_replace( ' ', '', qtb_norm_key( $a ) ) ] = 1;
		}
	}
	foreach ( $removed as $r ) {
		$seen[ str_replace( ' ', '', qtb_norm_key( $r['k'] ) ) ] = 1;
	}
	$fresh = array();
	$dup   = 0;
	foreach ( (array) $rows as $r ) {
		$key = str_replace( ' ', '', qtb_norm_key( (string) ( $r['k'] ?? '' ) ) );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			++$dup;
			continue;
		}
		$seen[ $key ] = 1;
		$fresh[]      = $r;
	}
	if ( ! $fresh ) {
		return array( 'added' => 0, 'dup' => $dup, 'removed' => 0 );
	}
	$res  = qtb_clean_run( $project, $fresh );
	$base = count( $kws );
	$now  = qtb_now();
	$ws   = qtb_ws_get( $pid );
	foreach ( $res['keywords'] as $n => $kw ) {
		$kw['i']     = $base + $n;
		$kw['ws']    = 'keep';
		$kw['tr']    = 'ok';
		$kw['tn']    = '';
		$kw['fresh'] = 1;
		$kw['added'] = $now;
		if ( ! empty( $kw['imp']['c'] ) ) {
			$kw['tl'] = 1;
			$kw['tn'] = 'در کلاستر شما';
		}
		$kws[ $kw['i'] ] = $kw;
	}
	qtb_annotate( $kws );
	$fk = array_filter( $kws, function ( $kw ) {
		return ! empty( $kw['fresh'] );
	} );
	qtb_ws_apply_rules( $fk, $ws['rules'] );
	foreach ( $fk as $i => $kw ) {
		$kws[ $i ] = $kw;
	}
	qtb_data_put( $pid, 'keywords', $kws );
	qtb_data_put( $pid, 'removed', array_merge( $removed, $res['removed'] ) );
	qtb_data_put( $pid, 'input', array_merge( (array) qtb_data_get( $pid, 'input', array() ), $fresh ) );
	return array( 'added' => count( $res['keywords'] ), 'dup' => $dup, 'removed' => count( $res['removed'] ) );
}

/**
 * AI triage of the new keywords only.
 */
function qtb_ws_add_ai( $project, &$job, $deadline ) {
	$job['only_new'] = 1;
	return qtb_ws_triage_ai( $project, $job, $deadline );
}

/**
 * Send every new keyword to its topic: the pillar from the file, else the topic whose
 * keywords it shares words with, else the AI (an existing topic or a new one).
 */
function qtb_ws_add_assign( $project, &$job, $deadline ) {
	$pid    = $project['id'];
	$kws    = qtb_data_get( $pid, 'keywords', array() );
	$ws     = qtb_ws_get( $pid );
	$active = qtb_ws_active( $kws );
	$fresh  = array_filter( $active, function ( $kw ) {
		return ! empty( $kw['fresh'] );
	} );
	if ( ! $fresh ) {
		qtb_job_log( $job, 'کلمه جدیدی برای موضوع‌بندی نماند (همه حذف یا پارک شدند).' );
		return true;
	}
	if ( ! $ws['topics'] ) {
		qtb_job_log( $job, 'هنوز موضوعی ساخته نشده؛ کلمات جدید با «ساخت موضوع‌ها» تقسیم می‌شوند.' );
		return true;
	}
	$owner = array(); // keyword → topic index.
	foreach ( $ws['topics'] as $ti => $t ) {
		foreach ( $t['kws'] as $i ) {
			$owner[ $i ] = $ti;
		}
	}
	$by_name = array();
	foreach ( $ws['topics'] as $ti => $t ) {
		$by_name[ qtb_key( $t['name'] ) ] = $ti;
	}
	$to   = isset( $job['sub']['to'] ) ? $job['sub']['to'] : array(); // fresh keyword → topic index | topic name.
	$left = array();
	if ( ! isset( $job['sub']['to'] ) ) {
		// 1. Pillar written in the file.
		foreach ( $fresh as $i => $kw ) {
			$p = (string) ( $kw['imp']['p'] ?? '' );
			if ( '' !== $p ) {
				$to[ $i ] = $by_name[ qtb_key( $p ) ] ?? $p;
			}
		}
		// 2. Word groups shared with keywords of one topic.
		foreach ( qtb_precluster( $active, array( 'brands' => array_merge( (array) $project['cfg']['brands'], (array) $project['cfg']['competitors'] ) ) ) as $g ) {
			$votes = array();
			foreach ( $g['kws'] as $m ) {
				if ( isset( $owner[ $m ] ) ) {
					$votes[ $owner[ $m ] ] = ( $votes[ $owner[ $m ] ] ?? 0 ) + 1;
				}
			}
			if ( ! $votes ) {
				continue;
			}
			arsort( $votes );
			foreach ( $g['kws'] as $m ) {
				if ( isset( $fresh[ $m ] ) && ! isset( $to[ $m ] ) ) {
					$to[ $m ] = key( $votes );
				}
			}
		}
		$job['sub']['to'] = $to;
	}
	foreach ( $fresh as $i => $kw ) {
		if ( ! isset( $to[ $i ] ) ) {
			$left[ $i ] = $kw;
		}
	}
	// 3. The AI for the rest (existing topics first).
	if ( $left && qtb_ai_connected() ) {
		$groups = qtb_precluster( $left, array() );
		$batch  = array();
		foreach ( array_values( $groups ) as $gi => $g ) {
			$batch[] = array(
				'gid'  => $gi,
				'head' => $kws[ $g['head'] ]['k'],
				'vol'  => $g['vol'],
				'more' => array_map( function ( $m ) use ( $kws ) {
					return $kws[ $m ]['k'];
				}, array_slice( $g['kws'], 1, 4 ) ),
			);
		}
		$known = array_column( $ws['topics'], 'name' );
		foreach ( array_chunk( $batch, 150 ) as $chunk ) {
			$res = qtb_ai_topics_batch( $project, $ws, $chunk, $known );
			if ( is_wp_error( $res ) ) {
				qtb_job_log( $job, 'موضوع‌بندی هوشمند کلمات جدید ناقص ماند: ' . $res->get_error_message(), 'warn' );
				break;
			}
			$gl = array_values( $groups );
			foreach ( $res as $gid => $name ) {
				foreach ( $gl[ $gid ]['kws'] ?? array() as $m ) {
					$to[ $m ] = $by_name[ qtb_key( $name ) ] ?? $name;
				}
			}
		}
	}
	// Whatever is left: «متفرقه».
	foreach ( $fresh as $i => $kw ) {
		if ( ! isset( $to[ $i ] ) ) {
			$to[ $i ] = $by_name[ qtb_key( 'متفرقه' ) ] ?? 'متفرقه';
		}
	}
	// Apply: existing topics get the keywords, new names become new topics.
	$count = array();
	foreach ( $to as $i => $dest ) {
		if ( is_string( $dest ) ) {
			$k = qtb_key( $dest );
			if ( ! isset( $by_name[ $k ] ) ) {
				$ws['topics'][]  = qtb_ws_new_topic( $ws, $dest, array(), $project );
				$by_name[ $k ]   = count( $ws['topics'] ) - 1;
			}
			$dest = $by_name[ $k ];
		}
		if ( ! in_array( (int) $i, $ws['topics'][ $dest ]['kws'], true ) ) {
			$ws['topics'][ $dest ]['kws'][] = (int) $i;
			$count[ $dest ]                  = ( $count[ $dest ] ?? 0 ) + 1;
		}
	}
	$names = array();
	foreach ( $count as $ti => $n ) {
		$ws['topics'][ $ti ]['fresh'] = (int) ( $ws['topics'][ $ti ]['fresh'] ?? 0 ) + $n;
		// What was reviewed stays: the topic's clusters are locked before it takes the new keywords.
		if ( 'new' !== $ws['topics'][ $ti ]['status'] ) {
			foreach ( $ws['topics'][ $ti ]['clusters'] as &$c ) {
				$c['locked'] = 1;
			}
			unset( $c );
		}
		// A reviewed topic must be processed again for its new keywords.
		if ( 'approved' === $ws['topics'][ $ti ]['status'] ) {
			$ws['topics'][ $ti ]['status'] = 'ready';
		}
		$names[] = $ws['topics'][ $ti ]['name'] . ' (' . qtb_num( $n ) . ')';
	}
	qtb_ws_put( $pid, $ws );
	foreach ( $kws as &$kw ) {
		unset( $kw['fresh'] );
	}
	unset( $kw );
	qtb_data_put( $pid, 'keywords', $kws );
	qtb_job_log( $job, sprintf( '%s کلمه جدید به موضوع‌ها رفت: %s. موضوع‌های علامت‌دار را «پردازش» کنید؛ کلاسترهای قفل‌شده دست نمی‌خورند و فقط کلمات جدید خوشه‌بندی می‌شوند.', qtb_num( array_sum( $count ) ), implode('، ', $names ) ) );
	return true;
}
