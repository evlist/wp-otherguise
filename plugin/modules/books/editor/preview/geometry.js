/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 219: the pure functions of the print preview panel (sizes, verdict, address of the iframe, when to reload).
 * Plain JavaScript, no build step. Exposed as window.booksPreviewGeometry, and as a CommonJS module for the tests.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksPreviewGeometry = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	// CSS pixels per millimetre (96 dpi).
	var PX_PER_MM = 96 / 25.4;

	function round1( value ) {
		return Math.round( value * 10 ) / 10;
	}

	function mmToPx( mm ) {
		return mm * PX_PER_MM;
	}

	function pxToMm( px ) {
		return px / PX_PER_MM;
	}

	/**
	 * The drawing of the page, scaled to fit in a box.
	 *
	 * page: { width_mm, height_mm, margin_mm }; box: { width, height } in pixels (the room of the panel).
	 * Returns the scale, the frame of the page, the usable part inside it (all scaled, in pixels) and the size at which the iframe is laid
	 * out (not scaled, in pixels: the usable part of the page, so that the page lays out as it would between the margins).
	 */
	function layout( page, box ) {
		var pageW = mmToPx( page.width_mm );
		var pageH = mmToPx( page.height_mm );
		var margin = mmToPx( page.margin_mm );
		var scale = Math.min( 1, box.width / pageW, box.height / pageH );
		var usableW = pageW - 2 * margin;
		var usableH = pageH - 2 * margin;

		return {
			scale: scale,
			frame: { width: pageW * scale, height: pageH * scale },
			usable: { x: margin * scale, y: margin * scale, width: usableW * scale, height: usableH * scale },
			iframe: { width: usableW, height: usableH },
		};
	}

	/**
	 * What the measure of the height of the content says about the usable part of the page.
	 *
	 * contentPx: the height of the document in the iframe, in pixels, or a non-positive / non-finite value when it is not known.
	 * Returns null when the height is not known; else { known, fits, overflowMm, contentMm, usableMm }. An indication only: it is not the
	 * pagination of a print engine.
	 */
	function verdict( contentPx, page ) {
		if ( typeof contentPx !== 'number' || ! isFinite( contentPx ) || contentPx <= 0 ) {
			return null;
		}

		var usableMm = page.height_mm - 2 * page.margin_mm;
		var contentMm = pxToMm( contentPx );
		var overflow = round1( contentMm - usableMm );

		return { fits: overflow <= 0, overflowMm: overflow > 0 ? overflow : 0, contentMm: round1( contentMm ), usableMm: round1( usableMm ) };
	}

	/**
	 * The address shown in the iframe: the permalink of a published post, the preview link of the editor for the others, with the mode
	 * and the argument that tells the page it is in the preview. Empty when there is nothing to show.
	 */
	function previewUrl( post, mode, argument, project ) {
		var base = 'publish' === post.status ? post.permalink : post.previewLink;

		if ( ! base ) {
			return '';
		}

		var url = new URL( base, 'http://example.invalid' );
		var relative = ! /^[a-z][a-z0-9+.-]*:/i.test( base );

		if ( mode ) {
			url.searchParams.set( 'mode', mode );
		}
		url.searchParams.set( argument, '1' );

		// The book project whose rules the page takes (slice 230): the server chooses the first one that covers the post when there is none.
		if ( project > 0 ) {
			url.searchParams.set( 'books_project', String( project ) );
		}

		return relative ? url.pathname + url.search + url.hash : url.toString();
	}

	/**
	 * Tells whether the page of the iframe must be loaded again: a save has just ended without failing. An autosave does not count: it
	 * does not change the page that the address shows.
	 *
	 * previous and next: { saving, autosaving, succeeded }.
	 */
	function savedJustNow( previous, next ) {
		return !! previous.saving && ! previous.autosaving && ! next.saving && !! next.succeeded;
	}

	/**
	 * A mode has a frame (the page drawn, the verdict) when it is one of the modes of the setting.
	 */
	function hasFrame( mode, frameModes ) {
		return Array.isArray( frameModes ) && frameModes.indexOf( mode ) !== -1;
	}

	return { PX_PER_MM: PX_PER_MM, mmToPx: mmToPx, pxToMm: pxToMm, layout: layout, verdict: verdict, previewUrl: previewUrl, savedJustNow: savedJustNow, hasFrame: hasFrame };
} ) );
