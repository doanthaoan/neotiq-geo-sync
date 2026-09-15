<?php
/**
 * Persisted scan results.
 *
 * One row per post, overwritten on each check, rather than a snapshot per run.
 * That is what makes a second pass cheap: the scan can skip everything already
 * checked and untouched since, so a 10 000 post catalogue is walked once and
 * then only where addresses actually moved.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Results table name.
 *
 * Underscores, not hyphens: a hyphen in a MySQL identifier has to be backticked
 * everywhere it appears, and one missed backtick is a syntax error at runtime.
 *
 * @return string
 */
function neotiq_geo_table() {
	global $wpdb;

	return $wpdb->prefix . 'neo_geo_results';
}

/**
 * Create or upgrade the results table. Safe to call repeatedly.
 */
function neotiq_geo_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table = neotiq_geo_table();

	// Nullable datetimes: this server runs with NO_ZERO_DATE, which rejects the
	// usual '0000-00-00 00:00:00' default.
	$sql = "CREATE TABLE {$table} (
		post_id bigint(20) unsigned NOT NULL,
		post_type varchar(20) NOT NULL DEFAULT '',
		status varchar(20) NOT NULL DEFAULT '',
		label varchar(255) NOT NULL DEFAULT '',
		changes longtext NOT NULL,
		notes longtext NOT NULL,
		post_modified datetime DEFAULT NULL,
		checked_at datetime DEFAULT NULL,
		applied_at datetime DEFAULT NULL,
		PRIMARY KEY  (post_id),
		KEY status (status),
		KEY post_type_status (post_type,status),
		KEY checked_at (checked_at)
	) " . $wpdb->get_charset_collate() . ';';

	dbDelta( $sql );

	update_option( 'neotiq_geo_db_version', NEOTIQ_GEO_DB_VERSION );
}

/**
 * Run the installer when the plugin files were updated without a reactivation.
 */
function neotiq_geo_maybe_install() {
	if ( get_option( 'neotiq_geo_db_version' ) !== NEOTIQ_GEO_DB_VERSION ) {
		neotiq_geo_install();
	}
}

/**
 * Store, or overwrite, the result for one post.
 *
 * @param array $display Display row, from neotiq_geo_display_row().
 * @param bool  $applied Whether corrections were just written.
 */
function neotiq_geo_store_result( array $display, $applied = false ) {
	global $wpdb;

	$post = get_post( $display['post_id'] );
	$now  = current_time( 'mysql', true );

	$data = array(
		'post_id'       => (int) $display['post_id'],
		'post_type'     => $post ? $post->post_type : '',
		'status'        => $display['status'],
		'label'         => mb_substr( (string) $display['label'], 0, 255 ),
		'changes'       => wp_json_encode( $display['changes'] ),
		'notes'         => wp_json_encode( $display['notes'] ),
		'post_modified' => $post ? $post->post_modified_gmt : null,
		'checked_at'    => $now,
	);

	if ( $applied ) {
		$data['applied_at'] = $now;
	}

	// REPLACE would drop applied_at on a later plain re-check; an upsert keeps it.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			'INSERT INTO `' . neotiq_geo_table() . '`
				(post_id, post_type, status, label, changes, notes, post_modified, checked_at, applied_at)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE
				post_type = VALUES(post_type),
				status = VALUES(status),
				label = VALUES(label),
				changes = VALUES(changes),
				notes = VALUES(notes),
				post_modified = VALUES(post_modified),
				checked_at = VALUES(checked_at),
				applied_at = COALESCE(VALUES(applied_at), applied_at)',
			$data['post_id'],
			$data['post_type'],
			$data['status'],
			$data['label'],
			$data['changes'],
			$data['notes'],
			$data['post_modified'],
			$data['checked_at'],
			isset( $data['applied_at'] ) ? $data['applied_at'] : null
		)
	);
}

/**
 * Post statuses a scan walks through.
 *
 * @param string $post_status 'any' or a single status.
 *
 * @return string[]
 */
function neotiq_geo_scan_statuses( $post_status ) {
	return 'any' === $post_status
		? array( 'publish', 'pending', 'draft', 'future', 'private' )
		: array( $post_status );
}

/**
 * SQL fragment restricting a scan to the requested posts.
 *
 * @param array $args Sanitised tool settings.
 *
 * @return array [ where sql, bound values ]
 */
function neotiq_geo_scan_where( array $args ) {
	$types    = 'all' === $args['post_type'] ? NEOTIQ_GEO_POST_TYPES : array( $args['post_type'] );
	$statuses = neotiq_geo_scan_statuses( $args['post_status'] );

	$where  = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
	$where .= ' AND p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';

	// Incremental: never checked, or edited since the last check.
	if ( 'full' !== $args['mode'] ) {
		$where .= ' AND ( r.post_id IS NULL OR r.checked_at IS NULL OR r.post_modified IS NULL OR r.post_modified < p.post_modified_gmt )';
	}

	return array( $where, array_merge( array_values( $types ), array_values( $statuses ) ) );
}

/**
 * How many posts the scan still has to walk through.
 *
 * @param array $args Sanitised tool settings.
 *
 * @return int
 */
function neotiq_geo_scan_total( array $args ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_scan_where( $args );

	$sql = 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p
		LEFT JOIN `' . neotiq_geo_table() . '` r ON r.post_id = p.ID
		WHERE ' . $where;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
}

/**
 * The next slice of post IDs to check.
 *
 * Keyset pagination on the post ID: an OFFSET would skip posts in incremental
 * mode, where the remaining set shrinks as rows are written.
 *
 * @param array $args     Sanitised tool settings.
 * @param int   $after_id Last post ID already handled.
 * @param int   $limit    Batch size.
 *
 * @return int[]
 */
function neotiq_geo_scan_batch( array $args, $after_id, $limit ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_scan_where( $args );

	$sql = 'SELECT p.ID FROM ' . $wpdb->posts . ' p
		LEFT JOIN `' . neotiq_geo_table() . '` r ON r.post_id = p.ID
		WHERE ' . $where . ' AND p.ID > %d
		ORDER BY p.ID ASC
		LIMIT %d';

	$values[] = (int) $after_id;
	$values[] = (int) $limit;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) );
}

/**
 * Stored rows matching a filter.
 *
 * @param array $args status, post_type, page, per_page.
 *
 * @return array [ rows, total ]
 */
function neotiq_geo_fetch_results( array $args ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_results_where( $args );

	$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
	$offset   = max( 0, ( max( 1, (int) $args['page'] ) - 1 ) * $per_page );

	$table = neotiq_geo_table();

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $values ) );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM `{$table}` WHERE {$where} ORDER BY post_id ASC LIMIT %d OFFSET %d",
			array_merge( $values, array( $per_page, $offset ) )
		),
		ARRAY_A
	);
	// phpcs:enable

	return array( array_map( 'neotiq_geo_row_from_storage', (array) $rows ), $total );
}

/**
 * Every post ID matching a filter, for "apply to all of them".
 *
 * @param array $args status, post_type.
 *
 * @return int[]
 */
function neotiq_geo_fetch_result_ids( array $args ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_results_where( $args );
	$table                  = neotiq_geo_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$table}` WHERE {$where} ORDER BY post_id ASC", $values ) );

	return array_map( 'intval', (array) $ids );
}

/**
 * SQL fragment filtering the stored results.
 *
 * @param array $args status, post_type.
 *
 * @return array [ where sql, bound values ]
 */
function neotiq_geo_results_where( array $args ) {
	$where  = '1 = %d';
	$values = array( 1 );

	if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
		$where   .= ' AND status = %s';
		$values[] = $args['status'];
	}

	if ( ! empty( $args['post_type'] ) && 'all' !== $args['post_type'] ) {
		$where   .= ' AND post_type = %s';
		$values[] = $args['post_type'];
	}

	return array( $where, $values );
}

/**
 * Row counts per status, and the date of the most recent check.
 *
 * @param string $post_type Post type filter, or 'all'.
 *
 * @return array counts, total, last_run.
 */
function neotiq_geo_summary( $post_type = 'all' ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_results_where( array( 'post_type' => $post_type ) );
	$table                  = neotiq_geo_table();

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM `{$table}` WHERE {$where} GROUP BY status", $values ),
		ARRAY_A
	);

	$last = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(checked_at) FROM `{$table}` WHERE {$where}", $values ) );
	// phpcs:enable

	$counts = array();
	$total  = 0;

	foreach ( (array) $rows as $row ) {
		$counts[ $row['status'] ] = (int) $row['total'];
		$total                   += (int) $row['total'];
	}

	return array(
		'counts'  => $counts,
		'total'   => $total,
		'lastRun' => $last ? get_date_from_gmt( $last, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
	);
}

/**
 * Empty the results table.
 *
 * @return int Rows removed.
 */
function neotiq_geo_clear_results() {
	global $wpdb;

	$table = neotiq_geo_table();

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$removed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	$wpdb->query( "TRUNCATE TABLE `{$table}`" );
	// phpcs:enable

	return $removed;
}

/**
 * Turn a stored row back into the shape the renderer expects.
 *
 * @param array $row Database row.
 *
 * @return array
 */
function neotiq_geo_row_from_storage( array $row ) {
	$changes = json_decode( (string) $row['changes'], true );
	$notes   = json_decode( (string) $row['notes'], true );

	return array(
		'post_id'   => (int) $row['post_id'],
		'title'     => get_the_title( (int) $row['post_id'] ),
		'post_type' => (string) $row['post_type'],
		'status'    => (string) $row['status'],
		'label'     => (string) $row['label'],
		'changes'   => is_array( $changes ) ? $changes : array(),
		'notes'     => is_array( $notes ) ? $notes : array(),
		'checked'   => $row['checked_at'] ? get_date_from_gmt( $row['checked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
	);
}
