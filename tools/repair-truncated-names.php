<?php
/**
 * One-off repair for term names and post titles truncated by an old import.
 *
 * The import stored `substr( $name, 0, mb_strlen( $name ) )` — a byte-count cut at a
 * character-count limit — so every name lost one trailing character per accented
 * character it contained. The slug was written from the undamaged name, so the tail
 * that was cut is still readable:
 *
 *     name "1380 Bâgé-le-Châ"   slug "organisation-seminaire-1380-bage-le-chatel"
 *                                                                          ^^^
 *
 * Dry run by default. Re-runnable: a repaired row stops matching and is skipped.
 *
 *     php tools/repair-truncated-names.php [--apply]
 *     /wp-content/plugins/neotiq-geo-sync/tools/repair-truncated-names.php   (admins)
 *
 * @package Neotiq_Geo_Sync
 */

$neotiq_cli = ( 'cli' === PHP_SAPI );

if ( ! defined( 'ABSPATH' ) ) {
	require dirname( __DIR__, 4 ) . '/wp-load.php';
}

if ( ! $neotiq_cli ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'neotiq-geo-sync' ) );
	}

	header( 'Content-Type: text/plain; charset=utf-8' );
}

$neotiq_apply = $neotiq_cli
	? in_array( '--apply', (array) $argv, true )
	: ! empty( $_GET['apply'] ) && check_admin_referer( 'neotiq-repair-names' );

/**
 * Put the truncated tail back, or return '' when this row is not the known damage.
 *
 * @param string $stored The name as it sits in the database.
 * @param string $slug   The slug, written before the truncation happened.
 *
 * @return string
 */
function neotiq_repair_name( $stored, $slug ) {
	// A name with no multi-byte character lost nothing: the cut length equalled the
	// byte length. This also keeps the whole tool away from ordinary rows whose
	// title simply differs from their slug.
	if ( strlen( $stored ) === mb_strlen( $stored, 'UTF-8' ) ) {
		return '';
	}

	$partial = sanitize_title( remove_accents( $stored ) );

	if ( '' === $partial || '' === $slug ) {
		return '';
	}

	// The slug may carry a prefix of its own ("organisation-seminaire-…"), so look
	// for the damaged name inside it rather than assuming it starts there.
	$at = strrpos( $slug, $partial );

	if ( false === $at ) {
		return '';
	}

	$missing = substr( $slug, $at + strlen( $partial ) );

	// A tail that is nothing but a number is WordPress making the slug unique, not a
	// lost word. Without this, any title with exactly as many accents as digits in
	// its "-2" suffix looks like damage.
	if ( '' === $missing || preg_match( '/^-?\d+$/', $missing ) ) {
		return '';
	}

	$repaired = $stored . $missing;

	// The gate. Re-applying the original cut to the candidate has to reproduce the
	// bytes still on disk. An edited title, or an accent inside the lost tail, gives
	// a different byte count and is left alone rather than guessed at.
	return substr( $repaired, 0, mb_strlen( $repaired, 'UTF-8' ) ) === $stored ? $repaired : '';
}

if ( defined( 'NEOTIQ_REPAIR_LIB_ONLY' ) ) {
	return; // Loaded by the test for neotiq_repair_name() alone.
}

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT tt.taxonomy AS taxonomy, t.term_id AS id, t.name AS text, t.slug AS slug
	 FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id",
	ARRAY_A
);

$found     = array();
$ambiguous = array();
$mojibake  = array();

foreach ( $rows as $row ) {
	$repaired = neotiq_repair_name( $row['text'], $row['slug'] );

	if ( '' === $repaired ) {
		// A separate fault, on some of the same rows: the name was read as latin1
		// before it was cut, so "é" became "Ã©" and then lost its tail. The accent
		// byte is gone and the slug has no accents to put it back, so these cannot
		// be repaired from the data alone — count them, leave them.
		if ( str_contains( $row['text'], 'Ã' ) || str_contains( $row['text'], 'Â' ) ) {
			$mojibake[] = $row;
		}

		continue;
	}

	$row['repaired'] = $repaired;
	$found[]         = $row;

	// The restored tail comes out of the slug, so it is lower case and uses "-" for
	// both hyphen and space. Mid-word that is always right; across a separator it is
	// a guess worth eyeballing.
	if ( str_contains( substr( $repaired, strlen( $row['text'] ) ), '-' ) ) {
		$ambiguous[] = $row;
	}
}

printf( "%s — %d row(s) to repair\n\n", $neotiq_apply ? 'APPLYING' : 'DRY RUN', count( $found ) );

$by_kind = array();

foreach ( $found as $row ) {
	$by_kind[ $row['taxonomy'] ][] = $row;
}

ksort( $by_kind );

foreach ( $by_kind as $kind => $list ) {
	printf( "%-28s %d\n", $kind, count( $list ) );

	foreach ( array_slice( $list, 0, 3 ) as $row ) {
		printf( "    %-34s -> %s\n", $row['text'], $row['repaired'] );
	}
}

if ( $ambiguous ) {
	printf( "\n%d restored tail(s) cross a word break — check the capital and the hyphen:\n", count( $ambiguous ) );

	foreach ( array_slice( $ambiguous, 0, 20 ) as $row ) {
		printf( "    %-8s %-34s -> %s\n", $row['id'], $row['text'], $row['repaired'] );
	}
}

if ( $mojibake ) {
	printf( "
%d name(s) were mangled by a separate encoding fault before being cut.
", count( $mojibake ) );
	echo "The accent bytes are gone and the slugs carry no accents, so these cannot be
";
	echo "repaired from the database alone. Left untouched:
";

	foreach ( array_slice( $mojibake, 0, 5 ) as $row ) {
		printf( "    %-8s %-30s %s
", $row['id'], $row['text'], $row['slug'] );
	}
}

if ( ! $found ) {
	echo "Nothing to do.\n";
	return;
}

if ( ! $neotiq_apply ) {
	echo "\nNothing was written. To apply:\n";
	echo $neotiq_cli
		? "    php tools/repair-truncated-names.php --apply\n"
		: '    ' . esc_url_raw( add_query_arg( 'apply', 1, wp_nonce_url( set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ), 'neotiq-repair-names' ) ) ) . "\n";
	return;
}

// Every change, oldest value first, so a bad run can be put back.
$log = WP_CONTENT_DIR . '/neotiq-name-repair-' . gmdate( 'Ymd-His' ) . '.csv';
$out = fopen( $log, 'w' );
fputcsv( $out, array( 'taxonomy', 'term_id', 'before', 'after' ) );

// Yoast's indexable table is optional; only touch it when it is really there.
$yoast = $wpdb->prefix . 'yoast_indexable';
$yoast = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $yoast ) ) === $yoast ? $yoast : '';

$done = 0;

foreach ( $found as $row ) {
	// ponytail: a direct column update, not wp_update_term() — that rebuilds the slug
	// from the name when none is passed, and the slugs are the only undamaged copy of
	// these names. Nothing else on the row changes.
	$written = $wpdb->update(
		$wpdb->terms,
		array( 'name' => $row['repaired'] ),
		array( 'term_id' => $row['id'] )
	);

	if ( false === $written ) {
		printf( "  FAILED term %s: %s\n", $row['id'], $wpdb->last_error );
		continue;
	}

	fputcsv( $out, array( $row['taxonomy'], $row['id'], $row['text'], $row['repaired'] ) );
	clean_term_cache( (int) $row['id'], $row['taxonomy'] );

	// Yoast keeps its own copy of the term name for breadcrumbs and titles; without
	// this the old, cut name keeps showing up long after the term is fixed.
	if ( $yoast ) {
		$wpdb->update(
			$yoast,
			array( 'breadcrumb_title' => $row['repaired'] ),
			array( 'object_type' => 'term', 'object_id' => $row['id'], 'breadcrumb_title' => $row['text'] )
		);
	}

	++$done;
}


fclose( $out );

printf( "\nRepaired %d of %d. Rollback data: %s\n", $done, count( $found ), $log );
