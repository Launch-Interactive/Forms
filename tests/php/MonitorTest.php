<?php
/**
 * Automated delivery tests: the monitoring key.
 *
 * @covers \LaunchInteractiveForms\Monitor
 * @covers \LaunchInteractiveForms\Processor
 * @covers \LaunchInteractiveForms\Rest_Api
 * @covers \LaunchInteractiveForms\Frontend
 */

use LaunchInteractiveForms\Monitor;
use LaunchInteractiveForms\Submissions;

class MonitorTest extends LF_TestCase {

	/** @var string */
	private $key;

	public function set_up() {
		parent::set_up();
		$this->key = Monitor::generate();
		reset_phpmailer_instance();
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_X_LIFORMS_MONITOR'], $_SERVER['HTTP_X_LIFORMS_MONITOR_RUN'] );
		parent::tear_down();
	}

	/** Contact form with reCAPTCHA, a notification to the client, Mailchimp and Zoho turned on. */
	private function protected_form( array $extra = array() ) {
		liforms()->settings->update(
			array(
				'recaptcha_site_key'   => 'site-key',
				'recaptcha_secret_key' => 'secret-key',
				'mailchimp_api_key'    => 'k-us5',
			)
		);
		return $this->make_form(
			array(
				$this->field( 'text', 'name', array( 'required' => true ) ),
				$this->field( 'email', 'email', array( 'required' => true ) ),
				array( 'type' => 'recaptcha' ),
			),
			array_merge(
				array(
					'settings'      => array( 'analytics_event' => 'contact_form_submit' ),
					'notifications' => array(
						array(
							'enabled' => true,
							'name'    => 'Client',
							'to'      => 'info@client.example, sales@client.example',
							'subject' => 'New enquiry from {field:name}',
							'message' => '{all_fields}',
						),
					),
					'mailchimp'     => array( 'enabled' => true, 'list_id' => 'abc', 'email_field' => 'email' ),
					'zoho'          => array( 'enabled' => true, 'field_map' => array( array( 'zoho' => 'Email', 'field' => 'email' ) ) ),
				),
				$extra
			)
		);
	}

	private function rest_submit( $form, array $values, array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', '/liforms/v1/forms/' . $form->id . '/submit' );
		$request->set_body_params( array( 'lif' => $values ) );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	private function monitor_headers( $run = 'RUN123' ) {
		return array(
			'X-Liforms-Monitor'     => $this->key,
			'X-Liforms-Monitor-Run' => $run,
		);
	}

	public function test_key_check() {
		$this->assertSame( 48, strlen( $this->key ) );
		$this->assertSame( 'ABC-1script', Monitor::check( $this->key, 'ABC-1<script>' ), 'Only letters, digits and dashes are kept.' );
		$this->assertSame( 'test', Monitor::check( $this->key, '' ) );
		$this->assertSame( '', Monitor::check( 'wrong', 'X' ) );
		$this->assertSame( '', Monitor::check( '', 'X' ) );
		Monitor::disable();
		$this->assertSame( '', Monitor::check( $this->key, 'X' ), 'Turned off: no key works, including an empty one.' );
		$this->assertSame( '', Monitor::check( '', 'X' ) );
	}

	public function test_key_is_stored_encrypted() {
		$this->assertStringStartsWith( 'lfenc1:', get_option( Monitor::OPTION ) );
		$this->assertStringNotContainsString( $this->key, (string) get_option( Monitor::OPTION ) );
	}

	public function test_without_the_key_the_captcha_still_applies() {
		$form = $this->protected_form();
		$this->assertSame( 422, $this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com' ) )->get_status() );
		$wrong = $this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com' ), array( 'X-Liforms-Monitor' => 'nope' ) );
		$this->assertSame( 422, $wrong->get_status() );
		$this->assertArrayNotHasKey( 'monitor', $wrong->get_data() );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_a_monitored_submission_is_emailed_as_a_test_and_nothing_else() {
		$form  = $this->protected_form();
		$hooks = 0;
		add_action(
			'liforms_submission',
			static function () use ( &$hooks ) {
				++$hooks;
			}
		);
		$this->mock_http( '__return_empty_array' );

		$response = $this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'monitor@example.com' ), $this->monitor_headers() );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'RUN123', $data['monitor']['run'] );
		$this->assertSame(
			array(
				array(
					'action'  => 'email',
					'name'    => 'Client',
					'success' => true,
					'message' => 'Sent',
					'to'      => array( 'info@client.example', 'sales@client.example' ),
				),
			),
			$data['monitor']['notifications']
		);

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( '[Automated test RUN123] New enquiry from Ann', $mail->subject );
		$this->assertStringStartsWith( Monitor::note(), $mail->body );
		$this->assertStringContainsString( 'Email: monitor@example.com', $mail->body );

		$this->assertSame( array(), get_posts( array( 'post_type' => Submissions::POST_TYPE, 'post_parent' => $form->id ) ), 'Not stored.' );
		$this->assertSame( array(), $this->http_requests, 'No captcha check, Mailchimp or Zoho request.' );
		$this->assertSame( 0, $hooks, 'Submission hooks don\'t run.' );
	}

	public function test_autoresponders_are_not_sent_during_a_test() {
		$notifications = array(
			array(
				'enabled' => true,
				'name'    => 'Client',
				'to'      => 'info@client.example',
				'subject' => 'New enquiry',
			),
			array(
				'enabled' => true,
				'name'    => 'Submission Confirmation',
				'to'      => '{field:email}',
				'subject' => 'Thanks for your enquiry',
			),
		);
		$form = $this->protected_form( array( 'notifications' => $notifications ) );

		$data = $this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'Test@Example.invalid' ), $this->monitor_headers() )->get_data();
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent, 'Only the client notification is sent.' );
		$this->assertSame( 'info@client.example', $sent[0]['to'][0][0] );
		$auto = $data['monitor']['notifications'][1];
		$this->assertTrue( $auto['skipped'] );
		$this->assertSame( array( 'Test@Example.invalid' ), $auto['to'] );

		// A real submission still gets its confirmation.
		reset_phpmailer_instance();
		liforms()->processor->reset();
		liforms()->settings->update( array( 'recaptcha_site_key' => '', 'recaptcha_secret_key' => '' ) );
		$this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com' ) );
		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	public function test_a_monitored_submission_is_still_validated() {
		$response = $this->rest_submit( $this->protected_form(), array( 'name' => '', 'email' => 'not-an-email' ), $this->monitor_headers() );
		$this->assertSame( 422, $response->get_status() );
		$this->assertArrayHasKey( 'email', (array) $response->get_data()['errors'] );
	}

	public function test_monitoring_skips_the_rate_limit_and_honeypot() {
		$form = $this->protected_form();
		add_filter(
			'liforms_rate_limit',
			static function () {
				return array(
					'max'    => 1,
					'window' => 600,
				);
			}
		);
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->rest_submit( $form, array( 'name' => 'Ann', 'email' => 'a@example.com' ), $this->monitor_headers() )->get_status() );
		}
		$request = new WP_REST_Request( 'POST', '/liforms/v1/forms/' . $form->id . '/submit' );
		$request->set_body_params( array( 'lif' => array( 'name' => 'Ann', 'email' => 'a@example.com' ), 'lif_hp' => 'filled' ) );
		foreach ( $this->monitor_headers() as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$this->assertArrayHasKey( 'monitor', rest_get_server()->dispatch( $request )->get_data(), 'A filled honeypot is ignored for a test run.' );
	}

	public function test_plain_post_test_runs_skip_the_analytics_event() {
		$form                                   = $this->protected_form();
		$_SERVER['REQUEST_METHOD']              = 'POST';
		$_SERVER['HTTP_X_LIFORMS_MONITOR']      = $this->key;
		$_SERVER['HTTP_X_LIFORMS_MONITOR_RUN']  = 'PLAIN1';
		$redirect = liforms()->frontend->process_post(
			array(
				'lif_form_id' => $form->id,
				'lif'         => array( 'name' => 'Ann', 'email' => 'a@example.com' ),
			),
			array()
		);
		$this->assertStringContainsString( 'lif_test=1', $redirect );
		$this->assertSame( '[Automated test PLAIN1] New enquiry from Ann', tests_retrieve_phpmailer_instance()->get_sent()->subject );

		liforms()->processor->reset();
		$_GET = array( 'lif_sent' => (string) $form->id, 'lif_test' => '1' );
		$html = liforms()->renderer->render( $form );
		$this->assertStringNotContainsString( 'data-lif-sent', $html );
		$_GET = array( 'lif_sent' => (string) $form->id );
		$this->assertStringContainsString( 'data-lif-sent', liforms()->renderer->render( $form ), 'Real submissions still send the event.' );
		$_GET = array();
	}

	public function test_settings_page_shows_and_creates_the_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="' . $this->key . '"', $html );
		$this->assertStringContainsString( 'value="liforms_monitor_key"', $html );

		Monitor::disable();
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Create a monitoring key', $html );
		$this->assertStringNotContainsString( 'id="lif-monitor-key"', $html );
	}
}
