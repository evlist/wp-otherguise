// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 502: space.js, the "Space" panel of the editor of the wiki pages, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const source = fs.readFileSync( path.join( __dirname, '../../plugin/modules/wiki/editor/space/space.js' ), 'utf8' );
const SETTINGS = {
	postType: 'wiki_page',
	spaces: [ { name: 'Biography', label: 'Autobiography' }, { name: 'Opinion', label: '' } ],
};

function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only' } );
	const registered = {};
	const edits = [];
	const editor = {
		getCurrentPostType: () => options.type || 'wiki_page',
		getCurrentPost: () => ( { meta: { wiki_space: options.saved || '' } } ),
		getEditedPostAttribute: () => ( { wiki_space: options.chosen || '' } ),
	};

	dom.window.wp = {
		element: { createElement: ( type, props, ...children ) => ( { type, props, children } ) },
		i18n: { __: ( value, domain ) => ( 'otherguise' === domain ? value : '[no domain] ' + value ) },
		data: { useSelect: ( select ) => select( () => editor ), useDispatch: () => ( { editPost: ( change ) => edits.push( change ) } ) },
		components: { SelectControl: 'SelectControl' },
		plugins: { registerPlugin: ( name, definition ) => ( registered.name = name, registered.definition = definition ) },
	};
	if ( ! options.noPanel ) {
		dom.window.wp.editor = { PluginDocumentSettingPanel: 'Panel' };
	}
	if ( ! options.noSettings ) {
		dom.window.wikiSpace = SETTINGS;
	}
	dom.window.eval( source );

	return { registered, edits };
}

test( 'nothing is registered without the settings or the panel component', () => {
	assert.strictEqual( load( { noSettings: true } ).registered.name, undefined );
	assert.strictEqual( load( { noPanel: true } ).registered.name, undefined );
} );

test( 'the panel is registered as wiki-space and shows nothing for another type of post', () => {
	const { registered } = load( { type: 'page' } );

	assert.strictEqual( registered.name, 'wiki-space' );
	assert.strictEqual( registered.definition.render(), null );
} );

test( 'a page with no space offers the spaces, with their label or their name, and can be changed', () => {
	const { registered, edits } = load();
	const panel = registered.definition.render();
	const select = panel.children[ 0 ];

	assert.strictEqual( panel.props.title, 'Space' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( select.props.options ) ), [
		{ label: 'Choose a space', value: '' },
		{ label: 'Autobiography', value: 'Biography' },
		{ label: 'Opinion', value: 'Opinion' },
	] );
	assert.strictEqual( select.props.disabled, false );
	assert.strictEqual( select.props.value, '' );
	assert.match( select.props.help, /before the page is published/ );

	select.props.onChange( 'Opinion' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( edits ) ), [ { meta: { wiki_space: 'Opinion' } } ] );
} );

test( 'a page that has a space shows it and cannot change it', () => {
	const { registered } = load( { saved: 'Biography', chosen: 'Biography' } );
	const select = registered.definition.render().children[ 0 ];

	assert.strictEqual( select.props.disabled, true );
	assert.strictEqual( select.props.value, 'Biography' );
	assert.match( select.props.help, /moving it changes its address/ );
} );
