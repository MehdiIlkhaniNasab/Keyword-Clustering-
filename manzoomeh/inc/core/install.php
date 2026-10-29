<?php
/**
 * Database tables and capabilities.
 *
 *   mzm_projects  one row per client / website.
 *   mzm_data      named, compressed JSON payloads of a project (keywords, clusters, calendar, job state…).
 *   mzm_serp      shared SERP cache (top 10 of Google per keyword and market), reused by all projects.
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Full table name.
 */
function mzm_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'mzm_' . $name;
}

/**
 * Create / upgrade tables.
 */
function mzm_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE " . mzm_table( 'projects' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(190) NOT NULL DEFAULT '',
		site_url varchar(255) NOT NULL DEFAULT '',
		config longtext NULL,
		status varchar(20) NOT NULL DEFAULT 'new',
		created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id)
	) $c;" );
	dbDelta( "CREATE TABLE " . mzm_table( 'data' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		project_id bigint(20) unsigned NOT NULL DEFAULT 0,
		name varchar(60) NOT NULL DEFAULT '',
		payload longtext NULL,
		updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY project_name (project_id,name)
	) $c;" );
	dbDelta( "CREATE TABLE " . mzm_table( 'serp' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		kw_hash char(32) NOT NULL DEFAULT '',
		market varchar(16) NOT NULL DEFAULT '',
		keyword varchar(255) NOT NULL DEFAULT '',
		payload longtext NULL,
		fetched_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY kw_market (kw_hash,market)
	) $c;" );
	update_option( 'mzm_db_version', MZM_DB_VERSION, false );
	delete_option( 'mzm_rewrite_version' );
}

/**
 * Deactivation: nothing to schedule; rewrite rules are rebuilt on next load.
 */
function mzm_deactivate() {
	delete_option( 'mzm_rewrite_version' );
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'mzm_db_version' ) !== MZM_DB_VERSION ) {
		mzm_install();
	}
} );

/**
 * Administrators always have the tool capability.
 */
add_filter( 'user_has_cap', function ( $all ) {
	if ( ! empty( $all['manage_options'] ) ) {
		$all[ MZM_CAP ] = true;
	}
	return $all;
} );
