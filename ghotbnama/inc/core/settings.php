<?php
/**
 * Global settings: AI, SERP data, Google access, network and project defaults.
 *
 * Ghotbnama can share its connections with Sokan (سُکان) installed on the same
 * site: the AI model, the SERP key and the Google access are read from Sokan's
 * settings (decrypted with Sokan's key), so the agency configures them once.
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

const QTB_SECRET_KEYS = array( 'ai_gateway_key', 'anthropic_key', 'serp_key', 'dfs_password', 'google_service_account' );

/**
 * Default settings.
 */
function qtb_settings_defaults() {
	return array(
		// AI: "sokan" = the model connected to Sokan; "own" = the fields below.
		'ai_source'              => 'sokan',
		'ai_provider'            => 'gateway', // gateway (OpenAI-compatible, e.g. GapGPT) | anthropic.
		'ai_base_url'            => 'https://api.gapgpt.app/v1',
		'ai_gateway_key'         => '',
		'anthropic_key'          => '',
		'ai_model'               => 'gpt-5-mini',   // Deep stages: structure, gap analysis.
		'ai_model_light'         => 'gpt-4.1-mini', // Bulk stages: keyword filter, naming, briefs.
		'ai_budget'              => 5,             // Monthly AI budget of Ghotbnama (USD).
		// SERP: "sokan" = Sokan's Serper / SerpApi key; "own" = the fields below; "off" = free mode only.
		'serp_source'            => 'sokan',
		'serp_provider'          => 'serper', // serper|dataforseo|serpapi.
		'serp_key'               => '',
		'dfs_login'              => '',
		'dfs_password'           => '',
		'serp_gl'                => 'ir',
		'serp_hl'                => 'fa',
		'serp_cache_days'        => 30,
		'serp_max_project'       => 2000, // Hard cap of paid SERP requests per run.
		// Google: "sokan" = Sokan's OAuth / service account; "own" = a service account JSON.
		'google_source'          => 'sokan',
		'google_service_account' => '',
		'proxy_mode'             => 'smart', // smart|all|none (when WP_PROXY_HOST is set).
		// Defaults of new projects.
		'def_months'             => 3,
		'def_per_month'          => 8,
		'def_cr'                 => 1.5,
		'def_aov'                => 0,
	);
}

/**
 * All settings (secrets decrypted).
 */
function qtb_settings() {
	if ( isset( $GLOBALS['qtb_settings_cache'] ) ) {
		return $GLOBALS['qtb_settings_cache'];
	}
	$stored = get_option( 'qtb_settings', array() );
	$s      = wp_parse_args( is_array( $stored ) ? $stored : array(), qtb_settings_defaults() );
	foreach ( QTB_SECRET_KEYS as $k ) {
		$s[ $k ] = qtb_decrypt( $s[ $k ] );
	}
	$GLOBALS['qtb_settings_cache'] = $s;
	return $s;
}

/**
 * One setting.
 */
function qtb_setting( $key, $default = '' ) {
	$s = qtb_settings();
	return isset( $s[ $key ] ) && '' !== $s[ $key ] ? $s[ $key ] : $default;
}

/**
 * Partial update. Secrets are encrypted at rest.
 */
function qtb_update_settings( $changes ) {
	$stored = get_option( 'qtb_settings', array() );
	$stored = is_array( $stored ) ? $stored : array();
	foreach ( $changes as $k => $v ) {
		$stored[ $k ] = in_array( $k, QTB_SECRET_KEYS, true ) ? qtb_encrypt( $v ) : $v;
	}
	update_option( 'qtb_settings', $stored, false );
	unset( $GLOBALS['qtb_settings_cache'] );
}

/**
 * Sokan's settings on this site (secrets decrypted), or [] when Sokan never ran here.
 */
function qtb_sokan_settings() {
	if ( isset( $GLOBALS['qtb_sokan_cache'] ) ) {
		return $GLOBALS['qtb_sokan_cache'];
	}
	$s = get_option( 'sap_settings', array() );
	if ( ! is_array( $s ) || ! $s ) {
		$GLOBALS['qtb_sokan_cache'] = array();
		return array();
	}
	foreach ( array( 'ai_gateway_key', 'anthropic_key', 'serp_key', 'google_client_secret', 'google_refresh_token', 'google_service_account' ) as $k ) {
		if ( isset( $s[ $k ] ) ) {
			$s[ $k ] = qtb_decrypt( $s[ $k ], 'seo-action-plan' );
		}
	}
	$GLOBALS['qtb_sokan_cache'] = $s;
	return $s;
}

/**
 * Is Sokan installed on this site (active or with saved settings)?
 */
function qtb_sokan_present() {
	return defined( 'SAP_VERSION' ) || (bool) qtb_sokan_settings();
}

/**
 * Effective AI configuration.
 *
 * @return array{source: string, provider: string, base_url: string, key: string, anthropic_key: string, model: string, model_light: string}
 */
function qtb_ai_conf() {
	$own = qtb_settings();
	$sk  = qtb_sokan_settings();
	if ( 'sokan' === $own['ai_source'] && $sk && ( '' !== (string) ( $sk['ai_gateway_key'] ?? '' ) || '' !== (string) ( $sk['anthropic_key'] ?? '' ) ) ) {
		return array(
			'source'        => 'sokan',
			'provider'      => (string) ( $sk['ai_provider'] ?? 'gateway' ),
			'base_url'      => (string) ( $sk['ai_base_url'] ?? 'https://api.gapgpt.app/v1' ),
			'key'           => (string) ( $sk['ai_gateway_key'] ?? '' ),
			'anthropic_key' => (string) ( $sk['anthropic_key'] ?? '' ),
			'model'         => (string) ( ( $sk['ai_model'] ?? '' ) ?: 'gpt-5-mini' ),
			'model_light'   => (string) ( ( $sk['ai_model_light'] ?? '' ) ?: ( $sk['ai_model'] ?? 'gpt-4.1-mini' ) ),
		);
	}
	return array(
		'source'        => 'own',
		'provider'      => $own['ai_provider'],
		'base_url'      => $own['ai_base_url'],
		'key'           => $own['ai_gateway_key'],
		'anthropic_key' => $own['anthropic_key'],
		'model'         => $own['ai_model'],
		'model_light'   => $own['ai_model_light'] ?: $own['ai_model'],
	);
}

/**
 * Effective SERP configuration.
 *
 * @return array{source: string, provider: string, key: string, login: string, password: string, gl: string, hl: string}
 */
function qtb_serp_conf() {
	$own = qtb_settings();
	$sk  = qtb_sokan_settings();
	$out = array( 'source' => 'off', 'provider' => '', 'key' => '', 'login' => '', 'password' => '', 'gl' => $own['serp_gl'] ?: 'ir', 'hl' => $own['serp_hl'] ?: 'fa' );
	if ( 'sokan' === $own['serp_source'] && '' !== (string) ( $sk['serp_key'] ?? '' ) ) {
		return array_merge( $out, array(
			'source'   => 'sokan',
			'provider' => (string) ( $sk['serp_provider'] ?? 'serper' ),
			'key'      => (string) $sk['serp_key'],
			'gl'       => (string) ( ( $sk['serp_gl'] ?? '' ) ?: $out['gl'] ),
			'hl'       => (string) ( ( $sk['serp_hl'] ?? '' ) ?: $out['hl'] ),
		) );
	}
	if ( 'off' === $own['serp_source'] ) {
		return $out;
	}
	if ( 'dataforseo' === $own['serp_provider'] ) {
		if ( '' !== $own['dfs_login'] && '' !== $own['dfs_password'] ) {
			return array_merge( $out, array( 'source' => 'own', 'provider' => 'dataforseo', 'login' => $own['dfs_login'], 'password' => $own['dfs_password'] ) );
		}
		return $out;
	}
	if ( '' !== $own['serp_key'] ) {
		return array_merge( $out, array( 'source' => 'own', 'provider' => $own['serp_provider'], 'key' => $own['serp_key'] ) );
	}
	return $out;
}

/**
 * Effective Google configuration.
 *
 * @return array{source: string, mode: string, service_account: string, client_id: string, client_secret: string, refresh_token: string}
 */
function qtb_google_conf() {
	$own = qtb_settings();
	$sk  = qtb_sokan_settings();
	if ( 'sokan' === $own['google_source'] && $sk ) {
		$mode = (string) ( $sk['google_auth_mode'] ?? 'oauth' );
		$ok   = 'service' === $mode ? '' !== (string) ( $sk['google_service_account'] ?? '' ) : '' !== (string) ( $sk['google_refresh_token'] ?? '' );
		if ( $ok ) {
			return array(
				'source'          => 'sokan',
				'mode'            => 'service' === $mode ? 'service' : 'oauth',
				'service_account' => (string) ( $sk['google_service_account'] ?? '' ),
				'client_id'       => (string) ( $sk['google_client_id'] ?? '' ),
				'client_secret'   => (string) ( $sk['google_client_secret'] ?? '' ),
				'refresh_token'   => (string) ( $sk['google_refresh_token'] ?? '' ),
			);
		}
	}
	return array(
		'source'          => '' !== $own['google_service_account'] ? 'own' : 'off',
		'mode'            => 'service',
		'service_account' => $own['google_service_account'],
		'client_id'       => '',
		'client_secret'   => '',
		'refresh_token'   => '',
	);
}
