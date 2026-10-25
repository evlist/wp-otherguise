// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 400: the "Share link" panel of the post editor, run in jsdom with a fake API.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const { mount } = require( '../../plugin/modules/access/editor/link/link-panel.js' );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const click = ( node ) => node.dispatchEvent( new node.ownerDocument.defaultView.Event( 'click', { bubbles: true } ) );
const URL_ONE = 'https://example.test/?shared=AbCdEfGhIjKlMnOpQrStUv';
const URL_TWO = 'https://example.test/?shared=ZyXwVuTsRqPoNmLkJiHgFe';

/** Mounts the panel for post 12 over a fake API: `context.link` is what the server holds. */
function make( link, extra ) {
	const dom = new JSDOM( '<!doctype html><body><div id="host"></div></body>' );
	const calls = [];
	const context = Object.assign( { link: link || { active: false, url: '', created: '' }, fail: null, next: URL_TWO }, extra );
	const answer = ( name, work ) => ( ...args ) => {
		calls.push( [ name ].concat( args ) );
		if ( context.fail === name ) {
			return Promise.reject( { message: 'Refused: ' + name } );
		}
		work && work( ...args );
		return Promise.resolve( Object.assign( { id: 12 }, context.link ) );
	};
	const api = {
		state: answer( 'state' ),
		create: answer( 'create', ( id, regenerate ) => {
			context.link = { active: true, url: regenerate || ! context.link.active ? context.next : context.link.url, created: '2026-10-25 09:00:00' };
		} ),
		revoke: answer( 'revoke', () => {
			context.link = { active: false, url: '', created: '' };
		} ),
	};
	const clipboard = context.clipboard === undefined ? { writeText: ( text ) => ( context.copied = text, Promise.resolve() ) } : context.clipboard;
	const panel = mount( { document: dom.window.document, container: dom.window.document.getElementById( 'host' ), postId: 12, api, clipboard } );
	return { dom, panel, calls, context, q: ( selector ) => dom.window.document.querySelector( selector ) };
}

test( 'it says it is loading, then that there is no link, with a button to make one', async () => {
	const { q, calls } = make();

	assert.strictEqual( q( '.access-link__loading' ).textContent, 'Loading…' );
	assert.deepStrictEqual( calls, [ [ 'state', 12 ] ] );
	await flush();

	assert.match( q( '.access-link__none' ).textContent, /No link/ );
	assert.strictEqual( q( '.access-link__url' ), null );
	assert.strictEqual( q( '.access-link__create' ).textContent, 'Create the link' );
} );

test( 'creating the link shows its address, the date and the warning', async () => {
	const { q, calls } = make();
	await flush();

	click( q( '.access-link__create' ) );
	await flush();

	assert.deepStrictEqual( calls[ 1 ], [ 'create', 12, false ] );
	assert.strictEqual( q( '.access-link__url' ).value, URL_TWO );
	assert.strictEqual( q( '.access-link__url' ).readOnly, true );
	assert.strictEqual( q( '.access-link__url' ).getAttribute( 'aria-label' ), 'Address of the link' );
	assert.match( q( '.access-link__warning' ).textContent, /Anyone who has this address can read this post/ );
	assert.strictEqual( q( '.access-link__created' ).textContent, 'Made on 2026-10-25 (GMT)' );
	assert.strictEqual( q( '.access-link__create' ), null );
} );

test( 'with a link already, the panel shows it at once', async () => {
	const { q } = make( { active: true, url: URL_ONE, created: '' } );
	await flush();

	assert.strictEqual( q( '.access-link__url' ).value, URL_ONE );
	assert.strictEqual( q( '.access-link__created' ), null, 'No date: none is shown.' );
} );

test( 'copying puts the address in the clipboard and says so', async () => {
	const { q, context } = make( { active: true, url: URL_ONE, created: '' } );
	await flush();

	click( q( '.access-link__copy' ) );
	await flush();

	assert.strictEqual( context.copied, URL_ONE );
	assert.strictEqual( q( '.access-link__message' ).textContent, 'Copied.' );
	assert.strictEqual( q( '.access-link__message' ).getAttribute( 'role' ), 'status' );
} );

test( 'without a clipboard, or when it refuses, the address is selected and the person is asked to copy it', async () => {
	const none = make( { active: true, url: URL_ONE, created: '' }, { clipboard: null } );
	await flush();
	click( none.q( '.access-link__copy' ) );

	assert.strictEqual( none.q( '.access-link__message' ).textContent, 'Select the address and copy it.' );

	const refusing = make( { active: true, url: URL_ONE, created: '' }, { clipboard: { writeText: () => Promise.reject( new Error( 'denied' ) ) } } );
	await flush();
	click( refusing.q( '.access-link__copy' ) );
	await flush();

	assert.strictEqual( refusing.q( '.access-link__message' ).textContent, 'Select the address and copy it.' );
} );

test( 'replacing the link asks first: No cancels, Yes makes a new one', async () => {
	const { q, calls, context } = make( { active: true, url: URL_ONE, created: '2026-10-24 08:00:00' } );
	await flush();

	click( q( '.access-link__regenerate' ) );

	assert.match( q( '.access-link__ask' ).textContent, /Replace the link\? The old one stops working/ );
	assert.strictEqual( calls.length, 1, 'Nothing is asked of the server yet.' );

	click( q( '.access-link__no' ) );

	assert.strictEqual( q( '.access-link__ask' ), null );
	assert.strictEqual( q( '.access-link__url' ).value, URL_ONE );

	click( q( '.access-link__regenerate' ) );
	click( q( '.access-link__yes' ) );
	await flush();

	assert.deepStrictEqual( calls[ calls.length - 1 ], [ 'create', 12, true ] );
	assert.strictEqual( q( '.access-link__url' ).value, URL_TWO );
	assert.strictEqual( context.link.url, URL_TWO );
	assert.strictEqual( q( '.access-link__ask' ), null, 'The question is gone.' );
} );

test( 'revoking asks first, then the panel says there is no link', async () => {
	const { q, calls } = make( { active: true, url: URL_ONE, created: '2026-10-24 08:00:00' } );
	await flush();

	click( q( '.access-link__revoke' ) );

	assert.strictEqual( q( '.access-link__ask' ).textContent, 'Revoke the link?' );

	click( q( '.access-link__yes' ) );
	await flush();

	assert.deepStrictEqual( calls[ calls.length - 1 ], [ 'revoke', 12 ] );
	assert.strictEqual( q( '.access-link__url' ), null );
	assert.ok( q( '.access-link__create' ) );
} );

test( 'a failing request shows its message and keeps the link that was there', async () => {
	const { q, context } = make( { active: true, url: URL_ONE, created: '' } );
	await flush();

	context.fail = 'revoke';
	click( q( '.access-link__revoke' ) );
	click( q( '.access-link__yes' ) );
	await flush();

	assert.strictEqual( q( '.access-link__error' ).textContent, 'Refused: revoke' );
	assert.strictEqual( q( '.access-link__error' ).getAttribute( 'role' ), 'alert' );
	assert.strictEqual( q( '.access-link__url' ).value, URL_ONE );
	assert.strictEqual( q( '.access-link__ask' ), null );

	const down = make( null, { fail: 'state' } );
	await flush();

	assert.strictEqual( down.q( '.access-link__error' ).textContent, 'Refused: state' );
	assert.strictEqual( down.q( '.access-link__loading' ).textContent, 'Loading…', 'Still not loaded.' );
} );

test( 'the panel can be refreshed and removed', async () => {
	const { q, panel, dom, context } = make( { active: true, url: URL_ONE, created: '' } );
	await flush();

	context.link = { active: true, url: URL_TWO, created: '' };
	await panel.refresh();

	assert.strictEqual( q( '.access-link__url' ).value, URL_TWO );

	panel.destroy();
	assert.strictEqual( dom.window.document.querySelector( '.access-link' ), null );
} );
