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
			'required_marker'          => '*',
			'recaptcha_version'        => 'v2',
			'recaptcha_site_key'       => '',
			'recaptcha_secret_key'     => '',
			'recaptcha_threshold'      => 0.5,
			'turnstile_site_key'       => '',
			'turnstile_secret_key'     => '',
			'mailchimp_api_key'        => '',
			'delete_data_on_uninstall' => false,
			'smtp_enabled'             => false,
			'smtp_host'                => '',
			'smtp_port'                => 587,
			'smtp_encryption'          => 'tls',
			'smtp_auth'                => true,
			'smtp_username'            => '',
			'smtp_password'            => '', // Encrypted; read it with smtp_password().
			'smtp_from_email'          => '',
			'smtp_from_name'           => '',
			'smtp_force_from'          => true,
		);
	}

	/**
	 * SMTP encryption choices.
	 *
	 * @return array<string,string>
	 */
	public static function smtp_encryptions() {
		return array(
			'tls'  => __( 'TLS / STARTTLS (usually port 587)', 'launch-interactive-forms' ),
			'ssl'  => __( 'SSL (usually port 465)', 'launch-interactive-forms' ),
			'none' => __( 'None (usually port 25; not recommended)', 'launch-interactive-forms' ),
		);
	}

	/**
	 * The SMTP password in plain text: from LAUNCH_FORMS_SMTP_PASSWORD if defined, otherwise
	 * decrypted from the database ('' if none, or if the site's salts changed since it was saved).
	 *
	 * @return string
	 */
	public function smtp_password() {
		if ( defined( 'LAUNCH_FORMS_SMTP_PASSWORD' ) ) {
			return (string) LAUNCH_FORMS_SMTP_PASSWORD;
		}
		return self::decrypt( (string) ( $this->all()['smtp_password'] ?? '' ) );
	}

	/**
	 * Whether an SMTP password is saved (or defined in wp-config.php).
	 *
	 * @return bool
	 */
	public function has_smtp_password() {
		return defined( 'LAUNCH_FORMS_SMTP_PASSWORD' ) || '' !== (string) ( $this->all()['smtp_password'] ?? '' );
	}

	/**
	 * Encrypt a secret for storage, so a database dump or backup doesn't reveal it. The key is
	 * derived from the site's salts in wp-config.php, which are not stored in the database.
	 *
	 * @param string $plain Secret.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		// base64 only makes the binary ciphertext safe to store as text.
		return 'lfenc1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret_key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a value from encrypt().
	 *
	 * @param string $stored Stored value.
	 * @return string Plain text, or '' if it can't be decrypted.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'lfenc1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 7 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret_key() );
		} catch ( \Exception $e ) {
			return '';
		}
		return false === $plain ? '' : $plain;
	}

	/**
	 * 32-byte key derived from the auth salts.
	 *
	 * @return string
	 */
	private static function secret_key() {
		return sodium_crypto_generichash( 'launch-forms-secret|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
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

		// Password: a new one is typed into smtp_password_new; otherwise keep what's stored
		// (the settings form never contains the saved password).
		$stored   = get_option( self::OPTION, array() );
		$password = isset( $input['smtp_password'] ) ? (string) $input['smtp_password'] : (string) ( is_array( $stored ) ? ( $stored['smtp_password'] ?? '' ) : '' );
		if ( ! empty( $input['smtp_password_clear'] ) ) {
			$password = '';
		} elseif ( isset( $input['smtp_password_new'] ) && '' !== (string) $input['smtp_password_new'] ) {
			$password = self::encrypt( str_replace( array( "\r", "\n" ), '', (string) $input['smtp_password_new'] ) );
		}
		if ( '' !== $password && 0 !== strpos( $password, 'lfenc1:' ) ) {
			$password = ''; // Never store a plain-text password.
		}

		$port       = isset( $input['smtp_port'] ) ? absint( $input['smtp_port'] ) : $defaults['smtp_port'];
		$encryption = (string) ( $input['smtp_encryption'] ?? 'tls' );
		$from_email = sanitize_email( (string) ( $input['smtp_from_email'] ?? '' ) );

		return array(
			'load_css'                 => ! empty( $input['load_css'] ),
			'required_marker'          => mb_substr( trim( sanitize_text_field( (string) ( $input['required_marker'] ?? $defaults['required_marker'] ) ) ), 0, 50 ),
			'recaptcha_version'        => ( $input['recaptcha_version'] ?? 'v2' ) === 'v3' ? 'v3' : 'v2',
			'recaptcha_site_key'       => $key( $input['recaptcha_site_key'] ?? '' ),
			'recaptcha_secret_key'     => $key( $input['recaptcha_secret_key'] ?? '' ),
			'recaptcha_threshold'      => max( 0.0, min( 1.0, $threshold ) ),
			'turnstile_site_key'       => $key( $input['turnstile_site_key'] ?? '' ),
			'turnstile_secret_key'     => $key( $input['turnstile_secret_key'] ?? '' ),
			'mailchimp_api_key'        => $key( $input['mailchimp_api_key'] ?? '' ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
			'smtp_enabled'             => ! empty( $input['smtp_enabled'] ),
			'smtp_host'                => preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) ( $input['smtp_host'] ?? '' ) ),
			'smtp_port'                => $port >= 1 && $port <= 65535 ? $port : $defaults['smtp_port'],
			'smtp_encryption'          => array_key_exists( $encryption, self::smtp_encryptions() ) ? $encryption : 'tls',
			'smtp_auth'                => ! empty( $input['smtp_auth'] ),
			'smtp_username'            => trim( sanitize_text_field( (string) ( $input['smtp_username'] ?? '' ) ) ),
			'smtp_password'            => $password,
			'smtp_from_email'          => is_email( $from_email ) ? $from_email : '',
			'smtp_from_name'           => trim( str_replace( array( '"', '<', '>' ), '', sanitize_text_field( (string) ( $input['smtp_from_name'] ?? '' ) ) ) ),
			'smtp_force_from'          => ! empty( $input['smtp_force_from'] ),
		);
	}
}
