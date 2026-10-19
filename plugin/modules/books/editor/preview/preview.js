/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 219: connects the floating print preview panel (panel.js) to the post editor: a menu item opens it, and the state of the post
 * (dirty, saved) tells it when it is out of date and when to load the page again. Plain JavaScript, no build step: the dependencies are
 * declared in EditorPreview.php. The format of the page and the modes come from window.booksPreview, set by an inline script.
 */
( function ( win, wp, settings ) {
	if ( ! wp || ! settings || ! wp.plugins || ! wp.data || ! wp.element || ! win.booksPreviewPanel || ! win.booksPreviewGeometry ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var geometry = win.booksPreviewGeometry;
	var panel = null;
	var previous = { saving: false, autosaving: false };

	function editor() {
		return wp.data.select( 'core/editor' );
	}

	function getPost() {
		var store = editor();

		return {
			status: store.getCurrentPostAttribute( 'status' ),
			permalink: store.getPermalink(),
			previewLink: store.getEditedPostPreviewLink(),
		};
	}

	function storage() {
		try {
			return win.localStorage;
		} catch ( e ) {
			return null;
		}
	}

	function ensurePanel() {
		if ( ! panel ) {
			panel = win.booksPreviewPanel.createPanel( {
				document: win.document,
				geometry: geometry,
				settings: settings,
				getPost: getPost,
				__: __,
				storage: storage(),
			} );
			panel.setStale( !! editor().isEditedPostDirty() );
		}

		return panel;
	}

	// The panel is out of date while the post has changes that are not saved; the page is loaded again when a save ends well (not for an
	// autosave: the address shows the saved post, which an autosave does not change).
	wp.data.subscribe( function () {
		var store = editor();
		var next = {
			saving: !! store.isSavingPost(),
			autosaving: !! store.isAutosavingPost(),
			succeeded: !! store.didPostSaveRequestSucceed(),
		};

		if ( panel ) {
			panel.setStale( !! store.isEditedPostDirty() );

			if ( geometry.savedJustNow( previous, next ) ) {
				panel.refresh();
			}
		}

		previous = next;
	} );

	var MenuItem = ( wp.editor && wp.editor.PluginMoreMenuItem ) || ( wp.editPost && wp.editPost.PluginMoreMenuItem );

	if ( ! MenuItem ) {
		return;
	}

	wp.plugins.registerPlugin( 'books-preview', {
		render: function () {
			return el(
				MenuItem,
				{
					icon: 'media-document',
					onClick: function () {
						ensurePanel().toggle();
					},
				},
				__( 'Print preview', 'otherguise' )
			);
		},
	} );
}( window, window.wp, window.booksPreview ) );
