'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const logic = require( '../../assets/js/conditional-logic.js' );

const rule = ( field, operator, value = '' ) => ( { field, operator, value } );
const cond = ( name, rules, action = 'show', match = 'all' ) => ( {
	name,
	conditions: { enabled: true, action, match, rules },
} );

test( 'ruleMatches operators', () => {
	const cases = [
		[ 'is', 'Yes', 'yes', true ],
		[ 'is', ' yes ', 'yes', true ],
		[ 'is', 'no', 'yes', false ],
		[ 'is', [ 'a', 'b' ], 'b', true ],
		[ 'is', '', '', true ],
		[ 'is_not', 'no', 'yes', true ],
		[ 'contains', 'Hello World', 'world', true ],
		[ 'contains', [ 'apple pie' ], 'pie', true ],
		[ 'not_contains', 'Hello', 'bye', true ],
		[ 'empty', '', '', true ],
		[ 'empty', [ '' ], '', true ],
		[ 'empty', undefined, '', true ],
		[ 'not_empty', '0', '', true ],
		[ 'greater_than', '10', '9.5', true ],
		[ 'greater_than', '10', '10', false ],
		[ 'less_than', '-1', '0', true ],
		[ 'greater_than', 'abc', '1', false ],
		[ 'greater_than', [ '5', '6' ], '1', false ],
		[ 'nonsense', 'x', 'x', false ],
	];
	for ( const [ op, value, expected, result ] of cases ) {
		assert.equal( logic.ruleMatches( op, value, expected ), result, `${ op } ${ JSON.stringify( value ) } ${ expected }` );
	}
} );

test( 'show when all rules match', () => {
	const fields = [
		{ name: 'type' },
		{ name: 'size' },
		cond( 'details', [ rule( 'type', 'is', 'other' ), rule( 'size', 'greater_than', '3' ) ] ),
	];
	assert.equal( logic.visibility( fields, { type: 'other', size: '2' } ).details, false );
	assert.equal( logic.visibility( fields, { type: 'other', size: '4' } ).details, true );
} );

test( 'hide when any rule matches', () => {
	const fields = [ { name: 'a' }, { name: 'b' }, cond( 'c', [ rule( 'a', 'is', '1' ), rule( 'b', 'is', '1' ) ], 'hide', 'any' ) ];
	assert.equal( logic.visibility( fields, { a: '0', b: '0' } ).c, true );
	assert.equal( logic.visibility( fields, { a: '0', b: '1' } ).c, false );
} );

test( 'hidden controller is treated as empty (chained conditions)', () => {
	const fields = [
		{ name: 'contact' },
		cond( 'phone', [ rule( 'contact', 'is', 'phone' ) ] ),
		cond( 'time', [ rule( 'phone', 'not_empty' ) ] ),
	];
	const vis = logic.visibility( fields, { contact: 'email', phone: '555' } );
	assert.equal( vis.phone, false );
	assert.equal( vis.time, false );
	assert.equal( logic.visibility( fields, { contact: 'phone', phone: '555' } ).time, true );
} );

test( 'circular references terminate', () => {
	const fields = [ cond( 'a', [ rule( 'b', 'empty' ) ] ), cond( 'b', [ rule( 'a', 'empty' ) ] ) ];
	const vis = logic.visibility( fields, {} );
	assert.ok( 'a' in vis && 'b' in vis );
} );

test( 'fields without conditions are visible', () => {
	assert.deepEqual( logic.visibility( [ { name: 'x', conditions: null }, { name: 'y' } ], {} ), { x: true, y: true } );
	const disabled = cond( 'z', [ rule( 'x', 'is', 'never' ) ] );
	disabled.conditions.enabled = false;
	assert.equal( logic.visibility( [ { name: 'x' }, disabled ], {} ).z, true );
} );
