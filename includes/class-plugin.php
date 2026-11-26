<?php
/**
 * Service container and bootstrap.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/** @var Settings */
	public $settings;
	/** @var Forms */
	public $forms;
	/** @var Submissions */
	public $submissions;
	/** @var Uploads */
	public $uploads;
	/** @var Captcha */
	public $captcha;
	/** @var Notifications */
	public $notifications;
	/** @var Processor */
	public $processor;
	/** @var Renderer */
	public $renderer;
	/** @var Frontend */
	public $frontend;
	/** @var Rest_Api */
	public $rest;
	/** @var Test_Email */
	public $test_email;
	/** @var Smtp */
	public $smtp;
	/** @var Admin */
	public $admin;
	/** @var Zoho */
	public $zoho;

	/**
	 * Whether hooks are registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build services.
	 */
	private function __construct() {
		$this->settings      = new Settings();
		$this->forms         = new Forms();
		$this->uploads       = new Uploads();
		$this->submissions   = new Submissions( $this->uploads );
		$this->captcha       = new Captcha( $this->settings );
		$this->notifications = new Notifications( $this->uploads );
		$this->processor     = new Processor( $this );
		$this->renderer      = new Renderer( $this );
		$this->frontend      = new Frontend( $this );
		$this->rest          = new Rest_Api( $this );
		$this->smtp          = new Smtp( $this->settings );
		$this->test_email    = new Test_Email( $this->smtp );
		$this->admin         = new Admin( $this );
		$this->zoho          = new Zoho( $this->settings );
	}

	/**
	 * Register hooks once.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( Upgrade::class, 'maybe_run' ), 1 );
		$this->settings->register_hooks();
		$this->smtp->register_hooks();
		$this->forms->register_hooks();
		$this->submissions->register_hooks();
		$this->frontend->register_hooks();
		$this->rest->register_hooks();
		$this->admin->register_hooks();
	}

	/**
	 * Activation: create the protected upload directory.
	 */
	public static function activate() {
		Upgrade::maybe_run();
		self::instance()->uploads->get_dir();
	}

	/**
	 * Capability required to view submissions, files and settings.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filters the capability needed to view submissions, download files and change settings.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return (string) apply_filters( 'liforms_manage_capability', 'manage_options' );
	}
}
