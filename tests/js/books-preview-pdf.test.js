// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 220: the PDF view of the preview panel, run in jsdom with a fake PDF API (no server, no network).
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const geometry = require( '../../plugin/modules/books/editor/preview/geometry.js' );
const { createPanel } = require( '../../plugin/modules/books/editor/preview/panel.js' );

const SETTINGS = {
	page: { width_mm: 148, height_mm: 210, margin_mm: 10 },
	modes: [ { value: 'web', label: 'Web' }, { value: 'print', label: 'Print' } ],
	defaultMode: 'print',
	frameModes: [ 'print' ],
	argument: 'books_preview',
};
const PUBLISHED = { status: 'publish', permalink: 'https://testing.vli.st/post/', previewLink: '' };
const flush = () => new Promise( ( resolve ) => setImmediate( resolve ) );

/** Builds a panel with a PDF API whose answers the test queues. */
function make( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body></body>', { pretendToBeVisual: true } );
	const log = { start: [], status: [], file: [], revoked: [], opened: [], timers: [] };
	const answers = { start: options.start || [ { status: 'queued', elapsed: 0 } ], status: options.status || [], file: options.file || [ { filename: 'post.pdf', base64: Buffer.from( '%PDF-1.7' ).toString( 'base64' ), pages: 2 } ] };
	const next = ( name ) => {
		const answer = answers[ name ].length > 1 ? answers[ name ].shift() : answers[ name ][ 0 ];
		return answer instanceof Error ? Promise.reject( answer ) : Promise.resolve( answer );
	};
	const api = {
		start: ( mode, refresh ) => ( log.start.push( [ mode, refresh ] ), next( 'start' ) ),
		status: ( mode ) => ( log.status.push( mode ), next( 'status' ) ),
		file: ( mode ) => ( log.file.push( mode ), next( 'file' ) ),
	};
	let urls = 0;
	const panel = createPanel( {
		document: dom.window.document,
		geometry,
		settings: SETTINGS,
		getPost: () => PUBLISHED,
		pdf: options.noPdf ? null : { enabled: true, api, createObjectURL: () => 'blob:pdf-' + ++urls, revokeObjectURL: ( url ) => log.revoked.push( url ), openWindow: ( url ) => log.opened.push( url ), giveUpMs: options.giveUpMs },
		setTimeout: ( callback ) => log.timers.push( callback ),
		clearTimeout: () => {},
	} );
	const q = ( selector ) => dom.window.document.querySelector( selector );
	const click = ( selector ) => q( selector ).dispatchEvent( new dom.window.Event( 'click' ) );

	return { dom, panel, log, q, click, text: () => q( '.books-preview__status' ).textContent, runTimer: () => log.timers.pop()() };
}

test( 'without a PDF server there are no views and no buttons', () => {
	const site = make( { noPdf: true } );

	assert.strictEqual( site.q( '.books-preview__views' ), null );
	assert.strictEqual( site.panel.pdfAvailable(), false );
	assert.strictEqual( site.panel.view(), 'web' );
	site.panel.setView( 'pdf' );
	assert.strictEqual( site.panel.view(), 'web', 'the PDF view does not exist' );
} );

test( 'with a PDF server: Web and PDF buttons, Web pressed, no download yet', () => {
	const site = make();

	assert.strictEqual( site.panel.pdfAvailable(), true );
	assert.strictEqual( site.q( '.books-preview__view--web' ).getAttribute( 'aria-pressed' ), 'true' );
	assert.strictEqual( site.q( '.books-preview__view--pdf' ).getAttribute( 'aria-pressed' ), 'false' );
	assert.strictEqual( site.q( '.books-preview__download' ).hidden, true );
	assert.strictEqual( site.q( '.books-preview__open' ).hidden, true );

	site.panel.open();
	assert.ok( site.q( '.books-preview__frame' ), 'the web view loads the page' );
	assert.strictEqual( site.log.start.length, 0, 'and asks nothing of the PDF server' );
} );

test( 'the PDF button asks for the PDF, polls its state, then shows it with the download and open buttons', async () => {
	const site = make( { status: [ { status: 'running', elapsed: 4 }, { status: 'done', elapsed: 21 } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );

	assert.strictEqual( site.panel.view(), 'pdf' );
	assert.strictEqual( site.q( '.books-preview__view--pdf' ).getAttribute( 'aria-pressed' ), 'true' );
	assert.strictEqual( site.q( '.books-preview__frame' ), null, 'the HTML page is removed' );
	assert.strictEqual( site.q( '.books-preview__usable' ).hidden, true );
	assert.deepStrictEqual( site.log.start, [ [ 'print', false ] ] );
	assert.strictEqual( site.text(), 'Generating the PDF…' );

	await flush();
	assert.strictEqual( site.log.timers.length, 1, 'the state is asked again later' );

	site.runTimer();
	await flush();
	assert.deepStrictEqual( site.log.status, [ 'print' ] );
	assert.strictEqual( site.text(), 'Generating the PDF… 4 s' );

	site.runTimer();
	await flush();
	await flush();
	assert.deepStrictEqual( site.log.file, [ 'print' ] );
	assert.strictEqual( site.text(), 'PDF: 2 pages.' );
	assert.strictEqual( site.q( '.books-preview__pdf' ).src, 'blob:pdf-1#zoom=page-fit&pagemode=none' );
	assert.strictEqual( site.q( '.books-preview__download' ).hidden, false );
	assert.strictEqual( site.q( '.books-preview__open' ).hidden, false );
	assert.strictEqual( site.log.timers.length, 0, 'the polling stopped' );
} );

test( 'the PDF has the size of the page frame', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();

	assert.strictEqual( site.q( '.books-preview__pdf' ).style.width, site.q( '.books-preview__page' ).style.width );
	assert.strictEqual( site.q( '.books-preview__pdf' ).style.height, site.q( '.books-preview__page' ).style.height );
} );

test( 'a PDF that is already made is shown at once, with one page counted as singular', async () => {
	const site = make( { start: [ { status: 'done' } ], file: [ { filename: 'one.pdf', base64: Buffer.from( '%PDF' ).toString( 'base64' ), pages: 1 } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();

	assert.strictEqual( site.text(), 'PDF: 1 page.' );
	assert.strictEqual( site.log.status.length, 0 );
} );

test( 'a PDF without a page count says it is ready', async () => {
	const site = make( { start: [ { status: 'done' } ], file: [ { filename: 'x.pdf', base64: Buffer.from( '%PDF' ).toString( 'base64' ), pages: null } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();

	assert.strictEqual( site.text(), 'PDF ready.' );
} );

test( 'download makes a link with the name of the file; open uses the address in a new window', async () => {
	const site = make( { start: [ { status: 'done' } ], file: [ { filename: 'repos.pdf', base64: Buffer.from( '%PDF' ).toString( 'base64' ), pages: 2 } ] } );
	const clicked = [];
	site.dom.window.HTMLAnchorElement.prototype.click = function () {
		clicked.push( [ this.getAttribute( 'href' ), this.getAttribute( 'download' ) ] );
	};

	site.panel.open();
	site.click( '.books-preview__download' );
	site.click( '.books-preview__open' );
	assert.deepStrictEqual( clicked, [], 'nothing before the PDF is ready' );
	assert.deepStrictEqual( site.log.opened, [] );

	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	site.click( '.books-preview__download' );
	site.click( '.books-preview__open' );

	assert.deepStrictEqual( clicked, [ [ 'blob:pdf-1', 'repos.pdf' ] ] );
	assert.deepStrictEqual( site.log.opened, [ 'blob:pdf-1' ] );
	assert.strictEqual( site.dom.window.document.querySelectorAll( 'body > a' ).length, 0, 'the temporary link is removed' );
} );

test( 'a failed conversion shows the message and no PDF', async () => {
	const site = make( { start: [ { status: 'queued' } ], status: [ { status: 'failed', message: 'The server answered with the status 503: deadline' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	site.runTimer();
	await flush();

	assert.match( site.text(), /^The PDF could not be made: The server answered with the status 503/ );
	assert.ok( site.q( '.books-preview__status--over' ) );
	assert.strictEqual( site.q( '.books-preview__pdf' ), null );
	assert.strictEqual( site.q( '.books-preview__download' ).hidden, true );
} );

test( 'an error of the route (a capability, no server) is shown too', async () => {
	const site = make( { start: [ Object.assign( new Error( 'No rendering server is configured.' ), { code: 'books_pdf_disabled' } ) ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();

	assert.match( site.text(), /No rendering server is configured\./ );

	const lost = make( { start: [ { status: 'done' } ], file: [ new Error( 'The PDF is not ready.' ) ] } );
	lost.panel.open();
	lost.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	assert.match( lost.text(), /The PDF is not ready\./ );

	const status = make( { start: [ { status: 'queued' } ], status: [ new Error( 'Sorry, you are not allowed to do that.' ) ] } );
	status.panel.open();
	status.click( '.books-preview__view--pdf' );
	await flush();
	status.runTimer();
	await flush();
	assert.match( status.text(), /not allowed/ );
} );

test( 'a file that cannot be decoded is reported', async () => {
	const site = make( { start: [ { status: 'done' } ] } );
	const original = site.dom.window.atob;

	site.panel.open();
	// The decoder of the test page is replaced by one that fails.
	Object.defineProperty( site.dom.window, 'atob', { configurable: true, value: () => { throw new Error( 'bad' ); } } );
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();

	assert.match( site.text(), /the file could not be read/ );
	assert.strictEqual( site.q( '.books-preview__pdf' ), null );
	Object.defineProperty( site.dom.window, 'atob', { configurable: true, value: original } );
} );

test( 'the panel gives up when the conversion takes too long', async () => {
	const site = make( { start: [ { status: 'queued' } ], status: [ { status: 'running' } ], giveUpMs: -1 } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();

	assert.match( site.text(), /it takes too long: is WP-Cron running\?/ );
	assert.strictEqual( site.log.timers.length, 0, 'no more polling' );
} );

test( 'Refresh in the PDF view makes a new conversion (forced); a refresh after a save is not forced', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	site.click( '.books-preview__refresh' );
	site.panel.refresh();
	site.panel.refresh( true );

	assert.deepStrictEqual( site.log.start, [ [ 'print', false ], [ 'print', true ], [ 'print', false ], [ 'print', true ] ] );
	assert.ok( site.log.revoked.includes( 'blob:pdf-1' ), 'the old address is released' );
} );

test( 'back to Web: the address of the PDF is released, the page is loaded, the buttons are hidden', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	site.click( '.books-preview__view--web' );

	assert.strictEqual( site.panel.view(), 'web' );
	assert.deepStrictEqual( site.log.revoked, [ 'blob:pdf-1' ] );
	assert.strictEqual( site.q( '.books-preview__pdf' ), null );
	assert.ok( site.q( '.books-preview__frame' ) );
	assert.strictEqual( site.q( '.books-preview__usable' ).hidden, false );
	assert.strictEqual( site.q( '.books-preview__download' ).hidden, true );
	assert.strictEqual( site.q( '.books-preview__view--web' ).getAttribute( 'aria-pressed' ), 'true' );
} );

test( 'a change of mode in the PDF view asks for the PDF of that mode', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	site.q( '.books-preview__mode' ).value = 'web';
	site.q( '.books-preview__mode' ).dispatchEvent( new site.dom.window.Event( 'change' ) );
	await flush();

	assert.deepStrictEqual( site.log.start, [ [ 'print', false ], [ 'web', false ] ] );
	assert.strictEqual( site.log.file[ site.log.file.length - 1 ], 'web' );
} );

test( 'closing the panel releases the PDF and stops the polling; an answer that comes late is ignored', async () => {
	const site = make( { start: [ { status: 'queued' } ], status: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	site.click( '.books-preview__close' );
	site.runTimer();
	await flush();

	assert.strictEqual( site.log.status.length, 0, 'the timer of a closed panel does nothing' );
	assert.strictEqual( site.log.file.length, 0 );
	assert.strictEqual( site.q( '.books-preview__pdf' ), null );

	const ready = make( { start: [ { status: 'done' } ] } );
	ready.panel.open();
	ready.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	ready.click( '.books-preview__close' );
	assert.deepStrictEqual( ready.log.revoked, [ 'blob:pdf-1' ] );
} );

test( 'reopening the panel in the PDF view asks again; the stale mark applies to the PDF too', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	site.panel.setStale( true );
	assert.match( site.text(), /PDF: 2 pages\. Out of date: update the post, then refresh\.$/ );
	assert.ok( site.q( '.books-preview__status--stale' ) );

	site.click( '.books-preview__close' );
	site.panel.open();
	assert.strictEqual( site.log.start.length, 2 );
} );

test( 'setMode and setView are safe in the web view and with the same view', () => {
	const site = make();

	site.panel.setView( 'web' );
	site.panel.setMode( 'web' );
	assert.strictEqual( site.panel.view(), 'web' );
	assert.strictEqual( site.log.start.length, 0 );
} );

test( 'a Refresh of the Web view makes the PDF out of date: the next PDF view makes it again', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.click( '.books-preview__view--pdf' );
	await flush();
	await flush();
	site.click( '.books-preview__view--web' );
	assert.deepStrictEqual( site.log.start, [ [ 'print', false ] ], 'going back to Web does not touch the PDF' );

	site.click( '.books-preview__refresh' );
	site.click( '.books-preview__view--pdf' );
	await flush();

	assert.deepStrictEqual( site.log.start, [ [ 'print', false ], [ 'print', true ] ], 'the PDF is asked again, forced' );

	site.click( '.books-preview__view--web' );
	site.click( '.books-preview__view--pdf' );
	await flush();
	assert.deepStrictEqual( site.log.start[ 2 ], [ 'print', false ], 'asked only once: the next switch uses the cache' );
} );

test( 'a refresh that is not forced (after a save) does not make the PDF out of date; a forced one by the program does', async () => {
	const site = make( { start: [ { status: 'done' } ] } );

	site.panel.open();
	site.panel.refresh();
	site.click( '.books-preview__view--pdf' );
	await flush();
	assert.deepStrictEqual( site.log.start, [ [ 'print', false ] ] );

	site.click( '.books-preview__view--web' );
	site.panel.refresh( true );
	site.click( '.books-preview__view--pdf' );
	await flush();
	assert.deepStrictEqual( site.log.start[ 1 ], [ 'print', true ] );
} );

test( 'without a PDF server a Refresh of the Web view has nothing to remember', () => {
	const site = make( { noPdf: true } );

	site.panel.open();
	site.click( '.books-preview__refresh' );
	assert.strictEqual( site.panel.view(), 'web' );
	assert.strictEqual( site.log.start.length, 0 );
} );
