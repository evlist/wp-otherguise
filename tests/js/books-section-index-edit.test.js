// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 613: the block books/section-index checks its options while they are typed, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const script = fs.readFileSync( path.join( __dirname, '../../plugin/modules/books/blocks/section-index/edit.js' ), 'utf8' );

/** Loads the script and gives back the edit function with what the test needs to drive it. */
function load( answer ) {
	const dom = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only' } );
	const h = ( type, props, ...children ) => ( { type, props: props || {}, children } );
	const log = { fetched: [], effects: [], timers: [], state: [] };
	const registered = {};

	log.state = [ {} ];
	dom.window.setTimeout = ( callback ) => log.timers.push( callback );
	dom.window.clearTimeout = () => {};
	dom.window.wp = {
		element: {
			createElement: h,
			Fragment: 'Fragment',
			useState: () => [ log.state[ 0 ], ( value ) => ( log.state[ 0 ] = value ) ],
			useEffect: ( effect, deps ) => log.effects.push( [ effect, deps ] ),
		},
		i18n: { __: ( text ) => text },
		blockEditor: { InspectorControls: 'Inspector', useBlockProps: () => ( {} ) },
		components: {
			PanelBody: 'PanelBody',
			TextControl: 'TextControl',
			ToggleControl: 'ToggleControl',
			SelectControl: 'SelectControl',
			CheckboxControl: 'CheckboxControl',
		},
		blocks: { registerBlockType: ( name, definition ) => ( registered.definition = definition ) },
		apiFetch: ( request ) => {
			log.fetched.push( request.path );
			return answer instanceof Error ? Promise.reject( answer ) : Promise.resolve( answer );
		},
	};
	dom.window.eval( script );

	return { registered, log };
}

/** Finds the nodes of a type in a tree of elements. */
function find( node, type, found ) {
	found = found || [];
	if ( node && 'object' === typeof node ) {
		if ( node.type === type ) {
			found.push( node );
		}
		[].concat( node.children || [] ).forEach( ( child ) => find( child, type, found ) );
	}
	return found;
}

const flush = () => new Promise( ( resolve ) => setImmediate( resolve ) );
const field = ( tree, label ) => find( tree, 'TextControl' ).find( ( control ) => control.props.label === label );

test( 'after a pause the options are checked by the server and what is wrong is shown in red under its field', async () => {
	const site = load( { available: true, errors: { include: [], exclude: [], rollup: [ 'Unknown kind "category". The kinds: place, species.' ] } } );
	const attributes = { include: '', exclude: '', rollup: 'kind:category', context: true, attribution: 'auto', modes: [] };
	const props = { attributes, setAttributes: () => {} };

	site.registered.definition.edit( props );
	assert.strictEqual( site.log.effects.length, 1, 'the check is an effect of the options' );
	assert.strictEqual( site.log.fetched.length, 0, 'nothing is asked while the typing goes on' );

	site.log.effects[ 0 ][ 0 ]();
	assert.strictEqual( site.log.timers.length, 1, 'it waits for a pause' );
	site.log.timers[ 0 ]();
	await flush();

	assert.strictEqual( site.log.fetched.length, 1 );
	assert.ok( site.log.fetched[ 0 ].startsWith( '/books/v1/index-options?' ) );
	assert.ok( site.log.fetched[ 0 ].includes( 'rollup=kind%3Acategory' ), 'the options are in the address, encoded' );

	const tree = site.registered.definition.edit( props );
	const help = field( tree, 'Broader topics with a line of their own' ).props.help;

	assert.strictEqual( help.props.role, 'alert' );
	assert.strictEqual( help.props.style.color, '#b32d2e' );
	assert.deepStrictEqual( help.children, [ 'Unknown kind "category". The kinds: place, species.' ] );
	assert.strictEqual( typeof field( tree, 'Include' ).props.help, 'string', 'the other fields keep their help' );
} );

test( 'the effect depends on the three options only, and a failing or absent route says nothing', async () => {
	const site = load( new Error( 'no route' ) );
	const attributes = { include: 'kind:place', exclude: '', rollup: '', context: true, attribution: 'auto', modes: [] };

	site.registered.definition.edit( { attributes, setAttributes: () => {} } );
	assert.deepStrictEqual( Array.from( site.log.effects[ 0 ][ 1 ] ), [ 'kind:place\u0001\u0001' ] );

	site.log.effects[ 0 ][ 0 ]();
	site.log.timers[ 0 ]();
	await flush();

	const tree = site.registered.definition.edit( { attributes, setAttributes: () => {} } );

	assert.strictEqual( typeof field( tree, 'Include' ).props.help, 'string', 'no message, the editing goes on' );
} );
