<?php
/**
 * @covers \LaunchForms\Mailchimp
 */

use LaunchForms\Mailchimp;

class MailchimpTest extends LF_TestCase {

	private function form( array $mc = array() ) {
		return $this->make_form(
			array(
				$this->field( 'email', 'email' ),
				$this->field( 'text', 'first' ),
				$this->field( 'checkbox', 'interests', array( 'options' => "A\nB" ) ),
				$this->field( 'consent', 'newsletter' ),
			),
			array(
				'mailchimp' => array_merge(
					array(
						'enabled'      => true,
						'list_id'      => 'abc123',
						'email_field'  => 'email',
						'double_optin' => true,
						'merge_fields' => array(
							array( 'tag' => 'FNAME', 'field' => 'first' ),
							array( 'tag' => 'INTERESTS', 'field' => 'interests' ),
						),
					),
					$mc
				),
			)
		);
	}

	private function ok() {
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'id' => 'x' ) );
			}
		);
	}

	public function test_datacenter_parsing() {
		$this->assertSame( 'us21', ( new Mailchimp( '0123456789abcdef-us21' ) )->datacenter() );
		$this->assertSame( '', ( new Mailchimp( 'nodash' ) )->datacenter() );
		$this->assertSame( '', ( new Mailchimp( 'abc-us21.evil.com/x' ) )->datacenter(), 'Key must not be able to redirect requests to another host.' );
		$this->assertSame( 'https://us21.api.mailchimp.com/3.0/', ( new Mailchimp( 'k-us21' ) )->base_url() );
	}

	public function test_subscribe_request() {
		$this->ok();
		$result = ( new Mailchimp( 'secretkey-us5' ) )->subscribe( $this->form(), array( 'email' => 'Ann@Example.com', 'first' => 'Ann', 'interests' => array( 'A', 'B' ) ) );
		$this->assertTrue( $result );
		$this->assertCount( 1, $this->http_requests );
		$req = $this->http_requests[0];
		$this->assertSame( 'https://us5.api.mailchimp.com/3.0/lists/abc123/members/' . md5( 'ann@example.com' ), $req['url'] );
		$this->assertSame( 'PUT', $req['args']['method'] );
		$this->assertSame( 'Basic ' . base64_encode( 'launchforms:secretkey-us5' ), $req['args']['headers']['Authorization'] );
		$body = json_decode( $req['args']['body'], true );
		$this->assertSame( 'Ann@Example.com', $body['email_address'] );
		$this->assertSame( 'pending', $body['status_if_new'] );
		$this->assertSame( array( 'FNAME' => 'Ann', 'INTERESTS' => 'A, B' ), $body['merge_fields'] );
	}

	public function test_single_opt_in_and_tags() {
		$this->ok();
		( new Mailchimp( 'k-us5' ) )->subscribe( $this->form( array( 'double_optin' => false, 'tags' => 'Website, Lead ' ) ), array( 'email' => 'a@example.com' ) );
		$this->assertSame( 'subscribed', json_decode( $this->http_requests[0]['args']['body'], true )['status_if_new'] );
		$this->assertStringEndsWith( '/tags', $this->http_requests[1]['url'] );
		$this->assertSame(
			array( array( 'name' => 'Website', 'status' => 'active' ), array( 'name' => 'Lead', 'status' => 'active' ) ),
			json_decode( $this->http_requests[1]['args']['body'], true )['tags']
		);
	}

	public function test_consent_field_controls_subscription() {
		$this->ok();
		$form = $this->form( array( 'consent_field' => 'newsletter' ) );
		$this->assertNull( ( new Mailchimp( 'k-us5' ) )->subscribe( $form, array( 'email' => 'a@example.com', 'newsletter' => '' ) ) );
		$this->assertSame( array(), $this->http_requests );
		$this->assertTrue( ( new Mailchimp( 'k-us5' ) )->subscribe( $form, array( 'email' => 'a@example.com', 'newsletter' => '1' ) ) );
	}

	public function test_deleted_consent_field_subscribes_nobody() {
		$this->ok();
		$form = $this->form( array( 'consent_field' => 'removed' ) );
		$this->assertNull( ( new Mailchimp( 'k-us5' ) )->subscribe( $form, array( 'email' => 'a@example.com' ) ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_skipped_or_errors() {
		$this->assertNull( ( new Mailchimp( 'k-us5' ) )->subscribe( $this->form( array( 'enabled' => false ) ), array( 'email' => 'a@example.com' ) ) );
		$this->assertSame( 'lf_mailchimp_key', ( new Mailchimp( '' ) )->subscribe( $this->form(), array( 'email' => 'a@example.com' ) )->get_error_code() );
		$this->assertSame( 'lf_mailchimp_list', ( new Mailchimp( 'k-us5' ) )->subscribe( $this->form( array( 'list_id' => '' ) ), array( 'email' => 'a@example.com' ) )->get_error_code() );
		$this->assertSame( 'lf_mailchimp_email', ( new Mailchimp( 'k-us5' ) )->subscribe( $this->form(), array( 'email' => 'nope' ) )->get_error_code() );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_api_error_surfaces_detail() {
		$this->mock_http(
			static function () {
				return array(
					'code' => 400,
					'body' => array( 'title' => 'Member Exists', 'detail' => 'is already a list member' ),
				);
			}
		);
		$result = ( new Mailchimp( 'k-us5' ) )->subscribe( $this->form(), array( 'email' => 'a@example.com' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'Mailchimp error 400: is already a list member', $result->get_error_message() );
	}

	public function test_get_lists() {
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'lists' => array( array( 'id' => 'l1', 'name' => 'Newsletter' ) ) ) );
			}
		);
		$this->assertSame( array( array( 'id' => 'l1', 'name' => 'Newsletter' ) ), ( new Mailchimp( 'k-us5' ) )->get_lists() );
		$this->assertStringContainsString( '/3.0/lists?', $this->http_requests[0]['url'] );
		$this->assertWPError( ( new Mailchimp( '' ) )->get_lists() );
	}

	public function test_member_payload_filter() {
		$this->ok();
		add_filter(
			'launch_forms_mailchimp_member',
			static function ( $body ) {
				$body['language'] = 'en';
				return $body;
			}
		);
		( new Mailchimp( 'k-us5' ) )->subscribe( $this->form(), array( 'email' => 'a@example.com' ) );
		$this->assertSame( 'en', json_decode( $this->http_requests[0]['args']['body'], true )['language'] );
	}
}
