<?php
/**
 * The listing fields: the three location shapes, the stored meta, and the hooks that
 * keep it current when terms change by any route.
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
		printf( "  ok    %-36s %s\n", $label, '' === $actual ? '(empty)' : $actual );

		return;
	}

	printf( "  FAIL  %-36s expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	++$failed;
}

/* ---- 1. Each of the three shapes ---------------------------------------- */

$plain = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'ville'
	 WHERE p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
$parts = neotiq_geo_location_parts( $plain );
check( 'France, city term', neotiq_geo_location_line( $plain ), $parts['department'] . ', ' . $parts['city'] );
check( '  city carries no postcode', (bool) preg_match( '/^\d/', $parts['city'] ), false );
check( '  department code kept for sorting', (bool) preg_match( '/^\d+$/', $parts['department_code'] ), true );

// Paris: the department term is "75 Paris" and the city term is "Paris", so the
// department has to drop out rather than print twice.
$paris = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
	 WHERE tt.taxonomy = 'localisation' AND tt.parent = 163 AND p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
$parts = neotiq_geo_location_parts( $paris );
check( 'Paris, arrondissement', neotiq_geo_location_line( $paris ), 'Paris, ' . $parts['arrondissement'] );

$abroad = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
	 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'euville'
	 WHERE p.post_type IN ('etablissement','prestataire') LIMIT 1"
);
check( 'outside France', neotiq_geo_location_line( $abroad ), neotiq_geo_location_parts( $abroad )['city'] );
check( '  no department', neotiq_geo_location_parts( $abroad )['department'], '' );

/* ---- 2. The terms themselves are left alone ----------------------------- */

$ville_name = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT t.name FROM {$wpdb->terms} t
		 INNER JOIN {$wpdb->term_relationships} r ON r.term_taxonomy_id = (
			SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = t.term_id AND tt.taxonomy = 'ville' )
		 WHERE r.object_id = %d LIMIT 1",
		$plain
	)
);
check( 'term name keeps its code', (bool) preg_match( '/^\d+\s/', (string) $ville_name ), true );

/* ---- 3. Stored as real meta, readable by a Dynamic Field ---------------- */

foreach ( neotiq_geo_listing_keys() as $key ) {
	check( 'row exists: ' . $key, metadata_exists( 'post', $plain, $key ), true );
}

check( 'meta matches the builder', get_post_meta( $plain, '_neotiq_location', true ), neotiq_geo_location_line( $plain ) );
check( 'city meta', get_post_meta( $plain, '_neotiq_city', true ), neotiq_geo_location_parts( $plain )['city'] );
check( 'writing twice changes nothing', neotiq_geo_store_listing_meta( $plain ), 'unchanged' );

// A post with no location still gets its rows, so a rebuild pass stops revisiting it.
$nowhere = (int) $wpdb->get_var(
	"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ('etablissement','prestataire')
	   AND NOT EXISTS (
		SELECT 1 FROM {$wpdb->term_relationships} r
		INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id
		WHERE r.object_id = p.ID AND tt.taxonomy IN ('localisation','ville','euville') ) LIMIT 1"
);

if ( $nowhere ) {
	check( 'no location, empty value', get_post_meta( $nowhere, '_neotiq_location', true ), '' );
	check( 'no location, row still written', metadata_exists( 'post', $nowhere, '_neotiq_location' ), true );
}

/* ---- 4. Terms changing by any route rebuilds the fields ----------------- */

$before = get_post_meta( $plain, '_neotiq_city', true );
$terms  = wp_get_object_terms( $plain, 'ville', array( 'fields' => 'ids' ) );

wp_set_object_terms( $plain, array(), 'ville' );
neotiq_geo_flush_listing_queue();
check( 'terms removed, meta follows', get_post_meta( $plain, '_neotiq_city', true ), '' );

wp_set_object_terms( $plain, $terms, 'ville' );
neotiq_geo_flush_listing_queue();
check( 'terms restored, meta follows', get_post_meta( $plain, '_neotiq_city', true ), $before );

// Renaming a term has to reach every post carrying it.
$term_id = (int) $terms[0];
$name    = get_term( $term_id )->name;
wp_update_term( $term_id, 'ville', array( 'name' => $name . ' TEST', 'slug' => get_term( $term_id )->slug ) );
neotiq_geo_flush_listing_queue();
check( 'term renamed, meta follows', get_post_meta( $plain, '_neotiq_city', true ), neotiq_geo_plain_name( $name . ' TEST' ) );

wp_update_term( $term_id, 'ville', array( 'name' => $name, 'slug' => get_term( $term_id )->slug ) );
neotiq_geo_flush_listing_queue();
check( 'term name restored', get_post_meta( $plain, '_neotiq_city', true ), $before );

/* ---- 5. Shortcode ------------------------------------------------------- */

check( 'shortcode', do_shortcode( '[neotiq_location id="' . $plain . '"]' ), esc_html( neotiq_geo_location_line( $plain ) ) );
check( 'shortcode city', do_shortcode( '[neotiq_location id="' . $plain . '" field="city"]' ), esc_html( neotiq_geo_location_parts( $plain )['city'] ) );

/* ---- 6. A grid of cards reads the meta, not the terms ------------------- */

$grid = new WP_Query(
	array(
		'post_type'      => array( 'etablissement', 'prestataire' ),
		'post_status'    => 'publish',
		'posts_per_page' => 20,
	)
);

$before_count = count( $wpdb->queries );

foreach ( $grid->posts as $post ) {
	get_post_meta( $post->ID, '_neotiq_location', true );
}

$cost = count( $wpdb->queries ) - $before_count;
printf( "\n  %d card(s) rendered in %d quer%s\n", count( $grid->posts ), $cost, 1 === $cost ? 'y' : 'ies' );
check( 'grid adds no queries', $cost, 0 );

printf( "\n%s\n", $failed ? "$failed check(s) failed" : 'all listing field checks passed' );
exit( $failed ? 1 : 0 );
