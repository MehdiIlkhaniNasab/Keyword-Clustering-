<?php
/**
 * Jalali (Shamsi) calendar conversion for the content calendar.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

const MZM_JMONTHS = array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );

/**
 * Gregorian → Jalali.
 */
function mzm_g2j( $gy, $gm, $gd ) {
	$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
	$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
	$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
	$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
	$days %= 12053;
	$jy   += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$jy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	if ( $days < 186 ) {
		$jm = 1 + intdiv( $days, 31 );
		$jd = 1 + ( $days % 31 );
	} else {
		$jm = 7 + intdiv( $days - 186, 30 );
		$jd = 1 + ( ( $days - 186 ) % 30 );
	}
	return array( $jy, $jm, $jd );
}

/**
 * Jalali → Gregorian.
 */
function mzm_j2g( $jy, $jm, $jd ) {
	$jy  += 1595;
	$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 ) + $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );
	$gy   = 400 * intdiv( $days, 146097 );
	$days %= 146097;
	if ( $days > 36524 ) {
		$gy  += 100 * intdiv( --$days, 36524 );
		$days %= 36524;
		if ( $days >= 365 ) {
			$days++;
		}
	}
	$gy  += 4 * intdiv( $days, 1461 );
	$days %= 1461;
	if ( $days > 365 ) {
		$gy  += intdiv( $days - 1, 365 );
		$days = ( $days - 1 ) % 365;
	}
	$gd    = $days + 1;
	$leap  = ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400;
	$sal_a = array( 0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
	for ( $gm = 0; $gm < 13 && $gd > $sal_a[ $gm ]; $gm++ ) {
		$gd -= $sal_a[ $gm ];
	}
	return array( $gy, $gm, $gd );
}

/**
 * Format a Gregorian date (Y-m-d or datetime) as Jalali text.
 *
 * @param string $format long (۲ آبان ۱۴۰۵) | short (۱۴۰۵/۰۸/۰۲) | day (۲ آبان) | month (آبان ۱۴۰۵).
 */
function mzm_jdate( $ymd, $format = 'long' ) {
	$ts = strtotime( (string) $ymd );
	if ( ! $ymd || ! $ts || 0 === strpos( (string) $ymd, '0000' ) ) {
		return '';
	}
	list( $jy, $jm, $jd ) = mzm_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
	switch ( $format ) {
		case 'short':
			return mzm_fa( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) );
		case 'day':
			return mzm_fa( $jd ) . ' ' . MZM_JMONTHS[ $jm ];
		case 'month':
			return MZM_JMONTHS[ $jm ] . ' ' . mzm_fa( $jy );
		default:
			return mzm_fa( $jd ) . ' ' . MZM_JMONTHS[ $jm ] . ' ' . mzm_fa( $jy );
	}
}

/**
 * Number of days in a Jalali month.
 */
function mzm_jmonth_days( $jy, $jm ) {
	if ( $jm <= 6 ) {
		return 31;
	}
	if ( $jm <= 11 ) {
		return 30;
	}
	list( $gy, $gm, $gd ) = mzm_j2g( $jy + 1, 1, 1 );
	$next                  = strtotime( sprintf( '%04d-%02d-%02d', $gy, $gm, $gd ) );
	list( $gy, $gm, $gd ) = mzm_j2g( $jy, 12, 1 );
	$start                 = strtotime( sprintf( '%04d-%02d-%02d', $gy, $gm, $gd ) );
	return (int) round( ( $next - $start ) / DAY_IN_SECONDS );
}

/**
 * Today's Jalali [year, month, day] in the site timezone.
 */
function mzm_jnow() {
	$ts = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
	return mzm_g2j( (int) gmdate( 'Y', $ts ), (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ) );
}

/**
 * Parse a date typed by the user: Jalali (۱۴۰۵/۰۸/۱۵) or Gregorian (2026-11-06).
 * Returns Gregorian Y-m-d or ''.
 */
function mzm_parse_date( $text ) {
	$t = mzm_norm( (string) $text );
	if ( ! preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $t, $m ) ) {
		return '';
	}
	$y  = (int) $m[1];
	$mo = (int) $m[2];
	$d  = (int) $m[3];
	if ( $mo < 1 || $mo > 12 || $d < 1 || $d > 31 ) {
		return '';
	}
	if ( $y < 1700 ) {
		list( $y, $mo, $d ) = mzm_j2g( $y, $mo, $d );
	}
	return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
}

/**
 * Jalali weekday name of a Gregorian date.
 */
function mzm_jweekday( $ymd ) {
	$names = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );
	return $names[ (int) gmdate( 'w', strtotime( $ymd ) ) ];
}
