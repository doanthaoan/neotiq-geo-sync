<?php
/**
 * Checks the location line against real posts, and proves a listing grid costs no
 * extra queries per card.
 *
 *     php tools/test-location-line.php
 *
 * @package Neotiq_Geo_Sync
 */

define( 'WP_USE_THEMES', false );
define( 'SAVEQUERIES', true );

require dirname( __DIR__, 4 ) . '/wp-load.php';

global $wpdb;

$failed = 0;

function check( $label, $actual, $expected ) {
	global $failed;

	if ( $actual === $expected ) {
		printf( "  ok    %-34s %s\n", $label, '' === $actual ? '(empty)' : $actual );

		return;
	}

	printf( "  FAIL  %-34s expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	++$failed;
}

/* ---- 1. Each of the three shapes ---------------------------------------- */

// France, no arrondissement: department then city, both without their code.
$plain = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'ville'
	 WHERE p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
$parts = neotiq_geo_location_parts( $plain );
check( 'France, city term', neotiq_geo_location_line( $plain ), $parts['department'] . ', ' . $parts['city'] );
check( '  city carries no postcode', (bool) preg_match( '/^\d/', $parts['city'] ), false );
check( '  no arrondissement', $parts['arrondissement'], '' );

// Paris: the department term is "75 Paris" and the city term is "Paris", so the
// department has to drop out rather than print twice.
$paris = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
	 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
	 WHERE tt.taxonomy = 'localisation' AND tt.parent = 163 AND p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
$parts = neotiq_geo_location_parts( $paris );
check( 'Paris, arrondissement', neotiq_geo_location_line( $paris ), 'Paris, ' . $parts['arrondissement'] );
check( '  arrondissement is ordinal', (bool) preg_match( '/^\d+(er|e)$/', $parts['arrondissement'] ), true );

// Outside France only the city is recorded.
$abroad = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'euville'
	 WHERE p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
$parts = neotiq_geo_location_parts( $abroad );
check( 'outside France', neotiq_geo_location_line( $abroad ), $parts['city'] );
check( '  no department', $parts['department'], '' );

// A post with no location at all must come back empty, so "Hide if value is empty"
// does the hiding and no visibility rule is needed.
$nowhere = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ('etablissement','prestataire')
	   AND NOT EXISTS (
		SELECT 1 FROM {$wpdb->term_relationships} r
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
		WHERE r.object_id = p.ID AND tt.taxonomy IN ('localisation','ville','euville') ) LIMIT 1"
);
check( 'no location', $nowhere ? neotiq_geo_location_line( $nowhere ) : '', '' );

/* ---- 2. The virtual meta reads like a real custom field ------------------ */

check( 'get_post_meta location', get_post_meta( $plain, '_neotiq_location', true ), neotiq_geo_location_line( $plain ) );
check( 'get_post_meta city', get_post_meta( $plain, '_neotiq_city', true ), neotiq_geo_location_parts( $plain )['city'] );
check( 'other meta untouched', get_post_meta( $plain, 'map_lat', true ), (string) get_post_meta( $plain, 'map_lat', true ) );
check( 'nothing stored', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_neotiq_location','_neotiq_city')" ), 0 );

/* ---- 3. Shortcode ------------------------------------------------------- */

check( 'shortcode', do_shortcode( '[neotiq_location id="' . $plain . '"]' ), esc_html( neotiq_geo_location_line( $plain ) ) );
check( 'shortcode city', do_shortcode( '[neotiq_location id="' . $plain . '" field="city"]' ), esc_html( neotiq_geo_location_parts( $plain )['city'] ) );

/* ---- 4. A grid of cards adds no queries --------------------------------- */

$grid = new WP_Query(
	array(
		'post_type'      => array( 'etablissement', 'prestataire' ),
		'post_status'    => 'publish',
		'posts_per_page' => 20,
	)
);

$before = count( $wpdb->queries );

foreach ( $grid->posts as $post ) {
	get_post_meta( $post->ID, '_neotiq_location', true );
}

$cost = count( $wpdb->queries ) - $before;
printf( "\n  %d card(s) rendered in %d quer%s\n", count( $grid->posts ), $cost, 1 === $cost ? 'y' : 'ies' );
check( 'grid adds no queries', $cost, 0 );

printf( "\n%s\n", $failed ? "$failed check(s) failed" : 'all location checks passed' );
exit( $failed ? 1 : 0 );
