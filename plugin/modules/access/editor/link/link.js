/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 400: connects the "Share link" panel (link-panel.js) to the post editor, as a panel of the document sidebar. Plain JavaScript, no
 * build step: the dependencies are declared in LinkPanel.php. The route and the post types come from window.accessLink, set by an inline
 * script.
 */
( function ( win, wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! wp.apiFetch || ! wp.i18n || ! win.accessLinkPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = function ( text ) {
		return wp.i18n.__( text, 'otherguise' );
	};
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! Panel ) {
		return;
	}

	var api = {
		state: function ( id ) {
			return wp.apiFetch( { path: settings.path + '/' + id } );
		},
		create: function ( id, regenerate ) {
			return wp.apiFetch( { path: settings.path + '/' + id, method: 'POST', data: { regenerate: !! regenerate } } );
		},
		revoke: function ( id ) {
			return wp.apiFetch( { path: settings.path + '/' + id, method: 'DELETE' } );
		},
	};

	function Body() {
		var ref = wp.element.useRef( null );

		wp.element.useEffect( function () {
			var panel = win.accessLinkPanel.mount( {
				document: win.document,
				container: ref.current,
				postId: wp.data.select( 'core/editor' ).getCurrentPostId(),
				api: api,
				__: __,
			} );

			return function () {
				panel.destroy();
			};
		}, [] );

		return el( 'div', { ref: ref } );
	}

	wp.plugins.registerPlugin( 'access-link', {
		render: function () {
			var type = wp.data.select( 'core/editor' ).getCurrentPostType();

			if ( ! type || settings.postTypes.indexOf( type ) === -1 ) {
				return null;
			}

			return el( Panel, { name: 'access-link', title: __( 'Share link' ), className: 'access-link-panel' }, el( Body ) );
		},
	} );
}( window, window.wp, window.accessLink ) );
