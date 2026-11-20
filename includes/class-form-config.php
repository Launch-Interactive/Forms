<?php
/**
 * Form configuration schema: defaults and sanitisation.
 *
 * A form's whole definition (fields, settings, notifications, Mailchimp) is stored as one
 * JSON string in post meta so it can be edited identically from the Forms screen and from
 * the block editor (via the REST API).
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for the form configuration array.
 */
class Form_Config {

	const FIELD_TYPES = array( 'text', 'email', 'url', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox', 'consent', 'date', 'hidden', 'file', 'content' );

	const OPTION_TYPES = array( 'select', 'radio', 'checkbox' );

	const OPERATORS = array( 'is', 'is_not', 'contains', 'not_contains', 'empty', 'not_empty', 'greater_than', 'less_than' );

	/**
	 * Field widths, as a share of the form's width. Fields next to each other share a row.
	 */
	const WIDTHS = array( 'full', 'half', 'third', 'two-thirds', 'quarter', 'three-quarters' );

	const DEFAULT_FILE_TYPES = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt' );

	/**
	 * Field width labels for the builder.
	 *
	 * @return array<string,string>
	 */
	public static function width_labels() {
		return array(
			'full'           => __( 'Full width', 'launch-forms' ),
			'half'           => __( 'Half (1/2)', 'launch-forms' ),
			'third'          => __( 'One third (1/3)', 'launch-forms' ),
			'two-thirds'     => __( 'Two thirds (2/3)', 'launch-forms' ),
			'quarter'        => __( 'Quarter (1/4)', 'launch-forms' ),
			'three-quarters' => __( 'Three quarters (3/4)', 'launch-forms' ),
		);
	}

	/**
	 * Human readable field type labels.
	 *
	 * @return array<string,string>
	 */
	public static function field_type_labels() {
		return array(
			'text'     => __( 'Text', 'launch-forms' ),
			'email'    => __( 'Email', 'launch-forms' ),
			'url'      => __( 'Website / URL', 'launch-forms' ),
			'tel'      => __( 'Phone', 'launch-forms' ),
			'number'   => __( 'Number', 'launch-forms' ),
			'textarea' => __( 'Paragraph', 'launch-forms' ),
			'select'   => __( 'Dropdown', 'launch-forms' ),
			'radio'    => __( 'Radio buttons', 'launch-forms' ),
			'checkbox' => __( 'Checkboxes', 'launch-forms' ),
			'consent'  => __( 'Single checkbox / consent', 'launch-forms' ),
			'date'     => __( 'Date', 'launch-forms' ),
			'hidden'   => __( 'Hidden', 'launch-forms' ),
			'file'     => __( 'File upload', 'launch-forms' ),
			'content'  => __( 'Text / HTML content', 'launch-forms' ),
		);
	}

	/**
	 * Default configuration for a new form.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'fields'        => array(),
			'settings'      => array(
				'submit_label'         => __( 'Submit', 'launch-forms' ),
				'success_message'      => __( 'Thanks! Your submission has been received.', 'launch-forms' ),
				'redirect_url'         => '',
				'store_submissions'    => true,
				'hide_form_on_success' => true,
				'captcha'              => 'none',
			),
			'notifications' => array(
				array(
					'enabled'      => true,
					'name'         => __( 'Admin notification', 'launch-forms' ),
					'to'           => '{admin_email}',
					'subject'      => __( 'New submission: {form_title}', 'launch-forms' ),
					'message'      => '{all_fields}',
					'reply_to'     => '',
					'from_name'    => '',
					'from_email'   => '',
					'attach_files' => false,
				),
			),
			'mailchimp'     => array(
				'enabled'       => false,
				'list_id'       => '',
				'email_field'   => '',
				'consent_field' => '',
				'double_optin'  => true,
				'tags'          => '',
				'merge_fields'  => array(),
			),
		);
	}

	/**
	 * Decode stored JSON into a sanitised config.
	 *
	 * @param mixed $json JSON string (or already decoded array).
	 * @return array
	 */
	public static function from_json( $json ) {
		if ( is_array( $json ) ) {
			return self::sanitize( $json );
		}
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return self::sanitize( is_array( $data ) ? $data : array() );
	}

	/**
	 * Sanitize callback for the post meta: always stores normalised JSON.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_json( $value ) {
		return wp_json_encode( self::from_json( $value ) );
	}

	/**
	 * Sanitise a full configuration array.
	 *
	 * @param array $data Raw config.
	 * @return array
	 */
	public static function sanitize( array $data ) {
		$defaults = self::defaults();

		$fields = array();
		$names  = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$field = self::sanitize_field( $raw );
			if ( null === $field ) {
				continue;
			}
			$field['name'] = self::unique_name( $field['name'], $names );
			$names[]       = $field['name'];
			$fields[]      = $field;
		}

		// Conditions may only reference fields that exist (and not the field itself).
		foreach ( $fields as &$field ) {
			$field['conditions']['rules'] = array_values(
				array_filter(
					$field['conditions']['rules'],
					static function ( $rule ) use ( $names, $field ) {
						return in_array( $rule['field'], $names, true ) && $rule['field'] !== $field['name'];
					}
				)
			);
			if ( empty( $field['conditions']['rules'] ) ) {
				$field['conditions']['enabled'] = false;
			}
		}
		unset( $field );

		$settings = array_merge( $defaults['settings'], (array) ( $data['settings'] ?? array() ) );
		$settings = array(
			'submit_label'         => sanitize_text_field( $settings['submit_label'] ) ?: $defaults['settings']['submit_label'],
			'success_message'      => wp_kses_post( (string) $settings['success_message'] ),
			'redirect_url'         => esc_url_raw( (string) $settings['redirect_url'] ),
			'store_submissions'    => self::bool( $settings['store_submissions'] ),
			'hide_form_on_success' => self::bool( $settings['hide_form_on_success'] ),
			'captcha'              => in_array( $settings['captcha'], array( 'none', 'recaptcha', 'turnstile' ), true ) ? $settings['captcha'] : 'none',
		);

		$notifications = array();
		$raw_notes     = array_key_exists( 'notifications', $data ) ? (array) $data['notifications'] : $defaults['notifications'];
		foreach ( $raw_notes as $raw ) {
			if ( is_array( $raw ) ) {
				$notifications[] = self::sanitize_notification( $raw );
			}
		}

		return array(
			'fields'        => $fields,
			'settings'      => $settings,
			'notifications' => $notifications,
			'mailchimp'     => self::sanitize_mailchimp( (array) ( $data['mailchimp'] ?? array() ), $names ),
		);
	}

	/**
	 * Sanitise one field definition.
	 *
	 * @param array $raw Raw field.
	 * @return array|null Null when the type is unknown.
	 */
	public static function sanitize_field( array $raw ) {
		$type = isset( $raw['type'] ) ? (string) $raw['type'] : '';
		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			return null;
		}

		$label = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
		$name  = self::sanitize_name( (string) ( $raw['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = self::sanitize_name( $label );
		}
		if ( '' === $name ) {
			$name = $type;
		}

		$field = array(
			'type'        => $type,
			'name'        => $name,
			'label'       => $label,
			'required'    => self::bool( $raw['required'] ?? false ),
			'placeholder' => sanitize_text_field( (string) ( $raw['placeholder'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $raw['description'] ?? '' ) ),
			'default'     => 'textarea' === $type ? sanitize_textarea_field( (string) ( $raw['default'] ?? '' ) ) : sanitize_text_field( (string) ( $raw['default'] ?? '' ) ),
			'width'       => 'hidden' !== $type && in_array( $raw['width'] ?? '', self::WIDTHS, true ) ? $raw['width'] : 'full',
			'css_class'   => implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) ( $raw['css_class'] ?? '' ) ) ) ) ),
			'conditions'  => self::sanitize_conditions( (array) ( $raw['conditions'] ?? array() ) ),
		);

		if ( in_array( $type, self::OPTION_TYPES, true ) ) {
			$field['options'] = self::sanitize_options( $raw['options'] ?? array() );
		}
		if ( 'select' === $type ) {
			$field['multiple'] = self::bool( $raw['multiple'] ?? false );
		}
		if ( 'number' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $key ) {
				$value         = $raw[ $key ] ?? '';
				$field[ $key ] = is_numeric( $value ) ? (string) ( 0 + $value ) : '';
			}
		}
		if ( 'file' === $type ) {
			$field['allowed_types'] = self::sanitize_file_types( $raw['allowed_types'] ?? self::DEFAULT_FILE_TYPES );
			$size                   = isset( $raw['max_size'] ) ? (float) $raw['max_size'] : 5;
			$field['max_size']      = $size > 0 ? min( $size, 1024 ) : 5;
		}
		if ( 'content' === $type ) {
			$field['content']  = wp_kses_post( (string) ( $raw['content'] ?? '' ) );
			$field['required'] = false;
		}
		if ( 'hidden' === $type ) {
			$field['required'] = false;
		}

		return $field;
	}

	/**
	 * Make a machine name: lowercase letters, numbers and underscores.
	 *
	 * @param string $value Raw name or label.
	 * @return string
	 */
	public static function sanitize_name( $value ) {
		$value = strtolower( remove_accents( trim( (string) $value ) ) );
		$value = preg_replace( '/[^a-z0-9_]+/', '_', $value );
		$value = trim( (string) $value, '_' );
		if ( '' !== $value && is_numeric( $value[0] ) ) {
			$value = 'f_' . $value;
		}
		return substr( $value, 0, 64 );
	}

	/**
	 * Ensure a field name is unique within the form.
	 *
	 * @param string   $name  Desired name.
	 * @param string[] $taken Names already used.
	 * @return string
	 */
	private static function unique_name( $name, array $taken ) {
		$candidate = $name;
		$i         = 2;
		while ( in_array( $candidate, $taken, true ) ) {
			$candidate = $name . '_' . $i++;
		}
		return $candidate;
	}

	/**
	 * Normalise choice options. Accepts [{label,value}], ["Label", ...] or "Label|value" lines.
	 *
	 * @param mixed $options Raw options.
	 * @return array<int,array{label:string,value:string}>
	 */
	public static function sanitize_options( $options ) {
		if ( is_string( $options ) ) {
			$options = preg_split( '/\r\n|\r|\n/', $options );
		}
		$clean = array();
		$seen  = array();
		foreach ( (array) $options as $option ) {
			if ( is_string( $option ) ) {
				$parts  = explode( '|', $option, 2 );
				$option = array(
					'label' => $parts[0],
					'value' => $parts[1] ?? $parts[0],
				);
			}
			if ( ! is_array( $option ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
			$value = sanitize_text_field( (string) ( $option['value'] ?? '' ) );
			if ( '' === $value ) {
				$value = $label;
			}
			if ( '' === $label ) {
				$label = $value;
			}
			if ( '' === $value || in_array( $value, $seen, true ) ) {
				continue;
			}
			$seen[]  = $value;
			$clean[] = array(
				'label' => $label,
				'value' => $value,
			);
		}
		return $clean;
	}

	/**
	 * Sanitise allowed file extensions; never allows executable or script types.
	 *
	 * @param mixed $types Array or comma separated string.
	 * @return string[]
	 */
	public static function sanitize_file_types( $types ) {
		if ( is_string( $types ) ) {
			$types = explode( ',', $types );
		}
		$clean = array();
		foreach ( (array) $types as $type ) {
			$type = strtolower( trim( (string) $type, " .\t\n\r" ) );
			$type = preg_replace( '/[^a-z0-9]/', '', $type );
			if ( '' === $type || in_array( $type, Uploads::BLOCKED_EXTENSIONS, true ) || in_array( $type, $clean, true ) ) {
				continue;
			}
			$clean[] = $type;
		}
		return $clean ? $clean : self::DEFAULT_FILE_TYPES;
	}

	/**
	 * Sanitise conditional logic.
	 *
	 * @param array $raw Raw conditions.
	 * @return array
	 */
	public static function sanitize_conditions( array $raw ) {
		$rules = array();
		foreach ( (array) ( $raw['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$field    = self::sanitize_name( (string) ( $rule['field'] ?? '' ) );
			$operator = (string) ( $rule['operator'] ?? 'is' );
			if ( '' === $field || ! in_array( $operator, self::OPERATORS, true ) ) {
				continue;
			}
			$rules[] = array(
				'field'    => $field,
				'operator' => $operator,
				'value'    => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
			);
		}
		return array(
			'enabled' => self::bool( $raw['enabled'] ?? false ) && ! empty( $rules ),
			'action'  => ( $raw['action'] ?? 'show' ) === 'hide' ? 'hide' : 'show',
			'match'   => ( $raw['match'] ?? 'all' ) === 'any' ? 'any' : 'all',
			'rules'   => $rules,
		);
	}

	/**
	 * Sanitise one email notification.
	 *
	 * @param array $raw Raw notification.
	 * @return array
	 */
	public static function sanitize_notification( array $raw ) {
		return array(
			'enabled'      => self::bool( $raw['enabled'] ?? true ),
			'name'         => sanitize_text_field( (string) ( $raw['name'] ?? '' ) ),
			'to'           => sanitize_text_field( (string) ( $raw['to'] ?? '' ) ),
			'subject'      => sanitize_text_field( (string) ( $raw['subject'] ?? '' ) ),
			'message'      => sanitize_textarea_field( (string) ( $raw['message'] ?? '' ) ),
			'reply_to'     => sanitize_text_field( (string) ( $raw['reply_to'] ?? '' ) ),
			'from_name'    => sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) ),
			'from_email'   => sanitize_email( (string) ( $raw['from_email'] ?? '' ) ),
			'attach_files' => self::bool( $raw['attach_files'] ?? false ),
		);
	}

	/**
	 * Sanitise the Mailchimp action settings.
	 *
	 * @param array    $raw   Raw settings.
	 * @param string[] $names Existing field names.
	 * @return array
	 */
	public static function sanitize_mailchimp( array $raw, array $names ) {
		$defaults = self::defaults()['mailchimp'];
		$raw      = array_merge( $defaults, $raw );
		$field_or_empty = static function ( $value ) use ( $names ) {
			$value = self::sanitize_name( (string) $value );
			return in_array( $value, $names, true ) ? $value : '';
		};

		$merge = array();
		foreach ( (array) $raw['merge_fields'] as $map ) {
			if ( ! is_array( $map ) ) {
				continue;
			}
			$tag   = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $map['tag'] ?? '' ) ) );
			$field = $field_or_empty( $map['field'] ?? '' );
			if ( '' !== $tag && '' !== $field ) {
				$merge[] = array(
					'tag'   => substr( $tag, 0, 10 ),
					'field' => $field,
				);
			}
		}

		return array(
			'enabled'       => self::bool( $raw['enabled'] ),
			'list_id'       => preg_replace( '/[^A-Za-z0-9]/', '', (string) $raw['list_id'] ),
			'email_field'   => $field_or_empty( $raw['email_field'] ),
			'consent_field' => $field_or_empty( $raw['consent_field'] ),
			'double_optin'  => self::bool( $raw['double_optin'] ),
			'tags'          => sanitize_text_field( (string) $raw['tags'] ),
			'merge_fields'  => $merge,
		);
	}

	/**
	 * Loose boolean cast that understands "false"/"0"/"".
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function bool( $value ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
