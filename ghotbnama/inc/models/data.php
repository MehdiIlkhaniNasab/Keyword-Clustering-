<?php
/**
 * Named project payloads (compressed JSON).
 *
 * Names used by the pipeline:
 *   input      raw keywords [{k, v}] as imported
 *   keywords   cleaned keywords (see qtb_clean_run)
 *   removed    removed keywords with the reason
 *   clusters   clusters with metrics
 *   pillars    pillars → sections → cluster ids
 *   inventory  pages of the site (products, categories, posts)
 *   calendar   content calendar
 *   job        pipeline state
 *   meta       run summary (counts, costs, dates)
 *
 * @package Ghotbnama
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a payload.
 */
function qtb_data_get( $project_id, $name, $default = array() ) {
	global $wpdb;
	$key = (int) $project_id . '|' . $name;
	if ( isset( $GLOBALS['qtb_data_cache'][ $key ] ) ) {
		return $GLOBALS['qtb_data_cache'][ $key ];
	}
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . qtb_table( 'data' ) . ' WHERE project_id = %d AND name = %s', (int) $project_id, $name ) ); // phpcs:ignore
	if ( null === $raw ) {
		return $default;
	}
	$data = qtb_unpack( $raw );
	$GLOBALS['qtb_data_cache'][ $key ] = $data;
	return $data;
}

/**
 * Write a payload.
 */
function qtb_data_put( $project_id, $name, $data ) {
	global $wpdb;
	$t      = qtb_table( 'data' );
	$packed = qtb_pack( $data );
	$id     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE project_id = %d AND name = %s", (int) $project_id, $name ) ); // phpcs:ignore
	if ( $id ) {
		$wpdb->update( $t, array( 'payload' => $packed, 'updated_at' => qtb_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore
	} else {
		$wpdb->insert( $t, array( 'project_id' => (int) $project_id, 'name' => $name, 'payload' => $packed, 'updated_at' => qtb_now() ) ); // phpcs:ignore
	}
	$GLOBALS['qtb_data_cache'][ (int) $project_id . '|' . $name ] = $data;
}

/**
 * When a payload was last written ('' = never).
 */
function qtb_data_time( $project_id, $name ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT updated_at FROM ' . qtb_table( 'data' ) . ' WHERE project_id = %d AND name = %s', (int) $project_id, $name ) ); // phpcs:ignore
}

/**
 * Delete payloads.
 */
function qtb_data_delete( $project_id, $names ) {
	global $wpdb;
	foreach ( (array) $names as $name ) {
		$wpdb->delete( qtb_table( 'data' ), array( 'project_id' => (int) $project_id, 'name' => $name ) ); // phpcs:ignore
		unset( $GLOBALS['qtb_data_cache'][ (int) $project_id . '|' . $name ] );
	}
}
