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
function load( settings, inner ) {
	const dom = new JSDOM( '', { runScripts: 'outside-only' } );
	dom.window.__inner = inner || [];
	const registered = {};
	const h = ( type, props, ...children ) => ( { type, props, children } );
	const tag = ( name ) => name;
	dom.window.wp = {
		element: { createElement: h, Fragment: tag( 'Fragment' ) },
		i18n: { __: ( text ) => text },
		blockEditor: {
			useBlockProps: () => ( { className: 'x' } ),
			InspectorControls: tag( 'InspectorControls' ),
			InnerBlocks: Object.assign( function InnerBlocks() {}, { Content: tag( 'InnerBlocks.Content' ) } ),
		},
		components: { PanelBody: tag( 'PanelBody' ), SelectControl: tag( 'SelectControl' ), TextControl: tag( 'TextControl' ) },
		data: { useSelect: ( callback ) => callback( () => ( { getBlocks: () => dom.window.__inner || [] } ) ) },
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
	const name = typeof node.type === 'function' ? node.type.name : node.type;
	if ( name === type ) return node;
	for ( const child of node.children || [] ) {
		const found = find( child, type );
		if ( found ) return found;
	}
	return null;
}

test( 'registers modes/link with variations for the print and the web versions, with an icon and no inner block', () => {
	const { name, def } = load( { modes: [] } );
	assert.strictEqual( name, 'modes/link' );
	assert.deepStrictEqual( plain( def.variations.map( ( v ) => v.name ) ), [ 'print', 'web' ] );
	assert.deepStrictEqual( plain( def.variations[ 0 ].attributes ), { mode: 'print', icon: 'print', label: 'Print version' } );
	assert.deepStrictEqual( plain( def.variations[ 1 ].attributes ), { mode: 'web', icon: 'web', label: 'Web version' } );
	assert.strictEqual( def.variations[ 0 ].innerBlocks, undefined );
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

const ICONS = [ { value: 'print', label: 'Printer', svg: '<svg id="p"></svg>' } ];

test( 'the icon stands for the content while the block has none, and gives way to inner blocks', () => {
	const props = { clientId: 'c', attributes: { mode: 'print', label: '', icon: 'print' }, setAttributes() {} };
	const empty = load( { modes: [], icons: ICONS }, [] ).def.edit( props );
	const shown = find( empty, 'div' );
	assert.strictEqual( shown.props.dangerouslySetInnerHTML.__html, '<svg id="p"></svg>' );
	assert.strictEqual( find( empty, 'InnerBlocks' ), null );

	const filled = load( { modes: [], icons: ICONS }, [ {} ] ).def.edit( props );
	assert.ok( find( filled, 'InnerBlocks' ) );
	assert.strictEqual( find( filled, 'div' ).props.dangerouslySetInnerHTML, undefined );

	const none = load( { modes: [], icons: ICONS }, [] ).def.edit( { clientId: 'c', attributes: { mode: 'print', label: '', icon: '' }, setAttributes() {} } );
	assert.ok( find( none, 'InnerBlocks' ), 'no icon: the inner blocks' );
} );

test( 'the icon select offers the icons after None and writes the attribute', () => {
	const set = [];
	const tree = load( { modes: [], icons: ICONS }, [] ).def.edit( { clientId: 'c', attributes: { mode: '', label: '', icon: '' }, setAttributes: ( a ) => set.push( a ) } );
	const select = ( function findIcon( node ) {
		if ( ! node || typeof node !== 'object' ) return null;
		if ( node.type === 'SelectControl' && node.props.label === 'Icon' ) return node;
		for ( const child of node.children || [] ) { const f = findIcon( child ); if ( f ) return f; }
		return null;
	}( tree ) );
	assert.deepStrictEqual( plain( select.props.options.map( ( o ) => o.value ) ), [ '', 'print' ] );
	select.props.onChange( 'print' );
	assert.deepStrictEqual( plain( set ), [ { icon: 'print' } ] );
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
