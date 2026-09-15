<?php
/**
 * AJAX endpoints behind the bulk correction tool.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared guard: valid nonce and the right capability.
 */
function neotiq_geo_ajax_guard() {
	check_ajax_referer( 'neotiq-geo-ajax', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to access this page.', 'neotiq-geo-sync' ) ), 403 );
	}

	// Reverse geocoding is throttled to one call per second per service, so a
	// batch can legitimately take a while.
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * Check one batch of posts and store the results.
 *
 * Walks forward by post ID rather than by offset: in incremental mode the set
 * of posts still to check shrinks as rows are written, and an offset would step
 * over the ones that moved up.
 */
function neotiq_geo_ajax_scan() {
	neotiq_geo_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in neotiq_geo_ajax_guard().
	$args     = neotiq_geo_sanitize_args( $_POST );
	$after_id = isset( $_POST['after_id'] ) ? max( 0, (int) $_POST['after_id'] ) : 0;
	$first    = empty( $_POST['after_id'] );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Counted before anything is stored: in incremental mode every row written
	// leaves the "still to check" set, so counting afterwards would give a
	// denominator the progress can never reach. Once per run is enough.
	$total = $first ? neotiq_geo_scan_total( $args ) : null;

	$post_ids = neotiq_geo_scan_batch( $args, $after_id, $args['chunk'] );
	$rows     = array();

	foreach ( $post_ids as $post_id ) {
		$result = neotiq_geo_sync_post(
			$post_id,
			array(
				'apply'          => $args['apply'],
				'force'          => $args['force'],
				'create_euville' => $args['create_euville'],
			)
		);

		$display = neotiq_geo_display_row( $result );
		neotiq_geo_store_result( $display, $args['apply'] && ! empty( $result['changes'] ) );

		$rows[]   = neotiq_geo_row_payload( $display, ! $args['apply'] );
		$after_id = $post_id;
	}

	$payload = array(
		'rows'     => $rows,
		'afterId'  => $after_id,
		'finished' => count( $post_ids ) < $args['chunk'],
		'summary'  => neotiq_geo_summary( $args['post_type'] ),
	);

	if ( null !== $total ) {
		$payload['total'] = $total;
	}

	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_neotiq_geo_scan', 'neotiq_geo_ajax_scan' );

/**
 * Read a page of stored results, without re-checking anything.
 */
function neotiq_geo_ajax_results() {
	neotiq_geo_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in neotiq_geo_ajax_guard().
	$filter = array(
		'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all',
		'post_type' => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'all',
		'page'      => isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1,
		'per_page'  => isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 50,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	list( $rows, $total ) = neotiq_geo_fetch_results( $filter );

	$payload = array();

	foreach ( $rows as $row ) {
		$payload[] = neotiq_geo_row_payload( $row, 'pending' === $row['status'] );
	}

	wp_send_json_success(
		array(
			'rows'    => $payload,
			'total'   => $total,
			'page'    => $filter['page'],
			'pages'   => (int) ceil( $total / max( 1, $filter['per_page'] ) ),
			'summary' => neotiq_geo_summary( $filter['post_type'] ),
		)
	);
}
add_action( 'wp_ajax_neotiq_geo_results', 'neotiq_geo_ajax_results' );

/**
 * Every post ID matching the current filter, so "apply to all of them" does not
 * depend on ticking thousands of checkboxes.
 */
function neotiq_geo_ajax_result_ids() {
	neotiq_geo_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in neotiq_geo_ajax_guard().
	$filter = array(
		'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all',
		'post_type' => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'all',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	wp_send_json_success( array( 'ids' => neotiq_geo_fetch_result_ids( $filter ) ) );
}
add_action( 'wp_ajax_neotiq_geo_result_ids', 'neotiq_geo_ajax_result_ids' );

/**
 * Apply the corrections to the posts picked in the results table.
 *
 * Each post is re-synced rather than replayed from the stored result, so a post
 * edited in the meantime gets its current state written, not a stale one.
 */
function neotiq_geo_ajax_apply() {
	neotiq_geo_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in neotiq_geo_ajax_guard().
	$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
	$create   = ! empty( $_POST['create_euville'] );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$rows = array();

	foreach ( $post_ids as $post_id ) {
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), NEOTIQ_GEO_POST_TYPES, true ) ) {
			continue;
		}

		$result  = neotiq_geo_sync_post(
			$post_id,
			array(
				'apply'          => true,
				'create_euville' => $create,
			)
		);
		$display = neotiq_geo_display_row( $result );

		neotiq_geo_store_result( $display, 'updated' === $result['status'] );

		$rows[] = neotiq_geo_row_payload( $display, false );
	}

	wp_send_json_success(
		array(
			'rows'    => $rows,
			'summary' => neotiq_geo_summary( 'all' ),
		)
	);
}
add_action( 'wp_ajax_neotiq_geo_apply', 'neotiq_geo_ajax_apply' );

/**
 * Empty the results table.
 */
function neotiq_geo_ajax_clear() {
	neotiq_geo_ajax_guard();

	wp_send_json_success(
		array(
			'removed' => neotiq_geo_clear_results(),
			'summary' => neotiq_geo_summary( 'all' ),
		)
	);
}
add_action( 'wp_ajax_neotiq_geo_clear', 'neotiq_geo_ajax_clear' );

/**
 * One row as the browser consumes it.
 *
 * @param array $display    Display row.
 * @param bool  $selectable Offer the checkbox that queues the post.
 *
 * @return array
 */
function neotiq_geo_row_payload( array $display, $selectable ) {
	return array(
		'post_id' => (int) $display['post_id'],
		'status'  => $display['status'],
		'html'    => neotiq_geo_row_html( $display, $selectable ),
	);
}

/**
 * Rebuild the derived fields — map coordinates and listing fields — for one batch.
 *
 * Deliberately independent of the address check: it reads data the post already
 * carries, so it never touches a geocoder and never waits on the one-per-second
 * throttle.
 */
function neotiq_geo_ajax_coordinates() {
	neotiq_geo_ajax_guard();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in neotiq_geo_ajax_guard().
	$args     = neotiq_geo_sanitize_args( $_POST );
	$after_id = isset( $_POST['after_id'] ) ? max( 0, (int) $_POST['after_id'] ) : 0;
	$first    = empty( $_POST['after_id'] );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	// Counted before writing: in "missing only" mode every row written leaves
	// the pending set, so counting afterwards gives an unreachable total.
	$total = $first ? neotiq_geo_coordinates_total( $args ) : null;

	$post_ids = neotiq_geo_coordinates_batch( $args, $after_id, $args['chunk'] );
	$tally    = array(
		'written'   => 0,
		'unchanged' => 0,
		'no_data'   => 0,
	);

	foreach ( $post_ids as $post_id ) {
		$outcome = neotiq_geo_refresh_post( $post_id );
		++$tally[ $outcome ];
		$after_id = $post_id;
	}

	$payload = array(
		'tally'    => $tally,
		'afterId'  => $after_id,
		'finished' => count( $post_ids ) < $args['chunk'],
		'summary'  => neotiq_geo_coordinates_summary( $args['post_type'] ),
	);

	if ( null !== $total ) {
		$payload['total'] = $total;
	}

	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_neotiq_geo_coordinates', 'neotiq_geo_ajax_coordinates' );
