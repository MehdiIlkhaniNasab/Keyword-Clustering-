<?php
/**
 * UI helpers: flash messages, chips, badges and small form parts.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Queue a flash message for the next screen.
 */
function qtb_flash_set( $msg, $ok = true ) {
	set_transient( 'qtb_flash_' . get_current_user_id(), array( 'm' => $msg, 'ok' => $ok ), 120 );
}

/**
 * Print and clear the flash message.
 */
function qtb_flash() {
	$key = 'qtb_flash_' . get_current_user_id();
	$f   = get_transient( $key );
	if ( $f ) {
		delete_transient( $key );
		printf( '<div class="qtb-flash %s">%s</div>', $f['ok'] ? 'is-ok' : 'is-err', esc_html( $f['m'] ) );
	}
}

/**
 * Hidden fields of an app form.
 */
function qtb_form_fields( $action, $extra = array() ) {
	echo '<input type="hidden" name="qtb_action" value="' . esc_attr( $action ) . '">';
	wp_nonce_field( 'qtb_' . $action, '_qtb_nonce' );
	foreach ( $extra as $k => $v ) {
		echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
	}
}

/**
 * Page type chip.
 */
function qtb_chip_page( $page, $c = null ) {
	$page  = qtb_page_norm( $page );
	$label = $c ? qtb_page_label( $c ) : ( qtb_page_types()[ $page ][0] ?? $page );
	$title = $c && ! empty( $c['page_reason'] ) ? ' title="' . esc_attr( $c['page_reason'] ) . '"' : '';
	return '<span class="qtb-chip is-' . esc_attr( $page ) . '"' . $title . '>' . esc_html( $label ) . '</span>';
}

/**
 * Priority chip.
 */
function qtb_chip_prio( $p ) {
	return '<span class="qtb-chip is-' . esc_attr( $p ) . '">' . esc_html( $p ) . '</span>';
}

/**
 * Coverage chip.
 */
function qtb_chip_cov( $cov ) {
	return '<span class="qtb-chip is-' . esc_attr( $cov ) . '">' . esc_html( qtb_coverage_label( $cov ) ) . '</span>';
}

/**
 * Score badge.
 */
function qtb_score_badge( $score ) {
	$cls = $score >= 65 ? 'is-hi' : ( $score < 30 ? 'is-lo' : '' );
	return '<span class="qtb-score ' . $cls . '" title="امتیاز فروش و بازگشت سرمایه">' . esc_html( qtb_num( $score ) ) . '</span>';
}

/**
 * <option> list.
 */
function qtb_options( $options, $current ) {
	foreach ( $options as $v => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( (string) $current, (string) $v, false ), esc_html( $label ) );
	}
}

/**
 * Connection indicator.
 */
function qtb_conn( $label, $state ) {
	$cls = 'sokan' === $state ? 'is-sokan' : ( $state ? 'is-on' : '' );
	printf( '<span class="qtb-conn %s"><i></i>%s</span>', esc_attr( $cls ), esc_html( $label . ( 'sokan' === $state ? ' (از سکان)' : '' ) ) );
}

/**
 * Short, decoded URL link.
 */
function qtb_link( $url, $max = 46 ) {
	if ( ! preg_match( '#^https?://#', (string) $url ) ) {
		return '<span class="qtb-muted">' . esc_html( $url ) . '</span>';
	}
	return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( rawurldecode( $url ) ) . '">' . esc_html( qtb_short_url( $url, $max ) ) . '</a>';
}

/**
 * Status dot class of a project.
 */
function qtb_status_class( $status ) {
	return array( 'running' => 'is-running', 'error' => 'is-error', 'new' => 'is-new' )[ $status ] ?? '';
}

/**
 * Pagination links.
 */
function qtb_pager( $pages, $cur, $base_args ) {
	if ( $pages < 2 ) {
		return;
	}
	echo '<div class="qtb-actions" style="justify-content:center;margin-top:14px">';
	for ( $i = 1; $i <= $pages; $i++ ) {
		if ( $pages > 12 && $i > 3 && $i < $pages - 2 && abs( $i - $cur ) > 2 ) {
			if ( 4 === $i || $pages - 3 === $i ) {
				echo '<span class="qtb-muted">…</span>';
			}
			continue;
		}
		printf( '<a class="qtb-btn is-small %s" href="%s">%s</a>', $i === $cur ? 'is-primary' : '', esc_url( add_query_arg( array_merge( $base_args, array( 'pg' => $i ) ) ) ), esc_html( qtb_num( $i ) ) );
	}
	echo '</div>';
}
