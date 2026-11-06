/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 502: the "Space" panel of the editor of the wiki pages. Plain JavaScript, no build step: the dependencies are declared in
 * SpacePanel.php. The post type and the spaces come from window.wikiSpace, set by an inline script.
 *
 * The space is chosen once, before the page is published, and is shown but cannot be changed afterwards (moving a page changes its address,
 * which is the business of another tool). The server decides: it refuses a page published with no space and a change of space.
 */
( function ( wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! wp.components || ! wp.i18n ) {
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

	function SpacePanel() {
		var state = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var saved = editor.getCurrentPost() || {};
			var edited = editor.getEditedPostAttribute( 'meta' ) || {};

			return {
				type: editor.getCurrentPostType(),
				saved: ( saved.meta && saved.meta.wiki_space ) || '',
				chosen: edited.wiki_space || '',
			};
		}, [] );
		var dispatch = wp.data.useDispatch( 'core/editor' );

		if ( state.type !== settings.postType ) {
			return null;
		}

		var locked = '' !== state.saved;
		var options = [ { label: __( 'Choose a space' ), value: '' } ].concat(
			settings.spaces.map( function ( space ) {
				return { label: space.label || space.name, value: space.name };
			} )
		);

		return el(
			Panel,
			{ name: 'wiki-space', title: __( 'Space' ), className: 'wiki-space-panel' },
			el( wp.components.SelectControl, {
				label: __( 'Space of the page' ),
				value: state.chosen,
				options: options,
				disabled: locked,
				help: locked ? __( 'A page stays in its space: moving it changes its address.' ) : __( 'Chosen once, before the page is published.' ),
				onChange: function ( value ) {
					dispatch.editPost( { meta: { wiki_space: value } } );
				},
			} )
		);
	}

	wp.plugins.registerPlugin( 'wiki-space', { render: SpacePanel } );
}( window.wp, window.wikiSpace ) );
