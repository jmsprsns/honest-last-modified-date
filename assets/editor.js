/**
 * "Last modified date" panel in the block editor's document sidebar.
 *
 * Shows the date readers see, how many words have changed since then against
 * the rule (checked on the server as you type, about a second after you
 * stop), and whether updating now would move the date. The editor's choice
 * for one update ("Set today's date" / "Keep this date") rides along with
 * the save request itself as hlmd_choice, then resets.
 * honest_last_modified_date_editor comes from PHP (see
 * Honest_Last_Modified_Date_Editor::script_data).
 */

/* global wp, honest_last_modified_date_editor */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config || ! wp.plugins || ! wp.element || ! wp.components || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var RawHTML = wp.element.RawHTML;
	var useSelect = wp.data.useSelect;
	var RadioControl = wp.components.RadioControl;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var i18n = config.i18n;
	var CHECK_DELAY = 1200;

	// The choice for the next save, read by the request middleware below.
	var nextChoice = '';

	if ( ! Panel || ! useSelect ) {
		return;
	}

	function isSaveRequest( options ) {
		var method = String( options.method || 'GET' ).toUpperCase();
		var path = String( options.path || '' ).split( '?' )[ 0 ].replace( /\/+$/, '' );

		return ( method === 'POST' || method === 'PUT' ) && config.postPath !== '' && path === config.postPath;
	}

	wp.apiFetch.use( function ( options, next ) {
		if ( nextChoice && isSaveRequest( options ) && ( ! options.data || typeof options.data === 'object' ) ) {
			options = Object.assign( {}, options, {
				data: Object.assign( {}, options.data || {}, { hlmd_choice: nextChoice } ),
			} );
		}

		return next( options );
	} );

	function verdict( status, choice ) {
		var update = choice === 'update' || ( choice !== 'keep' && status.met );

		return {
			update: update,
			text: update ? i18n.willUpdate : i18n.willKeep,
		};
	}

	function LastModifiedPanel() {
		var statusState = useState( config.status );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var choiceState = useState( '' );
		var choice = choiceState[ 0 ];
		var setChoice = choiceState[ 1 ];
		var failedState = useState( false );
		var failed = failedState[ 0 ];
		var setFailed = failedState[ 1 ];
		var timer = useRef( null );
		var lastSent = useRef( null );
		var requestId = useRef( 0 );
		var wasSaving = useRef( false );

		var editor = useSelect( function ( select ) {
			var store = select( 'core/editor' );
			var blocks = select( 'core/block-editor' );

			return {
				title: store.getEditedPostAttribute( 'title' ),
				blocks: blocks ? blocks.getBlocks() : null,
				saving: store.isSavingPost(),
				autosaving: store.isAutosavingPost(),
				failed: store.didPostSaveRequestFail(),
				published: store.getCurrentPostAttribute( 'status' ) === 'publish',
			};
		}, [] );

		function check( force ) {
			var store = wp.data.select( 'core/editor' );
			var title = store.getEditedPostAttribute( 'title' ) || '';
			var content = store.getEditedPostContent();
			var key = title + '\u0000' + content;
			var id;

			if ( ! force && key === lastSent.current ) {
				return;
			}

			lastSent.current = key;
			requestId.current += 1;
			id = requestId.current;

			wp.apiFetch( {
				path: config.route,
				method: 'POST',
				data: { id: config.postId, title: title, content: content },
			} ).then( function ( fresh ) {
				if ( id === requestId.current ) {
					setStatus( fresh );
					setFailed( false );
				}
			} ).catch( function () {
				if ( id === requestId.current ) {
					setFailed( true );
				}
			} );
		}

		// Re-check about a second after typing stops.
		useEffect( function () {
			if ( ! editor.published ) {
				return undefined;
			}

			clearTimeout( timer.current );
			timer.current = setTimeout( function () {
				check( false );
			}, CHECK_DELAY );

			return function () {
				clearTimeout( timer.current );
			};
		}, [ editor.title, editor.blocks, editor.published ] );

		// After a real save: the choice is used up, and the date may have moved.
		useEffect( function () {
			if ( editor.saving && ! editor.autosaving ) {
				wasSaving.current = true;
				return;
			}

			if ( wasSaving.current && ! editor.saving ) {
				wasSaving.current = false;

				if ( ! editor.failed ) {
					nextChoice = '';
					setChoice( '' );
					check( true );
				}
			}
		}, [ editor.saving, editor.autosaving, editor.failed ] );

		function choose( value ) {
			nextChoice = value;
			setChoice( value );
		}

		var children;

		if ( ! editor.published || ! status.published ) {
			children = el( 'p', { className: 'hlmd-note' }, i18n.notPublished );
		} else if ( status.tooLong ) {
			children = el( 'p', { className: 'hlmd-note' }, i18n.tooLong );
		} else {
			var result = verdict( status, choice );

			children = [
				el( 'div', { className: 'hlmd-shown', key: 'shown' },
					el( RawHTML, null, config.icon ),
					el( 'span', { className: 'hlmd-shown-text' },
						el( 'span', { className: 'hlmd-shown-label' }, i18n.shown ),
						el( 'strong', null, status.shown )
					)
				),
				el( 'div', { className: 'hlmd-meter' + ( status.met ? ' is-met' : '' ), key: 'meter' },
					el( 'span', { style: { width: Math.min( 100, status.meter ) + '%' } } )
				),
				el( 'p', { className: 'hlmd-count', key: 'count' },
					el( 'span', null, status.countText ),
					' ',
					el( 'span', { className: 'hlmd-muted' }, status.percentText )
				),
				el( 'p', { className: 'hlmd-verdict' + ( result.update ? ' is-update' : '' ), key: 'verdict' }, result.text ),
				failed ? el( 'p', { className: 'hlmd-note', key: 'failed' }, i18n.failed ) : null,
				config.allowChoice ? el( 'div', { className: 'hlmd-choices', key: 'choices' },
					el( RadioControl, {
						label: i18n.whenUpdate,
						selected: choice,
						options: [
							{ label: i18n.choiceRule.replace( '%s', status.rule ), value: '' },
							{ label: i18n.choiceUpdate, value: 'update' },
							{ label: i18n.choiceKeep, value: 'keep' },
						],
						onChange: choose,
					} )
				) : null,
			];
		}

		return el( Panel, {
			name: 'honest-last-modified-date',
			title: i18n.title,
			className: 'hlmd-panel',
		}, children );
	}

	wp.plugins.registerPlugin( 'honest-last-modified-date', {
		render: LastModifiedPanel,
		icon: null,
	} );
} )( window.wp, window.honest_last_modified_date_editor );
