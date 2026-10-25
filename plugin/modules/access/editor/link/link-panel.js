/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 400: the "Share link" panel of the post editor, in plain DOM (no React, no build step) so that it can be tested in jsdom. link.js
 * connects it to the editor. Exposed as window.accessLinkPanel.mount, and as a CommonJS module for the tests.
 *
 * "No link" with a button to make one; with a link, its address (to copy), a button to replace it and a button to revoke it, each of the
 * last two asking for a confirmation in the panel. Anyone who has the address can read the post, which the panel says.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.accessLinkPanel = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	/**
	 * Mounts the panel in a container.
	 *
	 * options: document, container, postId, api ({ state( id ), create( id, regenerate ), revoke( id ) }, each returning a promise of
	 * { active, url, created }), __ (translation), clipboard (optional: { writeText } returning a promise; default navigator.clipboard).
	 */
	function mount( options ) {
		var doc = options.document;
		var api = options.api;
		var postId = options.postId;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var state = { loaded: false, active: false, url: '', created: '' };
		var asking = '';
		var message = '';
		var failed = '';

		function make( tag, className, text ) {
			var node = doc.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( text !== undefined ) {
				node.textContent = text;
			}
			return node;
		}

		function button( label, className, onClick ) {
			var node = make( 'button', className + ' components-button is-secondary is-small', label );
			node.type = 'button';
			node.addEventListener( 'click', onClick );
			return node;
		}

		var rootNode = make( 'div', 'access-link' );
		options.container.appendChild( rootNode );

		function apply( answer ) {
			if ( answer && typeof answer === 'object' ) {
				state = { loaded: true, active: !! answer.active, url: answer.url || '', created: answer.created || '' };
			}
			failed = '';
			asking = '';
			render();
		}

		function fail( failure ) {
			failed = failure && failure.message ? failure.message : __( 'Something went wrong.' );
			asking = '';
			render();
		}

		function copy( input ) {
			var clipboard = options.clipboard || ( typeof navigator !== 'undefined' ? navigator.clipboard : null );
			var done = function () {
				message = __( 'Copied.' );
				render();
			};
			var manual = function () {
				input.select();
				message = __( 'Select the address and copy it.' );
				render();
			};

			if ( clipboard && clipboard.writeText ) {
				clipboard.writeText( state.url ).then( done, manual );
			} else {
				manual();
			}
		}

		function render() {
			rootNode.textContent = '';

			if ( failed ) {
				var error = make( 'div', 'access-link__error', failed );
				error.setAttribute( 'role', 'alert' );
				rootNode.appendChild( error );
			}

			if ( ! state.loaded ) {
				rootNode.appendChild( make( 'p', 'access-link__loading', __( 'Loading…' ) ) );
				return;
			}

			if ( ! state.active ) {
				rootNode.appendChild( make( 'p', 'access-link__none', __( 'No link. Only people who may edit this post can see it while it is not published.' ) ) );
				rootNode.appendChild( button( __( 'Create the link' ), 'access-link__create', function () {
					api.create( postId, false ).then( apply, fail );
				} ) );
				return;
			}

			rootNode.appendChild( make( 'p', 'access-link__warning', __( 'Anyone who has this address can read this post, even if it is not published, until you revoke it.' ) ) );

			var input = make( 'input', 'access-link__url' );
			input.type = 'text';
			input.readOnly = true;
			input.value = state.url;
			input.setAttribute( 'aria-label', __( 'Address of the link' ) );
			input.addEventListener( 'focus', function () {
				input.select();
			} );
			rootNode.appendChild( input );

			if ( state.created ) {
				rootNode.appendChild( make( 'p', 'access-link__created', __( 'Made on' ) + ' ' + state.created.slice( 0, 10 ) + ' (GMT)' ) );
			}

			if ( message ) {
				var note = make( 'p', 'access-link__message', message );
				note.setAttribute( 'role', 'status' );
				rootNode.appendChild( note );
				message = '';
			}

			var actions = make( 'div', 'access-link__actions' );

			if ( asking ) {
				var replace = 'regenerate' === asking;

				actions.appendChild( make( 'span', 'access-link__ask', replace ? __( 'Replace the link? The old one stops working.' ) : __( 'Revoke the link?' ) ) );
				actions.appendChild( button( __( 'Yes' ), 'access-link__yes', function () {
					( replace ? api.create( postId, true ) : api.revoke( postId ) ).then( apply, fail );
				} ) );
				actions.appendChild( button( __( 'No' ), 'access-link__no', function () {
					asking = '';
					render();
				} ) );
			} else {
				actions.appendChild( button( __( 'Copy' ), 'access-link__copy', function () {
					copy( input );
				} ) );
				actions.appendChild( button( __( 'Replace' ), 'access-link__regenerate', function () {
					asking = 'regenerate';
					render();
				} ) );
				actions.appendChild( button( __( 'Revoke' ), 'access-link__revoke', function () {
					asking = 'revoke';
					render();
				} ) );
			}

			rootNode.appendChild( actions );
		}

		render();
		api.state( postId ).then( apply, fail );

		return {
			refresh: function () {
				return api.state( postId ).then( apply, fail );
			},
			destroy: function () {
				if ( rootNode.parentNode ) {
					rootNode.parentNode.removeChild( rootNode );
				}
			},
			node: rootNode,
		};
	}

	return { mount: mount };
} ) );
