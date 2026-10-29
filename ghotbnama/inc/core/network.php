<?php
/**
 * Network routing (same rules as Sokan).
 *
 * Sites in Iran often reach Google only through a local proxy (WP_PROXY_HOST).
 * «smart» mode (default): only Google, SERP APIs and foreign AI APIs use the
 * proxy; Iranian services (GapGPT) and client sites connect directly. When the
 * proxy is down, a request is retried once directly.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosts that need the proxy in smart mode.
 */
function qtb_proxy_hosts() {
	return array( 'googleapis.com', 'google.com', 'googleusercontent.com', 'api.anthropic.com', 'api.openai.com', 'google.serper.dev', 'serpapi.com', 'api.dataforseo.com' );
}

/**
 * Is a proxy configured in wp-config.php?
 */
function qtb_proxy_defined() {
	return defined( 'WP_PROXY_HOST' ) && '' !== (string) WP_PROXY_HOST;
}

/**
 * Does a host match one of the patterns (suffix match)?
 */
function qtb_host_in( $host, $patterns ) {
	$host = strtolower( (string) $host );
	foreach ( $patterns as $p ) {
		$p = strtolower( trim( (string) $p ) );
		if ( '' !== $p && ( $host === $p || substr( $host, -strlen( '.' . $p ) ) === '.' . $p ) ) {
			return true;
		}
	}
	return false;
}

add_filter( 'pre_http_send_through_proxy', function ( $override, $uri, $check ) {
	if ( ! qtb_proxy_defined() ) {
		return $override;
	}
	if ( ! empty( $GLOBALS['qtb_force_direct'] ) ) {
		return false;
	}
	$host = (string) ( $check['host'] ?? '' );
	$ours = qtb_host_in( $host, qtb_proxy_hosts() );
	// With Sokan active its own filter routes everything else; only decide for our hosts.
	if ( defined( 'SAP_VERSION' ) && ! $ours ) {
		return $override;
	}
	$mode = qtb_setting( 'proxy_mode', 'smart' );
	if ( 'all' === $mode ) {
		return true;
	}
	if ( 'none' === $mode ) {
		return false;
	}
	return $ours;
}, 11, 3 );

/**
 * Is an HTTP error a dead proxy?
 */
function qtb_is_proxy_down( $error ) {
	$msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
	return qtb_proxy_defined() && ( false !== strpos( $msg, 'cURL error 7' ) || false !== strpos( $msg, 'cURL error 97' ) ) && false !== strpos( $msg, (string) WP_PROXY_HOST );
}

/**
 * Persian explanation of a network error.
 */
function qtb_net_error( $error ) {
	$msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
	if ( qtb_is_proxy_down( $msg ) ) {
		return 'پروکسی وردپرس خاموش است. نرم‌افزار پروکسی (مثلاً v2rayN) را روشن کنید و دوباره امتحان کنید.';
	}
	if ( false !== strpos( $msg, 'cURL error 28' ) ) {
		return 'مهلت اتصال تمام شد (شبکه کند یا مسدود). دوباره امتحان کنید.';
	}
	if ( false !== strpos( $msg, 'cURL error 6' ) ) {
		return 'نام دامنه پیدا نشد؛ اینترنت سرور یا آدرس را بررسی کنید.';
	}
	return $msg;
}

/**
 * HTTP request that retries once without the proxy when the proxy is down.
 */
function qtb_http( $url, $args ) {
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) && qtb_is_proxy_down( $res ) ) {
		$GLOBALS['qtb_force_direct'] = true;
		$res                         = wp_remote_request( $url, $args );
		unset( $GLOBALS['qtb_force_direct'] );
	}
	return $res;
}
