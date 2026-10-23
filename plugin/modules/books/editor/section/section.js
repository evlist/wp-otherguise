/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 223: connects the "Section" panel (section-ui.js) to the post editor, as a panel of the document sidebar. Plain JavaScript, no
 * build step: the dependencies are declared in SectionEditor.php. The routes and the post types come from window.booksSection, set by an
 * inline script.
 */
( function ( win, wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! wp.apiFetch || ! win.booksSectionUI ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

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
		add: function ( id, ids ) {
			return wp.apiFetch( { path: route( '/' + id + '/contents' ), method: 'POST', data: { ids: ids } } );
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
		candidates: function ( id, query ) {
			return wp.apiFetch( { path: route( '/' + id + '/candidates', query ) } );
		},
	};

	function Body() {
		var ref = wp.element.useRef( null );

		wp.element.useEffect( function () {
			var editor = wp.data.select( 'core/editor' );
			var ui = win.booksSectionUI.mount( {
				document: win.document,
				container: ref.current,
				postId: editor.getCurrentPostId(),
				api: api,
				__: __,
			} );

			return function () {
				ui.destroy();
			};
		}, [] );

		return el( 'div', { ref: ref } );
	}

	wp.plugins.registerPlugin( 'books-section', {
		render: function () {
			var type = wp.data.select( 'core/editor' ).getCurrentPostType();

			if ( ! type || settings.postTypes.indexOf( type ) === -1 ) {
				return null;
			}

			return el( Panel, { name: 'books-section', title: __( 'Section' ), className: 'books-section-panel' }, el( Body ) );
		},
	} );
}( window, window.wp, window.booksSection ) );
