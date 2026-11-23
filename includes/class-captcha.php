<?php
/**
 * Google reCAPTCHA (v2 checkbox / v3 score) and Cloudflare Turnstile.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Captcha rendering and server-side verification.
 */
class Captcha {

	const RECAPTCHA_VERIFY = 'https://www.google.com/recaptcha/api/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side verification API, not offloaded content.
	const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const RECAPTCHA_ACTION = 'launch_forms_submit';

	/** @var Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a provider has both keys configured.
	 *
	 * @param string $provider recaptcha|turnstile.
	 * @return bool
	 */
	public function is_configured( $provider ) {
		if ( 'recaptcha' === $provider ) {
			return '' !== (string) $this->settings->get( 'recaptcha_site_key' ) && '' !== (string) $this->settings->get( 'recaptcha_secret_key' );
		}
		if ( 'turnstile' === $provider ) {
			return '' !== (string) $this->settings->get( 'turnstile_site_key' ) && '' !== (string) $this->settings->get( 'turnstile_secret_key' );
		}
		return false;
	}

	/**
	 * The active provider for a form: the type of its captcha field ('none' when it has no
	 * captcha field, or the provider's keys aren't set under Forms → Settings).
	 *
	 * @param Form $form Form.
	 * @return string
	 */
	public function provider_for( Form $form ) {
		$field    = $form->captcha_field();
		$provider = $field ? $field['type'] : 'none';
		return $this->is_configured( $provider ) ? $provider : 'none';
	}

	/**
	 * Widget markup (and enqueue the provider script).
	 *
	 * @param Form $form Form.
	 * @return string
	 */
	public function render( Form $form ) {
		$provider = $this->provider_for( $form );
		if ( 'none' === $provider ) {
			return '';
		}
		$this->enqueue( $provider );

		if ( 'turnstile' === $provider ) {
			return '<div class="lf-captcha"><div class="cf-turnstile" data-sitekey="' . esc_attr( $this->settings->get( 'turnstile_site_key' ) ) . '" data-action="' . esc_attr( self::RECAPTCHA_ACTION ) . '"></div></div>';
		}
		if ( 'v3' === $this->settings->get( 'recaptcha_version' ) ) {
			return '<input type="hidden" name="g-recaptcha-response" class="lf-recaptcha-v3" data-sitekey="' . esc_attr( $this->settings->get( 'recaptcha_site_key' ) ) . '" data-action="' . esc_attr( self::RECAPTCHA_ACTION ) . '" value="">';
		}
		return '<div class="lf-captcha"><div class="g-recaptcha" data-sitekey="' . esc_attr( $this->settings->get( 'recaptcha_site_key' ) ) . '"></div></div>';
	}

	/**
	 * Enqueue the provider's script.
	 *
	 * @param string $provider Provider.
	 */
	public function enqueue( $provider ) {
		if ( 'turnstile' === $provider ) {
			// Turnstile must be loaded from Cloudflare; self-hosting it is not supported. Disclosed in readme.txt.
			wp_enqueue_script( 'launch-forms-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
			return;
		}
		$src    = 'https://www.google.com/recaptcha/api.js';
		$handle = 'launch-forms-recaptcha';
		if ( 'v3' === $this->settings->get( 'recaptcha_version' ) ) {
			$src    = add_query_arg( 'render', rawurlencode( (string) $this->settings->get( 'recaptcha_site_key' ) ), $src );
			$handle = 'launch-forms-recaptcha-v3';
		}
		// reCAPTCHA must be loaded from Google; self-hosting it is not supported. Disclosed in readme.txt.
		wp_enqueue_script( $handle, $src, array(), null, array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion, PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
	}

	/**
	 * Verify a submission's captcha token.
	 *
	 * @param Form   $form    Form.
	 * @param array  $request Unslashed request params (top level).
	 * @param string $ip      Visitor IP (sent to the provider only, never stored).
	 * @return true|\WP_Error
	 */
	public function verify( Form $form, array $request, $ip = '' ) {
		$provider = $this->provider_for( $form );
		if ( 'none' === $provider ) {
			return true;
		}

		$field = 'turnstile' === $provider ? 'cf-turnstile-response' : 'g-recaptcha-response';
		$token = isset( $request[ $field ] ) && is_string( $request[ $field ] ) ? trim( $request[ $field ] ) : '';
		$fail  = new \WP_Error( 'lf_captcha', __( 'Please complete the spam check and try again.', 'launch-forms' ) );
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return $fail;
		}

		$body = array(
			'secret'   => (string) $this->settings->get( 'turnstile' === $provider ? 'turnstile_secret_key' : 'recaptcha_secret_key' ),
			'response' => $token,
		);
		if ( $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			'turnstile' === $provider ? self::TURNSTILE_VERIFY : self::RECAPTCHA_VERIFY,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'lf_captcha_unreachable', __( 'The spam check could not be verified. Please try again.', 'launch-forms' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return $fail;
		}

		if ( 'recaptcha' === $provider && 'v3' === $this->settings->get( 'recaptcha_version' ) ) {
			$score = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
			if ( $score < (float) $this->settings->get( 'recaptcha_threshold' ) ) {
				return $fail;
			}
			if ( isset( $data['action'] ) && self::RECAPTCHA_ACTION !== $data['action'] ) {
				return $fail;
			}
		}

		return true;
	}
}
