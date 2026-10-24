// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slices 223 and 224: the sidebar view of the Book section panel, run in jsdom with a fake API and a fake window control.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { mount } = require( '../../plugin/modules/books/editor/section/section-ui.js' );
const { day, flush, click, fire, makeModel } = require( './section-helpers.js' );

/** Mounts the sidebar view for post 100. */
function make( initial ) {
	const site = makeModel( initial );
	const control = { open: false, toggled: 0, listeners: [] };
	const windowControl = {
		isOpen: () => control.open,
		toggle: () => {
			control.toggled++;
			control.open = ! control.open;
			control.listeners.forEach( ( listener ) => listener() );
		},
		subscribe: ( listener ) => {
			control.listeners.push( listener );
			return () => control.listeners.splice( control.listeners.indexOf( listener ), 1 );
		},
	};
	const ui = mount( {
		document: site.doc,
		container: site.doc.getElementById( 'host' ),
		model: site.model,
		windowControl,
		setTimeout: ( callback ) => {
			callback();
			return 1;
		},
		clearTimeout: () => {},
	} );
	return Object.assign( site, { ui, control, windowControl } );
}

const SECTION = { id: 7, title: 'Pyrenees', edit: 'https://example.test/edit/7', can_edit: true };

test( 'it loads the state and shows the sections of the post, and nothing about contents for a plain post', async () => {
	const { q, all, calls } = make( { containing: [ SECTION, { id: 8, title: 'Alps', edit: '', can_edit: false } ] } );

	assert.deepStrictEqual( calls.map( ( call ) => call[ 0 ] ), [ 'state' ] );
	await flush();

	assert.deepStrictEqual( all( '.books-section__containing-item .books-section__link' ).map( ( node ) => node.textContent ), [ 'Pyrenees', 'Alps' ] );
	assert.strictEqual( q( '.books-section__containing-item a' ).href, 'https://example.test/edit/7' );
	assert.strictEqual( all( '.books-section__remove-from' ).length, 1, 'Only the sections that the user may edit can be left.' );
	assert.strictEqual( q( '.books-section__check' ).checked, false );
	assert.strictEqual( q( '.books-section__summary' ).hidden, true );
	assert.strictEqual( q( '.books-section__window-link' ).hidden, true, 'A plain post has nothing to detach.' );
	assert.strictEqual( q( '.books-section__item' ), null, 'The contents are never listed in the sidebar.' );
} );

test( 'a section shows a summary and the link that opens the window, at the top of the panel', async () => {
	const { q, all, control, windowControl, ui } = make( { section: true, range: { from: '2026-05-11 10:00:00', to: '2026-09-29 08:00:00' }, contents: [ day( 1 ), day( 2 ), day( 3 ) ] } );
	await flush();

	assert.strictEqual( q( '.books-section__check' ).checked, true );
	assert.strictEqual( q( '.books-section__summary' ).hidden, false );
	assert.strictEqual( q( '.books-section__summary' ).textContent, 'Contains (3) · 2026-05-11 → 2026-09-29' );
	assert.strictEqual( q( '.books-section__window-link' ).hidden, false );
	assert.strictEqual( q( '.books-section__window-link' ).textContent, 'Open in a floating window' );
	assert.strictEqual( ui.node.firstChild, q( '.books-section__window-link' ), 'First thing of the panel.' );
	assert.strictEqual( all( '.books-section__item, .books-section__up, .books-section__down' ).length, 0 );

	click( q( '.books-section__window-link' ) );

	assert.strictEqual( control.toggled, 1 );
	assert.strictEqual( windowControl.isOpen(), true );
	assert.strictEqual( q( '.books-section__window-link' ).textContent, 'Close the floating window', 'It follows the window.' );

	click( q( '.books-section__window-link' ) );
	assert.strictEqual( q( '.books-section__window-link' ).textContent, 'Open in a floating window' );
} );

test( 'the summary has no range for an empty section, and one date when all the posts have the same', async () => {
	const empty = make( { section: true } );
	await flush();
	assert.strictEqual( empty.q( '.books-section__summary' ).textContent, 'Contains (0)' );

	const same = make( { section: true, range: { from: '2026-05-11 10:00:00', to: '2026-05-11 18:00:00' }, contents: [ day( 1 ) ] } );
	await flush();
	assert.strictEqual( same.q( '.books-section__summary' ).textContent, 'Contains (1) · 2026-05-11' );
} );

test( 'leaving a section asks the server and reads the state of this post again', async () => {
	const { q, calls, context } = make( { containing: [ SECTION ] } );
	await flush();

	context.state = Object.assign( {}, context.state, { containing: [] } );
	click( q( '.books-section__remove-from' ) );
	await flush();

	assert.deepStrictEqual( calls.slice( 1 ), [ [ 'remove', 7, 100 ], [ 'state', 100 ] ] );
	assert.strictEqual( q( '.books-section__containing' ).children.length, 0 );
} );

test( 'searching the sections offers the others only, and choosing one adds the post to it', async () => {
	const { q, all, calls, context } = make( { containing: [ SECTION ] } );
	await flush();

	context.sections = { items: [ day( 7, { title: 'Pyrenees' } ), day( 8, { title: 'Alps' } ), day( 100, { title: 'This very post' } ) ], total: 3 };
	q( '.books-section__find' ).value = 'a';
	fire( q( '.books-section__find' ), 'input' );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'search', 'a' ] );
	assert.deepStrictEqual( all( '.books-section__choose' ).map( ( node ) => node.textContent ), [ 'Alps' ], 'Neither the sections it is in, nor itself.' );

	click( q( '.books-section__choose' ) );
	await flush();

	assert.deepStrictEqual( calls[ 2 ], [ 'add', 8, [ 100 ] ] );
	assert.strictEqual( calls[ calls.length - 1 ][ 0 ], 'state', 'The state of this post is read again.' );
	assert.strictEqual( q( '.books-section__find' ).value, '' );
	assert.strictEqual( q( '.books-section__found' ).children.length, 0 );
} );

test( 'a section that would make a cycle is refused with a message', async () => {
	const { q, context } = make( {} );
	await flush();

	context.report = { added: [], already: [], skipped: { 100: 'cycle' } };
	context.sections = { items: [ day( 8, { title: 'Alps' } ) ], total: 1 };
	fire( q( '.books-section__find' ), 'focus' );
	await flush();
	click( q( '.books-section__choose' ) );
	await flush();
	await flush();

	assert.strictEqual( q( '.books-section__error' ).hidden, false );
	assert.match( q( '.books-section__error' ).textContent, /cannot contain itself/ );
} );

test( 'no section found says so', async () => {
	const { q } = make( {} );
	await flush();

	fire( q( '.books-section__find' ), 'focus' );
	await flush();

	assert.strictEqual( q( '.books-section__none' ).textContent, 'No section found.' );
} );

test( 'the switch marks the post as a section, and goes back when the server refuses', async () => {
	const { q, calls, context } = make( {} );
	await flush();

	q( '.books-section__check' ).checked = true;
	fire( q( '.books-section__check' ), 'change' );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'mark', 100, true ] );
	assert.strictEqual( q( '.books-section__summary' ).hidden, false );
	assert.strictEqual( q( '.books-section__window-link' ).hidden, false, 'Now there is something to detach.' );

	context.fail = 'mark';
	q( '.books-section__check' ).checked = false;
	fire( q( '.books-section__check' ), 'change' );
	await flush();

	assert.strictEqual( q( '.books-section__check' ).checked, true, 'Back to what it was.' );
	assert.strictEqual( q( '.books-section__error' ).textContent, 'Refused: mark' );
} );

test( 'the window control is optional to listen to, and destroying the view removes it and stops listening', async () => {
	const { ui, doc, control } = make( { section: true } );
	await flush();

	assert.strictEqual( control.listeners.length, 1 );

	ui.destroy();

	assert.strictEqual( doc.querySelector( '.books-section' ), null );
	assert.strictEqual( control.listeners.length, 0 );
} );
