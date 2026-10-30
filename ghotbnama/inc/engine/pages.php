<?php
/**
 * Page types and the page-type decision.
 *
 * Four kinds of page (the agency's own taxonomy):
 *   landing  لندینگ  — a custom page: service, campaign, local branch, or a topic no store structure fits.
 *   archive  آرشیو   — a store listing: product category, brand, tag or attribute (filter) page.
 *   product  محصول   — one product page.
 *   article  مقاله   — a blog post (guide, how-to, comparison / «best of» list).
 *
 * The decision combines what Google rewards (types of the top 10), what the store
 * has (matching products and archive terms from the connector), the business
 * profile (services, branches) and the intent, and always comes with a reason.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page types: key => [label, cell color].
 */
function qtb_page_types() {
	return array(
		'landing' => array( 'لندینگ', 'D9EAD3' ),
		'archive' => array( 'آرشیو', 'C9DAF8' ),
		'product' => array( 'محصول', 'FCE5CD' ),
		'article' => array( 'مقاله', 'FFF2CC' ),
	);
}

/**
 * Archive kinds.
 */
function qtb_archive_types() {
	return array(
		'category'  => 'دسته',
		'brand'     => 'برند',
		'tag'       => 'برچسب',
		'attribute' => 'ویژگی',
	);
}

/**
 * Page type of results stored by older versions.
 */
function qtb_page_norm( $page ) {
	$map = array( 'main' => 'archive', 'category' => 'archive', 'blog' => 'article' );
	$page = $map[ $page ] ?? $page;
	return isset( qtb_page_types()[ $page ] ) ? $page : 'archive';
}

/**
 * Full label of a cluster's page («آرشیو · برند»).
 */
function qtb_page_label( $c ) {
	$t = qtb_page_types()[ $c['page'] ][0] ?? $c['page'];
	if ( 'archive' === $c['page'] && ! empty( $c['archive'] ) && 'category' !== $c['archive'] ) {
		$t .= ' · ' . ( qtb_archive_types()[ $c['archive'] ] ?? $c['archive'] );
	}
	return $t;
}

/**
 * Options of a page select: «landing», «archive:brand», «product», «article»…
 */
function qtb_page_options() {
	$out = array( 'landing' => 'لندینگ' );
	foreach ( qtb_archive_types() as $k => $l ) {
		$out[ 'archive:' . $k ] = 'آرشیو · ' . $l;
	}
	$out['product'] = 'محصول';
	$out['article'] = 'مقاله';
	return $out;
}

/**
 * Select value of a cluster's page.
 */
function qtb_page_value( $c ) {
	return 'archive' === $c['page'] ? 'archive:' . ( $c['archive'] ?? 'category' ) : $c['page'];
}

/**
 * Parse a page select value → [page, archive kind] or null.
 */
function qtb_page_value_parse( $v ) {
	list( $page, $kind ) = array_pad( explode( ':', $v, 2 ), 2, '' );
	if ( ! isset( qtb_page_types()[ $page ] ) ) {
		return null;
	}
	$kind = 'archive' === $page ? ( isset( qtb_archive_types()[ $kind ] ) ? $kind : 'category' ) : '';
	return array( $page, $kind );
}

/**
 * Is a cluster an article?
 */
function qtb_is_article( $c ) {
	return 'article' === ( $c['page'] ?? '' );
}

/**
 * Store index for page decisions: products and archive terms by core words.
 *
 * @param array $inventory Site items [{type, url, title, cats?, tax?, brand?}].
 * @return array {products: [[core, url, title]], terms: [[core, url, title, kind]], brands: token set}
 */
function qtb_store_index( $inventory ) {
	$soft = qtb_lex_set( 'soft' );
	$stop = qtb_lex_set( 'stop' );
	$core = function ( $text ) use ( $soft, $stop ) {
		return array_values( array_unique( array_filter( qtb_tokens( $text ), function ( $t ) use ( $soft, $stop ) {
			return ! isset( $soft[ $t ] ) && ! isset( $stop[ $t ] ) && mb_strlen( $t ) > 1;
		} ) ) );
	};
	$out = array( 'products' => array(), 'terms' => array(), 'brands' => array(), 'has_store' => false );
	foreach ( (array) $inventory as $it ) {
		if ( 'product' === $it['type'] ) {
			$out['products'][]  = array( $core( $it['title'] . ' ' . implode( ' ', (array) ( $it['cats'] ?? array() ) ) ), $it['url'], $it['title'] );
			$out['has_store']   = true;
		} elseif ( 'category' === $it['type'] ) {
			$tax  = (string) ( $it['tax'] ?? 'product_cat' );
			$kind = ! empty( $it['brand'] ) ? 'brand' : ( 'product_tag' === $tax || 'post_tag' === $tax ? 'tag' : ( 0 === strpos( $tax, 'pa_' ) ? 'attribute' : 'category' ) );
			$out['terms'][] = array( $core( $it['title'] ), $it['url'], $it['title'], $kind );
			if ( 'brand' === $kind ) {
				foreach ( qtb_tokens( $it['title'] ) as $t ) {
					$out['brands'][ $t ] = true;
				}
			}
		}
	}
	return $out;
}

/**
 * Decide the page type of a cluster.
 *
 * @param array $c       Cluster (intent, core, kws, serp).
 * @param array $keywords Annotated keywords.
 * @param array $idx     Store index (qtb_store_index).
 * @param array $profile Project profile (services, locations…).
 * @return array {page, archive, reason, products, term_url}
 */
function qtb_page_decide( $c, $keywords, $idx, $profile = array() ) {
	$core  = (array) $c['core'];
	$text  = '';
	$brand = '';
	foreach ( array_slice( $c['kws'], 0, 8 ) as $m ) {
		$text .= ' ' . $keywords[ $m ]['nk'];
		if ( '' === $brand && ! empty( $keywords[ $m ]['brand_tok'] ) ) {
			$brand = (string) $keywords[ $m ]['brand_tok'];
		}
	}
	$text .= ' ';
	foreach ( $core as $t ) {
		if ( '' === $brand && isset( $idx['brands'][ $t ] ) ) {
			$brand = $t;
		}
	}
	$sh       = $c['serp']['types'] ?? array();
	$n        = ! empty( $c['serp'] );
	$art      = ( $sh['article'] ?? 0 ) + ( $sh['video'] ?? 0 ) + ( $sh['forum'] ?? 0 ) + ( $sh['wiki'] ?? 0 );
	$cat      = ( $sh['category'] ?? 0 ) + ( $sh['marketplace'] ?? 0 );
	$prod     = $sh['product'] ?? 0;
	$home     = ( $sh['home'] ?? 0 ) + ( $sh['other'] ?? 0 );
	$pct      = function ( $x ) {
		return qtb_num( round( 10 * $x ) );
	};
	$local    = qtb_lex_hit( trim( $text ), 'local' );
	$service  = qtb_lex_hit( trim( $text ), 'service' );
	$intent   = (string) ( $c['intent'] ?? 'm' );
	// Matching products and archive terms (all core words of the cluster in the title).
	$matches  = array();
	foreach ( $idx['products'] as $p ) {
		if ( $core && ! array_diff( $core, $p[0] ) ) {
			$matches[] = $p;
		}
	}
	$term = null;
	foreach ( $idx['terms'] as $t ) {
		if ( $core && ! array_diff( $core, $t[0] ) && count( $t[0] ) <= count( $core ) + 1 ) {
			if ( null === $term || count( $t[0] ) < count( $term[0] ) ) {
				$term = $t;
			}
		}
	}
	$np  = count( $matches );
	$out = array( 'page' => 'archive', 'archive' => 'category', 'reason' => '', 'products' => $np, 'term_url' => $term ? $term[1] : '' );

	// Learning words («چیست، طرز، نحوه، آموزش…») in the head or most members.
	$learn = 0;
	$cnt   = 0;
	foreach ( array_slice( $c['kws'], 0, 8 ) as $m ) {
		++$cnt;
		$learn += 'i' === ( $keywords[ $m ]['intent0'] ?? '' ) || qtb_lex_hit( $keywords[ $m ]['nk'], 'informational' ) ? 1 : 0;
	}
	$head_i = isset( $c['kws'][0] ) && ( 'i' === ( $keywords[ $c['kws'][0] ]['intent0'] ?? '' ) || qtb_lex_hit( $keywords[ $c['kws'][0] ]['nk'], 'informational' ) );
	$lex_i  = $cnt && ( $head_i || $learn / $cnt >= 0.5 ) && ! ( $n && $cat + $prod >= 0.6 );

	// 1. Learning intent.
	if ( 'i' === $intent || $lex_i || ( $n && $art >= 0.6 ) ) {
		$out['page']   = 'article';
		if ( $n && $art >= 0.6 ) {
			$out['reason'] = sprintf( 'گوگل برای این جستجو %s نتیجه از ۱۰ را به مقاله و محتوای آموزشی داده است.', $pct( $art ) );
		} else {
			$out['reason'] = 'نیت جستجو آموزشی است (کلمات پرسشی و آموزشی)؛ مقاله' . ( $n ? sprintf( '؛ %s نتیجه از ۱۰ گوگل هم محتوای آموزشی است.', $pct( $art ) ) : '.' );
		}
		return $out;
	}
	// 2. Choosing: «بهترین / مقایسه / تفاوت».
	if ( 'c' === $intent || qtb_lex_hit( trim( $text ), 'comparison' ) ) {
		if ( $n && $cat >= 0.5 ) {
			$out['reason'] = sprintf( 'جستجوی مقایسه‌ای است، ولی گوگل %s نتیجه از ۱۰ را به صفحات دسته و فروشگاه داده؛ آرشیو با جدول مقایسه.', $pct( $cat ) );
			return $out;
		}
		$out['page']   = 'article';
		$out['reason'] = 'جستجوی مقایسه و انتخاب («بهترین…»)؛ مقاله لیستی/مقایسه‌ای که به صفحات فروش لینک می‌دهد.' . ( $n ? sprintf( ' (%s نتیجه از ۱۰ مقاله‌اند)', $pct( $art ) ) : '' );
		return $out;
	}
	// 3. Service queries.
	if ( $service ) {
		$offered = ! empty( $profile['services'] ) && false !== mb_strpos( ' ' . qtb_norm_key( implode( ' ', (array) $profile['services'] ) ) . ' ', $service );
		// With a filled profile, a service that is not listed is not offered; without one, Google decides.
		$known   = ! empty( $profile['services'] ) || '' !== trim( (string) ( $profile['sells'] ?? '' ) );
		if ( $offered || ( ! $known && $n && $home >= 0.4 ) ) {
			$out['page']   = 'landing';
			$out['reason'] = $offered ? 'جستجوی خدمات است و این خدمت در پروفایل کسب‌وکار ثبت شده؛ لندینگ خدمات.' : sprintf( 'جستجوی خدمات است و گوگل %s نتیجه از ۱۰ را به صفحات اختصاصی/اصلی سایت‌ها داده؛ لندینگ.', $pct( $home ) );
			return $out;
		}
		$out['page']   = 'article';
		$out['reason'] = 'جستجوی خدمات («' . $service . '») است ولی این خدمت در پروفایل کسب‌وکار نیست؛ مقاله آموزشی، یا حذف اگر به کار نمی‌آید. (اگر این خدمت را ارائه می‌دهید در پروفایل اضافه کنید تا لندینگ شود.)';
		return $out;
	}
	// 4. Local queries.
	if ( $local ) {
		$has = ! empty( $profile['locations'] ) && false !== mb_strpos( ' ' . qtb_norm_key( implode( ' ', (array) $profile['locations'] ) ) . ' ', $local );
		if ( $has ) {
			$out['page']   = 'landing';
			$out['reason'] = 'جستجوی محلی («' . $local . '») و در پروفایل این شهر را دارید؛ لندینگ محلی.';
			return $out;
		}
		$out['reason'] = 'جستجوی محلی («' . $local . '») است ولی شعبه‌ای در این شهر ثبت نشده؛ همان آرشیو اصلی با ذکر ارسال به این شهر کافی است.';
		return $out;
	}
	// 5. One matching product: a product page.
	if ( 1 === $np && ( count( $core ) >= 2 || $prod >= 0.3 ) ) {
		$out['page']     = 'product';
		$out['reason']   = 'فقط یک محصول سایت با این جستجو منطبق است («' . $matches[0][2] . '»)؛ صفحه همان محصول را بهینه کنید.';
		$out['term_url'] = $matches[0][1];
		return $out;
	}
	// 6. Brand.
	if ( '' !== $brand ) {
		$out['archive'] = 'brand';
		$out['reason']  = 'جستجوی برند («' . $brand . '») است؛ صفحه آرشیو برند' . ( $term && 'brand' === $term[3] ? ' موجود است.' : ( $np ? sprintf( ' با %s محصول.', qtb_num( $np ) ) : '؛ اگر این برند را نمی‌فروشید حذفش کنید.' ) );
		return $out;
	}
	// 7. An existing archive term.
	if ( $term ) {
		$out['archive'] = $term[3];
		$out['reason']  = sprintf( 'سایت صفحه «%s» (%s) را دارد%s؛ همان آرشیو را هدف بگیرید.', $term[2], qtb_archive_types()[ $term[3] ] ?? '', $np ? sprintf( ' و %s محصول منطبق', qtb_num( $np ) ) : '' );
		return $out;
	}
	// 8. Google wants product pages.
	if ( $n && $prod >= 0.5 && $np <= 1 ) {
		$out['page']   = 'product';
		$out['reason'] = sprintf( 'گوگل %s نتیجه از ۱۰ را به صفحه محصول داده است.', $pct( $prod ) );
		return $out;
	}
	// 9. Several products: a listing.
	if ( $np >= 2 ) {
		$adj = false;
		foreach ( $c['kws'] as $m ) {
			$raw = explode( ' ', $keywords[ $m ]['nk'] );
			$adj = $adj || ( count( $raw ) >= 2 && 'ی' === mb_substr( end( $raw ), -1 ) );
		}
		$out['archive'] = $adj && count( $core ) >= 2 ? 'attribute' : 'category';
		$out['reason']  = sprintf( '%s محصول سایت با این جستجو منطبق‌اند؛ %s.', qtb_num( $np ), 'attribute' === $out['archive'] ? 'صفحه آرشیو ویژگی (فیلتر) کافی است' : 'صفحه دسته محصول کافی است و لندینگ جدا لازم نیست' );
		return $out;
	}
	// 10. Google shows home / custom pages of specialised sites.
	if ( $n && $home >= 0.4 && $cat < 0.4 ) {
		$out['page']   = 'landing';
		$out['reason'] = sprintf( 'گوگل %s نتیجه از ۱۰ را به صفحات اصلی/اختصاصی سایت‌های تخصصی داده؛ لندینگ اختصاصی.', $pct( $home ) );
		return $out;
	}
	// 11. Default: a listing page (new if the store has none).
	$out['reason'] = $n ? sprintf( 'گوگل %s نتیجه از ۱۰ را به صفحات دسته و فروشگاه داده است', $pct( $cat ) ) : 'جستجوی خرید است';
	$out['reason'] .= $idx['has_store'] && ! $np ? '؛ محصول منطبقی در سایت پیدا نشد — اگر این محصول را ندارید، حذف یا پارک کنید.' : '؛ صفحه دسته محصول.';
	return $out;
}

/**
 * Business profile of a project (what the page decision and the AI need to know).
 */
function qtb_project_profile( $project ) {
	$cfg = $project['cfg'];
	return array(
		'niche'      => (string) $cfg['niche'],
		'sells'      => (string) ( $cfg['sells'] ?? '' ),
		'not_sells'  => (string) ( $cfg['not_sells'] ?? '' ),
		'services'   => (array) ( $cfg['services'] ?? array() ),
		'locations'  => (array) ( $cfg['locations'] ?? array() ),
		'priorities' => (string) ( $cfg['priorities'] ?? '' ),
		'brands'     => (array) $cfg['brands'],
		'competitors' => (array) $cfg['competitors'],
	);
}

/**
 * Decide the page of every cluster that was not set by hand.
 */
function qtb_clusters_decide_pages( $project, $keywords, $clusters ) {
	$inv     = (array) ( qtb_data_get( $project['id'], 'inventory', array() )['items'] ?? array() );
	$idx     = qtb_store_index( $inv );
	$profile = qtb_project_profile( $project );
	foreach ( $clusters as &$c ) {
		$c['page'] = qtb_page_norm( $c['page'] ?? 'archive' );
		if ( ! empty( $c['locked_page'] ) ) {
			continue;
		}
		$d               = qtb_page_decide( $c, $keywords, $idx, $profile );
		$c['page']        = $d['page'];
		$c['archive']     = 'archive' === $d['page'] ? $d['archive'] : '';
		$c['page_reason'] = $d['reason'];
		$c['page_hint']   = $d['term_url'];
		$c['store_match'] = $d['products'];
	}
	unset( $c );
	return $clusters;
}
