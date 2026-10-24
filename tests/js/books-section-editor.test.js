// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slices 223 and 224: section.js, the connection of the Book section panel to the editor, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const dir = path.join( __dirname, '../../plugin/modules/books/editor/section/' );
const source = ( file ) => fs.readFileSync( path.join( dir, file ), 'utf8' );
const SETTINGS = { path: '/books/v1/sections', postTypes: [ 'post', 'page' ] };
const SCRIPTS = [ 'section-model.js', 'section-dialog.js', 'section-window.js', 'section-ui.js' ];

/** Loads the scripts in a jsdom with fake `wp` globals; returns what the editor would hold. */
function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>', { runScripts: 'outside-only' } );
	const registered = {};
	const fetched = [];
	const h = ( type, props, ...children ) => ( { type, props, children } );
	const refs = { current: null };
	const effects = [];
	const ticks = [];

	dom.window.wp = {
		element: {
			createElement: h,
			Fragment: 'Fragment',
			useRef: () => refs,
			useEffect: ( effect, deps ) => effects.push( [ effect, deps ] ),
			useState: ( initial ) => [ initial, ( update ) => ticks.push( update ) ],
		},
		i18n: { __: ( value, domain ) => ( 'otherguise' === domain ? value : '[no domain] ' + value ) },
		data: { select: () => ( { getCurrentPostId: () => 58325, getCurrentPostType: () => options.type || 'page' } ) },
		plugins: { registerPlugin: ( name, definition ) => ( registered.name = name, registered.definition = definition ) },
		apiFetch: ( request ) => {
			fetched.push( request );
			return Promise.resolve( { id: 58325, title: 'Trip', section: !! options.section, range: { from: '', to: '' }, contents: [], containing: [] } );
		},
	};
	if ( ! options.noPanel ) {
		if ( options.legacy ) {
			dom.window.wp.editPost = { PluginDocumentSettingPanel: 'LegacyPanel', PluginMoreMenuItem: 'LegacyMenu' };
		} else {
			dom.window.wp.editor = { PluginDocumentSettingPanel: 'Panel' };
			if ( ! options.noMenu ) {
				dom.window.wp.editor.PluginMoreMenuItem = 'Menu';
			}
		}
	}
	if ( options.noFetch ) {
		delete dom.window.wp.apiFetch;
	}
	if ( ! options.noSettings ) {
		dom.window.booksSection = SETTINGS;
	}
	if ( ! options.noScripts ) {
		SCRIPTS.forEach( ( file ) => dom.window.eval( source( file ) ) );
	}
	dom.window.eval( source( 'section.js' ) );

	return { dom, registered, fetched, refs, effects, ticks };
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

test( 'nothing is registered without the settings, the panel component, the fetch or the scripts', () => {
	for ( const options of [ { noSettings: true }, { noPanel: true }, { noFetch: true }, { noScripts: true } ] ) {
		assert.strictEqual( load( options ).registered.name, undefined, JSON.stringify( options ) );
	}
} );

test( 'the plugin renders the panel titled "Book section" for the post types that can hold sections only', () => {
	const { registered } = load();

	assert.strictEqual( registered.name, 'books-section' );

	const rendered = registered.definition.render();

	assert.strictEqual( rendered.type, 'Fragment' );
	assert.strictEqual( rendered.children[ 0 ].type, 'Panel' );
	assert.strictEqual( rendered.children[ 0 ].props.name, 'books-section' );
	assert.strictEqual( rendered.children[ 0 ].props.title, 'Book section', 'The text domain is passed.' );
	assert.strictEqual( typeof rendered.children[ 1 ].type, 'function', 'The menu item.' );

	assert.strictEqual( load( { type: 'attachment' } ).registered.definition.render(), null );
	assert.strictEqual( load( { type: 'post' } ).registered.definition.render().children[ 0 ].type, 'Panel' );
	assert.strictEqual( load( { legacy: true } ).registered.definition.render().children[ 0 ].type, 'LegacyPanel' );
	assert.strictEqual( load( { noMenu: true } ).registered.definition.render().children[ 1 ], null, 'No menu item to put in.' );
} );

test( 'the body mounts the sidebar view, which reads the state once, and removes it when it goes', async () => {
	const { dom, registered, fetched, refs, effects } = load();

	const body = registered.definition.render().children[ 0 ].children[ 0 ];

	body.type();
	refs.current = dom.window.document.getElementById( 'host' );
	assert.strictEqual( effects.length, 1 );
	assert.strictEqual( effects[ 0 ][ 1 ].length, 0, 'Once.' );

	const cleanup = effects[ 0 ][ 0 ]();

	assert.ok( dom.window.document.querySelector( '.books-section' ) );
	assert.ok( fetched.length >= 1 );
	assert.strictEqual( JSON.stringify( fetched[ 0 ] ), '{"path":"/books/v1/sections/58325"}' );

	cleanup();

	assert.strictEqual( dom.window.document.querySelector( '.books-section' ), null );
} );

test( 'the menu item exists for a section only, and opens and closes the window', async () => {
	const plain = load();
	const plainItem = plain.registered.definition.render().children[ 1 ];

	assert.strictEqual( plainItem.type(), null, 'A plain post: nothing in the menu.' );

	const site = load( { section: true } );
	const item = site.registered.definition.render().children[ 1 ];

	item.type();
	await flush();
	assert.strictEqual( site.effects.length, 1 );
	const stop = site.effects[ 0 ][ 0 ]();

	const shown = item.type();

	assert.strictEqual( shown.type, 'Menu' );
	assert.strictEqual( shown.children[ 0 ], 'Open the Book section in a window' );
	assert.strictEqual( shown.props.icon, 'external' );

	shown.props.onClick();

	assert.ok( site.dom.window.document.querySelector( '.books-section-window' ) );
	assert.strictEqual( item.type().children[ 0 ], 'Close the Book section window' );
	assert.ok( site.ticks.length >= 1, 'The menu is told to draw itself again.' );

	item.type().props.onClick();
	assert.strictEqual( site.dom.window.document.querySelector( '.books-section-window' ).hidden, true );

	stop();
} );

test( 'the routes: the paths, the methods and the data of each call, with a place for the posts that are added', async () => {
	const { dom, registered, fetched, refs, effects } = load( { section: true } );

	registered.definition.render().children[ 0 ].children[ 0 ].type();
	refs.current = dom.window.document.getElementById( 'host' );
	effects[ 0 ][ 0 ]();
	await flush();

	const check = dom.window.document.querySelector( '.books-section__check' );

	check.checked = false;
	check.dispatchEvent( new dom.window.Event( 'change' ) );

	const find = dom.window.document.querySelector( '.books-section__find' );

	find.value = 'alp ine';
	find.dispatchEvent( new dom.window.Event( 'input' ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );

	const seen = JSON.parse( JSON.stringify( fetched.map( ( request ) => [ request.path, request.method || 'GET', request.data || null ] ) ) );

	assert.ok( seen.some( ( call ) => '/books/v1/sections/58325' === call[ 0 ] && 'PUT' === call[ 1 ] && false === call[ 2 ].section ) );
	assert.ok( seen.some( ( call ) => '/books/v1/sections?search=alp%20ine' === call[ 0 ] && 'GET' === call[ 1 ] ) );
} );
