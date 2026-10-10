// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 205: the editor script of the block modes/link, run in jsdom with a minimal stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const source = fs.readFileSync( path.join( __dirname, '../../plugin/modules/modes/blocks/link/edit.js' ), 'utf8' );

/** Runs the script with fake `wp` globals; returns the registered block and the elements the edit function built. */
function load( settings ) {
	const dom = new JSDOM( '', { runScripts: 'outside-only' } );
	const registered = {};
	const h = ( type, props, ...children ) => ( { type, props, children } );
	const tag = ( name ) => name;
	dom.window.wp = {
		element: { createElement: h, Fragment: tag( 'Fragment' ) },
		i18n: { __: ( text ) => text },
		blockEditor: {
			useBlockProps: () => ( { className: 'x' } ),
			InspectorControls: tag( 'InspectorControls' ),
			InnerBlocks: Object.assign( tag( 'InnerBlocks' ), { Content: tag( 'InnerBlocks.Content' ) } ),
		},
		components: { PanelBody: tag( 'PanelBody' ), SelectControl: tag( 'SelectControl' ), TextControl: tag( 'TextControl' ) },
		blocks: { registerBlockType: ( name, def ) => ( registered.name = name, registered.def = def ) },
	};
	dom.window.modesLink = settings;
	dom.window.eval( source );
	return registered;
}

/** Copies a value made in the jsdom realm into this realm, so that deepStrictEqual compares prototypes fairly. */
const plain = ( value ) => JSON.parse( JSON.stringify( value ) );

/** Finds the first element of a tree whose type is `type`. */
function find( node, type ) {
	if ( ! node || typeof node !== 'object' ) return null;
	if ( node.type === type ) return node;
	for ( const child of node.children || [] ) {
		const found = find( child, type );
		if ( found ) return found;
	}
	return null;
}

test( 'registers modes/link with a variation for the print version', () => {
	const { name, def } = load( { modes: [] } );
	assert.strictEqual( name, 'modes/link' );
	assert.strictEqual( def.variations.length, 1 );
	assert.strictEqual( def.variations[ 0 ].attributes.mode, 'print' );
	assert.strictEqual( def.variations[ 0 ].innerBlocks[ 0 ][ 0 ], 'core/html' );
	assert.match( def.variations[ 0 ].innerBlocks[ 0 ][ 1 ].content, /^<svg[^>]*currentColor/ );
} );

test( 'the mode select offers the modes of the site after a placeholder', () => {
	const { def } = load( { modes: [ { value: 'web', label: 'Web' }, { value: 'print', label: 'Print' } ] } );
	const select = find( def.edit( { attributes: { mode: 'print', label: '' }, setAttributes() {} } ), 'SelectControl' );
	assert.deepStrictEqual( plain( select.props.options.map( ( o ) => o.value ) ), [ '', 'web', 'print' ] );
	assert.strictEqual( select.props.value, 'print' );
} );

test( 'the controls write the attributes', () => {
	const { def } = load( { modes: [ { value: 'web', label: 'Web' } ] } );
	const set = [];
	const tree = def.edit( { attributes: { mode: '', label: '' }, setAttributes: ( a ) => set.push( a ) } );
	find( tree, 'SelectControl' ).props.onChange( 'web' );
	find( tree, 'TextControl' ).props.onChange( 'Print' );
	assert.deepStrictEqual( plain( set ), [ { mode: 'web' }, { label: 'Print' } ] );
} );

test( 'works when the inline script did not run', () => {
	const { def } = load( undefined );
	const select = find( def.edit( { attributes: { mode: '', label: '' }, setAttributes() {} } ), 'SelectControl' );
	assert.strictEqual( select.props.options.length, 1 );
} );

test( 'saves only the inner blocks', () => {
	const { def } = load( { modes: [] } );
	assert.strictEqual( def.save().type, 'InnerBlocks.Content' );
} );
