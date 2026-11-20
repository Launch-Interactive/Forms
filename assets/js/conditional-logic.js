/**
 * Launch Forms conditional logic evaluator.
 *
 * Pure functions with no DOM access so they can be unit tested in Node. Mirrors
 * includes/class-conditional-logic.php — keep the two in sync.
 */
( function ( root, factory ) {
	var api = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.launchFormsConditional = api;
	}
} )( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function normalize( value ) {
		if ( value === null || value === undefined || typeof value === 'object' ) {
			return '';
		}
		return String( value ).trim().toLowerCase();
	}

	function isNumeric( value ) {
		return value !== '' && /^[-+]?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i.test( value );
	}

	function ruleMatches( operator, value, expected ) {
		var items = ( Array.isArray( value ) ? value : [ value ] )
			.map( normalize )
			.filter( function ( item ) {
				return item.length > 0;
			} );
		expected = normalize( expected );

		switch ( operator ) {
			case 'empty':
				return items.length === 0;
			case 'not_empty':
				return items.length > 0;
			case 'is':
				return items.indexOf( expected ) !== -1 || ( expected === '' && items.length === 0 );
			case 'is_not':
				return ! ruleMatches( 'is', value, expected );
			case 'contains':
				return items.some( function ( item ) {
					return expected === '' || item.indexOf( expected ) !== -1;
				} );
			case 'not_contains':
				return ! ruleMatches( 'contains', value, expected );
			case 'greater_than':
			case 'less_than':
				if ( items.length !== 1 || ! isNumeric( items[ 0 ] ) || ! isNumeric( expected ) ) {
					return false;
				}
				return operator === 'greater_than'
					? parseFloat( items[ 0 ] ) > parseFloat( expected )
					: parseFloat( items[ 0 ] ) < parseFloat( expected );
		}
		return false;
	}

	/**
	 * @param {Array<{name:string,conditions?:object}>} fields
	 * @param {Object<string,string|string[]>} values
	 * @return {Object<string,boolean>} name => visible
	 */
	function visibility( fields, values ) {
		var byName = {};
		var memo = {};
		fields.forEach( function ( field ) {
			byName[ field.name ] = field;
		} );

		function resolve( name, stack ) {
			if ( Object.prototype.hasOwnProperty.call( memo, name ) ) {
				return memo[ name ];
			}
			if ( ! byName[ name ] || stack.indexOf( name ) !== -1 ) {
				return true;
			}
			var conditions = byName[ name ].conditions || {};
			if ( ! conditions.enabled || ! conditions.rules || ! conditions.rules.length ) {
				memo[ name ] = true;
				return true;
			}
			var nextStack = stack.concat( [ name ] );
			var results = conditions.rules.map( function ( rule ) {
				var controller = rule.field;
				var value = resolve( controller, nextStack ) ? values[ controller ] : '';
				return ruleMatches( rule.operator, value === undefined ? '' : value, rule.value );
			} );
			var matched =
				conditions.match === 'any'
					? results.indexOf( true ) !== -1
					: results.indexOf( false ) === -1;
			var visible = conditions.action === 'hide' ? ! matched : matched;
			memo[ name ] = visible;
			return visible;
		}

		var result = {};
		Object.keys( byName ).forEach( function ( name ) {
			result[ name ] = resolve( name, [] );
		} );
		return result;
	}

	return {
		normalize: normalize,
		ruleMatches: ruleMatches,
		visibility: visibility,
	};
} );
