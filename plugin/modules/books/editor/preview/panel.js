/**
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Slice 219: the floating panel of the print preview, in plain DOM (no React, no build step) so that it can be tested in jsdom.
 * preview.js connects it to the editor. Exposed as window.booksPreviewPanel.createPanel, and as a CommonJS module for the tests.
 *
 * The panel shows the page in an iframe, at the size of the usable part of the page (the page minus the margins), scaled to fit.
 * What it measures is an indication, not the pagination of a print engine. When a PDF server is configured (slice 220), a "PDF" view
 * shows the PDF that the server made instead of the HTML page, with a download and an "open in a new tab" button.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.booksPreviewPanel = api;
	}
}( typeof window !== 'undefined' ? window : null, function () {
	var MIN_WIDTH = 200;
	var MAX_WIDTH = 900;
	var DEFAULT_WIDTH = 340;
	// Layout size of the iframe of a mode that has no page frame.
	var UNFRAMED = { width: 1024, height: 768 };
	var IMAGES_TIMEOUT = 3000;
	var STORAGE_KEY = 'books_preview_panel';
	// Time between two questions about the state of the PDF, and time after which the panel gives up, in milliseconds.
	var POLL_MS = 2000;
	var GIVE_UP_MS = 600000;

	function clamp( value, min, max ) {
		return Math.min( Math.max( value, min ), max );
	}

	/**
	 * Builds the panel.
	 *
	 * options: document, geometry, settings ({ page, modes, defaultMode, frameModes, argument }), getPost (returns { status, permalink,
	 * previewLink }), __ (translation), storage (getItem/setItem, optional), setTimeout/clearTimeout (optional), onClose (optional).
	 */
	function createPanel( options ) {
		var doc = options.document;
		var geo = options.geometry;
		var settings = options.settings;
		var __ = options.__ || function ( text ) {
			return text;
		};
		var storage = options.storage || null;
		var later = options.setTimeout || function ( callback, ms ) {
			return setTimeout( callback, ms );
		};
		var cancel = options.clearTimeout || function ( id ) {
			clearTimeout( id );
		};

		var pdfOptions = options.pdf && options.pdf.enabled && options.pdf.api ? options.pdf : null;
		var win = doc.defaultView;
		var state = {
			open: false,
			mode: settings.defaultMode,
			stale: false,
			measured: undefined,
			measuring: false,
			width: DEFAULT_WIDTH,
			x: null,
			y: null,
			view: 'web',
			pdfText: '',
			pdfUrl: '',
			pdfName: '',
			pdfPages: null,
			pdfPreview: false,
			// A forced refresh of the HTML view means that what the page shows has changed (a photo, a change that the key of the cache cannot
			// see): the PDF that was made is then out of date, and the next time it is asked for it is made again.
			pdfForce: false,
		};
		var pdfFrame = null;
		var generation = 0;
		var timer = null;
		var iframe = null;

		function el( tag, className, text ) {
			var node = doc.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			if ( undefined !== text ) {
				node.textContent = text;
			}
			return node;
		}

		var root = el( 'div', 'books-preview' );
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-label', __( 'Print preview', 'otherguise' ) );
		root.hidden = true;

		var bar = el( 'div', 'books-preview__bar' );
		var title = el( 'strong', 'books-preview__title', __( 'Print preview', 'otherguise' ) );
		var select = el( 'select', 'books-preview__mode' );
		select.setAttribute( 'aria-label', __( 'Mode', 'otherguise' ) );
		( settings.modes || [] ).forEach( function ( mode ) {
			var option = el( 'option', '', mode.label );
			option.value = mode.value;
			select.appendChild( option );
		} );
		select.value = state.mode;
		var refreshButton = el( 'button', 'books-preview__refresh button button-small', __( 'Refresh', 'otherguise' ) );
		refreshButton.type = 'button';
		var closeButton = el( 'button', 'books-preview__close', '×' );
		closeButton.type = 'button';
		closeButton.setAttribute( 'aria-label', __( 'Close', 'otherguise' ) );
		bar.appendChild( title );
		bar.appendChild( select );
		bar.appendChild( refreshButton );
		bar.appendChild( closeButton );

		// The views: the HTML page or, when a PDF server is configured, the PDF (slice 220).
		var views = null;
		var webButton = null;
		var pdfButton = null;
		var downloadButton = null;
		var openButton = null;
		if ( pdfOptions ) {
			views = el( 'div', 'books-preview__views' );
			webButton = el( 'button', 'books-preview__view books-preview__view--web button button-small', __( 'Web', 'otherguise' ) );
			pdfButton = el( 'button', 'books-preview__view books-preview__view--pdf button button-small', __( 'PDF', 'otherguise' ) );
			downloadButton = el( 'button', 'books-preview__download button button-small', __( 'Download', 'otherguise' ) );
			openButton = el( 'button', 'books-preview__open button button-small', __( 'Open in a new tab', 'otherguise' ) );
			[ webButton, pdfButton, downloadButton, openButton ].forEach( function ( button ) {
				button.type = 'button';
				views.appendChild( button );
			} );
			webButton.setAttribute( 'aria-pressed', 'true' );
			pdfButton.setAttribute( 'aria-pressed', 'false' );
			downloadButton.hidden = true;
			openButton.hidden = true;
		}

		var status = el( 'div', 'books-preview__status' );
		status.setAttribute( 'aria-live', 'polite' );
		var stage = el( 'div', 'books-preview__stage' );
		var page = el( 'div', 'books-preview__page' );
		var usable = el( 'div', 'books-preview__usable' );
		page.appendChild( usable );
		stage.appendChild( page );
		var handle = el( 'div', 'books-preview__resize' );
		handle.setAttribute( 'aria-hidden', 'true' );

		root.appendChild( bar );
		if ( views ) {
			root.appendChild( views );
		}
		root.appendChild( status );
		root.appendChild( stage );
		root.appendChild( handle );

		function framed() {
			return geo.hasFrame( state.mode, settings.frameModes );
		}

		function remember() {
			if ( ! storage ) {
				return;
			}
			try {
				storage.setItem( STORAGE_KEY, JSON.stringify( { x: state.x, y: state.y, width: state.width } ) );
			} catch ( e ) {
				// Storage can be unavailable: the panel works without it.
			}
		}

		function restore() {
			if ( ! storage ) {
				return;
			}
			try {
				var saved = JSON.parse( storage.getItem( STORAGE_KEY ) || 'null' );
				if ( saved && 'object' === typeof saved ) {
					if ( isFinite( saved.width ) ) {
						state.width = clamp( saved.width, MIN_WIDTH, MAX_WIDTH );
					}
					if ( isFinite( saved.x ) && isFinite( saved.y ) ) {
						state.x = saved.x;
						state.y = saved.y;
					}
				}
			} catch ( e ) {
				// Unreadable: the defaults stay.
			}
		}

		function view() {
			var win = doc.defaultView;
			return { width: win && win.innerWidth ? win.innerWidth : 1280, height: win && win.innerHeight ? win.innerHeight : 800 };
		}

		function place() {
			var size = view();
			var x = null === state.x ? size.width - state.width - 40 : state.x;
			var y = null === state.y ? 80 : state.y;

			state.x = clamp( x, 0, Math.max( 0, size.width - 80 ) );
			state.y = clamp( y, 0, Math.max( 0, size.height - 40 ) );
			root.style.left = state.x + 'px';
			root.style.top = state.y + 'px';
			root.style.width = state.width + 'px';
		}

		function draw() {
			var box = { width: state.width - 16, height: Math.max( 200, view().height - 220 ) };
			var shape;

			if ( framed() ) {
				shape = geo.layout( settings.page, box );
			} else {
				var scale = Math.min( 1, box.width / UNFRAMED.width, box.height / UNFRAMED.height );
				shape = {
					scale: scale,
					frame: { width: UNFRAMED.width * scale, height: UNFRAMED.height * scale },
					usable: { x: 0, y: 0, width: UNFRAMED.width * scale, height: UNFRAMED.height * scale },
					iframe: UNFRAMED,
				};
			}

			page.className = 'books-preview__page' + ( framed() ? ' books-preview__page--framed' : '' );
			page.style.width = shape.frame.width + 'px';
			page.style.height = shape.frame.height + 'px';
			usable.style.left = shape.usable.x + 'px';
			usable.style.top = shape.usable.y + 'px';
			usable.style.width = shape.usable.width + 'px';
			usable.style.height = shape.usable.height + 'px';

			usable.hidden = 'pdf' === state.view;

			if ( iframe ) {
				iframe.style.width = shape.iframe.width + 'px';
				iframe.style.height = shape.iframe.height + 'px';
				iframe.style.transform = 'scale(' + shape.scale + ')';
			}

			// The PDF viewer of the browser fills the whole page frame: the PDF has its own margins.
			if ( pdfFrame ) {
				pdfFrame.style.width = shape.frame.width + 'px';
				pdfFrame.style.height = shape.frame.height + 'px';
			}
		}

		function say() {
			if ( 'pdf' === state.view ) {
				sayPdf();
				return;
			}

			var text;
			var post = options.getPost();
			var url = geo.previewUrl( post, state.mode, settings.argument );
			var parts = [];

			if ( '' === url ) {
				text = __( 'Nothing to preview yet: save the post first.', 'otherguise' );
			} else if ( state.measuring ) {
				text = __( 'Measuring…', 'otherguise' );
			} else if ( ! framed() ) {
				text = '';
			} else if ( null === state.measured || undefined === state.measured ) {
				text = __( 'Height unknown.', 'otherguise' );
			} else if ( state.measured.fits ) {
				text = __( 'Fits in the page.', 'otherguise' ) + ' (' + state.measured.contentMm + ' / ' + state.measured.usableMm + ' mm)';
			} else {
				text = __( 'Overflows the page by', 'otherguise' ) + ' ' + state.measured.overflowMm + ' mm. (' + state.measured.contentMm + ' / ' + state.measured.usableMm + ' mm)';
			}

			if ( '' !== text ) {
				parts.push( text );
			}
			if ( state.stale && '' !== url ) {
				parts.push( __( 'Out of date: update the post, then refresh.', 'otherguise' ) );
			}

			status.textContent = parts.join( ' ' );
			status.className = 'books-preview__status' + ( state.measured && ! state.measured.fits && framed() ? ' books-preview__status--over' : '' ) + ( state.stale ? ' books-preview__status--stale' : '' );
		}

		// Reads the height of the page in the iframe, once its images have loaded or after a time limit.
		function measure( frame, id ) {
			var finished = false;
			var pending = [];

			function finish() {
				if ( finished || id !== generation ) {
					return;
				}
				finished = true;
				if ( null !== timer ) {
					cancel( timer );
					timer = null;
				}

				var height = null;
				try {
					var inner = frame.contentDocument;
					height = Math.ceil( inner.documentElement.getBoundingClientRect().height );
				} catch ( e ) {
					height = null;
				}

				state.measuring = false;
				state.measured = geo.verdict( height, settings.page );
				say();
			}

			try {
				var images = frame.contentDocument.images || [];
				for ( var i = 0; i < images.length; i++ ) {
					if ( ! images[ i ].complete ) {
						pending.push( images[ i ] );
					}
				}
			} catch ( e ) {
				pending = [];
			}

			var waiting = pending.length;
			pending.forEach( function ( image ) {
				var done = function () {
					waiting--;
					if ( waiting <= 0 ) {
						finish();
					}
				};
				image.addEventListener( 'load', done );
				image.addEventListener( 'error', done );
			} );

			if ( 0 === waiting ) {
				finish();
			} else {
				timer = later( finish, IMAGES_TIMEOUT );
			}
		}

		// Loads the page again in a new iframe, so that the browser really fetches it.
		function load( force ) {
			if ( 'pdf' === state.view ) {
				loadPdf( force );
				return;
			}
			if ( pdfOptions ) {
				releasePdf();
				if ( force ) {
					state.pdfForce = true;
				}
			}

			generation++;
			var id = generation;
			var url = geo.previewUrl( options.getPost(), state.mode, settings.argument );

			if ( null !== timer ) {
				cancel( timer );
				timer = null;
			}
			if ( iframe && iframe.parentNode ) {
				iframe.parentNode.removeChild( iframe );
			}
			iframe = null;
			state.measured = undefined;
			state.measuring = false;

			if ( '' !== url && framed() ) {
				state.measuring = true;
			}

			if ( '' !== url ) {
				iframe = el( 'iframe', 'books-preview__frame' );
				iframe.setAttribute( 'title', __( 'Preview of the page', 'otherguise' ) );
				iframe.addEventListener( 'load', function () {
					if ( id === generation && framed() ) {
						measure( iframe, id );
					} else if ( id === generation ) {
						state.measuring = false;
						say();
					}
				} );
				usable.appendChild( iframe );
				iframe.src = url;
			}

			draw();
			say();
		}

		function sayPdf() {
			var parts = [];
			if ( '' !== state.pdfText ) {
				parts.push( state.pdfText );
			}
			if ( state.pdfPreview && '' !== state.pdfUrl ) {
				parts.push( __( 'Preview of a post that is not published: the QR code and the addresses are not final, so this is not the version to print.', 'otherguise' ) );
			}
			if ( state.stale && '' !== state.pdfUrl ) {
				parts.push( __( 'Out of date: update the post, then refresh.', 'otherguise' ) );
			}
			status.textContent = parts.join( ' ' );
			status.className = 'books-preview__status books-preview__status--pdf' + ( state.pdfFailed ? ' books-preview__status--over' : '' ) + ( state.stale ? ' books-preview__status--stale' : '' );
		}

		function releasePdf() {
			if ( '' !== state.pdfUrl ) {
				( pdfOptions.revokeObjectURL || function ( url ) {
					win.URL.revokeObjectURL( url );
				} )( state.pdfUrl );
			}
			state.pdfUrl = '';
			state.pdfName = '';
			state.pdfPages = null;
			state.pdfPreview = false;
			state.pdfFailed = false;
			if ( pdfFrame && pdfFrame.parentNode ) {
				pdfFrame.parentNode.removeChild( pdfFrame );
			}
			pdfFrame = null;
			downloadButton.hidden = true;
			openButton.hidden = true;
		}

		function pdfFailed( id, message ) {
			if ( id !== generation ) {
				return;
			}
			state.pdfFailed = true;
			state.pdfText = __( 'The PDF could not be made:', 'otherguise' ) + ' ' + message;
			say();
		}

		// Turns the answer of the file route (the PDF in base64) into an address that the iframe and the buttons can use.
		function showPdf( id, file ) {
			if ( id !== generation ) {
				return;
			}
			var decode = pdfOptions.atob || function ( text ) {
				return win.atob( text );
			};
			var BlobClass = pdfOptions.Blob || win.Blob;
			var make = pdfOptions.createObjectURL || function ( blob ) {
				return win.URL.createObjectURL( blob );
			};
			var url = '';
			try {
				var binary = decode( file.base64 );
				var bytes = new Uint8Array( binary.length );
				for ( var i = 0; i < binary.length; i++ ) {
					bytes[ i ] = binary.charCodeAt( i );
				}
				url = make( new BlobClass( [ bytes ], { type: 'application/pdf' } ) );
			} catch ( e ) {
				pdfFailed( id, __( 'the file could not be read.', 'otherguise' ) );
				return;
			}

			state.pdfUrl = url;
			state.pdfName = file.filename || 'page.pdf';
			state.pdfPages = file.pages || null;
			state.pdfPreview = !! file.preview;
			state.pdfFailed = false;
			state.pdfText = state.pdfPages ? 'PDF: ' + state.pdfPages + ' ' + ( 1 === state.pdfPages ? __( 'page', 'otherguise' ) : __( 'pages', 'otherguise' ) ) + '.' : __( 'PDF ready.', 'otherguise' );

			pdfFrame = el( 'iframe', 'books-preview__pdf' );
			pdfFrame.setAttribute( 'title', __( 'PDF of the page', 'otherguise' ) );
			pdfFrame.src = url + '#zoom=page-fit&pagemode=none';
			page.appendChild( pdfFrame );
			downloadButton.hidden = false;
			openButton.hidden = false;
			draw();
			say();
		}

		// Asks for the PDF, then asks for its state until it is ready, has failed, or the time is up.
		function loadPdf( force ) {
			force = !! force || state.pdfForce;
			state.pdfForce = false;
			generation++;
			var id = generation;
			var started = Date.now();

			if ( null !== timer ) {
				cancel( timer );
				timer = null;
			}
			if ( iframe && iframe.parentNode ) {
				iframe.parentNode.removeChild( iframe );
			}
			iframe = null;
			releasePdf();
			state.measuring = false;
			state.pdfText = __( 'Generating the PDF…', 'otherguise' );
			draw();
			say();

			var handle = function ( answer ) {
				if ( id !== generation ) {
					return;
				}
				if ( 'done' === answer.status ) {
					pdfOptions.api.file( state.mode ).then( function ( file ) {
						showPdf( id, file );
					}, function ( error ) {
						pdfFailed( id, error && error.message ? error.message : '' );
					} );
				} else if ( 'failed' === answer.status ) {
					pdfFailed( id, answer.message || '' );
				} else if ( Date.now() - started > ( pdfOptions.giveUpMs || GIVE_UP_MS ) ) {
					pdfFailed( id, __( 'it takes too long: is WP-Cron running?', 'otherguise' ) );
				} else {
					state.pdfText = __( 'Generating the PDF…', 'otherguise' ) + ( answer.elapsed ? ' ' + answer.elapsed + ' s' : '' );
					say();
					timer = later( function () {
						timer = null;
						if ( id === generation ) {
							pdfOptions.api.status( state.mode ).then( handle, fail );
						}
					}, pdfOptions.pollMs || POLL_MS );
				}
			};
			var fail = function ( error ) {
				pdfFailed( id, error && error.message ? error.message : '' );
			};

			pdfOptions.api.start( state.mode, !! force ).then( handle, fail );
		}

		function setView( view ) {
			if ( ! pdfOptions || view === state.view ) {
				return;
			}
			state.view = view;
			webButton.setAttribute( 'aria-pressed', 'web' === view ? 'true' : 'false' );
			pdfButton.setAttribute( 'aria-pressed', 'pdf' === view ? 'true' : 'false' );
			if ( state.open ) {
				load();
			}
		}

		function show() {
			state.open = true;
			root.hidden = false;
			place();
			load();
		}

		function hide() {
			state.open = false;
			root.hidden = true;
			generation++;
			if ( null !== timer ) {
				cancel( timer );
				timer = null;
			}
			if ( iframe && iframe.parentNode ) {
				iframe.parentNode.removeChild( iframe );
			}
			iframe = null;
			if ( pdfOptions ) {
				releasePdf();
			}
		}

		select.addEventListener( 'change', function () {
			state.mode = select.value;
			if ( state.open ) {
				load();
			}
		} );
		refreshButton.addEventListener( 'click', function () {
			if ( state.open ) {
				load( true );
			}
		} );
		if ( pdfOptions ) {
			webButton.addEventListener( 'click', function () {
				setView( 'web' );
			} );
			pdfButton.addEventListener( 'click', function () {
				setView( 'pdf' );
			} );
			downloadButton.addEventListener( 'click', function () {
				if ( '' === state.pdfUrl ) {
					return;
				}
				var link = doc.createElement( 'a' );
				link.href = state.pdfUrl;
				link.download = state.pdfName;
				doc.body.appendChild( link );
				link.click();
				doc.body.removeChild( link );
			} );
			openButton.addEventListener( 'click', function () {
				if ( '' !== state.pdfUrl ) {
					( pdfOptions.openWindow || function ( url ) {
						win.open( url, '_blank' );
					} )( state.pdfUrl );
				}
			} );
		}
		closeButton.addEventListener( 'click', function () {
			hide();
			if ( options.onClose ) {
				options.onClose();
			}
		} );

		// Moving the panel by its title bar, and resizing it by the handle (the page keeps its proportion: only the width is set).
		function drag( start, move ) {
			return function ( event ) {
				if ( event.button && 0 !== event.button ) {
					return;
				}
				var from = { x: event.clientX, y: event.clientY };
				var origin = start();
				var onMove = function ( e ) {
					move( origin, e.clientX - from.x, e.clientY - from.y );
				};
				var onUp = function () {
					doc.removeEventListener( 'pointermove', onMove );
					doc.removeEventListener( 'pointerup', onUp );
					remember();
				};
				doc.addEventListener( 'pointermove', onMove );
				doc.addEventListener( 'pointerup', onUp );
				event.preventDefault();
			};
		}

		bar.addEventListener(
			'pointerdown',
			function ( event ) {
				if ( event.target === select || event.target === refreshButton || event.target === closeButton ) {
					return;
				}
				drag(
					function () {
						return { x: state.x, y: state.y };
					},
					function ( origin, dx, dy ) {
						state.x = origin.x + dx;
						state.y = origin.y + dy;
						place();
					}
				)( event );
			}
		);
		handle.addEventListener(
			'pointerdown',
			drag(
				function () {
					return { width: state.width };
				},
				function ( origin, dx ) {
					state.width = clamp( origin.width + dx, MIN_WIDTH, MAX_WIDTH );
					place();
					draw();
				}
			)
		);

		restore();
		doc.body.appendChild( root );

		return {
			element: root,
			iframe: function () {
				return iframe;
			},
			open: show,
			close: hide,
			toggle: function () {
				if ( state.open ) {
					hide();
				} else {
					show();
				}
			},
			isOpen: function () {
				return state.open;
			},
			refresh: function ( force ) {
				if ( state.open ) {
					load( !! force );
				}
			},
			view: function () {
				return state.view;
			},
			setView: setView,
			pdfAvailable: function () {
				return !! pdfOptions;
			},
			setStale: function ( stale ) {
				state.stale = !! stale;
				say();
			},
			mode: function () {
				return state.mode;
			},
			setMode: function ( slug ) {
				state.mode = slug;
				select.value = slug;
				if ( state.open ) {
					load();
				}
			},
			destroy: function () {
				hide();
				if ( root.parentNode ) {
					root.parentNode.removeChild( root );
				}
			},
		};
	}

	return { createPanel: createPanel, STORAGE_KEY: STORAGE_KEY, MIN_WIDTH: MIN_WIDTH, MAX_WIDTH: MAX_WIDTH, DEFAULT_WIDTH: DEFAULT_WIDTH };
} ) );
