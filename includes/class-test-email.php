<?php
/**
 * "Send test email" tool so site owners can confirm wp_mail() works.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a diagnostic email and reports the exact failure reason.
 */
class Test_Email {

	/**
	 * Send a test email.
	 *
	 * @param string $to Recipient.
	 * @return array{success:bool,message:string}
	 */
	public function send( $to ) {
		$to = sanitize_email( (string) $to );
		if ( ! is_email( $to ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a valid email address.', 'launch-forms' ),
			);
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Launch Forms test email', 'launch-forms' ), $site );
		$body    = implode(
			"\n",
			array(
				__( 'This is a test email sent from the Launch Forms settings page.', 'launch-forms' ),
				__( 'If you are reading this, WordPress can send email and your form notifications should arrive.', 'launch-forms' ),
				'',
				/* translators: %s: URL. */
				sprintf( __( 'Site: %s', 'launch-forms' ), home_url( '/' ) ),
				/* translators: %s: date. */
				sprintf( __( 'Sent: %s', 'launch-forms' ), wp_date( 'Y-m-d H:i:s T' ) ),
				/* translators: %s: from address. */
				sprintf( __( 'From address: %s', 'launch-forms' ), $this->from_address() ),
			)
		);

		$error   = null;
		$catcher = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catcher );
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $catcher );

		if ( $sent ) {
			return array(
				'success' => true,
				/* translators: %s: email address. */
				'message' => sprintf( __( 'Test email sent to %s. If it does not arrive within a few minutes, check your spam folder and consider an SMTP plugin.', 'launch-forms' ), $to ),
			);
		}

		$reason = $error instanceof \WP_Error ? $error->get_error_message() : __( 'wp_mail() returned false.', 'launch-forms' );
		return array(
			'success' => false,
			/* translators: %s: error message. */
			'message' => sprintf( __( 'The test email could not be sent: %s. Your server may not be configured to send mail; an SMTP plugin usually fixes this.', 'launch-forms' ), $reason ),
		);
	}

	/**
	 * The From address WordPress will use.
	 *
	 * @return string
	 */
	public function from_address() {
		$host    = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$host    = 0 === strpos( (string) $host, 'www.' ) ? substr( $host, 4 ) : $host;
		$default = 'wordpress@' . $host;
		return (string) apply_filters( 'wp_mail_from', $default );
	}
}
