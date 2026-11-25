// SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
// SPDX-License-Identifier: GPL-3.0-or-later
//
// Slice 633: the quick edit of the table of the assignments, run in jsdom with a fake REST API.
const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { JSDOM } = require( 'jsdom' );
const { attach } = require( '../../plugin/modules/index/admin/quick-edit.js' );
const picker = require( '../../plugin/modules/index/admin/topic-picker.js' );

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 5 ) );
const words = { change: 'Change topic', close: 'Close topics', choose: '— a close topic —', search: 'search', update: 'Update', cancel: 'Cancel', anyway: 'Assign anyway', nothing: 'Choose a topic.', failed: 'Failed', working: 'Working…', exclude: 'Not in the index (exclude this motif)', excluded: 'Not in the index' };

function make( answers ) {
	const dom = new JSDOM( '<!doctype html><body><table><tbody>' +
		'<tr id="r1"><th class="check-column"><input type="checkbox" name="designation[]" value="7"></th><td class="column-designation"><strong>aisne (02)</strong><div class="row-actions"><span class="posts"><a href="#p">Posts</a></span></div></td><td class="column-topic"><a href="#old">aisne (02)</a> <small>Place</small></td><td class="column-status">candidate</td><td class="column-by">system</td><td class="column-state">assigned</td></tr>' +
		'<tr id="r2"><th class="check-column"><input type="checkbox" name="designation[]" value="8"></th><td class="column-designation"><strong>autre</strong></td><td class="column-topic">—</td><td class="column-by">—</td><td class="column-state">pending</td></tr>' +
		'</tbody></table></body>' );
	const doc = dom.window.document;
	const calls = [];
	const api = {
		call: ( path, method, body ) => {
			calls.push( { path, method, body } );
			const answer = answers[ method + ' ' + path ];
			return Promise.resolve( typeof answer === 'function' ? answer( body ) : answer );
		},
		search: () => Promise.resolve( [] ),
	};
	const quick = attach( { document: doc, words, api, picker, topics: {} } );
	return { dom, doc, calls, quick };
}

test( 'every line gets the link, also the line that has no actions', () => {
	const { doc } = make( {} );

	assert.strictEqual( doc.querySelectorAll( '.change-topic a' ).length, 2 );
	assert.ok( doc.querySelector( '#r2 .column-topic .row-actions .change-topic' ), 'The link is in the column of the topic.' );
} );

test( 'the panel offers the close topics, and Update changes the line in place', async () => {
	const { doc, calls } = make( {
		'GET 7/neighbours': [ { id: 5, label: 'Aisne', display: 'Aisne (France)', kind: 'River', chain: 'France' } ],
		'POST 7/topic': { ok: true, topic: { id: 5, label: 'Aisne', display: 'Aisne (France)', kind: 'River', chain: 'France', url: 'https://example.test/topic5' }, by: 'hand', state: 'assigned', status: 'approved' },
	} );

	doc.querySelector( '#r1 .change-topic a' ).click();
	await flush();

	const select = doc.querySelector( '.otherguise-quick-edit select' );

	assert.strictEqual( select.options.length, 3, 'The first option says to choose, the last one excludes the motif.' );
	assert.match( select.options[ 1 ].textContent, /^Aisne \(France\) \u00b7 River #5$/ );

	doc.querySelector( '.otherguise-quick-edit button.button-primary' ).click();
	await flush();
	assert.match( doc.querySelector( '.otherguise-quick-edit__message' ).textContent, /Choose a topic/, 'Nothing is sent without a topic.' );

	select.value = '5';
	select.dispatchEvent( new doc.defaultView.Event( 'change' ) );
	doc.querySelector( '.otherguise-quick-edit button.button-primary' ).click();
	await flush();

	assert.deepStrictEqual( calls[ 1 ], { path: '7/topic', method: 'POST', body: { topic: 5, force: false } } );
	assert.strictEqual( doc.querySelector( '#r1 .column-by' ).textContent, 'hand' );
	assert.strictEqual( doc.querySelector( '#r1 .column-status' ).textContent, 'approved', 'The status of the new topic (slice 637).' );
	assert.strictEqual( doc.querySelector( '#r1 .column-topic a' ).getAttribute( 'href' ), 'https://example.test/topic5', 'The topic has its link, like the lines that were not changed.' );
	assert.strictEqual( doc.querySelector( '#r1 .column-topic a' ).textContent, 'Aisne (France)', 'The label as the index prints it (slice 639).' );
	assert.strictEqual( doc.querySelector( '#r1 .column-topic small' ).textContent, 'River', 'Then the kind.' );
	assert.ok( doc.querySelector( '#r1 .column-topic .change-topic' ), 'The link to change it again stays.' );
	assert.strictEqual( doc.querySelector( '.otherguise-quick-edit' ), null, 'The panel is closed.' );
} );

test( 'kinds that exclude each other are said, and the same button then assigns anyway', async () => {
	let sent = null;
	const { doc } = make( {
		'GET 8/neighbours': [ { id: 9, label: 'Moulins', kind: 'Commune', chain: '' } ],
		'POST 8/topic': ( body ) => {
			sent = body;
			return body.force ? { ok: true, topic: { id: 9, label: 'Moulins', kind: 'Commune', chain: '' }, by: 'hand', state: 'assigned' } : { ok: false, conflict: true, error: 'Kinds exclude each other.' };
		},
	} );

	doc.querySelector( '#r2 .change-topic a' ).click();
	await flush();
	doc.querySelector( '.otherguise-quick-edit select' ).value = '9';
	doc.querySelector( '.otherguise-quick-edit button.button-primary' ).click();
	await flush();

	assert.match( doc.querySelector( '.otherguise-quick-edit__message' ).textContent, /Kinds exclude/ );
	assert.strictEqual( doc.querySelector( '.otherguise-quick-edit button.button-primary' ).textContent, 'Assign anyway' );
	assert.strictEqual( doc.querySelector( '#r2 .column-by' ).textContent, '—', 'Nothing changed yet.' );

	doc.querySelector( '.otherguise-quick-edit button.button-primary' ).click();
	await flush();

	assert.strictEqual( sent.force, true );
	assert.strictEqual( doc.querySelector( '#r2 .column-by' ).textContent, 'hand' );
} );

test( 'Cancel closes the panel and a second line replaces the first panel', async () => {
	const { doc } = make( { 'GET 7/neighbours': [], 'GET 8/neighbours': [] } );

	doc.querySelector( '#r1 .change-topic a' ).click();
	doc.querySelector( '#r2 .change-topic a' ).click();
	await flush();
	assert.strictEqual( doc.querySelectorAll( '.otherguise-quick-edit' ).length, 1 );

	doc.querySelector( '.otherguise-quick-edit button:not(.button-primary)' ).click();
	assert.strictEqual( doc.querySelector( '.otherguise-quick-edit' ), null );
} );

test( 'the last choice takes the motif out of the index, and the line says so (slice 635)', async () => {
	let sent = null;
	const { doc } = make( {
		'GET 7/neighbours': [ { id: 5, label: 'Aisne', display: 'Aisne (France)', kind: 'River', chain: 'France' } ],
		'POST 7/topic': ( body ) => {
			sent = body;
			return { ok: true, excluded: true, by: 'hand', state: 'excluded', status: '' };
		},
	} );

	doc.querySelector( '#r1 .change-topic a' ).click();
	await flush();

	const select = doc.querySelector( '.otherguise-quick-edit select' );

	assert.strictEqual( select.options[ select.options.length - 1 ].value, 'exclude', 'Always the last choice.' );

	select.value = 'exclude';
	doc.querySelector( '.otherguise-quick-edit button.button-primary' ).click();
	await flush();

	assert.deepStrictEqual( sent, { exclude: true } );
	assert.strictEqual( doc.querySelector( '#r1 .column-topic em' ).textContent, 'Not in the index' );
	assert.strictEqual( doc.querySelector( '#r1 .column-state' ).textContent, 'excluded' );
	assert.strictEqual( doc.querySelector( '#r1 .column-status' ).textContent, '\u2014', 'No topic: no status.' );
	assert.ok( doc.querySelector( '#r1 .column-topic .change-topic' ), 'The link to change it stays.' );
	assert.strictEqual( doc.querySelector( '#r1 .column-topic a[href="#old"]' ), null, 'The old topic is gone from the line.' );
} );
