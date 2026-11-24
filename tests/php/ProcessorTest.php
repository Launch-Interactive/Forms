<?php
/**
 * @covers \LaunchForms\Processor
 * @covers \LaunchForms\Submission_Result
 */

use LaunchForms\Submissions;

class ProcessorTest extends LF_TestCase {

	private function submit( $form, array $values, array $files = array(), array $extra = array() ) {
		return launch_forms()->processor->process( $form, array_merge( array( 'lf' => $values ), $extra ), $files, array( 'page_url' => 'https://example.org/contact/' ) );
	}

	private function submissions_for( $form ) {
		return get_posts(
			array(
				'post_type'   => Submissions::POST_TYPE,
				'post_parent' => $form->id,
				'numberposts' => -1,
			)
		);
	}

	public function test_successful_submission_is_stored_and_emailed() {
		$form   = $this->contact_form( array( 'settings' => array( 'success_message' => '<p>Cheers</p>' ) ) );
		$result = $this->submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com', 'message' => "Hi\nthere" ) );

		$this->assertTrue( $result->success );
		$this->assertSame( 'Cheers', $result->message );
		$this->assertGreaterThan( 0, $result->submission_id );

		$entries = launch_forms()->submissions->get_entries( $result->submission_id );
		$this->assertSame( array( 'name', 'email', 'message' ), wp_list_pluck( $entries, 'name' ) );
		$this->assertSame( "Hi\nthere", $entries[2]['value'] );
		$this->assertSame( 'Message', $entries[2]['label'] );
		$this->assertSame( 'https://example.org/contact/', get_post_meta( $result->submission_id, Submissions::META_PAGE, true ) );

		$emails = $this->sent_emails();
		$this->assertCount( 1, $emails );
		$this->assertSame( get_option( 'admin_email' ), $emails[0]['to'][0][0] );
		$this->assertStringContainsString( 'Email: ann@example.com', $emails[0]['body'] );

		$log = launch_forms()->submissions->get_log( $result->submission_id );
		$this->assertTrue( $log[0]['success'] );
	}

	public function test_validation_errors_prevent_storage_and_email() {
		$form   = $this->contact_form();
		$result = $this->submit( $form, array( 'name' => '', 'email' => 'bad' ) );
		$this->assertFalse( $result->success );
		$this->assertSame( array( 'name', 'email' ), array_keys( $result->errors ) );
		$this->assertSame( 'bad', $result->values['email'] );
		$this->assertCount( 0, $this->submissions_for( $form ) );
		$this->assertCount( 0, $this->sent_emails() );
		$this->assertSame( $result, launch_forms()->processor->get_result( $form->id ) );
	}

	public function test_honeypot_silently_discards() {
		$form   = $this->contact_form();
		$result = $this->submit( $form, array( 'name' => 'Bot', 'email' => 'bot@example.com' ), array(), array( 'lf_hp' => 'http://spam' ) );
		$this->assertTrue( $result->success, 'Bots should see success.' );
		$this->assertTrue( $result->spam );
		$this->assertCount( 0, $this->submissions_for( $form ) );
		$this->assertCount( 0, $this->sent_emails() );
	}

	public function test_storage_can_be_disabled() {
		$form   = $this->contact_form( array( 'settings' => array( 'store_submissions' => false ) ) );
		$result = $this->submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com' ) );
		$this->assertTrue( $result->success );
		$this->assertSame( 0, $result->submission_id );
		$this->assertCount( 0, $this->submissions_for( $form ) );
		$this->assertCount( 1, $this->sent_emails() );
	}

	public function test_redirect_url_returned() {
		$form   = $this->contact_form( array( 'settings' => array( 'redirect_url' => 'https://example.org/thanks/' ) ) );
		$result = $this->submit( $form, array( 'name' => 'Ann', 'email' => 'ann@example.com' ) );
		$this->assertSame( 'https://example.org/thanks/', $result->redirect );
	}

	public function test_conditionally_hidden_values_are_not_stored() {
		$form   = $this->make_form(
			array(
				$this->field( 'select', 'topic', array( 'options' => "Sales\nSupport" ) ),
				$this->field(
					'text',
					'order_number',
					array(
						'required'   => true,
						'conditions' => array(
							'enabled' => true,
							'rules'   => array( array( 'field' => 'topic', 'operator' => 'is', 'value' => 'Support' ) ),
						),
					)
				),
			)
		);
		$result  = $this->submit( $form, array( 'topic' => 'Sales', 'order_number' => 'smuggled' ) );
		$this->assertTrue( $result->success );
		$entries = launch_forms()->submissions->get_entries( $result->submission_id );
		$this->assertSame( array( 'topic' ), wp_list_pluck( $entries, 'name' ) );

		$result = $this->submit( $form, array( 'topic' => 'Support' ) );
		$this->assertFalse( $result->success );
		$this->assertArrayHasKey( 'order_number', $result->errors );
	}

	public function test_file_upload_is_stored_privately_and_linked() {
		$form   = $this->make_form(
			array(
				$this->field( 'email', 'email', array( 'required' => true ) ),
				$this->field( 'file', 'cv', array( 'required' => true, 'allowed_types' => 'pdf' ) ),
			)
		);
		$result = $this->submit( $form, array( 'email' => 'ann@example.com' ), array( 'cv' => $this->fake_upload( 'My CV.pdf', $this->pdf_bytes() ) ) );
		$this->assertTrue( $result->success, wp_json_encode( $result->errors ) );

		$entries = launch_forms()->submissions->get_entries( $result->submission_id );
		$file    = $entries[1]['value'];
		$this->assertSame( 'My-CV.pdf', $file['name'] );
		$this->assertFileExists( launch_forms()->uploads->path( $file['file'] ) );
		$this->assertStringContainsString( 'Cv: My-CV.pdf', $this->sent_emails()[0]['body'] );
	}

	public function test_required_file_missing_and_bad_file() {
		$form = $this->make_form( array( $this->field( 'file', 'cv', array( 'required' => true, 'allowed_types' => 'pdf' ) ) ) );
		$this->assertSame( 'This field is required.', $this->submit( $form, array() )->errors['cv'] );

		$result = $this->submit( $form, array(), array( 'cv' => $this->fake_upload( 'cv.pdf', '<?php system($_GET[1]);' ) ) );
		$this->assertSame( 'This file type is not allowed.', $result->errors['cv'] );
		$this->assertCount( 0, glob( $this->private_dir . '/*' ) ?: array() );
	}

	public function test_files_not_stored_when_other_fields_fail() {
		$form   = $this->make_form(
			array(
				$this->field( 'email', 'email', array( 'required' => true ) ),
				$this->field( 'file', 'cv', array( 'allowed_types' => 'pdf' ) ),
			)
		);
		$result = $this->submit( $form, array( 'email' => '' ), array( 'cv' => $this->fake_upload( 'cv.pdf', $this->pdf_bytes() ) ) );
		$this->assertFalse( $result->success );
		$stored = array_filter(
			(array) glob( $this->private_dir . '/*' ),
			static function ( $f ) {
				return preg_match( '/[a-f0-9]{32}$/', $f );
			}
		);
		$this->assertCount( 0, $stored, 'No orphaned files on failed submissions.' );
	}

	public function test_files_deleted_after_email_when_storage_disabled() {
		$form = $this->make_form(
			array( $this->field( 'file', 'cv', array( 'allowed_types' => 'pdf' ) ) ),
			array(
				'settings'      => array( 'store_submissions' => false ),
				'notifications' => array( array( 'to' => 'hr@example.com', 'attach_files' => true ) ),
			)
		);
		$attachments = array();
		$perms       = array();
		add_filter(
			'wp_mail',
			static function ( $args ) use ( &$attachments, &$perms ) {
				foreach ( $args['attachments'] as $path ) {
					$attachments[ $path ] = file_get_contents( $path );
					$perms[]              = fileperms( dirname( $path ) ) & 0777;
				}
				return $args;
			}
		);
		$this->submit( $form, array(), array( 'cv' => $this->fake_upload( 'cv.pdf', $this->pdf_bytes() ) ) );
		$this->assertCount( 1, $attachments );
		$path = array_keys( $attachments )[0];
		$this->assertSame( 'cv.pdf', basename( $path ), 'Attachment keeps the original file name.' );
		$this->assertStringStartsWith( trailingslashit( $this->private_dir ), $path, 'Copies live in the protected private folder, not the system temp dir.' );
		$this->assertSame( array( 0700 ), $perms, 'The copy folder is owner-only.' );
		$this->assertDirectoryDoesNotExist( dirname( $path ), 'The copy folder is removed after sending.' );
		$this->assertSame( $this->pdf_bytes(), $attachments[ $path ] );
		$this->assertFileDoesNotExist( $path, 'Temporary attachment copy is cleaned up.' );
		$this->assertStringContainsString( 'filename=cv.pdf', $this->sent_emails()[0]['body'] );
		$stored = array_filter(
			(array) glob( $this->private_dir . '/*' ),
			static function ( $f ) {
				return preg_match( '/[a-f0-9]{32}$/', $f );
			}
		);
		$this->assertCount( 0, $stored );
	}

	public function test_hooks_fire() {
		$form  = $this->contact_form();
		$calls = array();
		add_action(
			'launch_forms_before_actions',
			static function () use ( &$calls ) {
				$calls[] = 'before';
			}
		);
		add_action(
			'launch_forms_submission',
			static function ( $id, $f, $entries, $log ) use ( &$calls ) {
				$calls[] = array( $id, wp_list_pluck( $entries, "name" ), count( $log ) );
			},
			10,
			4
		);
		$result = $this->submit( $form, array( 'name' => 'A', 'email' => 'a@example.com' ) );
		$this->assertSame( array( "before", array( $result->submission_id, array( "name", "email", "message" ), 1 ) ), $calls );
	}

	public function test_mailchimp_runs_and_failures_are_logged_not_fatal() {
		launch_forms()->settings->update( array( 'mailchimp_api_key' => 'abc-us5' ) );
		$this->mock_http(
			static function () {
				return array(
					'code' => 400,
					'body' => array( 'title' => 'Invalid Resource', 'detail' => 'looks fake' ),
				);
			}
		);
		$form   = $this->contact_form(
			array(
				'mailchimp' => array(
					'enabled'     => true,
					'list_id'     => 'list1',
					'email_field' => 'email',
				),
			)
		);
		$result = $this->submit( $form, array( 'name' => 'A', 'email' => 'a@example.com' ) );
		$this->assertTrue( $result->success, 'An integration failure must not lose the submission.' );
		$log = launch_forms()->submissions->get_log( $result->submission_id );
		$this->assertSame( 'mailchimp', $log[1]['action'] );
		$this->assertFalse( $log[1]['success'] );
		$this->assertStringContainsString( 'looks fake', $log[1]['message'] );
	}

	public function test_captcha_failure_blocks_submission() {
		launch_forms()->settings->update( array( 'turnstile_site_key' => 'site', 'turnstile_secret_key' => 'secret' ) );
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'success' => false ) );
			}
		);
		$form   = $this->contact_form( array( 'settings' => array( 'captcha' => 'turnstile' ) ) );
		$result = $this->submit( $form, array( 'name' => 'A', 'email' => 'a@example.com' ), array(), array( 'cf-turnstile-response' => 'token' ) );
		$this->assertFalse( $result->success );
		$this->assertSame( 'Please complete the spam check and try again.', $result->message );
		$this->assertSame( 'A', $result->values['name'] );
		$this->assertCount( 0, $this->submissions_for( $form ) );
	}
}
