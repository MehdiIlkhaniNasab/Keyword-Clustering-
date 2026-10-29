<?php
/**
 * Form posts of the app (project, settings). Long work (runs, uploads, edits,
 * Google Sheets) goes through the REST API instead.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle a POSTed app form, then redirect (PRG).
 */
function mzm_handle_post( $route ) {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['mzm_action'] ) ) { // phpcs:ignore
		return;
	}
	$action = sanitize_key( wp_unslash( $_POST['mzm_action'] ) ); // phpcs:ignore
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mzm_nonce'] ?? '' ) ), 'mzm_' . $action ) ) { // phpcs:ignore
		mzm_flash_set( 'نشست منقضی شد؛ دوباره امتحان کنید.', false );
		wp_safe_redirect( mzm_url( $route ) );
		exit;
	}
	$post = wp_unslash( $_POST ); // phpcs:ignore
	switch ( $action ) {
		case 'save_project':
			$id   = absint( $post['project_id'] ?? 0 );
			$name = trim( (string) ( $post['name'] ?? '' ) );
			if ( '' === $name ) {
				mzm_flash_set( 'نام پروژه را وارد کنید.', false );
				wp_safe_redirect( $id ? mzm_project_url( $id, 'settings' ) : mzm_url( 'new' ) );
				exit;
			}
			$site = trim( (string) ( $post['site_url'] ?? '' ) );
			if ( '' !== $site && ! preg_match( '#^https?://#', $site ) ) {
				$site = 'https://' . $site;
			}
			$days = array_map( 'intval', (array) ( $post['publish_days'] ?? array() ) );
			$cfg  = array(
				'niche'        => sanitize_textarea_field( (string) ( $post['niche'] ?? '' ) ),
				'brands'       => mzm_list( $post['brands'] ?? '' ),
				'competitors'  => mzm_list( $post['competitors'] ?? '' ),
				'negatives'    => mzm_list( $post['negatives'] ?? '' ),
				'sheet_url'    => esc_url_raw( trim( (string) ( $post['sheet_url'] ?? '' ) ) ),
				'input_tab'    => sanitize_text_field( (string) ( $post['input_tab'] ?? '' ) ) ?: 'کلمات کلیدی',
				'output_url'   => esc_url_raw( trim( (string) ( $post['output_url'] ?? '' ) ) ),
				'auto_write'   => empty( $post['auto_write'] ) ? 0 : 1,
				'store_mode'   => in_array( $post['store_mode'] ?? '', array( 'auto', 'local', 'woo_rest', 'wp_rest', 'sitemap', 'none' ), true ) ? $post['store_mode'] : 'auto',
				'woo_key'      => sanitize_text_field( (string) ( $post['woo_key'] ?? '' ) ),
				'serp_mode'    => in_array( $post['serp_mode'] ?? '', array( 'auto', 'free', 'economy', 'full' ), true ) ? $post['serp_mode'] : 'auto',
				'serp_cap'     => max( 0, (int) ( $post['serp_cap'] ?? 0 ) ),
				'strictness'   => max( 3, min( 5, (int) ( $post['strictness'] ?? 4 ) ) ),
				'min_volume'   => max( 0, (int) ( $post['min_volume'] ?? 0 ) ),
				'months'       => max( 1, min( 12, (int) ( $post['months'] ?? 3 ) ) ),
				'per_month'    => max( 1, min( 40, (int) ( $post['per_month'] ?? 8 ) ) ),
				'start_date'   => mzm_parse_date( (string) ( $post['start_date'] ?? '' ) ),
				'publish_days' => $days ?: array( 6, 2 ),
				'cr'           => max( 0.05, (float) str_replace( '٫', '.', mzm_norm( (string) ( $post['cr'] ?? 1.5 ) ) ) ),
				'aov'          => max( 0, (int) preg_replace( '/\D/', '', mzm_norm( (string) ( $post['aov'] ?? 0 ) ) ) ),
				'gap_limit'    => max( 0, min( 200, (int) ( $post['gap_limit'] ?? 40 ) ) ),
			);
			$secret = trim( (string) ( $post['woo_secret'] ?? '' ) );
			if ( '' !== $secret && false === strpos( $secret, '•' ) ) {
				$cfg['woo_secret'] = $secret;
			} elseif ( $id ) {
				$cfg['woo_secret'] = mzm_project( $id )['cfg']['woo_secret'] ?? '';
			}
			$id = mzm_project_save( array( 'name' => $name, 'site_url' => $site, 'cfg' => $cfg ), $id );
			mzm_flash_set( 'تنظیمات پروژه ذخیره شد.' );
			wp_safe_redirect( empty( $post['project_id'] ) ? mzm_project_url( $id ) : mzm_project_url( $id, 'settings' ) );
			exit;

		case 'delete_project':
			$id = absint( $post['project_id'] ?? 0 );
			mzm_project_delete( $id );
			mzm_flash_set( 'پروژه حذف شد.' );
			wp_safe_redirect( mzm_url() );
			exit;

		case 'save_settings':
			$changes = array(
				'ai_source'        => 'own' === ( $post['ai_source'] ?? '' ) ? 'own' : 'sokan',
				'ai_provider'      => 'anthropic' === ( $post['ai_provider'] ?? '' ) ? 'anthropic' : 'gateway',
				'ai_base_url'      => esc_url_raw( trim( (string) ( $post['ai_base_url'] ?? '' ) ) ) ?: 'https://api.gapgpt.app/v1',
				'ai_model'         => sanitize_text_field( (string) ( $post['ai_model'] ?? '' ) ) ?: 'gpt-5-mini',
				'ai_model_light'   => sanitize_text_field( (string) ( $post['ai_model_light'] ?? '' ) ),
				'ai_budget'        => max( 0, (float) ( $post['ai_budget'] ?? 5 ) ),
				'serp_source'      => in_array( $post['serp_source'] ?? '', array( 'sokan', 'own', 'off' ), true ) ? $post['serp_source'] : 'sokan',
				'serp_provider'    => in_array( $post['serp_provider'] ?? '', array( 'serper', 'dataforseo', 'serpapi' ), true ) ? $post['serp_provider'] : 'serper',
				'dfs_login'        => sanitize_text_field( (string) ( $post['dfs_login'] ?? '' ) ),
				'serp_gl'          => sanitize_key( (string) ( $post['serp_gl'] ?? 'ir' ) ) ?: 'ir',
				'serp_hl'          => sanitize_key( (string) ( $post['serp_hl'] ?? 'fa' ) ) ?: 'fa',
				'serp_cache_days'  => max( 1, min( 180, (int) ( $post['serp_cache_days'] ?? 30 ) ) ),
				'serp_max_project' => max( 0, (int) ( $post['serp_max_project'] ?? 2000 ) ),
				'google_source'    => 'own' === ( $post['google_source'] ?? '' ) ? 'own' : 'sokan',
				'proxy_mode'       => in_array( $post['proxy_mode'] ?? '', array( 'smart', 'all', 'none' ), true ) ? $post['proxy_mode'] : 'smart',
				'def_months'       => max( 1, min( 12, (int) ( $post['def_months'] ?? 3 ) ) ),
				'def_per_month'    => max( 1, min( 40, (int) ( $post['def_per_month'] ?? 8 ) ) ),
				'def_cr'           => max( 0.05, (float) ( $post['def_cr'] ?? 1.5 ) ),
				'def_aov'          => max( 0, (int) ( $post['def_aov'] ?? 0 ) ),
			);
			// Secrets: keep the stored value when the masked placeholder comes back.
			foreach ( array( 'ai_gateway_key', 'anthropic_key', 'serp_key', 'dfs_password', 'google_service_account' ) as $k ) {
				$v = trim( (string) ( $post[ $k ] ?? '' ) );
				if ( '' !== $v && false === strpos( $v, '•' ) ) {
					$changes[ $k ] = $v;
				} elseif ( ! empty( $post[ $k . '_clear' ] ) ) {
					$changes[ $k ] = '';
				}
			}
			if ( isset( $changes['google_service_account'] ) && '' !== $changes['google_service_account'] ) {
				$json = json_decode( $changes['google_service_account'], true );
				if ( empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
					unset( $changes['google_service_account'] );
					mzm_update_settings( $changes );
					mzm_flash_set( 'تنظیمات ذخیره شد، اما JSON سرویس اکانت معتبر نبود (client_email و private_key ندارد).', false );
					wp_safe_redirect( mzm_url( 'settings' ) );
					exit;
				}
			}
			mzm_update_settings( $changes );
			mzm_flash_set( 'تنظیمات ذخیره شد.' );
			wp_safe_redirect( mzm_url( 'settings' ) );
			exit;
	}
}
