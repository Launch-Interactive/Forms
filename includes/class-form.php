<?php
/**
 * Immutable form value object.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * A loaded form definition.
 */
class Form {

	/** @var int */
	public $id;
	/** @var string */
	public $title;
	/** @var array[] */
	public $fields;
	/** @var array */
	public $settings;
	/** @var array[] */
	public $notifications;
	/** @var array */
	public $mailchimp;
	/** @var array */
	public $zoho;

	/**
	 * Constructor.
	 *
	 * @param int          $id     Post ID.
	 * @param string       $title  Title.
	 * @param array|string $config Config array or JSON.
	 */
	public function __construct( $id, $title, $config ) {
		$config              = Form_Config::from_json( $config );
		$this->id            = (int) $id;
		$this->title         = (string) $title;
		$this->fields        = $config['fields'];
		$this->settings      = $config['settings'];
		$this->notifications = $config['notifications'];
		$this->mailchimp     = $config['mailchimp'];
		$this->zoho          = $config['zoho'];
	}

	/**
	 * Find a field by name.
	 *
	 * @param string $name Field name.
	 * @return array|null
	 */
	public function get_field( $name ) {
		foreach ( $this->fields as $field ) {
			if ( $field['name'] === $name ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Whether the form contains a field of the given type.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public function has_field_type( $type ) {
		foreach ( $this->fields as $field ) {
			if ( $field['type'] === $type ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether any field shows or hides itself based on other answers.
	 *
	 * @return bool
	 */
	public function has_conditions() {
		foreach ( $this->fields as $field ) {
			if ( ! empty( $field['conditions']['enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The form's captcha field, if it has one.
	 *
	 * @return array|null
	 */
	public function captcha_field() {
		foreach ( $this->fields as $field ) {
			if ( in_array( $field['type'], Form_Config::CAPTCHA_TYPES, true ) ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Export as the config array.
	 *
	 * @return array
	 */
	public function to_config() {
		return array(
			'fields'        => $this->fields,
			'settings'      => $this->settings,
			'notifications' => $this->notifications,
			'mailchimp'     => $this->mailchimp,
			'zoho'          => $this->zoho,
		);
	}
}
