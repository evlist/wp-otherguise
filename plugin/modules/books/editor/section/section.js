/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 224: connects the "Book section" panel to the post editor: the sidebar view (section-ui.js) as a panel of the document sidebar,
 * the floating window (section-window.js) and its menu item, over one model (section-model.js) and the routes of the REST API. Plain
 * JavaScript, no build step: the dependencies are declared in SectionEditor.php. The routes and the post types come from
 * window.booksSection, set by an inline script.
 */
( function ( win, wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! wp.apiFetch || ! wp.i18n
		|| ! win.booksSectionModel || ! win.booksSectionDialog || ! win.booksSectionWindow || ! win.booksSectionUI ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = function ( text ) {
		return wp.i18n.__( text, 'otherguise' );
	};
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var MenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginMoreMenuItem );

	if ( ! Panel ) {
		return;
	}

	function route( suffix, query ) {
		var parts = [];
		Object.keys( query || {} ).forEach( function ( key ) {
			var value = query[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					parts.push( encodeURIComponent( key + '[]' ) + '=' + encodeURIComponent( item ) );
				} );
			} else if ( '' !== value && null !== value && undefined !== value ) {
				parts.push( encodeURIComponent( key ) + '=' + encodeURIComponent( value ) );
			}
		} );
		return settings.path + suffix + ( parts.length ? '?' + parts.join( '&' ) : '' );
	}

	var api = {
		state: function ( id ) {
			return wp.apiFetch( { path: route( '/' + id ) } );
		},
		mark: function ( id, section ) {
			return wp.apiFetch( { path: route( '/' + id ), method: 'PUT', data: { section: !! section } } );
		},
		search: function ( text ) {
			return wp.apiFetch( { path: route( '', { search: text } ) } );
		},
		add: function ( id, ids, place ) {
			var data = { ids: ids };
			if ( place && place.before ) {
				data.before = place.before;
			}
			if ( place && place.after ) {
				data.after = place.after;
			}
			return wp.apiFetch( { path: route( '/' + id + '/contents' ), method: 'POST', data: data } );
		},
		remove: function ( id, post ) {
			return wp.apiFetch( { path: route( '/' + id + '/contents/' + post ), method: 'DELETE' } );
		},
		move: function ( id, post, to ) {
			return wp.apiFetch( { path: route( '/' + id + '/contents/' + post + '/move' ), method: 'POST', data: { to: String( to ) } } );
		},
		reset: function ( id ) {
			return wp.apiFetch( { path: route( '/' + id + '/order' ), method: 'DELETE' } );
		},
		thumbs: function ( id, ids ) {
			return wp.apiFetch( { path: route( '/' + id + '/thumbs', { ids: ids } ) } );
		},
		candidates: function ( id, query ) {
			return wp.apiFetch( { path: route( '/' + id + '/candidates', query ) } );
		},
	};

	function storage() {
		try {
			return win.localStorage;
		} catch ( e ) {
			return null;
		}
	}

	// The model, the dialog and the window of the post being edited: built once, when the editor first renders the plugin (the panel of
	// the sidebar may be closed, and the menu item needs to know whether the post is a section).
	var parts = null;

	function ensure() {
		if ( parts ) {
			return parts;
		}

		var model = win.booksSectionModel.createModel( { postId: wp.data.select( 'core/editor' ).getCurrentPostId(), api: api, __: __ } );
		var dialog = win.booksSectionDialog.createDialog( { document: win.document, model: model, __: __ } );
		var floating = win.booksSectionWindow.createWindow( {
			document: win.document,
			model: model,
			dialog: dialog,
			__: __,
			storage: storage(),
			viewport: function () {
				return { width: win.innerWidth, height: win.innerHeight };
			},
		} );

		parts = { model: model, floating: floating };
		model.load();

		return parts;
	}

	function Body() {
		var ref = wp.element.useRef( null );

		wp.element.useEffect( function () {
			var current = ensure();
			var ui = win.booksSectionUI.mount( {
				document: win.document,
				container: ref.current,
				model: current.model,
				windowControl: current.floating,
				__: __,
			} );

			return function () {
				ui.destroy();
			};
		}, [] );

		return el( 'div', { ref: ref } );
	}

	// The switch "Unlisted" (slice 402): only when the Access module offers its route (window.booksSection.unlisted); the state lives in
	// that module, this panel only shows and changes it.
	function Unlisted() {
		var config = settings.unlisted;
		var Toggle = wp.components && wp.components.ToggleControl;
		var id = wp.data.select( 'core/editor' ).getCurrentPostId();
		var state = wp.element.useState( null );

		function address() {
			return config.path + '/' + id + '/unlisted';
		}

		wp.element.useEffect( function () {
			if ( ! config || ! Toggle ) {
				return;
			}

			wp.apiFetch( { path: address() } ).then( function ( answer ) {
				state[ 1 ]( { unlisted: !! answer.unlisted, busy: false } );
			}, function () {} );
		}, [] );

		if ( ! config || ! Toggle || ! state[ 0 ] ) {
			return null;
		}

		return el( Toggle, {
			label: __( 'Unlisted' ),
			help: __( 'The address works, but the post is left out of the home page, the archives, the search, the feeds, the sitemaps and the previous and next links.' ),
			checked: state[ 0 ].unlisted,
			disabled: state[ 0 ].busy,
			onChange: function ( value ) {
				var before = state[ 0 ].unlisted;

				state[ 1 ]( { unlisted: before, busy: true } );
				wp.apiFetch( { path: address(), method: 'POST', data: { unlisted: !! value } } ).then( function ( answer ) {
					state[ 1 ]( { unlisted: !! answer.unlisted, busy: false } );
				}, function () {
					state[ 1 ]( { unlisted: before, busy: false } );
				} );
			},
		} );
	}

	// The item of the "⋮" menu that opens or closes the window: for a section only.
	function Item() {
		var current = ensure();
		var useState = wp.element.useState;
		var tick = useState( 0 );

		wp.element.useEffect( function () {
			var refresh = function () {
				tick[ 1 ]( function ( value ) {
					return value + 1;
				} );
			};
			var stopModel = current.model.subscribe( refresh );
			var stopWindow = current.floating.subscribe( refresh );

			return function () {
				stopModel();
				stopWindow();
			};
		}, [] );

		if ( ! current.model.getState().section ) {
			return null;
		}

		return el(
			MenuItem,
			{ icon: 'external', onClick: current.floating.toggle },
			current.floating.isOpen() ? __( 'Close the Book section window' ) : __( 'Open the Book section in a window' )
		);
	}

	wp.plugins.registerPlugin( 'books-section', {
		render: function () {
			var type = wp.data.select( 'core/editor' ).getCurrentPostType();

			if ( ! type || settings.postTypes.indexOf( type ) === -1 ) {
				return null;
			}

			return el(
				wp.element.Fragment,
				null,
				el( Panel, { name: 'books-section', title: __( 'Book section' ), className: 'books-section-panel' }, el( Body ), settings.unlisted ? el( Unlisted ) : null ),
				MenuItem ? el( Item ) : null
			);
		},
	} );
}( window, window.wp, window.booksSection ) );
