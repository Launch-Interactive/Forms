<?php
/**
 * Shared helpers for Launch Interactive Forms tests.
 *
 * @package LaunchForms
 */

use LaunchForms\Form;
use LaunchForms\Form_Config;

/**
 * Base test case.
 */
abstract class LF_TestCase extends WP_UnitTestCase {

	/** @var array[] HTTP requests captured by mock_http(). */
	protected $http_requests = array();

	/** @var string Private upload dir used by the test. */
	protected $private_dir;

	/** @var string[] Temp files to clean up. */
	private $temp_files = array();

	public function set_up() {
		parent::set_up();
		reset_phpmailer_instance();
		launch_forms()->processor->reset();
		launch_forms()->renderer->reset();
		// WP_UnitTestCase unregisters all meta keys after each test; restore ours.
		launch_forms()->forms->register();
		$this->http_requests = array();

		$this->private_dir = trailingslashit( get_temp_dir() ) . 'lf-private-' . wp_generate_password( 8, false );
		$dir               = $this->private_dir;
		add_filter(
			'launch_forms_private_dir',
			static function () use ( $dir ) {
				return $dir;
			}
		);
		// Fixture files are not real HTTP uploads.
		add_filter( 'launch_forms_is_uploaded_file', '__return_true' );
	}

	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->private_dir ) ) {
			foreach ( scandir( $this->private_dir ) as $file ) {
				if ( is_file( $this->private_dir . '/' . $file ) ) {
					unlink( $this->private_dir . '/' . $file );
				}
			}
			rmdir( $this->private_dir );
		}
		delete_option( 'launch_forms_settings' );
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Create and load a published form.
	 *
	 * @param array  $fields Field definitions.
	 * @param array  $extra  Extra config sections (settings, notifications, mailchimp).
	 * @param string $title  Title.
	 * @return Form
	 */
	protected function make_form( array $fields, array $extra = array(), $title = 'Contact' ) {
		$config = array_merge( array( 'fields' => $fields ), $extra );
		$id     = launch_forms()->forms->create( $title, $config );
		$this->assertIsInt( $id );
		return launch_forms()->forms->get( $id );
	}

	/**
	 * A minimal field definition.
	 *
	 * @param string $type  Type.
	 * @param string $name  Name.
	 * @param array  $extra Overrides.
	 * @return array
	 */
	protected function field( $type, $name, array $extra = array() ) {
		return array_merge(
			array(
				'type'  => $type,
				'name'  => $name,
				'label' => ucfirst( str_replace( '_', ' ', $name ) ),
			),
			$extra
		);
	}

	/**
	 * Standard contact form.
	 *
	 * @param array $extra Extra config.
	 * @return Form
	 */
	protected function contact_form( array $extra = array() ) {
		return $this->make_form(
			array(
				$this->field( 'text', 'name', array( 'required' => true ) ),
				$this->field( 'email', 'email', array( 'required' => true ) ),
				$this->field( 'textarea', 'message' ),
			),
			$extra
		);
	}

	/**
	 * Intercept outgoing HTTP requests.
	 *
	 * @param callable $responder fn( string $url, array $args ): array{code:int, body:mixed}.
	 */
	protected function mock_http( callable $responder ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responder ) {
				$this->http_requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$response = $responder( $url, $args );
				return array(
					'headers'  => array(),
					'body'     => is_string( $response['body'] ) ? $response['body'] : wp_json_encode( $response['body'] ),
					'response' => array(
						'code'    => $response['code'] ?? 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Create a fixture "uploaded" file.
	 *
	 * @param string $name     Client file name.
	 * @param string $contents Contents.
	 * @return array Normalised file array.
	 */
	protected function fake_upload( $name, $contents ) {
		$tmp = wp_tempnam( 'lf-test' );
		file_put_contents( $tmp, $contents );
		$this->temp_files[] = $tmp;
		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $contents ),
		);
	}

	/**
	 * Bytes of a valid 1x1 PNG.
	 *
	 * @return string
	 */
	protected function png_bytes() {
		return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
	}

	/**
	 * Bytes of a tiny valid PDF.
	 *
	 * @return string
	 */
	protected function pdf_bytes() {
		return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	}

	/**
	 * Emails sent through the mock mailer.
	 *
	 * @return array
	 */
	protected function sent_emails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}
}
