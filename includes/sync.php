<?php
/**
 * Turning a geocoded address into taxonomy assignments.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronise one post's location taxonomies with its OpenStreetMap address.
 *
 * @param int   $post_id Post ID.
 * @param array $opts    apply, force, create_euville.
 *
 * @return array {
 *     @type int    $post_id Post ID.
 *     @type string $title   Post title.
 *     @type string $status  no_data, error, conflict, ok, pending or updated.
 *     @type array  $notes   Human readable, already translated.
 *     @type array  $changes Taxonomy => [ before term IDs, after term IDs ].
 * }
 */
function neotiq_geo_sync_post( $post_id, array $opts = array() ) {
	$opts = wp_parse_args(
		$opts,
		array(
			'apply'          => false,
			'force'          => false,
			'create_euville' => false,
		)
	);

	$result = array(
		'post_id' => (int) $post_id,
		'title'   => get_the_title( $post_id ),
		'status'  => 'no_data',
		'label'   => '',
		'notes'   => array(),
		'changes' => array(),
	);

	$osm = neotiq_geo_read_osm( $post_id );

	if ( ! $osm ) {
		$result['notes'][] = __( 'No coordinates in the OpenStreetMap field.', 'neotiq-geo-sync' );

		return $result;
	}

	$geo = neotiq_geo_resolve( $post_id, $osm, $opts['force'] );

	if ( ! $geo ) {
		$result['status'] = 'error';
		$result['notes'][] = __( 'Reverse geocoding unavailable (network error or no result).', 'neotiq-geo-sync' );

		return $result;
	}

	$result['label']   = $geo['label'];
	$result['notes'][] = $osm['lat'] . ',' . $osm['lng'] . ' → ' . $geo['label'];

	// The country on the post is authoritative; it is only derived when absent,
	// and any disagreement with the address is reported.
	$current_eu = neotiq_geo_term_ids( $post_id, 'eu' );
	$geo_eu     = neotiq_geo_eu_term_by_code( $geo['country_code'] );

	if ( ! $current_eu && $geo_eu ) {
		$result['changes']['eu'] = array( array(), array( $geo_eu ) );
		$current_eu              = array( $geo_eu );
	} elseif ( ! $geo_eu ) {
		$result['notes'][] = sprintf(
			/* translators: %s: ISO country code returned by the geocoder. */
			__( 'Country “%s” is missing from the eu taxonomy.', 'neotiq-geo-sync' ),
			$geo['country_code']
		);
	} elseif ( ! in_array( $geo_eu, $current_eu, true ) ) {
		// The post's country and the address contradict each other: one of them
		// is wrong and nothing says which. Report it and write nothing, rather
		// than propagate the error to the other taxonomies.
		$result['notes'][] = sprintf(
			/* translators: 1: country term(s) on the post, 2: ISO country code from the address. */
			__( 'Country mismatch: post says “%1$s”, address says “%2$s”. Nothing was changed, this needs a manual decision.', 'neotiq-geo-sync' ),
			implode( ', ', array_map( 'neotiq_geo_term_name', $current_eu ) ),
			$geo['country_code']
		);
		$result['status'] = 'conflict';

		return $result;
	}

	$france_id = neotiq_geo_eu_term_by_code( 'FR' );
	$is_france = $current_eu ? in_array( $france_id, $current_eu, true ) : ( 'FR' === $geo['country_code'] );

	if ( $is_france ) {
		neotiq_geo_build_france( $result, $geo );
	} else {
		neotiq_geo_build_europe( $result, $geo, $opts['create_euville'] );
	}

	$result['changes'] = array_filter(
		$result['changes'],
		static function ( $pair ) {
			sort( $pair[0] );
			sort( $pair[1] );

			return $pair[0] !== $pair[1];
		}
	);

	if ( $result['changes'] ) {
		$result['status'] = $opts['apply'] ? 'updated' : 'pending';
	} else {
		$result['status'] = 'ok';
	}

	if ( $opts['apply'] ) {
		foreach ( $result['changes'] as $taxonomy => $pair ) {
			wp_set_object_terms( $post_id, $pair[1], $taxonomy, false );
		}
	}

	return $result;
}

/**
 * France: localisation (Region > Department > City > Arrondissement) and ville.
 *
 * @param array $result Result being built, by reference.
 * @param array $geo    Normalised geocoding result.
 */
function neotiq_geo_build_france( array &$result, array $geo ) {
	$dept_id = neotiq_geo_find_department( $geo['dept_code'], $geo['dept_name'] );

	if ( ! $dept_id ) {
		$result['notes'][] = sprintf(
			/* translators: 1: department code, 2: department name. */
			__( 'Department not found in the localisation taxonomy (code “%1$s”, name “%2$s”).', 'neotiq-geo-sync' ),
			$geo['dept_code'],
			$geo['dept_name']
		);

		return;
	}

	if ( 'ban' !== $geo['source'] ) {
		// Photon is enough for the department and the commune, never for an
		// arrondissement.
		$result['notes'][] = __( 'Address outside the Base Adresse Nationale, fell back to Photon (no arrondissement detection).', 'neotiq-geo-sync' );
	}

	$target = $dept_id;

	// Paris / Marseille / Lyon: "Paris 8e Arrondissement" arrives either in
	// district or directly in city, depending on the point being queried.
	$arrondissement_source = '' !== $geo['district'] ? $geo['district'] : $geo['city'];

	if ( preg_match( '/^(.+?)\s+(\d+)\s*(?:er|ère|eme|ème|e)\s+Arrondissement/iu', $arrondissement_source, $matches ) ) {
		$city_id = neotiq_geo_loc_find(
			array(
				'type'   => 'City',
				'parent' => $dept_id,
				'name'   => $matches[1],
			)
		);

		if ( $city_id ) {
			// Arrondissements are named "1" to "20" and coded on the postcode
			// (75008, 13003, 69002). The number is safer: the 16th arrondissement
			// of Paris is also written 75116.
			$arrondissement_id = neotiq_geo_loc_find(
				array(
					'parent' => $city_id,
					'name'   => $matches[2],
				)
			);

			if ( ! $arrondissement_id ) {
				$arrondissement_id = neotiq_geo_loc_find(
					array(
						'parent' => $city_id,
						'code'   => $geo['postcode'],
					)
				);
			}

			if ( ! $arrondissement_id ) {
				$result['notes'][] = sprintf(
					/* translators: 1: arrondissement number, 2: city name. */
					__( 'Arrondissement %1$s not found under “%2$s”.', 'neotiq-geo-sync' ),
					$matches[2],
					$matches[1]
				);
			}

			$target = $arrondissement_id ? $arrondissement_id : $city_id;
		} else {
			$result['notes'][] = sprintf(
				/* translators: %s: city name. */
				__( 'City “%s” not found under the department.', 'neotiq-geo-sync' ),
				$matches[1]
			);
		}
	}

	$result['changes']['localisation'] = array(
		neotiq_geo_term_ids( $result['post_id'], 'localisation' ),
		neotiq_geo_loc_chain( $target ),
	);

	// Paris / Marseille / Lyon have no ville term on purpose: the arrondissement
	// in the localisation taxonomy plays that role.
	$current_ville = neotiq_geo_term_ids( $result['post_id'], 'ville' );

	if ( $target === $dept_id ) {
		list( $ville_id, $candidates ) = neotiq_geo_find_ville( $geo['postcode'], array( $geo['city'], $geo['oldcity'] ) );

		if ( $ville_id ) {
			$result['changes']['ville'] = array( $current_ville, array( $ville_id ) );
		} else {
			$result['notes'][] = sprintf(
				/* translators: 1: postcode, 2: city name, 3: number of terms sharing that postcode. */
				_n(
					'City “%1$s %2$s” could not be resolved (%3$d term on this postcode), city left unchanged.',
					'City “%1$s %2$s” could not be resolved (%3$d terms on this postcode), city left unchanged.',
					count( $candidates ),
					'neotiq-geo-sync'
				),
				$geo['postcode'],
				$geo['city'],
				count( $candidates )
			);
		}
	} elseif ( $current_ville ) {
		$result['changes']['ville'] = array( $current_ville, array() );
	}

	$current_euville = neotiq_geo_term_ids( $result['post_id'], 'euville' );

	if ( $current_euville ) {
		$result['changes']['euville'] = array( $current_euville, array() );
	}
}

/**
 * Outside France: euville.
 *
 * @param array $result Result being built, by reference.
 * @param array $geo    Normalised geocoding result.
 * @param bool  $create Create the euville term when nothing matches.
 */
function neotiq_geo_build_europe( array &$result, array $geo, $create ) {
	$candidates = array( $geo['city'] );

	// Photon returns the local name when no French translation exists ("Σπάτα")
	// while the taxonomy stores the Latin transliteration ("Spata"), so the
	// English rendering is tried second.
	if ( preg_match( '/[^\p{Latin}\p{Common}]/u', $geo['city'] ) ) {
		$coordinates = explode( ',', isset( $geo['key'] ) ? $geo['key'] : '' );

		if ( 2 === count( $coordinates ) ) {
			$english = neotiq_geo_reverse_photon( $coordinates[0], $coordinates[1], 'en' );

			if ( $english ) {
				$candidates[] = isset( $english['city'] ) ? $english['city'] : '';
				$candidates[] = isset( $english['name'] ) ? $english['name'] : '';
			}
		}
	}

	list( $euville_id, $created ) = neotiq_geo_find_euville( $candidates, $create );
	$current_euville              = neotiq_geo_term_ids( $result['post_id'], 'euville' );

	if ( $euville_id ) {
		$result['changes']['euville'] = array( $current_euville, array( $euville_id ) );

		if ( $created ) {
			$result['notes'][] = sprintf(
				/* translators: %s: newly created term name. */
				__( 'euville term “%s” was created.', 'neotiq-geo-sync' ),
				neotiq_geo_term_name( $euville_id )
			);
		}
	} else {
		$result['notes'][] = sprintf(
			/* translators: %s: city name returned by the geocoder. */
			__( 'City “%s” is missing from the euville taxonomy, left unchanged.', 'neotiq-geo-sync' ),
			$geo['city']
		);
	}

	foreach ( array( 'ville', 'localisation' ) as $taxonomy ) {
		$current = neotiq_geo_term_ids( $result['post_id'], $taxonomy );

		if ( $current ) {
			$result['changes'][ $taxonomy ] = array( $current, array() );
		}
	}
}

/**
 * Flatten a sync result into the shape the results table stores and renders:
 * term IDs resolved to names, so a stored row survives a term being renamed or
 * deleted and needs no further lookups to display.
 *
 * @param array $result Output of neotiq_geo_sync_post().
 *
 * @return array
 */
function neotiq_geo_display_row( array $result ) {
	$changes = array();

	foreach ( $result['changes'] as $taxonomy => $pair ) {
		$changes[ $taxonomy ] = array(
			'before' => implode( ', ', array_map( 'neotiq_geo_term_name', $pair[0] ) ),
			'after'  => implode( ', ', array_map( 'neotiq_geo_term_name', $pair[1] ) ),
		);
	}

	return array(
		'post_id'   => (int) $result['post_id'],
		'title'     => $result['title'],
		'post_type' => get_post_type( $result['post_id'] ),
		'status'    => $result['status'],
		'label'     => $result['label'],
		'changes'   => $changes,
		'notes'     => $result['notes'],
		'checked'   => '',
	);
}

/**
 * Translated label for a sync status.
 *
 * @param string $status Status key returned by neotiq_geo_sync_post().
 *
 * @return string
 */
function neotiq_geo_status_label( $status ) {
	$labels = array(
		'no_data'  => __( 'No address', 'neotiq-geo-sync' ),
		'error'    => __( 'Error', 'neotiq-geo-sync' ),
		'conflict' => __( 'Conflict', 'neotiq-geo-sync' ),
		'ok'       => __( 'Already correct', 'neotiq-geo-sync' ),
		'pending'  => __( 'To correct', 'neotiq-geo-sync' ),
		'updated'  => __( 'Corrected', 'neotiq-geo-sync' ),
	);

	return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
}
