<?php
/**
 * Front-end app: the tool lives at {site}/seo/cluster/ as a standalone,
 * full-screen dashboard (own login screen, no theme), like Sokan at /seo/plan/.
 *
 * Routes (relative to the base):
 *   /                      dashboard (all projects)
 *   /new/                  new project
 *   /project/{id}/{tab}/   a project (overview, clusters, map, gaps, competitors, removed, calendar, export, settings)
 *   /settings/             connections & settings
 *   /guide/                guide
 *
 * Without pretty permalinks the same routes work as /?mzm_app=1&mzm_route=…
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

const MZM_REWRITE_VERSION = '1';

/**
 * Base path of the app. Filterable.
 */
function mzm_app_base() {
	return trim( (string) apply_filters( 'mzm_app_base', 'seo/cluster' ), '/' );
}

/**
 * App URL of a route.
 */
function mzm_url( $route = '', $args = array() ) {
	$route = trim( (string) $route, '/' );
	if ( get_option( 'permalink_structure' ) ) {
		$url = home_url( '/' . mzm_app_base() . '/' . ( '' !== $route ? $route . '/' : '' ) );
	} else {
		$url = add_query_arg( array( 'mzm_app' => 1, 'mzm_route' => $route ), home_url( '/' ) );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Project screen URL.
 */
function mzm_project_url( $project_id, $tab = 'overview', $args = array() ) {
	return mzm_url( 'project/' . (int) $project_id . ( 'overview' !== $tab ? '/' . $tab : '' ), $args );
}

add_action( 'init', function () {
	add_rewrite_rule( '^' . preg_quote( mzm_app_base(), '#' ) . '/?(.*)$', 'index.php?mzm_app=1&mzm_route=$matches[1]', 'top' );
	if ( get_option( 'mzm_rewrite_version' ) !== MZM_REWRITE_VERSION . '|' . mzm_app_base() ) {
		flush_rewrite_rules( false );
		update_option( 'mzm_rewrite_version', MZM_REWRITE_VERSION . '|' . mzm_app_base(), false );
	}
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'mzm_app';
	$vars[] = 'mzm_route';
	return $vars;
} );

/**
 * wp-admin keeps one menu item (and an admin-bar link) that opens the app.
 */
add_action( 'admin_menu', function () {
	add_menu_page( 'منظومه', 'منظومه (کلاسترینگ)', MZM_CAP, 'mzm', '__return_null', 'dashicons-networking', 4 );
} );
add_action( 'load-toplevel_page_mzm', function () {
	wp_safe_redirect( mzm_url() );
	exit;
} );
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( mzm_can() ) {
		$bar->add_node( array( 'id' => 'mzm', 'title' => 'منظومه', 'href' => mzm_url() ) );
	}
}, 81 );

/**
 * Render the app before the theme.
 */
add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'mzm_app' ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	status_header( 200 );
	show_admin_bar( false );
	$route = trim( (string) get_query_var( 'mzm_route' ), '/' );
	if ( ! is_user_logged_in() ) {
		mzm_render( 'login', array( 'redirect' => mzm_url( $route ) ) );
	}
	if ( ! mzm_can() ) {
		status_header( 403 );
		mzm_render( 'denied' );
	}
	wp_raise_memory_limit( 'admin' );
	mzm_handle_post( $route );
	mzm_render( 'shell', mzm_resolve_route( $route ) );
}, 1 );

/**
 * Project tabs.
 */
function mzm_project_tabs() {
	return array(
		'overview'    => array( 'نمای کلی', 'grid' ),
		'clusters'    => array( 'پیلارها و کلاسترها', 'orbit' ),
		'map'         => array( 'نقشه کلمات', 'list' ),
		'gaps'        => array( 'فرصت‌ها و شکاف‌ها', 'gap' ),
		'competitors' => array( 'رقبا', 'radar' ),
		'calendar'    => array( 'تقویم محتوا', 'calendar' ),
		'removed'     => array( 'حذف‌شده‌ها', 'filter' ),
		'export'      => array( 'خروجی', 'download' ),
		'settings'    => array( 'تنظیمات پروژه', 'settings' ),
	);
}

/**
 * Route → view + variables.
 */
function mzm_resolve_route( $route ) {
	$parts = array_values( array_filter( explode( '/', $route ), 'strlen' ) );
	$first = $parts[0] ?? '';
	$out   = array( 'route' => $route, 'nav' => $first ?: 'dashboard', 'project' => null );
	switch ( $first ) {
		case 'new':
			return array_merge( $out, array( 'view' => 'project-form', 'title' => 'پروژه جدید', 'vars' => array( 'project' => null ) ) );
		case 'settings':
			return array_merge( $out, array( 'view' => 'settings', 'title' => 'تنظیمات و اتصال‌ها', 'vars' => array() ) );
		case 'guide':
			return array_merge( $out, array( 'view' => 'guide', 'title' => 'راهنما', 'vars' => array() ) );
		case 'project':
			$project = mzm_project( absint( $parts[1] ?? 0 ) );
			if ( $project ) {
				$tabs = mzm_project_tabs();
				$tab  = sanitize_key( $parts[2] ?? 'overview' );
				$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
				return array_merge( $out, array(
					'view'    => 'project',
					'title'   => $project['name'] . ' — ' . $tabs[ $tab ][0],
					'nav'     => 'project',
					'project' => $project,
					'vars'    => array( 'project' => $project, 'tab' => $tab ),
				) );
			}
			status_header( 404 );
			return array_merge( $out, array( 'view' => 'not-found', 'title' => 'یافت نشد', 'vars' => array() ) );
		default:
			return array_merge( $out, array( 'view' => 'dashboard', 'title' => 'داشبورد', 'nav' => 'dashboard', 'vars' => array() ) );
	}
}

/**
 * Output a full-page template and stop.
 */
function mzm_render( $mzm_template, $mzm_args = array() ) {
	extract( $mzm_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include MZM_DIR . '/templates/app/' . $mzm_template . '.php';
	exit;
}

/**
 * Asset tags (no theme, no wp_head).
 */
function mzm_app_assets() {
	printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( MZM_URL . 'assets/css/app.css?ver=' . MZM_VERSION ) );
	printf(
		'<script>window.MZM = %s;</script>' . "\n",
		wp_json_encode( array(
			'rest'  => esc_url_raw( rest_url( 'manzoomeh/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) )
	);
	printf( '<script src="%s" defer></script>' . "\n", esc_url( MZM_URL . 'assets/js/app.js?ver=' . MZM_VERSION ) );
}

/**
 * Login failures come back to the app's own login screen.
 */
add_action( 'wp_login_failed', function () {
	$to = isset( $_POST['mzm_login'], $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore
	if ( $to ) {
		wp_safe_redirect( add_query_arg( 'mzm_login', 'failed', $to ) );
		exit;
	}
} );
