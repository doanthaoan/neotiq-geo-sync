/**
 * Bulk correction tool.
 *
 * Checking and reviewing are independent: a check walks the catalogue in
 * batches and writes what it finds, the results table below reads those stored
 * findings back, one page at a time.
 */
( function () {
	'use strict';

	var config = window.neotiqGeoSync;

	if ( ! config ) {
		return;
	}

	var el = function ( id ) {
		return document.getElementById( id );
	};

	var form          = el( 'neotiq-geo-form' );
	var rows          = el( 'neotiq-geo-rows' );
	var progress      = el( 'neotiq-geo-progress' );
	var summary       = el( 'neotiq-geo-summary' );
	var lastRun       = el( 'neotiq-geo-last-run' );
	var showing       = el( 'neotiq-geo-showing' );
	var applyBar      = el( 'neotiq-geo-apply-bar' );
	var applyButton   = el( 'neotiq-geo-apply' );
	var applyAll      = el( 'neotiq-geo-apply-all' );
	var selectedCount = el( 'neotiq-geo-selected-count' );
	var selectAll     = el( 'neotiq-geo-select-all' );
	var runButton     = el( 'neotiq-geo-run' );
	var stopButton    = el( 'neotiq-geo-stop' );

	var page    = 1;
	var pages   = 1;
	var total   = 0;
	var stopped = false;

	/* ---- plumbing ------------------------------------------------------ */

	function request( action, data ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( payload ) {
			if ( ! payload || ! payload.success ) {
				throw new Error( 'neotiq-geo-sync: request rejected' );
			}

			return payload.data;
		} );
	}

	/** sprintf for the handful of placeholders used here. */
	function format( template, values ) {
		var index = 0;

		return String( template )
			.replace( /%(\d)\$[ds]/g, function ( match, position ) {
				return values[ position - 1 ];
			} )
			.replace( /%[ds]/g, function () {
				return values[ index++ ];
			} );
	}

	function fail( error ) {
		window.console.error( error );
		progress.textContent = config.i18n.failed;
	}

	/* ---- settings and filters ------------------------------------------ */

	function scanSettings() {
		return {
			post_type: el( 'neotiq-geo-post-type' ).value,
			post_status: el( 'neotiq-geo-post-status' ).value,
			mode: el( 'neotiq-geo-mode' ).value,
			chunk: el( 'neotiq-geo-chunk' ).value,
			apply: el( 'neotiq-geo-apply-now' ).checked ? '1' : '',
			create_euville: el( 'neotiq-geo-create-euville' ).checked ? '1' : '',
			force: el( 'neotiq-geo-force' ).checked ? '1' : ''
		};
	}

	function filters() {
		return {
			status: el( 'neotiq-geo-filter-status' ).value,
			post_type: el( 'neotiq-geo-filter-type' ).value
		};
	}

	/* ---- summary and rows ---------------------------------------------- */

	function renderSummary( data ) {
		if ( ! data ) {
			return;
		}

		summary.textContent = Object.keys( data.counts ).map( function ( status ) {
			return ( config.labels[ status ] || status ) + ': ' + data.counts[ status ];
		} ).join( ' · ' );

		lastRun.textContent = data.lastRun
			? format( config.i18n.lastRun, [ data.lastRun ] )
			: config.i18n.neverRun;
	}

	function putRow( row ) {
		var existing = el( 'neotiq-geo-row-' + row.post_id );

		if ( existing ) {
			existing.outerHTML = row.html;
		} else {
			rows.insertAdjacentHTML( 'beforeend', row.html );
		}
	}

	function busy( running ) {
		runButton.disabled = running;
		stopButton.hidden  = ! running;

		form.querySelectorAll( 'input, select' ).forEach( function ( field ) {
			field.disabled = running;
		} );
	}

	function refreshSelection() {
		var pickable = rows.querySelectorAll( '.neotiq-geo-pick' );
		var selected = rows.querySelectorAll( '.neotiq-geo-pick:checked' ).length;

		selectedCount.textContent = format( config.i18n.selected, [ selected ] );
		applyButton.disabled      = 0 === selected;
		applyBar.hidden           = 0 === pickable.length;

		// Ticking a page at a time is useless across thousands of rows, so offer
		// the whole filtered set as one action when it spills past this page.
		var correctable = 'pending' === filters().status ? total : 0;

		applyAll.hidden = ! correctable || correctable <= pickable.length;

		if ( ! applyAll.hidden ) {
			applyAll.textContent = format( config.i18n.applyAll, [ correctable ] );
		}
	}

	/* ---- reading stored results ---------------------------------------- */

	function load( targetPage ) {
		page = Math.max( 1, targetPage || 1 );

		rows.innerHTML = '';
		showing.textContent = config.i18n.loading;
		selectAll.checked = false;

		var data = filters();

		data.page     = page;
		data.per_page = el( 'neotiq-geo-per-page' ).value;

		return request( 'neotiq_geo_results', data ).then( function ( payload ) {
			total = payload.total;
			pages = payload.pages;

			payload.rows.forEach( putRow );
			renderSummary( payload.summary );

			if ( ! payload.rows.length ) {
				showing.textContent = config.i18n.noResults;
			} else {
				var perPage = parseInt( data.per_page, 10 );
				var first   = ( page - 1 ) * perPage + 1;

				showing.textContent = format( config.i18n.showing, [ first, first + payload.rows.length - 1, total ] );
			}

			el( 'neotiq-geo-prev' ).disabled = page <= 1;
			el( 'neotiq-geo-next' ).disabled = page >= pages;

			refreshSelection();
		} ).catch( fail );
	}

	/* ---- checking ------------------------------------------------------- */

	function scan() {
		stopped = false;
		progress.textContent = config.i18n.scanning;
		busy( true );

		var options   = scanSettings();
		var processed = 0;
		var expected  = 0;

		function batch( afterId ) {
			var data = Object.assign( {}, options );

			if ( afterId ) {
				data.after_id = afterId;
			}

			return request( 'neotiq_geo_scan', data ).then( function ( payload ) {
				if ( undefined !== payload.total ) {
					expected = payload.total;
				}

				processed += payload.rows.length;
				renderSummary( payload.summary );

				if ( ! expected ) {
					progress.textContent = config.i18n.nothingToDo;

					return;
				}

				progress.textContent = format( config.i18n.progress, [ processed, expected ] );

				if ( stopped ) {
					progress.textContent += ' — ' + config.i18n.cancelled;

					return;
				}

				if ( ! payload.finished ) {
					return batch( payload.afterId );
				}

				progress.textContent = format( config.i18n.finished, [ processed ] );
			} );
		}

		batch( 0 ).catch( fail ).then( function () {
			busy( false );

			return load( 1 );
		} );
	}

	/* ---- applying ------------------------------------------------------- */

	function applyTo( ids ) {
		if ( ! ids.length ) {
			window.alert( config.i18n.nothing );

			return;
		}

		if ( ! window.confirm( format( config.i18n.confirm, [ ids.length ] ) ) ) {
			return;
		}

		var size    = Math.max( 1, parseInt( el( 'neotiq-geo-chunk' ).value, 10 ) || 20 );
		var create  = el( 'neotiq-geo-create-euville' ).checked ? '1' : '';
		var applied = 0;

		applyButton.disabled = true;
		applyAll.disabled    = true;
		progress.textContent = config.i18n.applying;

		function batch( index ) {
			if ( index >= ids.length ) {
				return Promise.resolve();
			}

			return request( 'neotiq_geo_apply', {
				post_ids: ids.slice( index, index + size ),
				create_euville: create
			} ).then( function ( payload ) {
				// Only rows on screen need repainting; the rest live in the table.
				payload.rows.forEach( function ( row ) {
					if ( el( 'neotiq-geo-row-' + row.post_id ) ) {
						putRow( row );
					}
				} );

				applied += payload.rows.length;
				renderSummary( payload.summary );
				progress.textContent = format( config.i18n.applied, [ applied ] );

				return batch( index + size );
			} );
		}

		batch( 0 ).then( function () {
			progress.textContent = format( config.i18n.applied, [ applied ] );
			applyAll.disabled    = false;

			return load( page );
		} ).catch( fail );
	}

	/* ---- wiring --------------------------------------------------------- */

	runButton.addEventListener( 'click', scan );

	stopButton.addEventListener( 'click', function () {
		stopped = true;
	} );

	applyButton.addEventListener( 'click', function () {
		applyTo( Array.prototype.map.call(
			rows.querySelectorAll( '.neotiq-geo-pick:checked' ),
			function ( input ) {
				return input.value;
			}
		) );
	} );

	applyAll.addEventListener( 'click', function () {
		request( 'neotiq_geo_result_ids', filters() ).then( function ( payload ) {
			applyTo( payload.ids );
		} ).catch( fail );
	} );

	selectAll.addEventListener( 'change', function () {
		rows.querySelectorAll( '.neotiq-geo-pick' ).forEach( function ( input ) {
			input.checked = selectAll.checked;
		} );

		refreshSelection();
	} );

	rows.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'neotiq-geo-pick' ) ) {
			refreshSelection();
		}
	} );

	el( 'neotiq-geo-refresh' ).addEventListener( 'click', function () {
		load( page );
	} );

	el( 'neotiq-geo-prev' ).addEventListener( 'click', function () {
		load( page - 1 );
	} );

	el( 'neotiq-geo-next' ).addEventListener( 'click', function () {
		load( page + 1 );
	} );

	[ 'neotiq-geo-filter-status', 'neotiq-geo-filter-type', 'neotiq-geo-per-page' ].forEach( function ( id ) {
		el( id ).addEventListener( 'change', function () {
			load( 1 );
		} );
	} );

	el( 'neotiq-geo-clear' ).addEventListener( 'click', function () {
		if ( ! window.confirm( config.i18n.confirmClear ) ) {
			return;
		}

		request( 'neotiq_geo_clear', {} ).then( function ( payload ) {
			progress.textContent = format( config.i18n.cleared, [ payload.removed ] );
			renderSummary( payload.summary );

			return load( 1 );
		} ).catch( fail );
	} );

	/* ---- tabs ----------------------------------------------------------- */

	document.querySelectorAll( '[data-neotiq-tab]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			document.querySelectorAll( '[data-neotiq-tab]' ).forEach( function ( other ) {
				var active = other === tab;

				other.classList.toggle( 'nav-tab-active', active );
				el( 'neotiq-geo-tab-' + other.dataset.neotiqTab ).hidden = ! active;
			} );
		} );
	} );

	/* ---- map coordinates ------------------------------------------------ */

	var coordPanel    = el( 'neotiq-geo-coord-panel' );
	var coordRun      = el( 'neotiq-geo-coord-run' );
	var coordStop     = el( 'neotiq-geo-coord-stop' );
	var coordProgress = el( 'neotiq-geo-coord-progress' );
	var coordCoverage = el( 'neotiq-geo-coord-coverage' );
	var coordStopped  = false;

	function renderCoverage( data ) {
		if ( ! data ) {
			return;
		}

		coordCoverage.textContent = format(
			config.i18n.coverage,
			[ data.total, data.withAddress, data.withCoordinates, data.withLocation, data.missing ]
		);
	}

	function extract() {
		coordStopped = false;
		coordProgress.textContent = config.i18n.extracting;
		coordRun.disabled = true;
		coordStop.hidden  = false;

		coordPanel.querySelectorAll( 'select, input' ).forEach( function ( field ) {
			field.disabled = true;
		} );

		var options = {
			post_type: el( 'neotiq-geo-coord-post-type' ).value,
			post_status: el( 'neotiq-geo-coord-post-status' ).value,
			mode: el( 'neotiq-geo-coord-mode' ).value,
			chunk: el( 'neotiq-geo-coord-chunk' ).value
		};

		var tally    = { written: 0, unchanged: 0, no_data: 0 };
		var expected = 0;
		var seen     = 0;

		function batch( afterId ) {
			var data = Object.assign( {}, options );

			if ( afterId ) {
				data.after_id = afterId;
			}

			return request( 'neotiq_geo_coordinates', data ).then( function ( payload ) {
				if ( undefined !== payload.total ) {
					expected = payload.total;
				}

				Object.keys( tally ).forEach( function ( key ) {
					tally[ key ] += payload.tally[ key ];
					seen         += payload.tally[ key ];
				} );

				renderCoverage( payload.summary );

				if ( ! expected ) {
					coordProgress.textContent = config.i18n.extractNone;

					return;
				}

				coordProgress.textContent = format( config.i18n.progress, [ seen, expected ] );

				if ( coordStopped ) {
					coordProgress.textContent += ' — ' + config.i18n.cancelled;

					return;
				}

				if ( ! payload.finished ) {
					return batch( payload.afterId );
				}

				coordProgress.textContent = format(
					config.i18n.extracted,
					[ tally.written, tally.unchanged, tally.no_data ]
				);
			} );
		}

		batch( 0 ).catch( function ( error ) {
			window.console.error( error );
			coordProgress.textContent = config.i18n.failed;
		} ).then( function () {
			coordRun.disabled = false;
			coordStop.hidden  = true;

			coordPanel.querySelectorAll( 'select, input' ).forEach( function ( field ) {
				field.disabled = false;
			} );
		} );
	}

	coordRun.addEventListener( 'click', extract );

	coordStop.addEventListener( 'click', function () {
		coordStopped = true;
	} );

	/* ---- boot ----------------------------------------------------------- */

	renderSummary( config.summary );
	renderCoverage( config.coordinates );
	load( 1 );
}() );
