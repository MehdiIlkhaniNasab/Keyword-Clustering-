<?php
/**
 * The project picture: demand by intent and page type, and the 12-month roadmap in four
 * three-month phases with a traffic range (positions 5 to 2 — a range, never a promise).
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Phases.
 */
function qtb_phases() {
	return array(
		1 => array( 'name' => 'پایه فروش و بردهای سریع', 'months' => 'ماه ۱ تا ۳', 'what' => 'صفحاتی که الان رتبه ۴ تا ۲۰ دارند، و صفحات اصلی فروش (لندینگ، آرشیو و محصول) پرارزش‌ترین پیلارها.' ),
		2 => array( 'name' => 'گسترش صفحات فروش', 'months' => 'ماه ۴ تا ۶', 'what' => 'بقیه آرشیوها، برندها، ویژگی‌ها و محصولات؛ مقاله‌های مقایسه و «بهترین…» که به صفحات فاز ۱ لینک می‌دهند.' ),
		3 => array( 'name' => 'مرجعیت موضوعی', 'months' => 'ماه ۷ تا ۹', 'what' => 'مقاله‌های آموزشی و حل مشکل پیلارهای اصلی؛ هرکدام به صفحه مادر فروش لینک می‌دهد.' ),
		4 => array( 'name' => 'کلمات سخت و تکمیل', 'months' => 'ماه ۱۰ تا ۱۲', 'what' => 'کلمات پررقابت، پیلارهای کم‌اولویت و به‌روزرسانی صفحات فازهای قبل.' ),
	);
}

/**
 * Phase of a page (cluster).
 */
function qtb_phase_of( $c ) {
	$sell = in_array( $c['page'], array( 'landing', 'archive', 'product' ), true );
	$prio = (string) ( $c['priority'] ?? '' );
	if ( ! empty( $c['quick_win'] ) || ( $sell && 'P1' === $prio ) ) {
		return 1;
	}
	if ( (int) ( $c['diff'] ?? 0 ) >= 70 || 'P3' === $prio && ! $sell ) {
		return 4;
	}
	if ( $sell || 'c' === ( $c['intent'] ?? '' ) ) {
		return 2;
	}
	return 3;
}

/**
 * Roadmap of a set of clusters: per phase pages by type, volume, traffic range, examples.
 */
function qtb_roadmap( $clusters ) {
	$out = array();
	foreach ( qtb_phases() as $n => $ph ) {
		$out[ $n ] = $ph + array( 'pages' => 0, 'types' => array(), 'vol' => 0, 'low' => 0, 'high' => 0, 'ex' => array() );
	}
	$sorted = $clusters;
	usort( $sorted, function ( $a, $b ) {
		return ( $b['score'] ?? $b['vol'] ) <=> ( $a['score'] ?? $a['vol'] );
	} );
	foreach ( $sorted as $c ) {
		$n = qtb_phase_of( $c );
		$out[ $n ]['pages']++;
		$out[ $n ]['types'][ $c['page'] ] = ( $out[ $n ]['types'][ $c['page'] ] ?? 0 ) + 1;
		$out[ $n ]['vol']                += (int) $c['vol'];
		$out[ $n ]['low']                += $c['vol'] * qtb_ctr_at( 5 );
		$out[ $n ]['high']               += $c['vol'] * qtb_ctr_at( 2 );
		if ( count( $out[ $n ]['ex'] ) < 6 ) {
			$out[ $n ]['ex'][] = $c['name'];
		}
	}
	return $out;
}

/**
 * Demand anatomy of the active keywords: volume by intent.
 */
function qtb_demand( $kws ) {
	$by    = array();
	$total = 0;
	foreach ( $kws as $kw ) {
		if ( ! in_array( $kw['ws'] ?? 'keep', array( 'keep', 'unsure' ), true ) ) {
			continue;
		}
		$in          = (string) ( $kw['ai_intent'] ?? ( $kw['intent0'] ?? 'm' ) );
		$in          = isset( qtb_intents()[ $in ] ) ? $in : 'm';
		$by[ $in ]   = ( $by[ $in ] ?? 0 ) + (int) $kw['v'];
		$total      += (int) $kw['v'];
	}
	arsort( $by );
	return array( 'by' => $by, 'total' => $total );
}

/**
 * Clusters for the picture: the published result when there is one, else the workshop.
 */
function qtb_picture_clusters( $project, $R ) {
	if ( ! empty( $R['clusters'] ) ) {
		return $R['clusters'];
	}
	$out = array();
	foreach ( qtb_ws_get( $project['id'] )['topics'] as $t ) {
		foreach ( $t['clusters'] as $c ) {
			$c['pillar_name'] = $t['name'];
			$out[]            = $c;
		}
	}
	return $out;
}
