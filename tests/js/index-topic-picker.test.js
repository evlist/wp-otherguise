// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 630: the field that chooses a topic, run in jsdom with a fake search.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const { mount } = require( '../../plugin/modules/index/admin/topic-picker.js' );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 5 ) );

function make( answers ) {
	const dom = new JSDOM( '<!doctype html><body><p><input id="label" type="text"><input id="to" type="hidden"></p></body>' );
	const doc = dom.window.document;
	const calls = [];
	const api = {
		search: ( text ) => {
			calls.push( text );
			return Promise.resolve( answers[ text ] || [] );
		},
	};
	const picker = mount( { document: doc, input: doc.getElementById( 'label' ), hidden: doc.getElementById( 'to' ), api, wait: 0 } );
	const type = ( text ) => {
		doc.getElementById( 'label' ).value = text;
		doc.getElementById( 'label' ).dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	};
	return { dom, doc, calls, picker, type, list: () => doc.querySelector( '.topic-picker__list' ) };
}

test( 'typing searches, the suggestions tell the kind and the topics above, a click chooses', async () => {
	const s = make( { moul: [ { id: 7, label: 'Moulins', kind: 'Commune', chain: 'France > Auvergne > Allier' }, { id: 9, label: 'Moulins', kind: 'Waterway', chain: '' } ] } );

	s.type( 'moul' );
	await flush();

	const items = s.list().querySelectorAll( 'li' );

	assert.deepStrictEqual( s.calls, [ 'moul' ] );
	assert.strictEqual( items.length, 2 );
	assert.match( items[ 0 ].textContent, /Moulins \(Commune, France > Auvergne > Allier\) #7/ );
	assert.strictEqual( s.list().hidden, false );

	items[ 0 ].dispatchEvent( new s.dom.window.Event( 'click', { bubbles: true } ) );

	assert.strictEqual( s.doc.getElementById( 'to' ).value, '7' );
	assert.strictEqual( s.doc.getElementById( 'label' ).value, 'Moulins' );
	assert.strictEqual( s.list().hidden, true, 'The list closes.' );
} );

test( 'one letter is not searched, and typing again forgets the choice', async () => {
	const s = make( { moul: [ { id: 7, label: 'Moulins', kind: 'Commune', chain: '' } ] } );

	s.type( 'm' );
	await flush();
	assert.deepStrictEqual( s.calls, [], 'Too short to search.' );

	s.type( 'moul' );
	await flush();
	s.list().querySelector( 'li' ).dispatchEvent( new s.dom.window.Event( 'click', { bubbles: true } ) );
	assert.strictEqual( s.doc.getElementById( 'to' ).value, '7' );

	s.type( 'moulins-sur' );
	assert.strictEqual( s.doc.getElementById( 'to' ).value, '', 'The number is forgotten as soon as the text changes.' );
} );

test( 'an answer that comes late is ignored, and a failed search closes the list', async () => {
	const dom = new JSDOM( '<!doctype html><body><p><input id="label" type="text"><input id="to" type="hidden"></p></body>' );
	const doc = dom.window.document;
	const waiting = {};
	const api = { search: ( text ) => new Promise( ( resolve, reject ) => { waiting[ text ] = { resolve, reject }; } ) };

	mount( { document: doc, input: doc.getElementById( 'label' ), hidden: doc.getElementById( 'to' ), api, wait: 0 } );

	const type = ( text ) => {
		doc.getElementById( 'label' ).value = text;
		doc.getElementById( 'label' ).dispatchEvent( new dom.window.Event( 'input', { bubbles: true } ) );
	};

	type( 'mo' );
	await flush();
	type( 'mou' );
	await flush();
	waiting.mou.resolve( [ { id: 1, label: 'Mouans', kind: '', chain: '' } ] );
	waiting.mo.resolve( [ { id: 2, label: 'Montpellier', kind: '', chain: '' } ] );
	await flush();

	const items = doc.querySelectorAll( '.topic-picker__list li' );

	assert.strictEqual( items.length, 1 );
	assert.match( items[ 0 ].textContent, /Mouans/ );

	type( 'moul' );
	await flush();
	waiting.moul.reject( new Error( 'refused' ) );
	await flush();

	assert.strictEqual( doc.querySelector( '.topic-picker__list' ).hidden, true );
} );

test( 'destroying the picker removes its list and stops listening', async () => {
	const s = make( { moul: [ { id: 7, label: 'Moulins', kind: '', chain: '' } ] } );

	s.picker.destroy();
	s.type( 'moul' );
	await flush();

	assert.strictEqual( s.list(), null );
	assert.deepStrictEqual( s.calls, [] );
} );

test( 'the box of the target shows only while the action that needs it is chosen (slice 632)', () => {
	const { showWhenChosen } = require( '../../plugin/modules/index/admin/topic-picker.js' );
	const dom = new JSDOM( '<!doctype html><body><select id="bulk-action-selector-top"><option value="-1"></option><option value="assign"></option><option value="delete"></option></select><fieldset id="box"></fieldset><select id="bulk-action-selector-bottom"><option value="-1"></option><option value="assign"></option></select></body>' );
	const doc = dom.window.document;
	const box = doc.getElementById( 'box' );

	showWhenChosen( { document: doc, box, action: 'assign' } );
	assert.strictEqual( box.hidden, true, 'Hidden while no action is chosen.' );

	doc.getElementById( 'bulk-action-selector-top' ).value = 'assign';
	doc.getElementById( 'bulk-action-selector-top' ).dispatchEvent( new dom.window.Event( 'change' ) );
	assert.strictEqual( box.hidden, false );

	doc.getElementById( 'bulk-action-selector-top' ).value = 'delete';
	doc.getElementById( 'bulk-action-selector-top' ).dispatchEvent( new dom.window.Event( 'change' ) );
	assert.strictEqual( box.hidden, true );

	doc.getElementById( 'bulk-action-selector-bottom' ).value = 'assign';
	doc.getElementById( 'bulk-action-selector-bottom' ).dispatchEvent( new dom.window.Event( 'change' ) );
	assert.strictEqual( box.hidden, false, 'The list at the bottom counts too.' );
} );
