<?php
/**
 * Keyword cleaning: duplicates and spelling variants, low volume, competitor
 * brands and marketplaces, project negative words, junk queries, and an AI
 * relevance pass that catches what rules cannot (unknown brands, off-topic
 * queries). Nothing is deleted silently: every removed keyword keeps its reason.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removal reasons.
 */
function mzm_remove_reasons() {
	return array(
		'duplicate'   => 'تکراری / املای دیگر',
		'low_volume'  => 'حجم جستجوی کم',
		'competitor'  => 'برند رقیب',
		'marketplace' => 'نام بازارگاه یا سایت دیگر',
		'negative'    => 'کلمه منفی پروژه',
		'junk'        => 'بی‌ارزش برای فروش (دانلود، فیلم، فال…)',
		'irrelevant'  => 'نامرتبط با کسب‌وکار',
		'navigational' => 'جستجوی ناوبری سایت دیگر',
		'invalid'     => 'نامعتبر',
		'manual'      => 'حذف دستی',
	);
}

/**
 * Display form: Arabic letters and digits fixed, ZWNJ kept, single spaces.
 */
function mzm_display( $s ) {
	$s = strtr( (string) $s, array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\u{00A0}" => ' ', 'ـ' => '' ) );
	$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $s );
	$s = preg_replace( '/\x{200C}+/u', "\u{200C}", $s );
	$s = preg_replace( '/\s*\x{200C}\s*/u', "\u{200C}", $s );
	return trim( preg_replace( '/[ \t\r\n]+/u', ' ', $s ) );
}

/**
 * Lexicon entries that the project's own niche / brand text mentions (never treated as junk / competitor).
 */
function mzm_niche_words( $project ) {
	$text = mzm_norm_key( $project['cfg']['niche'] . ' ' . implode( ' ', (array) $project['cfg']['brands'] ) . ' ' . $project['name'] );
	return ' ' . $text . ' ';
}

/**
 * Rule-based cleaning.
 *
 * @param array $input [{k, v}]
 * @return array{keywords: array, removed: array}
 */
function mzm_clean_run( $project, $input ) {
	$cfg     = $project['cfg'];
	$niche   = mzm_niche_words( $project );
	$in_niche = function ( $entry ) use ( $niche ) {
		return false !== mb_strpos( $niche, ' ' . $entry . ' ' );
	};
	$comp    = array_values( array_filter( array_map( 'mzm_norm_key', (array) $cfg['competitors'] ) ) );
	$market  = array_values( array_filter( mzm_lexicon()['marketplaces'], function ( $e ) use ( $in_niche ) {
		return ! $in_niche( $e );
	} ) );
	$junk    = array_values( array_filter( mzm_lexicon()['junk'], function ( $e ) use ( $in_niche ) {
		return ! $in_niche( $e );
	} ) );
	$neg     = array_values( array_filter( array_map( 'mzm_norm_key', (array) $cfg['negatives'] ) ) );
	$own     = array_values( array_filter( array_map( 'mzm_norm_key', (array) $cfg['brands'] ) ) );
	$own_dom = mzm_project_domain( $project );
	if ( $own_dom ) {
		$own[] = mzm_norm_key( preg_replace( '/\.[a-z.]+$/', '', $own_dom ) );
	}
	$min     = max( 0, (int) $cfg['min_volume'] );
	$hit     = function ( $nk, $list ) {
		$padded = ' ' . $nk . ' ';
		$compact = str_replace( ' ', '', $nk );
		foreach ( $list as $e ) {
			if ( '' === $e ) {
				continue;
			}
			if ( false !== mb_strpos( $padded, ' ' . $e . ' ' ) || ( mb_strlen( $e ) >= 5 && false !== mb_strpos( $compact, str_replace( ' ', '', $e ) ) ) ) {
				return $e;
			}
		}
		return '';
	};

	$by_key  = array();
	$removed = array();
	foreach ( (array) $input as $row ) {
		$k  = mzm_display( $row['k'] ?? '' );
		$v  = max( 0, (int) ( $row['v'] ?? 0 ) );
		$nk = mzm_norm_key( $k );
		if ( '' === $nk || ! preg_match( '/\p{L}/u', $nk ) || count( explode( ' ', $nk ) ) > 14 ) {
			$removed[] = array( 'k' => $k, 'v' => $v, 'r' => 'invalid', 'd' => '' );
			continue;
		}
		$key = str_replace( ' ', '', $nk );
		if ( isset( $by_key[ $key ] ) ) {
			$keep = $by_key[ $key ];
			// Keep the spelling with the higher volume as the main form.
			if ( $v > $keep['v'] ) {
				$removed[]      = array( 'k' => $keep['k'], 'v' => $keep['v'], 'r' => 'duplicate', 'd' => $k );
				$keep['alts'][] = $keep['k'];
				$keep['k']      = $k;
				$keep['v']      = $v;
				$keep['nk']     = $nk;
			} else {
				$removed[] = array( 'k' => $k, 'v' => $v, 'r' => 'duplicate', 'd' => $keep['k'] );
				if ( $k !== $keep['k'] ) {
					$keep['alts'][] = $k;
				}
			}
			$by_key[ $key ] = $keep;
			continue;
		}
		$by_key[ $key ] = array( 'k' => $k, 'v' => $v, 'nk' => $nk, 'key' => $key, 'alts' => array() );
	}

	$keywords = array();
	foreach ( $by_key as $kw ) {
		$nk = $kw['nk'];
		$e  = '';
		if ( $own && $hit( $nk, $own ) ) {
			$kw['brand'] = 1;
		} elseif ( $comp && ( $e = $hit( $nk, $comp ) ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'competitor', 'd' => $e );
			continue;
		} elseif ( $e = $hit( $nk, $market ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'marketplace', 'd' => $e );
			continue;
		}
		if ( $neg && ( $e = $hit( $nk, $neg ) ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'negative', 'd' => $e );
			continue;
		}
		if ( empty( $kw['brand'] ) && ( $e = $hit( $nk, $junk ) ) ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'junk', 'd' => $e );
			continue;
		}
		if ( $kw['v'] < $min ) {
			$removed[] = array( 'k' => $kw['k'], 'v' => $kw['v'], 'r' => 'low_volume', 'd' => '' );
			continue;
		}
		$keywords[] = $kw;
	}
	$keywords = mzm_sort( $keywords, 'v' );
	foreach ( $keywords as $i => &$kw ) {
		$kw['i'] = $i;
	}
	unset( $kw );
	return array( 'keywords' => $keywords, 'removed' => $removed );
}

/**
 * AI relevance pass for one batch of keywords.
 *
 * Returns labels for the keywords the model thinks should go, plus an intent
 * guess for every keyword (t transactional, c commercial, i informational, n navigational).
 *
 * @param array $batch [{i, k, v}]
 * @return array|WP_Error {drop: [i => [reason, detail]], intent: [i => t|c|i|n]}
 */
function mzm_ai_filter_batch( $project, $batch ) {
	$cfg   = $project['cfg'];
	$lines = array();
	foreach ( $batch as $kw ) {
		$lines[] = $kw['i'] . '|' . $kw['k'];
	}
	$system = 'تو یک متخصص ارشد سئو و کیورد ریسرچ فارسی هستی که کلمات کلیدی را برای یک کسب‌وکار پاک‌سازی می‌کنی. هدف: کلماتی بمانند که برای فروش و جذب مشتری این کسب‌وکار ارزش دارند (خرید، بررسی و آموزش مرتبط با محصولات/خدمات آن).';
	$user   = "کسب‌وکار: {$project['name']} ({$project['site_url']})\nحوزه و محصولات: " . ( $cfg['niche'] ?: '(توضیح داده نشده؛ از خود کلمات حدس بزن)' ) .
		"\nبرند خودمان: " . ( implode( '، ', (array) $cfg['brands'] ) ?: '—' ) .
		"\nبرندهای رقیب: " . ( implode( '، ', (array) $cfg['competitors'] ) ?: '—' ) .
		"\n\nبرای هر کلمه (شناسه|کلمه) تشخیص بده:\n" .
		"- drop: فقط کلماتی که باید حذف شوند، با دلیل: competitor (نام برند/فروشگاه/سایت رقیب یا هر برند فروشنده دیگر مثل «خرید X از فلان‌شاپ»)، navigational (جستجوی سایت یا اپ دیگر)، irrelevant (بی‌ربط به این کسب‌وکار یا معنای دیگر کلمه)، junk (بی‌ارزش: دانلود، سرگرمی، شعر و…). در شک، حذف نکن. نام برند تولیدکننده محصول (مثلاً سامسونگ برای فروشگاه موبایل) رقیب نیست.\n" .
		"- intent: نیت هر کلمه: t خرید/قیمت، c مقایسه/انتخاب/انواع، i آموزشی/اطلاعاتی، n ناوبری برند خودمان.\n\nکلمات:\n" . implode( "\n", $lines );
	$schema = array(
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => array( 'drop', 'intent' ),
		'properties'           => array(
			'drop'   => array(
				'type'  => 'array',
				'items' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'reason', 'note' ),
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'reason' => array( 'type' => 'string', 'enum' => array( 'competitor', 'navigational', 'irrelevant', 'junk' ) ),
						'note'   => array( 'type' => 'string' ),
					),
				),
			),
			'intent' => array( 'type' => 'string', 'description' => 'برای همه کلمات به ترتیب: شناسه:حرف، با فاصله جدا. مثال: 12:t 13:i 14:c' ),
		),
	);
	$res = mzm_ai_request( $system, $user, $schema, array( 'purpose' => 'light', 'max_tokens' => 7000, 'timeout' => 180 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$valid = array_fill_keys( array_column( $batch, 'i' ), true );
	$drop  = array();
	foreach ( (array) ( $res['drop'] ?? array() ) as $d ) {
		$id = (int) ( $d['id'] ?? -1 );
		if ( isset( $valid[ $id ] ) ) {
			$drop[ $id ] = array( (string) $d['reason'], mb_substr( (string) ( $d['note'] ?? '' ), 0, 80 ) );
		}
	}
	$intent = array();
	if ( preg_match_all( '/(\d+)\s*:\s*([tcin])/u', (string) ( $res['intent'] ?? '' ), $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $one ) {
			if ( isset( $valid[ (int) $one[1] ] ) ) {
				$intent[ (int) $one[1] ] = $one[2];
			}
		}
	}
	return array( 'drop' => $drop, 'intent' => $intent );
}
