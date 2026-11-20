<?php
/**
 * Global plugin settings.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Stores captcha keys, Mailchimp key and general options.
 */
class Settings {

	const OPTION = 'launch_forms_settings';

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'load_css'                 => true,
			'recaptcha_version'        => 'v2',
			'recaptcha_site_key'       => '',
			'recaptcha_secret_key'     => '',
			'recaptcha_threshold'      => 0.5,
			'turnstile_site_key'       => '',
			'turnstile_secret_key'     => '',
			'mailchimp_api_key'        => '',
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register() {
		register_setting(
			'launch_forms',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Get one setting. Constants named LAUNCH_FORMS_<KEY> override stored values (useful for secrets).
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		$constant = 'LAUNCH_FORMS_' . strtoupper( $key );
		if ( defined( $constant ) ) {
			return constant( $constant );
		}
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Update settings (merged).
	 *
	 * @param array $values Values.
	 */
	public function update( array $values ) {
		update_option( self::OPTION, $this->sanitize( array_merge( $this->all(), $values ) ) );
	}

	/**
	 * Sanitise submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$key      = static function ( $value ) {
			return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
		};

		$threshold = isset( $input['recaptcha_threshold'] ) ? (float) $input['recaptcha_threshold'] : $defaults['recaptcha_threshold'];

		return array(
			'load_css'                 => ! empty( $input['load_css'] ),
			'recaptcha_version'        => ( $input['recaptcha_version'] ?? 'v2' ) === 'v3' ? 'v3' : 'v2',
			'recaptcha_site_key'       => $key( $input['recaptcha_site_key'] ?? '' ),
			'recaptcha_secret_key'     => $key( $input['recaptcha_secret_key'] ?? '' ),
			'recaptcha_threshold'      => max( 0.0, min( 1.0, $threshold ) ),
			'turnstile_site_key'       => $key( $input['turnstile_site_key'] ?? '' ),
			'turnstile_secret_key'     => $key( $input['turnstile_secret_key'] ?? '' ),
			'mailchimp_api_key'        => $key( $input['mailchimp_api_key'] ?? '' ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}
}
