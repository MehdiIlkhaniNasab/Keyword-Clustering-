<?php
/**
 * Persian text normalisation, tokens, light stemming and string similarity.
 *
 * Three levels:
 *   mzm_norm()      display form: Persian letters, Latin digits, single spaces.
 *   mzm_norm_key()  matching form: also آ→ا, ة→ه, punctuation removed (so «آپارتمانی»
 *                   and «اپارتمانی» match).
 *   mzm_key()       compact form without spaces (so «گل‌های»، «گل های»، «گلهای» match).
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display normalisation.
 */
function mzm_norm( $s ) {
	$s = (string) $s;
	$s = strtr( $s, array(
		'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
		"\u{200C}" => ' ', "\u{200D}" => '', "\u{200F}" => '', "\u{200E}" => '', "\u{00A0}" => ' ', 'ـ' => '',
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	) );
	$s = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $s ); // Diacritics.
	$s = mb_strtolower( $s, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Matching normalisation.
 */
function mzm_norm_key( $s ) {
	$s = strtr( mzm_norm( $s ), array( 'آ' => 'ا' ) );
	$s = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $s );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Compact key (no spaces).
 */
function mzm_key( $s ) {
	return str_replace( ' ', '', mzm_norm_key( $s ) );
}

/**
 * Tokens of a phrase (matching form).
 *
 * @return string[]
 */
function mzm_tokens( $s ) {
	$s = mzm_norm_key( $s );
	return '' === $s ? array() : explode( ' ', $s );
}

/**
 * Multibyte Levenshtein distance (bounded: returns $max + 1 when larger).
 */
function mzm_lev( $a, $b, $max = 3 ) {
	if ( $a === $b ) {
		return 0;
	}
	$x  = preg_split( '//u', $a, -1, PREG_SPLIT_NO_EMPTY );
	$y  = preg_split( '//u', $b, -1, PREG_SPLIT_NO_EMPTY );
	$lx = count( $x );
	$ly = count( $y );
	if ( abs( $lx - $ly ) > $max ) {
		return $max + 1;
	}
	$prev = range( 0, $ly );
	for ( $i = 1; $i <= $lx; $i++ ) {
		$cur = array( $i );
		$min = $i;
		for ( $j = 1; $j <= $ly; $j++ ) {
			$cost      = $x[ $i - 1 ] === $y[ $j - 1 ] ? 0 : 1;
			$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
			$min       = min( $min, $cur[ $j ] );
		}
		if ( $min > $max ) {
			return $max + 1;
		}
		$prev = $cur;
	}
	return $prev[ $ly ];
}

/**
 * Jaccard similarity of two token lists.
 */
function mzm_jaccard( $a, $b ) {
	$a = array_unique( (array) $a );
	$b = array_unique( (array) $b );
	if ( ! $a && ! $b ) {
		return 1.0;
	}
	$i = count( array_intersect( $a, $b ) );
	$u = count( array_unique( array_merge( $a, $b ) ) );
	return $u ? $i / $u : 0.0;
}

/**
 * Corpus-validated light stemmer: strips a plural / adjective suffix only when
 * the remaining stem is itself a word of this keyword list (so «گیاهان» → «گیاه»,
 * «آپارتمانی» → «آپارتمان», but «باران» stays «باران» unless «بار» is a topic word).
 *
 * @param array $vocab token => frequency.
 * @return array token => stem
 */
function mzm_stem_map( $vocab ) {
	$suffixes = array( 'هایی', 'های', 'ها', 'ترین', 'تر', 'ات', 'ان', 'یی', 'ی' );
	$map      = array();
	foreach ( $vocab as $tok => $freq ) {
		$tok = (string) $tok;
		foreach ( $suffixes as $suf ) {
			$len = mb_strlen( $suf );
			$min = in_array( $suf, array( 'هایی', 'های', 'ها', 'ان' ), true ) ? 2 : 3;
			if ( mb_strlen( $tok ) - $len < $min || mb_substr( $tok, -$len ) !== $suf ) {
				continue;
			}
			$stem = mb_substr( $tok, 0, -$len );
			if ( isset( $vocab[ $stem ] ) && $stem !== $tok ) {
				$map[ $tok ] = $stem;
				break;
			}
		}
	}
	// Chains: «گلهایی» → «گلها» → «گل».
	foreach ( $map as $tok => $stem ) {
		$seen = 0;
		while ( isset( $map[ $stem ] ) && $seen++ < 4 ) {
			$stem = $map[ $stem ];
		}
		$map[ $tok ] = $stem;
	}
	return $map;
}

/**
 * Spelling-variant map: rare tokens that are one or two letters away from a
 * more frequent token of the list («زامیفولیا» → «زاموفیلیا»، «مونسترا» → «مانسترا»).
 *
 * @param array $vocab token => frequency.
 * @return array token => canonical token
 */
function mzm_variant_map( $vocab ) {
	arsort( $vocab );
	$buckets = array();
	foreach ( $vocab as $tok => $f ) {
		$tok = (string) $tok;
		$len = mb_strlen( $tok );
		// Verbs («نمیکنه»، «میریزه») differ by one letter and mean different things.
		if ( $len < 5 || preg_match( '/\d/', $tok ) || preg_match( '/^(نمی|می)/u', $tok ) ) {
			continue;
		}
		$buckets[ mb_substr( $tok, 0, 1 ) ][] = array( $tok, $len, $f );
	}
	$map = array();
	foreach ( $buckets as $list ) {
		$n = count( $list );
		for ( $i = 0; $i < $n; $i++ ) {
			list( $a, $la, $fa ) = $list[ $i ];
			if ( isset( $map[ $a ] ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $n; $j++ ) {
				list( $b, $lb, $fb ) = $list[ $j ];
				if ( isset( $map[ $b ] ) || abs( $la - $lb ) > 2 ) {
					continue;
				}
				$max = min( $la, $lb ) >= 8 ? 2 : 1;
				// Same ending kept: «سانسوریا»/«سانسوریای» are stems, not typos.
				if ( mzm_lev( $a, $b, $max ) <= $max && mb_substr( $a, -1 ) === mb_substr( $b, -1 ) ) {
					$map[ $b ] = $a; // $a is the more frequent spelling.
				}
			}
		}
	}
	return $map;
}
