/**
 * "Last Modified Date" box in the classic editor: keeps the words-changed
 * count and the verdict current as you type (checked on the server about a
 * second after you stop). The choice radios are part of the post form.
 * honest_last_modified_date_editor comes from PHP (see
 * Honest_Last_Modified_Date_Editor::script_data).
 */

/* global wp, tinymce, honest_last_modified_date_editor */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! config ) {
		return;
	}

	var CHECK_DELAY = 1200;
	var box;
	var timer = null;
	var lastSent = null;
	var requestId = 0;
	var status = config.status;

	function part( name ) {
		return box.querySelector( '[data-hlmd="' + name + '"]' );
	}

	function choice() {
		var checked = box.querySelector( 'input[name="hlmd_choice"]:checked' );

		return checked ? checked.value : '';
	}

	function render() {
		var meter = part( 'meter' );
		var verdict = part( 'verdict' );
		var picked = choice();
		var update = picked === 'update' || ( picked !== 'keep' && status.met );

		if ( meter ) {
			meter.classList.toggle( 'is-met', !! status.met );
			meter.firstElementChild.style.width = Math.min( 100, status.meter ) + '%';
		}

		[ 'count', 'percent', 'rule' ].forEach( function ( name ) {
			var node = part( name );
			var text = name === 'count' ? status.countText : ( name === 'percent' ? status.percentText : config.i18n.choiceRule.replace( '%s', status.rule ) );

			if ( node && typeof text === 'string' ) {
				node.textContent = text;
			}
		} );

		if ( verdict ) {
			verdict.classList.toggle( 'is-update', update );
			verdict.textContent = update ? config.i18n.willUpdate : config.i18n.willKeep;
		}
	}

	function current() {
		var editor = typeof tinymce !== 'undefined' ? tinymce.get( 'content' ) : null;
		var textarea = document.getElementById( 'content' );
		var title = document.getElementById( 'title' );
		var content = editor && ! editor.isHidden() ? editor.getContent() : ( textarea ? textarea.value : '' );

		return { title: title ? title.value : '', content: content };
	}

	function check() {
		var values = current();
		var key = values.title + '\u0000' + values.content;
		var id;

		if ( key === lastSent ) {
			return;
		}

		lastSent = key;
		requestId += 1;
		id = requestId;

		wp.apiFetch( {
			path: config.route,
			method: 'POST',
			data: { id: config.postId, title: values.title, content: values.content },
		} ).then( function ( fresh ) {
			if ( id === requestId && fresh && fresh.published && ! fresh.tooLong ) {
				status = fresh;
				render();
			}
		} ).catch( function () {} );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( check, CHECK_DELAY );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		box = document.querySelector( '[data-hlmd-box]' );

		if ( ! box || ! status || ! status.published ) {
			return;
		}

		lastSent = current().title + '\u0000' + current().content;

		box.addEventListener( 'change', render );

		[ 'title', 'content' ].forEach( function ( id ) {
			var field = document.getElementById( id );

			if ( field ) {
				field.addEventListener( 'input', schedule );
			}
		} );

		// The visual editor lives in an iframe; TinyMCE reports its own changes.
		if ( typeof tinymce !== 'undefined' ) {
			var hook = function ( editor ) {
				if ( editor && editor.id === 'content' ) {
					editor.on( 'input keyup change undo redo SetContent', schedule );
				}
			};

			if ( tinymce.get( 'content' ) ) {
				hook( tinymce.get( 'content' ) );
			}

			tinymce.on( 'AddEditor', function ( event ) {
				hook( event.editor );
			} );
		}
	} );
} )( window.wp, window.honest_last_modified_date_editor );
