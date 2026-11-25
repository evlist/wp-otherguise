// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 633: "Change topic", the quick edit of the table of the assignments. A link in the actions of each line opens a panel under the line with the topics
// that are close to the motif and a field that searches the others; "Update" assigns the motif by hand through the REST API and changes the
// line in place, so that the search, the view and the page of the table stay as they were. Without this script the bulk actions do the same.
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.otherguiseQuickEdit = factory();
		if ( root.document && root.otherguiseTopics && root.otherguiseTopics.edit ) {
			root.document.addEventListener( 'DOMContentLoaded', function () {
				const config = root.otherguiseTopics;
				const call = function ( path, method, body ) {
					return root
						.fetch( config.root + config.edit + path, {
							method: method,
							headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
							body: body ? JSON.stringify( body ) : undefined,
						} )
						.then( function ( response ) {
							return response.json();
						} );
				};
				const search = function ( text ) {
					return root
						.fetch( config.root + config.path + '?search=' + encodeURIComponent( text ), { headers: { 'X-WP-Nonce': config.nonce } } )
						.then( function ( response ) {
							return response.json();
						} );
				};
				root.otherguiseQuickEdit.attach( { document: root.document, words: config.words || {}, api: { call: call, search: search }, picker: root.otherguiseTopicPicker, topics: config } );
			} );
		}
	}
}( typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	/**
	 * Adds "Change topic" to the lines of the table.
	 *
	 * @param {Object} options `document`, `words` (the texts), `api.call( path, method, body )` (a promise of the answer), `picker` (the field that searches the topics)
	 *                         and `topics` (the settings of that field).
	 * @return {Object} `open( row )`, to open the panel of a line.
	 */
	function attach( options ) {
		const doc = options.document;
		const words = options.words;
		let panel = null;

		function element( tag, text, className ) {
			const node = doc.createElement( tag );

			if ( text ) {
				node.textContent = text;
			}
			if ( className ) {
				node.className = className;
			}
			return node;
		}

		function close() {
			if ( panel && panel.parentNode ) {
				panel.parentNode.removeChild( panel );
			}
			panel = null;
		}

		function topicText( topic ) {
			const detail = [ topic.kind, topic.chain ].filter( Boolean ).join( ', ' );

			return topic.label + ( detail ? ' (' + detail + ')' : '' ) + ' #' + topic.id;
		}

		function update( row, answer ) {
			const cell = function ( name ) {
				return row.querySelector( '.column-' + name );
			};

			if ( cell( 'topic' ) ) {
				const where = cell( 'topic' );
				const actions = where.querySelector( '.row-actions' );
				const detail = [ answer.topic.kind, answer.topic.chain ].filter( Boolean ).join( ', ' );
				const link = element( 'a', answer.topic.label );

				// What the server wrote is replaced, except the actions of the line.
				Array.from( where.childNodes ).forEach( function ( node ) {
					if ( node !== actions ) {
						where.removeChild( node );
					}
				} );
				if ( answer.topic.url ) {
					link.href = answer.topic.url;
				}
				where.insertBefore( answer.topic.url ? link : doc.createTextNode( answer.topic.label ), actions );
				where.insertBefore( doc.createTextNode( ' ' ), actions );
				where.insertBefore( element( 'small', detail ), actions );
			}
			if ( cell( 'by' ) ) {
				cell( 'by' ).textContent = answer.by;
			}
			if ( cell( 'state' ) ) {
				cell( 'state' ).textContent = answer.state;
			}
		}

		function open( row ) {
			const id = row.querySelector( 'input[name="designation[]"]' ).value;
			const columns = row.children.length;
			const name = element( 'tr', '', 'inline-edit-row otherguise-quick-edit' );
			const cell = element( 'td' );
			const message = element( 'p', '', 'otherguise-quick-edit__message' );
			const select = element( 'select' );
			const typed = element( 'input' );
			const chosen = element( 'input' );
			const buttons = element( 'p', '', 'submit' );
			const save = element( 'button', words.update, 'button button-primary' );
			const cancel = element( 'button', words.cancel, 'button' );
			let force = false;

			close();
			cell.setAttribute( 'colspan', String( columns ) );
			select.appendChild( Object.assign( element( 'option', words.choose ), { value: '' } ) );
			typed.type = 'text';
			typed.size = 40;
			typed.placeholder = words.search;
			chosen.type = 'hidden';
			save.type = 'button';
			cancel.type = 'button';

			const label = element( 'label' );

			label.appendChild( element( 'strong', words.close ) );
			label.appendChild( doc.createElement( 'br' ) );
			label.appendChild( select );
			cell.appendChild( label );
			cell.appendChild( element( 'p' ) ).appendChild( typed );
			cell.appendChild( chosen );
			cell.appendChild( message );
			buttons.appendChild( save );
			buttons.appendChild( doc.createTextNode( ' ' ) );
			buttons.appendChild( cancel );
			cell.appendChild( buttons );
			name.appendChild( cell );
			row.parentNode.insertBefore( name, row.nextSibling );
			panel = name;

			if ( options.picker && options.topics ) {
				options.picker.mount( {
					document: doc,
					input: typed,
					hidden: chosen,
					api: {
						search: function ( text ) {
							return options.api.search ? options.api.search( text ) : Promise.resolve( [] );
						},
					},
					wait: 250,
				} );
			}

			options.api.call( id + '/neighbours', 'GET' ).then( function ( topics ) {
				( Array.isArray( topics ) ? topics : [] ).forEach( function ( topic ) {
					select.appendChild( Object.assign( element( 'option', topicText( topic ) ), { value: String( topic.id ) } ) );
				} );
			} );

			select.addEventListener( 'change', function () {
				if ( select.value ) {
					typed.value = '';
					chosen.value = '';
				}
			} );
			cancel.addEventListener( 'click', close );

			save.addEventListener( 'click', function () {
				const topic = select.value || chosen.value;

				if ( ! topic ) {
					message.textContent = words.nothing;
					return;
				}
				message.textContent = words.working;
				save.disabled = true;
				options.api.call( id + '/topic', 'POST', { topic: Number( topic ), force: force } ).then(
					function ( answer ) {
						save.disabled = false;
						if ( answer && answer.ok ) {
							update( row, answer );
							close();
							return;
						}
						message.textContent = ( answer && answer.error ) || words.failed;
						if ( answer && answer.conflict ) {
							force = true;
							save.textContent = words.anyway;
						}
					},
					function () {
						save.disabled = false;
						message.textContent = words.failed;
					}
				);
			} );

			return name;
		}

		doc.querySelectorAll( 'tbody tr' ).forEach( function ( row ) {
			if ( ! row.querySelector( 'input[name="designation[]"]' ) ) {
				return;
			}

			// The link is in the column of the topic, where the topic is changed.
			const place = row.querySelector( '.column-topic' ) || row.querySelector( 'td' );
			let actions = place.querySelector( '.row-actions' );

			if ( ! actions ) {
				actions = element( 'div', '', 'row-actions' );
				place.appendChild( actions );
			}

			const link = element( 'a', words.change );
			const span = element( 'span', '', 'change-topic' );

			link.href = '#';
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				open( row );
			} );
			span.appendChild( link );
			actions.appendChild( span );
		} );

		return { open: open };
	}

	return { attach: attach };
} ) );
