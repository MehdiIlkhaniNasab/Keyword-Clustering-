<?php
/**
 * Google access token: Sokan's connection (OAuth refresh token or service
 * account) or Ghotbnama's own service account.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_GOOGLE_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

/**
 * Is a Google connection available?
 */
function qtb_google_connected() {
	return 'off' !== qtb_google_conf()['source'];
}

/**
 * Service account e-mail (the address to share sheets with).
 */
function qtb_google_service_email() {
	$conf = qtb_google_conf();
	if ( 'service' !== $conf['mode'] ) {
		return '';
	}
	$json = json_decode( (string) $conf['service_account'], true );
	return (string) ( $json['client_email'] ?? '' );
}

/**
 * Valid access token (cached ~1h).
 */
function qtb_google_token() {
	$conf = qtb_google_conf();
	$ck   = 'qtb_google_token_' . md5( $conf['source'] . $conf['mode'] . $conf['refresh_token'] . qtb_google_service_email() );
	$hit  = get_transient( $ck );
	if ( $hit ) {
		return $hit;
	}
	if ( 'off' === $conf['source'] ) {
		return new WP_Error( 'qtb_google_off', 'اتصال گوگل تنظیم نشده. در «تنظیمات › گوگل شیت» فایل JSON سرویس اکانت را وارد کنید یا از اتصال سکان استفاده کنید.' );
	}
	if ( 'service' === $conf['mode'] ) {
		$token = qtb_google_service_account_token( $conf['service_account'] );
	} else {
		$res = qtb_http( 'https://oauth2.googleapis.com/token', array(
			'method'  => 'POST',
			'timeout' => 20,
			'body'    => array(
				'client_id'     => $conf['client_id'],
				'client_secret' => $conf['client_secret'],
				'refresh_token' => $conf['refresh_token'],
				'grant_type'    => 'refresh_token',
			),
		) );
		$token = is_wp_error( $res ) ? $res : json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( is_wp_error( $token ) ) {
		return new WP_Error( 'qtb_google_net', 'اتصال به گوگل ناموفق بود: ' . qtb_net_error( $token ) );
	}
	if ( empty( $token['access_token'] ) ) {
		$err = (string) ( $token['error'] ?? '' );
		if ( 'invalid_grant' === $err ) {
			return new WP_Error( 'qtb_google_token', 'اتصال گوگل سکان منقضی شده است. در سکان دوباره «اتصال حساب گوگل» را بزنید یا در قطب‌نما سرویس اکانت تعریف کنید.' );
		}
		return new WP_Error( 'qtb_google_token', 'دریافت توکن گوگل ناموفق بود: ' . ( $token['error_description'] ?? $err ?: 'unknown' ) );
	}
	if ( 'service' !== $conf['mode'] && isset( $token['scope'] ) && false === strpos( (string) $token['scope'], 'auth/spreadsheets' ) ) {
		return new WP_Error( 'qtb_google_scope', 'حساب گوگل سکان اجازه Google Sheets ندارد. در سکان دوباره «اتصال حساب گوگل» را بزنید و تیک Google Sheets را بزنید.' );
	}
	set_transient( $ck, $token['access_token'], max( 60, (int) ( $token['expires_in'] ?? 3600 ) - 120 ) );
	return $token['access_token'];
}

/**
 * Service account JSON → access token (signed JWT, RS256).
 */
function qtb_google_service_account_token( $json_text ) {
	$json = json_decode( (string) $json_text, true );
	if ( empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
		return new WP_Error( 'qtb_sa', 'فایل JSON سرویس اکانت معتبر نیست.' );
	}
	$b64    = function ( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	};
	$now    = time();
	$header = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = $b64( wp_json_encode( array(
		'iss'   => $json['client_email'],
		'scope' => QTB_GOOGLE_SCOPE,
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	) ) );
	$sig = '';
	if ( ! openssl_sign( $header . '.' . $claims, $sig, $json['private_key'], 'sha256WithRSAEncryption' ) ) {
		return new WP_Error( 'qtb_sa_sign', 'امضای JWT ناموفق بود (کلید خصوصی سرویس اکانت نامعتبر است).' );
	}
	$res = qtb_http( 'https://oauth2.googleapis.com/token', array(
		'method'  => 'POST',
		'timeout' => 20,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $header . '.' . $claims . '.' . $b64( $sig ),
		),
	) );
	return is_wp_error( $res ) ? $res : json_decode( wp_remote_retrieve_body( $res ), true );
}

/**
 * Authorized JSON request to a Google API.
 */
function qtb_google_request( $method, $url, $body = null, $timeout = 90 ) {
	$token = qtb_google_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( array() === $body ? new stdClass() : $body, JSON_UNESCAPED_UNICODE );
	}
	$res = qtb_http( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'qtb_google_net', 'اتصال به گوگل ناموفق بود: ' . qtb_net_error( $res ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code >= 400 ) {
		$msg = (string) ( $data['error']['message'] ?? ( 'HTTP ' . $code ) );
		if ( 403 === $code && preg_match( '/permission|caller does not have/i', $msg ) ) {
			$mail = qtb_google_service_email();
			return new WP_Error( 'qtb_sheet_access', 'به این گوگل شیت دسترسی وجود ندارد. شیت را' . ( $mail ? ' با ایمیل ' . $mail : ' با حساب گوگل متصل' ) . ' با دسترسی Editor به‌اشتراک بگذارید.', array( 'status' => $code ) );
		}
		if ( preg_match( '/has not been used in project|it is disabled|SERVICE_DISABLED|accessNotConfigured/i', $msg ) ) {
			return new WP_Error( 'qtb_google_api_off', 'Google Sheets API در پروژه Google Cloud سرویس اکانت فعال نیست. در console.cloud.google.com بخش APIs › Library، «Google Sheets API» را Enable کنید و چند دقیقه بعد دوباره امتحان کنید.', array( 'status' => $code ) );
		}
		return new WP_Error( 'qtb_google_http', 'خطای API گوگل: ' . $msg, array( 'status' => $code ) );
	}
	return is_array( $data ) ? $data : array();
}
