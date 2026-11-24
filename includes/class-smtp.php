<?php
/**
 * Send all WordPress email through an SMTP server.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Configures PHPMailer from the plugin's SMTP settings.
 */
class Smtp {

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
	 * Hooks. These run for every email WordPress sends, not just form notifications.
	 */
	public function register_hooks() {
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_filter( 'wp_mail_from', array( $this, 'from_email' ), 20 );
		add_filter( 'wp_mail_from_name', array( $this, 'from_name' ), 20 );
	}

	/**
	 * Whether SMTP is switched on and has a server.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) $this->settings->get( 'smtp_enabled' ) && '' !== (string) $this->settings->get( 'smtp_host' );
	}

	/**
	 * "host:port" for messages.
	 *
	 * @return string
	 */
	public function server_label() {
		return $this->settings->get( 'smtp_host' ) . ':' . (int) $this->settings->get( 'smtp_port' );
	}

	/**
	 * Point PHPMailer at the SMTP server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
	 */
	public function configure( $phpmailer ) {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$encryption = (string) $this->settings->get( 'smtp_encryption' );

		$phpmailer->isSMTP();
		$phpmailer->Host        = (string) $this->settings->get( 'smtp_host' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->Port        = (int) $this->settings->get( 'smtp_port' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPSecure  = in_array( $encryption, array( 'tls', 'ssl' ), true ) ? $encryption : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPAutoTLS = 'none' !== $encryption; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		$phpmailer->SMTPAuth    = (bool) $this->settings->get( 'smtp_auth' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( $phpmailer->SMTPAuth ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Username = (string) $this->settings->get( 'smtp_username' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$phpmailer->Password = $this->settings->smtp_password(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		$phpmailer->Timeout = 15; // phpcs:ignore WordPress.NamingConventions.ValidVariableName

		/**
		 * Fires after Launch Interactive Forms configures SMTP, for extra PHPMailer options.
		 *
		 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer.
		 */
		do_action( 'launch_forms_smtp_configured', $phpmailer );
	}

	/**
	 * Use the configured From address: always when "force" is on, otherwise only in place of
	 * WordPress' default wordpress@ address.
	 *
	 * @param string $email Current From address.
	 * @return string
	 */
	public function from_email( $email ) {
		$ours = (string) $this->settings->get( 'smtp_from_email' );
		if ( ! $this->is_enabled() || ! is_email( $ours ) ) {
			return $email;
		}
		if ( $this->settings->get( 'smtp_force_from' ) || 0 === strpos( (string) $email, 'wordpress@' ) ) {
			return $ours;
		}
		return $email;
	}

	/**
	 * Use the configured From name, on the same terms as the address.
	 *
	 * @param string $name Current From name.
	 * @return string
	 */
	public function from_name( $name ) {
		$ours = (string) $this->settings->get( 'smtp_from_name' );
		if ( ! $this->is_enabled() || '' === $ours ) {
			return $name;
		}
		if ( $this->settings->get( 'smtp_force_from' ) || 'WordPress' === $name ) {
			return $ours;
		}
		return $name;
	}
}
