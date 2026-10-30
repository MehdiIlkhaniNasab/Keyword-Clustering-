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
 * Without pretty permalinks the same routes work as /?qtb_app=1&qtb_route=…
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_REWRITE_VERSION = '1';

/**
 * Base path of the app. Filterable.
 */
function qtb_app_base() {
	return trim( (string) apply_filters( 'qtb_app_base', 'seo/cluster' ), '/' );
}

/**
 * App URL of a route.
 */
function qtb_url( $route = '', $args = array() ) {
	$route = trim( (string) $route, '/' );
	if ( get_option( 'permalink_structure' ) ) {
		$url = home_url( '/' . qtb_app_base() . '/' . ( '' !== $route ? $route . '/' : '' ) );
	} else {
		$url = add_query_arg( array( 'qtb_app' => 1, 'qtb_route' => $route ), home_url( '/' ) );
	}
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Project screen URL.
 */
function qtb_project_url( $project_id, $tab = 'overview', $args = array() ) {
	return qtb_url( 'project/' . (int) $project_id . ( 'overview' !== $tab ? '/' . $tab : '' ), $args );
}

add_action( 'init', function () {
	add_rewrite_rule( '^' . preg_quote( qtb_app_base(), '#' ) . '/?(.*)$', 'index.php?qtb_app=1&qtb_route=$matches[1]', 'top' );
	if ( get_option( 'qtb_rewrite_version' ) !== QTB_REWRITE_VERSION . '|' . qtb_app_base() ) {
		flush_rewrite_rules( false );
		update_option( 'qtb_rewrite_version', QTB_REWRITE_VERSION . '|' . qtb_app_base(), false );
	}
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'qtb_app';
	$vars[] = 'qtb_route';
	return $vars;
} );

/**
 * wp-admin keeps one menu item (and an admin-bar link) that opens the app.
 */
add_action( 'admin_menu', function () {
	add_menu_page( 'قطب‌نما', 'قطب‌نما (کلاسترینگ)', QTB_CAP, 'qtb', '__return_null', 'dashicons-networking', 4 );
} );
add_action( 'load-toplevel_page_qtb', function () {
	wp_safe_redirect( qtb_url() );
	exit;
} );
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( qtb_can() ) {
		$bar->add_node( array( 'id' => 'qtb', 'title' => 'قطب‌نما', 'href' => qtb_url() ) );
	}
}, 81 );

/**
 * Render the app before the theme.
 */
add_action( 'template_redirect', function () {
	if ( ! get_query_var( 'qtb_app' ) ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow', true );
	status_header( 200 );
	show_admin_bar( false );
	$route = trim( (string) get_query_var( 'qtb_route' ), '/' );
	if ( ! is_user_logged_in() ) {
		qtb_render( 'login', array( 'redirect' => qtb_url( $route ) ) );
	}
	if ( ! qtb_can_review() ) {
		status_header( 403 );
		qtb_render( 'denied' );
	}
	wp_raise_memory_limit( 'admin' );
	qtb_handle_post( $route );
	qtb_render( 'shell', qtb_resolve_route( $route ) );
}, 1 );

/**
 * Project tabs.
 */
function qtb_project_tabs() {
	return array(
		'overview'    => array( 'نمای کلی', 'grid' ),
		'workshop'    => array( 'کارگاه کلاسترینگ', 'layers' ),
		'clusters'    => array( 'پیلارها و کلاسترها', 'compass' ),
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
function qtb_resolve_route( $route ) {
	$parts = array_values( array_filter( explode( '/', $route ), 'strlen' ) );
	$first = $parts[0] ?? '';
	$out   = array( 'route' => $route, 'nav' => $first ?: 'dashboard', 'project' => null );
	// SEO team reviewers: the project list, the workshop and the guide.
	if ( ! qtb_can() ) {
		if ( in_array( $first, array( 'new', 'settings' ), true ) ) {
			$first = '';
		}
		if ( 'project' === $first ) {
			$parts[2] = 'workshop';
		}
	}
	switch ( $first ) {
		case 'new':
			return array_merge( $out, array( 'view' => 'project-form', 'title' => 'پروژه جدید', 'vars' => array( 'project' => null ) ) );
		case 'settings':
			return array_merge( $out, array( 'view' => 'settings', 'title' => 'تنظیمات و اتصال‌ها', 'vars' => array() ) );
		case 'guide':
			return array_merge( $out, array( 'view' => 'guide', 'title' => 'راهنما', 'vars' => array() ) );
		case 'project':
			$project = qtb_project( absint( $parts[1] ?? 0 ) );
			if ( $project ) {
				$tabs = qtb_project_tabs();
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
function qtb_render( $qtb_template, $qtb_args = array() ) {
	extract( $qtb_args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include QTB_DIR . '/templates/app/' . $qtb_template . '.php';
	exit;
}

/**
 * Asset tags (no theme, no wp_head).
 */
function qtb_app_assets() {
	printf( '<link rel="stylesheet" href="%s">' . "\n", esc_url( QTB_URL . 'assets/css/app.css?ver=' . QTB_VERSION ) );
	printf(
		'<script>window.QTB = %s;</script>' . "\n",
		wp_json_encode( array(
			'rest'  => esc_url_raw( rest_url( 'ghotbnama/v1/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) )
	);
	printf( '<script src="%s" defer></script>' . "\n", esc_url( QTB_URL . 'assets/js/app.js?ver=' . QTB_VERSION ) );
}

/**
 * Login failures come back to the app's own login screen.
 */
add_action( 'wp_login_failed', function () {
	$to = isset( $_POST['qtb_login'], $_POST['redirect_to'] ) ? wp_unslash( $_POST['redirect_to'] ) : ''; // phpcs:ignore
	if ( $to ) {
		wp_safe_redirect( add_query_arg( 'qtb_login', 'failed', $to ) );
		exit;
	}
} );
