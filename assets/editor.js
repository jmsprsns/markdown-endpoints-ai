/**
 * "Markdown for AI" panel in the block editor's sidebar.
 *
 * Shows whether agents get a Markdown version of the post, switches it off
 * for this post, sets its content signals, and links to the side-by-side
 * preview. Settings are post meta saved with the post (registered in
 * Markdown_Endpoints_AI_Editor::register_meta). The status is refreshed after
 * each save. markdown_endpoints_ai_editor is passed in from PHP.
 */

/* global markdown_endpoints_ai_editor */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var RawHTML = wp.element.RawHTML;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var components = wp.components;
	var i18n = config.i18n;

	if ( ! Panel || ! wp.data || ! wp.data.useSelect ) {
		return;
	}

	function fill( text, values ) {
		return values.reduce( function ( out, value, index ) {
			return out.replace( '%' + ( index + 1 ) + '$s', value );
		}, text ).replace( '%%', '%' );
	}

	function fetchStatus() {
		var body = new FormData();

		body.append( 'action', 'markdown_endpoints_ai_post_status' );
		body.append( 'nonce', config.nonce );
		body.append( 'post', config.postId );

		return fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function MarkdownPanel() {
		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var saving = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return editor.isSavingPost() && ! editor.isAutosavingPost();
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;
		var statusState = useState( config.status );
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var wasSaving = useRef( false );

		// After a save, ask again: publishing or a changed setting changes the answer.
		useEffect( function () {
			if ( wasSaving.current && ! saving ) {
				window.setTimeout( function () {
					fetchStatus().then( function ( json ) {
						if ( json && json.success ) {
							setStatus( json.data );
						}
					} );
				}, 800 );
			}
			wasSaving.current = saving;
		}, [ saving ] );

		function setMeta( key, value ) {
			var change = {};

			change[ key ] = value;
			editPost( { meta: change } );
		}

		function signal( key, label, fallback ) {
			return el( components.SelectControl, {
				label: label,
				value: meta[ key ] || '',
				options: [
					{ label: fill( i18n.siteDefault.replace( '%s', '%1$s' ), [ fallback ] ), value: '' },
					{ label: i18n.yes, value: 'yes' },
					{ label: i18n.no, value: 'no' }
				],
				onChange: function ( value ) {
					setMeta( key, value );
				},
				__nextHasNoMarginBottom: true
			} );
		}

		var served = status.served;
		var children = [
			el(
				'div',
				{ key: 'status', className: 'mdai-panel-status ' + ( served ? 'is-on' : 'is-off' ) },
				el( RawHTML, null, served ? config.icons.on : config.icons.off ),
				el( 'span', null, served ? i18n.served : status.reason )
			)
		];

		if ( served ) {
			children.push(
				el(
					'p',
					{ key: 'stats', className: 'mdai-panel-stats' },
					status.tokens ? fill( i18n.stats, [ status.tokens.toLocaleString(), String( status.saved ) ] ) : i18n.pending
				)
			);
		}

		children.push(
			el( components.ToggleControl, {
				key: 'toggle',
				label: i18n.toggle,
				checked: ! meta._mdai_exclude,
				onChange: function ( on ) {
					setMeta( '_mdai_exclude', ! on );
				},
				__nextHasNoMarginBottom: true
			} ),
			el(
				'div',
				{ key: 'buttons', className: 'mdai-panel-buttons' },
				el( components.Button, { variant: 'secondary', href: status.preview, target: '_blank' }, i18n.view ),
				status.url ? el( components.ExternalLink, { href: status.url }, i18n.open ) : null
			),
			el(
				'div',
				{ key: 'signals', className: 'mdai-panel-signals' },
				el( 'h3', null, i18n.signals ),
				signal( '_mdai_signal_search', i18n.search, config.defaults.search ),
				signal( '_mdai_signal_input', i18n.input, config.defaults.input ),
				signal( '_mdai_signal_train', i18n.train, config.defaults.train )
			)
		);

		return el( Panel, { name: 'markdown-endpoints-ai', title: i18n.title, className: 'mdai-panel' }, children );
	}

	wp.plugins.registerPlugin( 'markdown-endpoints-ai', { render: MarkdownPanel, icon: null } );
}( window.wp, window.markdown_endpoints_ai_editor ) );
