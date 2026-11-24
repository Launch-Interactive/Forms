<?php
/**
 * @covers \LaunchForms\Test_Email
 * @covers \LaunchForms\Admin::handle_test_email
 */

class TestEmailTest extends LF_TestCase {

	public function test_sends_test_email() {
		$result = launch_forms()->test_email->send( 'me@example.com' );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'me@example.com', $result['message'] );
		$mail = $this->sent_emails()[0];
		$this->assertSame( 'me@example.com', $mail['to'][0][0] );
		$this->assertStringContainsString( 'Launch Interactive Forms test email', $mail['subject'] );
		$this->assertStringContainsString( 'WordPress can send email', $mail['body'] );
	}

	public function test_rejects_invalid_address() {
		$result = launch_forms()->test_email->send( 'not-an-address' );
		$this->assertFalse( $result['success'] );
		$this->assertCount( 0, $this->sent_emails() );
	}

	public function test_reports_wp_mail_failure_reason() {
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not instantiate mail function.' ) );
				return false;
			}
		);
		$result = launch_forms()->test_email->send( 'me@example.com' );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Could not instantiate mail function.', $result['message'] );
		$this->assertStringContainsString( 'SMTP', $result['message'] );
	}

	public function test_reports_generic_failure() {
		add_filter( 'pre_wp_mail', '__return_false' );
		$this->assertStringContainsString( 'wp_mail() returned false', launch_forms()->test_email->send( 'me@example.com' )['message'] );
	}

	public function test_from_address() {
		$this->assertSame( 'wordpress@example.org', launch_forms()->test_email->from_address() );
		add_filter(
			'wp_mail_from',
			static function () {
				return 'noreply@example.org';
			}
		);
		$this->assertSame( 'noreply@example.org', launch_forms()->test_email->from_address() );
	}

	public function test_admin_handler_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		launch_forms()->admin->handle_test_email();
	}

	public function test_admin_handler_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['to']             = 'me@example.com';
		$_REQUEST['_wpnonce']    = 'bad';
		$this->expectException( WPDieException::class );
		try {
			launch_forms()->admin->handle_test_email();
		} finally {
			$this->assertCount( 0, $this->sent_emails() );
			unset( $_POST['to'], $_REQUEST['_wpnonce'] );
		}
	}

	public function test_settings_page_contains_test_email_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		launch_forms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="launch_forms_test_email"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'Send test email', $html );
		$this->assertStringContainsString( 'launch_forms_settings[recaptcha_site_key]', $html );
		$this->assertStringContainsString( 'launch_forms_settings[turnstile_secret_key]', $html );
		$this->assertStringContainsString( 'launch_forms_settings[mailchimp_api_key]', $html );
	}

	public function test_settings_page_shows_last_result_once() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		set_transient( 'launch_forms_test_email_' . $user, array( 'success' => false, 'message' => 'Boom <b>' ), 60 );
		ob_start();
		launch_forms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Boom &lt;b&gt;', $html );
		$this->assertFalse( get_transient( 'launch_forms_test_email_' . $user ) );
	}
}
