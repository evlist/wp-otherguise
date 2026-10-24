/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 224: the floating window of the "Book section" panel, in plain DOM so that it can be tested in jsdom. It shows the contents of
 * the section, one line per post: the featured image behind the text (lightened), arrows to move the post, icons to add posts before
 * and after it, to remove it (with a confirmation in the line), to view it and to edit it. It is moved by its title bar, resized by its
 * corner, and the browser remembers its place. Exposed as window.booksSectionWindow.createWindow, and as a CommonJS module for the tests.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksSectionWindow = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	var STORAGE_KEY = 'booksSectionWindow';
	var MIN_WIDTH = 280;
	var MIN_HEIGHT = 220;
	var NARROW = 600;

	/**
	 * Builds the window (hidden until it is opened).
	 *
	 * options: document, model (createModel), dialog (createDialog), __ (translation), storage (getItem/setItem, optional),
	 * viewport (returns { width, height }), ResizeObserver (optional).
	 */
	function createWindow( options ) {
		var doc = options.document;
		var model = options.model;
		var dialog = options.dialog;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var storage = options.storage || null;
		var viewport = options.viewport || function () {
			return { width: 1280, height: 800 };
		};
		var listeners = [];
		var isOpen = false;
		// The posts for which "Remove from the section?" is being asked, by id.
		var asking = {};

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

		// An icon button: a Dashicon, the label as title and aria-label.
		function icon( glyph, label, className, onClick ) {
			var node = make( 'button', 'books-section-icon ' + className );
			var picture = make( 'span', 'dashicons dashicons-' + glyph );
			node.type = 'button';
			node.title = label;
			node.setAttribute( 'aria-label', label );
			picture.setAttribute( 'aria-hidden', 'true' );
			node.appendChild( picture );
			node.addEventListener( 'click', onClick );
			return node;
		}

		// An icon that is a link opening a page in a new tab.
		function iconLink( glyph, label, className, href ) {
			var node = make( 'a', 'books-section-icon ' + className );
			var picture = make( 'span', 'dashicons dashicons-' + glyph );
			node.href = href;
			node.target = '_blank';
			node.rel = 'noopener';
			node.title = label;
			node.setAttribute( 'aria-label', label );
			picture.setAttribute( 'aria-hidden', 'true' );
			node.appendChild( picture );
			return node;
		}

		function text( label, className, onClick ) {
			var node = make( 'button', className + ' components-button is-secondary is-small', label );
			node.type = 'button';
			node.addEventListener( 'click', onClick );
			return node;
		}

		var windowNode = make( 'div', 'books-section-window' );
		windowNode.hidden = true;
		windowNode.setAttribute( 'role', 'dialog' );
		windowNode.setAttribute( 'aria-label', __( 'Book section' ) );
		windowNode.tabIndex = -1;

		var bar = make( 'div', 'books-section-window__bar' );
		var heading = make( 'strong', 'books-section-window__title' );
		var closeButton = make( 'button', 'books-section-window__close' );
		closeButton.type = 'button';
		closeButton.title = __( 'Close the window' );
		closeButton.setAttribute( 'aria-label', __( 'Close the window' ) );
		closeButton.appendChild( make( 'span', 'dashicons dashicons-no-alt' ) );
		bar.appendChild( heading );
		bar.appendChild( closeButton );

		var body = make( 'div', 'books-section-window__body' );
		var error = make( 'div', 'books-section__error' );
		error.setAttribute( 'role', 'alert' );
		error.hidden = true;
		var list = make( 'ol', 'books-section-window__list' );
		var empty = make( 'p', 'books-section-window__empty', __( 'This section is empty.' ) );
		var footer = make( 'div', 'books-section-window__footer' );
		var addEnd = text( __( 'Add posts…' ), 'books-section__add', function () {
			dialog.open( {}, '' );
		} );
		var reset = text( __( 'Reset the order' ), 'books-section__reset', function () {
			model.reset();
		} );
		footer.appendChild( addEnd );
		footer.appendChild( reset );
		body.appendChild( error );
		body.appendChild( list );
		body.appendChild( empty );
		body.appendChild( footer );
		windowNode.appendChild( bar );
		windowNode.appendChild( body );

		function notify() {
			listeners.slice().forEach( function ( listener ) {
				listener();
			} );
		}

		// ---- Place and size ----

		function readBox() {
			var size = viewport();
			var fallback = { x: Math.max( 8, size.width - 460 ), y: 110, w: 420, h: Math.min( 640, Math.max( 320, size.height - 180 ) ) };
			try {
				var box = JSON.parse( storage ? storage.getItem( STORAGE_KEY ) || 'null' : 'null' );
				return box && [ 'x', 'y', 'w', 'h' ].every( function ( key ) {
					return 'number' === typeof box[ key ];
				} ) ? box : fallback;
			} catch ( e ) {
				return fallback;
			}
		}

		function keepInView( box ) {
			var size = viewport();
			var w = Math.min( Math.max( MIN_WIDTH, box.w ), size.width - 8 );
			var h = Math.min( Math.max( MIN_HEIGHT, box.h ), size.height - 8 );

			return {
				w: w,
				h: h,
				x: Math.min( Math.max( 0, box.x ), size.width - Math.min( w, 120 ) ),
				y: Math.min( Math.max( 32, box.y ), size.height - 40 ),
			};
		}

		var box = null;

		function place( next ) {
			box = next;
			windowNode.style.left = box.x + 'px';
			windowNode.style.top = box.y + 'px';
			windowNode.style.width = box.w + 'px';
			windowNode.style.height = box.h + 'px';
		}

		function remember() {
			try {
				if ( storage ) {
					storage.setItem( STORAGE_KEY, JSON.stringify( box ) );
				}
			} catch ( e ) {
				// The place is just not remembered.
			}
		}

		function layout() {
			var narrow = viewport().width < NARROW;

			windowNode.classList.toggle( 'is-full', narrow );
			if ( ! narrow ) {
				place( keepInView( readBox() ) );
			}
		}

		// Moved by the title bar (a pointer, so a finger too).
		bar.addEventListener( 'pointerdown', function ( event ) {
			if ( event.target.closest( 'button' ) || windowNode.classList.contains( 'is-full' ) ) {
				return;
			}
			event.preventDefault();
			var startX = event.clientX;
			var startY = event.clientY;
			var origin = box;

			function move( moveEvent ) {
				place( keepInView( { x: origin.x + moveEvent.clientX - startX, y: origin.y + moveEvent.clientY - startY, w: origin.w, h: origin.h } ) );
			}

			function stop( stopEvent ) {
				doc.removeEventListener( 'pointermove', move );
				doc.removeEventListener( 'pointerup', stop );
				place( keepInView( { x: origin.x + stopEvent.clientX - startX, y: origin.y + stopEvent.clientY - startY, w: origin.w, h: origin.h } ) );
				remember();
			}

			doc.addEventListener( 'pointermove', move );
			doc.addEventListener( 'pointerup', stop );
		} );

		// Resized by its corner (CSS `resize`): the size is read back and remembered.
		var Observer = options.ResizeObserver || ( typeof ResizeObserver === 'function' ? ResizeObserver : null );
		var observer = null;

		if ( Observer ) {
			observer = new Observer( function () {
				if ( isOpen && windowNode.offsetWidth > 0 && box && ! windowNode.classList.contains( 'is-full' ) ) {
					var w = windowNode.offsetWidth;
					var h = windowNode.offsetHeight;

					if ( w !== box.w || h !== box.h ) {
						box = { x: box.x, y: box.y, w: w, h: h };
						remember();
					}
				}
			} );
		}

		// ---- The lines ----

		function dateOf( post ) {
			return post.date.slice( 0, 10 );
		}

		// The featured image behind the text of a line, when it is known and the line has none yet.
		function background( item, url ) {
			if ( ! url || item.querySelector( '.books-section-row__bg' ) ) {
				return;
			}

			var picture = make( 'img', 'books-section-row__bg' );

			picture.src = url;
			picture.alt = '';
			picture.setAttribute( 'loading', 'lazy' );
			picture.setAttribute( 'decoding', 'async' );
			item.insertBefore( picture, item.firstChild );
		}

		// An image arrived: it is put in its line without drawing the list again (the scroll stays where it is).
		function paint() {
			Array.prototype.forEach.call( list.children, function ( item ) {
				background( item, model.getThumb( Number( item.getAttribute( 'data-id' ) ) ) );
			} );
		}

		function line( post, index, count ) {
			var item = make( 'li', 'books-section-row' );
			item.setAttribute( 'data-id', String( post.id ) );

			background( item, model.getThumb( post.id ) );

			var top = make( 'div', 'books-section-row__edge books-section-row__top' );
			var up = icon( 'arrow-up-alt2', __( 'Move up' ), 'books-section-row__up', function () {
				model.move( post.id, 'up' );
			} );
			up.disabled = 0 === index;
			top.appendChild( up );
			top.appendChild( icon( 'insert-before', __( 'Add posts before' ), 'books-section-row__before', function () {
				dialog.open( { before: post.id }, __( 'before' ) + ' “' + post.title + '”' );
			} ) );

			var middle = make( 'div', 'books-section-row__middle' );
			var label = make( 'div', 'books-section-row__label' );
			label.appendChild( make( 'strong', 'books-section-row__name', post.title ) );
			label.appendChild( make( 'span', 'books-section-row__meta', dateOf( post ) + ' · ' + post.status + ( post.pinned ? ' · ' + __( 'moved' ) : '' ) ) );
			middle.appendChild( label );

			var tools = make( 'div', 'books-section-row__tools' );

			if ( asking[ post.id ] ) {
				tools.appendChild( make( 'span', 'books-section-row__ask', __( 'Remove from the section?' ) ) );
				tools.appendChild( text( __( 'Yes' ), 'books-section-row__yes', function () {
					delete asking[ post.id ];
					model.removeOwn( post.id );
				} ) );
				tools.appendChild( text( __( 'No' ), 'books-section-row__no', function () {
					delete asking[ post.id ];
					render();
				} ) );
			} else {
				tools.appendChild( icon( 'trash', __( 'Remove from the section' ), 'books-section-row__remove', function () {
					asking[ post.id ] = true;
					render();
				} ) );

				if ( post.view ) {
					tools.appendChild( iconLink( 'visibility', __( 'View' ), 'books-section-row__view', post.view ) );
				}

				if ( post.edit ) {
					tools.appendChild( iconLink( 'edit', __( 'Edit' ), 'books-section-row__edit', post.edit ) );
				}
			}
			middle.appendChild( tools );

			var bottom = make( 'div', 'books-section-row__edge books-section-row__bottom' );
			var down = icon( 'arrow-down-alt2', __( 'Move down' ), 'books-section-row__down', function () {
				model.move( post.id, 'down' );
			} );
			down.disabled = index === count - 1;
			bottom.appendChild( down );
			bottom.appendChild( icon( 'insert-after', __( 'Add posts after' ), 'books-section-row__after', function () {
				dialog.open( { after: post.id }, __( 'after' ) + ' “' + post.title + '”' );
			} ) );

			item.appendChild( top );
			item.appendChild( middle );
			item.appendChild( bottom );
			return item;
		}

		function render() {
			var state = model.getState();
			var message = model.getError();

			heading.textContent = __( 'Book section' ) + ( state.title ? ' — ' + state.title : '' );
			error.textContent = message;
			error.hidden = '' === message;

			// A question "Remove?" belongs to a line: it goes when the post is no longer in the list.
			var present = {};
			state.contents.forEach( function ( post ) {
				present[ post.id ] = true;
			} );
			Object.keys( asking ).forEach( function ( id ) {
				if ( ! present[ id ] ) {
					delete asking[ id ];
				}
			} );

			list.textContent = '';
			state.contents.forEach( function ( post, index ) {
				list.appendChild( line( post, index, state.contents.length ) );
			} );

			empty.hidden = state.contents.length > 0;
			model.wantThumbs( state.contents.map( function ( post ) {
				return post.id;
			} ) );
			reset.disabled = ! state.contents.some( function ( post ) {
				return post.pinned;
			} );
		}

		var unsubscribe = model.subscribe( function ( what ) {
			if ( ! isOpen ) {
				return;
			}
			if ( 'thumbs' === what ) {
				paint();
			} else {
				render();
			}
		} );

		closeButton.addEventListener( 'click', function () {
			close();
		} );

		windowNode.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key || dialog.isOpen() ) {
				return;
			}
			if ( Object.keys( asking ).length > 0 ) {
				asking = {};
				render();
				return;
			}
			close();
		} );

		function open() {
			if ( ! windowNode.parentNode ) {
				doc.body.appendChild( windowNode );
				if ( observer ) {
					observer.observe( windowNode );
				}
			}
			layout();
			windowNode.hidden = false;
			isOpen = true;
			render();
			windowNode.focus();
			notify();
		}

		function close() {
			if ( ! isOpen ) {
				return;
			}
			dialog.close();
			windowNode.hidden = true;
			isOpen = false;
			notify();
		}

		return {
			open: open,
			close: close,
			toggle: function () {
				if ( isOpen ) {
					close();
				} else {
					open();
				}
			},
			isOpen: function () {
				return isOpen;
			},
			subscribe: function ( listener ) {
				listeners.push( listener );
				return function () {
					var at = listeners.indexOf( listener );
					if ( at !== -1 ) {
						listeners.splice( at, 1 );
					}
				};
			},
			destroy: function () {
				unsubscribe();
				if ( observer ) {
					observer.disconnect();
				}
				if ( windowNode.parentNode ) {
					windowNode.parentNode.removeChild( windowNode );
				}
			},
			node: windowNode,
		};
	}

	return { createWindow: createWindow, STORAGE_KEY: STORAGE_KEY };
} ) );
