<?php
/**
 * Plugin Name:       Neotiq Geo Sync
 * Plugin URI:        https://neotiq.com/
 * Description:       Derives the Country, Region, Department, Arrondissement and City taxonomies from the ACF OpenStreetMap address of venues and providers. Runs on save in both the admin and the JetFormBuilder front-end forms, and ships a bulk correction tool.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Neotiq
 * Author URI:        https://neotiq.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       neotiq-geo-sync
 * Domain Path:       /languages
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NEOTIQ_GEO_VERSION', '1.3.0' );
define( 'NEOTIQ_GEO_FILE', __FILE__ );
define( 'NEOTIQ_GEO_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Post types carrying an OpenStreetMap address.
 */
const NEOTIQ_GEO_POST_TYPES = array( 'etablissement', 'prestataire' );

/**
 * ACF OpenStreetMap meta keys, in priority order. Both are checked because the
 * prestataire post type uses either one depending on when the post was created.
 */
const NEOTIQ_GEO_OSM_KEYS = array( 'mc_etablissement_localisation_osm', 'mc_prestataire_localisation_osm' );

/**
 * Post meta holding the cached reverse geocoding result.
 */
const NEOTIQ_GEO_CACHE_META = '_neotiq_geo_cache';

/**
 * Bumped whenever the results table schema changes, so an update that skips
 * reactivation still gets its migration.
 */
const NEOTIQ_GEO_DB_VERSION = '1';

require_once NEOTIQ_GEO_PATH . 'includes/storage.php';
require_once NEOTIQ_GEO_PATH . 'includes/geocoding.php';
require_once NEOTIQ_GEO_PATH . 'includes/coordinates.php';
require_once NEOTIQ_GEO_PATH . 'includes/taxonomies.php';
require_once NEOTIQ_GEO_PATH . 'includes/sync.php';
require_once NEOTIQ_GEO_PATH . 'includes/display.php';
require_once NEOTIQ_GEO_PATH . 'includes/hooks.php';

if ( is_admin() ) {
	require_once NEOTIQ_GEO_PATH . 'admin/tools-page.php';
	require_once NEOTIQ_GEO_PATH . 'admin/ajax.php';
}

register_activation_hook( __FILE__, 'neotiq_geo_install' );
add_action( 'admin_init', 'neotiq_geo_maybe_install' );

add_action(
	'init',
	static function () {
		load_plugin_textdomain(
			'neotiq-geo-sync',
			false,
			dirname( plugin_basename( NEOTIQ_GEO_FILE ) ) . '/languages'
		);
	}
);
