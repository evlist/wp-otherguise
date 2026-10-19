// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 219: the floating panel of the print preview, run in jsdom (the iframe is not loaded for real: its load and its content are faked).
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const geometry = require( '../../plugin/modules/books/editor/preview/geometry.js' );
const { createPanel, STORAGE_KEY } = require( '../../plugin/modules/books/editor/preview/panel.js' );

const SETTINGS = {
	page: { width_mm: 148, height_mm: 210, margin_mm: 10 },
	modes: [ { value: 'web', label: 'Web' }, { value: 'print', label: 'Print' } ],
	defaultMode: 'print',
	frameModes: [ 'print' ],
	argument: 'books_preview',
};
const PUBLISHED = { status: 'publish', permalink: 'https://testing.vli.st/post/', previewLink: 'https://testing.vli.st/?p=1&preview=true' };

/** Builds a panel in a fresh jsdom, with a post and a storage that the test can change. */
function make( extra ) {
	const dom = new JSDOM( '<!doctype html><body></body>', { pretendToBeVisual: true } );
	const context = Object.assign( { post: PUBLISHED, store: {}, closed: 0, timers: [] }, extra );
	const panel = createPanel( {
		document: dom.window.document,
		geometry,
		settings: SETTINGS,
		getPost: () => context.post,
		storage: context.noStorage
			? null
			: {
					getItem: ( key ) => ( key in context.store ? context.store[ key ] : null ),
					setItem: ( key, value ) => {
						if ( context.failStorage ) throw new Error( 'blocked' );
						context.store[ key ] = value;
					},
			  },
		setTimeout: ( callback ) => context.timers.push( callback ),
		clearTimeout: () => {},
		onClose: () => context.closed++,
	} );
	return { dom, panel, context, q: ( selector ) => dom.window.document.querySelector( selector ) };
}

/** Gives the iframe a fake document of a given height (and images), and fires its load event. */
function loaded( dom, panel, height, images ) {
	const frame = panel.iframe();
	Object.defineProperty( frame, 'contentDocument', {
		configurable: true,
		value: { images: images || [], documentElement: { getBoundingClientRect: () => ( { height } ) } },
	} );
	frame.dispatchEvent( new dom.window.Event( 'load' ) );
}

const text = ( q ) => q( '.books-preview__status' ).textContent;

test( 'the panel is built hidden, with the modes, in the body', () => {
	const { panel, q } = make();

	assert.strictEqual( panel.isOpen(), false );
	assert.strictEqual( q( '.books-preview' ).hidden, true );
	assert.deepStrictEqual( [ ...q( '.books-preview__mode' ).options ].map( ( o ) => o.value ), [ 'web', 'print' ] );
	assert.strictEqual( q( '.books-preview__mode' ).value, 'print', 'the default mode is chosen' );
	assert.strictEqual( panel.iframe(), null, 'nothing is loaded while it is closed' );
} );

test( 'opening loads the page in print mode, at the usable size, scaled', () => {
	const { panel, q } = make();

	panel.open();

	const frame = panel.iframe();
	assert.strictEqual( panel.isOpen(), true );
	assert.strictEqual( q( '.books-preview' ).hidden, false );
	assert.strictEqual( frame.src, 'https://testing.vli.st/post/?mode=print&books_preview=1' );
	assert.strictEqual( frame.style.width, geometry.mmToPx( 128 ) + 'px', 'laid out between the margins' );
	assert.strictEqual( frame.style.height, geometry.mmToPx( 190 ) + 'px' );
	assert.match( frame.style.transform, /^scale\(0\.\d+\)$/ );
	assert.ok( q( '.books-preview__page--framed' ), 'the page frame is drawn for print' );
	assert.strictEqual( text( q ), 'Measuring…' );
} );

test( 'the verdict: fits, overflows, height unknown', () => {
	const { dom, panel, q } = make();

	panel.open();
	loaded( dom, panel, geometry.mmToPx( 150 ) );
	assert.match( text( q ), /^Fits in the page\. \(15[01](\.\d)? \/ 190 mm\)$/ );
	assert.ok( ! q( '.books-preview__status--over' ) );

	panel.refresh();
	loaded( dom, panel, 800 );
	assert.match( text( q ), /^Overflows the page by 21\.7 mm\. \(211\.7 \/ 190 mm\)$/ );
	assert.ok( q( '.books-preview__status--over' ) );

	panel.refresh();
	loaded( dom, panel, 0 );
	assert.strictEqual( text( q ), 'Height unknown.' );
} );

test( 'a page that cannot be read (another origin) gives an unknown height, not an error', () => {
	const { dom, panel, q } = make();

	panel.open();
	Object.defineProperty( panel.iframe(), 'contentDocument', {
		configurable: true,
		get() {
			throw new Error( 'blocked' );
		},
	} );
	panel.iframe().dispatchEvent( new dom.window.Event( 'load' ) );

	assert.strictEqual( text( q ), 'Height unknown.' );
} );

test( 'the measure waits for the images, or for the time limit', () => {
	const { dom, panel, context, q } = make();
	const listeners = {};
	const image = { complete: false, addEventListener: ( name, fn ) => ( listeners[ name ] = fn ) };

	panel.open();
	loaded( dom, panel, geometry.mmToPx( 150 ), [ { complete: true }, image ] );
	assert.strictEqual( text( q ), 'Measuring…', 'an image is still loading' );

	listeners.load();
	assert.match( text( q ), /^Fits/ );

	panel.refresh();
	loaded( dom, panel, geometry.mmToPx( 215 ), [ image ] );
	assert.strictEqual( text( q ), 'Measuring…' );
	context.timers[ context.timers.length - 1 ]();
	assert.match( text( q ), /^Overflows/, 'the time limit measures what is there' );
} );

test( 'a load that comes after a refresh is ignored', () => {
	const { dom, panel, q } = make();

	panel.open();
	const old = panel.iframe();
	panel.refresh();
	assert.notStrictEqual( panel.iframe(), old, 'a new iframe, so that the page is fetched again' );

	Object.defineProperty( old, 'contentDocument', { value: { images: [], documentElement: { getBoundingClientRect: () => ( { height: 9999 } ) } } } );
	old.dispatchEvent( new dom.window.Event( 'load' ) );

	assert.strictEqual( text( q ), 'Measuring…', 'the old frame does not answer' );
} );

test( 'out of date: the mark is shown while the post is dirty, and goes with the flag', () => {
	const { dom, panel, q } = make();

	panel.open();
	loaded( dom, panel, geometry.mmToPx( 150 ) );
	panel.setStale( true );
	assert.match( text( q ), /Out of date: update the post, then refresh\.$/ );
	assert.ok( q( '.books-preview__status--stale' ) );

	panel.setStale( false );
	assert.ok( ! /Out of date/.test( text( q ) ) );
} );

test( 'a post with nothing to show says so, and loads nothing', () => {
	const { panel, q } = make( { post: { status: 'draft' } } );

	panel.open();

	assert.strictEqual( panel.iframe(), null );
	assert.match( text( q ), /^Nothing to preview yet/ );
} );

test( 'a draft shows the preview link of the editor', () => {
	const { panel } = make( { post: { status: 'draft', previewLink: 'https://testing.vli.st/?p=5&preview=true&preview_nonce=abc' } } );

	panel.open();

	assert.strictEqual( panel.iframe().src, 'https://testing.vli.st/?p=5&preview=true&preview_nonce=abc&mode=print&books_preview=1' );
} );

test( 'another mode without a frame: no page frame and no verdict, the page still loads', () => {
	const { dom, panel, q } = make();

	panel.open();
	q( '.books-preview__mode' ).value = 'web';
	q( '.books-preview__mode' ).dispatchEvent( new dom.window.Event( 'change' ) );

	assert.strictEqual( panel.mode(), 'web' );
	assert.strictEqual( panel.iframe().src, 'https://testing.vli.st/post/?mode=web&books_preview=1' );
	assert.ok( ! q( '.books-preview__page--framed' ) );
	assert.strictEqual( panel.iframe().style.width, '1024px' );

	loaded( dom, panel, 5000 );
	assert.strictEqual( text( q ), '', 'no verdict where there is no page' );

	panel.setMode( 'print' );
	assert.strictEqual( q( '.books-preview__mode' ).value, 'print' );
	assert.ok( q( '.books-preview__page--framed' ) );
} );

test( 'the Refresh button loads the page again; the close button closes and tells the editor', () => {
	const { dom, panel, context, q } = make();

	panel.open();
	const first = panel.iframe();
	q( '.books-preview__refresh' ).dispatchEvent( new dom.window.Event( 'click' ) );
	assert.notStrictEqual( panel.iframe(), first );

	q( '.books-preview__close' ).dispatchEvent( new dom.window.Event( 'click' ) );
	assert.strictEqual( panel.isOpen(), false );
	assert.strictEqual( q( '.books-preview' ).hidden, true );
	assert.strictEqual( panel.iframe(), null );
	assert.strictEqual( context.closed, 1 );

	panel.refresh();
	assert.strictEqual( panel.iframe(), null, 'refreshing a closed panel loads nothing' );
} );

test( 'toggle opens then closes; destroy removes the panel', () => {
	const { dom, panel, q } = make();

	panel.toggle();
	assert.strictEqual( panel.isOpen(), true );
	panel.toggle();
	assert.strictEqual( panel.isOpen(), false );

	panel.destroy();
	assert.strictEqual( q( '.books-preview' ), null );
	assert.strictEqual( dom.window.document.body.children.length, 0 );
} );

/** Fires a pointer event on a target. */
function pointer( dom, target, type, x, y ) {
	const event = new dom.window.Event( type, { bubbles: true, cancelable: true } );
	event.clientX = x;
	event.clientY = y;
	event.button = 0;
	target.dispatchEvent( event );
}

test( 'the panel moves by its title bar, and the position is remembered', () => {
	const { dom, panel, context, q } = make();

	panel.open();
	const left = parseFloat( q( '.books-preview' ).style.left );
	const top = parseFloat( q( '.books-preview' ).style.top );

	pointer( dom, q( '.books-preview__title' ), 'pointerdown', 100, 100 );
	pointer( dom, dom.window.document, 'pointermove', 60, 130 );
	pointer( dom, dom.window.document, 'pointerup', 60, 130 );

	assert.strictEqual( parseFloat( q( '.books-preview' ).style.left ), left - 40 );
	assert.strictEqual( parseFloat( q( '.books-preview' ).style.top ), top + 30 );
	assert.strictEqual( JSON.parse( context.store[ STORAGE_KEY ] ).x, left - 40 );

	pointer( dom, dom.window.document, 'pointermove', 0, 0 );
	assert.strictEqual( parseFloat( q( '.books-preview' ).style.left ), left - 40, 'the move ended with the button' );
} );

test( 'the controls of the title bar do not start a move', () => {
	const { dom, panel, q } = make();

	panel.open();
	const left = q( '.books-preview' ).style.left;

	pointer( dom, q( '.books-preview__refresh' ), 'pointerdown', 100, 100 );
	pointer( dom, dom.window.document, 'pointermove', 10, 10 );

	assert.strictEqual( q( '.books-preview' ).style.left, left );
} );

test( 'the panel is kept inside the window', () => {
	const { dom, panel, q } = make();

	panel.open();
	pointer( dom, q( '.books-preview__title' ), 'pointerdown', 100, 100 );
	pointer( dom, dom.window.document, 'pointermove', -5000, -5000 );
	pointer( dom, dom.window.document, 'pointerup', -5000, -5000 );

	assert.strictEqual( parseFloat( q( '.books-preview' ).style.left ), 0 );
	assert.strictEqual( parseFloat( q( '.books-preview' ).style.top ), 0 );
} );

test( 'the handle resizes the panel, the page keeps its proportion, within limits', () => {
	const { dom, panel, q } = make();

	panel.open();
	const frame = () => parseFloat( q( '.books-preview__page' ).style.width ) / parseFloat( q( '.books-preview__page' ).style.height );
	const ratio = 148 / 210;

	pointer( dom, q( '.books-preview__resize' ), 'pointerdown', 0, 0 );
	pointer( dom, dom.window.document, 'pointermove', 100, 0 );
	pointer( dom, dom.window.document, 'pointerup', 100, 0 );

	assert.strictEqual( q( '.books-preview' ).style.width, '440px' );
	assert.ok( Math.abs( frame() - ratio ) < 0.001 );

	pointer( dom, q( '.books-preview__resize' ), 'pointerdown', 0, 0 );
	pointer( dom, dom.window.document, 'pointermove', 5000, 0 );
	pointer( dom, dom.window.document, 'pointerup', 5000, 0 );
	assert.strictEqual( q( '.books-preview' ).style.width, '900px' );

	pointer( dom, q( '.books-preview__resize' ), 'pointerdown', 0, 0 );
	pointer( dom, dom.window.document, 'pointermove', -5000, 0 );
	pointer( dom, dom.window.document, 'pointerup', -5000, 0 );
	assert.strictEqual( q( '.books-preview' ).style.width, '200px' );
} );

test( 'a saved position and size are restored; garbage and a blocked storage are ignored', () => {
	const saved = make( { store: { [ STORAGE_KEY ]: JSON.stringify( { x: 12, y: 34, width: 500 } ) } } );
	saved.panel.open();
	assert.strictEqual( saved.q( '.books-preview' ).style.left, '12px' );
	assert.strictEqual( saved.q( '.books-preview' ).style.top, '34px' );
	assert.strictEqual( saved.q( '.books-preview' ).style.width, '500px' );

	const garbage = make( { store: { [ STORAGE_KEY ]: '{not json' } } );
	garbage.panel.open();
	assert.strictEqual( garbage.q( '.books-preview' ).style.width, '340px', 'the default width' );

	const blocked = make( { failStorage: true } );
	blocked.panel.open();
	pointer( blocked.dom, blocked.q( '.books-preview__title' ), 'pointerdown', 10, 10 );
	pointer( blocked.dom, blocked.dom.window.document, 'pointermove', 20, 20 );
	pointer( blocked.dom, blocked.dom.window.document, 'pointerup', 20, 20 );
	assert.strictEqual( blocked.panel.isOpen(), true, 'it works without a storage' );

	const none = make( { noStorage: true } );
	none.panel.open();
	assert.strictEqual( none.panel.isOpen(), true );
} );
