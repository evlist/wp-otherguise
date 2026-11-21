// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 500: rules.js, the panel "Branch rules" of the page editor, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const source = fs.readFileSync( path.join( __dirname, '../../plugin/modules/wiki/editor/rules/rules.js' ), 'utf8' );
const SETTINGS = { postType: 'page', path: '/wiki/v1/rules', keys: [ 'template', 'class', 'status' ], canEdit: true };

function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only' } );
	const registered = {};
	const edits = [];
	const fetched = [];
	const infoSetter = [];
	const effects = [];
	const editor = {
		getCurrentPostType: () => options.type || 'page',
		getCurrentPostId: () => 12,
		getEditedPostAttribute: ( name ) => ( 'parent' === name ? 3 : { wiki_rules: options.own || {} } ),
		isSavingPost: () => !! options.saving,
	};

	dom.window.wp = {
		element: {
			createElement: ( type, props, ...children ) => ( { type, props, children } ),
			useState: () => [ options.info === undefined ? null : options.info, ( value ) => infoSetter.push( value ) ],
			useEffect: ( effect, deps ) => effects.push( [ effect, deps ] ),
		},
		i18n: { __: ( value, domain ) => ( 'otherguise' === domain ? value : '[no domain] ' + value ) },
		data: { useSelect: ( select ) => select( () => editor ), useDispatch: () => ( { editPost: ( change ) => edits.push( change ) } ) },
		components: { TextControl: 'TextControl' },
		plugins: { registerPlugin: ( name, definition ) => ( registered.name = name, registered.definition = definition ) },
		apiFetch: ( request ) => ( fetched.push( request ), Promise.resolve( { effective: {} } ) ),
	};
	if ( ! options.noPanel ) {
		dom.window.wp.editor = { PluginDocumentSettingPanel: 'Panel' };
	}
	if ( ! options.noSettings ) {
		dom.window.wikiRules = Object.assign( {}, SETTINGS, options.settings || {} );
	}
	dom.window.eval( source );

	return { registered, edits, fetched, infoSetter, effects };
}

test( 'nothing is registered without the settings or the panel component', () => {
	assert.strictEqual( load( { noSettings: true } ).registered.name, undefined );
	assert.strictEqual( load( { noPanel: true } ).registered.name, undefined );
} );

test( 'the panel is registered as wiki-rules and shows nothing for another type of post', () => {
	const { registered } = load( { type: 'post' } );

	assert.strictEqual( registered.name, 'wiki-rules' );
	assert.strictEqual( registered.definition.render(), null );
} );

test( 'one input per key, with the rules of this page, and a change is saved with the page', () => {
	const { registered, edits } = load( { own: { class: 'book' } } );
	const panel = registered.definition.render();
	const inputs = panel.children[ 0 ];

	assert.strictEqual( panel.props.title, 'Branch rules' );
	assert.deepStrictEqual( Array.from( inputs, ( input ) => [ input.props.label, input.props.value ] ), [ [ 'Template', '' ], [ 'CSS class', 'book' ], [ 'Status line', '' ] ] );
	assert.strictEqual( inputs[ 0 ].props.disabled, false );

	inputs[ 2 ].props.onChange( 'Fact' );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( edits ) ), [ { meta: { wiki_rules: { class: 'book', status: 'Fact' } } } ] );
} );

test( 'what is inherited is shown with the page it comes from; what this page sets itself is not', () => {
	const info = { effective: { template: { value: 'page-bio', from: 1, title: 'Biography', own: false }, class: { value: 'book', from: 12, title: 'Here', own: true } } };
	const inputs = load( { own: { class: 'book' }, info } ).registered.definition.render().children[ 0 ];

	assert.strictEqual( inputs[ 0 ].props.help, 'Inherited: "page-bio" (Biography)' );
	assert.match( inputs[ 1 ].props.help, /Classes added/, 'Set on this page: the usual help.' );
	assert.match( inputs[ 2 ].props.help, /A line shown/ );
} );

test( 'the inputs are read only for a user who may not change the rules', () => {
	const inputs = load( { settings: { canEdit: false } } ).registered.definition.render().children[ 0 ];

	assert.ok( inputs.every( ( input ) => true === input.props.disabled ) );
} );

test( 'the rules are read from the server when the page is loaded, not while it is being saved', () => {
	const loaded = load();

	loaded.registered.definition.render();
	loaded.effects[ 0 ][ 0 ]();

	assert.deepStrictEqual( JSON.parse( JSON.stringify( loaded.fetched ) ), [ { path: '/wiki/v1/rules/12' } ] );
	assert.deepStrictEqual( JSON.parse( JSON.stringify( loaded.effects[ 0 ][ 1 ] ) ), [ 12, 3, false ], 'Read again when the parent changes or a save ends.' );

	const saving = load( { saving: true } );

	saving.registered.definition.render();
	saving.effects[ 0 ][ 0 ]();
	assert.strictEqual( saving.fetched.length, 0 );
} );
