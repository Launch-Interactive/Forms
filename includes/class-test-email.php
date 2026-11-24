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

	/** @var Smtp|null */
	private $smtp;

	/**
	 * Constructor.
	 *
	 * @param Smtp|null $smtp SMTP settings, to say how the email was sent.
	 */
	public function __construct( ?Smtp $smtp = null ) {
		$this->smtp = $smtp;
	}

	/**
	 * How email is being sent, for messages.
	 *
	 * @return string
	 */
	public function transport_label() {
		if ( $this->smtp && $this->smtp->is_enabled() ) {
			/* translators: %s: SMTP host:port. */
			return sprintf( __( 'SMTP server %s', 'launch-interactive-forms' ), $this->smtp->server_label() );
		}
		return __( "the web server's built-in mail function", 'launch-interactive-forms' );
	}

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
				'message' => __( 'Please enter a valid email address.', 'launch-interactive-forms' ),
			);
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Launch Interactive Forms test email', 'launch-interactive-forms' ), $site );
		$body    = implode(
			"\n",
			array(
				__( 'This is a test email sent from the Launch Interactive Forms settings page.', 'launch-interactive-forms' ),
				__( 'If you are reading this, WordPress can send email and your form notifications should arrive.', 'launch-interactive-forms' ),
				'',
				/* translators: %s: URL. */
				sprintf( __( 'Site: %s', 'launch-interactive-forms' ), home_url( '/' ) ),
				/* translators: %s: date. */
				sprintf( __( 'Sent: %s', 'launch-interactive-forms' ), wp_date( 'Y-m-d H:i:s T' ) ),
				/* translators: %s: from address. */
				sprintf( __( 'From address: %s', 'launch-interactive-forms' ), $this->from_address() ),
				/* translators: %s: e.g. "SMTP server smtp.example.com:587". */
				sprintf( __( 'Sent using: %s', 'launch-interactive-forms' ), $this->transport_label() ),
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
				/* translators: 1: email address, 2: e.g. "SMTP server smtp.example.com:587". */
				'message' => sprintf( __( 'Test email sent to %1$s using %2$s. If it does not arrive within a few minutes, check your spam folder.', 'launch-interactive-forms' ), $to, $this->transport_label() ),
			);
		}

		$reason = $error instanceof \WP_Error ? $error->get_error_message() : __( 'wp_mail() returned false.', 'launch-interactive-forms' );
		$reason = rtrim( trim( wp_strip_all_tags( $reason ) ), '.' );
		return array(
			'success' => false,
			'message' => $this->smtp && $this->smtp->is_enabled()
				/* translators: 1: SMTP host:port, 2: error message. */
				? sprintf( __( 'The test email could not be sent through %1$s: %2$s. Check the SMTP host, port, encryption, username and password.', 'launch-interactive-forms' ), $this->smtp->server_label(), $reason )
				/* translators: %s: error message. */
				: sprintf( __( 'The test email could not be sent: %s. Your server may not be able to send mail; set up SMTP above to send through your email provider instead.', 'launch-interactive-forms' ), $reason ),
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
		return (string) apply_filters( 'wp_mail_from', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter: shows the From address wp_mail() will use.
	}
}
