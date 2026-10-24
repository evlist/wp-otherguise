/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 224: the state of the Book section panel and the calls of the API, shared by its two views (the sidebar and the floating window).
 * Exposed as window.booksSectionModel.createModel, and as a CommonJS module for the tests.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksSectionModel = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	/**
	 * Builds the model of the post being edited.
	 *
	 * options: postId, api ({ state, mark, search, add( id, ids, place ), remove, move, reset, candidates }, each returning a promise),
	 * __ (translation).
	 */
	function createModel( options ) {
		var api = options.api;
		var postId = options.postId;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var state = { id: postId, title: '', section: false, range: { from: '', to: '' }, contents: [], containing: [] };
		var error = '';
		var listeners = [];

		function notify() {
			listeners.slice().forEach( function ( listener ) {
				listener();
			} );
		}

		function fail( failure ) {
			error = failure && failure.message ? failure.message : __( 'Something went wrong.' );
			notify();
		}

		function apply( answer ) {
			if ( answer && typeof answer === 'object' && Array.isArray( answer.contents ) ) {
				state = {
					id: answer.id,
					title: answer.title || '',
					section: !! answer.section,
					range: answer.range || { from: '', to: '' },
					contents: answer.contents,
					containing: answer.containing || [],
				};
			}
			notify();
			return answer;
		}

		// Runs a request whose answer is the state of this post; a failure is kept as the error, and the promise answers undefined.
		function run( promise ) {
			error = '';
			return promise.then( apply, fail );
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

		return {
			postId: postId,
			getState: function () {
				return state;
			},
			getError: function () {
				return error;
			},
			clearError: function () {
				error = '';
				notify();
			},
			showError: fail,
			subscribe: function ( listener ) {
				listeners.push( listener );
				return function () {
					var at = listeners.indexOf( listener );
					if ( at !== -1 ) {
						listeners.splice( at, 1 );
					}
				};
			},
			skipMessage: skipMessage,
			load: function () {
				return run( api.state( postId ) );
			},
			// Marks or unmarks this post as a section; answers true when it worked.
			mark: function ( on ) {
				return run( api.mark( postId, on ) ).then( function ( answer ) {
					return !! answer;
				} );
			},
			// Searches among the sections (not a request on this post: a failure shows the error and answers an empty list).
			search: function ( text ) {
				return api.search( text ).then( function ( answer ) {
					return answer && answer.items ? answer.items : [];
				}, function ( failure ) {
					fail( failure );
					return [];
				} );
			},
			// Puts this post in another section, at the end (its date), then reads the state again.
			join: function ( sectionId ) {
				error = '';
				return api.add( sectionId, [ postId ] ).then( function ( result ) {
					var why = result && result.report && result.report.skipped ? result.report.skipped[ postId ] : null;
					// Reading the state again clears the error: the explanation is put back after it.
					return run( api.state( postId ) ).then( function ( answer ) {
						if ( why ) {
							error = skipMessage( why );
							notify();
						}
						return answer;
					} );
				}, fail );
			},
			// Takes this post out of another section, then reads the state again (the answer of the route is the state of that section).
			leave: function ( sectionId ) {
				error = '';
				return api.remove( sectionId, postId ).then( function () {
					return run( api.state( postId ) );
				}, fail );
			},
			// What this post holds.
			removeOwn: function ( post ) {
				return run( api.remove( postId, post ) );
			},
			move: function ( post, to ) {
				return run( api.move( postId, post, to ) );
			},
			reset: function () {
				return run( api.reset( postId ) );
			},
			// Adds posts at their date or at a place ({ before: id } or { after: id }); answers the report ({ added, already, skipped }).
			add: function ( ids, place ) {
				error = '';
				return api.add( postId, ids, place || {} ).then( function ( result ) {
					apply( result );
					return result.report;
				}, function ( failure ) {
					fail( failure );
					return null;
				} );
			},
			candidates: function ( params ) {
				return api.candidates( postId, params ).then( function ( answer ) {
					return answer;
				}, function ( failure ) {
					fail( failure );
					return null;
				} );
			},
		};
	}

	return { createModel: createModel };
} ) );
