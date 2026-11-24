<?php
/**
 * @covers \LaunchInteractiveForms\Smtp
 * @covers \LaunchInteractiveForms\Settings
 * @covers \LaunchInteractiveForms\Test_Email
 */

use LaunchInteractiveForms\Settings;

class SmtpTest extends LF_TestCase {

	private function enable( array $extra = array() ) {
		liforms()->settings->update(
			array_merge(
				array(
					'smtp_enabled'      => true,
					'smtp_host'         => 'smtp.example.com',
					'smtp_port'         => 587,
					'smtp_encryption'   => 'tls',
					'smtp_auth'         => true,
					'smtp_username'     => 'me@example.com',
					'smtp_password_new' => 'p@ss "word"\\ ü',
					'smtp_from_email'   => 'hello@example.com',
					'smtp_from_name'    => 'Example Co',
					'smtp_force_from'   => true,
				),
				$extra
			)
		);
	}

	private function send() {
		reset_phpmailer_instance();
		$this->assertTrue( wp_mail( 'to@example.org', 'Subject', 'Body' ) );
		return tests_retrieve_phpmailer_instance();
	}

	public function test_off_by_default_and_leaves_mail_alone() {
		$this->assertFalse( liforms()->smtp->is_enabled() );
		$mailer = $this->send();
		$this->assertSame( 'mail', $mailer->Mailer );
		$this->assertSame( 'wordpress@example.org', $mailer->From );
	}

	public function test_configures_phpmailer() {
		$this->enable();
		$mailer = $this->send();
		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp.example.com', $mailer->Host );
		$this->assertSame( 587, $mailer->Port );
		$this->assertSame( 'tls', $mailer->SMTPSecure );
		$this->assertTrue( $mailer->SMTPAutoTLS );
		$this->assertTrue( $mailer->SMTPAuth );
		$this->assertSame( 'me@example.com', $mailer->Username );
		$this->assertSame( 'p@ss "word"\\ ü', $mailer->Password, 'Password survives encryption exactly.' );
		$this->assertSame( 'hello@example.com', $mailer->From );
		$this->assertSame( 'Example Co', $mailer->FromName );
		$this->assertSame( 15, $mailer->Timeout );
	}

	public function test_ssl_none_and_no_auth() {
		$this->enable( array( 'smtp_encryption' => 'ssl', 'smtp_port' => 465 ) );
		$this->assertSame( 'ssl', $this->send()->SMTPSecure );

		$this->enable( array( 'smtp_encryption' => 'none', 'smtp_port' => 25, 'smtp_auth' => false ) );
		$mailer = $this->send();
		$this->assertSame( '', $mailer->SMTPSecure );
		$this->assertFalse( $mailer->SMTPAutoTLS, 'No surprise STARTTLS when encryption is off.' );
		$this->assertFalse( $mailer->SMTPAuth );
	}

	public function test_enabled_without_host_does_nothing() {
		$this->enable( array( 'smtp_host' => '' ) );
		$this->assertSame( 'mail', $this->send()->Mailer );
	}

	public function test_from_only_replaces_the_default_unless_forced() {
		$this->enable( array( 'smtp_force_from' => false ) );
		$this->assertSame( 'hello@example.com', $this->send()->From, 'Default wordpress@ address replaced.' );

		reset_phpmailer_instance();
		wp_mail( 'to@example.org', 'S', 'B', array( 'From: Shop <shop@example.net>' ) );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'shop@example.net', $mailer->From, 'Another plugin\'s From is kept.' );
		$this->assertSame( 'Shop', $mailer->FromName );

		$this->enable( array( 'smtp_force_from' => true ) );
		reset_phpmailer_instance();
		wp_mail( 'to@example.org', 'S', 'B', array( 'From: Shop <shop@example.net>' ) );
		$this->assertSame( 'hello@example.com', tests_retrieve_phpmailer_instance()->From, 'Forced.' );
	}

	public function test_form_notifications_go_through_smtp() {
		$this->enable();
		$form   = $this->contact_form();
		$result = liforms()->processor->process( $form, array( 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
		$this->assertTrue( $result->success );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp.example.com', $mailer->Host );
	}

	public function test_password_is_encrypted_and_never_stored_in_plain_text() {
		$this->enable();
		$raw = get_option( Settings::OPTION );
		$this->assertStringStartsWith( 'lfenc1:', $raw['smtp_password'] );
		$this->assertStringNotContainsString( 'p@ss', maybe_serialize( $raw ) );
		$this->assertArrayNotHasKey( 'smtp_password_new', $raw );
		$this->assertSame( 'p@ss "word"\\ ü', liforms()->settings->smtp_password() );

		// Two encryptions of the same password differ (random nonce).
		$this->assertNotSame( Settings::encrypt( 'x' ), Settings::encrypt( 'x' ) );
		// A plain value smuggled into the stored key is discarded.
		liforms()->settings->update( array( 'smtp_password' => 'plaintext' ) );
		$this->assertSame( '', get_option( Settings::OPTION )['smtp_password'] );
	}

	public function test_saving_the_form_without_a_new_password_keeps_the_old_one() {
		$this->enable();
		liforms()->settings->register();
		// What options.php sends: the password box is empty and the stored value isn't in the form.
		update_option(
			Settings::OPTION,
			array(
				'smtp_enabled'      => '1',
				'smtp_host'         => 'smtp2.example.com',
				'smtp_password_new' => '',
			)
		);
		$this->assertSame( 'smtp2.example.com', liforms()->settings->get( 'smtp_host' ) );
		$this->assertSame( 'p@ss "word"\\ ü', liforms()->settings->smtp_password() );

		update_option( Settings::OPTION, array( 'smtp_enabled' => '1', 'smtp_password_new' => 'new-one' ) );
		$this->assertSame( 'new-one', liforms()->settings->smtp_password() );

		update_option( Settings::OPTION, array( 'smtp_enabled' => '1', 'smtp_password_clear' => '1' ) );
		$this->assertSame( '', liforms()->settings->smtp_password() );
		$this->assertFalse( liforms()->settings->has_smtp_password() );
	}

	public function test_tampered_or_foreign_ciphertext_decrypts_to_nothing() {
		$this->assertSame( '', Settings::decrypt( 'lfenc1:' . base64_encode( str_repeat( 'x', 60 ) ) ) );
		$this->assertSame( '', Settings::decrypt( 'lfenc1:!!!' ) );
		$this->assertSame( '', Settings::decrypt( 'plain' ) );
		$good = Settings::encrypt( 'secret' );
		$this->assertSame( '', Settings::decrypt( substr( $good, 0, -2 ) . 'AA' ) );
	}

	public function test_settings_are_sanitised() {
		$clean = liforms()->settings->sanitize(
			array(
				'smtp_host'       => "smtp.example.com\r\nX: y<script>",
				'smtp_port'       => '99999',
				'smtp_encryption' => 'starttls-ish',
				'smtp_username'   => " me@example.com\n",
				'smtp_from_email' => 'not an email',
				'smtp_from_name'  => 'Bad "Name" <x>',
			)
		);
		$this->assertSame( 'smtp.example.comXyscript', $clean['smtp_host'] );
		$this->assertSame( 587, $clean['smtp_port'] );
		$this->assertSame( 'tls', $clean['smtp_encryption'] );
		$this->assertSame( 'me@example.com', $clean['smtp_username'] );
		$this->assertSame( '', $clean['smtp_from_email'] );
		$this->assertSame( 'Bad Name', $clean['smtp_from_name'] );
	}

	public function test_test_email_reports_the_smtp_server() {
		$this->enable();
		$result = liforms()->test_email->send( 'me@example.org' );
		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'SMTP server smtp.example.com:587', $result['message'] );
		$this->assertStringContainsString( 'Sent using: SMTP server smtp.example.com:587', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_smtp_details_are_hidden_until_custom_smtp_is_ticked() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = static function () {
			ob_start();
			liforms()->admin->render_settings_page();
			return ob_get_clean();
		};
		$html = $page();
		$this->assertStringContainsString( 'Use Custom SMTP', $html );
		$this->assertStringContainsString( '<div id="lif-smtp-details" hidden>', $html );

		$this->enable();
		$this->assertStringContainsString( '<div id="lif-smtp-details">', $page() );
	}

	public function test_settings_page_never_prints_the_password() {
		$this->enable();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="liforms_settings[smtp_password_new]"', $html );
		$this->assertStringContainsString( 'Saved. Leave blank to keep it.', $html );
		$this->assertStringNotContainsString( 'p@ss', $html );
		$this->assertStringNotContainsString( 'lfenc1:', $html );
		$this->assertStringContainsString( 'value="smtp.example.com"', $html );
	}
}
