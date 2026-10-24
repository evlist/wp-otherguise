// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slices 223 and 224: what the tests of the Book section panel share (a fake API, posts, a jsdom page).
const { JSDOM } = require( 'jsdom' );
const { createModel } = require( '../../plugin/modules/books/editor/section/section-model.js' );

const day = ( id, extra ) => Object.assign( {
	id,
	title: 'Day ' + id,
	status: 'publish',
	type: 'post',
	date: '2026-05-' + String( 10 + id ) + ' 10:00:00',
	edit: 'https://example.test/edit/' + id,
	view: 'https://example.test/?p=' + id,
	thumb: '',
	pinned: null,
}, extra );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const click = ( node ) => node.dispatchEvent( new node.ownerDocument.defaultView.Event( 'click', { bubbles: true } ) );
const fire = ( node, type, constructor, init ) => node.dispatchEvent( new node.ownerDocument.defaultView[ constructor || 'Event' ]( type, init ) );

/** A fake API whose answers the test can change: `context.state` answers the state calls, `context.fail` names a call that fails. */
function makeApi( initial ) {
	const calls = [];
	const context = {
		state: Object.assign( { id: 100, title: 'The book', section: false, range: { from: '', to: '' }, contents: [], containing: [] }, initial ),
		sections: { items: [], total: 0 },
		candidates: { items: [], total: 0 },
		fail: null,
		report: null,
	};
	const answer = ( name, extra ) => ( ...args ) => {
		calls.push( [ name ].concat( args ) );
		if ( context.fail && context.fail === name ) {
			return Promise.reject( { message: 'Refused: ' + name } );
		}
		return Promise.resolve( extra ? extra( ...args ) : context.state );
	};
	const api = {
		state: answer( 'state' ),
		mark: answer( 'mark', ( id, on ) => Object.assign( {}, context.state, { section: on } ) ),
		search: answer( 'search', () => context.sections ),
		add: answer( 'add', ( id, ids ) => Object.assign( { report: context.report || { added: ids, already: [], skipped: {} } }, context.state ) ),
		remove: answer( 'remove' ),
		move: answer( 'move' ),
		reset: answer( 'reset' ),
		candidates: answer( 'candidates', () => context.candidates ),
	};
	return { api, calls, context };
}

/** A fresh jsdom page, a fake API and a model for post 100. */
function makeModel( initial ) {
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>', { pretendToBeVisual: true } );
	const fake = makeApi( initial );
	const model = createModel( { postId: 100, api: fake.api } );
	return Object.assign( fake, {
		dom,
		model,
		doc: dom.window.document,
		q: ( selector ) => dom.window.document.querySelector( selector ),
		all: ( selector ) => Array.from( dom.window.document.querySelectorAll( selector ) ),
	} );
}

module.exports = { day, flush, click, fire, makeApi, makeModel };
