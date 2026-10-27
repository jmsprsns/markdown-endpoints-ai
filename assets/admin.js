/**
 * Content Powered > Markdown Endpoints for AI page behaviour, also used by
 * the Markdown preview page. Only loaded on those two pages.
 *
 * Tabs, copy buttons, the caching check, and the Tools buttons (rebuild,
 * delete cached files, clear the log). markdown_endpoints_ai_admin.ajaxUrl,
 * .nonce and .i18n are passed in from PHP (see
 * Markdown_Endpoints_AI_Admin::enqueue_assets).
 */

/* global markdown_endpoints_ai_admin */
( function () {
	'use strict';

	var data = window.markdown_endpoints_ai_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.mdai-form' );

		initTabs( form );
		initCopy();

		if ( data.ajaxUrl ) {
			initCheck();
			initActions();
		}
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-mdai-tab]' );
		var panels = document.querySelectorAll( '[data-mdai-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		if ( ! tabs.length ) {
			return;
		}

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-mdai-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found ) {
				show( tabs[ 0 ].getAttribute( 'data-mdai-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-mdai-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-mdai-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '[data-mdai-goto]' );

			if ( link ) {
				event.preventDefault();
				show( link.getAttribute( 'data-mdai-goto' ) );
				history.replaceState( null, '', '#' + link.getAttribute( 'data-mdai-goto' ) );
				window.scrollTo( 0, 0 );
			}
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Copy ---------------------------------------------------------------------

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-mdai-copy], [data-mdai-copy-text]' );
			var text;

			if ( ! button ) {
				return;
			}

			if ( button.hasAttribute( 'data-mdai-copy-text' ) ) {
				text = button.getAttribute( 'data-mdai-copy-text' );
			} else {
				var source = document.querySelector( button.getAttribute( 'data-mdai-copy' ) );
				text = source ? source.textContent : '';
			}

			copy( text ).then( function () {
				var label = button.querySelector( 'span' ) || button;
				var before = label.textContent;

				label.textContent = data.i18n.copied;
				window.setTimeout( function () {
					label.textContent = before;
				}, 1500 );
			} );
		} );
	}

	function copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		// Plain-HTTP admin screens have no clipboard API.
		return new Promise( function ( resolve ) {
			var area = document.createElement( 'textarea' );

			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			document.execCommand( 'copy' );
			document.body.removeChild( area );
			resolve();
		} );
	}

	// AJAX ---------------------------------------------------------------------

	function post( action ) {
		var body = new FormData();

		body.append( 'action', 'markdown_endpoints_ai_' + action );
		body.append( 'nonce', data.nonce );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function initCheck() {
		var button = document.querySelector( '[data-mdai-check]' );
		var results = document.querySelector( '[data-mdai-check-results]' );

		if ( ! button || ! results ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var label = button.querySelector( 'span' );
			var before = label.textContent;

			button.disabled = true;
			label.textContent = data.i18n.checking;

			post( 'check' )
				.then( function ( json ) {
					if ( json && json.success ) {
						// Built and escaped on the server (Markdown_Endpoints_AI_Admin::check_results).
						results.innerHTML = json.data.html;
					} else {
						results.textContent = data.i18n.failed;
					}
				} )
				.catch( function () {
					results.textContent = data.i18n.failed;
				} )
				.then( function () {
					button.disabled = false;
					label.textContent = before;
				} );
		} );
	}

	function initActions() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-mdai-action]' );
			var confirmKey;
			var result;

			if ( ! button ) {
				return;
			}

			confirmKey = button.getAttribute( 'data-mdai-confirm' );
			if ( confirmKey && ! window.confirm( data.i18n[ confirmKey ] ) ) {
				return;
			}

			result = button.parentNode.querySelector( '[data-mdai-action-result]' );
			button.disabled = true;
			if ( result ) {
				result.className = 'mdai-action-result';
				result.textContent = data.i18n.working;
			}

			post( button.getAttribute( 'data-mdai-action' ) )
				.then( function ( json ) {
					if ( result ) {
						result.className = 'mdai-action-result' + ( json && json.success ? '' : ' is-error' );
						result.textContent = json && json.success ? json.data.message : data.i18n.failed;
					}
					if ( json && json.success && json.data.state ) {
						setState( json.data.state );
						poll();
					}
				} )
				.catch( function () {
					if ( result ) {
						result.className = 'mdai-action-result is-error';
						result.textContent = data.i18n.failed;
					}
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	function setState( text ) {
		var cell = document.querySelector( '[data-mdai-rebuild-state]' );

		if ( cell ) {
			cell.textContent = text;
		}
	}

	// Follow a background rebuild until it's done.
	function poll() {
		window.setTimeout( function () {
			post( 'rebuild_state' ).then( function ( json ) {
				if ( json && json.success ) {
					setState( json.data.state );
					if ( ! json.data.done ) {
						poll();
					}
				}
			} );
		}, 4000 );
	}
}() );
