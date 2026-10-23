/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 223: the "Section" panel of the post editor, in plain DOM (no React, no build step) so that it can be tested in jsdom.
 * section.js connects it to the editor. Exposed as window.booksSectionUI.mount, and as a CommonJS module for the tests.
 *
 * It shows the sections that contain the post (with a search among the sections only to add the post to one), a switch "Use this post as
 * a section" and, for a section, its contents in order with a dialog to add posts (search, dates, statuses, selection).
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
	var ALL_CAP = 200;
	var STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/**
	 * Mounts the panel in a container.
	 *
	 * options: document, container, postId, api ({ state, mark, search, add, remove, move, reset, candidates }, each returning a promise),
	 * __ (translation), setTimeout / clearTimeout (optional).
	 */
	function mount( options ) {
		var doc = options.document;
		var api = options.api;
		var postId = options.postId;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var later = options.setTimeout || ( typeof setTimeout === 'function' ? setTimeout : null );
		var cancel = options.clearTimeout || ( typeof clearTimeout === 'function' ? clearTimeout : function () {} );
		var state = { section: false, contents: [], containing: [] };
		var timer = null;
		var dialog = { page: 1, total: 0, items: [], selected: {}, busy: false };

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

		var own = make( 'div', 'books-section__own' );
		var contentsTitle = make( 'h3', 'books-section__title' );
		var contentsList = make( 'ol', 'books-section__contents' );
		var resetButton = button( __( 'Reset the order' ), 'books-section__reset', function () {
			run( api.reset( postId ) );
		} );
		var addButton = button( __( 'Add posts…' ), 'books-section__add', function () {
			openDialog();
		} );
		own.appendChild( contentsTitle );
		own.appendChild( contentsList );
		own.appendChild( resetButton );
		own.appendChild( addButton );

		var overlay = make( 'div', 'books-section__dialog' );
		overlay.hidden = true;
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );

		rootNode.appendChild( error );
		rootNode.appendChild( inBox );
		rootNode.appendChild( switchLabel );
		rootNode.appendChild( own );
		rootNode.appendChild( overlay );
		options.container.appendChild( rootNode );

		function showError( failure ) {
			var message = failure && failure.message ? failure.message : __( 'Something went wrong.' );
			error.textContent = message;
			error.hidden = false;
		}

		function clearError() {
			error.textContent = '';
			error.hidden = true;
		}

		/** Runs a request, then shows the state it answers (or the error). Returns the promise of the answer. */
		function run( promise ) {
			clearError();
			return promise.then(
				function ( answer ) {
					apply( answer );
					return answer;
				},
				function ( failure ) {
					showError( failure );
					render();
				}
			);
		}

		function apply( answer ) {
			if ( answer && typeof answer === 'object' && Array.isArray( answer.contents ) ) {
				state = { section: !! answer.section, contents: answer.contents, containing: answer.containing || [] };
			}
			render();
		}

		function link( info ) {
			var node = info.edit ? make( 'a', 'books-section__link', info.title ) : make( 'span', 'books-section__link', info.title );
			if ( info.edit ) {
				node.href = info.edit;
			}
			return node;
		}

		function render() {
			containingList.textContent = '';
			state.containing.forEach( function ( section ) {
				var item = make( 'li', 'books-section__containing-item' );
				item.appendChild( link( section ) );
				if ( section.can_edit ) {
					item.appendChild( button( __( 'Remove' ), 'books-section__remove-from', function () {
						// The answer is the state of the section; what this panel shows is the state of this post.
						run( api.remove( section.id, postId ).then( function () {
							return api.state( postId );
						} ) );
					} ) );
				}
				containingList.appendChild( item );
			} );

			check.checked = state.section;
			own.hidden = ! state.section;
			contentsTitle.textContent = __( 'Contains' ) + ' (' + state.contents.length + ')';
			contentsList.textContent = '';
			state.contents.forEach( function ( post, index ) {
				var item = make( 'li', 'books-section__item' );
				item.setAttribute( 'data-id', String( post.id ) );
				item.appendChild( link( post ) );
				item.appendChild( make( 'span', 'books-section__meta', ' ' + post.date.slice( 0, 10 ) + ' · ' + post.status + ( post.pinned ? ' · ' + __( 'moved' ) : '' ) ) );
				var up = button( __( 'Up' ), 'books-section__up', function () {
					run( api.move( postId, post.id, 'up' ) );
				} );
				up.disabled = 0 === index;
				var down = button( __( 'Down' ), 'books-section__down', function () {
					run( api.move( postId, post.id, 'down' ) );
				} );
				down.disabled = index === state.contents.length - 1;
				item.appendChild( up );
				item.appendChild( down );
				item.appendChild( button( __( 'Remove' ), 'books-section__remove', function () {
					run( api.remove( postId, post.id ) );
				} ) );
				contentsList.appendChild( item );
			} );
			resetButton.disabled = ! state.contents.some( function ( post ) {
				return post.pinned;
			} );
			if ( ! overlay.hidden ) {
				renderDialog();
			}
		}

		// Adding this post to a section: the search among the sections only.
		function searchSections() {
			var text = find.value;
			api.search( text ).then(
				function ( answer ) {
					found.textContent = '';
					var here = {};
					state.containing.forEach( function ( section ) {
						here[ section.id ] = true;
					} );
					( answer.items || [] ).filter( function ( section ) {
						return section.id !== postId && ! here[ section.id ];
					} ).forEach( function ( section ) {
						var item = make( 'li', 'books-section__found-item' );
						var choose = button( section.title, 'books-section__choose', function () {
							clearError();
							find.value = '';
							found.textContent = '';
							api.add( section.id, [ postId ] ).then(
								function ( result ) {
									var why = result && result.report && result.report.skipped ? result.report.skipped[ postId ] : null;
									if ( why ) {
										showError( { message: skipMessage( why ) } );
									}
									return api.state( postId ).then( apply );
								},
								showError
							);
						} );
						item.appendChild( choose );
						found.appendChild( item );
					} );
					if ( ! found.firstChild ) {
						found.appendChild( make( 'li', 'books-section__none', __( 'No section found.' ) ) );
					}
				},
				showError
			);
		}

		function skipMessage( why ) {
			var messages = {
				cycle: __( 'A section cannot contain itself or a section that contains it.' ),
				missing: __( 'This post does not exist.' ),
				type: __( 'This type of content cannot be put in a section.' ),
				forbidden: __( 'You are not allowed to read this post.' ),
			};
			return messages[ why ] || why;
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
			run( api.mark( postId, wanted ) ).then( function ( answer ) {
				if ( ! answer ) {
					check.checked = ! wanted;
				}
			} );
		} );

		// The dialog to add posts to this section.
		var fields = {};

		function buildDialog() {
			var box = make( 'div', 'books-section__box' );
			box.appendChild( make( 'h3', 'books-section__title', __( 'Add posts' ) ) );

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
				dialogSearch( true );
			} ) );
			box.appendChild( filters );

			fields.status = make( 'div', 'books-section__d-status-line' );
			fields.status.setAttribute( 'role', 'status' );
			fields.list = make( 'ul', 'books-section__d-results' );
			fields.more = button( __( 'More results' ), 'books-section__d-more', function () {
				dialogSearch( false );
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
			actions.appendChild( button( __( 'Close' ), 'books-section__d-close', closeDialog ) );
			box.appendChild( actions );
			overlay.appendChild( box );
		}

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

		function dialogSearch( fresh ) {
			if ( fresh ) {
				dialog.page = 0;
				dialog.items = [];
				dialog.selected = {};
			}
			dialog.page += 1;
			dialog.busy = true;
			api.candidates( postId, criteria( dialog.page ) ).then(
				function ( answer ) {
					dialog.busy = false;
					dialog.total = answer.total || 0;
					dialog.items = dialog.items.concat( answer.items || [] );
					renderDialog();
				},
				function ( failure ) {
					dialog.busy = false;
					showError( failure );
				}
			);
		}

		function renderDialog() {
			fields.list.textContent = '';
			dialog.items.forEach( function ( post ) {
				var item = make( 'li', 'books-section__d-item' );
				var label = make( 'label' );
				var input = make( 'input' );
				input.type = 'checkbox';
				input.value = String( post.id );
				input.disabled = !! post.in_section;
				input.checked = !! post.in_section || !! dialog.selected[ post.id ];
				input.addEventListener( 'change', function () {
					dialog.selected[ post.id ] = input.checked;
				} );
				label.appendChild( input );
				label.appendChild( doc.createTextNode( ' ' + post.date.slice( 0, 10 ) + ' · ' + post.title + ' (' + post.status + ')' + ( post.in_section ? ' — ' + __( 'already in' ) : '' ) ) );
				item.appendChild( label );
				fields.list.appendChild( item );
			} );
			fields.status.textContent = dialog.items.length + ' / ' + dialog.total;
			fields.more.hidden = dialog.items.length >= dialog.total;
		}

		function report( result ) {
			var data = result && result.report ? result.report : { added: [], already: [], skipped: {} };
			fields.status.textContent = data.added.length + ' ' + __( 'added' ) + ', ' + data.already.length + ' ' + __( 'already there' ) + ', ' + Object.keys( data.skipped ).length + ' ' + __( 'skipped' );
		}

		function addIds( ids ) {
			if ( ! ids.length ) {
				return Promise.resolve();
			}
			clearError();
			return api.add( postId, ids ).then(
				function ( result ) {
					apply( result );
					dialog.items.forEach( function ( post ) {
						if ( ids.indexOf( post.id ) !== -1 && ( result.report.added.indexOf( post.id ) !== -1 || result.report.already.indexOf( post.id ) !== -1 ) ) {
							post.in_section = true;
						}
					} );
					dialog.selected = {};
					renderDialog();
					report( result );
				},
				showError
			);
		}

		function addSelection() {
			var ids = dialog.items.filter( function ( post ) {
				return ! post.in_section && dialog.selected[ post.id ];
			} ).map( function ( post ) {
				return post.id;
			} );
			return addIds( ids );
		}

		// Every result, page after page, up to the cap of one request.
		function addAll() {
			var criteriaNow = criteria( 1 );
			var ids = [];

			function collect( page ) {
				criteriaNow.page = page;
				return api.candidates( postId, criteriaNow ).then( function ( answer ) {
					( answer.items || [] ).forEach( function ( post ) {
						if ( ! post.in_section && ids.length < ALL_CAP ) {
							ids.push( post.id );
						}
					} );
					if ( ( answer.items || [] ).length && ids.length < ALL_CAP && page * 50 < answer.total ) {
						return collect( page + 1 );
					}
				} );
			}

			return collect( 1 ).then( function () {
				return addIds( ids ).then( function () {
					return dialogSearch( true );
				} );
			}, showError );
		}

		function openDialog() {
			if ( ! fields.list ) {
				buildDialog();
			}
			overlay.hidden = false;
			dialogSearch( true );
		}

		function closeDialog() {
			overlay.hidden = true;
		}

		overlay.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeDialog();
			}
		} );

		render();
		run( api.state( postId ) );

		return {
			refresh: function () {
				return run( api.state( postId ) );
			},
			destroy: function () {
				cancel( timer );
				if ( rootNode.parentNode ) {
					rootNode.parentNode.removeChild( rootNode );
				}
			},
			node: rootNode,
		};
	}

	return { mount: mount };
} ) );
