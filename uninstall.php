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

delete_post_meta_by_key( '_neotiq_geo_cache' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'neo_geo_results`' );

delete_option( 'neotiq_geo_db_version' );
