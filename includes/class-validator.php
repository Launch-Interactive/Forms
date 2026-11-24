<?php
/**
 * Sanitises and validates submitted values.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Field validation.
 */
class Validator {

	const MAX_LENGTH = 20000;

	/**
	 * Sanitise raw input into strings / string arrays, before conditional logic runs.
	 *
	 * @param Form  $form  Form.
	 * @param array $input Raw (unslashed) `lf` array.
	 * @param array $files Normalised uploads keyed by field name (only names are used).
	 * @return array<string,string|string[]>
	 */
	public function normalize( Form $form, array $input, array $files = array() ) {
		$values = array();
		foreach ( $form->fields as $field ) {
			$name = $field['name'];
			$type = $field['type'];
			if ( in_array( $type, Form_Config::NON_INPUT_TYPES, true ) ) {
				continue;
			}
			if ( 'file' === $type ) {
				$values[ $name ] = isset( $files[ $name ]['name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $files[ $name ]['error'] ?? UPLOAD_ERR_NO_FILE ) ? sanitize_file_name( (string) $files[ $name ]['name'] ) : '';
				continue;
			}
			$multi = 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) );
			$raw   = $input[ $name ] ?? ( $multi ? array() : '' );

			if ( $multi ) {
				$raw             = is_array( $raw ) ? array_slice( $raw, 0, 200 ) : ( '' === $raw ? array() : array( $raw ) );
				$values[ $name ] = array_values( array_map( 'sanitize_text_field', array_filter( $raw, 'is_scalar' ) ) );
				continue;
			}
			if ( ! is_scalar( $raw ) ) {
				$raw = '';
			}
			$raw             = substr( (string) $raw, 0, self::MAX_LENGTH );
			$values[ $name ] = 'textarea' === $type ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		}
		return $values;
	}

	/**
	 * Validate a submission.
	 *
	 * @param Form  $form  Form.
	 * @param array $input Raw (unslashed) `lf` array.
	 * @param array $files Normalised uploads keyed by field name.
	 * @return array{values:array,errors:array,visibility:array,raw:array}
	 */
	public function validate( Form $form, array $input, array $files = array() ) {
		$raw        = $this->normalize( $form, $input, $files );
		$visibility = Conditional_Logic::visibility( $form->fields, $raw );
		$values     = array();
		$errors     = array();

		foreach ( $form->fields as $field ) {
			$name = $field['name'];
			if ( in_array( $field['type'], Form_Config::NON_INPUT_TYPES, true ) || 'file' === $field['type'] || empty( $visibility[ $name ] ) ) {
				continue;
			}
			$value = $raw[ $name ];
			$error = $this->validate_field( $field, $value );
			if ( is_string( $error ) ) {
				$errors[ $name ] = $error;
			}
			$values[ $name ] = $value;
		}

		/**
		 * Filters validation errors. Add `field_name => message` to reject a submission.
		 *
		 * @param array $errors Errors.
		 * @param array $values Clean values.
		 * @param Form  $form   Form.
		 */
		$errors = (array) apply_filters( 'launch_forms_validation_errors', $errors, $values, $form );

		return array(
			'values'     => $values,
			'errors'     => $errors,
			'visibility' => $visibility,
			'raw'        => $raw,
		);
	}

	/**
	 * Validate one field. May normalise $value in place.
	 *
	 * @param array           $field Field.
	 * @param string|string[] $value Value (by reference).
	 * @return string|null Error message or null.
	 */
	public function validate_field( array $field, &$value ) {
		$empty = is_array( $value ) ? empty( $value ) : '' === trim( (string) $value );
		if ( $empty ) {
			return $field['required'] ? __( 'This field is required.', 'launch-interactive-forms' ) : null;
		}

		switch ( $field['type'] ) {
			case 'email':
				$clean = sanitize_email( $value );
				if ( ! is_email( $clean ) ) {
					return __( 'Please enter a valid email address.', 'launch-interactive-forms' );
				}
				$value = $clean;
				break;

			case 'url':
				$clean = preg_match( '/\s/', trim( $value ) ) ? '' : esc_url_raw( trim( $value ), array( 'http', 'https' ) );
				$host  = '' !== $clean ? (string) wp_parse_url( $clean, PHP_URL_HOST ) : '';
				if ( '' === $clean || ! filter_var( $clean, FILTER_VALIDATE_URL ) || false === strpos( $host, '.' ) ) {
					return __( 'Please enter a valid URL, including https://.', 'launch-interactive-forms' );
				}
				$value = $clean;
				break;

			case 'tel':
				if ( ! preg_match( '/^[0-9+().\-\s\/x#*]{3,40}$/i', $value ) ) {
					return __( 'Please enter a valid phone number.', 'launch-interactive-forms' );
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return __( 'Please enter a number.', 'launch-interactive-forms' );
				}
				if ( '' !== $field['min'] && (float) $value < (float) $field['min'] ) {
					/* translators: %s: minimum. */
					return sprintf( __( 'Please enter a number of at least %s.', 'launch-interactive-forms' ), $field['min'] );
				}
				if ( '' !== $field['max'] && (float) $value > (float) $field['max'] ) {
					/* translators: %s: maximum. */
					return sprintf( __( 'Please enter a number no greater than %s.', 'launch-interactive-forms' ), $field['max'] );
				}
				break;

			case 'date':
				$date = \DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
					return __( 'Please enter a valid date.', 'launch-interactive-forms' );
				}
				break;

			case 'select':
			case 'radio':
			case 'checkbox':
				$allowed = wp_list_pluck( $field['options'], 'value' );
				foreach ( (array) $value as $item ) {
					if ( ! in_array( (string) $item, $allowed, true ) ) {
						return __( 'Please choose a valid option.', 'launch-interactive-forms' );
					}
				}
				if ( is_array( $value ) ) {
					$value = array_values( array_unique( $value ) );
				}
				break;

			case 'consent':
				$value = '1';
				break;

			case 'country':
				if ( ! Countries::is_valid( $value ) ) {
					return __( 'Please choose a country from the list.', 'launch-interactive-forms' );
				}
				break;
		}

		return null;
	}
}
