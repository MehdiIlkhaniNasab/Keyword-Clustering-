<?php
/**
 * Content gap analysis and page briefs (AI) from Google's top 10.
 *
 * For each cluster the model reads the cluster keywords, the titles and
 * snippets of the ranking pages, People Also Ask, related searches and our own
 * page, and returns what the winning page must cover, what the current top
 * results miss (our angle), an outline, FAQ, title / meta and the sales CTA.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clusters that get a gap analysis: the best-scoring landings and articles.
 *
 * @return int[] cluster ids
 */
function qtb_gap_targets( $clusters, $limit ) {
	$list = qtb_sort( $clusters, 'score' );
	$out  = array();
	foreach ( $list as $c ) {
		if ( count( $out ) >= $limit ) {
			break;
		}
		if ( $c['vol'] < 20 || ! empty( $c['gap'] ) ) {
			continue;
		}
		$out[] = $c['id'];
	}
	return $out;
}

/**
 * JSON schema of one brief.
 */
function qtb_gap_schema_item() {
	$str  = array( 'type' => 'string' );
	$list = array( 'type' => 'array', 'items' => $str );
	return array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'id', 'intent', 'angle', 'title', 'meta', 'h1', 'outline', 'must_cover', 'gaps', 'faq', 'words', 'cta', 'schema' ),
		'properties'           => array(
			'id'         => array( 'type' => 'integer' ),
			'intent'     => $str,
			'angle'      => $str,
			'title'      => $str,
			'meta'       => $str,
			'h1'         => $str,
			'outline'    => $list,
			'must_cover' => $list,
			'gaps'       => $list,
			'faq'        => $list,
			'words'      => array( 'type' => 'integer' ),
			'cta'        => $str,
			'schema'     => $str,
		),
	);
}

/**
 * Gap analysis / brief for a batch of clusters (≤ 4 per call).
 *
 * @param array $batch [{id, name, page, intent, kws: [k], serp: metrics|null, target: url|null, money: [names]}]
 * @param string $purpose analysis|light.
 * @return array|WP_Error id → brief
 */
function qtb_ai_gap_batch( $project, $batch, $purpose = 'analysis' ) {
	$types  = qtb_page_types();
	$blocks = array();
	foreach ( $batch as $c ) {
		$b = sprintf( "### شناسه %d — «%s»\nنوع صفحه: %s | نیت: %s\nکلمات کلاستر: %s", $c['id'], $c['name'], $types[ $c['page'] ][0] ?? $c['page'], qtb_intents()[ $c['intent'] ] ?? $c['intent'], implode( '، ', array_slice( $c['kws'], 0, 14 ) ) );
		if ( ! empty( $c['serp'] ) ) {
			$b .= "\nعنوان ۱۰ نتیجه اول گوگل:\n- " . implode( "\n- ", array_slice( (array) $c['serp']['titles'], 0, 10 ) );
			if ( ! empty( $c['snippets'] ) ) {
				$b .= "\nخلاصه نتایج: " . implode( ' / ', array_slice( $c['snippets'], 0, 6 ) );
			}
			if ( ! empty( $c['serp']['paa'] ) ) {
				$b .= "\nسوالات مرتبط گوگل (PAA): " . implode( ' | ', $c['serp']['paa'] );
			}
			if ( ! empty( $c['serp']['related'] ) ) {
				$b .= "\nجستجوهای مرتبط: " . implode( ' | ', $c['serp']['related'] );
			}
			$b .= "\nنوع صفحات رتبه‌گرفته: " . wp_json_encode( $c['serp']['types'], JSON_UNESCAPED_UNICODE );
		} else {
			$b .= "\n(داده نتایج گوگل در دسترس نیست؛ از دانش خودت درباره نتایج معمول این کلمه در گوگل فارسی استفاده کن.)";
		}
		if ( ! empty( $c['target'] ) ) {
			$b .= "\nصفحه فعلی ما: " . $c['target'];
		}
		if ( ! empty( $c['money'] ) ) {
			$b .= "\nلندینگ‌های فروش مرتبط برای لینک و CTA: " . implode( '، ', $c['money'] );
		}
		$blocks[] = $b;
	}
	$system = 'تو استراتژیست ارشد محتوای سئو در بازار ایران هستی. با بررسی ۱۰ نتیجه اول گوگل، بهترین صفحه ممکن برای هر کلاستر را طراحی می‌کنی: صفحه‌ای که هم رتبه بگیرد و هم بفروشد. شکاف‌ها را دقیق و مشخص بنویس (نه کلی‌گویی).';
	$user   = "کسب‌وکار: {$project['name']} ({$project['site_url']}) — " . ( $project['cfg']['niche'] ?: '' ) .
		"\n\nبرای هر کلاستر بده:\n- intent: نیت واقعی کاربر در یک جمله\n- angle: زاویه برنده و تمایز ما نسبت به رقبا\n- title: عنوان سئو (حداکثر ۶۰ کاراکتر، کلمه اصلی اول)\n- meta: توضیحات متا (حداکثر ۱۵۵ کاراکتر، با دعوت به اقدام)\n- h1\n- outline: ۶ تا ۱۰ سرتیتر H2 به ترتیب\n- must_cover: موضوعاتی که همه رقبای برتر پوشش داده‌اند و حتماً باید باشد\n- gaps: شکاف‌های محتوایی؛ چیزهایی که نتایج فعلی کم دارند یا ضعیف گفته‌اند و ما باید اضافه کنیم (جدول مقایسه، قیمت به‌روز، راهنمای انتخاب، تصاویر واقعی، تجربه و…)\n- faq: ۴ تا ۶ سوال متداول\n- words: تعداد کلمه پیشنهادی\n- cta: دعوت به اقدام فروش و لینک به کدام لندینگ\n- schema: نوع اسکیما (Product، ItemList، FAQPage، HowTo، Article)\n\n" . implode( "\n\n", $blocks );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'items' ),
		'properties'           => array( 'items' => array( 'type' => 'array', 'items' => qtb_gap_schema_item() ) ),
	);
	$res = qtb_ai_request( $system, $user, $schema, array( 'purpose' => $purpose, 'max_tokens' => 2400 * count( $batch ) + 800, 'timeout' => 240 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_fill_keys( array_column( $batch, 'id' ), true );
	$out   = array();
	foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
		$id = (int) ( $it['id'] ?? -1 );
		if ( ! isset( $valid[ $id ] ) ) {
			continue;
		}
		$clean = function ( $list, $n ) {
			return array_values( array_slice( array_filter( array_map( function ( $s ) {
				return trim( wp_strip_all_tags( (string) $s ) );
			}, (array) $list ) ), 0, $n ) );
		};
		$out[ $id ] = array(
			'intent'     => trim( (string) $it['intent'] ),
			'angle'      => trim( (string) $it['angle'] ),
			'title'      => mb_substr( trim( (string) $it['title'] ), 0, 90 ),
			'meta'       => mb_substr( trim( (string) $it['meta'] ), 0, 200 ),
			'h1'         => mb_substr( trim( (string) $it['h1'] ), 0, 120 ),
			'outline'    => $clean( $it['outline'], 12 ),
			'must_cover' => $clean( $it['must_cover'], 10 ),
			'gaps'       => $clean( $it['gaps'], 10 ),
			'faq'        => $clean( $it['faq'], 8 ),
			'words'      => max( 300, min( 6000, (int) $it['words'] ) ),
			'cta'        => trim( (string) $it['cta'] ),
			'schema'     => trim( (string) $it['schema'] ),
			'serp'       => ! empty( $batch[ array_search( $id, array_column( $batch, 'id' ), true ) ]['serp'] ),
		);
	}
	return $out;
}

/**
 * Prompt input of a cluster for the gap analysis.
 */
function qtb_gap_input( $keywords, $c, $clusters_by_id, $serps ) {
	$money = array();
	if ( 'blog' === $c['page'] ) {
		foreach ( $clusters_by_id as $o ) {
			if ( $o['pillar'] === $c['pillar'] && 'blog' !== $o['page'] && count( $money ) < 3 ) {
				$money[] = $o['name'] . ( $o['target'] ? ' (' . $o['target']['url'] . ')' : '' );
			}
		}
	}
	$snips = array();
	if ( isset( $c['serp_kw'], $serps[ $c['serp_kw'] ] ) ) {
		foreach ( array_slice( (array) $serps[ $c['serp_kw'] ]['organic'], 0, 6 ) as $o ) {
			if ( '' !== $o['snippet'] ) {
				$snips[] = mb_substr( $o['snippet'], 0, 140 );
			}
		}
	}
	return array(
		'id'       => $c['id'],
		'name'     => $c['name'],
		'page'     => $c['page'],
		'intent'   => $c['intent'],
		'kws'      => array_map( function ( $m ) use ( $keywords ) {
			return $keywords[ $m ]['k'];
		}, $c['kws'] ),
		'serp'     => $c['serp'],
		'snippets' => $snips,
		'target'   => $c['target'] ? $c['target']['url'] : '',
		'money'    => $money,
	);
}
