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

test( 'labels with links (consent) give clean titles and keys', () => {
	const label = 'I agree to the <a href="#terms">terms &amp; conditions</a>';
	assert.equal( h.stripTags( label ), 'I agree to the terms & conditions' );
	assert.equal( h.slugify( label ), 'i_agree_to_the_terms_conditions' );
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

test( 'removeReferences drops rules and mailchimp mappings but keeps the consent gate', () => {
	const config = {
		fields: [ { name: 'a' }, { name: 'b', conditions: { enabled: true, rules: [ { field: 'a', operator: 'empty' } ] } } ],
		mailchimp: { email_field: 'a', consent_field: 'a', merge_fields: [ { tag: 'X', field: 'a' } ] },
	};
	const out = h.removeReferences( config, 'a' );
	assert.deepEqual( out.fields[ 1 ].conditions.rules, [] );
	assert.equal( out.fields[ 1 ].conditions.enabled, false );
	assert.equal( out.mailchimp.email_field, '' );
	assert.equal( out.mailchimp.consent_field, 'a' ); // Kept, so nobody is subscribed without consent.
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

test( 'dropIndex: an item passes another when its leading edge crosses that item\'s midpoint', () => {
	// Three 40px items with 8px gaps: tops 0, 48, 96; midpoints 20, 68, 116.
	const rects = [ { top: 0, height: 40 }, { top: 48, height: 40 }, { top: 96, height: 40 } ];
	assert.equal( h.dropIndex( rects, 0, 0 ), 0, 'Not moved.' );
	assert.equal( h.dropIndex( rects, 0, 27 ), 0, 'Bottom edge (67) short of the next midpoint.' );
	assert.equal( h.dropIndex( rects, 0, 28 ), 1, 'Bottom edge reaches 68.' );
	assert.equal( h.dropIndex( rects, 1, 20 ), 0, 'Moving up: top edge reaches the first midpoint.' );
	assert.equal( h.dropIndex( rects, 1, 21 ), 1 );
} );

test( 'dropIndex: items can be dragged to the very bottom and top while kept inside the list', () => {
	const rects = [ { top: 0, height: 40 }, { top: 48, height: 40 }, { top: 96, height: 40 } ];
	// The builder clamps the dragged item so it never goes past the list's edges.
	const lowest = ( from ) => rects[ 2 ].top + rects[ 2 ].height - rects[ from ].height;
	assert.equal( h.dropIndex( rects, 0, lowest( 0 ) ), 2, 'Top item to the bottom (the reported bug).' );
	assert.equal( h.dropIndex( rects, 1, lowest( 1 ) ), 2 );
	assert.equal( h.dropIndex( rects, 2, 0 ), 0, 'Bottom item to the top.' );

	// A tall (expanded) item above a short last item, and a short item below a tall one.
	const mixed = [ { top: 0, height: 300 }, { top: 308, height: 40 }, { top: 356, height: 40 } ];
	assert.equal( h.dropIndex( mixed, 0, 396 - 300 ), 2, 'Expanded item to the bottom.' );
	assert.equal( h.dropIndex( mixed, 2, 0 ), 0, 'Short item past a tall one to the top.' );
	assert.equal( h.dropIndex( mixed, 2, 200 ), 1, 'Not yet past the tall item\'s midpoint (150).' );
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

test( 'suggestZohoMap guesses lead fields from field names and types', () => {
	const map = h.suggestZohoMap( [
		{ type: 'text', name: 'name' },
		{ type: 'email', name: 'your_email' },
		{ type: 'tel', name: 'phone' },
		{ type: 'text', name: 'company' },
		{ type: 'textarea', name: 'message' },
		{ type: 'recaptcha', name: 'captcha' },
		{ type: 'text', name: 'favourite_colour' },
	] );
	assert.deepEqual(
		map.map( ( m ) => [ m.zoho, m.field ] ),
		[
			[ h.ZOHO_FULL_NAME, 'name' ],
			[ 'Email', 'your_email' ],
			[ 'Phone', 'phone' ],
			[ 'Company', 'company' ],
			[ 'Description', 'message' ],
		]
	);
} );

test( 'suggestZohoMap prefers separate first and last name fields over a full name', () => {
	const map = h.suggestZohoMap( [ { type: 'text', name: 'name' }, { type: 'text', name: 'first_name' }, { type: 'text', name: 'last_name' } ] );
	assert.deepEqual( map.map( ( m ) => m.zoho ), [ 'First_Name', 'Last_Name' ] );
} );

test( 'missingZohoFields lists required fields nothing fills; a full name fills Last_Name', () => {
	const zohoFields = [ { api_name: 'Last_Name', label: 'Last Name', required: true }, { api_name: 'Company', label: 'Company', required: true }, { api_name: 'Email', required: false } ];
	assert.deepEqual( h.missingZohoFields( [], zohoFields ).map( ( z ) => z.api_name ), [ 'Last_Name', 'Company' ] );
	assert.deepEqual( h.missingZohoFields( [ { zoho: h.ZOHO_FULL_NAME, field: 'name' }, { zoho: 'Company', field: '' } ], zohoFields ).map( ( z ) => z.api_name ), [ 'Company' ] );
} );

test( 'renaming and removing a field updates the Zoho map but keeps the consent gate', () => {
	const config = {
		fields: [ { name: 'a' }, { name: 'b' } ],
		mailchimp: {},
		zoho: { consent_field: 'a', field_map: [ { zoho: 'Email', field: 'a' }, { zoho: 'Phone', field: 'b' } ] },
	};
	const renamed = h.renameReferences( config, 'a', 'c' );
	assert.equal( renamed.zoho.consent_field, 'c' );
	assert.equal( renamed.zoho.field_map[ 0 ].field, 'c' );
	const removed = h.removeReferences( config, 'b' );
	assert.deepEqual( removed.zoho.field_map, [ { zoho: 'Email', field: 'a' } ] );
	assert.equal( h.removeReferences( config, 'a' ).zoho.consent_field, 'a' );
} );
