// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slices 223 and 224: the dialog that adds posts to a section, run in jsdom with a fake API.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { createDialog } = require( '../../plugin/modules/books/editor/section/section-dialog.js' );
const { day, flush, click, fire, makeModel } = require( './section-helpers.js' );

function make( initial ) {
	const site = makeModel( initial );
	const dialog = createDialog( { document: site.doc, model: site.model } );
	return Object.assign( site, { dialog } );
}

const names = ( calls ) => calls.map( ( call ) => call[ 0 ] );

test( 'the dialog is built hidden and put in the page when it is opened, with what the filters start from', async () => {
	const { dialog, doc, calls, context, q } = make( { section: true } );

	assert.strictEqual( dialog.isOpen(), false );
	assert.strictEqual( doc.querySelector( '.books-section__dialog' ), null );

	context.candidates = { items: [ day( 1, { in_section: false } ) ], total: 1 };
	await dialog.open( {}, '' );

	assert.strictEqual( dialog.isOpen(), true );
	assert.strictEqual( q( '.books-section__dialog' ).getAttribute( 'role' ), 'dialog' );
	assert.deepStrictEqual( calls[ 0 ], [ 'candidates', 100, { search: '', after: '', before: '', statuses: [ 'publish' ], page: 1 } ] );
	assert.strictEqual( q( '.books-section__dialog .books-section__title' ).textContent, 'Add posts' );

	await dialog.open( { before: 3 }, 'before “Day 3”' );

	assert.strictEqual( q( '.books-section__dialog .books-section__title' ).textContent, 'Add posts before “Day 3”' );
	assert.strictEqual( doc.querySelectorAll( '.books-section__dialog' ).length, 1, 'Only one page of it.' );
} );

test( 'it searches with the filters, ticks the selection and adds it', async () => {
	const { dialog, all, calls, context, q } = make( { section: true, contents: [ day( 2 ) ] } );

	context.candidates = { items: [ day( 1, { in_section: false } ), day( 2, { in_section: true } ), day( 3, { in_section: false } ) ], total: 3 };
	await dialog.open( {}, '' );

	const boxes = all( '.books-section__d-item input' );

	assert.strictEqual( boxes.length, 3 );
	assert.strictEqual( boxes[ 1 ].disabled, true, 'Already in the section.' );
	assert.strictEqual( boxes[ 1 ].checked, true );
	assert.strictEqual( q( '.books-section__d-status-line' ).textContent, '3 / 3' );
	assert.strictEqual( q( '.books-section__d-more' ).hidden, true );

	boxes[ 2 ].checked = true;
	fire( boxes[ 2 ], 'change' );
	context.state = Object.assign( {}, context.state, { contents: [ day( 2 ), day( 3 ) ] } );
	click( q( '.books-section__d-add' ) );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'add', 100, [ 3 ], {} ] );
	assert.strictEqual( q( '.books-section__d-status-line' ).textContent, '1 added, 0 already there, 0 skipped' );
	assert.strictEqual( all( '.books-section__d-item input' )[ 2 ].disabled, true, 'Now in the section too.' );
	assert.strictEqual( context.state.contents.length, 2 );

	q( '.books-section__d-search' ).value = 'day';
	q( '.books-section__d-after' ).value = '2026-05-11';
	q( '.books-section__d-status input[value=draft]' ).checked = true;
	click( q( '.books-section__d-go' ) );
	await flush();

	assert.deepStrictEqual( calls[ calls.length - 1 ], [ 'candidates', 100, { search: 'day', after: '2026-05-11', before: '', statuses: [ 'publish', 'draft' ], page: 1 } ] );
} );

test( 'the posts are added at the place the dialog was opened for', async () => {
	const { dialog, all, calls, context, q } = make( { section: true } );

	context.candidates = { items: [ day( 4, { in_section: false } ), day( 5, { in_section: false } ) ], total: 2 };
	await dialog.open( { after: 2 }, 'after “Day 2”' );

	all( '.books-section__d-item input' ).forEach( ( box ) => {
		box.checked = true;
		fire( box, 'change' );
	} );
	click( q( '.books-section__d-add' ) );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'add', 100, [ 4, 5 ], { after: 2 } ] );
} );

test( 'adding with nothing ticked asks nothing; more results are appended', async () => {
	const { dialog, all, calls, context, q } = make( { section: true } );

	context.candidates = { items: [ day( 1, { in_section: false } ) ], total: 2 };
	await dialog.open( {}, '' );

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
	const pages = {};

	for ( let page = 1; page <= 5; page++ ) {
		pages[ page ] = Array.from( { length: 50 }, ( x, index ) => day( page * 1000 + index, { in_section: 1 === page && 0 === index } ) );
	}

	const { dialog, calls, api, q } = make( { section: true } );
	const original = api.candidates;

	api.candidates = ( id, criteria ) => original( id, criteria ).then( () => ( { items: pages[ criteria.page ] || [], total: 250 } ) );
	await dialog.open( {}, '' );
	click( q( '.books-section__d-add-all' ) );
	await flush();
	await flush();
	await flush();

	const add = calls.find( ( call ) => 'add' === call[ 0 ] );

	assert.strictEqual( add[ 2 ].length, 200, 'At most 200 at a time.' );
	assert.ok( ! add[ 2 ].includes( 1000 ), 'The one that is in already is left out.' );
	assert.strictEqual( add[ 2 ][ 0 ], 1001 );
} );

test( 'it closes with its button and with Escape, and a failing search keeps the error in the model', async () => {
	const { dialog, model, context, q } = make( { section: true } );

	await dialog.open( {}, '' );
	click( q( '.books-section__d-close' ) );
	assert.strictEqual( dialog.isOpen(), false );

	await dialog.open( {}, '' );
	fire( q( '.books-section__dialog' ), 'keydown', 'KeyboardEvent', { key: 'Escape' } );
	assert.strictEqual( dialog.isOpen(), false );

	context.fail = 'candidates';
	await dialog.open( {}, '' );
	assert.strictEqual( model.getError(), 'Refused: candidates' );
	dialog.close();
} );
