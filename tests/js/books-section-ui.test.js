// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 223: the Section panel of the post editor, run in jsdom with a fake API (each call is recorded and answers a state or a failure).
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const { mount } = require( '../../plugin/modules/books/editor/section/section-ui.js' );

const day = ( id, extra ) => Object.assign( { id, title: 'Day ' + id, status: 'publish', type: 'post', date: '2026-05-' + String( 10 + id ) + ' 10:00:00', edit: 'https://example.test/edit/' + id, pinned: null }, extra );
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

/** Mounts the panel for post 100 with a fake API whose answers the test can change. */
function make( initial, overrides ) {
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>' );
	const calls = [];
	const context = {
		state: Object.assign( { id: 100, section: false, contents: [], containing: [] }, initial ),
		sections: { items: [], total: 0 },
		candidates: { items: [], total: 0 },
		fail: null,
	};
	const answer = ( name, extra ) => ( ...args ) => {
		calls.push( [ name ].concat( args ) );
		if ( context.fail && context.fail === name ) {
			return Promise.reject( { message: 'Refused: ' + name } );
		}
		return Promise.resolve( extra ? extra( ...args ) : context.state );
	};
	const api = Object.assign(
		{
			state: answer( 'state' ),
			mark: answer( 'mark', ( id, on ) => Object.assign( {}, context.state, { section: on } ) ),
			search: answer( 'search', () => context.sections ),
			add: answer( 'add', ( id, ids ) => Object.assign( { report: { added: ids, already: [], skipped: {} } }, context.state ) ),
			remove: answer( 'remove' ),
			move: answer( 'move' ),
			reset: answer( 'reset' ),
			candidates: answer( 'candidates', () => context.candidates ),
		},
		overrides
	);
	const ui = mount( {
		document: dom.window.document,
		container: dom.window.document.getElementById( 'host' ),
		postId: 100,
		api,
		setTimeout: ( callback ) => {
			callback();
			return 1;
		},
		clearTimeout: () => {},
	} );
	const q = ( selector ) => dom.window.document.querySelector( selector );
	const all = ( selector ) => Array.from( dom.window.document.querySelectorAll( selector ) );
	return { dom, ui, calls, context, q, all };
}

const names = ( calls ) => calls.map( ( call ) => call[ 0 ] );
const click = ( node ) => node.dispatchEvent( new node.ownerDocument.defaultView.Event( 'click', { bubbles: true } ) );
const fire = ( node, type, constructor, init ) => node.dispatchEvent( new node.ownerDocument.defaultView[ constructor || 'Event' ]( type, init ) );

test( 'it loads the state and shows the sections of the post and the switch', async () => {
	const { q, all, calls } = make( { containing: [ { id: 7, title: 'Pyrenees', edit: 'https://example.test/edit/7', can_edit: true }, { id: 8, title: 'Alps', edit: '', can_edit: false } ] } );

	assert.deepStrictEqual( names( calls ), [ 'state' ] );
	await flush();

	assert.deepStrictEqual( all( '.books-section__containing-item .books-section__link' ).map( ( node ) => node.textContent ), [ 'Pyrenees', 'Alps' ] );
	assert.strictEqual( q( '.books-section__containing-item a' ).href, 'https://example.test/edit/7' );
	assert.strictEqual( all( '.books-section__remove-from' ).length, 1, 'Only the sections that the user may edit can be left.' );
	assert.strictEqual( q( '.books-section__check' ).checked, false );
	assert.strictEqual( q( '.books-section__own' ).hidden, true );
} );

test( 'leaving a section asks the server and shows the new state', async () => {
	const { q, calls, context } = make( { containing: [ { id: 7, title: 'Pyrenees', edit: '', can_edit: true } ] } );
	await flush();

	context.state = { id: 100, section: false, contents: [], containing: [] };
	click( q( '.books-section__remove-from' ) );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'remove', 7, 100 ] );
	assert.deepStrictEqual( calls[ 2 ], [ 'state', 100 ], 'The state of this post is read again, not the one of the section.' );
	assert.strictEqual( q( '.books-section__containing' ).children.length, 0 );
} );

test( 'searching the sections offers the others only, and choosing one adds the post to it', async () => {
	const { q, all, calls, context } = make( { containing: [ { id: 7, title: 'Pyrenees', edit: '', can_edit: true } ] } );
	await flush();

	context.sections = { items: [ day( 7, { title: 'Pyrenees' } ), day( 8, { title: 'Alps' } ), day( 100, { title: 'This very post' } ) ], total: 3 };
	q( '.books-section__find' ).value = 'a';
	fire( q( '.books-section__find' ), 'input', 'Event' );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'search', 'a' ] );
	assert.deepStrictEqual( all( '.books-section__choose' ).map( ( node ) => node.textContent ), [ 'Alps' ], 'Neither the sections it is in, nor itself.' );

	click( q( '.books-section__choose' ) );
	await flush();

	assert.deepStrictEqual( calls[ 2 ], [ 'add', 8, [ 100 ] ] );
	assert.deepStrictEqual( names( calls ).slice( -1 ), [ 'state' ], 'The state is read again.' );
	assert.strictEqual( q( '.books-section__find' ).value, '' );
	assert.strictEqual( q( '.books-section__found' ).children.length, 0 );
} );

test( 'a section that would make a cycle is refused with a message', async () => {
	const { q, context } = make( {}, { add: () => Promise.resolve( { report: { added: [], already: [], skipped: { 100: 'cycle' } }, id: 100, section: false, contents: [], containing: [] } ) } );
	await flush();

	context.sections = { items: [ day( 8, { title: 'Alps' } ) ], total: 1 };
	fire( q( '.books-section__find' ), 'focus', 'Event' );
	await flush();
	click( q( '.books-section__choose' ) );
	await flush();

	assert.strictEqual( q( '.books-section__error' ).hidden, false );
	assert.match( q( '.books-section__error' ).textContent, /cannot contain itself/ );
} );

test( 'no section found says so', async () => {
	const { q } = make( {} );
	await flush();

	fire( q( '.books-section__find' ), 'focus', 'Event' );
	await flush();

	assert.strictEqual( q( '.books-section__none' ).textContent, 'No section found.' );
} );

test( 'the switch marks the post as a section, and goes back when the server refuses', async () => {
	const { q, calls, context } = make( {} );
	await flush();

	q( '.books-section__check' ).checked = true;
	fire( q( '.books-section__check' ), 'change', 'Event' );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'mark', 100, true ] );
	assert.strictEqual( q( '.books-section__own' ).hidden, false );

	context.fail = 'mark';
	q( '.books-section__check' ).checked = false;
	fire( q( '.books-section__check' ), 'change', 'Event' );
	await flush();

	assert.strictEqual( q( '.books-section__check' ).checked, true, 'Back to what it was.' );
	assert.strictEqual( q( '.books-section__error' ).textContent, 'Refused: mark' );
} );

test( 'the contents are listed in order with their buttons; moving, removing and resetting ask the server', async () => {
	const { q, all, calls, context } = make( { section: true, contents: [ day( 1 ), day( 2, { pinned: 2 } ), day( 3 ) ] } );
	await flush();

	assert.strictEqual( q( '.books-section__own' ).hidden, false );
	assert.strictEqual( q( '.books-section__own .books-section__title' ).textContent, 'Contains (3)' );
	assert.deepStrictEqual( all( '.books-section__item' ).map( ( node ) => node.dataset.id ), [ '1', '2', '3' ] );
	assert.match( all( '.books-section__meta' )[ 1 ].textContent, /2026-05-12 · publish · moved/ );
	assert.strictEqual( all( '.books-section__up' )[ 0 ].disabled, true, 'The first cannot go up.' );
	assert.strictEqual( all( '.books-section__down' )[ 2 ].disabled, true, 'The last cannot go down.' );
	assert.strictEqual( q( '.books-section__reset' ).disabled, false, 'A post was moved.' );

	click( all( '.books-section__down' )[ 0 ] );
	click( all( '.books-section__up' )[ 2 ] );
	click( all( '.books-section__remove' )[ 1 ] );
	click( q( '.books-section__reset' ) );
	await flush();

	assert.deepStrictEqual( calls.slice( 1 ), [ [ 'move', 100, 1, 'down' ], [ 'move', 100, 3, 'up' ], [ 'remove', 100, 2 ], [ 'reset', 100 ] ] );

	context.state = { id: 100, section: true, contents: [ day( 1 ) ], containing: [] };
	click( q( '.books-section__reset' ) );
	await flush();

	assert.strictEqual( q( '.books-section__reset' ).disabled, true, 'Nothing moved: nothing to reset.' );
} );

test( 'a failing request shows its message and keeps the panel', async () => {
	const { q, all, context } = make( { section: true, contents: [ day( 1 ), day( 2 ) ] } );
	await flush();

	context.fail = 'move';
	click( all( '.books-section__down' )[ 0 ] );
	await flush();

	assert.strictEqual( q( '.books-section__error' ).textContent, 'Refused: move' );
	assert.strictEqual( all( '.books-section__item' ).length, 2 );
} );

test( 'the dialog searches with the filters, ticks the selection and adds it', async () => {
	const { q, all, calls, context } = make( { section: true, contents: [ day( 2 ) ] } );
	await flush();

	context.candidates = { items: [ day( 1, { in_section: false } ), day( 2, { in_section: true } ), day( 3, { in_section: false } ) ], total: 3 };
	click( q( '.books-section__add' ) );
	await flush();

	assert.strictEqual( q( '.books-section__dialog' ).hidden, false );
	assert.deepStrictEqual( calls[ 1 ], [ 'candidates', 100, { search: '', after: '', before: '', statuses: [ 'publish' ], page: 1 } ] );

	const boxes = all( '.books-section__d-item input' );

	assert.strictEqual( boxes.length, 3 );
	assert.strictEqual( boxes[ 1 ].disabled, true, 'Already in the section.' );
	assert.strictEqual( boxes[ 1 ].checked, true );
	assert.strictEqual( q( '.books-section__d-status-line' ).textContent, '3 / 3' );
	assert.strictEqual( q( '.books-section__d-more' ).hidden, true );

	boxes[ 2 ].checked = true;
	boxes[ 2 ].dispatchEvent( new boxes[ 2 ].ownerDocument.defaultView.Event( 'change' ) );
	context.state = { id: 100, section: true, contents: [ day( 2 ), day( 3 ) ], containing: [] };
	click( q( '.books-section__d-add' ) );
	await flush();

	assert.deepStrictEqual( calls[ 2 ], [ 'add', 100, [ 3 ] ] );
	assert.strictEqual( q( '.books-section__d-status-line' ).textContent, '1 added, 0 already there, 0 skipped' );
	assert.strictEqual( all( '.books-section__item' ).length, 2 );
	assert.strictEqual( all( '.books-section__d-item input' )[ 2 ].disabled, true, 'Now in the section too.' );

	q( '.books-section__d-search' ).value = 'day';
	q( '.books-section__d-after' ).value = '2026-05-11';
	q( '.books-section__d-status input[value=draft]' ).checked = true;
	click( q( '.books-section__d-go' ) );
	await flush();

	assert.deepStrictEqual( calls[ calls.length - 1 ], [ 'candidates', 100, { search: 'day', after: '2026-05-11', before: '', statuses: [ 'publish', 'draft' ], page: 1 } ] );
} );

test( 'adding with nothing ticked asks nothing; more results are appended', async () => {
	const { q, all, calls, context } = make( { section: true } );
	await flush();

	context.candidates = { items: [ day( 1, { in_section: false } ) ], total: 2 };
	click( q( '.books-section__add' ) );
	await flush();

	assert.strictEqual( q( '.books-section__d-more' ).hidden, false );

	click( q( '.books-section__d-add' ) );
	await flush();
	assert.strictEqual( names( calls ).filter( ( name ) => 'add' === name ).length, 0 );

	context.candidates = { items: [ day( 2, { in_section: false } ) ], total: 2 };
	click( q( '.books-section__d-more' ) );
	await flush();

	assert.strictEqual( calls[ calls.length - 1 ][ 2 ].page, 2 );
	assert.strictEqual( all( '.books-section__d-item' ).length, 2 );
	assert.strictEqual( q( '.books-section__d-more' ).hidden, true );
} );

test( 'adding all the results collects every page up to the cap and skips what is in already', async () => {
	const pages = {
		1: Array.from( { length: 50 }, ( x, index ) => day( 1000 + index, { in_section: 0 === index } ) ),
		2: Array.from( { length: 50 }, ( x, index ) => day( 2000 + index, { in_section: false } ) ),
		3: Array.from( { length: 50 }, ( x, index ) => day( 3000 + index, { in_section: false } ) ),
		4: Array.from( { length: 50 }, ( x, index ) => day( 4000 + index, { in_section: false } ) ),
		5: Array.from( { length: 50 }, ( x, index ) => day( 5000 + index, { in_section: false } ) ),
	};
	const { q, calls } = make( { section: true }, { candidates: ( id, criteria ) => {
		calls_push( [ 'candidates', id, criteria ] );
		return Promise.resolve( { items: pages[ criteria.page ] || [], total: 250 } );
	} } );
	function calls_push( call ) {
		calls.push( call );
	}
	await flush();

	click( q( '.books-section__add' ) );
	await flush();
	click( q( '.books-section__d-add-all' ) );
	await flush();
	await flush();

	const add = calls.find( ( call ) => 'add' === call[ 0 ] );

	assert.strictEqual( add[ 2 ].length, 200, 'At most 200 at a time.' );
	assert.ok( ! add[ 2 ].includes( 1000 ), 'The one that is in already is left out.' );
	assert.strictEqual( add[ 2 ][ 0 ], 1001 );
} );

test( 'the dialog closes with its button and with Escape, and a failing search shows its message', async () => {
	const { q, context } = make( { section: true } );
	await flush();

	click( q( '.books-section__add' ) );
	await flush();
	click( q( '.books-section__d-close' ) );

	assert.strictEqual( q( '.books-section__dialog' ).hidden, true );

	click( q( '.books-section__add' ) );
	await flush();
	fire( q( '.books-section__dialog' ), 'keydown', 'KeyboardEvent', { key: 'Escape' } );

	assert.strictEqual( q( '.books-section__dialog' ).hidden, true );

	context.fail = 'candidates';
	click( q( '.books-section__add' ) );
	await flush();

	assert.strictEqual( q( '.books-section__error' ).textContent, 'Refused: candidates' );
} );

test( 'destroying the panel removes it, and the title of a post without link is not a link', async () => {
	const { ui, dom } = make( { section: true, contents: [ day( 1, { edit: '' } ) ] } );
	await flush();

	assert.strictEqual( dom.window.document.querySelector( '.books-section__item a' ), null );

	ui.destroy();

	assert.strictEqual( dom.window.document.querySelector( '.books-section' ), null );
} );
