// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 224: the model shared by the two views of the Book section panel, with a fake API.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { createModel } = require( '../../plugin/modules/books/editor/section/section-model.js' );
const { day, makeApi } = require( './section-helpers.js' );

const names = ( calls ) => calls.map( ( call ) => call[ 0 ] );

test( 'the model starts empty, loads the state and tells its listeners', async () => {
	const { api, calls, context } = makeApi( { section: true, title: 'Pyrenees', range: { from: '2026-05-11 10:00:00', to: '2026-05-12 10:00:00' }, contents: [ day( 1 ), day( 2 ) ] } );
	const model = createModel( { postId: 100, api } );
	let heard = 0;

	assert.strictEqual( model.postId, 100 );
	assert.strictEqual( model.getState().section, false );
	assert.strictEqual( model.getError(), '' );

	const stop = model.subscribe( () => heard++ );

	await model.load();

	assert.deepStrictEqual( calls[ 0 ], [ 'state', 100 ] );
	assert.strictEqual( heard, 1 );
	assert.strictEqual( model.getState().title, 'Pyrenees' );
	assert.deepStrictEqual( model.getState().contents.map( ( post ) => post.id ), [ 1, 2 ] );
	assert.strictEqual( model.getState().range.to, '2026-05-12 10:00:00' );

	stop();
	await model.load();
	assert.strictEqual( heard, 1, 'No longer listening.' );
	assert.ok( context );
} );

test( 'a failing request is kept as the error, a new request clears it', async () => {
	const { api, context } = makeApi( { section: true, contents: [ day( 1 ) ] } );
	const model = createModel( { postId: 100, api } );

	context.fail = 'state';
	assert.strictEqual( await model.load(), undefined );
	assert.strictEqual( model.getError(), 'Refused: state' );

	context.fail = null;
	await model.load();
	assert.strictEqual( model.getError(), '' );

	context.fail = 'move';
	await model.move( 1, 'down' );
	assert.strictEqual( model.getError(), 'Refused: move' );
	assert.strictEqual( model.getState().contents.length, 1, 'The state is kept.' );

	model.clearError();
	assert.strictEqual( model.getError(), '' );

	model.showError( {} );
	assert.strictEqual( model.getError(), 'Something went wrong.' );
} );

test( 'marking answers whether it worked', async () => {
	const { api, calls, context } = makeApi( {} );
	const model = createModel( { postId: 100, api } );

	assert.strictEqual( await model.mark( true ), true );
	assert.deepStrictEqual( calls[ 0 ], [ 'mark', 100, true ] );
	assert.strictEqual( model.getState().section, true );

	context.fail = 'mark';
	assert.strictEqual( await model.mark( false ), false );
	assert.strictEqual( model.getError(), 'Refused: mark' );
} );

test( 'searching the sections answers the items, or nothing on a failure', async () => {
	const { api, calls, context } = makeApi( {} );
	const model = createModel( { postId: 100, api } );

	context.sections = { items: [ day( 7 ) ], total: 1 };
	assert.strictEqual( ( await model.search( 'alp' ) )[ 0 ].id, 7 );
	assert.deepStrictEqual( calls[ 0 ], [ 'search', 'alp' ] );

	context.fail = 'search';
	assert.deepStrictEqual( await model.search( 'x' ), [] );
	assert.strictEqual( model.getError(), 'Refused: search' );
} );

test( 'joining a section adds this post to it then reads this post again; a refusal is explained', async () => {
	const { api, calls, context } = makeApi( { containing: [ { id: 7, title: 'S', edit: '', can_edit: true } ] } );
	const model = createModel( { postId: 100, api } );

	await model.join( 7 );

	assert.deepStrictEqual( calls.slice( 0, 2 ), [ [ 'add', 7, [ 100 ] ], [ 'state', 100 ] ] );
	assert.strictEqual( model.getError(), '' );
	assert.strictEqual( model.getState().containing.length, 1 );

	context.report = { added: [], already: [], skipped: { 100: 'cycle' } };
	await model.join( 8 );
	assert.match( model.getError(), /cannot contain itself/ );

	context.report = { added: [], already: [], skipped: { 100: 'forbidden' } };
	await model.join( 8 );
	assert.match( model.getError(), /not allowed to read/ );

	context.report = { added: [], already: [], skipped: { 100: 'odd' } };
	await model.join( 8 );
	assert.strictEqual( model.getError(), 'odd', 'An unknown reason is shown as it is.' );

	context.fail = 'add';
	await model.join( 9 );
	assert.strictEqual( model.getError(), 'Refused: add' );
} );

test( 'leaving a section reads this post again, because the answer of the route is the state of that section', async () => {
	const { api, calls } = makeApi( {} );
	const model = createModel( { postId: 100, api } );

	await model.leave( 7 );

	assert.deepStrictEqual( calls, [ [ 'remove', 7, 100 ], [ 'state', 100 ] ] );
} );

test( 'what this post holds: removing, moving, resetting and adding with or without a place', async () => {
	const { api, calls, context } = makeApi( { section: true, contents: [ day( 1 ), day( 2 ) ] } );
	const model = createModel( { postId: 100, api } );

	await model.removeOwn( 2 );
	await model.move( 1, 'down' );
	await model.reset();

	assert.deepStrictEqual( calls, [ [ 'remove', 100, 2 ], [ 'move', 100, 1, 'down' ], [ 'reset', 100 ] ] );

	context.report = { added: [ 5 ], already: [ 6 ], skipped: {} };

	const report = await model.add( [ 5, 6 ], { before: 1 } );

	assert.deepStrictEqual( calls[ 3 ], [ 'add', 100, [ 5, 6 ], { before: 1 } ] );
	assert.deepStrictEqual( report, { added: [ 5 ], already: [ 6 ], skipped: {} } );

	await model.add( [ 7 ] );
	assert.deepStrictEqual( calls[ 4 ], [ 'add', 100, [ 7 ], {} ], 'No place: an empty place.' );

	context.fail = 'add';
	assert.strictEqual( await model.add( [ 8 ] ), null );
	assert.strictEqual( model.getError(), 'Refused: add' );
} );

test( 'the candidates are asked for this post; a failure answers null', async () => {
	const { api, calls, context } = makeApi( {} );
	const model = createModel( { postId: 100, api } );

	context.candidates = { items: [ day( 3 ) ], total: 1 };

	assert.strictEqual( ( await model.candidates( { page: 2 } ) ).total, 1 );
	assert.deepStrictEqual( calls[ 0 ], [ 'candidates', 100, { page: 2 } ] );

	context.fail = 'candidates';
	assert.strictEqual( await model.candidates( {} ), null );
	assert.strictEqual( model.getError(), 'Refused: candidates' );
	assert.deepStrictEqual( names( calls ), [ 'candidates', 'candidates' ] );
} );
