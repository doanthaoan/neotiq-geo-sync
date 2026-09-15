<?php
/**
 * Reading the OpenStreetMap field and reverse geocoding its coordinates.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates and address stored in the ACF OpenStreetMap field.
 *
 * @param int $post_id Post ID.
 *
 * @return array|null lat, lng, address.
 */
function neotiq_geo_read_osm( $post_id ) {
	foreach ( NEOTIQ_GEO_OSM_KEYS as $key ) {
		$raw = maybe_unserialize( get_post_meta( $post_id, $key, true ) );

		if ( ! is_array( $raw ) ) {
			continue;
		}

		// A hand-placed marker is more precise than the centre of the map.
		$point = $raw;
		if ( ! empty( $raw['markers'] ) && is_array( $raw['markers'] ) ) {
			$first = reset( $raw['markers'] );
			if ( isset( $first['lat'], $first['lng'] ) ) {
				$point = $first;
			}
		}

		if ( ! isset( $point['lat'], $point['lng'] ) || ! is_numeric( $point['lat'] ) || ! is_numeric( $point['lng'] ) ) {
			continue;
		}

		return array(
			'lat'     => (float) $point['lat'],
			'lng'     => (float) $point['lng'],
			'address' => isset( $raw['address'] ) ? (string) $raw['address'] : '',
		);
	}

	return null;
}

/**
 * Space requests one second apart per host.
 *
 * The French BAN advertises `x-ratelimit-limit-second: 1` and Photon asks for
 * reasonable use. Without this, a bulk run silently loses one post in three.
 *
 * @param string $host Host name.
 */
function neotiq_geo_throttle( $host ) {
	static $last = array();

	$wait = isset( $last[ $host ] ) ? 1.05 - ( microtime( true ) - $last[ $host ] ) : 0;

	if ( $wait > 0 ) {
		usleep( (int) ( $wait * 1000000 ) );
	}

	$last[ $host ] = microtime( true );
}

/**
 * GET a JSON endpoint.
 *
 * @param string $url Full URL.
 *
 * @return array|null Decoded payload.
 */
function neotiq_geo_http_json( $url ) {
	neotiq_geo_throttle( wp_parse_url( $url, PHP_URL_HOST ) );

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => sprintf( 'neotiq-geo-sync/%s (+%s)', NEOTIQ_GEO_VERSION, home_url() ),
			'headers'    => array( 'Accept' => 'application/json' ),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	return is_array( $data ) ? $data : null;
}

/**
 * Base Adresse Nationale: department code (2A/2B included), postcode, commune
 * and arrondissement, with no quota. Only answers for France.
 *
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 *
 * @return array|null Feature properties.
 */
function neotiq_geo_reverse_ban( $lat, $lng ) {
	$data = neotiq_geo_http_json(
		add_query_arg(
			array(
				'lat' => $lat,
				'lon' => $lng,
			),
			'https://api-adresse.data.gouv.fr/reverse/'
		)
	);

	return isset( $data['features'][0]['properties'] ) ? $data['features'][0]['properties'] : null;
}

/**
 * Photon, already used by the search form city autocomplete, for the rest of
 * Europe. `lang=fr` returns "Rome", "Athènes"... which is how the euville
 * taxonomy spells them.
 *
 * @param float  $lat  Latitude.
 * @param float  $lng  Longitude.
 * @param string $lang Result language.
 *
 * @return array|null Feature properties.
 */
function neotiq_geo_reverse_photon( $lat, $lng, $lang = 'fr' ) {
	$data = neotiq_geo_http_json(
		add_query_arg(
			array(
				'lat'  => $lat,
				'lon'  => $lng,
				'lang' => $lang,
			),
			'https://photon.komoot.io/reverse'
		)
	);

	return isset( $data['features'][0]['properties'] ) ? $data['features'][0]['properties'] : null;
}

/**
 * Reverse geocode a post's coordinates, through the cache.
 *
 * The BAN is queried first (arrondissement precision, no quota); Photon takes
 * over outside France.
 *
 * @param int   $post_id Post ID.
 * @param array $osm     Output of neotiq_geo_read_osm().
 * @param bool  $force   Ignore the cached result.
 *
 * @return array|null Normalised geocoding result.
 */
function neotiq_geo_resolve( $post_id, array $osm, $force ) {
	$key    = round( $osm['lat'], 6 ) . ',' . round( $osm['lng'], 6 );
	$cached = get_post_meta( $post_id, NEOTIQ_GEO_CACHE_META, true );

	if ( ! $force && is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $key ) {
		// Defaults: a cache written by an earlier version may lack a key.
		return array_merge( neotiq_geo_empty_result(), $cached );
	}

	$ban = neotiq_geo_reverse_ban( $osm['lat'], $osm['lng'] );
	$geo = ( $ban && ! empty( $ban['context'] ) ) ? neotiq_geo_from_ban( $ban ) : null;

	if ( ! $geo ) {
		$photon = neotiq_geo_reverse_photon( $osm['lat'], $osm['lng'] );
		$geo    = $photon ? neotiq_geo_from_photon( $photon, $osm ) : null;
	}

	if ( ! $geo ) {
		return null;
	}

	$geo['key'] = $key;
	update_post_meta( $post_id, NEOTIQ_GEO_CACHE_META, $geo );

	return $geo;
}

/**
 * Shape of a geocoding result, with every field empty.
 *
 * @return array
 */
function neotiq_geo_empty_result() {
	return array(
		'source'       => '',
		'country_code' => '',
		'dept_code'    => '',
		'dept_name'    => '',
		'postcode'     => '',
		'city'         => '',
		'oldcity'      => '',
		'district'     => '',
		'label'        => '',
	);
}

/**
 * Normalise a Base Adresse Nationale feature.
 *
 * @param array $props Feature properties.
 *
 * @return array
 */
function neotiq_geo_from_ban( array $props ) {
	// context reads "13, Bouches-du-Rhône, Provence-Alpes-Côte d'Azur".
	$context = array_map( 'trim', explode( ',', (string) $props['context'] ) );

	return array_merge(
		neotiq_geo_empty_result(),
		array(
			'source'       => 'ban',
			'country_code' => 'FR',
			'dept_code'    => $context[0],
			'dept_name'    => isset( $context[1] ) ? $context[1] : '',
			'postcode'     => isset( $props['postcode'] ) ? (string) $props['postcode'] : '',
			'city'         => isset( $props['city'] ) ? (string) $props['city'] : '',
			// Delegated commune from before a merger: the city taxonomy predates
			// the "communes nouvelles", so it often holds the older name.
			'oldcity'      => isset( $props['oldcity'] ) ? (string) $props['oldcity'] : '',
			'district'     => isset( $props['district'] ) ? (string) $props['district'] : '',
			'label'        => isset( $props['label'] ) ? (string) $props['label'] : '',
		)
	);
}

/**
 * Normalise a Photon feature.
 *
 * @param array $props Feature properties.
 * @param array $osm   Output of neotiq_geo_read_osm(), used as a label fallback.
 *
 * @return array
 */
function neotiq_geo_from_photon( array $props, array $osm ) {
	$city = '';

	foreach ( array( 'city', 'district', 'county', 'name' ) as $field ) {
		if ( ! empty( $props[ $field ] ) ) {
			$city = (string) $props[ $field ];
			break;
		}
	}

	$label    = trim( $city . ' ' . ( isset( $props['country'] ) ? $props['country'] : '' ) );
	$postcode = isset( $props['postcode'] ) ? (string) $props['postcode'] : '';
	$country  = isset( $props['countrycode'] ) ? strtoupper( (string) $props['countrycode'] ) : '';

	return array_merge(
		neotiq_geo_empty_result(),
		array(
			'source'       => 'photon',
			'country_code' => $country,
			// Country-gated: the Spanish postcode 26009 (Logroño) would
			// otherwise read as the French department 26 (Drôme).
			'dept_code'    => 'FR' === $country ? neotiq_geo_dept_code_from_postcode( $postcode ) : '',
			// In France, county holds the department name ("Essonne", "Corse-du-Sud").
			'dept_name'    => isset( $props['county'] ) ? (string) $props['county'] : '',
			'postcode'     => $postcode,
			'city'         => $city,
			'district'     => isset( $props['district'] ) ? (string) $props['district'] : '',
			'label'        => '' !== $label ? $label : $osm['address'],
		)
	);
}

/**
 * Department code from a French postcode.
 *
 * Corsica (20xxx) deliberately returns an empty string: 2A/2B cannot be told
 * apart from the postcode reliably, so the department name takes over.
 *
 * @param string $postcode Postcode.
 *
 * @return string
 */
function neotiq_geo_dept_code_from_postcode( $postcode ) {
	$postcode = preg_replace( '/\D/', '', (string) $postcode );

	if ( 5 !== strlen( $postcode ) ) {
		return '';
	}

	if ( in_array( substr( $postcode, 0, 2 ), array( '97', '98' ), true ) ) {
		return substr( $postcode, 0, 3 );
	}

	return '20' === substr( $postcode, 0, 2 ) ? '' : substr( $postcode, 0, 2 );
}
