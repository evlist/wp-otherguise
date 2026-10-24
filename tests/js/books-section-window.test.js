// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 224: the floating window of the Book section panel, run in jsdom with a fake API.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { createWindow, STORAGE_KEY } = require( '../../plugin/modules/books/editor/section/section-window.js' );
const { day, flush, click, fire, makeModel } = require( './section-helpers.js' );

const SECTION = {
	section: true,
	title: 'Pyrenees van trip',
	range: { from: '2026-05-11 10:00:00', to: '2026-05-13 10:00:00' },
	contents: [
		day( 1, { print: 'https://example.test/?p=1&mode=print' } ),
		day( 2, { pinned: 2, print: 'https://example.test/?p=2&mode=print' } ),
		day( 3, { status: 'draft', view: 'https://example.test/?p=3&preview=true', print: 'https://example.test/?p=3&preview=true&mode=print' } ),
	],
};

/** Builds the window over a fake dialog that records how it was opened. */
function make( initial, extra ) {
	const site = makeModel( initial );
	const opened = [];
	const dialog = {
		open: ( place, label ) => opened.push( [ place, label ] ),
		close: () => {
			dialog.state = false;
		},
		isOpen: () => !! dialog.state,
		state: false,
	};
	const env = Object.assign( { store: {}, size: { width: 1280, height: 800 } }, extra );
	const floating = createWindow( {
		document: site.doc,
		model: site.model,
		dialog,
		storage: env.noStorage ? null : {
			getItem: ( key ) => ( key in env.store ? env.store[ key ] : null ),
			setItem: ( key, value ) => {
				if ( env.failStorage ) {
					throw new Error( 'blocked' );
				}
				env.store[ key ] = value;
			},
		},
		viewport: () => env.size,
		ResizeObserver: env.Observer,
	} );
	return Object.assign( site, { floating, opened, dialog, env } );
}

const open = async ( site ) => {
	await site.model.load();
	site.floating.open();
};

test( 'the window is built hidden, put in the page when it opens, and says which book section it shows', async () => {
	const site = make( SECTION );

	assert.strictEqual( site.floating.isOpen(), false );
	assert.strictEqual( site.q( '.books-section-window' ), null );

	await open( site );

	assert.strictEqual( site.floating.isOpen(), true );
	assert.strictEqual( site.q( '.books-section-window' ).hidden, false );
	assert.strictEqual( site.q( '.books-section-window' ).getAttribute( 'role' ), 'dialog' );
	assert.strictEqual( site.q( '.books-section-window' ).getAttribute( 'aria-label' ), 'Book section' );
	assert.strictEqual( site.q( '.books-section-window__title' ).textContent, 'Book section — Pyrenees van trip' );
	assert.strictEqual( site.all( '.books-section-window' ).length, 1 );
} );

test( 'it opens, closes and toggles, and tells the listeners', async () => {
	const site = make( SECTION );
	let heard = 0;
	const stop = site.floating.subscribe( () => heard++ );

	await open( site );
	site.floating.toggle();

	assert.strictEqual( site.floating.isOpen(), false );
	assert.strictEqual( site.q( '.books-section-window' ).hidden, true );

	site.floating.toggle();
	click( site.q( '.books-section-window__close' ) );

	assert.strictEqual( site.floating.isOpen(), false );
	assert.strictEqual( heard, 4 );

	site.floating.close();
	assert.strictEqual( heard, 4, 'Closing a closed window says nothing.' );

	stop();
	site.floating.open();
	assert.strictEqual( heard, 4 );
	assert.strictEqual( site.dialog.isOpen(), false );
} );

test( 'one line per post, in order, with the title, the date, the status and a mark for a moved one', async () => {
	const site = make( SECTION );
	await open( site );

	assert.deepStrictEqual( site.all( '.books-section-row' ).map( ( row ) => row.dataset.id ), [ '1', '2', '3' ] );
	assert.deepStrictEqual( site.all( '.books-section-row__name' ).map( ( node ) => node.textContent ), [ 'Day 1', 'Day 2', 'Day 3' ] );
	assert.deepStrictEqual( site.all( '.books-section-row__meta' ).map( ( node ) => node.textContent ), [ '2026-05-11 · publish', '2026-05-12 · publish · moved', '2026-05-13 · draft' ] );
	assert.strictEqual( site.q( '.books-section-window__empty' ).hidden, true );
} );

test( 'the featured image is the background of the line: lazy, without a text alternative, absent when the post has none', async () => {
	const site = make( SECTION );

	site.context.images = { 1: 'https://example.test/one.jpg', 3: 'https://example.test/three.jpg' };
	await open( site );
	await flush();

	const pictures = site.all( '.books-section-row__bg' );

	assert.strictEqual( pictures.length, 2 );
	assert.strictEqual( pictures[ 0 ].src, 'https://example.test/one.jpg' );
	assert.strictEqual( pictures[ 0 ].getAttribute( 'loading' ), 'lazy' );
	assert.strictEqual( pictures[ 0 ].getAttribute( 'decoding' ), 'async' );
	assert.strictEqual( pictures[ 0 ].getAttribute( 'alt' ), '' );
	assert.deepStrictEqual( pictures.map( ( picture ) => picture.closest( '.books-section-row' ).dataset.id ), [ '1', '3' ] );
	assert.strictEqual( pictures[ 0 ].parentNode.firstChild, pictures[ 0 ], 'Behind the rest of the line.' );
	assert.strictEqual( site.all( '.books-section-row' )[ 1 ].querySelector( '.books-section-row__bg' ), null, 'A post without image has none.' );
} );

test( 'the list is usable before the images arrive, and an image does not draw the list again', async () => {
	const site = make( SECTION );
	let release = null;

	site.api.thumbs = ( id, ids ) => {
		site.calls.push( [ 'thumbs', id, ids ] );
		return new Promise( ( resolve ) => {
			release = () => resolve( { thumbs: { 1: 'https://example.test/one.jpg' } } );
		} );
	};
	await open( site );

	assert.strictEqual( site.all( '.books-section-row' ).length, 3, 'The lines are there.' );
	assert.strictEqual( site.all( '.books-section-row__bg' ).length, 0, 'Without an image yet.' );

	click( site.all( '.books-section-row__down' )[ 0 ] );
	await flush();
	assert.deepStrictEqual( site.calls.filter( ( call ) => 'move' === call[ 0 ] ), [ [ 'move', 100, 1, 'down' ] ], 'The buttons work while the images are awaited.' );

	const row = site.all( '.books-section-row' )[ 0 ];

	release();
	await flush();

	assert.strictEqual( site.all( '.books-section-row__bg' ).length, 1 );
	assert.strictEqual( site.all( '.books-section-row' )[ 0 ], row, 'The line is the same node: the list was not drawn again.' );
	assert.strictEqual( row.querySelector( '.books-section-row__bg' ).src, 'https://example.test/one.jpg' );
} );

test( 'the images are asked ten at a time, once, and a failure is not an error of the window', async () => {
	const many = Array.from( { length: 25 }, ( x, index ) => day( index + 1 ) );
	const site = make( { section: true, contents: many } );

	site.context.images = { 25: 'https://example.test/last.jpg' };
	await open( site );
	await flush();
	await flush();
	await flush();

	const asked = site.calls.filter( ( call ) => 'thumbs' === call[ 0 ] );

	assert.deepStrictEqual( asked.map( ( call ) => call[ 2 ].length ), [ 10, 10, 5 ] );
	assert.strictEqual( site.all( '.books-section-row__bg' ).length, 1 );

	await site.model.load();
	await flush();

	assert.strictEqual( site.calls.filter( ( call ) => 'thumbs' === call[ 0 ] ).length, 3, 'Not asked again after a new state.' );

	const failing = make( SECTION );

	failing.context.fail = 'thumbs';
	await open( failing );
	await flush();

	assert.strictEqual( failing.model.getError(), '', 'No error banner for an image.' );
	assert.strictEqual( failing.all( '.books-section-row' ).length, 3 );
	assert.strictEqual( failing.all( '.books-section-row__bg' ).length, 0 );
} );

test( 'the arrows move a post and are disabled at the ends', async () => {
	const site = make( SECTION );
	await open( site );

	assert.strictEqual( site.all( '.books-section-row__up' )[ 0 ].disabled, true );
	assert.strictEqual( site.all( '.books-section-row__down' )[ 2 ].disabled, true );
	assert.strictEqual( site.all( '.books-section-row__up' )[ 1 ].disabled, false );

	click( site.all( '.books-section-row__down' )[ 0 ] );
	click( site.all( '.books-section-row__up' )[ 2 ] );
	await flush();

	assert.deepStrictEqual( site.calls.filter( ( call ) => 'move' === call[ 0 ] ), [ [ 'move', 100, 1, 'down' ], [ 'move', 100, 3, 'up' ] ] );
	assert.strictEqual( site.all( '.books-section-row__up' )[ 0 ].getAttribute( 'aria-label' ), 'Move up' );
	assert.strictEqual( site.all( '.books-section-row__down' )[ 0 ].title, 'Move down' );
} );

test( 'add before and add after open the dialog for the place of the line', async () => {
	const site = make( SECTION );
	await open( site );

	click( site.all( '.books-section-row__before' )[ 1 ] );
	click( site.all( '.books-section-row__after' )[ 2 ] );
	click( site.q( '.books-section__add' ) );

	assert.deepStrictEqual( site.opened, [
		[ { before: 2 }, 'before “Day 2”' ],
		[ { after: 3 }, 'after “Day 3”' ],
		[ {}, '' ],
	] );
	assert.strictEqual( site.all( '.books-section-row__before' )[ 0 ].getAttribute( 'aria-label' ), 'Add posts before' );
	assert.strictEqual( site.all( '.books-section-row__after' )[ 0 ].getAttribute( 'aria-label' ), 'Add posts after' );
} );

test( 'view and edit are links that open in a new tab, the view of a draft being its preview', async () => {
	const site = make( SECTION );
	await open( site );

	const views = site.all( '.books-section-row__view' );
	const edits = site.all( '.books-section-row__edit' );

	assert.deepStrictEqual( views.map( ( node ) => node.getAttribute( 'href' ) ), [ 'https://example.test/?p=1', 'https://example.test/?p=2', 'https://example.test/?p=3&preview=true' ] );
	assert.deepStrictEqual( edits.map( ( node ) => node.getAttribute( 'href' ) ), [ 'https://example.test/edit/1', 'https://example.test/edit/2', 'https://example.test/edit/3' ] );
	[ ...views, ...edits ].forEach( ( node ) => {
		assert.strictEqual( node.getAttribute( 'target' ), '_blank' );
		assert.strictEqual( node.getAttribute( 'rel' ), 'noopener' );
		assert.ok( node.getAttribute( 'aria-label' ) );
	} );
	assert.strictEqual( views[ 0 ].getAttribute( 'aria-label' ), 'View' );
	assert.strictEqual( edits[ 0 ].getAttribute( 'aria-label' ), 'Edit' );
} );

test( 'the print version opens in a new tab from an icon between view and edit', async () => {
	const site = make( SECTION );
	await open( site );

	const prints = site.all( '.books-section-row__print' );

	assert.deepStrictEqual( prints.map( ( node ) => node.getAttribute( 'href' ) ), [ 'https://example.test/?p=1&mode=print', 'https://example.test/?p=2&mode=print', 'https://example.test/?p=3&preview=true&mode=print' ] );
	prints.forEach( ( node ) => {
		assert.strictEqual( node.getAttribute( 'target' ), '_blank' );
		assert.strictEqual( node.getAttribute( 'rel' ), 'noopener' );
		assert.strictEqual( node.getAttribute( 'aria-label' ), 'Open the print version' );
		assert.strictEqual( node.title, 'Open the print version' );
		assert.ok( node.querySelector( '.dashicons-printer' ) );
	} );

	const order = Array.from( site.all( '.books-section-row__tools' )[ 0 ].children ).map( ( node ) => node.className.match( /books-section-row__(\w+)/ )[ 1 ] );

	assert.deepStrictEqual( order, [ 'remove', 'view', 'print', 'edit' ], 'Remove, view, print, edit.' );
} );

test( 'a post with no print version has no such icon', async () => {
	const site = make( { section: true, contents: [ day( 1 ), day( 2, { print: '' } ) ] } );
	await open( site );

	assert.strictEqual( site.all( '.books-section-row__print' ).length, 0 );
	assert.strictEqual( site.all( '.books-section-row__view' ).length, 2, 'The others are there.' );
} );

test( 'a post without link to view or edit has no such icon', async () => {
	const site = make( { section: true, contents: [ day( 1, { view: '', edit: '', print: '' } ) ] } );
	await open( site );

	assert.strictEqual( site.all( '.books-section-row__view' ).length, 0 );
	assert.strictEqual( site.all( '.books-section-row__edit' ).length, 0 );
	assert.strictEqual( site.all( '.books-section-row__remove' ).length, 1 );
} );

test( 'removing asks in the line first: No and Escape cancel, Yes removes', async () => {
	const site = make( SECTION );
	await open( site );

	click( site.all( '.books-section-row__remove' )[ 1 ] );

	assert.strictEqual( site.all( '.books-section-row__ask' ).length, 1 );
	assert.strictEqual( site.all( '.books-section-row__ask' )[ 0 ].textContent, 'Remove from the section?' );
	assert.strictEqual( site.calls.filter( ( call ) => 'remove' === call[ 0 ] ).length, 0, 'Nothing is asked of the server yet.' );

	click( site.q( '.books-section-row__no' ) );

	assert.strictEqual( site.all( '.books-section-row__ask' ).length, 0 );
	assert.strictEqual( site.all( '.books-section-row__remove' ).length, 3 );

	click( site.all( '.books-section-row__remove' )[ 0 ] );
	fire( site.q( '.books-section-window' ), 'keydown', 'KeyboardEvent', { key: 'Escape' } );

	assert.strictEqual( site.all( '.books-section-row__ask' ).length, 0, 'Escape cancels the question first.' );
	assert.strictEqual( site.floating.isOpen(), true, 'And does not close the window.' );

	site.context.state = Object.assign( {}, site.model.getState(), { contents: [ day( 1 ), day( 3 ) ] } );
	click( site.all( '.books-section-row__remove' )[ 1 ] );
	click( site.q( '.books-section-row__yes' ) );
	await flush();

	assert.deepStrictEqual( site.calls[ site.calls.length - 1 ], [ 'remove', 100, 2 ] );
	assert.deepStrictEqual( site.all( '.books-section-row' ).map( ( row ) => row.dataset.id ), [ '1', '3' ] );
	assert.strictEqual( site.all( '.books-section-row__ask' ).length, 0 );
} );

test( 'a question about a post that is gone from the list is dropped', async () => {
	const site = make( SECTION );
	await open( site );

	click( site.all( '.books-section-row__remove' )[ 2 ] );
	site.context.state = Object.assign( {}, site.model.getState(), { contents: [ day( 1 ), day( 2 ) ] } );
	await site.model.load();

	assert.strictEqual( site.all( '.books-section-row__ask' ).length, 0 );
} );

test( 'Reset the order is enabled only when a post was moved; an empty section says so', async () => {
	const site = make( SECTION );
	await open( site );

	assert.strictEqual( site.q( '.books-section__reset' ).disabled, false );
	click( site.q( '.books-section__reset' ) );
	assert.deepStrictEqual( site.calls[ site.calls.length - 1 ], [ 'reset', 100 ] );

	site.context.state = Object.assign( {}, site.model.getState(), { contents: [ day( 1 ) ] } );
	await site.model.load();
	assert.strictEqual( site.q( '.books-section__reset' ).disabled, true );

	site.context.state = Object.assign( {}, site.model.getState(), { contents: [] } );
	await site.model.load();

	assert.strictEqual( site.q( '.books-section-window__empty' ).hidden, false );
	assert.strictEqual( site.q( '.books-section-window__empty' ).textContent, 'This section is empty.' );
	assert.strictEqual( site.all( '.books-section-row' ).length, 0 );
} );

test( 'a refusal of the server is shown in the window and goes with the next request', async () => {
	const site = make( SECTION );
	await open( site );

	site.context.fail = 'move';
	click( site.all( '.books-section-row__down' )[ 0 ] );
	await flush();

	assert.strictEqual( site.q( '.books-section-window .books-section__error' ).hidden, false );
	assert.strictEqual( site.q( '.books-section-window .books-section__error' ).textContent, 'Refused: move' );
	assert.strictEqual( site.all( '.books-section-row' ).length, 3 );

	site.context.fail = null;
	await site.model.load();
	assert.strictEqual( site.q( '.books-section-window .books-section__error' ).hidden, true );
} );

test( 'a change of the state is shown while it is open and nothing is drawn while it is closed', async () => {
	const site = make( SECTION );
	await open( site );
	site.floating.close();

	site.context.state = Object.assign( {}, site.model.getState(), { contents: [ day( 9 ) ] } );
	await site.model.load();

	assert.deepStrictEqual( site.all( '.books-section-row' ).map( ( row ) => row.dataset.id ), [ '1', '2', '3' ], 'Not drawn while closed.' );

	site.floating.open();
	assert.deepStrictEqual( site.all( '.books-section-row' ).map( ( row ) => row.dataset.id ), [ '9' ], 'Drawn when it opens.' );
} );

test( 'Escape closes the window, but not while the dialog is open', async () => {
	const site = make( SECTION );
	await open( site );

	site.dialog.state = true;
	fire( site.q( '.books-section-window' ), 'keydown', 'KeyboardEvent', { key: 'Escape' } );
	assert.strictEqual( site.floating.isOpen(), true );

	site.dialog.state = false;
	fire( site.q( '.books-section-window' ), 'keydown', 'KeyboardEvent', { key: 'a' } );
	assert.strictEqual( site.floating.isOpen(), true );

	fire( site.q( '.books-section-window' ), 'keydown', 'KeyboardEvent', { key: 'Escape' } );
	assert.strictEqual( site.floating.isOpen(), false );
} );

test( 'closing the window closes the dialog', async () => {
	const site = make( SECTION );
	await open( site );

	site.dialog.state = true;
	site.floating.close();

	assert.strictEqual( site.dialog.isOpen(), false );
} );

test( 'the place and the size are those of the browser, or a default, kept in view', async () => {
	const fresh = make( SECTION );
	await open( fresh );

	assert.strictEqual( fresh.q( '.books-section-window' ).style.left, '820px' );
	assert.strictEqual( fresh.q( '.books-section-window' ).style.width, '420px' );

	const stored = make( SECTION, { store: { [ STORAGE_KEY ]: JSON.stringify( { x: 50, y: 60, w: 500, h: 400 } ) } } );
	await open( stored );

	assert.deepStrictEqual( [ 'left', 'top', 'width', 'height' ].map( ( key ) => stored.q( '.books-section-window' ).style[ key ] ), [ '50px', '60px', '500px', '400px' ] );

	const outside = make( SECTION, { store: { [ STORAGE_KEY ]: JSON.stringify( { x: 5000, y: 5000, w: 5000, h: 10 } ) } } );
	await open( outside );

	const style = outside.q( '.books-section-window' ).style;

	assert.strictEqual( style.width, '1272px', 'No wider than the screen.' );
	assert.strictEqual( style.height, '220px', 'Not smaller than the minimum.' );
	assert.ok( parseInt( style.left, 10 ) < 1280 && parseInt( style.top, 10 ) < 800, 'The title bar is in view.' );

	for ( const bad of [ 'not json', JSON.stringify( { x: 1 } ), JSON.stringify( null ) ] ) {
		const odd = make( SECTION, { store: { [ STORAGE_KEY ]: bad } } );
		await open( odd );
		assert.strictEqual( odd.q( '.books-section-window' ).style.width, '420px', 'A bad value is the default: ' + bad );
	}

	const none = make( SECTION, { noStorage: true } );
	await open( none );
	assert.strictEqual( none.q( '.books-section-window' ).style.width, '420px' );
} );

test( 'the title bar moves the window and the place is remembered; the buttons of the bar do not move it', async () => {
	const site = make( SECTION );
	await open( site );

	const bar = site.q( '.books-section-window__bar' );
	const event = ( type, x, y ) => {
		const made = new site.dom.window.Event( type, { bubbles: true, cancelable: true } );
		made.clientX = x;
		made.clientY = y;
		return made;
	};

	bar.dispatchEvent( event( 'pointerdown', 100, 100 ) );
	site.doc.dispatchEvent( event( 'pointermove', 60, 130 ) );

	assert.strictEqual( site.q( '.books-section-window' ).style.left, '780px' );
	assert.strictEqual( site.q( '.books-section-window' ).style.top, '140px' );

	site.doc.dispatchEvent( event( 'pointerup', 60, 130 ) );

	assert.deepStrictEqual( JSON.parse( site.env.store[ STORAGE_KEY ] ), { x: 780, y: 140, w: 420, h: 620 } );

	site.doc.dispatchEvent( event( 'pointermove', 0, 0 ) );
	assert.strictEqual( site.q( '.books-section-window' ).style.left, '780px', 'No longer following the pointer.' );

	const close = site.q( '.books-section-window__close' );
	close.dispatchEvent( event( 'pointerdown', 100, 100 ) );
	site.doc.dispatchEvent( event( 'pointermove', 0, 0 ) );
	assert.strictEqual( site.q( '.books-section-window' ).style.left, '780px', 'A press on the close button is not a move.' );
} );

test( 'a storage that refuses is not an error', async () => {
	const site = make( SECTION, { failStorage: true } );
	await open( site );

	const bar = site.q( '.books-section-window__bar' );
	const made = ( type ) => Object.assign( new site.dom.window.Event( type, { bubbles: true, cancelable: true } ), { clientX: 10, clientY: 10 } );

	bar.dispatchEvent( made( 'pointerdown' ) );
	site.doc.dispatchEvent( made( 'pointerup' ) );

	assert.strictEqual( site.floating.isOpen(), true );
} );

test( 'a size change of the window (its corner) is remembered', async () => {
	let callback = null;
	class Observer {
		constructor( fn ) {
			callback = fn;
		}
		observe() {}
		disconnect() {}
	}
	const site = make( SECTION, { Observer } );
	await open( site );

	const node = site.q( '.books-section-window' );

	Object.defineProperty( node, 'offsetWidth', { configurable: true, value: 600 } );
	Object.defineProperty( node, 'offsetHeight', { configurable: true, value: 450 } );
	callback();

	assert.deepStrictEqual( JSON.parse( site.env.store[ STORAGE_KEY ] ), { x: 820, y: 110, w: 600, h: 450 } );

	callback();
	site.floating.destroy();
} );

test( 'on a narrow screen the window covers the screen and is not moved', async () => {
	const site = make( SECTION, { size: { width: 480, height: 800 } } );
	await open( site );

	assert.ok( site.q( '.books-section-window' ).classList.contains( 'is-full' ) );
	assert.strictEqual( site.q( '.books-section-window' ).style.width, '' );

	const made = ( type, x ) => Object.assign( new site.dom.window.Event( type, { bubbles: true, cancelable: true } ), { clientX: x, clientY: 0 } );

	site.q( '.books-section-window__bar' ).dispatchEvent( made( 'pointerdown', 10 ) );
	site.doc.dispatchEvent( made( 'pointermove', 200 ) );

	assert.strictEqual( site.q( '.books-section-window' ).style.left, '' );

	site.env.size = { width: 1280, height: 800 };
	site.floating.close();
	site.floating.open();

	assert.ok( ! site.q( '.books-section-window' ).classList.contains( 'is-full' ), 'Back to a window on a wide screen.' );
} );

test( 'destroying the window removes it and stops listening to the model', async () => {
	const site = make( SECTION );
	await open( site );

	site.floating.destroy();

	assert.strictEqual( site.q( '.books-section-window' ), null );

	site.context.state = Object.assign( {}, site.model.getState(), { contents: [] } );
	await site.model.load();
} );
