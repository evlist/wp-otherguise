/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 219: connects the floating print preview panel (panel.js) to the post editor: a button of the toolbar of the editor (next to the
 * pinned plugin sidebars such as Media Helper's) and a menu item open it, and the state of the post
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
	var watchers = [];

	// The button shows whether the panel is open: it is told when the panel opens or closes, from the button, the menu or the panel.
	function changed() {
		watchers.slice().forEach( function ( watcher ) {
			watcher();
		} );
	}

	function toggle() {
		ensurePanel().toggle();
		changed();
	}

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

	// The routes of the PDF preview (slice 220), called with the REST cookie authentication of the editor.
	function pdfOptions() {
		if ( ! settings.pdf || ! settings.pdf.enabled || ! wp.apiFetch ) {
			return null;
		}

		var route = function ( suffix, mode, project ) {
			var query = [];

			if ( mode ) {
				query.push( 'mode=' + encodeURIComponent( mode ) );
			}

			if ( project > 0 ) {
				query.push( 'project=' + encodeURIComponent( project ) );
			}

			return settings.pdf.path + '/' + editor().getCurrentPostId() + suffix + ( query.length ? '?' + query.join( '&' ) : '' );
		};

		return {
			enabled: true,
			api: {
				start: function ( mode, refresh, project ) {
					return wp.apiFetch( { path: route( '' ), method: 'POST', data: { mode: mode, refresh: !! refresh, project: project || 0 } } );
				},
				status: function ( mode, project ) {
					return wp.apiFetch( { path: route( '', mode, project ) } );
				},
				file: function ( mode, project ) {
					return wp.apiFetch( { path: route( '/file', mode, project ) } );
				},
			},
		};
	}

	function ensurePanel() {
		if ( ! panel ) {
			panel = win.booksPreviewPanel.createPanel( {
				pdf: pdfOptions(),
				document: win.document,
				geometry: geometry,
				settings: settings,
				getPost: getPost,
				__: __,
				storage: storage(),
				onClose: changed,
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
	// The area of the pinned items of the toolbar, where the plugin sidebars (Media Helper's) put their icon, is the slot
	// `PinnedItems/core`: what `PinnedItems` of `@wordpress/interface` fills, a package that has no global of its own.
	var Fill = wp.components && wp.components.Fill;
	var Button = wp.components && wp.components.Button;

	if ( ! MenuItem && ! ( Fill && Button ) ) {
		return;
	}

	// The button of the toolbar, when the editor offers the slot.
	function ToolbarButton() {
		var state = wp.element.useState( !! ( panel && panel.isOpen() ) );

		wp.element.useEffect( function () {
			var watcher = function () {
				state[ 1 ]( !! ( panel && panel.isOpen() ) );
			};
			watchers.push( watcher );

			return function () {
				watchers = watchers.filter( function ( other ) {
					return other !== watcher;
				} );
			};
		}, [] );

		return el(
			Fill,
			{ name: 'PinnedItems/core' },
			el( Button, {
				icon: 'media-document',
				label: __( 'Print preview', 'otherguise' ),
				isPressed: state[ 0 ],
				onClick: toggle,
			} )
		);
	}

	wp.plugins.registerPlugin( 'books-preview', {
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				Fill && Button ? el( ToolbarButton ) : null,
				MenuItem ? el( MenuItem, { icon: 'media-document', onClick: toggle }, __( 'Print preview', 'otherguise' ) ) : null
			);
		},
	} );
}( window, window.wp, window.booksPreview ) );
