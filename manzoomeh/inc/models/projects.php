<?php
/**
 * Projects: one per client website, with its clustering configuration.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default project configuration.
 */
function mzm_project_defaults() {
	return array(
		'niche'          => '',           // What the business sells / does (guides relevance and naming).
		'brands'         => array(),      // Own brand names (kept, marked as brand).
		'competitors'    => array(),      // Competitor brands / sites (removed).
		'negatives'      => array(),      // Extra words that make a keyword useless for this project.
		'sheet_url'      => '',           // Input Google Sheet.
		'input_tab'      => 'کلمات کلیدی',
		'output_url'     => '',           // Output Google Sheet ('' = the input sheet).
		'auto_write'     => 0,            // Write the result into Google Sheets after each run.
		'store_mode'     => 'auto',       // auto|local|woo_rest|wp_rest|sitemap|none.
		'woo_key'        => '',
		'woo_secret'     => '',
		'serp_mode'      => 'auto',       // auto|free|economy|full.
		'serp_cap'       => 0,            // 0 = global cap.
		'strictness'     => 4,            // Shared top-10 URLs needed to merge: 3 flexible, 4 balanced, 5 strict.
		'min_volume'     => 0,            // Keywords below are removed.
		'months'         => (int) mzm_setting( 'def_months', 3 ),
		'per_month'      => (int) mzm_setting( 'def_per_month', 8 ),
		'start_date'     => '',           // Gregorian Y-m-d of the first publish date ('' = next Saturday).
		'publish_days'   => array( 6, 2 ), // PHP weekday numbers (6 = شنبه, 2 = سه‌شنبه).
		'cr'             => (float) mzm_setting( 'def_cr', 1.5 ), // Conversion rate of commercial pages (%).
		'aov'            => (float) mzm_setting( 'def_aov', 0 ),  // Average order value (Toman); 0 = from the store.
		'gap_limit'      => 40,           // Clusters that get the AI gap analysis.
	);
}

/**
 * Normalize a raw project row.
 */
function mzm_project_row( $row ) {
	if ( ! $row ) {
		return null;
	}
	$cfg = json_decode( (string) $row['config'], true );
	$cfg = wp_parse_args( is_array( $cfg ) ? $cfg : array(), mzm_project_defaults() );
	if ( '' !== (string) $cfg['woo_secret'] ) {
		$cfg['woo_secret'] = mzm_decrypt( $cfg['woo_secret'] );
	}
	return array(
		'id'         => (int) $row['id'],
		'name'       => (string) $row['name'],
		'site_url'   => (string) $row['site_url'],
		'status'     => (string) $row['status'],
		'created_at' => (string) $row['created_at'],
		'updated_at' => (string) $row['updated_at'],
		'cfg'        => $cfg,
	);
}

/**
 * One project.
 */
function mzm_project( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . mzm_table( 'projects' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
	return mzm_project_row( $row );
}

/**
 * All projects, newest first.
 */
function mzm_projects() {
	global $wpdb;
	$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . mzm_table( 'projects' ) . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore
	return array_values( array_filter( array_map( 'mzm_project_row', $rows ) ) );
}

/**
 * Create or update a project.
 *
 * @return int Project id.
 */
function mzm_project_save( $data, $id = 0 ) {
	global $wpdb;
	$old = $id ? mzm_project( $id ) : null;
	$cfg = wp_parse_args( (array) ( $data['cfg'] ?? array() ), $old ? $old['cfg'] : mzm_project_defaults() );
	if ( '' !== (string) $cfg['woo_secret'] ) {
		$cfg['woo_secret'] = mzm_encrypt( $cfg['woo_secret'] );
	}
	$row = array(
		'name'       => sanitize_text_field( (string) ( $data['name'] ?? ( $old['name'] ?? '' ) ) ),
		'site_url'   => esc_url_raw( (string) ( $data['site_url'] ?? ( $old['site_url'] ?? '' ) ) ),
		'config'     => wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE ),
		'updated_at' => mzm_now(),
	);
	if ( $old ) {
		$wpdb->update( mzm_table( 'projects' ), $row, array( 'id' => (int) $id ) ); // phpcs:ignore
		return (int) $id;
	}
	$row['status']     = 'new';
	$row['created_at'] = mzm_now();
	$wpdb->insert( mzm_table( 'projects' ), $row ); // phpcs:ignore
	return (int) $wpdb->insert_id;
}

/**
 * Set a project's status (new|running|ready|error).
 */
function mzm_project_status( $id, $status ) {
	global $wpdb;
	$wpdb->update( mzm_table( 'projects' ), array( 'status' => $status, 'updated_at' => mzm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore
}

/**
 * Delete a project and its data.
 */
function mzm_project_delete( $id ) {
	global $wpdb;
	$wpdb->delete( mzm_table( 'projects' ), array( 'id' => (int) $id ) ); // phpcs:ignore
	$wpdb->delete( mzm_table( 'data' ), array( 'project_id' => (int) $id ) ); // phpcs:ignore
}

/**
 * Own domain of a project (host without www).
 */
function mzm_project_domain( $project ) {
	return mzm_host( $project['site_url'] );
}
