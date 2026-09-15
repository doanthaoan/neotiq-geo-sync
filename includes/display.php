<?php
/**
 * The location line shown on listing cards.
 *
 * Published as virtual post meta, so a JetEngine Dynamic Field reads it like any
 * custom field and its own "Hide if value is empty" handles the showing and hiding —
 * no Dynamic Visibility rules, no Query Builder.
 *
 * Nothing is stored. The line is built from the terms already on the post, and
 * WP_Query primes those into the object cache for every card on the page before the
 * first one renders, so a grid of twenty costs no extra queries. Nothing to backfill
 * and nothing that can go stale when a term is renamed.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Virtual meta keys this file answers for.
 */
const NEOTIQ_GEO_META_LOCATION = '_neotiq_location';
const NEOTIQ_GEO_META_CITY     = '_neotiq_city';

/**
 * Drop the code the imported term names carry: "26000 Valence", "75 Paris".
 *
 * @param string $name Term name.
 *
 * @return string
 */
function neotiq_geo_plain_name( $name ) {
	return trim( preg_replace( '/^\d{2,5}\s+/u', '', $name ) );
}

/**
 * The parts of a post's location, already cleaned up for display.
 *
 * @param int $post_id Post ID.
 *
 * @return array{department:string,city:string,arrondissement:string}
 */
function neotiq_geo_location_parts( $post_id ) {
	$parts = array(
		'department'     => '',
		'city'           => '',
		'arrondissement' => '',
	);

	// get_the_terms(), not wp_get_object_terms(): only the former reads the object
	// term cache WP_Query filled in, which is the whole performance story here.
	$chain = get_the_terms( $post_id, 'localisation' );

	if ( ! $chain || is_wp_error( $chain ) ) {
		// Outside France the city is the only thing recorded.
		$euville = get_the_terms( $post_id, 'euville' );

		if ( $euville && ! is_wp_error( $euville ) ) {
			$parts['city'] = neotiq_geo_plain_name( reset( $euville )->name );
		}

		return $parts;
	}

	// The chain arrives in no particular order and holds the region too, so each term
	// is placed by its depth: 0 region, 1 department, 2 city, 3 arrondissement.
	foreach ( $chain as $term ) {
		switch ( count( get_ancestors( $term->term_id, 'localisation', 'taxonomy' ) ) ) {
			case 1:
				$parts['department'] = neotiq_geo_plain_name( $term->name );
				break;
			case 2:
				$parts['city'] = neotiq_geo_plain_name( $term->name );
				break;
			case 3:
				$parts['arrondissement'] = neotiq_geo_arrondissement_label( $term->name );
				break;
		}
	}

	// Everywhere but Paris, Lyon and Marseille the city lives in its own taxonomy.
	if ( '' === $parts['city'] ) {
		$ville = get_the_terms( $post_id, 'ville' );

		if ( $ville && ! is_wp_error( $ville ) ) {
			$parts['city'] = neotiq_geo_plain_name( reset( $ville )->name );
		}
	}

	return $parts;
}

/**
 * Arrondissement terms are named "1" to "20".
 *
 * Not translated: an arrondissement is a French subdivision and "8e" is its name
 * rather than a word about it, the way "Bouches-du-Rhône" is.
 *
 * @param string $number Term name.
 *
 * @return string
 */
function neotiq_geo_arrondissement_label( $number ) {
	$number = trim( $number );

	return '1' === $number ? '1er' : $number . 'e';
}

/**
 * The finished line: "Drôme, Valence", "Rhône, Lyon, 2e", "Marrakech".
 *
 * @param int $post_id Post ID.
 *
 * @return string
 */
function neotiq_geo_location_line( $post_id ) {
	$parts = neotiq_geo_location_parts( $post_id );

	// Paris is its own department, so the two would read "Paris, Paris, 8e".
	if ( $parts['department'] === $parts['city'] ) {
		$parts['department'] = '';
	}

	return implode( ', ', array_filter( $parts ) );
}

/**
 * Answer for the virtual keys, and leave every other meta read alone.
 *
 * @param mixed  $value    Short-circuit value, null until someone sets one.
 * @param int    $post_id  Post ID.
 * @param string $meta_key Requested key.
 *
 * @return mixed
 */
function neotiq_geo_virtual_meta( $value, $post_id, $meta_key ) {
	if ( NEOTIQ_GEO_META_LOCATION === $meta_key ) {
		return array( neotiq_geo_location_line( $post_id ) );
	}

	if ( NEOTIQ_GEO_META_CITY === $meta_key ) {
		$parts = neotiq_geo_location_parts( $post_id );

		return array( $parts['city'] );
	}

	return $value;
}
add_filter( 'get_post_metadata', 'neotiq_geo_virtual_meta', 10, 3 );

/**
 * The same two values for a text block or a template.
 *
 *     [neotiq_location]
 *     [neotiq_location field="city"]
 *
 * @param array $atts Shortcode attributes.
 *
 * @return string
 */
function neotiq_geo_location_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'field' => 'location',
			'id'    => get_the_ID(),
		),
		$atts,
		'neotiq_location'
	);

	$post_id = (int) $atts['id'];

	if ( ! $post_id ) {
		return '';
	}

	if ( 'location' === $atts['field'] ) {
		return esc_html( neotiq_geo_location_line( $post_id ) );
	}

	$parts = neotiq_geo_location_parts( $post_id );

	return isset( $parts[ $atts['field'] ] ) ? esc_html( $parts[ $atts['field'] ] ) : '';
}
add_shortcode( 'neotiq_location', 'neotiq_geo_location_shortcode' );
