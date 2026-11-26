<?php
/**
 * Automated delivery tests: a secret key lets a monitoring script submit forms past the spam checks.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Monitoring key.
 *
 * A scheduled script (e.g. Playwright) opens the real form page, fills it in and submits it,
 * sending the key in the X-Liforms-Monitor header. Such a submission skips the captcha,
 * honeypot and rate limit, but is validated and emailed as usual (marked as an automated
 * test), so it proves that the form, the site's email and the recipients' inboxes work. It is
 * not stored and not sent to Mailchimp, Zoho CRM, Google Analytics or submission hooks.
 */
class Monitor {

	/** Option holding the key (encrypted). */
	const OPTION = 'liforms_monitor_key';

	/** Request header carrying the key. */
	const HEADER = 'X-Liforms-Monitor';

	/** Request header carrying the run's code, which goes in the email subject. */
	const RUN_HEADER = 'X-Liforms-Monitor-Run';

	/**
	 * The key: LIFORMS_MONITOR_KEY if defined, otherwise the saved one ('' when off).
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'LIFORMS_MONITOR_KEY' ) ) {
			return (string) LIFORMS_MONITOR_KEY;
		}
		return Settings::decrypt( (string) get_option( self::OPTION, '' ) );
	}

	/**
	 * Create (or replace) the key.
	 *
	 * @return string The new key.
	 */
	public static function generate() {
		$key = bin2hex( random_bytes( 24 ) );
		update_option( self::OPTION, Settings::encrypt( $key ), false );
		return $key;
	}

	/**
	 * Turn monitoring off.
	 */
	public static function disable() {
		delete_option( self::OPTION );
	}

	/**
	 * Check the headers of a request.
	 *
	 * @param string $key Value of the X-Liforms-Monitor header.
	 * @param string $run Value of the X-Liforms-Monitor-Run header.
	 * @return string The run code ('test' if none was sent) when the key is right, '' otherwise.
	 */
	public static function check( $key, $run ) {
		$expected = self::key();
		if ( strlen( $expected ) < 32 || ! is_string( $key ) || ! hash_equals( $expected, $key ) ) {
			return '';
		}
		$run = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $run );
		return '' !== $run ? substr( $run, 0, 64 ) : 'test';
	}

	/**
	 * Run code for the current plain (non-REST) request, '' when it isn't a monitoring run.
	 *
	 * @return string
	 */
	public static function from_server() {
		$key = isset( $_SERVER['HTTP_X_LIFORMS_MONITOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LIFORMS_MONITOR'] ) ) : '';
		$run = isset( $_SERVER['HTTP_X_LIFORMS_MONITOR_RUN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_LIFORMS_MONITOR_RUN'] ) ) : '';
		return '' === $key ? '' : self::check( $key, $run );
	}

	/**
	 * Email subject for a test run.
	 *
	 * @param string $subject Normal subject.
	 * @param string $run     Run code.
	 * @return string
	 */
	public static function subject( $subject, $run ) {
		/* translators: 1: run code, 2: the email's normal subject. */
		return sprintf( __( '[Automated test %1$s] %2$s', 'launch-interactive-forms' ), $run, $subject );
	}

	/**
	 * Note added to the top of a test email.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'This is an automated test of your website\'s form, sent to check that enquiries reach your inbox. No action is needed.', 'launch-interactive-forms' );
	}
}
