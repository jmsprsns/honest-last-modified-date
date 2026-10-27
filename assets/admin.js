/**
 * Content Powered > Honest Last Modified Date page behaviour. Only loaded on that page.
 *
 * Tabs, each content type's on/off switch and rule (which number boxes
 * apply, and the sentence that describes the rule), options that depend on
 * another, and the "Clear activity" confirmation.
 * honest_last_modified_date_admin.i18n comes from PHP (see
 * Honest_Last_Modified_Date_Admin::enqueue_assets).
 */

/* global honest_last_modified_date_admin */
( function () {
	'use strict';

	var data = window.honest_last_modified_date_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.hlmd-form' );

		initTabs( form );
		initTypes();
		initDepends( form );
		initClear();
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-hlmd-tab]' );
		var panels = document.querySelectorAll( '[data-hlmd-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-hlmd-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-hlmd-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-hlmd-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-hlmd-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Content types: switch, rule, numbers and the summary --------------------------

	function format( template, values ) {
		return template.replace( /%(\d)\$s/g, function ( match, index ) {
			var value = values[ parseInt( index, 10 ) - 1 ];

			return value === undefined ? match : value;
		} ).replace( /%%/g, '%' );
	}

	function number( value ) {
		return Number( value ).toLocaleString();
	}

	function initTypes() {
		document.querySelectorAll( '[data-hlmd-panel="rules"]' ).forEach( function ( box ) {
			var master = box.querySelector( '[data-hlmd-master]' );
			var body = box.querySelector( '[data-hlmd-body]' );
			var off = box.querySelector( '[data-hlmd-off]' );
			var words = box.querySelector( '[data-hlmd-words]' );
			var percent = box.querySelector( '[data-hlmd-percent]' );
			var summary = box.querySelector( '[data-hlmd-summary]' );
			var text = box.querySelector( '[data-hlmd-summary-text]' );

			if ( ! master || ! body || ! words || ! percent || ! summary || ! text ) {
				return;
			}

			function rule() {
				var checked = box.querySelector( '[data-hlmd-rule]:checked' );

				return checked ? checked.value : 'either';
			}

			function update() {
				var current = rule();
				var w = Math.max( 1, parseInt( words.value, 10 ) || 1 );
				var p = Math.min( 100, Math.max( 1, parseInt( percent.value, 10 ) || 1 ) );
				var type = summary.getAttribute( 'data-type' ) || '';
				var typical = parseInt( summary.getAttribute( 'data-typical' ), 10 ) || 0;
				var sentence = format( data.i18n[ current ] || data.i18n.either, [ number( w ), number( p ), type ] );
				var byPercent = Math.max( 1, Math.ceil( p * typical / 100 ) );
				var needed;

				body.hidden = ! master.checked;

				if ( off ) {
					off.hidden = master.checked;
				}

				box.querySelectorAll( '[data-hlmd-uses]' ).forEach( function ( field ) {
					var uses = field.getAttribute( 'data-hlmd-uses' );

					field.classList.toggle( 'is-unused', current !== 'either' && current !== 'both' && current !== uses );
				} );

				if ( typical > 0 ) {
					if ( current === 'words' ) {
						sentence += ' ' + format( data.i18n.typicalShare, [ '', '', type, number( typical ), number( Math.min( 100, Math.round( 100 * w / typical ) ) ) ] );
					} else {
						needed = current === 'percent' ? byPercent : ( current === 'both' ? Math.max( w, byPercent ) : Math.min( w, byPercent ) );
						sentence += ' ' + format( data.i18n.typicalWords, [ '', '', type, number( typical ), number( needed ) ] );
					}
				}

				text.textContent = sentence;
			}

			box.addEventListener( 'change', update );
			box.addEventListener( 'input', update );
			update();
		} );
	}

	// Options that only apply while another is ticked ------------------------------

	function initDepends( form ) {
		if ( ! form ) {
			return;
		}

		var rows = form.querySelectorAll( '[data-hlmd-depends]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-hlmd-depends' );
				var box = form.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );

				row.classList.toggle( 'is-disabled', !! box && ! box.checked );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// Clear activity -------------------------------------------------------------

	function initClear() {
		document.querySelectorAll( '[data-hlmd-clear]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( data.i18n.clearConfirm ) ) {
					event.preventDefault();
				}
			} );
		} );
	}
} )();
