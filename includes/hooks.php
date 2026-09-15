<?php
/**
 * Automatic sync on save, in the admin and on the front end.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync a post if it is one of ours, once per request.
 *
 * @param int $post_id Post ID.
 */
function neotiq_geo_maybe_sync( $post_id ) {
	$post_id = (int) $post_id;

	if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! in_array( get_post_type( $post_id ), NEOTIQ_GEO_POST_TYPES, true ) ) {
		return;
	}

	static $done = array();

	if ( isset( $done[ $post_id ] ) ) {
		return;
	}

	$done[ $post_id ] = true;

	// Cheap and geocoder-free: mirror the coordinates before anything else, so
	// map listings stay right even when the address cannot be resolved.
	neotiq_geo_extract_coordinates( $post_id );

	neotiq_geo_sync_post(
		$post_id,
		array(
			'apply'          => true,
			'create_euville' => true,
		)
	);
}

// Admin: after ACF has saved its fields. The existing snippet that extracts
// map_lat / map_lng runs at priority 20.
add_action( 'acf/save_post', 'neotiq_geo_maybe_sync', 25 );

/**
 * Front end: JetFormBuilder writes terms and meta in the properties' do_after,
 * which runs AFTER "jet-form-builder/action/after-post-insert". "after-run" is
 * the first point where the post is complete.
 *
 * @param object $modifier JetFormBuilder modifier instance.
 */
function neotiq_geo_jfb_after_run( $modifier ) {
	if ( ! is_object( $modifier ) || ! method_exists( $modifier, 'get_action' ) ) {
		return;
	}

	$action = $modifier->get_action();

	if ( is_object( $action ) && method_exists( $action, 'get_inserted' ) ) {
		neotiq_geo_maybe_sync( $action->get_inserted() );
	}
}
add_action( 'jet-form-builder/modifier/after-run', 'neotiq_geo_jfb_after_run' );
