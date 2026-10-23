// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 223: section.js, the connection of the Section panel to the editor, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const dir = path.join( __dirname, '../../plugin/modules/books/editor/section/' );
const source = ( file ) => fs.readFileSync( path.join( dir, file ), 'utf8' );
const SETTINGS = { path: '/books/v1/sections', postTypes: [ 'post', 'page' ] };

/** Loads the two scripts in a jsdom with fake `wp` globals; returns what the editor would hold. */
function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>', { runScripts: 'outside-only' } );
	const registered = {};
	const fetched = [];
	const h = ( type, props, ...children ) => ( { type, props, children } );
	const refs = { current: null };
	const effects = [];

	dom.window.wp = {
		element: { createElement: h, useRef: () => refs, useEffect: ( effect, deps ) => effects.push( [ effect, deps ] ) },
		i18n: { __: ( value ) => value },
		data: { select: () => ( { getCurrentPostId: () => 58325, getCurrentPostType: () => options.type || 'page' } ) },
		plugins: { registerPlugin: ( name, definition ) => ( registered.name = name, registered.definition = definition ) },
		apiFetch: ( request ) => {
			fetched.push( request );
			return Promise.resolve( { id: 58325, section: false, contents: [], containing: [] } );
		},
	};
	if ( ! options.noPanel ) {
		if ( options.legacy ) {
			dom.window.wp.editPost = { PluginDocumentSettingPanel: 'LegacyPanel' };
		} else {
			dom.window.wp.editor = { PluginDocumentSettingPanel: 'Panel' };
		}
	}
	if ( options.noFetch ) {
		delete dom.window.wp.apiFetch;
	}
	if ( ! options.noSettings ) {
		dom.window.booksSection = SETTINGS;
	}
	if ( ! options.noUi ) {
		dom.window.eval( source( 'section-ui.js' ) );
	}
	dom.window.eval( source( 'section.js' ) );

	return { dom, registered, fetched, refs, effects, h };
}

test( 'nothing is registered without the settings, the panel component, the fetch or the UI script', () => {
	for ( const options of [ { noSettings: true }, { noPanel: true }, { noFetch: true }, { noUi: true } ] ) {
		assert.strictEqual( load( options ).registered.name, undefined, JSON.stringify( options ) );
	}
} );

test( 'the plugin is registered and renders the panel for the post types that can hold sections only', () => {
	const { registered } = load();

	assert.strictEqual( registered.name, 'books-section' );

	const rendered = registered.definition.render();

	assert.strictEqual( rendered.type, 'Panel' );
	assert.strictEqual( rendered.props.name, 'books-section' );
	assert.strictEqual( rendered.props.title, 'Section' );

	assert.strictEqual( load( { type: 'attachment' } ).registered.definition.render(), null );
	assert.strictEqual( load( { type: 'post' } ).registered.definition.render().type, 'Panel' );
	assert.strictEqual( load( { legacy: true } ).registered.definition.render().type, 'LegacyPanel' );
} );

test( 'the body mounts the panel in its container with the routes of the REST API, and removes it when it goes', async () => {
	const { dom, registered, fetched, refs, effects } = load();

	const body = registered.definition.render().children[ 0 ];

	body.type();
	refs.current = dom.window.document.getElementById( 'host' );
	assert.strictEqual( effects.length, 1 );
	assert.strictEqual( effects[ 0 ][ 1 ].length, 0, 'Once.' );

	const cleanup = effects[ 0 ][ 0 ]();

	assert.ok( dom.window.document.querySelector( '.books-section' ) );
	assert.strictEqual( JSON.stringify( fetched[ 0 ] ), '{"path":"/books/v1/sections/58325"}' );

	cleanup();

	assert.strictEqual( dom.window.document.querySelector( '.books-section' ), null );
} );

test( 'the routes: the paths, the methods and the data of each call', async () => {
	const { dom, registered, fetched, refs, effects } = load();

	registered.definition.render().children[ 0 ].type();
	refs.current = dom.window.document.getElementById( 'host' );
	effects[ 0 ][ 0 ]();
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	// The panel was built with the api of section.js: use it through the panel's controls.
	const check = dom.window.document.querySelector( '.books-section__check' );

	check.checked = true;
	check.dispatchEvent( new dom.window.Event( 'change' ) );

	const find = dom.window.document.querySelector( '.books-section__find' );

	find.value = 'alp ine';
	find.dispatchEvent( new dom.window.Event( 'input' ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 400 ) );

	assert.deepStrictEqual( JSON.parse( JSON.stringify( fetched.slice( 1, 3 ).map( ( request ) => [ request.path, request.method || 'GET', request.data || null ] ) ) ), [
		[ '/books/v1/sections/58325', 'PUT', { section: true } ],
		[ '/books/v1/sections?search=alp%20ine', 'GET', null ],
	] );
} );
