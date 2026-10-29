<?php
/**
 * Named project payloads (compressed JSON).
 *
 * Names used by the pipeline:
 *   input      raw keywords [{k, v}] as imported
 *   keywords   cleaned keywords (see mzm_clean_run)
 *   removed    removed keywords with the reason
 *   clusters   clusters with metrics
 *   pillars    pillars → sections → cluster ids
 *   inventory  pages of the site (products, categories, posts)
 *   calendar   content calendar
 *   job        pipeline state
 *   meta       run summary (counts, costs, dates)
 *
 * @package Manzoomeh
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a payload.
 */
function mzm_data_get( $project_id, $name, $default = array() ) {
	global $wpdb;
	$key = (int) $project_id . '|' . $name;
	if ( isset( $GLOBALS['mzm_data_cache'][ $key ] ) ) {
		return $GLOBALS['mzm_data_cache'][ $key ];
	}
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM ' . mzm_table( 'data' ) . ' WHERE project_id = %d AND name = %s', (int) $project_id, $name ) ); // phpcs:ignore
	if ( null === $raw ) {
		return $default;
	}
	$data = mzm_unpack( $raw );
	$GLOBALS['mzm_data_cache'][ $key ] = $data;
	return $data;
}

/**
 * Write a payload.
 */
function mzm_data_put( $project_id, $name, $data ) {
	global $wpdb;
	$t      = mzm_table( 'data' );
	$packed = mzm_pack( $data );
	$id     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE project_id = %d AND name = %s", (int) $project_id, $name ) ); // phpcs:ignore
	if ( $id ) {
		$wpdb->update( $t, array( 'payload' => $packed, 'updated_at' => mzm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore
	} else {
		$wpdb->insert( $t, array( 'project_id' => (int) $project_id, 'name' => $name, 'payload' => $packed, 'updated_at' => mzm_now() ) ); // phpcs:ignore
	}
	$GLOBALS['mzm_data_cache'][ (int) $project_id . '|' . $name ] = $data;
}

/**
 * When a payload was last written ('' = never).
 */
function mzm_data_time( $project_id, $name ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT updated_at FROM ' . mzm_table( 'data' ) . ' WHERE project_id = %d AND name = %s', (int) $project_id, $name ) ); // phpcs:ignore
}

/**
 * Delete payloads.
 */
function mzm_data_delete( $project_id, $names ) {
	global $wpdb;
	foreach ( (array) $names as $name ) {
		$wpdb->delete( mzm_table( 'data' ), array( 'project_id' => (int) $project_id, 'name' => $name ) ); // phpcs:ignore
		unset( $GLOBALS['mzm_data_cache'][ (int) $project_id . '|' . $name ] );
	}
}
