<?php
/**
 * Server-side conditional logic. Mirrors assets/js/conditional-logic.js exactly so hidden
 * fields are ignored during validation even when JavaScript is disabled or bypassed.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates show/hide rules.
 */
class Conditional_Logic {

	/**
	 * Work out which fields are visible.
	 *
	 * A field whose controlling field is itself hidden sees that controller's value as empty,
	 * so chains of conditions behave intuitively. Circular references resolve to visible.
	 *
	 * @param array[] $fields Field definitions.
	 * @param array   $values Submitted values keyed by field name (string or string[]).
	 * @return array<string,bool> Field name => visible.
	 */
	public static function visibility( array $fields, array $values ) {
		$by_name = array();
		foreach ( $fields as $field ) {
			$by_name[ $field['name'] ] = $field;
		}

		$memo    = array();
		$resolve = static function ( $name, array $stack ) use ( &$resolve, &$memo, $by_name, $values ) {
			if ( isset( $memo[ $name ] ) ) {
				return $memo[ $name ];
			}
			if ( ! isset( $by_name[ $name ] ) || in_array( $name, $stack, true ) ) {
				return true;
			}
			$conditions = $by_name[ $name ]['conditions'] ?? array();
			if ( empty( $conditions['enabled'] ) || empty( $conditions['rules'] ) ) {
				$memo[ $name ] = true;
				return true;
			}

			$stack[] = $name;
			$results = array();
			foreach ( $conditions['rules'] as $rule ) {
				$controller = $rule['field'];
				$value      = $resolve( $controller, $stack ) ? ( $values[ $controller ] ?? '' ) : '';
				$results[]  = self::rule_matches( $rule['operator'], $value, (string) $rule['value'] );
			}

			$matched       = ( 'any' === ( $conditions['match'] ?? 'all' ) ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
			$visible       = ( 'hide' === ( $conditions['action'] ?? 'show' ) ) ? ! $matched : $matched;
			$memo[ $name ] = $visible;
			return $visible;
		};

		$visibility = array();
		foreach ( $by_name as $name => $field ) {
			$visibility[ $name ] = $resolve( $name, array() );
		}
		return $visibility;
	}

	/**
	 * Evaluate one rule.
	 *
	 * @param string          $operator Operator.
	 * @param string|string[] $value    Actual value.
	 * @param string          $expected Configured comparison value.
	 * @return bool
	 */
	public static function rule_matches( $operator, $value, $expected ) {
		$items    = is_array( $value ) ? array_map( array( __CLASS__, 'normalize' ), $value ) : array( self::normalize( $value ) );
		$items    = array_values( array_filter( $items, 'strlen' ) );
		$expected = self::normalize( $expected );

		switch ( $operator ) {
			case 'empty':
				return empty( $items );
			case 'not_empty':
				return ! empty( $items );
			case 'is':
				return in_array( $expected, $items, true ) || ( '' === $expected && empty( $items ) );
			case 'is_not':
				return ! self::rule_matches( 'is', $value, $expected );
			case 'contains':
				foreach ( $items as $item ) {
					if ( '' === $expected || false !== strpos( $item, $expected ) ) {
						return true;
					}
				}
				return false;
			case 'not_contains':
				return ! self::rule_matches( 'contains', $value, $expected );
			case 'greater_than':
			case 'less_than':
				if ( 1 !== count( $items ) || ! is_numeric( $items[0] ) || ! is_numeric( $expected ) ) {
					return false;
				}
				return 'greater_than' === $operator ? (float) $items[0] > (float) $expected : (float) $items[0] < (float) $expected;
		}
		return false;
	}

	/**
	 * Lowercase and trim for comparisons.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function normalize( $value ) {
		$value = trim( is_scalar( $value ) ? (string) $value : '' );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
