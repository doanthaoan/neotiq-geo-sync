<?php
/**
 * Bulk correction tool, under Tools.
 *
 * Scanning and reviewing are separate: a scan writes its findings to the
 * results table, and the review screen reads that table back, filtered and
 * paginated. Reviewing a catalogue therefore costs one query, not a full
 * re-scan.
 *
 * @package Neotiq_Geo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook suffix of our page, remembered so assets load nowhere else.
 *
 * @var string
 */
$GLOBALS['neotiq_geo_page_hook'] = '';

add_action(
	'admin_menu',
	static function () {
		$GLOBALS['neotiq_geo_page_hook'] = add_submenu_page(
			'tools.php',
			__( 'Neotiq Geo Sync', 'neotiq-geo-sync' ),
			__( 'Neotiq Geo Sync', 'neotiq-geo-sync' ),
			'manage_options',
			'neotiq-geo-sync',
			'neotiq_geo_render_page'
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook_suffix ) {
		if ( $hook_suffix !== $GLOBALS['neotiq_geo_page_hook'] ) {
			return;
		}

		wp_enqueue_script(
			'neotiq-geo-sync',
			plugins_url( 'admin/js/tools.js', NEOTIQ_GEO_FILE ),
			array(),
			NEOTIQ_GEO_VERSION,
			true
		);

		wp_localize_script(
			'neotiq-geo-sync',
			'neotiqGeoSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'neotiq-geo-ajax' ),
				'summary' => neotiq_geo_summary( 'all' ),
				'labels'  => array(
					'no_data'  => neotiq_geo_status_label( 'no_data' ),
					'error'    => neotiq_geo_status_label( 'error' ),
					'conflict' => neotiq_geo_status_label( 'conflict' ),
					'ok'       => neotiq_geo_status_label( 'ok' ),
					'pending'  => neotiq_geo_status_label( 'pending' ),
					'updated'  => neotiq_geo_status_label( 'updated' ),
				),
				'i18n'    => array(
					'scanning'     => __( 'Checking…', 'neotiq-geo-sync' ),
					'progress'     => __( 'Checked %1$d of %2$d', 'neotiq-geo-sync' ),
					'finished'     => __( 'Check finished: %d post(s) processed.', 'neotiq-geo-sync' ),
					'nothingToDo'  => __( 'Nothing to check: every post is up to date. Use "Re-check everything" to force a full pass.', 'neotiq-geo-sync' ),
					'applying'     => __( 'Applying…', 'neotiq-geo-sync' ),
					'applied'      => __( 'Applied to %d post(s).', 'neotiq-geo-sync' ),
					'selected'     => __( '%d selected', 'neotiq-geo-sync' ),
					'nothing'      => __( 'Select at least one post first.', 'neotiq-geo-sync' ),
					'confirm'      => __( "Apply the corrections to %d post(s)?\n\nBack up your database first: this overwrites the existing terms and cannot be undone from this screen.", 'neotiq-geo-sync' ),
					'confirmClear' => __( 'Delete every stored result? The posts themselves are not touched, but the next check will have to walk through all of them again.', 'neotiq-geo-sync' ),
					'cleared'      => __( '%d stored result(s) deleted.', 'neotiq-geo-sync' ),
					'failed'       => __( 'The request failed. Check the browser console, then start the batch again.', 'neotiq-geo-sync' ),
					'cancelled'    => __( 'Stopped. Start it again to carry on where it left off.', 'neotiq-geo-sync' ),
					'loading'      => __( 'Loading…', 'neotiq-geo-sync' ),
					'noResults'    => __( 'No stored result matches this filter.', 'neotiq-geo-sync' ),
					'showing'      => __( 'Showing %1$d–%2$d of %3$d', 'neotiq-geo-sync' ),
					'lastRun'      => __( 'Last check: %s', 'neotiq-geo-sync' ),
					'neverRun'     => __( 'No check stored yet.', 'neotiq-geo-sync' ),
					'applyAll'     => __( 'Apply to all %d matching posts', 'neotiq-geo-sync' ),
					'extracting'   => __( 'Extracting…', 'neotiq-geo-sync' ),
					'extracted'    => __( 'Finished: %1$d updated, %2$d already up to date, %3$d with nothing to derive.', 'neotiq-geo-sync' ),
					'extractNone'  => __( 'Nothing to rebuild: every post is up to date. Use "Rebuild all" to refresh them anyway.', 'neotiq-geo-sync' ),
					'coverage'     => __( '%1$d posts · %2$d with an address · %3$d with coordinates · %4$d with a location line · %5$d still to do', 'neotiq-geo-sync' ),
				),
				'coordinates' => neotiq_geo_coordinates_summary( 'all' ),
			)
		);
	}
);

/**
 * Human label for one of our post types, taken from its registration.
 *
 * @param string $post_type Post type name.
 *
 * @return string
 */
function neotiq_geo_post_type_label( $post_type ) {
	$object = get_post_type_object( $post_type );

	return ( $object && ! empty( $object->labels->name ) ) ? $object->labels->name : $post_type;
}

/**
 * Sanitise the tool settings coming from the browser.
 *
 * @param array $input Raw request data.
 *
 * @return array
 */
function neotiq_geo_sanitize_args( array $input ) {
	$args = array(
		'post_type'      => isset( $input['post_type'] ) ? sanitize_key( wp_unslash( $input['post_type'] ) ) : 'all',
		'post_status'    => isset( $input['post_status'] ) ? sanitize_key( wp_unslash( $input['post_status'] ) ) : 'any',
		'mode'           => isset( $input['mode'] ) ? sanitize_key( wp_unslash( $input['mode'] ) ) : 'incremental',
		'apply'          => ! empty( $input['apply'] ),
		'force'          => ! empty( $input['force'] ),
		'create_euville' => ! empty( $input['create_euville'] ),
		'chunk'          => isset( $input['chunk'] ) ? max( 1, min( 100, (int) $input['chunk'] ) ) : 20,
	);

	if ( ! in_array( $args['post_type'], array_merge( array( 'all' ), NEOTIQ_GEO_POST_TYPES ), true ) ) {
		$args['post_type'] = 'all';
	}

	if ( ! in_array( $args['post_status'], array( 'any', 'publish' ), true ) ) {
		$args['post_status'] = 'any';
	}

	if ( ! in_array( $args['mode'], array( 'incremental', 'full' ), true ) ) {
		$args['mode'] = 'incremental';
	}

	// A forced re-geocode only makes sense as a full pass.
	if ( $args['force'] ) {
		$args['mode'] = 'full';
	}

	return $args;
}

/**
 * Render the tool page.
 */
function neotiq_geo_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'neotiq-geo-sync' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Neotiq Geo Sync', 'neotiq-geo-sync' ); ?></h1>
		<p><?php esc_html_e( 'Reads the OpenStreetMap address of every post and corrects its country, region, department, arrondissement and city terms. Findings are stored, so you can review them later without checking everything again.', 'neotiq-geo-sync' ); ?></p>

		<h2 class="nav-tab-wrapper">
			<a href="#" class="nav-tab nav-tab-active" data-neotiq-tab="taxonomies"><?php esc_html_e( 'Location taxonomies', 'neotiq-geo-sync' ); ?></a>
			<a href="#" class="nav-tab" data-neotiq-tab="coordinates"><?php esc_html_e( 'Listing data', 'neotiq-geo-sync' ); ?></a>
		</h2>

		<div id="neotiq-geo-tab-taxonomies">
			<?php
			neotiq_geo_render_scan_panel();
			neotiq_geo_render_results_panel();
			?>
		</div>

		<div id="neotiq-geo-tab-coordinates" hidden>
			<?php neotiq_geo_render_coordinates_panel(); ?>
		</div>
	</div>
	<?php
}

/**
 * The map coordinate extraction controls.
 */
function neotiq_geo_render_coordinates_panel() {
	?>
	<div id="neotiq-geo-coord-panel" class="card" style="max-width:900px;padding:4px 16px;">
		<h2><?php esc_html_e( 'Rebuild listing data', 'neotiq-geo-sync' ); ?></h2>
		<p>
			<?php esc_html_e( 'Writes the two sets of fields a listing needs: map_lat, map_lng and map_coordinate for JetEngine map listings and the distance search, and _neotiq_location, _neotiq_city, _neotiq_department, _neotiq_dept_code, _neotiq_arrondissement and _neotiq_arrondissement_code for listing cards and search ordering. Both are derived from data the posts already carry, so this needs no geocoding and runs at full speed, independently of the address check. New and edited posts are kept up to date automatically; run this once after installing to fill in the posts that already exist.', 'neotiq-geo-sync' ); ?>
		</p>
		<p><strong id="neotiq-geo-coord-coverage"></strong></p>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="neotiq-geo-coord-post-type"><?php esc_html_e( 'Post type', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-coord-post-type">
						<option value="all"><?php esc_html_e( 'All', 'neotiq-geo-sync' ); ?></option>
						<?php foreach ( NEOTIQ_GEO_POST_TYPES as $post_type ) : ?>
							<option value="<?php echo esc_attr( $post_type ); ?>">
								<?php echo esc_html( neotiq_geo_post_type_label( $post_type ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-coord-post-status"><?php esc_html_e( 'Status', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-coord-post-status">
						<option value="any"><?php esc_html_e( 'Any status', 'neotiq-geo-sync' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published only', 'neotiq-geo-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-coord-mode"><?php esc_html_e( 'Scope', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-coord-mode">
						<option value="incremental"><?php esc_html_e( 'Only what is missing', 'neotiq-geo-sync' ); ?></option>
						<option value="full"><?php esc_html_e( 'Rebuild all', 'neotiq-geo-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Values that already match are left alone either way, so rewriting all is safe, just slower.', 'neotiq-geo-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-coord-chunk"><?php esc_html_e( 'Posts per batch', 'neotiq-geo-sync' ); ?></label></th>
				<td><input type="number" id="neotiq-geo-coord-chunk" min="1" max="100" value="100"></td>
			</tr>
		</table>
		<p>
			<button type="button" class="button button-primary" id="neotiq-geo-coord-run"><?php esc_html_e( 'Start', 'neotiq-geo-sync' ); ?></button>
			<button type="button" class="button" id="neotiq-geo-coord-stop" hidden><?php esc_html_e( 'Stop', 'neotiq-geo-sync' ); ?></button>
			<span id="neotiq-geo-coord-progress" class="description"></span>
		</p>
	</div>
	<?php
}

/**
 * The scan controls.
 */
function neotiq_geo_render_scan_panel() {
	?>
	<div id="neotiq-geo-form" class="card" style="max-width:900px;padding:4px 16px;">
		<h2><?php esc_html_e( 'Check addresses', 'neotiq-geo-sync' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="neotiq-geo-post-type"><?php esc_html_e( 'Post type', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-post-type" name="post_type">
						<option value="all"><?php esc_html_e( 'All', 'neotiq-geo-sync' ); ?></option>
						<?php foreach ( NEOTIQ_GEO_POST_TYPES as $post_type ) : ?>
							<option value="<?php echo esc_attr( $post_type ); ?>">
								<?php echo esc_html( neotiq_geo_post_type_label( $post_type ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-post-status"><?php esc_html_e( 'Status', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-post-status" name="post_status">
						<option value="any"><?php esc_html_e( 'Any status', 'neotiq-geo-sync' ); ?></option>
						<option value="publish"><?php esc_html_e( 'Published only', 'neotiq-geo-sync' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-mode"><?php esc_html_e( 'Scope', 'neotiq-geo-sync' ); ?></label></th>
				<td>
					<select id="neotiq-geo-mode" name="mode">
						<option value="incremental"><?php esc_html_e( 'New and modified posts only', 'neotiq-geo-sync' ); ?></option>
						<option value="full"><?php esc_html_e( 'Re-check everything', 'neotiq-geo-sync' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'The first option skips posts already checked and untouched since, so a second pass costs almost nothing. Stopping and starting again carries on where it left off.', 'neotiq-geo-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="neotiq-geo-chunk"><?php esc_html_e( 'Posts per batch', 'neotiq-geo-sync' ); ?></label></th>
				<td><input type="number" id="neotiq-geo-chunk" name="chunk" min="1" max="100" value="20"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Options', 'neotiq-geo-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="neotiq-geo-apply-now" name="apply" value="1">
						<strong><?php esc_html_e( 'Apply the corrections while checking', 'neotiq-geo-sync' ); ?></strong>
						<?php esc_html_e( '— leave unchecked to review first and pick what to correct', 'neotiq-geo-sync' ); ?>
					</label><br>
					<label>
						<input type="checkbox" id="neotiq-geo-create-euville" name="create_euville" value="1">
						<?php esc_html_e( 'Create missing euville terms', 'neotiq-geo-sync' ); ?>
					</label><br>
					<label>
						<input type="checkbox" id="neotiq-geo-force" name="force" value="1">
						<?php esc_html_e( 'Ignore the geocoding cache (forces a full pass and re-queries every address)', 'neotiq-geo-sync' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="button button-primary" id="neotiq-geo-run"><?php esc_html_e( 'Start', 'neotiq-geo-sync' ); ?></button>
			<button type="button" class="button" id="neotiq-geo-stop" hidden><?php esc_html_e( 'Stop', 'neotiq-geo-sync' ); ?></button>
			<span id="neotiq-geo-progress" class="description"></span>
		</p>
	</div>
	<?php
}

/**
 * The stored results browser.
 */
function neotiq_geo_render_results_panel() {
	?>
	<h2><?php esc_html_e( 'Stored results', 'neotiq-geo-sync' ); ?></h2>
	<p>
		<span id="neotiq-geo-last-run" class="description"></span><br>
		<span id="neotiq-geo-summary"></span>
	</p>

	<div class="tablenav top">
		<div class="alignleft actions">
			<label class="screen-reader-text" for="neotiq-geo-filter-status"><?php esc_html_e( 'Filter by status', 'neotiq-geo-sync' ); ?></label>
			<select id="neotiq-geo-filter-status">
				<option value="all"><?php esc_html_e( 'All statuses', 'neotiq-geo-sync' ); ?></option>
				<option value="pending"><?php echo esc_html( neotiq_geo_status_label( 'pending' ) ); ?></option>
				<option value="updated"><?php echo esc_html( neotiq_geo_status_label( 'updated' ) ); ?></option>
				<option value="ok"><?php echo esc_html( neotiq_geo_status_label( 'ok' ) ); ?></option>
				<option value="conflict"><?php echo esc_html( neotiq_geo_status_label( 'conflict' ) ); ?></option>
				<option value="no_data"><?php echo esc_html( neotiq_geo_status_label( 'no_data' ) ); ?></option>
				<option value="error"><?php echo esc_html( neotiq_geo_status_label( 'error' ) ); ?></option>
			</select>

			<label class="screen-reader-text" for="neotiq-geo-filter-type"><?php esc_html_e( 'Filter by post type', 'neotiq-geo-sync' ); ?></label>
			<select id="neotiq-geo-filter-type">
				<option value="all"><?php esc_html_e( 'All', 'neotiq-geo-sync' ); ?></option>
				<?php foreach ( NEOTIQ_GEO_POST_TYPES as $post_type ) : ?>
					<option value="<?php echo esc_attr( $post_type ); ?>"><?php echo esc_html( neotiq_geo_post_type_label( $post_type ) ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="neotiq-geo-per-page"><?php esc_html_e( 'Rows per page', 'neotiq-geo-sync' ); ?></label>
			<select id="neotiq-geo-per-page">
				<option value="50">50</option>
				<option value="100">100</option>
				<option value="200">200</option>
			</select>

			<button type="button" class="button" id="neotiq-geo-refresh"><?php esc_html_e( 'Refresh', 'neotiq-geo-sync' ); ?></button>
			<button type="button" class="button" id="neotiq-geo-clear"><?php esc_html_e( 'Delete stored results', 'neotiq-geo-sync' ); ?></button>
		</div>
		<div class="tablenav-pages">
			<span id="neotiq-geo-showing" class="displaying-num"></span>
			<button type="button" class="button" id="neotiq-geo-prev">&lsaquo;</button>
			<button type="button" class="button" id="neotiq-geo-next">&rsaquo;</button>
		</div>
	</div>

	<div id="neotiq-geo-apply-bar" class="notice notice-warning" hidden>
		<p>
			<strong><?php esc_html_e( 'Back up your database before applying.', 'neotiq-geo-sync' ); ?></strong>
			<?php esc_html_e( 'Corrections replace the existing terms on the selected posts and cannot be undone from this screen.', 'neotiq-geo-sync' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary" id="neotiq-geo-apply">
				<?php esc_html_e( 'Apply the selected corrections', 'neotiq-geo-sync' ); ?>
			</button>
			<button type="button" class="button" id="neotiq-geo-apply-all" hidden></button>
			<span id="neotiq-geo-selected-count" class="description"></span>
		</p>
	</div>

	<table class="widefat striped">
		<thead>
			<tr>
				<td class="check-column">
					<input type="checkbox" id="neotiq-geo-select-all" title="<?php esc_attr_e( 'Select every correctable post on this page', 'neotiq-geo-sync' ); ?>">
				</td>
				<th><?php esc_html_e( 'ID', 'neotiq-geo-sync' ); ?></th>
				<th><?php esc_html_e( 'Post', 'neotiq-geo-sync' ); ?></th>
				<th><?php esc_html_e( 'Status', 'neotiq-geo-sync' ); ?></th>
				<th><?php esc_html_e( 'Changes', 'neotiq-geo-sync' ); ?></th>
				<th><?php esc_html_e( 'Details', 'neotiq-geo-sync' ); ?></th>
			</tr>
		</thead>
		<tbody id="neotiq-geo-rows"></tbody>
	</table>
	<?php
}

/**
 * Render one result row.
 *
 * @param array $display    Display row, from neotiq_geo_display_row() or storage.
 * @param bool  $selectable Show the checkbox that queues the post for correction.
 *
 * @return string Table row markup.
 */
function neotiq_geo_row_html( array $display, $selectable ) {
	$changes = array();

	foreach ( $display['changes'] as $taxonomy => $pair ) {
		$changes[] = sprintf(
			'<strong>%s</strong>: %s → %s',
			esc_html( $taxonomy ),
			esc_html( '' !== $pair['before'] ? $pair['before'] : '—' ),
			esc_html( '' !== $pair['after'] ? $pair['after'] : '—' )
		);
	}

	$checkbox = ( $selectable && $display['changes'] )
		? sprintf( '<input type="checkbox" class="neotiq-geo-pick" value="%d">', (int) $display['post_id'] )
		: '';

	$checked = ! empty( $display['checked'] )
		? sprintf(
			'<br><span class="description">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: date and time of the last check. */
					__( 'checked %s', 'neotiq-geo-sync' ),
					$display['checked']
				)
			)
		)
		: '';

	return sprintf(
		'<tr id="neotiq-geo-row-%1$d" data-status="%2$s">
			<th scope="row" class="check-column">%3$s</th>
			<td>%1$d</td>
			<td><a href="%4$s" target="_blank">%5$s</a><br><span class="description">%6$s</span></td>
			<td>%7$s%8$s</td>
			<td>%9$s</td>
			<td><span class="description">%10$s</span></td>
		</tr>',
		(int) $display['post_id'],
		esc_attr( $display['status'] ),
		$checkbox,
		esc_url( (string) get_edit_post_link( $display['post_id'] ) ),
		esc_html( $display['title'] ),
		esc_html( neotiq_geo_post_type_label( $display['post_type'] ) ),
		esc_html( neotiq_geo_status_label( $display['status'] ) ),
		$checked,
		$changes ? implode( '<br>', $changes ) : '—',
		esc_html( implode( ' · ', $display['notes'] ) )
	);
}
