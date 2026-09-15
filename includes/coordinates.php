<?php
/**
 * Map coordinates extracted from the OpenStreetMap field.
 *
 * JetEngine's map listings and its geo-distance search read plain lat/lng meta,
 * not the serialised OSM field, so the coordinates are mirrored into map_lat,
 * map_lng and map_coordinate.
 *
 * This needs no geocoding: it only reads meta the post already carries, so it
 * runs at full speed and is kept separate from the address check.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta keys the coordinates are mirrored into.
 *
 * @return string[]
 */
function neotiq_geo_coordinate_keys() {
	return array( 'map_lat', 'map_lng', 'map_coordinate' );
}

/**
 * The ACF key of a field, resolved against the field groups that apply to this
 * post.
 *
 * update_field() resolves a bare name non-strictly: with the same name defined
 * in both the Etablissement and the Prestataire group, it would attach the
 * first key it finds and cross-link a prestataire to the etablissement field.
 * Looking the key up per post type avoids that.
 *
 * @param string $name    Field name.
 * @param int    $post_id Post ID.
 *
 * @return string Field key, empty when ACF does not know the field here.
 */
function neotiq_geo_acf_field_key( $name, $post_id ) {
	static $cache = array();

	// Location rules for these fields are post-type based, so one lookup per
	// post type is enough.
	$post_type = (string) get_post_type( $post_id );

	if ( isset( $cache[ $post_type ][ $name ] ) ) {
		return $cache[ $post_type ][ $name ];
	}

	$key = '';

	if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
		foreach ( (array) acf_get_field_groups( array( 'post_id' => $post_id ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( isset( $field['name'], $field['key'] ) && $field['name'] === $name ) {
					$key = $field['key'];
					break 2;
				}
			}
		}
	}

	$cache[ $post_type ][ $name ] = $key;

	return $key;
}

/**
 * Write one value, through ACF when it owns the field so the reference meta
 * stays right, otherwise as plain post meta.
 *
 * @param int    $post_id Post ID.
 * @param string $name    Meta key.
 * @param string $value   Value.
 */
function neotiq_geo_write_value( $post_id, $name, $value ) {
	$key = neotiq_geo_acf_field_key( $name, $post_id );

	if ( $key && function_exists( 'update_field' ) ) {
		update_field( $key, $value, $post_id );

		return;
	}

	update_post_meta( $post_id, $name, $value );
}

/**
 * Mirror one post's OpenStreetMap coordinates into the map_* meta.
 *
 * @param int $post_id Post ID.
 *
 * @return string written, unchanged or no_data.
 */
function neotiq_geo_extract_coordinates( $post_id ) {
	$osm = neotiq_geo_read_osm( $post_id );

	if ( ! $osm ) {
		return 'no_data';
	}

	$values = array(
		'map_lat'        => (string) $osm['lat'],
		'map_lng'        => (string) $osm['lng'],
		'map_coordinate' => $osm['lat'] . ',' . $osm['lng'],
	);

	$written = false;

	foreach ( $values as $name => $value ) {
		if ( (string) get_post_meta( $post_id, $name, true ) === $value ) {
			continue;
		}

		neotiq_geo_write_value( $post_id, $name, $value );
		$written = true;
	}

	return $written ? 'written' : 'unchanged';
}

/**
 * Everything derived from one post: its map coordinates and its listing fields.
 *
 * Both read data the post already carries, so neither touches a geocoder and neither
 * waits on the one-call-per-second throttle.
 *
 * @param int $post_id Post ID.
 *
 * @return string written, unchanged or no_data.
 */
function neotiq_geo_refresh_post( $post_id ) {
	$coordinates = neotiq_geo_extract_coordinates( $post_id );
	$listing     = neotiq_geo_store_listing_meta( $post_id );

	if ( 'written' === $coordinates || 'written' === $listing ) {
		return 'written';
	}

	return 'no_data' === $coordinates && 'no_data' === $listing ? 'no_data' : 'unchanged';
}

/**
 * SQL fragment selecting posts a rebuild pass should visit.
 *
 * @param array $args post_type, post_status, mode.
 *
 * @return array [ where sql, bound values ]
 */
function neotiq_geo_coordinates_where( array $args ) {
	global $wpdb;

	$types    = 'all' === $args['post_type'] ? NEOTIQ_GEO_POST_TYPES : array( $args['post_type'] );
	$statuses = neotiq_geo_scan_statuses( $args['post_status'] );
	$osm_keys = NEOTIQ_GEO_OSM_KEYS;

	$where  = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
	$where .= ' AND p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';

	$values = array_merge( array_values( $types ), array_values( $statuses ) );

	if ( 'full' === $args['mode'] ) {
		return array( $where, $values );
	}

	// Still to do: either the listing fields have never been built, or the post has an
	// address but no coordinates. A post with no address leaves the set as soon as its
	// listing fields are written, so the pending count always reaches zero instead of
	// holding on to posts that can never gain coordinates.
	$where   .= ' AND ( NOT EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' listing
		WHERE listing.post_id = p.ID AND listing.meta_key = %s )';
	$values[] = '_neotiq_location';

	$where .= ' OR ( EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' osm
		WHERE osm.post_id = p.ID
		  AND osm.meta_key IN (' . implode( ',', array_fill( 0, count( $osm_keys ), '%s' ) ) . ')
		  AND osm.meta_value <> \'\' )';
	$values = array_merge( $values, array_values( $osm_keys ) );

	$where   .= ' AND NOT EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' lat
		WHERE lat.post_id = p.ID AND lat.meta_key = %s AND lat.meta_value <> \'\' ) ) )';
	$values[] = 'map_lat';

	return array( $where, $values );
}

/**
 * How many posts an extraction pass has to visit.
 *
 * @param array $args post_type, post_status, mode.
 *
 * @return int
 */
function neotiq_geo_coordinates_total( array $args ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_coordinates_where( $args );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . $where, $values ) );
}

/**
 * The next slice of posts to extract.
 *
 * @param array $args     post_type, post_status, mode.
 * @param int   $after_id Last post ID already handled.
 * @param int   $limit    Batch size.
 *
 * @return int[]
 */
function neotiq_geo_coordinates_batch( array $args, $after_id, $limit ) {
	global $wpdb;

	list( $where, $values ) = neotiq_geo_coordinates_where( $args );

	$values[] = (int) $after_id;
	$values[] = (int) $limit;

	$sql = 'SELECT p.ID FROM ' . $wpdb->posts . ' p WHERE ' . $where . ' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $values ) ) );
}

/**
 * Coverage: how many posts have an address, and how many carry coordinates.
 *
 * @param string $post_type Post type filter, or 'all'.
 *
 * @return array withAddress, withCoordinates, missing.
 */
function neotiq_geo_coordinates_summary( $post_type = 'all' ) {
	$base = array(
		'post_type'   => $post_type,
		'post_status' => 'any',
	);

	global $wpdb;

	$total   = neotiq_geo_coordinates_total( array_merge( $base, array( 'mode' => 'full' ) ) );
	$missing = neotiq_geo_coordinates_total( array_merge( $base, array( 'mode' => 'incremental' ) ) );

	$types    = 'all' === $post_type ? NEOTIQ_GEO_POST_TYPES : array( $post_type );
	$osm_keys = NEOTIQ_GEO_OSM_KEYS;

	$counted = function ( $sql, $values ) use ( $wpdb, $types ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE p.post_type IN ('
				. implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND ' . $sql,
				array_merge( array_values( $types ), $values )
			)
		);
	};

	$with_address = $counted(
		'EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' osm WHERE osm.post_id = p.ID
			AND osm.meta_key IN (' . implode( ',', array_fill( 0, count( $osm_keys ), '%s' ) ) . ')
			AND osm.meta_value <> \'\' )',
		array_values( $osm_keys )
	);

	$with_coordinates = $counted(
		'EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' lat
			WHERE lat.post_id = p.ID AND lat.meta_key = %s AND lat.meta_value <> \'\' )',
		array( 'map_lat' )
	);

	$with_location = $counted(
		'EXISTS ( SELECT 1 FROM ' . $wpdb->postmeta . ' listing
			WHERE listing.post_id = p.ID AND listing.meta_key = %s AND listing.meta_value <> \'\' )',
		array( '_neotiq_location' )
	);

	return array(
		'total'           => $total,
		'withAddress'     => $with_address,
		'withCoordinates' => $with_coordinates,
		'withLocation'    => $with_location,
		'missing'         => $missing,
	);
}
