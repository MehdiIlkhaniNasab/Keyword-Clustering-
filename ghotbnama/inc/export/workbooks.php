<?php
/**
 * Workbook models (rendered to XLSX and to Google Sheets).
 *
 *   Keyword research — the deliverable: a summary, one sheet per pillar in the
 *   research layout (section bands, one «keyword | volume» block per page, the
 *   main keyword first, the total under it; one calm color per page type), the
 *   page map (each page with all its keywords) and removed / parked keywords.
 *
 *   Content calendar — kept for the API, no longer part of the main output.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Palette.
 */
function qtb_xc( $key ) {
	$c = array(
		'ink'      => '1B2B24',
		'brand'    => '0F3D2E',
		'brand2'   => '1E7B55',
		'mint'     => 'E3F2EA',
		'gold'     => 'C8A951',
		'muted'    => '6B7C74',
		'line'     => 'D9E4DE',
		'landing'  => 'D9EAD3',
		'archive'  => 'C9DAF8',
		'product'  => 'FCE5CD',
		'article'  => 'FFF2CC',
		'total'    => '6AA84F',
		'sec_main'    => '38761D',
		'sec_landing' => '93C47D',
		'sec_archive' => '6D9EEB',
		'sec_product' => 'E69138',
		'sec_article' => 'F1C232',
		'grid'     => 'F3F3F3',
	);
	return $c[ $key ] ?? $key;
}

/**
 * Cell helper.
 */
function qtb_c( $v, $s = array(), $link = '' ) {
	$cell = array( 'v' => $v, 's' => $s );
	if ( $link ) {
		$cell['link'] = $link;
	}
	return $cell;
}

/**
 * Header style (dark green bar).
 */
function qtb_xs_head() {
	return array( 'b' => true, 'bg' => qtb_xc( 'brand' ), 'color' => 'FFFFFF', 'h' => 'center', 'wrap' => true, 'border' => '1E7B55' );
}

/**
 * Body style of tables.
 */
function qtb_xs_td( $extra = array() ) {
	return array_merge( array( 'border' => qtb_xc( 'line' ), 'v' => 'center' ), $extra );
}

/**
 * A simple table sheet.
 *
 * @param array $head [label => width]
 * @param array $rows rows of cells
 */
function qtb_table_sheet( $name, $head, $rows, $tab = '', $title = '' ) {
	$sh  = array( 'name' => $name, 'rtl' => true, 'tab' => $tab, 'cols' => array(), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	$r   = 1;
	$n   = count( $head );
	if ( $title ) {
		$sh['cells'][1][1] = qtb_c( $title, array( 'b' => true, 'size' => 13, 'color' => qtb_xc( 'brand' ) ) );
		$sh['merges'][]    = 'A1:' . qtb_ref( 1, max( 1, $n ) );
		$sh['heights'][1]  = 28;
		$r                 = 2;
	}
	$c = 1;
	foreach ( $head as $label => $w ) {
		$sh['cells'][ $r ][ $c ] = qtb_c( $label, qtb_xs_head() );
		$sh['cols'][ $c ]        = $w;
		++$c;
	}
	$sh['heights'][ $r ] = 30;
	$sh['freeze']        = 'A' . ( $r + 1 );
	$sh['filter']        = 'A' . $r . ':' . qtb_col( $n ) . $r;
	foreach ( $rows as $row ) {
		++$r;
		$c = 1;
		foreach ( $row as $cell ) {
			$sh['cells'][ $r ][ $c ] = is_array( $cell ) ? array_merge( array( 's' => qtb_xs_td() ), $cell ) : qtb_c( $cell, qtb_xs_td( is_numeric( $cell ) && ! is_string( $cell ) ? array( 'fmt' => '#,##0', 'h' => 'center' ) : array() ) );
			++$c;
		}
	}
	return $sh;
}

/**
 * Everything a workbook needs from the stored result.
 */
function qtb_result( $project ) {
	$pid = $project['id'];
	$r   = array(
		'keywords' => qtb_data_get( $pid, 'keywords', array() ),
		'clusters' => qtb_data_get( $pid, 'clusters', array() ),
		'pillars'  => qtb_data_get( $pid, 'pillars', array() ),
		'removed'  => qtb_data_get( $pid, 'removed', array() ),
		'calendar' => qtb_data_get( $pid, 'calendar', array() ),
		'meta'     => qtb_data_get( $pid, 'meta', array() ),
	);
	$r['by_id'] = array();
	foreach ( $r['clusters'] as $c ) {
		$r['by_id'][ $c['id'] ] = $c;
	}
	return $r;
}

/**
 * Coverage labels.
 */
function qtb_coverage_label( $key ) {
	$l = array( 'new' => 'صفحه جدید لازم', 'optimize' => 'بهینه‌سازی صفحه موجود', 'ok' => 'رتبه در صفحه اول' );
	return $l[ $key ] ?? '';
}

/**
 * Next action of a cluster.
 */
function qtb_cluster_action( $c ) {
	$type = qtb_page_types()[ $c['page'] ][0] ?? '';
	if ( ! empty( $c['quick_win'] ) ) {
		return 'برد سریع: بهینه‌سازی صفحه رتبه‌گرفته برای ورود به ۳ نتیجه اول';
	}
	switch ( $c['coverage'] ) {
		case 'new':
			return 'ساخت ' . $type;
		case 'optimize':
			return 'بهینه‌سازی و تکمیل صفحه موجود';
		default:
			return 'حفظ رتبه و تقویت با لینک داخلی';
	}
}

/**
 * Design tokens of the deliverable: deep forest, warm gold, and one calm tone per page type.
 */
function qtb_xd( $key ) {
	$d = array(
		'forest'  => '0B3B2E',
		'forest2' => '1F5C45',
		'gold'    => 'C9A55C',
		'gold_bg' => 'F7F1E1',
		'ink'     => '1F2D27',
		'ink2'    => '3E4F47',
		'muted'   => '7A8A82',
		'paper'   => 'F4F7F5',
		'hair'    => 'E4EAE6',
		'zebra'   => 'FAFCFB',
	);
	return $d[ $key ] ?? $key;
}

/**
 * Tones of a page type: tint (cluster head), soft (main keyword), mid (lines), dark (text / section band).
 */
function qtb_xpage( $page ) {
	$t = array(
		'landing' => array( 'tint' => 'DFF0E6', 'soft' => 'F1F8F4', 'mid' => '8CC5A3', 'dark' => '1E6B47', 'band' => '2E7D57' ),
		'archive' => array( 'tint' => 'E2EBF6', 'soft' => 'F2F6FB', 'mid' => '9BB7DC', 'dark' => '2C5684', 'band' => '3B6A9E' ),
		'product' => array( 'tint' => 'FAE6D8', 'soft' => 'FDF4EE', 'mid' => 'E5AE88', 'dark' => '8F4719', 'band' => 'B5652F' ),
		'article' => array( 'tint' => 'F9EFCF', 'soft' => 'FDF9EC', 'mid' => 'DDC27A', 'dark' => '7A5B0C', 'band' => 'A47E1B' ),
		'main'    => array( 'tint' => 'DFF0E6', 'soft' => 'F1F8F4', 'mid' => '8CC5A3', 'dark' => '0B3B2E', 'band' => '1F5C45' ),
	);
	return $t[ $page ] ?? $t['archive'];
}

/**
 * A title band (first rows of every sheet).
 */
function qtb_xband( &$sh, $title, $sub, $last_col, $first_col = 2 ) {
	$sh['cells'][1][ $first_col ] = qtb_c( $title, array( 'b' => true, 'size' => 15, 'color' => 'FFFFFF', 'bg' => qtb_xd( 'forest' ), 'h' => 'right', 'indent' => 1, 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
	$sh['cells'][2][ $first_col ] = qtb_c( $sub, array( 'size' => 9, 'color' => qtb_xd( 'muted' ), 'bg' => qtb_xd( 'paper' ), 'h' => 'right', 'indent' => 1 ) );
	for ( $c = $first_col + 1; $c <= $last_col; $c++ ) {
		$sh['cells'][1][ $c ] = qtb_c( '', array( 'bg' => qtb_xd( 'forest' ), 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
		$sh['cells'][2][ $c ] = qtb_c( '', array( 'bg' => qtb_xd( 'paper' ) ) );
	}
	if ( $last_col > $first_col ) {
		$sh['merges'][] = qtb_ref( 1, $first_col ) . ':' . qtb_ref( 1, $last_col );
		$sh['merges'][] = qtb_ref( 2, $first_col ) . ':' . qtb_ref( 2, $last_col );
	}
	$sh['heights'][1] = 36;
	$sh['heights'][2] = 22;
	$sh['heights'][3] = 10;
}

/**
 * A clean table: forest header with a gold line, hairline rows, light zebra, optional group bands.
 *
 * @param array $head  [label => [width, align]]
 * @param array $rows  rows of cells (a row ['__band' => text] is a full-width group band)
 */
function qtb_xtable( $name, $title, $sub, $head, $rows, $tab ) {
	$n  = count( $head );
	$sh = array( 'name' => $name, 'rtl' => true, 'fit' => true, 'tab' => $tab, 'cols' => array( 1 => 1.6 ), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	qtb_xband( $sh, $title, $sub, $n + 1 );
	$r     = 4;
	$c     = 2;
	$align = array();
	foreach ( $head as $label => $def ) {
		$sh['cells'][ $r ][ $c ] = qtb_c( $label, array( 'b' => true, 'size' => 9.5, 'color' => 'FFFFFF', 'bg' => qtb_xd( 'forest2' ), 'h' => 'center', 'wrap' => true, 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
		$sh['cols'][ $c ]        = $def[0];
		$align[ $c ]             = $def[1] ?? '';
		++$c;
	}
	$sh['heights'][ $r ] = 30;
	$sh['freeze']        = 'A' . ( $r + 1 );
	$sh['filter']        = qtb_ref( $r, 2 ) . ':' . qtb_ref( $r, $n + 1 );
	$z                   = 0;
	foreach ( $rows as $row ) {
		++$r;
		if ( isset( $row['__band'] ) ) {
			$sh['cells'][ $r ][2] = qtb_c( $row['__band'], array( 'b' => true, 'size' => 11, 'color' => qtb_xd( 'forest' ), 'bg' => qtb_xd( 'gold_bg' ), 'h' => 'right', 'indent' => 1, 'bb' => qtb_xd( 'gold' ) ) );
			for ( $cc = 3; $cc <= $n + 1; $cc++ ) {
				$sh['cells'][ $r ][ $cc ] = qtb_c( '', array( 'bg' => qtb_xd( 'gold_bg' ), 'bb' => qtb_xd( 'gold' ) ) );
			}
			$sh['merges'][]      = qtb_ref( $r, 2 ) . ':' . qtb_ref( $r, $n + 1 );
			$sh['heights'][ $r ] = 24;
			$z                   = 0;
			continue;
		}
		$bg = ( $z++ % 2 ) ? qtb_xd( 'zebra' ) : '';
		$c  = 2;
		foreach ( $row as $cell ) {
			$base = array( 'color' => qtb_xd( 'ink2' ), 'bb' => qtb_xd( 'hair' ), 'v' => 'center' );
			if ( $bg ) {
				$base['bg'] = $bg;
			}
			if ( '' !== $align[ $c ] ) {
				$base['h'] = $align[ $c ];
			}
			if ( is_array( $cell ) ) {
				$cell['s'] = array_merge( $base, (array) ( $cell['s'] ?? array() ) );
				$sh['cells'][ $r ][ $c ] = $cell;
			} else {
				if ( is_int( $cell ) || is_float( $cell ) ) {
					$base['fmt'] = '#,##0';
					$base['h']   = $base['h'] ?? 'center';
				}
				$sh['cells'][ $r ][ $c ] = qtb_c( $cell, $base );
			}
			++$c;
		}
	}
	return $sh;
}

/**
 * Keyword research workbook: summary, one sheet per pillar (the research layout), the
 * page map (each page with its keywords), and removed / parked keywords.
 */
function qtb_book_research( $project ) {
	$R      = qtb_result( $project );
	$kws    = $R['keywords'];
	$by_id  = $R['by_id'];
	$meta   = $R['meta'];
	$sheets = array();
	$pages  = array( 'landing' => 0, 'archive' => 0, 'product' => 0, 'article' => 0 );
	foreach ( $R['clusters'] as $cl ) {
		$pages[ $cl['page'] ] = ( $pages[ $cl['page'] ] ?? 0 ) + 1;
	}
	$nkw = 0;
	foreach ( $R['clusters'] as $cl ) {
		$nkw += count( $cl['kws'] );
	}
	$vol = array_sum( array_column( $R['clusters'], 'vol' ) );
	$sub = sprintf( '%s  ·  %s  ·  %s', qtb_host( $project['site_url'] ), qtb_jdate( $meta['built'] ?? qtb_now() ), 'free' === ( $meta['serp_mode'] ?? 'free' ) ? 'خوشه‌بندی معنایی و هوش مصنوعی' : 'خوشه‌بندی بر اساس ۱۰ نتیجه اول گوگل' );

	// 1. Summary.
	$d = array( 'name' => 'خلاصه', 'rtl' => true, 'fit' => true, 'tab' => qtb_xd( 'forest' ), 'cols' => array( 1 => 1.6 ), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	for ( $c = 2; $c <= 9; $c++ ) {
		$d['cols'][ $c ] = 14;
	}
	$d['cols'][2] = 26;
	qtb_xband( $d, 'کیورد ریسرچ  |  ' . $project['name'], $sub, 9 );
	$kpis = array(
		array( 'حجم جستجوی ماهانه', $vol ),
		array( 'صفحه (کلاستر)', count( $R['clusters'] ) ),
		array( 'پیلار', count( $R['pillars'] ) ),
		array( 'کلمه کلیدی', $nkw ),
	);
	$c = 2;
	foreach ( $kpis as $k ) {
		$span = 2 === $c ? 1 : 2;
		$end  = $c + $span - 1;
		$d['cells'][5][ $c ] = qtb_c( $k[0], array( 'size' => 9, 'color' => qtb_xd( 'muted' ), 'bg' => qtb_xd( 'paper' ), 'h' => 'center' ) );
		$d['cells'][6][ $c ] = qtb_c( $k[1], array( 'b' => true, 'size' => 18, 'color' => qtb_xd( 'forest' ), 'bg' => qtb_xd( 'paper' ), 'h' => 'center', 'fmt' => '#,##0', 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
		for ( $x = $c + 1; $x <= $end; $x++ ) {
			$d['cells'][5][ $x ] = qtb_c( '', array( 'bg' => qtb_xd( 'paper' ) ) );
			$d['cells'][6][ $x ] = qtb_c( '', array( 'bg' => qtb_xd( 'paper' ), 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
		}
		if ( $end > $c ) {
			$d['merges'][] = qtb_ref( 5, $c ) . ':' . qtb_ref( 5, $end );
			$d['merges'][] = qtb_ref( 6, $c ) . ':' . qtb_ref( 6, $end );
		}
		$c = $end + 1;
	}
	$d['heights'][5] = 22;
	$d['heights'][6] = 38;
	$r = 8;
	$d['cells'][ $r ][2] = qtb_c( 'پیلارها', array( 'b' => true, 'size' => 12, 'color' => qtb_xd( 'forest' ), 'v' => 'center' ) );
	$d['heights'][ $r ]  = 26;
	++$r;
	$head = array( 'پیلار', 'حجم جستجو', 'صفحه', 'لندینگ', 'آرشیو', 'محصول', 'مقاله', 'کلمه' );
	foreach ( $head as $i => $h ) {
		$d['cells'][ $r ][ $i + 2 ] = qtb_c( $h, array( 'b' => true, 'size' => 9.5, 'color' => 'FFFFFF', 'bg' => qtb_xd( 'forest2' ), 'h' => 'center', 'bb' => qtb_xd( 'gold' ), 'bbm' => 1 ) );
	}
	$d['heights'][ $r ] = 26;
	$z = 0;
	foreach ( $R['pillars'] as $p ) {
		++$r;
		$cnt = array( 'landing' => 0, 'archive' => 0, 'product' => 0, 'article' => 0 );
		$n   = 0;
		foreach ( $p['clusters'] as $cid ) {
			if ( isset( $by_id[ $cid ] ) ) {
				$cnt[ $by_id[ $cid ]['page'] ] = ( $cnt[ $by_id[ $cid ]['page'] ] ?? 0 ) + 1;
				$n                             += count( $by_id[ $cid ]['kws'] );
			}
		}
		$bg   = ( $z++ % 2 ) ? qtb_xd( 'zebra' ) : '';
		$vals = array( $p['name'], (int) $p['vol'], count( $p['clusters'] ), $cnt['landing'], $cnt['archive'], $cnt['product'], $cnt['article'], $n );
		foreach ( $vals as $i => $v ) {
			$st = array( 'bb' => qtb_xd( 'hair' ), 'v' => 'center', 'color' => 0 === $i ? qtb_xd( 'ink' ) : qtb_xd( 'ink2' ) ) + ( $bg ? array( 'bg' => $bg ) : array() );
			$st += 0 === $i ? array( 'b' => true ) : array( 'h' => 'center', 'fmt' => '#,##0' );
			$d['cells'][ $r ][ $i + 2 ] = qtb_c( $v, $st );
		}
		$d['heights'][ $r ] = 22;
	}
	$r += 2;
	$d['cells'][ $r ][2] = qtb_c( 'نوع صفحات', array( 'b' => true, 'size' => 12, 'color' => qtb_xd( 'forest' ), 'v' => 'center' ) );
	$d['heights'][ $r ]  = 26;
	foreach ( array( 'landing' => 'لندینگ — صفحه اختصاصی (خدمات، شعبه، موضوع خاص)', 'archive' => 'آرشیو — دسته، برند، برچسب یا ویژگی فروشگاه', 'product' => 'محصول — یک محصول مشخص', 'article' => 'مقاله — آموزش، مقایسه و راهنمای خرید' ) as $k => $label ) {
		++$r;
		$t = qtb_xpage( $k );
		$d['cells'][ $r ][2] = qtb_c( qtb_page_types()[ $k ][0] ?? $k, array( 'b' => true, 'bg' => $t['tint'], 'color' => $t['dark'], 'h' => 'center', 'bb' => $t['mid'] ) );
		$d['cells'][ $r ][3] = qtb_c( (int) ( $pages[ $k ] ?? 0 ), array( 'b' => true, 'color' => $t['dark'], 'h' => 'center', 'bb' => qtb_xd( 'hair' ) ) );
		$d['cells'][ $r ][4] = qtb_c( $label, array( 'color' => qtb_xd( 'ink2' ), 'size' => 9.5, 'bb' => qtb_xd( 'hair' ), 'v' => 'center', 'indent' => 1 ) );
		for ( $x = 5; $x <= 9; $x++ ) {
			$d['cells'][ $r ][ $x ] = qtb_c( '', array( 'bb' => qtb_xd( 'hair' ) ) );
		}
		$d['merges'][]      = qtb_ref( $r, 4 ) . ':' . qtb_ref( $r, 9 );
		$d['heights'][ $r ] = 22;
	}
	$sheets[] = $d;

	// 2. Pillar sheets.
	foreach ( $R['pillars'] as $p ) {
		$sheets[] = qtb_pillar_sheet( $p, $by_id, $kws, $sub );
	}

	// 3. Page map: one row per page, its keywords in one cell.
	$rows = array();
	$no   = 0;
	foreach ( $R['pillars'] as $p ) {
		$rows[] = array( '__band' => $p['name'] . '  ·  ' . qtb_num( count( $p['clusters'] ) ) . ' صفحه  ·  حجم ' . qtb_num( $p['vol'] ) );
		foreach ( $p['sections'] as $s ) {
			foreach ( $s['clusters'] as $cid ) {
				$cl = $by_id[ $cid ] ?? null;
				if ( ! $cl ) {
					continue;
				}
				$t    = qtb_xpage( $cl['page'] );
				$head = $cl['kws'][0];
				$rest = array();
				foreach ( array_slice( $cl['kws'], 1 ) as $m ) {
					$rest[] = $kws[ $m ]['k'] . ' (' . qtb_num( $kws[ $m ]['v'] ) . ')';
				}
				$title  = (string) ( $cl['gap']['title'] ?? '' );
				$rows[] = array(
					++$no,
					$s['name'],
					qtb_c( $cl['name'], array( 'b' => true, 'color' => qtb_xd( 'ink' ) ) ),
					qtb_c( qtb_page_label( $cl ), array( 'b' => true, 'bg' => $t['tint'], 'color' => $t['dark'], 'h' => 'center' ) ),
					qtb_c( $kws[ $head ]['k'], array( 'color' => $t['dark'] ) ),
					(int) $kws[ $head ]['v'],
					qtb_c( implode( '، ', $rest ), array( 'wrap' => true, 'size' => 9.5 ) ),
					count( $cl['kws'] ),
					qtb_c( (int) $cl['vol'], array( 'b' => true, 'fmt' => '#,##0', 'h' => 'center', 'color' => qtb_xd( 'forest' ) ) ),
					$cl['target'] ? qtb_c( rawurldecode( $cl['target']['url'] ), array( 'color' => '2C5684', 'size' => 9 ), $cl['target']['url'] ) : qtb_c( 'صفحه جدید', array( 'color' => qtb_xd( 'muted' ), 'size' => 9 ) ),
					qtb_c( '' !== $title ? $title : '', array( 'wrap' => true, 'size' => 9.5 ) ),
				);
			}
		}
	}
	$sheets[] = qtb_xtable(
		'نقشه صفحات',
		'نقشه صفحات و کلمات  |  ' . $project['name'],
		'هر ردیف یک صفحه: کلمه اصلی صفحه و کلمات فرعی که همان صفحه باید پوشش دهد. ' . $sub,
		array(
			'#'                => array( 5, 'center' ),
			'بخش'              => array( 18, '' ),
			'صفحه'             => array( 28, '' ),
			'نوع صفحه'         => array( 14, 'center' ),
			'کلمه کلیدی اصلی'  => array( 26, '' ),
			'حجم'              => array( 9, 'center' ),
			'کلمات فرعی (حجم)' => array( 70, '' ),
			'تعداد کلمات'      => array( 9, 'center' ),
			'حجم کل صفحه'      => array( 11, 'center' ),
			'صفحه فعلی سایت'   => array( 30, '' ),
			'عنوان پیشنهادی'   => array( 30, '' ),
		),
		$rows,
		qtb_xd( 'gold' )
	);

	// 4. Removed and parked.
	$rows    = array();
	$reasons = qtb_remove_reasons();
	foreach ( $kws as $kw ) {
		if ( 'park' === ( $kw['ws'] ?? '' ) ) {
			$rows[] = array( $kw['k'], (int) $kw['v'], qtb_c( 'پارکینگ', array( 'b' => true, 'color' => '7A5B0C', 'bg' => 'F9EFCF', 'h' => 'center' ) ), 'برای تصمیم بعدی', (string) ( $kw['tn'] ?? '' ) );
		}
	}
	foreach ( qtb_sort( $R['removed'], 'v' ) as $x ) {
		$rows[] = array( $x['k'], (int) $x['v'], qtb_c( 'حذف', array( 'color' => '9A3B37', 'h' => 'center' ) ), $reasons[ $x['r'] ] ?? $x['r'], (string) ( $x['d'] ?? '' ) );
	}
	$sheets[] = qtb_xtable( 'حذف‌شده و پارکینگ', 'کلمات کنار گذاشته‌شده', 'برای شفافیت: هر کلمه با دلیل. کلمات پارکینگ را بعداً می‌توان به پروژه برگرداند.', array( 'کلمه' => array( 34, '' ), 'حجم' => array( 10, 'center' ), 'وضعیت' => array( 11, 'center' ), 'دلیل' => array( 30, '' ), 'جزئیات' => array( 34, '' ) ), $rows, 'A0A9A4' );

	return array( 'title' => 'Keyword Research — ' . $project['name'], 'sheets' => $sheets );
}

/**
 * One pillar sheet in the research layout: section bands, one «keyword | volume» block per
 * cluster (page), the main keyword first, and the total under it.
 */
function qtb_pillar_sheet( $p, $by_id, $kws, $sub = '' ) {
	$sh      = array( 'name' => $p['name'], 'rtl' => true, 'tab' => qtb_xd( 'forest2' ), 'cols' => array( 1 => 1.6 ), 'cells' => array(), 'merges' => array(), 'heights' => array( 4 => 26, 5 => 34, 6 => 17 ), 'freeze' => 'A7' );
	$col     = 2;
	$max_row = 7;
	$nkw     = 0;
	foreach ( $p['sections'] as $s ) {
		$start = $col;
		$kind  = 'main' === $s['kind'] ? 'main' : ( $s['kind'] ?: 'archive' );
		foreach ( $s['clusters'] as $cid ) {
			$c = $by_id[ $cid ] ?? null;
			if ( ! $c ) {
				continue;
			}
			$t                      = qtb_xpage( $c['page'] );
			$sh['cols'][ $col ]     = 30;
			$sh['cols'][ $col + 1 ] = 9;
			$sh['cols'][ $col + 2 ] = 1.6;
			// Cluster head: the page.
			$sh['cells'][5][ $col ]     = qtb_c( $c['name'], array( 'b' => true, 'size' => 10.5, 'bg' => $t['tint'], 'color' => $t['dark'], 'h' => 'center', 'wrap' => true, 'bb' => $t['mid'], 'bbm' => 1 ) );
			$sh['cells'][5][ $col + 1 ] = qtb_c( '', array( 'bg' => $t['tint'], 'bb' => $t['mid'], 'bbm' => 1 ) );
			$sh['merges'][]             = qtb_ref( 5, $col ) . ':' . qtb_ref( 5, $col + 1 );
			$sh['cells'][6][ $col ]     = qtb_c( qtb_page_label( $c ) . '  ·  ' . qtb_num( count( $c['kws'] ) ) . ' کلمه', array( 'size' => 8, 'color' => $t['dark'], 'h' => 'center' ) );
			$sh['merges'][]             = qtb_ref( 6, $col ) . ':' . qtb_ref( 6, $col + 1 );
			$r                          = 7;
			foreach ( $c['kws'] as $j => $m ) {
				$st  = array( 'color' => qtb_xd( 'ink2' ), 'bb' => qtb_xd( 'hair' ) );
				$st2 = array( 'color' => qtb_xd( 'muted' ), 'bb' => qtb_xd( 'hair' ), 'fmt' => '#,##0', 'h' => 'center', 'size' => 9.5 );
				if ( 0 === $j ) {
					$st  = array( 'b' => true, 'color' => qtb_xd( 'ink' ), 'bg' => $t['soft'], 'bb' => $t['mid'] );
					$st2 = array( 'b' => true, 'color' => $t['dark'], 'bg' => $t['soft'], 'bb' => $t['mid'], 'fmt' => '#,##0', 'h' => 'center' );
				}
				$sh['cells'][ $r ][ $col ]     = qtb_c( $kws[ $m ]['k'], $st );
				$sh['cells'][ $r ][ $col + 1 ] = qtb_c( (int) $kws[ $m ]['v'], $st2 );
				++$r;
				++$nkw;
			}
			$sh['cells'][ $r ][ $col ]     = qtb_c( 'جمع', array( 'b' => true, 'bg' => $t['tint'], 'color' => $t['dark'], 'h' => 'center', 'bt' => $t['mid'], 'btm' => 1 ) );
			$sh['cells'][ $r ][ $col + 1 ] = qtb_c( (int) $c['vol'], array( 'b' => true, 'bg' => $t['tint'], 'color' => $t['dark'], 'h' => 'center', 'fmt' => '#,##0', 'bt' => $t['mid'], 'btm' => 1 ) );
			$max_row                       = max( $max_row, $r );
			$col                          += 3;
		}
		if ( $col > $start ) {
			$band = qtb_xpage( $kind )['band'];
			$end  = $col - 2;
			for ( $x = $start; $x <= $end; $x++ ) {
				$sh['cells'][4][ $x ] = qtb_c( $x === $start ? $s['name'] : '', array( 'b' => true, 'size' => 11, 'bg' => $band, 'color' => 'FFFFFF', 'h' => $end - $start > 2 ? 'right' : 'center', 'indent' => 1 ) );
			}
			if ( $end > $start ) {
				$sh['merges'][] = qtb_ref( 4, $start ) . ':' . qtb_ref( 4, $end );
			}
			// A wider gap between sections.
			$sh['cols'][ $col - 1 ] = 3.2;
		}
	}
	$last = max( 3, $col - 2 );
	qtb_xband( $sh, $p['name'], sprintf( 'حجم کل %s  ·  %s صفحه  ·  %s کلمه%s', qtb_num( $p['vol'] ), qtb_num( count( $p['clusters'] ) ), qtb_num( $nkw ), '' !== $sub ? '  ·  ' . $sub : '' ), $last );
	return $sh;
}

/**
 * Content calendar workbook.
 */
function qtb_book_calendar( $project ) {
	$R     = qtb_result( $project );
	$cal   = (array) $R['calendar'];
	$arts  = (array) ( $cal['articles'] ?? array() );
	$by_id = $R['by_id'];

	// Calendar sheet (agency structure).
	$sh = array( 'name' => 'Calendar', 'rtl' => true, 'tab' => 'A64D79', 'cols' => array(), 'cells' => array(), 'merges' => array(), 'heights' => array(), 'lists' => array() );
	$widths = array( 1 => 6, 2 => 26, 3 => 7, 4 => 34, 5 => 30, 6 => 26 );
	for ( $c = 7; $c <= 26; $c++ ) {
		$widths[ $c ] = 0 === ( $c - 7 ) % 2 ? 22 : 18;
	}
	$widths += array( 27 => 14, 28 => 14, 29 => 24, 30 => 16, 31 => 10, 32 => 50 );
	$sh['cols'] = $widths;
	$g_style = function ( $bg, $white = true ) {
		return array( 'b' => true, 'bg' => $bg, 'color' => $white ? 'FFFFFF' : '20124D', 'h' => 'center', 'border' => 'FFFFFF' );
	};
	$sub = array( 'Main Idea', 'KP', 'Title', 'Desc', 'Source' );
	for ( $i = 1; $i <= 5; $i++ ) {
		$sub[] = 'L' . $i;
		$sub[] = 'K' . $i;
	}
	for ( $i = 1; $i <= 5; $i++ ) {
		$sub[] = 'L' . $i;
		$sub[] = 'K' . $i;
	}
	$sub   = array_merge( $sub, array( 'Status', 'Publish Date', 'Link', 'Pillar', 'Words', 'Brief (H2)' ) );
	$r     = 1;
	$month = 0;
	$stat  = array( 'ایده', 'در صف', 'سفارش داده شد', 'در حال نگارش', 'بازبینی', 'منتشر شد', 'نیاز به آپدیت' );
	$first = 0;
	foreach ( $arts as $a ) {
		if ( $a['month'] !== $month ) {
			if ( $month ) {
				$r += 2;
			}
			$month = $a['month'];
			$sh['cells'][ $r ][1] = qtb_c( 'M' . $month, $g_style( '20124D' ) );
			$sh['merges'][]       = 'A' . $r . ':A' . ( $r + 1 );
			$sh['cells'][ $r ][2] = qtb_c( 'Writing Guide', $g_style( 'A64D79' ) );
			$sh['merges'][]       = 'B' . $r . ':F' . $r;
			$sh['cells'][ $r ][7] = qtb_c( 'Out Links', $g_style( 'E06666' ) );
			$sh['merges'][]       = 'G' . $r . ':P' . $r;
			$sh['cells'][ $r ][17] = qtb_c( 'In Links', $g_style( '8E7CC3' ) );
			$sh['merges'][]        = 'Q' . $r . ':Z' . $r;
			$sh['cells'][ $r ][27] = qtb_c( 'Publish', $g_style( 'E69138' ) );
			$sh['merges'][]        = 'AA' . $r . ':AC' . $r;
			$sh['cells'][ $r ][30] = qtb_c( 'قطب‌نما', $g_style( qtb_xc( 'brand' ) ) );
			$sh['merges'][]        = 'AD' . $r . ':AF' . $r;
			$sh['heights'][ $r ]   = 24;
			++$r;
			foreach ( $sub as $i => $label ) {
				$col = $i + 2;
				$bg  = $col <= 6 ? 'D5A6BD' : ( $col <= 16 ? 'F4CCCC' : ( $col <= 26 ? 'D9D2E9' : ( $col <= 29 ? 'FCE5CD' : qtb_xc( 'mint' ) ) ) );
				$sh['cells'][ $r ][ $col ] = qtb_c( $label, array( 'b' => true, 'bg' => $bg, 'h' => 'center', 'border' => 'FFFFFF', 'color' => '20124D' ) );
			}
			if ( ! $first ) {
				$first = $r + 1;
			}
		}
		++$r;
		$c     = $by_id[ $a['cluster'] ] ?? null;
		$bodyS = array( 'border' => 'E6E0EC', 'v' => 'center' );
		$link  = function ( $u ) use ( $bodyS ) {
			return preg_match( '#^https?://#', (string) $u ) ? qtb_c( rawurldecode( $u ), array_merge( $bodyS, array( 'color' => '0000FF' ) ), $u ) : qtb_c( (string) $u, array_merge( $bodyS, array( 'color' => '7F6000' ) ) );
		};
		$sh['cells'][ $r ][1] = qtb_c( $a['n'], array_merge( $bodyS, array( 'h' => 'center', 'b' => true ) ) );
		$sh['cells'][ $r ][2] = qtb_c( $a['idea'], array_merge( $bodyS, array( 'b' => true ) ) );
		$sh['cells'][ $r ][3] = qtb_c( (int) $a['kp'], array_merge( $bodyS, array( 'h' => 'center', 'fmt' => '#,##0' ) ) );
		$sh['cells'][ $r ][4] = qtb_c( $a['title'], array_merge( $bodyS, array( 'wrap' => true ) ) );
		$sh['cells'][ $r ][5] = qtb_c( $a['desc'], array_merge( $bodyS, array( 'wrap' => true ) ) );
		$sh['cells'][ $r ][6] = $a['source'] ? $link( $a['source'] ) : qtb_c( '', $bodyS );
		for ( $i = 0; $i < 5; $i++ ) {
			$o = $a['out'][ $i ] ?? null;
			$sh['cells'][ $r ][ 7 + 2 * $i ] = $o ? $link( $o['url'] ) : qtb_c( '', $bodyS );
			$sh['cells'][ $r ][ 8 + 2 * $i ] = qtb_c( $o ? $o['anchor'] : '', $bodyS );
			$n = $a['in'][ $i ] ?? null;
			$sh['cells'][ $r ][ 17 + 2 * $i ] = $n ? $link( $n['url'] ) : qtb_c( '', $bodyS );
			$sh['cells'][ $r ][ 18 + 2 * $i ] = qtb_c( $n ? $n['anchor'] : '', $bodyS );
		}
		$sh['cells'][ $r ][27] = qtb_c( $a['status'], array_merge( $bodyS, array( 'h' => 'center', 'bg' => 'FFF8F0' ) ) );
		$sh['cells'][ $r ][28] = qtb_c( $a['date'] ? qtb_jdate( $a['date'], 'short' ) : '', array_merge( $bodyS, array( 'h' => 'center' ) ) );
		$sh['cells'][ $r ][29] = $a['existing'] ? $link( $a['existing'] ) : qtb_c( '', $bodyS );
		$sh['cells'][ $r ][30] = qtb_c( $a['pillar'], $bodyS );
		$sh['cells'][ $r ][31] = qtb_c( (int) $a['words'], array_merge( $bodyS, array( 'h' => 'center' ) ) );
		$sh['cells'][ $r ][32] = qtb_c( implode( ' | ', (array) $a['outline'] ), array_merge( $bodyS, array( 'size' => 9 ) ) );
		$sh['heights'][ $r ]   = 34;
	}
	if ( ! $arts ) {
		$sh['cells'][1][1] = qtb_c( 'مقاله‌ای برای تقویم پیدا نشد: همه کلاسترهای آموزشی صفحه دارند یا کلاستر بلاگ نیست. ایده‌ها در تب Ideas است.', array( 'b' => true, 'color' => '20124D' ) );
		$sh['merges'][]    = 'A1:J1';
	}
	if ( $first ) {
		$sh['lists'][] = array( 'range' => 'AA' . $first . ':AA' . max( $first, $r ), 'values' => $stat );
		$sh['freeze']  = 'C1';
	}
	$sheets = array( $sh );

	// Ideas.
	$rows = array();
	foreach ( (array) ( $cal['ideas'] ?? array() ) as $x ) {
		$rows[] = array( qtb_c( $x['k'], qtb_xs_td( $x['pick'] ? array( 'b' => true, 'bg' => 'FCE5CD' ) : array() ) ), (int) $x['v'], $x['kind'], '' === $x['clarity'] ? '' : (int) $x['clarity'], (int) $x['expertise'], $x['journey'], (int) $x['cannibal'], (int) $x['diff'], qtb_c( (bool) $x['pick'], qtb_xs_td( array( 'h' => 'center' ) ) ), $x['pillar'], (int) $x['score'] );
	}
	$sheets[] = qtb_table_sheet( 'Ideas', array( 'کلمه کلیدی' => 34, 'میزان جستجو' => 11, 'ماهیت' => 9, 'دقت نتایج' => 9, 'میزان تخصص مورد نیاز' => 12, 'سفر مشتری' => 10, 'کنیبالیزیشن' => 10, 'درجه سختی' => 9, 'انتخاب' => 8, 'پیلار' => 18, 'امتیاز' => 8 ), $rows, 'E69138' );

	// B keyword: keyword cloud of every article (blocks per month).
	$bk = array( 'name' => 'B keyword', 'rtl' => true, 'tab' => qtb_xc( 'total' ), 'cols' => array( 1 => 2.5 ), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	$r0    = 2;
	$month = 0;
	$col   = 2;
	$maxr  = $r0;
	foreach ( $arts as $a ) {
		if ( $a['month'] !== $month ) {
			if ( $month ) {
				$r0  = $maxr + 3;
				$col = 2;
			}
			$month = $a['month'];
			$bk['cells'][ $r0 ][2] = qtb_c( 'M' . $month, array( 'b' => true, 'bg' => qtb_xc( 'sec_main' ), 'color' => 'FFFFFF', 'h' => 'center' ) );
			$bk['merges'][]        = 'B' . $r0 . ':C' . $r0;
			$maxr                  = $r0;
		}
		$r = $r0 + 2;
		$bk['cols'][ $col ]     = 30;
		$bk['cols'][ $col + 1 ] = 8.5;
		$bk['cols'][ $col + 2 ] = 2.5;
		$sum = 0;
		foreach ( $a['cloud'] as $j => $kv ) {
			$st = array( 'border' => qtb_xc( 'line' ), 'bg' => 'F3F3F3' );
			if ( 0 === $j ) {
				$st = array( 'border' => qtb_xc( 'line' ), 'bg' => qtb_xc( 'landing' ), 'b' => true );
			}
			$bk['cells'][ $r ][ $col ]     = qtb_c( $kv[0], $st );
			$bk['cells'][ $r ][ $col + 1 ] = qtb_c( (int) $kv[1], array_merge( $st, array( 'fmt' => '#,##0', 'h' => 'center' ) ) );
			$sum                          += (int) $kv[1];
			++$r;
		}
		$bk['cells'][ $r ][ $col ]     = qtb_c( 'جمع', array( 'b' => true, 'bg' => '93C47D', 'color' => 'FFFFFF', 'h' => 'center' ) );
		$bk['cells'][ $r ][ $col + 1 ] = qtb_c( $sum, array( 'b' => true, 'bg' => '93C47D', 'color' => 'FFFFFF', 'h' => 'center', 'fmt' => '#,##0' ) );
		$maxr = max( $maxr, $r );
		$col += 3;
	}
	$sheets[] = $bk;

	// Briefs.
	$rows = array();
	foreach ( $arts as $a ) {
		$g      = (array) ( ( $by_id[ $a['cluster'] ]['gap'] ?? array() ) );
		$w      = qtb_xs_td( array( 'wrap' => true, 'v' => 'top' ) );
		$rows[] = array( (int) $a['n'], 'M' . $a['month'], qtb_c( $a['title'], qtb_xs_td( array( 'b' => true, 'wrap' => true, 'v' => 'top' ) ) ), qtb_c( (string) ( $g['h1'] ?? '' ), $w ), qtb_c( (string) ( $g['meta'] ?? '' ), $w ), qtb_c( implode( "\n", (array) $a['outline'] ), $w ), qtb_c( implode( "\n", (array) $a['faq'] ), $w ), qtb_c( implode( "\n", (array) ( $g['gaps'] ?? array() ) ), $w ), (int) $a['words'], qtb_c( (string) ( $g['cta'] ?? '' ), $w ), (string) ( $g['schema'] ?? '' ), $a['source'] ? qtb_c( rawurldecode( $a['source'] ), qtb_xs_td( array( 'color' => '0000FF' ) ), $a['source'] ) : '' );
	}
	$sheets[] = qtb_table_sheet( 'Briefs', array( '#' => 5, 'ماه' => 6, 'عنوان' => 34, 'H1' => 30, 'متا' => 36, 'سرتیترها (H2)' => 44, 'سوالات متداول' => 38, 'شکاف‌ها و تمایز' => 38, 'کلمات' => 8, 'CTA' => 30, 'اسکیما' => 12, 'منبع رقیب' => 34 ), $rows, '8E7CC3' );

	return array( 'title' => 'Content Calendar — ' . $project['name'], 'sheets' => $sheets );
}

/**
 * Exports folder (protected from direct access).
 */
function qtb_export_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'ghotbnama-exports';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
	}
	return $dir;
}

/**
 * Build an export file.
 *
 * @param string $kind research|calendar
 * @return array|WP_Error {path, name}
 */
function qtb_export_xlsx( $project, $kind ) {
	qtb_raise_limits( 180 );
	$book = 'calendar' === $kind ? qtb_book_calendar( $project ) : qtb_book_research( $project );
	$slug = sanitize_title( qtb_host( $project['site_url'] ) ?: 'project-' . $project['id'] );
	$name = ( 'calendar' === $kind ? 'Content-Calendar-' : 'Keyword-Research-' ) . $slug . '-' . gmdate( 'Ymd' ) . '.xlsx';
	$path = qtb_export_dir() . '/' . wp_generate_password( 12, false ) . '-' . $name;
	$res  = qtb_xlsx_write( $book, $path );
	return is_wp_error( $res ) ? $res : array( 'path' => $path, 'name' => $name );
}
