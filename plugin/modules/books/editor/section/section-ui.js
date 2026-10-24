/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 224: the sidebar view of the "Book section" panel, in plain DOM (no React, no build step) so that it can be tested in jsdom.
 * section.js connects it to the editor. Exposed as window.booksSectionUI.mount, and as a CommonJS module for the tests.
 *
 * For a plain post: the sections that contain it, with a search among the sections only to add it to one, and the switch "Use this post
 * as a section". For a section it shows a summary only (how many posts, the range of their dates) and the link that opens the floating
 * window, where the contents are (section-window.js).
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksSectionUI = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	var SEARCH_DELAY = 250;

	/**
	 * Mounts the sidebar view in a container.
	 *
	 * options: document, container, model (createModel), windowControl ({ toggle, isOpen, subscribe }), __ (translation),
	 * setTimeout / clearTimeout (optional).
	 */
	function mount( options ) {
		var doc = options.document;
		var model = options.model;
		var control = options.windowControl;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var later = options.setTimeout || ( typeof setTimeout === 'function' ? setTimeout : null );
		var cancel = options.clearTimeout || ( typeof clearTimeout === 'function' ? clearTimeout : function () {} );
		var timer = null;

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

		var rootNode = make( 'div', 'books-section' );

		// At the top of the panel, for a section only: the link that opens the window.
		var windowLink = make( 'button', 'books-section__window-link components-button is-link' );
		windowLink.type = 'button';
		windowLink.addEventListener( 'click', function () {
			control.toggle();
		} );

		var error = make( 'div', 'books-section__error' );
		error.setAttribute( 'role', 'alert' );
		error.hidden = true;

		var inBox = make( 'div', 'books-section__in' );
		var containingList = make( 'ul', 'books-section__containing' );
		var find = make( 'input', 'books-section__find' );
		find.type = 'search';
		find.placeholder = __( 'Add to a section…' );
		var found = make( 'ul', 'books-section__found' );
		inBox.appendChild( make( 'h3', 'books-section__title', __( 'In sections' ) ) );
		inBox.appendChild( containingList );
		inBox.appendChild( find );
		inBox.appendChild( found );

		var switchLabel = make( 'label', 'books-section__switch' );
		var check = make( 'input', 'books-section__check' );
		check.type = 'checkbox';
		switchLabel.appendChild( check );
		switchLabel.appendChild( doc.createTextNode( ' ' + __( 'Use this post as a section' ) ) );

		var summary = make( 'p', 'books-section__summary' );

		rootNode.appendChild( windowLink );
		rootNode.appendChild( error );
		rootNode.appendChild( inBox );
		rootNode.appendChild( switchLabel );
		rootNode.appendChild( summary );
		options.container.appendChild( rootNode );

		function link( info ) {
			var node = info.edit ? make( 'a', 'books-section__link', info.title ) : make( 'span', 'books-section__link', info.title );
			if ( info.edit ) {
				node.href = info.edit;
			}
			return node;
		}

		function render() {
			var state = model.getState();
			var message = model.getError();

			error.textContent = message;
			error.hidden = '' === message;

			windowLink.hidden = ! state.section;
			windowLink.textContent = control.isOpen() ? __( 'Close the floating window' ) : __( 'Open in a floating window' );

			containingList.textContent = '';
			state.containing.forEach( function ( section ) {
				var item = make( 'li', 'books-section__containing-item' );
				item.appendChild( link( section ) );
				if ( section.can_edit ) {
					item.appendChild( button( __( 'Remove' ), 'books-section__remove-from', function () {
						model.leave( section.id );
					} ) );
				}
				containingList.appendChild( item );
			} );

			check.checked = state.section;
			summary.hidden = ! state.section;

			var count = state.contents.length;
			var range = state.range && state.range.from ? ' · ' + state.range.from.slice( 0, 10 ) + ( state.range.to.slice( 0, 10 ) !== state.range.from.slice( 0, 10 ) ? ' → ' + state.range.to.slice( 0, 10 ) : '' ) : '';
			summary.textContent = __( 'Contains' ) + ' (' + count + ')' + range;
		}

		// Adding this post to a section: the search among the sections only.
		function searchSections() {
			model.search( find.value ).then( function ( items ) {
				var here = {};
				model.getState().containing.forEach( function ( section ) {
					here[ section.id ] = true;
				} );
				found.textContent = '';
				items.filter( function ( section ) {
					return section.id !== model.postId && ! here[ section.id ];
				} ).forEach( function ( section ) {
					var item = make( 'li', 'books-section__found-item' );
					item.appendChild( button( section.title, 'books-section__choose', function () {
						find.value = '';
						found.textContent = '';
						model.join( section.id );
					} ) );
					found.appendChild( item );
				} );
				if ( ! found.firstChild ) {
					found.appendChild( make( 'li', 'books-section__none', __( 'No section found.' ) ) );
				}
			} );
		}

		find.addEventListener( 'input', function () {
			if ( later ) {
				cancel( timer );
				timer = later( searchSections, SEARCH_DELAY );
			} else {
				searchSections();
			}
		} );
		find.addEventListener( 'focus', function () {
			if ( '' === find.value ) {
				searchSections();
			}
		} );

		check.addEventListener( 'change', function () {
			var wanted = check.checked;
			model.mark( wanted ).then( function ( worked ) {
				if ( ! worked ) {
					check.checked = ! wanted;
				}
			} );
		} );

		var unsubscribe = model.subscribe( function ( what ) {
			if ( 'thumbs' !== what ) {
				render();
			}
		} );
		var unwatch = control.subscribe ? control.subscribe( render ) : null;

		render();
		model.load();

		return {
			refresh: function () {
				return model.load();
			},
			destroy: function () {
				cancel( timer );
				unsubscribe();
				if ( unwatch ) {
					unwatch();
				}
				if ( rootNode.parentNode ) {
					rootNode.parentNode.removeChild( rootNode );
				}
			},
			node: rootNode,
		};
	}

	return { mount: mount };
} ) );
