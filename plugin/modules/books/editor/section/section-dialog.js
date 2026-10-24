/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 224: the dialog that adds posts to a section (search, dates, statuses, selection), in plain DOM. It is opened by the floating window
 * ("Add posts…" at the end, "add before" and "add after" on a line). Exposed as window.booksSectionDialog.createDialog, and as a CommonJS
 * module for the tests.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksSectionDialog = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	var ALL_CAP = 200;
	var PAGE = 50;
	var STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/**
	 * Builds the dialog (hidden until open() is called).
	 *
	 * options: document, model (createModel), __ (translation).
	 */
	function createDialog( options ) {
		var doc = options.document;
		var model = options.model;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var place = {};
		var found = { page: 0, total: 0, items: [], selected: {} };
		var fields = {};

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

		var overlay = make( 'div', 'books-section__dialog' );
		overlay.hidden = true;
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-label', __( 'Add posts' ) );

		var box = make( 'div', 'books-section__box' );
		var title = make( 'h3', 'books-section__title', __( 'Add posts' ) );
		box.appendChild( title );

		fields.search = make( 'input', 'books-section__d-search' );
		fields.search.type = 'search';
		fields.search.placeholder = __( 'Search…' );
		fields.after = make( 'input', 'books-section__d-after' );
		fields.after.type = 'date';
		fields.before = make( 'input', 'books-section__d-before' );
		fields.before.type = 'date';
		var filters = make( 'div', 'books-section__filters' );
		filters.appendChild( fields.search );
		filters.appendChild( make( 'label', '', __( 'From' ) ) ).appendChild( fields.after );
		filters.appendChild( make( 'label', '', __( 'To' ) ) ).appendChild( fields.before );

		fields.statuses = {};
		STATUSES.forEach( function ( status ) {
			var label = make( 'label', 'books-section__d-status' );
			var input = make( 'input' );
			input.type = 'checkbox';
			input.value = status;
			input.checked = 'publish' === status;
			fields.statuses[ status ] = input;
			label.appendChild( input );
			label.appendChild( doc.createTextNode( ' ' + status ) );
			filters.appendChild( label );
		} );
		filters.appendChild( button( __( 'Search' ), 'books-section__d-go', function () {
			search( true );
		} ) );
		box.appendChild( filters );

		fields.status = make( 'div', 'books-section__d-status-line' );
		fields.status.setAttribute( 'role', 'status' );
		fields.list = make( 'ul', 'books-section__d-results' );
		fields.more = button( __( 'More results' ), 'books-section__d-more', function () {
			search( false );
		} );
		box.appendChild( fields.status );
		box.appendChild( fields.list );
		box.appendChild( fields.more );

		var actions = make( 'div', 'books-section__d-actions' );
		actions.appendChild( button( __( 'Add the selection' ), 'books-section__d-add', function () {
			addSelection();
		} ) );
		actions.appendChild( button( __( 'Add all the results' ), 'books-section__d-add-all', function () {
			addAll();
		} ) );
		actions.appendChild( button( __( 'Close' ), 'books-section__d-close', close ) );
		box.appendChild( actions );
		overlay.appendChild( box );

		function criteria( page ) {
			return {
				search: fields.search.value,
				after: fields.after.value,
				before: fields.before.value,
				statuses: STATUSES.filter( function ( status ) {
					return fields.statuses[ status ].checked;
				} ),
				page: page,
			};
		}

		function search( fresh ) {
			if ( fresh ) {
				found = { page: 0, total: 0, items: [], selected: {} };
			}
			found.page += 1;
			return model.candidates( criteria( found.page ) ).then( function ( answer ) {
				if ( answer ) {
					found.total = answer.total || 0;
					found.items = found.items.concat( answer.items || [] );
					render();
				}
			} );
		}

		function render() {
			fields.list.textContent = '';
			found.items.forEach( function ( post ) {
				var item = make( 'li', 'books-section__d-item' );
				var label = make( 'label' );
				var input = make( 'input' );
				input.type = 'checkbox';
				input.value = String( post.id );
				input.disabled = !! post.in_section;
				input.checked = !! post.in_section || !! found.selected[ post.id ];
				input.addEventListener( 'change', function () {
					found.selected[ post.id ] = input.checked;
				} );
				label.appendChild( input );
				label.appendChild( doc.createTextNode( ' ' + post.date.slice( 0, 10 ) + ' · ' + post.title + ' (' + post.status + ')' + ( post.in_section ? ' — ' + __( 'already in' ) : '' ) ) );
				item.appendChild( label );
				fields.list.appendChild( item );
			} );
			fields.status.textContent = found.items.length + ' / ' + found.total;
			fields.more.hidden = found.items.length >= found.total;
		}

		function report( data ) {
			var counts = data || { added: [], already: [], skipped: {} };
			fields.status.textContent = counts.added.length + ' ' + __( 'added' ) + ', ' + counts.already.length + ' ' + __( 'already there' ) + ', ' + Object.keys( counts.skipped ).length + ' ' + __( 'skipped' );
		}

		function addIds( ids ) {
			if ( ! ids.length ) {
				return Promise.resolve();
			}
			return model.add( ids, place ).then( function ( data ) {
				if ( ! data ) {
					return;
				}
				found.items.forEach( function ( post ) {
					if ( ids.indexOf( post.id ) !== -1 && ( data.added.indexOf( post.id ) !== -1 || data.already.indexOf( post.id ) !== -1 ) ) {
						post.in_section = true;
					}
				} );
				found.selected = {};
				render();
				report( data );
			} );
		}

		function addSelection() {
			return addIds( found.items.filter( function ( post ) {
				return ! post.in_section && found.selected[ post.id ];
			} ).map( function ( post ) {
				return post.id;
			} ) );
		}

		// Every result, page after page, up to the cap of one request.
		function addAll() {
			var ids = [];

			function collect( page ) {
				var wanted = criteria( page );
				return model.candidates( wanted ).then( function ( answer ) {
					if ( ! answer ) {
						return;
					}
					( answer.items || [] ).forEach( function ( post ) {
						if ( ! post.in_section && ids.length < ALL_CAP ) {
							ids.push( post.id );
						}
					} );
					if ( ( answer.items || [] ).length && ids.length < ALL_CAP && page * PAGE < answer.total ) {
						return collect( page + 1 );
					}
				} );
			}

			return collect( 1 ).then( function () {
				return addIds( ids ).then( function () {
					return search( true );
				} );
			} );
		}

		function open( where, label ) {
			place = where || {};
			title.textContent = __( 'Add posts' ) + ( label ? ' ' + label : '' );
			overlay.hidden = false;
			if ( ! overlay.parentNode ) {
				doc.body.appendChild( overlay );
			}
			return search( true );
		}

		function close() {
			overlay.hidden = true;
		}

		overlay.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
				close();
			}
		} );

		return {
			open: open,
			close: close,
			isOpen: function () {
				return ! overlay.hidden;
			},
			node: overlay,
		};
	}

	return { createDialog: createDialog };
} ) );
