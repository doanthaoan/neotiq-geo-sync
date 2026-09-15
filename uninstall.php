<?php
/**
 * Removes everything the plugin created: the results table, its schema version,
 * and the cached reverse geocoding results.
 *
 * Taxonomy assignments are left alone: they are the site's own content, not
 * data this plugin owns.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * Listed here rather than read from the plugin, which is not loaded on uninstall.
 *
 * @return string[]
 */
function neotiq_geo_uninstall_listing_keys() {
	return array( '_neotiq_location', '_neotiq_city', '_neotiq_department', '_neotiq_dept_code' );
}

delete_post_meta_by_key( '_neotiq_geo_cache' );

// The listing fields, all derived from the site's own terms.
foreach ( neotiq_geo_uninstall_listing_keys() as $neotiq_key ) {
	delete_post_meta_by_key( $neotiq_key );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'neo_geo_results`' );

delete_option( 'neotiq_geo_db_version' );
