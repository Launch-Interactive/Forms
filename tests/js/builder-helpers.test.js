'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const h = require( '../../assets/js/builder.js' );

test( 'slugify mirrors PHP Form_Config::sanitize_name', () => {
	assert.equal( h.slugify( 'Your Name!' ), 'your_name' );
	assert.equal( h.slugify( 'Émail address' ), 'email_address' );
	assert.equal( h.slugify( '1st choice' ), 'f_1st_choice' );
	assert.equal( h.slugify( '  --  ' ), '' );
	assert.equal( h.slugify( 'x'.repeat( 100 ) ).length, 64 );
} );

test( 'uniqueName avoids collisions, ignoring the field being edited', () => {
	const fields = [ { name: 'email' }, { name: 'email_2' } ];
	assert.equal( h.uniqueName( 'email', fields, -1 ), 'email_3' );
	assert.equal( h.uniqueName( 'email', fields, 0 ), 'email' );
	assert.equal( h.uniqueName( 'phone', fields, -1 ), 'phone' );
} );

test( 'createField produces sane defaults per type', () => {
	const labels = { text: 'Text', select: 'Dropdown', file: 'File upload', content: 'Content', number: 'Number' };
	const text = h.createField( 'text', [], labels );
	assert.equal( text.name, 'text' );
	assert.equal( text.conditions.enabled, false );
	assert.equal( h.createField( 'text', [ text ], labels ).name, 'text_2' );
	assert.equal( h.createField( 'select', [], labels ).options.length, 2 );
	assert.deepEqual( h.createField( 'file', [], labels ).allowed_types, [ 'jpg', 'jpeg', 'png', 'pdf' ] );
	assert.equal( h.createField( 'content', [], labels ).label, '' );
	assert.equal( h.createField( 'number', [], labels ).min, '' );
	assert.equal( text.width, 'full' );
} );

test( 'options text round-trip', () => {
	const options = h.textToOptions( 'Red|r\nBlue\n\n  Green | g  ' );
	assert.deepEqual( options, [
		{ label: 'Red', value: 'r' },
		{ label: 'Blue', value: 'Blue' },
		{ label: 'Green', value: 'g' },
	] );
	assert.equal( h.optionsToText( options ), 'Red|r\nBlue\nGreen|g' );
} );

test( 'moveItem', () => {
	assert.deepEqual( h.moveItem( [ 1, 2, 3 ], 0, 2 ), [ 2, 3, 1 ] );
	assert.deepEqual( h.moveItem( [ 1, 2, 3 ], 0, -1 ), [ 1, 2, 3 ] );
} );

test( 'renameReferences updates conditions, mailchimp and merge tags', () => {
	const config = {
		fields: [
			{ name: 'email' },
			{ name: 'note', conditions: { enabled: true, rules: [ { field: 'email', operator: 'not_empty', value: '' } ] } },
		],
		notifications: [ { to: '{field:email}', subject: 'From {field:email}', message: '{all_fields}', reply_to: '{field:email}', from_name: '' } ],
		mailchimp: { email_field: 'email', consent_field: '', merge_fields: [ { tag: 'EMAIL2', field: 'email' } ] },
	};
	const out = h.renameReferences( config, 'email', 'work_email' );
	assert.equal( out.fields[ 1 ].conditions.rules[ 0 ].field, 'work_email' );
	assert.equal( out.notifications[ 0 ].to, '{field:work_email}' );
	assert.equal( out.notifications[ 0 ].subject, 'From {field:work_email}' );
	assert.equal( out.notifications[ 0 ].reply_to, '{field:work_email}' );
	assert.equal( out.mailchimp.email_field, 'work_email' );
	assert.equal( out.mailchimp.merge_fields[ 0 ].field, 'work_email' );
	assert.equal( config.mailchimp.email_field, 'email', 'Input is not mutated.' );
	assert.equal( h.renameReferences( config, 'email', 'email' ), config );
} );

test( 'removeReferences drops rules and mailchimp mappings', () => {
	const config = {
		fields: [ { name: 'a' }, { name: 'b', conditions: { enabled: true, rules: [ { field: 'a', operator: 'empty' } ] } } ],
		mailchimp: { email_field: 'a', consent_field: 'a', merge_fields: [ { tag: 'X', field: 'a' } ] },
	};
	const out = h.removeReferences( config, 'a' );
	assert.deepEqual( out.fields[ 1 ].conditions.rules, [] );
	assert.equal( out.fields[ 1 ].conditions.enabled, false );
	assert.equal( out.mailchimp.email_field, '' );
	assert.equal( out.mailchimp.consent_field, '' );
	assert.deepEqual( out.mailchimp.merge_fields, [] );
} );

test( 'parseConfig tolerates bad JSON and merges defaults', () => {
	const defaults = { settings: { submit_label: 'Submit', captcha: 'none' }, notifications: [ { to: '{admin_email}' } ], mailchimp: { enabled: false } };
	const bad = h.parseConfig( '{not json', defaults );
	assert.deepEqual( bad.fields, [] );
	assert.equal( bad.settings.submit_label, 'Submit' );
	assert.equal( bad.notifications.length, 1 );
	const good = h.parseConfig( '{"fields":[{"name":"x"}],"settings":{"submit_label":"Go"},"notifications":[]}', defaults );
	assert.equal( good.fields[ 0 ].name, 'x' );
	assert.equal( good.settings.submit_label, 'Go' );
	assert.equal( good.settings.captcha, 'none' );
	assert.deepEqual( good.notifications, [], 'An explicitly empty notification list is kept.' );
} );

test( 'dropIndex counts the other items whose midpoint is above the dragged centre', () => {
	const rects = [ { top: 0, height: 40 }, { top: 48, height: 40 }, { top: 96, height: 40 } ];
	// Not moved: lands where it started.
	assert.equal( h.dropIndex( rects, 0, 20 ), 0 );
	assert.equal( h.dropIndex( rects, 1, 68 ), 1 );
	// First item dragged past the second's midpoint (68) but not the third's (116).
	assert.equal( h.dropIndex( rects, 0, 70 ), 1 );
	assert.equal( h.dropIndex( rects, 0, 130 ), 2 );
	// Last item dragged above everything.
	assert.equal( h.dropIndex( rects, 2, 5 ), 0 );
	assert.equal( h.dropIndex( rects, 2, 60 ), 1 );
} );

test( 'movedIndex tracks where an item ends up after moveItem', () => {
	const list = [ 'a', 'b', 'c', 'd', 'e' ];
	for ( let from = 0; from < list.length; from++ ) {
		for ( let to = 0; to < list.length; to++ ) {
			const moved = h.moveItem( list, from, to );
			list.forEach( ( item, i ) => {
				assert.equal( moved[ h.movedIndex( i, from, to ) ], item, `${ item } from=${ from } to=${ to }` );
			} );
		}
	}
} );

test( 'mergeTags lists general tags and one per field (not content blocks)', () => {
	const tags = h.mergeTags( { fields: [ { name: 'email', type: 'email' }, { name: 'intro', type: 'content' } ] } );
	assert.ok( tags.includes( '{all_fields}' ) );
	assert.ok( tags.includes( '{field:email}' ) );
	assert.ok( ! tags.includes( '{field:intro}' ) );
} );

test( 'renaming a field updates the success message tags', () => {
	const out = h.renameReferences(
		{ fields: [ { name: 'email' } ], notifications: [], mailchimp: {}, settings: { success_message: 'Sent to {field:email}.', submit_label: 'Go' } },
		'email',
		'work_email'
	);
	assert.equal( out.settings.success_message, 'Sent to {field:work_email}.' );
	assert.equal( out.settings.submit_label, 'Go' );
} );

test( 'captcha fields: created without a label, not inputs, excluded from merge tags', () => {
	const field = h.createField( 'turnstile', [], { turnstile: 'Cloudflare Turnstile' } );
	assert.equal( field.label, '' );
	assert.equal( field.name, 'turnstile' );
	assert.ok( h.isCaptcha( field ) );
	assert.ok( ! h.isInput( field ) );
	assert.ok( ! h.mergeTags( { fields: [ field ] } ).includes( '{field:turnstile}' ) );
} );

test( 'normalizeConfig moves the old captcha setting into a field once', () => {
	const config = h.normalizeConfig( { fields: [ { type: 'text', name: 'name' } ], settings: { captcha: 'recaptcha' } }, {} );
	assert.deepEqual( config.fields.map( ( f ) => f.type ), [ 'text', 'recaptcha' ] );
	assert.equal( config.settings.captcha, 'none' );
	assert.equal( h.normalizeConfig( config, {} ).fields.length, 2 );
	const placed = h.normalizeConfig( { fields: [ { type: 'turnstile', name: 'turnstile' } ], settings: { captcha: 'recaptcha' } }, {} );
	assert.deepEqual( placed.fields.map( ( f ) => f.type ), [ 'turnstile' ] );
} );
