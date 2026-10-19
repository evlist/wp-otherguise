// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 219: the pure functions of the print preview panel.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const g = require( '../../plugin/modules/books/editor/preview/geometry.js' );

const A5 = { width_mm: 148, height_mm: 210, margin_mm: 10 };

test( 'millimetres and pixels convert both ways at 96 dpi', () => {
	assert.ok( Math.abs( g.mmToPx( 25.4 ) - 96 ) < 1e-9 );
	assert.ok( Math.abs( g.pxToMm( 96 ) - 25.4 ) < 1e-9 );
	assert.ok( Math.abs( g.pxToMm( g.mmToPx( 148 ) ) - 148 ) < 1e-9 );
} );

test( 'the layout lays the iframe out at the usable size and scales the frame to the box', () => {
	const l = g.layout( A5, { width: 280, height: 10000 } );
	const pageW = g.mmToPx( 148 );

	assert.ok( Math.abs( l.scale - 280 / pageW ) < 1e-9, 'limited by the width' );
	assert.ok( Math.abs( l.frame.width - 280 ) < 1e-9 );
	assert.ok( Math.abs( l.frame.height - g.mmToPx( 210 ) * l.scale ) < 1e-9, 'the proportion of the page is kept' );
	assert.ok( Math.abs( l.iframe.width - g.mmToPx( 128 ) ) < 1e-9, 'the iframe has the width between the margins' );
	assert.ok( Math.abs( l.iframe.height - g.mmToPx( 190 ) ) < 1e-9 );
	assert.ok( Math.abs( l.usable.x - g.mmToPx( 10 ) * l.scale ) < 1e-9 );
	assert.ok( Math.abs( l.usable.width - l.iframe.width * l.scale ) < 1e-9 );
} );

test( 'the page is never enlarged, and the height can limit the scale', () => {
	assert.strictEqual( g.layout( A5, { width: 5000, height: 5000 } ).scale, 1 );

	const l = g.layout( A5, { width: 5000, height: 400 } );
	assert.ok( Math.abs( l.scale - 400 / g.mmToPx( 210 ) ) < 1e-9 );
	assert.ok( Math.abs( l.frame.height - 400 ) < 1e-9 );
} );

test( 'a zero margin makes the usable part the whole page', () => {
	const l = g.layout( { width_mm: 100, height_mm: 100, margin_mm: 0 }, { width: 10000, height: 10000 } );

	assert.strictEqual( l.usable.x, 0 );
	assert.ok( Math.abs( l.usable.width - l.frame.width ) < 1e-9 );
} );

test( 'the verdict: fits, exactly full, overflows, and unknown', () => {
	const usable = g.mmToPx( 190 );
	const fits = g.verdict( usable - 20, A5 );
	const full = g.verdict( usable, A5 );
	const over = g.verdict( usable + g.mmToPx( 25 ), A5 );

	assert.strictEqual( fits.fits, true );
	assert.strictEqual( fits.overflowMm, 0 );
	assert.strictEqual( full.fits, true );
	assert.strictEqual( full.usableMm, 190 );
	assert.strictEqual( over.fits, false );
	assert.strictEqual( over.overflowMm, 25 );
	assert.strictEqual( over.contentMm, 215 );

	for ( const bad of [ 0, -5, NaN, Infinity, null, undefined, '300' ] ) {
		assert.strictEqual( g.verdict( bad, A5 ), null, 'unknown height: ' + String( bad ) );
	}
} );

test( 'the address: the permalink of a published post, the preview link of the others', () => {
	const published = { status: 'publish', permalink: 'https://testing.vli.st/2025/10/04/post/', previewLink: 'https://testing.vli.st/?p=5&preview=true' };
	const draft = { status: 'draft', permalink: 'https://testing.vli.st/?p=5', previewLink: 'https://testing.vli.st/?p=5&preview=true&preview_nonce=abc' };

	assert.strictEqual( g.previewUrl( published, 'print', 'books_preview' ), 'https://testing.vli.st/2025/10/04/post/?mode=print&books_preview=1' );
	assert.strictEqual(
		g.previewUrl( draft, 'print', 'books_preview' ),
		'https://testing.vli.st/?p=5&preview=true&preview_nonce=abc&mode=print&books_preview=1'
	);
} );

test( 'the address keeps the other arguments, replaces a mode that is already there, and accepts a relative link', () => {
	assert.strictEqual(
		g.previewUrl( { status: 'publish', permalink: 'https://x.test/p/?a=1&mode=web#top' }, 'print', 'books_preview' ),
		'https://x.test/p/?a=1&mode=print&books_preview=1#top'
	);
	assert.strictEqual( g.previewUrl( { status: 'publish', permalink: '/p/?a=1' }, 'print', 'books_preview' ), '/p/?a=1&mode=print&books_preview=1' );
	assert.strictEqual( g.previewUrl( { status: 'publish', permalink: 'https://x.test/p/' }, '', 'books_preview' ), 'https://x.test/p/?books_preview=1', 'no mode: none added' );
} );

test( 'no address when there is nothing to show', () => {
	assert.strictEqual( g.previewUrl( { status: 'publish' }, 'print', 'books_preview' ), '' );
	assert.strictEqual( g.previewUrl( { status: 'draft', permalink: 'https://x.test/p/' }, 'print', 'books_preview' ), '' );
} );

test( 'the page is loaded again when a save ends without failing, not for an autosave, not for a failure', () => {
	assert.strictEqual( g.savedJustNow( { saving: true, autosaving: false }, { saving: false, succeeded: true } ), true );
	assert.strictEqual( g.savedJustNow( { saving: true, autosaving: true }, { saving: false, succeeded: true } ), false );
	assert.strictEqual( g.savedJustNow( { saving: true, autosaving: false }, { saving: false, succeeded: false } ), false );
	assert.strictEqual( g.savedJustNow( { saving: true, autosaving: false }, { saving: true, succeeded: true } ), false );
	assert.strictEqual( g.savedJustNow( { saving: false, autosaving: false }, { saving: false, succeeded: true } ), false );
} );

test( 'a mode has a frame when it is in the list', () => {
	assert.strictEqual( g.hasFrame( 'print', [ 'print' ] ), true );
	assert.strictEqual( g.hasFrame( 'web', [ 'print' ] ), false );
	assert.strictEqual( g.hasFrame( 'print', undefined ), false );
} );
