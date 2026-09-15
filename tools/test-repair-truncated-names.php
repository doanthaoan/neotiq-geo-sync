<?php
/**
 * Checks the gate in neotiq_repair_name(): it must rebuild the known damage and
 * refuse everything else.
 *
 *     php tools/test-repair-truncated-names.php
 *
 * @package Neotiq_Geo_Sync
 */

define( 'NEOTIQ_REPAIR_LIB_ONLY', true );
define( 'WP_USE_THEMES', false );

require dirname( __DIR__, 4 ) . '/wp-load.php';
require __DIR__ . '/repair-truncated-names.php';

$cases = array(
	// Real damage, rebuilt from the slug.
	array( '1380 Bâgé-le-Châ', 'organisation-seminaire-1380-bage-le-chatel', '1380 Bâgé-le-Châtel' ),
	array( '7360 Dunière-sur-Eyrieu', 'organisation-seminaire-7360-duniere-sur-eyrieux', '7360 Dunière-sur-Eyrieux' ),
	array( '1300 Ambléo', 'organisation-seminaire-1300-ambleon', '1300 Ambléon' ),
	array( '7340 Féline', 'organisation-seminaire-7340-felines', '7340 Félines' ),

	// Left alone: nothing was lost.
	array( '7170 Darbres', 'organisation-seminaire-7170-darbres', '' ),
	array( '1500 Ambérieu-en-Bugey', 'organisation-seminaire-1500-amberieu-en-bugey', '' ),

	// Left alone: a title that simply differs from its slug is not this damage.
	array( 'Séminaire à Paris', 'seminaire-a-paris-2', '' ),
	array( 'Nos établissements', 'etablissements-partenaires', '' ),
	array( 'Réservez', 'reservez-votre-salle-maintenant', '' ),

	// Left alone: the cut landed inside a character, so the bytes cannot be trusted.
	array( "Caf\xC3", 'cafe', '' ),
);

$failed = 0;

foreach ( $cases as list( $stored, $slug, $expected ) ) {
	$actual = neotiq_repair_name( $stored, $slug );

	if ( $actual === $expected ) {
		continue;
	}

	printf( "FAIL  %-26s + %-48s\n        expected %-26s got %s\n", $stored, $slug, var_export( $expected, true ), var_export( $actual, true ) );
	++$failed;
}

// Whatever it returns has to survive the original cut unchanged — the invariant the
// whole repair rests on.
foreach ( $cases as list( $stored, $slug, $expected ) ) {
	$actual = neotiq_repair_name( $stored, $slug );

	if ( '' !== $actual && substr( $actual, 0, mb_strlen( $actual, 'UTF-8' ) ) !== $stored ) {
		printf( "FAIL  %s does not re-truncate to %s\n", $actual, $stored );
		++$failed;
	}
}

printf( "%d case(s), %d failed\n", count( $cases ), $failed );
exit( $failed ? 1 : 0 );
