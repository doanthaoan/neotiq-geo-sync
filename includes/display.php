<?php
/**
 * The location fields a listing card reads, and the sort keys a search query uses.
 *
 * Stored as ordinary post meta, so a JetEngine Dynamic Field reads them like any
 * custom field — its own "Hide if value is empty" does the showing and hiding, with
 * no Dynamic Visibility rule — and a Query Builder query can ORDER BY them with a
 * plain join instead of aggregating over every term on every post.
 *
 * The terms themselves are never touched. Term names keep the codes they carry
 * ("26000 Valence", "75 Paris"); the code is dropped here, on the way out.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Taxonomies a location is read from.
 */
const NEOTIQ_GEO_LOCATION_TAXONOMIES = array( 'localisation', 'ville', 'euville' );

/**
 * The meta keys written for every post.
 *
 * @return string[]
 */
function neotiq_geo_listing_keys() {
	return array( '_neotiq_location', '_neotiq_city', '_neotiq_department', '_neotiq_dept_code' );
}

/**
 * Drop the code an imported term name carries: "26000 Valence", "75 Paris".
 *
 * @param string $name Term name.
 *
 * @return string
 */
function neotiq_geo_plain_name( $name ) {
	return trim( preg_replace( '/^\d{2,5}\s+/u', '', $name ) );
}

/**
 * The parts of a post's location, cleaned up for display.
 *
 * @param int $post_id Post ID.
 *
 * @return array{department:string,department_code:string,city:string,arrondissement:string}
 */
function neotiq_geo_location_parts( $post_id ) {
	$parts = array(
		'department'      => '',
		'department_code' => '',
		'city'            => '',
		'arrondissement'  => '',
	);

	// get_the_terms(), not wp_get_object_terms(): only the former reads the object
	// term cache, which WP_Query fills for every card on the page in one go.
	$chain = get_the_terms( $post_id, 'localisation' );

	if ( $chain && ! is_wp_error( $chain ) ) {
		// The chain arrives in no particular order and carries the region too, so
		// each term is placed by its depth: 0 region, 1 department, 2 city,
		// 3 arrondissement.
		foreach ( $chain as $term ) {
			switch ( count( get_ancestors( $term->term_id, 'localisation', 'taxonomy' ) ) ) {
				case 1:
					$parts['department']      = neotiq_geo_plain_name( $term->name );
					$parts['department_code'] = (string) get_term_meta( $term->term_id, 'localisation_code', true );
					break;

				case 2:
					$parts['city'] = neotiq_geo_plain_name( $term->name );
					break;

				case 3:
					$parts['arrondissement'] = neotiq_geo_arrondissement_label( $term->name );
					break;
			}
		}
	}

	// Everywhere but Paris, Lyon and Marseille the city is a term of its own: ville
	// in France, euville abroad. Each source is tried in turn rather than chosen up
	// front, because a post can carry a stray term from the wrong country — a Spanish
	// venue left tagged with a French region still has its city in euville.
	foreach ( array( 'ville', 'euville' ) as $taxonomy ) {
		if ( '' !== $parts['city'] ) {
			break;
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$parts['city'] = neotiq_geo_plain_name( reset( $terms )->name );
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
 * The finished line: "Drôme, Valence", "Rhône, Lyon, 2e", "Paris, 13e", "Marrakech".
 *
 * @param int $post_id Post ID.
 *
 * @return string
 */
function neotiq_geo_location_line( $post_id ) {
	$parts = neotiq_geo_location_parts( $post_id );

	unset( $parts['department_code'] );

	// Paris is its own department, so the two would read "Paris, Paris, 13e".
	if ( $parts['department'] === $parts['city'] ) {
		$parts['department'] = '';
	}

	return implode( ', ', array_filter( $parts ) );
}

/**
 * Write the listing fields for one post.
 *
 * Every key is written even when the value is empty, so a rebuild pass can tell a
 * post it has already visited from one it has not, and so the pending set empties
 * instead of holding on to posts that will never have a location. An empty value is
 * what JetEngine's "Hide if value is empty" acts on.
 *
 * @param int $post_id Post ID.
 *
 * @return string written, unchanged or no_data.
 */
function neotiq_geo_store_listing_meta( $post_id ) {
	$post_id = (int) $post_id;
	$parts   = neotiq_geo_location_parts( $post_id );

	$values = array(
		'_neotiq_location'   => neotiq_geo_location_line( $post_id ),
		'_neotiq_city'       => $parts['city'],
		'_neotiq_department' => $parts['department'],
		'_neotiq_dept_code'  => $parts['department_code'],
	);

	$written = false;

	foreach ( $values as $key => $value ) {
		if ( metadata_exists( 'post', $post_id, $key ) && get_post_meta( $post_id, $key, true ) === $value ) {
			continue;
		}

		update_post_meta( $post_id, $key, $value );
		$written = true;
	}

	if ( $written ) {
		return 'written';
	}

	return '' === $values['_neotiq_location'] ? 'no_data' : 'unchanged';
}

/**
 * Queue a post for a rebuild at the end of the request, and hand back the queue.
 *
 * @param int $post_id Post to add, or 0 to read the queue.
 *
 * @return int[]
 */
function neotiq_geo_listing_queue( $post_id = 0 ) {
	static $queue = array();

	if ( $post_id ) {
		$queue[ (int) $post_id ] = true;
	}

	return array_keys( $queue );
}

/**
 * Terms changed on a post: its listing fields need rebuilding.
 *
 * Deferred to shutdown rather than done here, because a sync writes localisation,
 * ville and euville one after another — rebuilding on the first would read the other
 * two before they are written, and rebuilding on each would do the work three times.
 *
 * @param int    $object_id Post ID.
 * @param array  $terms     Terms set, unused.
 * @param array  $tt_ids    Term taxonomy IDs, unused.
 * @param string $taxonomy  Taxonomy.
 */
function neotiq_geo_terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
	if ( ! in_array( $taxonomy, NEOTIQ_GEO_LOCATION_TAXONOMIES, true ) ) {
		return;
	}

	if ( ! in_array( get_post_type( $object_id ), NEOTIQ_GEO_POST_TYPES, true ) ) {
		return;
	}

	neotiq_geo_listing_queue( $object_id );
}
add_action( 'set_object_terms', 'neotiq_geo_terms_changed', 10, 4 );

/**
 * A location term was renamed: every post carrying it still shows the old name.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID, unused.
 * @param string $taxonomy Taxonomy.
 */
function neotiq_geo_term_renamed( $term_id, $tt_id, $taxonomy ) {
	if ( ! in_array( $taxonomy, NEOTIQ_GEO_LOCATION_TAXONOMIES, true ) ) {
		return;
	}

	// ponytail: renaming a department walks every post in it, one at a time. Fine for
	// a city and for the odd correction; if bulk term renames ever become routine,
	// rebuild from the tools page instead of here.
	foreach ( (array) get_objects_in_term( $term_id, $taxonomy ) as $post_id ) {
		if ( in_array( get_post_type( $post_id ), NEOTIQ_GEO_POST_TYPES, true ) ) {
			neotiq_geo_listing_queue( (int) $post_id );
		}
	}
}
add_action( 'edited_term', 'neotiq_geo_term_renamed', 10, 3 );

/**
 * Rebuild everything queued during this request.
 */
function neotiq_geo_flush_listing_queue() {
	foreach ( neotiq_geo_listing_queue() as $post_id ) {
		neotiq_geo_store_listing_meta( $post_id );
	}
}
add_action( 'shutdown', 'neotiq_geo_flush_listing_queue' );

/**
 * The same values for a text block or a template.
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
