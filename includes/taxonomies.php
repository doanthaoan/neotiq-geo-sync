<?php
/**
 * Matching a geocoded address against the site taxonomies.
 *
 * eu           Country, localisation_code holds the ISO 3166-1 alpha-2 code.
 * localisation Region > Department > City > Arrondissement (France).
 * ville        French communes, named "49620 Mauges-sur-Loire".
 * euville      Flat list of cities outside France.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lowercase, unaccented, alphanumeric-only form of a label, for comparison.
 *
 * @param string $value Raw label.
 *
 * @return string
 */
function neotiq_geo_norm( $value ) {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $value ) ) );
}

/**
 * Term name, or #ID when the term is gone.
 *
 * @param int $term_id Term ID.
 *
 * @return string
 */
function neotiq_geo_term_name( $term_id ) {
	$term = get_term( $term_id );

	return ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $term_id;
}

/**
 * Term IDs assigned to a post in a taxonomy.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy name.
 *
 * @return int[]
 */
function neotiq_geo_term_ids( $post_id, $taxonomy ) {
	$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

	return is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
}

/* -------------------------------------------------------------------------
 * localisation taxonomy
 * ---------------------------------------------------------------------- */

/**
 * The whole localisation tree, indexed by term ID, with its two term metas.
 *
 * @return array[] name, type, code, parent.
 */
function neotiq_geo_loc_index() {
	static $index = null;

	if ( null !== $index ) {
		return $index;
	}

	$index = array();
	$terms = get_terms(
		array(
			'taxonomy'   => 'localisation',
			'hide_empty' => false,
		)
	);

	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
		$index[ (int) $term->term_id ] = array(
			'name'   => $term->name,
			'type'   => (string) get_term_meta( $term->term_id, 'localisation_type', true ),
			'code'   => (string) get_term_meta( $term->term_id, 'localisation_code', true ),
			'parent' => (int) $term->parent,
		);
	}

	return $index;
}

/**
 * First localisation term matching every given field. The `name` field is
 * compared on its normalised form, the others strictly.
 *
 * @param array $where Field => expected value.
 *
 * @return int Term ID, 0 when not found.
 */
function neotiq_geo_loc_find( array $where ) {
	foreach ( neotiq_geo_loc_index() as $term_id => $row ) {
		foreach ( $where as $field => $expected ) {
			$matches = ( 'name' === $field )
				? neotiq_geo_norm( $row['name'] ) === neotiq_geo_norm( $expected )
				: (string) $row[ $field ] === (string) $expected;

			if ( ! $matches ) {
				continue 2;
			}
		}

		return (int) $term_id;
	}

	return 0;
}

/**
 * Department by code ("2A", "75"...), falling back to its name — some terms are
 * prefixed with their code ("75 Paris", "01 Ain").
 *
 * @param string $code Department code.
 * @param string $name Department name.
 *
 * @return int Term ID, 0 when not found.
 */
function neotiq_geo_find_department( $code, $name ) {
	if ( '' !== (string) $code ) {
		$term_id = neotiq_geo_loc_find(
			array(
				'type' => 'Department',
				'code' => $code,
			)
		);

		if ( $term_id ) {
			return $term_id;
		}
	}

	if ( '' === (string) $name ) {
		return 0;
	}

	foreach ( neotiq_geo_loc_index() as $term_id => $row ) {
		if ( 'Department' !== $row['type'] ) {
			continue;
		}

		if ( neotiq_geo_norm( preg_replace( '/^\s*\d+\s+/', '', $row['name'] ) ) === neotiq_geo_norm( $name ) ) {
			return (int) $term_id;
		}
	}

	return 0;
}

/**
 * A term and its whole ancestry, outermost first.
 *
 * @param int $term_id Term ID.
 *
 * @return int[]
 */
function neotiq_geo_loc_chain( $term_id ) {
	$index = neotiq_geo_loc_index();
	$chain = array();

	while ( $term_id && isset( $index[ $term_id ] ) && ! in_array( (int) $term_id, $chain, true ) ) {
		array_unshift( $chain, (int) $term_id );
		$term_id = $index[ $term_id ]['parent'];
	}

	return $chain;
}

/* -------------------------------------------------------------------------
 * ville taxonomy (France, Paris / Marseille / Lyon excluded)
 * ---------------------------------------------------------------------- */

/**
 * A ville term slug reduced to the commune itself: the SEO prefix and the
 * postcode are stripped ("organisation-seminaire-55000-gery" becomes "gery").
 *
 * The slug is used rather than the name because some imported names are
 * truncated ("Gér" for "Géry") while the slug is always intact.
 *
 * @param WP_Term $term     City term.
 * @param string  $postcode Five digit postcode.
 *
 * @return string
 */
function neotiq_geo_ville_slug( WP_Term $term, $postcode ) {
	foreach ( array( $postcode, ltrim( $postcode, '0' ) ) as $code ) {
		$position = strpos( $term->slug, '-' . $code . '-' );

		if ( false !== $position ) {
			return substr( $term->slug, $position + strlen( $code ) + 2 );
		}
	}

	return $term->slug;
}

/**
 * Find the ville term for a postcode and commune.
 *
 * Terms carry the postcode in their localisation_code meta, sometimes without
 * the leading zero ("7530" for 07530), so both spellings are queried.
 *
 * @param string       $postcode Postcode.
 * @param string|array $cities   Commune, then former communes (mergers).
 *
 * @return array [ term_id, candidate terms ].
 */
function neotiq_geo_find_ville( $postcode, $cities ) {
	$postcode = str_pad( preg_replace( '/\D/', '', $postcode ), 5, '0', STR_PAD_LEFT );
	$cities   = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $cities ) ) ) );

	$terms = get_terms(
		array(
			'taxonomy'   => 'ville',
			'hide_empty' => false,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'localisation_code',
					'value'   => array( $postcode, ltrim( $postcode, '0' ) ),
					'compare' => 'IN',
				),
			),
		)
	);

	if ( is_wp_error( $terms ) || ! $terms ) {
		return array( 0, array() );
	}

	foreach ( $cities as $city ) {
		$city_slug = sanitize_title( $city );

		// Anchored on the commune alone: "luzarches" must not catch
		// "le-plessis-luzarches", which shares the same postcode.
		foreach ( $terms as $term ) {
			if ( neotiq_geo_ville_slug( $term, $postcode ) === $city_slug ) {
				return array( (int) $term->term_id, $terms );
			}
		}

		// Fall back to the name, stripped of its leading postcode.
		foreach ( $terms as $term ) {
			if ( neotiq_geo_norm( preg_replace( '/^\s*\d+\s*/', '', $term->name ) ) === neotiq_geo_norm( $city ) ) {
				return array( (int) $term->term_id, $terms );
			}
		}
	}

	// A single term on this postcode leaves no room for ambiguity.
	if ( 1 === count( $terms ) ) {
		return array( (int) $terms[0]->term_id, $terms );
	}

	return array( 0, $terms );
}

/* -------------------------------------------------------------------------
 * euville taxonomy (outside France)
 * ---------------------------------------------------------------------- */

/**
 * Find, or optionally create, the euville term for a city.
 *
 * @param array $candidates City names, most trusted first.
 * @param bool  $create     Create the term when nothing matches.
 *
 * @return array [ term_id, created ].
 */
function neotiq_geo_find_euville( array $candidates, $create ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'euville',
			'hide_empty' => false,
		)
	);
	$terms = is_wp_error( $terms ) ? array() : $terms;

	$candidates = array_values( array_filter( array_map( 'trim', array_map( 'strval', $candidates ) ) ) );

	foreach ( $candidates as $candidate ) {
		foreach ( $terms as $term ) {
			if ( neotiq_geo_norm( $term->name ) === neotiq_geo_norm( $candidate ) ) {
				return array( (int) $term->term_id, false );
			}
		}
	}

	// Spelling variants from one language to the next: "Castelló de la Plana"
	// against "Castellón de la plana". Two characters apart at most, and only
	// on names long enough for that to mean something.
	foreach ( $candidates as $candidate ) {
		$needle = neotiq_geo_norm( $candidate );

		if ( strlen( $needle ) < 6 ) {
			continue;
		}

		foreach ( $terms as $term ) {
			if ( levenshtein( $needle, neotiq_geo_norm( $term->name ) ) <= 2 ) {
				return array( (int) $term->term_id, false );
			}
		}
	}

	if ( ! $create || ! $candidates ) {
		return array( 0, false );
	}

	// Prefer a Latin script name: the taxonomy stores "Spata", not "Σπάτα".
	$latin = array_values(
		array_filter(
			$candidates,
			static function ( $candidate ) {
				return ! preg_match( '/[^\p{Latin}\p{Common}]/u', $candidate );
			}
		)
	);

	$created = wp_insert_term( $latin ? $latin[0] : $candidates[0], 'euville' );

	return is_wp_error( $created ) ? array( 0, false ) : array( (int) $created['term_id'], true );
}

/* -------------------------------------------------------------------------
 * eu taxonomy (country)
 * ---------------------------------------------------------------------- */

/**
 * Country term whose localisation_code meta matches an ISO 3166-1 alpha-2 code.
 *
 * @param string $country_code Two letter country code.
 *
 * @return int Term ID, 0 when not found.
 */
function neotiq_geo_eu_term_by_code( $country_code ) {
	static $map = null;

	if ( null === $map ) {
		$map   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'eu',
				'hide_empty' => false,
			)
		);

		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$code = strtoupper( (string) get_term_meta( $term->term_id, 'localisation_code', true ) );

			if ( '' !== $code ) {
				$map[ $code ] = (int) $term->term_id;
			}
		}
	}

	$country_code = strtoupper( (string) $country_code );

	return isset( $map[ $country_code ] ) ? $map[ $country_code ] : 0;
}
