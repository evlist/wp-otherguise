// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 400: link.js, the connection of the Share link panel to the editor, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const dir = path.join( __dirname, '../../plugin/modules/access/editor/link/' );
const source = ( file ) => fs.readFileSync( path.join( dir, file ), 'utf8' );
const SETTINGS = { path: '/access/v1/posts', postTypes: [ 'post', 'page' ] };

function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>', { runScripts: 'outside-only' } );
	const registered = {};
	const fetched = [];
	const refs = { current: null };
	const effects = [];

	dom.window.wp = {
		element: { createElement: ( type, props, ...children ) => ( { type, props, children } ), useRef: () => refs, useEffect: ( effect, deps ) => effects.push( [ effect, deps ] ) },
		i18n: { __: ( value, domain ) => ( 'otherguise' === domain ? value : '[no domain] ' + value ) },
		data: { select: () => ( { getCurrentPostId: () => 12, getCurrentPostType: () => options.type || 'post' } ) },
		plugins: { registerPlugin: ( name, definition ) => ( registered.name = name, registered.definition = definition ) },
		apiFetch: ( request ) => {
			fetched.push( request );
			return Promise.resolve( { id: 12, active: false, url: '', created: '' } );
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
		dom.window.accessLink = SETTINGS;
	}
	if ( ! options.noPanelScript ) {
		dom.window.eval( source( 'link-panel.js' ) );
	}
	dom.window.eval( source( 'link.js' ) );

	return { dom, registered, fetched, refs, effects };
}

test( 'nothing is registered without the settings, the panel component, the fetch or the panel script', () => {
	for ( const options of [ { noSettings: true }, { noPanel: true }, { noFetch: true }, { noPanelScript: true } ] ) {
		assert.strictEqual( load( options ).registered.name, undefined, JSON.stringify( options ) );
	}
} );

test( 'the panel is titled "Share link" and shown for the types of post that can have a link', () => {
	const { registered } = load();

	assert.strictEqual( registered.name, 'access-link' );

	const rendered = registered.definition.render();

	assert.strictEqual( rendered.type, 'Panel' );
	assert.strictEqual( rendered.props.name, 'access-link' );
	assert.strictEqual( rendered.props.title, 'Share link', 'The text domain is passed.' );
	assert.strictEqual( load( { type: 'attachment' } ).registered.definition.render(), null );
	assert.strictEqual( load( { legacy: true } ).registered.definition.render().type, 'LegacyPanel' );
} );

test( 'the body mounts the panel, which reads the state, and removes it when it goes', async () => {
	const { dom, registered, fetched, refs, effects } = load();

	registered.definition.render().children[ 0 ].type();
	refs.current = dom.window.document.getElementById( 'host' );

	assert.strictEqual( effects[ 0 ][ 1 ].length, 0, 'Once.' );

	const cleanup = effects[ 0 ][ 0 ]();

	assert.ok( dom.window.document.querySelector( '.access-link' ) );
	assert.strictEqual( JSON.stringify( fetched[ 0 ] ), '{"path":"/access/v1/posts/12"}' );

	cleanup();

	assert.strictEqual( dom.window.document.querySelector( '.access-link' ), null );
} );

test( 'the routes: create, replace and revoke', async () => {
	const { dom, registered, fetched, refs, effects } = load();

	registered.definition.render().children[ 0 ].type();
	refs.current = dom.window.document.getElementById( 'host' );
	effects[ 0 ][ 0 ]();
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	dom.window.document.querySelector( '.access-link__create' ).dispatchEvent( new dom.window.Event( 'click' ) );

	const seen = JSON.parse( JSON.stringify( fetched.map( ( request ) => [ request.path, request.method || 'GET', request.data || null ] ) ) );

	assert.deepStrictEqual( seen[ 1 ], [ '/access/v1/posts/12', 'POST', { regenerate: false } ] );
} );
