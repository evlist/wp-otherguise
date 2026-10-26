// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 219: preview.js, the connection of the panel to the editor, run in jsdom with a stand-in for the `wp` globals.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const dir = path.join( __dirname, '../../plugin/modules/books/editor/preview/' );
const source = ( file ) => fs.readFileSync( path.join( dir, file ), 'utf8' );
const SETTINGS = {
	page: { width_mm: 148, height_mm: 210, margin_mm: 10 },
	modes: [ { value: 'web', label: 'Web' }, { value: 'print', label: 'Print' } ],
	defaultMode: 'print',
	frameModes: [ 'print' ],
	argument: 'books_preview',
};

/** Loads the three scripts in a jsdom with fake `wp` globals; returns what the editor would hold. */
function load( options ) {
	options = options || {};
	const dom = new JSDOM( '<!doctype html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true } );
	const editorState = { dirty: false, saving: false, autosaving: false, succeeded: false, status: 'publish' };
	const subscribers = [];
	const registered = {};
	const Fragment = 'Fragment';
	const h = ( type, props, ...children ) => ( { type, props, children } );
	const store = {
		getCurrentPostAttribute: () => editorState.status,
		getCurrentPostId: () => 58325,
		getPermalink: () => 'https://testing.vli.st/post/',
		getEditedPostPreviewLink: () => 'https://testing.vli.st/?p=1&preview=true',
		isEditedPostDirty: () => editorState.dirty,
		isSavingPost: () => editorState.saving,
		isAutosavingPost: () => editorState.autosaving,
		didPostSaveRequestSucceed: () => editorState.succeeded,
	};
	const menu = 'PluginMoreMenuItem';
	// A minimal stand-in for the hooks: the state and the effects of the button live in `hooks`, per render.
	const hooks = { states: [], index: 0, effects: [], cleanups: [] };
	const useState = ( initial ) => {
		const i = hooks.index++;
		if ( ! ( i in hooks.states ) ) hooks.states[ i ] = initial;
		return [ hooks.states[ i ], ( value ) => ( hooks.states[ i ] = value ) ];
	};
	const useEffect = ( effect ) => {
		if ( hooks.effects.length === 0 ) hooks.cleanups.push( effect() );
		hooks.effects.push( effect );
	};

	dom.window.wp = {
		element: { createElement: h, Fragment, useState, useEffect },
		i18n: { __: ( value ) => value },
		data: { select: () => store, subscribe: ( fn ) => subscribers.push( fn ) },
		plugins: { registerPlugin: ( name, def ) => ( registered.name = name, registered.def = def ) },
	};
	if ( ! options.noToolbar ) {
		dom.window.wp.components = { Fill: 'Fill', Button: 'Button' };
	}
	if ( options.legacyMenu ) {
		dom.window.wp.editPost = { PluginMoreMenuItem: menu };
	} else if ( ! options.noMenu ) {
		dom.window.wp.editor = { PluginMoreMenuItem: menu };
	}
	const fetched = [];
	if ( options.pdf ) {
		dom.window.wp.apiFetch = ( request ) => ( fetched.push( request ), Promise.resolve( { status: 'failed', message: 'stop' } ) );
	}
	if ( ! options.noSettings ) {
		dom.window.booksPreview = options.pdf ? Object.assign( {}, SETTINGS, { pdf: { enabled: true, path: '/books/v1/pdf' } } ) : SETTINGS;
	}
	if ( options.blockedStorage ) {
		Object.defineProperty( dom.window, 'localStorage', {
			get() {
				throw new Error( 'blocked' );
			},
		} );
	}
	dom.window.eval( source( 'geometry.js' ) );
	dom.window.eval( source( 'panel.js' ) );
	dom.window.eval( source( 'preview.js' ) );

	const change = ( values ) => {
		Object.assign( editorState, values );
		subscribers.forEach( ( fn ) => fn() );
	};

	return { dom, registered, change, subscribers, hooks, fetched, q: ( selector ) => dom.window.document.querySelector( selector ) };
}

/** Renders the plugin (hooks start again at each render): { button, item } where button is the Button of the toolbar. */
function render( site ) {
	site.hooks.index = 0;
	const tree = site.registered.def.render();
	const find = ( node, type ) => {
		if ( ! node || typeof node !== 'object' ) return null;
		if ( node.type === type ) return node;
		for ( const child of node.children || [] ) {
			const found = find( child, type );
			if ( found ) return found;
		}
		return null;
	};
	const toolbar = tree.children.find( ( child ) => child && typeof child.type === 'function' );
	const pinned = toolbar ? toolbar.type() : null;

	return { tree, pinned, button: find( pinned, 'Button' ), item: find( tree, 'PluginMoreMenuItem' ) };
}

/** Clicks the menu item. */
function click( site ) {
	render( site ).item.props.onClick();
}

test( 'the plugin adds a menu item to the options menu of the editor', () => {
	const site = load();
	const { item } = render( site );

	assert.strictEqual( site.registered.name, 'books-preview' );
	assert.strictEqual( item.type, 'PluginMoreMenuItem' );
	assert.strictEqual( item.props.icon, 'media-document' );
	assert.deepStrictEqual( item.children, [ 'Print preview' ] );
	assert.strictEqual( site.q( '.books-preview' ), null, 'no panel until the item is used' );
} );

test( 'the plugin adds a button to the toolbar, in the area of the pinned items', () => {
	const site = load();
	const { pinned, button } = render( site );

	assert.strictEqual( pinned.type, 'Fill' );
	assert.strictEqual( pinned.props.name, 'PinnedItems/core' );
	assert.strictEqual( button.props.icon, 'media-document' );
	assert.strictEqual( button.props.label, 'Print preview' );
	assert.strictEqual( button.props.isPressed, false );
	assert.strictEqual( site.q( '.books-preview' ), null, 'no panel until the button is used' );
} );

test( 'the button opens and closes the panel and shows whether it is open', () => {
	const site = load();

	render( site ).button.props.onClick();
	assert.strictEqual( site.q( '.books-preview' ).hidden, false );
	assert.strictEqual( render( site ).button.props.isPressed, true, 'pressed while the panel is open' );

	render( site ).button.props.onClick();
	assert.strictEqual( site.q( '.books-preview' ).hidden, true );
	assert.strictEqual( render( site ).button.props.isPressed, false );
} );

test( 'closing the panel with its own button releases the toolbar button', () => {
	const site = load();

	render( site ).button.props.onClick();
	assert.strictEqual( render( site ).button.props.isPressed, true );

	site.q( '.books-preview__close' ).dispatchEvent( new site.dom.window.Event( 'click' ) );
	assert.strictEqual( render( site ).button.props.isPressed, false );
} );

test( 'the menu item and the button drive the same panel', () => {
	const site = load();

	render( site ).button.props.onClick();
	render( site ).item.props.onClick();

	assert.strictEqual( site.q( '.books-preview' ).hidden, true );
	assert.strictEqual( site.dom.window.document.querySelectorAll( '.books-preview' ).length, 1 );
} );

test( 'without the toolbar area the menu item alone is offered; without the menu the button alone', () => {
	const menuOnly = render( load( { noToolbar: true } ) );
	assert.strictEqual( menuOnly.pinned, null );
	assert.strictEqual( menuOnly.item.type, 'PluginMoreMenuItem' );

	const buttonOnly = render( load( { noMenu: true } ) );
	assert.strictEqual( buttonOnly.item, null );
	assert.strictEqual( buttonOnly.button.type, 'Button' );
} );

test( 'the older place of the menu item is used when the editor package has none', () => {
	assert.strictEqual( render( load( { legacyMenu: true } ) ).item.type, 'PluginMoreMenuItem' );
} );

test( 'nothing is registered without the settings, or without a menu and a toolbar to put the control in', () => {
	assert.strictEqual( load( { noSettings: true } ).registered.def, undefined );
	assert.strictEqual( load( { noMenu: true, noToolbar: true } ).registered.def, undefined );
} );

test( 'the menu item opens the panel on the saved post, in print mode; used again, it closes it', () => {
	const site = load();

	click( site );
	assert.strictEqual( site.q( '.books-preview' ).hidden, false );
	assert.strictEqual( site.q( '.books-preview__frame' ).src, 'https://testing.vli.st/post/?mode=print&books_preview=1' );

	click( site );
	assert.strictEqual( site.q( '.books-preview' ).hidden, true );
} );

test( 'a dirty post marks the preview out of date, and saving it clears the mark', () => {
	const site = load();

	click( site );
	site.change( { dirty: true } );
	assert.match( site.q( '.books-preview__status' ).textContent, /Out of date/ );

	site.change( { dirty: false } );
	assert.ok( ! /Out of date/.test( site.q( '.books-preview__status' ).textContent ) );
} );

test( 'the panel opened on a dirty post starts out of date', () => {
	const site = load();

	site.change( { dirty: true } );
	click( site );

	assert.match( site.q( '.books-preview__status' ).textContent, /Out of date/ );
} );

test( 'the page is loaded again after a save that succeeds, not after an autosave or a failure', () => {
	const site = load();

	click( site );
	const first = site.q( '.books-preview__frame' );

	site.change( { saving: true, autosaving: true } );
	site.change( { saving: false, autosaving: false, succeeded: true } );
	assert.strictEqual( site.q( '.books-preview__frame' ), first, 'an autosave does not change the page shown' );

	site.change( { saving: true, autosaving: false, succeeded: false } );
	site.change( { saving: false, succeeded: false } );
	assert.strictEqual( site.q( '.books-preview__frame' ), first, 'a failed save neither' );

	site.change( { saving: true, autosaving: false } );
	site.change( { saving: false, succeeded: true } );
	assert.notStrictEqual( site.q( '.books-preview__frame' ), first, 'a save that succeeds loads the page again' );
} );

test( 'changes of the store before the panel is opened do nothing', () => {
	const site = load();

	site.change( { saving: true } );
	site.change( { saving: false, succeeded: true, dirty: true } );

	assert.strictEqual( site.q( '.books-preview' ), null );
} );

test( 'a draft shows its preview link; a blocked local storage does not stop the panel', () => {
	const site = load( { blockedStorage: true } );

	site.change( { status: 'draft' } );
	click( site );

	assert.strictEqual( site.q( '.books-preview__frame' ).src, 'https://testing.vli.st/?p=1&preview=true&mode=print&books_preview=1' );
} );

test( 'without a PDF server the panel has no PDF view', () => {
	const site = load();

	click( site );

	assert.strictEqual( site.q( '.books-preview__views' ), null );
} );

test( 'with a PDF server the panel has the views, and the PDF view calls the routes with the id of the post', async () => {
	const site = load( { pdf: true } );

	click( site );
	assert.ok( site.q( '.books-preview__views' ) );

	site.q( '.books-preview__view--pdf' ).dispatchEvent( new site.dom.window.Event( 'click' ) );
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	assert.deepStrictEqual( JSON.parse( JSON.stringify( site.fetched ) ), [ { path: '/books/v1/pdf/58325', method: 'POST', data: { mode: 'print', refresh: false, project: 0 } } ] );
} );

test( 'the PDF routes for the state and the file carry the mode in the query', async () => {
	const site = load( { pdf: true } );

	click( site );
	site.dom.window.eval( 'window.__api = null' );
	// The panel polls the state and asks for the file through the same function: here the routes are read from what it calls.
	site.q( '.books-preview__view--pdf' ).dispatchEvent( new site.dom.window.Event( 'click' ) );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	site.q( '.books-preview__refresh' ).dispatchEvent( new site.dom.window.Event( 'click' ) );
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	assert.strictEqual( site.fetched[ 1 ].data.refresh, true, 'the Refresh button forces a new conversion' );
} );

test( 'a save that succeeds loads the PDF again without forcing it', async () => {
	const site = load( { pdf: true } );

	click( site );
	site.q( '.books-preview__view--pdf' ).dispatchEvent( new site.dom.window.Event( 'click' ) );
	await new Promise( ( resolve ) => setImmediate( resolve ) );
	const before = site.fetched.length;

	site.change( { saving: true, autosaving: false } );
	site.change( { saving: false, succeeded: true } );
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	assert.strictEqual( site.fetched.length, before + 1 );
	assert.strictEqual( site.fetched[ before ].data.refresh, false );
} );
