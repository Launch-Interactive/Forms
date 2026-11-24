<?php
/**
 * @covers \LaunchInteractiveForms\Notifications
 */

class NotificationsTest extends LF_TestCase {

	private function entries() {
		return array(
			array( 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ann Lee' ),
			array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => 'ann@example.com' ),
			array( 'name' => 'topics', 'label' => 'Topics', 'type' => 'checkbox', 'value' => array( 'News', 'Offers' ) ),
			array( 'name' => 'agree', 'label' => 'Agree', 'type' => 'consent', 'value' => '1' ),
			array( 'name' => 'msg', 'label' => 'Message', 'type' => 'textarea', 'value' => "Line 1\nLine 2" ),
		);
	}

	private function notification( array $overrides ) {
		return array_merge(
			array(
				'enabled'      => true,
				'name'         => 'Test',
				'to'           => 'owner@example.com',
				'subject'      => 'New',
				'message'      => '{all_fields}',
				'reply_to'     => '',
				'from_name'    => '',
				'from_email'   => '',
				'attach_files' => false,
			),
			$overrides
		);
	}

	public function test_merge_tags() {
		$form = $this->contact_form();
		$n    = liforms()->notifications;
		$out  = $n->replace_tags(
			'{field:name} <{field:email}> [{field:topics}] {field:missing}|{form_title}|{form_id}|{site_name}|{admin_email}|{submission_id}|{page_url}',
			$form,
			$this->entries(),
			array( 'submission_id' => 42, 'page_url' => 'https://example.org/p/' )
		);
		$this->assertSame( 'Ann Lee <ann@example.com> [News, Offers] |Contact|' . $form->id . '|Test Blog|admin@example.org|42|https://example.org/p/', $out );
		$this->assertNotSame( '{date}', $n->replace_tags( '{date}', $form, array() ) );
	}

	public function test_all_fields_format() {
		$text = liforms()->notifications->all_fields( $this->entries() );
		$this->assertSame( "Name: Ann Lee\nEmail: ann@example.com\nTopics: News, Offers\nAgree: Yes\nMessage: \nLine 1\nLine 2", $text );
	}

	public function test_sends_with_headers() {
		$form   = $this->contact_form();
		$result = liforms()->notifications->send(
			$this->notification(
				array(
					'to'         => 'a@example.com, {field:email}; not-an-email',
					'subject'    => 'From {field:name}',
					'reply_to'   => '{field:email}',
					'from_name'  => 'Site <Forms>',
					'from_email' => 'forms@example.org',
				)
			),
			$form,
			$this->entries()
		);
		$this->assertTrue( $result );
		$mail = $this->sent_emails()[0];
		$this->assertSame( array( 'a@example.com', 'ann@example.com' ), array_column( $mail['to'], 0 ) );
		$this->assertSame( 'From Ann Lee', $mail['subject'] );
		$this->assertStringContainsString( 'Reply-To: ann@example.com', $mail['header'] );
		$this->assertStringContainsString( 'From: Site Forms <forms@example.org>', $mail['header'] );
		$this->assertStringContainsString( 'text/plain', $mail['header'] );
	}

	public function test_header_injection_is_neutralised() {
		$form    = $this->contact_form();
		$entries = $this->entries();
		// Values are sanitised on input, but defend in depth against raw newlines too.
		$entries[0]['value'] = "Evil\r\nBcc: victim@example.com";
		$entries[1]['value'] = "x@example.com\r\nBcc: victim@example.com";
		liforms()->notifications->send( $this->notification( array( 'subject' => 'Hi {field:name}', 'reply_to' => '{field:email}' ) ), $form, $entries );
		$mail = $this->sent_emails()[0];
		$this->assertSame( array(), $mail['bcc'] );
		$this->assertSame( array( 'owner@example.com' ), array_column( $mail['to'], 0 ) );
		$this->assertDoesNotMatchRegularExpression( '/^Bcc:/mi', $mail['header'], 'No injected header lines.' );
		$this->assertStringNotContainsString( "\n", $mail['subject'] );
	}

	public function test_no_valid_recipient_returns_error() {
		$result = liforms()->notifications->send( $this->notification( array( 'to' => '{field:missing}' ) ), $this->contact_form(), $this->entries() );
		$this->assertWPError( $result );
		$this->assertCount( 0, $this->sent_emails() );
	}

	public function test_send_all_skips_disabled_and_logs() {
		$form = $this->contact_form(
			array(
				'notifications' => array(
					$this->notification( array( 'name' => 'Admin' ) ),
					$this->notification( array( 'name' => 'Off', 'enabled' => false ) ),
					$this->notification( array( 'name' => 'Autoresponder', 'to' => '{field:email}', 'message' => 'Thanks {field:name}' ) ),
				),
			)
		);
		$log = liforms()->notifications->send_all( $form, $this->entries() );
		$this->assertSame( array( 'Admin', 'Autoresponder' ), wp_list_pluck( $log, 'name' ) );
		$this->assertCount( 2, $this->sent_emails() );
		$this->assertStringContainsString( 'Thanks Ann Lee', $this->sent_emails()[1]['body'] );
	}

	public function test_mail_failure_reason_is_captured() {
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );
				return false;
			}
		);
		$result = liforms()->notifications->send( $this->notification( array() ), $this->contact_form(), $this->entries() );
		$this->assertWPError( $result );
		$this->assertSame( 'SMTP connect() failed.', $result->get_error_message() );
	}

	public function test_email_filter() {
		add_filter(
			'liforms_notification_email',
			static function ( $email ) {
				$email['subject'] = 'Filtered';
				return $email;
			}
		);
		liforms()->notifications->send( $this->notification( array() ), $this->contact_form(), $this->entries() );
		$this->assertSame( 'Filtered', $this->sent_emails()[0]['subject'] );
	}
}
