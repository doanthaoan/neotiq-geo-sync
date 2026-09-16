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

// Everything this plugin writes to a post is prefixed, so one query covers the
// listing fields and the geocoding cache. Matching the prefix rather than listing the
// keys is what stops this drifting behind includes/display.php, which is not loaded
// on uninstall and so cannot be asked.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_neotiq_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'neo_geo_results`' );

delete_option( 'neotiq_geo_db_version' );
