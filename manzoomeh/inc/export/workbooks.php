<?php
/**
 * Workbook models of the two deliverables (rendered to XLSX and to Google Sheets):
 *
 *   Keyword research — dashboard, one sheet per pillar in the Delna layout
 *   (section headers, one 2-column block per cluster: keyword | volume, total
 *   row; green = main landing, blue = category / product landing, yellow =
 *   blog), keyword map, clusters, content gaps, cannibalization, competitors,
 *   more ideas (People Also Ask), taxonomy and removed keywords.
 *
 *   Content calendar — Calendar (M1, M2… with Writing Guide, Out Links, In
 *   Links, Status, Publish Date, Link), Ideas, B keyword (keyword cloud of each
 *   article) and Briefs.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Palette.
 */
function mzm_xc( $key ) {
	$c = array(
		'ink'      => '1B2B24',
		'brand'    => '0F3D2E',
		'brand2'   => '1E7B55',
		'mint'     => 'E3F2EA',
		'gold'     => 'C8A951',
		'muted'    => '6B7C74',
		'line'     => 'D9E4DE',
		'main'     => 'D9EAD3',
		'landing'  => 'C9DAF8',
		'blog'     => 'FFF2CC',
		'total'    => '6AA84F',
		'sec_main' => '93C47D',
		'sec_land' => '6D9EEB',
		'sec_blog' => 'F1C232',
		'grid'     => 'F3F3F3',
	);
	return $c[ $key ] ?? $key;
}

/**
 * Cell helper.
 */
function mzm_c( $v, $s = array(), $link = '' ) {
	$cell = array( 'v' => $v, 's' => $s );
	if ( $link ) {
		$cell['link'] = $link;
	}
	return $cell;
}

/**
 * Header style (dark green bar).
 */
function mzm_xs_head() {
	return array( 'b' => true, 'bg' => mzm_xc( 'brand' ), 'color' => 'FFFFFF', 'h' => 'center', 'wrap' => true, 'border' => '1E7B55' );
}

/**
 * Body style of tables.
 */
function mzm_xs_td( $extra = array() ) {
	return array_merge( array( 'border' => mzm_xc( 'line' ), 'v' => 'center' ), $extra );
}

/**
 * A simple table sheet.
 *
 * @param array $head [label => width]
 * @param array $rows rows of cells
 */
function mzm_table_sheet( $name, $head, $rows, $tab = '', $title = '' ) {
	$sh  = array( 'name' => $name, 'rtl' => true, 'tab' => $tab, 'cols' => array(), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	$r   = 1;
	$n   = count( $head );
	if ( $title ) {
		$sh['cells'][1][1] = mzm_c( $title, array( 'b' => true, 'size' => 13, 'color' => mzm_xc( 'brand' ) ) );
		$sh['merges'][]    = 'A1:' . mzm_ref( 1, max( 1, $n ) );
		$sh['heights'][1]  = 28;
		$r                 = 2;
	}
	$c = 1;
	foreach ( $head as $label => $w ) {
		$sh['cells'][ $r ][ $c ] = mzm_c( $label, mzm_xs_head() );
		$sh['cols'][ $c ]        = $w;
		++$c;
	}
	$sh['heights'][ $r ] = 30;
	$sh['freeze']        = 'A' . ( $r + 1 );
	$sh['filter']        = 'A' . $r . ':' . mzm_col( $n ) . $r;
	foreach ( $rows as $row ) {
		++$r;
		$c = 1;
		foreach ( $row as $cell ) {
			$sh['cells'][ $r ][ $c ] = is_array( $cell ) ? array_merge( array( 's' => mzm_xs_td() ), $cell ) : mzm_c( $cell, mzm_xs_td( is_numeric( $cell ) && ! is_string( $cell ) ? array( 'fmt' => '#,##0', 'h' => 'center' ) : array() ) );
			++$c;
		}
	}
	return $sh;
}

/**
 * Everything a workbook needs from the stored result.
 */
function mzm_result( $project ) {
	$pid = $project['id'];
	$r   = array(
		'keywords' => mzm_data_get( $pid, 'keywords', array() ),
		'clusters' => mzm_data_get( $pid, 'clusters', array() ),
		'pillars'  => mzm_data_get( $pid, 'pillars', array() ),
		'removed'  => mzm_data_get( $pid, 'removed', array() ),
		'calendar' => mzm_data_get( $pid, 'calendar', array() ),
		'meta'     => mzm_data_get( $pid, 'meta', array() ),
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
function mzm_coverage_label( $key ) {
	$l = array( 'new' => 'صفحه جدید لازم', 'optimize' => 'بهینه‌سازی صفحه موجود', 'ok' => 'رتبه در صفحه اول' );
	return $l[ $key ] ?? '';
}

/**
 * Next action of a cluster.
 */
function mzm_cluster_action( $c ) {
	$type = mzm_page_types()[ $c['page'] ][0] ?? '';
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
 * Keyword research workbook.
 */
function mzm_book_research( $project ) {
	$R      = mzm_result( $project );
	$kws    = $R['keywords'];
	$by_id  = $R['by_id'];
	$meta   = $R['meta'];
	$types  = mzm_page_types();
	$sheets = array();

	// Dashboard.
	$d = array( 'name' => 'داشبورد', 'rtl' => true, 'tab' => mzm_xc( 'brand' ), 'cols' => array( 1 => 2 ), 'cells' => array(), 'merges' => array(), 'heights' => array() );
	for ( $c = 2; $c <= 10; $c++ ) {
		$d['cols'][ $c ] = 17;
	}
	$d['cols'][2]     = 30;
	$d['cells'][1][2] = mzm_c( 'منظومه | کیورد ریسرچ ' . $project['name'], array( 'b' => true, 'size' => 16, 'color' => 'FFFFFF', 'bg' => mzm_xc( 'brand' ), 'h' => 'right' ) );
	$d['merges'][]    = 'B1:J1';
	$d['heights'][1]  = 38;
	$d['cells'][2][2] = mzm_c( sprintf( '%s · ساخته‌شده %s · %s', $project['site_url'], mzm_jdate( $meta['built'] ?? mzm_now() ), 'free' === ( $meta['serp_mode'] ?? 'free' ) ? 'خوشه‌بندی زبانی + هوش مصنوعی' : 'خوشه‌بندی بر اساس ۱۰ نتیجه اول گوگل' ), array( 'color' => mzm_xc( 'muted' ), 'size' => 9 ) );
	$d['merges'][]    = 'B2:J2';
	$kpis = array(
		'کلمات ورودی'                => (int) ( $meta['input'] ?? 0 ),
		'کلمات معتبر'                => (int) ( $meta['keywords'] ?? count( $kws ) ),
		'کلاستر (صفحه)'              => count( $R['clusters'] ),
		'پیلار'                      => count( $R['pillars'] ),
		'حجم جستجوی ماهانه'          => (int) ( $meta['volume'] ?? 0 ),
		'فروش بالقوه ماهانه (تومان)' => (int) ( $meta['revenue'] ?? 0 ),
		'صفحه جدید لازم'             => (int) ( $meta['coverage']['new'] ?? 0 ),
		'برد سریع'                   => (int) ( $meta['quick'] ?? 0 ),
		'کلمات حذف‌شده'              => (int) ( $meta['removed'] ?? 0 ),
	);
	$c = 2;
	foreach ( $kpis as $label => $val ) {
		$d['cells'][4][ $c ] = mzm_c( $label, array( 'size' => 9, 'color' => mzm_xc( 'muted' ), 'bg' => mzm_xc( 'mint' ), 'h' => 'center', 'wrap' => true ) );
		$d['cells'][5][ $c ] = mzm_c( $val, array( 'b' => true, 'size' => 15, 'color' => mzm_xc( 'brand' ), 'bg' => mzm_xc( 'mint' ), 'h' => 'center', 'fmt' => '#,##0' ) );
		++$c;
	}
	$d['heights'][4] = 30;
	$d['heights'][5] = 34;
	$r    = 7;
	$head = array( 'پیلار', 'حجم جستجو', 'کلاستر', 'لندینگ', 'مقاله', 'اولویت P1', 'صفحه جدید', 'ترافیک بالقوه', 'فروش بالقوه (تومان)' );
	foreach ( $head as $i => $h ) {
		$d['cells'][ $r ][ $i + 2 ] = mzm_c( $h, mzm_xs_head() );
	}
	foreach ( $R['pillars'] as $p ) {
		++$r;
		$vals = array( $p['name'], $p['vol'], count( $p['clusters'] ), (int) ( $p['landings'] ?? 0 ), (int) ( $p['blogs'] ?? 0 ), (int) ( $p['p1'] ?? 0 ), (int) ( $p['new'] ?? 0 ), (int) round( $p['traffic'] ?? 0 ), (int) round( $p['revenue'] ?? 0 ) );
		foreach ( $vals as $i => $v ) {
			$d['cells'][ $r ][ $i + 2 ] = mzm_c( $v, mzm_xs_td( 0 === $i ? array( 'b' => true ) : array( 'fmt' => '#,##0', 'h' => 'center' ) ) );
		}
	}
	$r += 2;
	$d['cells'][ $r ][2] = mzm_c( 'راهنمای رنگ‌ها', array( 'b' => true, 'color' => mzm_xc( 'brand' ) ) );
	foreach ( array( 'main' => 'لندینگ اصلی (صفحه دسته اصلی)', 'landing' => 'لندینگ دسته / نوع / محصول', 'blog' => 'مقاله بلاگ (پشتیبان فروش)' ) as $k => $label ) {
		++$r;
		$d['cells'][ $r ][2] = mzm_c( $label, array( 'bg' => mzm_xc( $k ), 'border' => mzm_xc( 'line' ) ) );
	}
	$r += 2;
	$d['cells'][ $r ][2] = mzm_c( 'برترین فرصت‌ها (بر اساس امتیاز فروش و بازگشت سرمایه)', array( 'b' => true, 'size' => 12, 'color' => mzm_xc( 'brand' ) ) );
	$d['merges'][]       = 'B' . $r . ':J' . $r;
	++$r;
	foreach ( array( 'کلاستر', 'پیلار', 'نوع صفحه', 'حجم', 'امتیاز', 'اولویت', 'وضعیت', 'اقدام بعدی' ) as $i => $h ) {
		$d['cells'][ $r ][ $i + 2 ] = mzm_c( $h, mzm_xs_head() );
	}
	$d['merges'][] = 'I' . $r . ':J' . $r;
	foreach ( array_slice( mzm_sort( $R['clusters'], 'score' ), 0, 20 ) as $cl ) {
		++$r;
		$vals = array( $cl['name'], $R['pillars'][ $cl['pillar'] ]['name'] ?? '', $types[ $cl['page'] ][0] ?? '', $cl['vol'], $cl['score'], $cl['priority'], mzm_coverage_label( $cl['coverage'] ), mzm_cluster_action( $cl ) );
		foreach ( $vals as $i => $v ) {
			$st = mzm_xs_td( 0 === $i ? array( 'b' => true, 'bg' => mzm_xc( 'blog' === $cl['page'] ? 'blog' : ( 'main' === $cl['page'] ? 'main' : 'landing' ) ) ) : ( is_int( $v ) ? array( 'fmt' => '#,##0', 'h' => 'center' ) : array() ) );
			$d['cells'][ $r ][ $i + 2 ] = mzm_c( $v, $st );
		}
		$d['merges'][] = 'I' . $r . ':J' . $r;
	}
	$sheets[] = $d;

	// Pillar sheets (Delna layout).
	foreach ( $R['pillars'] as $p ) {
		$sheets[] = mzm_pillar_sheet( $p, $by_id, $kws );
	}

	// Keyword map.
	$rows = array();
	foreach ( $R['pillars'] as $p ) {
		foreach ( $p['sections'] as $s ) {
			foreach ( $s['clusters'] as $cid ) {
				$cl = $by_id[ $cid ] ?? null;
				if ( ! $cl ) {
					continue;
				}
				foreach ( $cl['kws'] as $j => $m ) {
					$rows[] = array(
						$p['name'],
						$s['name'],
						mzm_c( $cl['name'], mzm_xs_td( 0 === $j ? array( 'b' => true ) : array() ) ),
						mzm_c( $kws[ $m ]['k'], mzm_xs_td( 0 === $j ? array( 'b' => true ) : array() ) ),
						(int) $kws[ $m ]['v'],
						0 === $j ? 'اصلی' : 'فرعی',
						mzm_intents()[ $cl['intent'] ] ?? '',
						mzm_c( $types[ $cl['page'] ][0] ?? '', mzm_xs_td( array( 'bg' => mzm_xc( 'blog' === $cl['page'] ? 'blog' : ( 'main' === $cl['page'] ? 'main' : 'landing' ) ) ) ) ),
						$cl['priority'],
						(int) $cl['score'],
						(int) $cl['diff'],
						$cl['target'] ? mzm_c( rawurldecode( $cl['target']['url'] ), mzm_xs_td( array( 'color' => '1155CC' ) ), $cl['target']['url'] ) : '',
						mzm_coverage_label( $cl['coverage'] ),
						$cl['serp'] && $cl['serp']['own_pos'] ? (int) $cl['serp']['own_pos'] : '',
					);
				}
			}
		}
	}
	$sheets[] = mzm_table_sheet( 'نقشه کلمات', array( 'پیلار' => 18, 'بخش' => 18, 'کلاستر (صفحه)' => 28, 'کلمه کلیدی' => 32, 'حجم' => 9, 'نقش' => 7, 'نیت' => 16, 'نوع صفحه' => 14, 'اولویت' => 8, 'امتیاز' => 8, 'سختی' => 8, 'صفحه هدف' => 36, 'وضعیت' => 18, 'جایگاه ما' => 9 ), $rows, mzm_xc( 'brand2' ) );

	// Clusters.
	$rows = array();
	foreach ( mzm_sort( $R['clusters'], 'score' ) as $cl ) {
		$rows[] = array(
			mzm_c( $cl['name'], mzm_xs_td( array( 'b' => true, 'bg' => mzm_xc( 'blog' === $cl['page'] ? 'blog' : ( 'main' === $cl['page'] ? 'main' : 'landing' ) ) ) ) ),
			$R['pillars'][ $cl['pillar'] ]['name'] ?? '',
			$cl['section'] ?? '',
			count( $cl['kws'] ),
			(int) $cl['vol'],
			mzm_intents()[ $cl['intent'] ] ?? '',
			$types[ $cl['page'] ][0] ?? '',
			$cl['priority'],
			(int) $cl['score'],
			(int) $cl['diff'],
			(int) round( $cl['value']['traffic'] ?? 0 ),
			(int) ( $cl['value']['revenue'] ?? 0 ),
			$cl['target'] ? mzm_c( rawurldecode( $cl['target']['url'] ), mzm_xs_td( array( 'color' => '1155CC' ) ), $cl['target']['url'] ) : '',
			mzm_coverage_label( $cl['coverage'] ),
			$cl['serp'] && $cl['serp']['own_pos'] ? (int) $cl['serp']['own_pos'] : '',
			! empty( $cl['quick_win'] ) ? 'بله' : '',
			$cl['serp'] ? implode( '، ', array_slice( $cl['serp']['competitors'], 0, 4 ) ) : '',
			implode( ' | ', array_map( function ( $i ) {
				return $i['text'];
			}, (array) ( $cl['issues'] ?? array() ) ) ),
			$cl['serp'] ? 'بله' : 'خیر',
			mzm_cluster_action( $cl ),
		);
	}
	$sheets[] = mzm_table_sheet( 'کلاسترها', array( 'کلاستر (کلمه اصلی)' => 28, 'پیلار' => 16, 'بخش' => 16, 'تعداد کلمات' => 9, 'حجم کل' => 10, 'نیت' => 16, 'نوع صفحه' => 13, 'اولویت' => 8, 'امتیاز' => 8, 'سختی' => 8, 'ترافیک بالقوه' => 11, 'فروش بالقوه (تومان)' => 15, 'صفحه هدف' => 34, 'وضعیت پوشش' => 18, 'جایگاه ما' => 9, 'برد سریع' => 9, 'رقبای برتر' => 36, 'هشدارها' => 40, 'بررسی با گوگل' => 10, 'اقدام بعدی' => 40 ), $rows, mzm_xc( 'brand2' ) );

	// Content gaps.
	$rows = array();
	foreach ( mzm_sort( $R['clusters'], 'score' ) as $cl ) {
		$g = (array) ( $cl['gap'] ?? array() );
		if ( empty( $g['angle'] ) && empty( $g['gaps'] ) ) {
			continue;
		}
		$w      = mzm_xs_td( array( 'wrap' => true, 'v' => 'top' ) );
		$rows[] = array(
			mzm_c( $cl['name'], mzm_xs_td( array( 'b' => true, 'v' => 'top' ) ) ),
			$types[ $cl['page'] ][0] ?? '',
			mzm_c( (string) ( $g['intent'] ?? '' ), $w ),
			mzm_c( (string) ( $g['angle'] ?? '' ), $w ),
			mzm_c( implode( "\n", (array) ( $g['must_cover'] ?? array() ) ), $w ),
			mzm_c( implode( "\n", (array) ( $g['gaps'] ?? array() ) ), mzm_xs_td( array( 'wrap' => true, 'v' => 'top', 'bg' => 'FDF3E1' ) ) ),
			mzm_c( implode( "\n", (array) ( $g['outline'] ?? array() ) ), $w ),
			mzm_c( implode( "\n", (array) ( $g['faq'] ?? array() ) ), $w ),
			mzm_c( (string) ( $g['title'] ?? '' ), $w ),
			mzm_c( (string) ( $g['meta'] ?? '' ), $w ),
			(int) ( $g['words'] ?? 0 ),
			mzm_c( (string) ( $g['cta'] ?? '' ), $w ),
			(string) ( $g['schema'] ?? '' ),
			empty( $g['serp'] ) ? 'دانش مدل' : '۱۰ نتیجه گوگل',
		);
	}
	if ( $rows ) {
		$sheets[] = mzm_table_sheet( 'شکاف محتوایی', array( 'کلاستر' => 24, 'نوع صفحه' => 12, 'نیت کاربر' => 30, 'زاویه برنده ما' => 34, 'باید پوشش داده شود' => 38, 'شکاف‌ها (رقبا ندارند)' => 40, 'سرتیترهای پیشنهادی' => 40, 'سوالات متداول' => 36, 'عنوان سئو' => 30, 'متا' => 36, 'کلمات' => 8, 'دعوت به اقدام' => 30, 'اسکیما' => 12, 'منبع تحلیل' => 12 ), $rows, 'E69138' );
	}

	// Cannibalization & warnings.
	$rows = array();
	foreach ( $R['clusters'] as $cl ) {
		foreach ( (array) ( $cl['issues'] ?? array() ) as $is ) {
			$rows[] = array( mzm_c( $cl['name'], mzm_xs_td( array( 'b' => true ) ) ), mzm_c( $is['text'], mzm_xs_td( array( 'wrap' => true ) ) ), mzm_c( implode( "\n", array_map( 'rawurldecode', (array) $is['urls'] ) ), mzm_xs_td( array( 'wrap' => true, 'color' => '1155CC' ) ) ), (int) $cl['vol'] );
		}
	}
	if ( $rows ) {
		$sheets[] = mzm_table_sheet( 'کنیبالیزیشن', array( 'کلاستر' => 28, 'مشکل' => 46, 'آدرس‌ها' => 60, 'حجم' => 10 ), $rows, 'CC0000' );
	}

	// Competitors.
	$dom = array();
	foreach ( $R['clusters'] as $cl ) {
		if ( ! $cl['serp'] ) {
			continue;
		}
		foreach ( $cl['serp']['competitors'] as $rank => $dm ) {
			if ( ! isset( $dom[ $dm ] ) ) {
				$dom[ $dm ] = array( 'n' => 0, 'vol' => 0, 'land' => 0, 'blog' => 0 );
			}
			$dom[ $dm ]['n']++;
			$dom[ $dm ]['vol'] += $cl['vol'];
			$dom[ $dm ][ 'blog' === $cl['page'] ? 'blog' : 'land' ]++;
		}
	}
	if ( $dom ) {
		uasort( $dom, function ( $a, $b ) {
			return $b['vol'] <=> $a['vol'];
		} );
		$checked = max( 1, count( array_filter( $R['clusters'], function ( $c ) {
			return ! empty( $c['serp'] );
		} ) ) );
		$rows = array();
		foreach ( array_slice( $dom, 0, 150, true ) as $dm => $x ) {
			$rows[] = array( mzm_c( $dm, mzm_xs_td( array( 'b' => true ) ), 'https://' . $dm ), $x['n'], mzm_c( round( $x['n'] / $checked, 3 ), mzm_xs_td( array( 'fmt' => '0%', 'h' => 'center' ) ) ), $x['vol'], $x['land'], $x['blog'], mzm_domain_in( $dm, 'market_domains' ) ? 'بازارگاه' : ( mzm_domain_in( $dm, 'strong' ) ? 'دامنه قوی' : '' ) );
		}
		$sheets[] = mzm_table_sheet( 'رقبا', array( 'دامنه' => 30, 'حضور در ۱۰ نتیجه اول' => 14, 'سهم از کلاسترها' => 12, 'حجم جستجوی پوشش‌داده' => 16, 'در لندینگ‌ها' => 12, 'در مقاله‌ها' => 12, 'نوع' => 12 ), $rows, '674EA7' );
	}

	// More ideas: People Also Ask + related searches.
	$rows = array();
	$seen = array();
	foreach ( mzm_sort( $R['clusters'], 'score' ) as $cl ) {
		if ( ! $cl['serp'] ) {
			continue;
		}
		foreach ( array( 'paa' => 'سوال کاربران (PAA)', 'related' => 'جستجوی مرتبط' ) as $k => $label ) {
			foreach ( (array) $cl['serp'][ $k ] as $q ) {
				$key = mzm_key( $q );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$rows[]       = array( $q, $label, $cl['name'], $R['pillars'][ $cl['pillar'] ]['name'] ?? '' );
			}
		}
	}
	if ( $rows ) {
		$sheets[] = mzm_table_sheet( 'ایده‌های بیشتر', array( 'ایده / سوال' => 48, 'منبع' => 18, 'کلاستر مرتبط' => 28, 'پیلار' => 18 ), $rows, mzm_xc( 'gold' ) );
	}

	// Taxonomy.
	$rows = array();
	foreach ( $R['pillars'] as $p ) {
		$rows[] = array( mzm_c( $p['name'], mzm_xs_td( array( 'b' => true ) ) ), mzm_c( implode( '، ', array_column( $p['sections'], 'name' ) ), mzm_xs_td( array( 'wrap' => true ) ) ), count( $p['clusters'] ), (int) ( $p['landings'] ?? 0 ), (int) ( $p['blogs'] ?? 0 ), (int) $p['vol'] );
	}
	$sheets[] = mzm_table_sheet( 'دسته بندی', array( 'پیلار' => 22, 'بخش‌ها' => 60, 'کلاستر' => 10, 'لندینگ' => 10, 'مقاله' => 10, 'حجم' => 12 ), $rows, mzm_xc( 'brand2' ) );

	// Removed keywords.
	$rows    = array();
	$reasons = mzm_remove_reasons();
	foreach ( mzm_sort( $R['removed'], 'v' ) as $x ) {
		$rows[] = array( $x['k'], (int) $x['v'], $reasons[ $x['r'] ] ?? $x['r'], (string) ( $x['d'] ?? '' ), ! empty( $x['ai'] ) ? 'هوش مصنوعی' : ( 'manual' === $x['r'] ? 'دستی' : 'قانون' ) );
	}
	$sheets[] = mzm_table_sheet( 'حذف‌شده‌ها', array( 'کلمه' => 34, 'حجم' => 10, 'دلیل حذف' => 30, 'جزئیات' => 26, 'تشخیص' => 12 ), $rows, '999999' );

	return array( 'title' => 'Keyword Research — ' . $project['name'], 'sheets' => $sheets );
}

/**
 * One pillar sheet in the Delna layout.
 */
function mzm_pillar_sheet( $p, $by_id, $kws ) {
	$sh  = array( 'name' => $p['name'], 'rtl' => true, 'tab' => mzm_xc( 'total' ), 'cols' => array( 1 => 2.5 ), 'cells' => array(), 'merges' => array(), 'heights' => array( 1 => 30, 2 => 24, 3 => 22, 4 => 16 ), 'freeze' => 'A5' );
	$col = 2;
	$secfill = array( 'main' => mzm_xc( 'sec_main' ), 'landing' => mzm_xc( 'sec_land' ), 'blog' => mzm_xc( 'sec_blog' ) );
	$fill    = array( 'main' => mzm_xc( 'main' ), 'category' => mzm_xc( 'landing' ), 'product' => mzm_xc( 'landing' ), 'blog' => mzm_xc( 'blog' ) );
	$max_row = 5;
	foreach ( $p['sections'] as $s ) {
		$start = $col;
		foreach ( $s['clusters'] as $cid ) {
			$c = $by_id[ $cid ] ?? null;
			if ( ! $c ) {
				continue;
			}
			$f = $fill[ $c['page'] ] ?? mzm_xc( 'landing' );
			$sh['cols'][ $col ]     = 30;
			$sh['cols'][ $col + 1 ] = 8.5;
			$sh['cols'][ $col + 2 ] = 2.5;
			$sh['cells'][3][ $col ] = mzm_c( $c['name'], array( 'b' => true, 'bg' => $f, 'h' => 'center', 'border' => 'B7C9BF', 'wrap' => true ) );
			$sh['cells'][3][ $col + 1 ] = mzm_c( '', array( 'bg' => $f, 'border' => 'B7C9BF' ) );
			$sh['merges'][] = mzm_ref( 3, $col ) . ':' . mzm_ref( 3, $col + 1 );
			$badge = sprintf( '%s · امتیاز %s · %s', $c['priority'], $c['score'], mzm_coverage_label( $c['coverage'] ) );
			$sh['cells'][4][ $col ] = mzm_c( $badge, array( 'size' => 8, 'color' => mzm_xc( 'muted' ), 'h' => 'center' ) );
			$sh['merges'][] = mzm_ref( 4, $col ) . ':' . mzm_ref( 4, $col + 1 );
			$r = 5;
			foreach ( $c['kws'] as $j => $m ) {
				$st  = array( 'border' => mzm_xc( 'line' ) );
				$st2 = array( 'border' => mzm_xc( 'line' ), 'fmt' => '#,##0', 'h' => 'center' );
				if ( 0 === $j ) {
					$st['b']   = true;
					$st['bg']  = $f;
					$st2['b']  = true;
					$st2['bg'] = $f;
				}
				$sh['cells'][ $r ][ $col ]     = mzm_c( $kws[ $m ]['k'], $st );
				$sh['cells'][ $r ][ $col + 1 ] = mzm_c( (int) $kws[ $m ]['v'], $st2 );
				++$r;
			}
			$sh['cells'][ $r ][ $col ]     = mzm_c( 'جمع', array( 'b' => true, 'bg' => mzm_xc( 'total' ), 'color' => 'FFFFFF', 'h' => 'center' ) );
			$sh['cells'][ $r ][ $col + 1 ] = mzm_c( (int) $c['vol'], array( 'b' => true, 'bg' => mzm_xc( 'total' ), 'color' => 'FFFFFF', 'h' => 'center', 'fmt' => '#,##0' ) );
			$max_row = max( $max_row, $r );
			$col    += 3;
		}
		if ( $col > $start ) {
			$sh['cells'][2][ $start ] = mzm_c( $s['name'], array( 'b' => true, 'size' => 11, 'bg' => $secfill[ $s['kind'] ] ?? mzm_xc( 'sec_land' ), 'color' => 'blog' === $s['kind'] ? mzm_xc( 'ink' ) : 'FFFFFF', 'h' => 'center' ) );
			$end = $col - 2;
			if ( $end > $start ) {
				$sh['merges'][] = mzm_ref( 2, $start ) . ':' . mzm_ref( 2, $end );
			}
		}
	}
	$last = max( 3, $col - 2 );
	$sh['cells'][1][2] = mzm_c( sprintf( '%s — حجم کل %s · %s کلاستر', $p['name'], mzm_num( $p['vol'] ), mzm_num( count( $p['clusters'] ) ) ), array( 'b' => true, 'size' => 13, 'bg' => mzm_xc( 'brand' ), 'color' => 'FFFFFF', 'h' => 'right' ) );
	if ( $last > 2 ) {
		$sh['merges'][] = 'B1:' . mzm_ref( 1, $last );
	}
	return $sh;
}

/**
 * Content calendar workbook.
 */
function mzm_book_calendar( $project ) {
	$R     = mzm_result( $project );
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
			$sh['cells'][ $r ][1] = mzm_c( 'M' . $month, $g_style( '20124D' ) );
			$sh['merges'][]       = 'A' . $r . ':A' . ( $r + 1 );
			$sh['cells'][ $r ][2] = mzm_c( 'Writing Guide', $g_style( 'A64D79' ) );
			$sh['merges'][]       = 'B' . $r . ':F' . $r;
			$sh['cells'][ $r ][7] = mzm_c( 'Out Links', $g_style( 'E06666' ) );
			$sh['merges'][]       = 'G' . $r . ':P' . $r;
			$sh['cells'][ $r ][17] = mzm_c( 'In Links', $g_style( '8E7CC3' ) );
			$sh['merges'][]        = 'Q' . $r . ':Z' . $r;
			$sh['cells'][ $r ][27] = mzm_c( 'Publish', $g_style( 'E69138' ) );
			$sh['merges'][]        = 'AA' . $r . ':AC' . $r;
			$sh['cells'][ $r ][30] = mzm_c( 'منظومه', $g_style( mzm_xc( 'brand' ) ) );
			$sh['merges'][]        = 'AD' . $r . ':AF' . $r;
			$sh['heights'][ $r ]   = 24;
			++$r;
			foreach ( $sub as $i => $label ) {
				$col = $i + 2;
				$bg  = $col <= 6 ? 'D5A6BD' : ( $col <= 16 ? 'F4CCCC' : ( $col <= 26 ? 'D9D2E9' : ( $col <= 29 ? 'FCE5CD' : mzm_xc( 'mint' ) ) ) );
				$sh['cells'][ $r ][ $col ] = mzm_c( $label, array( 'b' => true, 'bg' => $bg, 'h' => 'center', 'border' => 'FFFFFF', 'color' => '20124D' ) );
			}
			if ( ! $first ) {
				$first = $r + 1;
			}
		}
		++$r;
		$c     = $by_id[ $a['cluster'] ] ?? null;
		$bodyS = array( 'border' => 'E6E0EC', 'v' => 'center' );
		$link  = function ( $u ) use ( $bodyS ) {
			return preg_match( '#^https?://#', (string) $u ) ? mzm_c( rawurldecode( $u ), array_merge( $bodyS, array( 'color' => '0000FF' ) ), $u ) : mzm_c( (string) $u, array_merge( $bodyS, array( 'color' => '7F6000' ) ) );
		};
		$sh['cells'][ $r ][1] = mzm_c( $a['n'], array_merge( $bodyS, array( 'h' => 'center', 'b' => true ) ) );
		$sh['cells'][ $r ][2] = mzm_c( $a['idea'], array_merge( $bodyS, array( 'b' => true ) ) );
		$sh['cells'][ $r ][3] = mzm_c( (int) $a['kp'], array_merge( $bodyS, array( 'h' => 'center', 'fmt' => '#,##0' ) ) );
		$sh['cells'][ $r ][4] = mzm_c( $a['title'], array_merge( $bodyS, array( 'wrap' => true ) ) );
		$sh['cells'][ $r ][5] = mzm_c( $a['desc'], array_merge( $bodyS, array( 'wrap' => true ) ) );
		$sh['cells'][ $r ][6] = $a['source'] ? $link( $a['source'] ) : mzm_c( '', $bodyS );
		for ( $i = 0; $i < 5; $i++ ) {
			$o = $a['out'][ $i ] ?? null;
			$sh['cells'][ $r ][ 7 + 2 * $i ] = $o ? $link( $o['url'] ) : mzm_c( '', $bodyS );
			$sh['cells'][ $r ][ 8 + 2 * $i ] = mzm_c( $o ? $o['anchor'] : '', $bodyS );
			$n = $a['in'][ $i ] ?? null;
			$sh['cells'][ $r ][ 17 + 2 * $i ] = $n ? $link( $n['url'] ) : mzm_c( '', $bodyS );
			$sh['cells'][ $r ][ 18 + 2 * $i ] = mzm_c( $n ? $n['anchor'] : '', $bodyS );
		}
		$sh['cells'][ $r ][27] = mzm_c( $a['status'], array_merge( $bodyS, array( 'h' => 'center', 'bg' => 'FFF8F0' ) ) );
		$sh['cells'][ $r ][28] = mzm_c( $a['date'] ? mzm_jdate( $a['date'], 'short' ) : '', array_merge( $bodyS, array( 'h' => 'center' ) ) );
		$sh['cells'][ $r ][29] = $a['existing'] ? $link( $a['existing'] ) : mzm_c( '', $bodyS );
		$sh['cells'][ $r ][30] = mzm_c( $a['pillar'], $bodyS );
		$sh['cells'][ $r ][31] = mzm_c( (int) $a['words'], array_merge( $bodyS, array( 'h' => 'center' ) ) );
		$sh['cells'][ $r ][32] = mzm_c( implode( ' | ', (array) $a['outline'] ), array_merge( $bodyS, array( 'size' => 9 ) ) );
		$sh['heights'][ $r ]   = 34;
	}
	if ( $first ) {
		$sh['lists'][] = array( 'range' => 'AA' . $first . ':AA' . max( $first, $r ), 'values' => $stat );
		$sh['freeze']  = 'C1';
	}
	$sheets = array( $sh );

	// Ideas.
	$rows = array();
	foreach ( (array) ( $cal['ideas'] ?? array() ) as $x ) {
		$rows[] = array( mzm_c( $x['k'], mzm_xs_td( $x['pick'] ? array( 'b' => true, 'bg' => 'FCE5CD' ) : array() ) ), (int) $x['v'], $x['kind'], '' === $x['clarity'] ? '' : (int) $x['clarity'], (int) $x['expertise'], $x['journey'], (int) $x['cannibal'], (int) $x['diff'], mzm_c( (bool) $x['pick'], mzm_xs_td( array( 'h' => 'center' ) ) ), $x['pillar'], (int) $x['score'] );
	}
	$sheets[] = mzm_table_sheet( 'Ideas', array( 'کلمه کلیدی' => 34, 'میزان جستجو' => 11, 'ماهیت' => 9, 'دقت نتایج' => 9, 'میزان تخصص مورد نیاز' => 12, 'سفر مشتری' => 10, 'کنیبالیزیشن' => 10, 'درجه سختی' => 9, 'انتخاب' => 8, 'پیلار' => 18, 'امتیاز' => 8 ), $rows, 'E69138' );

	// B keyword: keyword cloud of every article (blocks per month).
	$bk = array( 'name' => 'B keyword', 'rtl' => true, 'tab' => mzm_xc( 'total' ), 'cols' => array( 1 => 2.5 ), 'cells' => array(), 'merges' => array(), 'heights' => array() );
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
			$bk['cells'][ $r0 ][2] = mzm_c( 'M' . $month, array( 'b' => true, 'bg' => mzm_xc( 'sec_main' ), 'color' => 'FFFFFF', 'h' => 'center' ) );
			$bk['merges'][]        = 'B' . $r0 . ':C' . $r0;
			$maxr                  = $r0;
		}
		$r = $r0 + 2;
		$bk['cols'][ $col ]     = 30;
		$bk['cols'][ $col + 1 ] = 8.5;
		$bk['cols'][ $col + 2 ] = 2.5;
		$sum = 0;
		foreach ( $a['cloud'] as $j => $kv ) {
			$st = array( 'border' => mzm_xc( 'line' ), 'bg' => 'F3F3F3' );
			if ( 0 === $j ) {
				$st = array( 'border' => mzm_xc( 'line' ), 'bg' => mzm_xc( 'main' ), 'b' => true );
			}
			$bk['cells'][ $r ][ $col ]     = mzm_c( $kv[0], $st );
			$bk['cells'][ $r ][ $col + 1 ] = mzm_c( (int) $kv[1], array_merge( $st, array( 'fmt' => '#,##0', 'h' => 'center' ) ) );
			$sum                          += (int) $kv[1];
			++$r;
		}
		$bk['cells'][ $r ][ $col ]     = mzm_c( 'جمع', array( 'b' => true, 'bg' => '93C47D', 'color' => 'FFFFFF', 'h' => 'center' ) );
		$bk['cells'][ $r ][ $col + 1 ] = mzm_c( $sum, array( 'b' => true, 'bg' => '93C47D', 'color' => 'FFFFFF', 'h' => 'center', 'fmt' => '#,##0' ) );
		$maxr = max( $maxr, $r );
		$col += 3;
	}
	$sheets[] = $bk;

	// Briefs.
	$rows = array();
	foreach ( $arts as $a ) {
		$g      = (array) ( ( $by_id[ $a['cluster'] ]['gap'] ?? array() ) );
		$w      = mzm_xs_td( array( 'wrap' => true, 'v' => 'top' ) );
		$rows[] = array( (int) $a['n'], 'M' . $a['month'], mzm_c( $a['title'], mzm_xs_td( array( 'b' => true, 'wrap' => true, 'v' => 'top' ) ) ), mzm_c( (string) ( $g['h1'] ?? '' ), $w ), mzm_c( (string) ( $g['meta'] ?? '' ), $w ), mzm_c( implode( "\n", (array) $a['outline'] ), $w ), mzm_c( implode( "\n", (array) $a['faq'] ), $w ), mzm_c( implode( "\n", (array) ( $g['gaps'] ?? array() ) ), $w ), (int) $a['words'], mzm_c( (string) ( $g['cta'] ?? '' ), $w ), (string) ( $g['schema'] ?? '' ), $a['source'] ? mzm_c( rawurldecode( $a['source'] ), mzm_xs_td( array( 'color' => '0000FF' ) ), $a['source'] ) : '' );
	}
	$sheets[] = mzm_table_sheet( 'Briefs', array( '#' => 5, 'ماه' => 6, 'عنوان' => 34, 'H1' => 30, 'متا' => 36, 'سرتیترها (H2)' => 44, 'سوالات متداول' => 38, 'شکاف‌ها و تمایز' => 38, 'کلمات' => 8, 'CTA' => 30, 'اسکیما' => 12, 'منبع رقیب' => 34 ), $rows, '8E7CC3' );

	return array( 'title' => 'Content Calendar — ' . $project['name'], 'sheets' => $sheets );
}

/**
 * Exports folder (protected from direct access).
 */
function mzm_export_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'manzoomeh-exports';
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
function mzm_export_xlsx( $project, $kind ) {
	mzm_raise_limits( 180 );
	$book = 'calendar' === $kind ? mzm_book_calendar( $project ) : mzm_book_research( $project );
	$slug = sanitize_title( mzm_host( $project['site_url'] ) ?: 'project-' . $project['id'] );
	$name = ( 'calendar' === $kind ? 'Content-Calendar-' : 'Keyword-Research-' ) . $slug . '-' . gmdate( 'Ymd' ) . '.xlsx';
	$path = mzm_export_dir() . '/' . wp_generate_password( 12, false ) . '-' . $name;
	$res  = mzm_xlsx_write( $book, $path );
	return is_wp_error( $res ) ? $res : array( 'path' => $path, 'name' => $name );
}
