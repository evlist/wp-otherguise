// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 630: the field that chooses the topic of an assignment. Typing searches the topics (the label, its kind and the topics above it, to tell
// "Moulins (Allier)" from "Moulins" the river); a click chooses one: its number goes to a hidden field, its label stays in the box.
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.otherguiseTopicPicker = factory();
		if ( root.document && root.otherguiseTopics ) {
			root.document.addEventListener( 'DOMContentLoaded', function () {
				const config = root.otherguiseTopics;
				const api = {
					search: function ( text ) {
						return root
							.fetch( config.root + config.path + '?search=' + encodeURIComponent( text ), { headers: { 'X-WP-Nonce': config.nonce } } )
							.then( function ( response ) {
								return response.json();
							} );
					},
				};
				root.document.querySelectorAll( '[data-topic-picker]' ).forEach( function ( input ) {
					root.otherguiseTopicPicker.mount( { document: root.document, input: input, hidden: root.document.getElementById( input.getAttribute( 'data-topic-picker' ) ), api: api } );
				} );
			} );
		}
	}
}( typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	/**
	 * Attaches the suggestions to a text field.
	 *
	 * @param {Object} options `document`, `input`, `hidden` (the field that holds the number), `api.search( text )` (a promise of a list) and `wait` (the
	 *                         delay before a search, in milliseconds; 250 by default).
	 * @return {Object} `destroy()`.
	 */
	function mount( options ) {
		const doc = options.document;
		const input = options.input;
		const hidden = options.hidden;
		const api = options.api;
		const wait = undefined === options.wait ? 250 : options.wait;
		let timer = null;
		let ticket = 0;
		const list = doc.createElement( 'ul' );

		list.className = 'topic-picker__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		input.parentNode.insertBefore( list, input.nextSibling );
		input.setAttribute( 'autocomplete', 'off' );

		function clear() {
			while ( list.firstChild ) {
				list.removeChild( list.firstChild );
			}
			list.hidden = true;
		}

		function choose( topic ) {
			hidden.value = String( topic.id );
			input.value = topic.label;
			clear();
		}

		function show( topics ) {
			clear();
			topics.forEach( function ( topic ) {
				const item = doc.createElement( 'li' );
				const detail = [ topic.kind, topic.chain ].filter( Boolean ).join( ', ' );

				item.setAttribute( 'role', 'option' );
				item.textContent = topic.label + ( detail ? ' (' + detail + ')' : '' ) + ' #' + topic.id;
				item.addEventListener( 'click', function () {
					choose( topic );
				} );
				list.appendChild( item );
			} );
			list.hidden = 0 === topics.length;
		}

		function ask() {
			const text = input.value.trim();
			const mine = ++ticket;

			hidden.value = '';

			if ( text.length < 2 ) {
				clear();
				return;
			}

			api.search( text ).then( function ( topics ) {
				// An answer that comes late, after another letter was typed, is ignored.
				if ( mine === ticket ) {
					show( topics );
				}
			}, clear );
		}

		function onInput() {
			// What was typed is no longer the topic that was chosen.
			hidden.value = '';

			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( ask, wait );
		}

		input.addEventListener( 'input', onInput );

		return {
			destroy: function () {
				input.removeEventListener( 'input', onInput );
				if ( timer ) {
					clearTimeout( timer );
				}
				if ( list.parentNode ) {
					list.parentNode.removeChild( list );
				}
			},
		};
	}

	return { mount: mount };
} ) );
