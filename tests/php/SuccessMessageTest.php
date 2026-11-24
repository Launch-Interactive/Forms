<?php
/**
 * Merge tags in the success message.
 *
 * @covers \LaunchInteractiveForms\Renderer
 * @covers \LaunchInteractiveForms\Notifications
 * @covers \LaunchInteractiveForms\Frontend
 * @covers \LaunchInteractiveForms\Rest_Api
 */

class SuccessMessageTest extends LF_TestCase {

	const MESSAGE = "Thanks for submitting our contact form. We will be in touch soon.\n\nA confirmation email was sent to {field:email}.";

	private function form( $message = self::MESSAGE, array $settings = array() ) {
		return $this->contact_form( array( 'settings' => array_merge( array( 'success_message' => $message ), $settings ) ) );
	}

	private function rest_submit( $form, array $values ) {
		$request = new WP_REST_Request( 'POST', '/liforms/v1/forms/' . $form->id . '/submit' );
		$request->set_body_params( array( 'lif' => $values ) );
		return rest_get_server()->dispatch( $request )->get_data();
	}

	public function test_ajax_response_fills_in_the_email() {
		$data = $this->rest_submit( $this->form(), array( 'name' => 'Ann', 'email' => 'ann@example.com' ) );
		$this->assertTrue( $data['success'] );
		$this->assertSame( "<p>Thanks for submitting our contact form. We will be in touch soon.</p>\n<p>A confirmation email was sent to ann@example.com.</p>\n", $data['message_html'] );
		$this->assertStringEndsWith( 'A confirmation email was sent to ann@example.com.', $data['message'] );
	}

	public function test_visitor_values_are_escaped() {
		$form = $this->form( '<strong>Hi {field:name}</strong>' );
		$data = $this->rest_submit( $form, array( 'name' => 'Ann & <3 "x"', 'email' => 'ann@example.com' ) );
		$this->assertStringContainsString( '<strong>Hi Ann &amp; &lt;3 &quot;x&quot;</strong>', $data['message_html'], 'Admin HTML kept, visitor text escaped.' );
		$this->assertStringNotContainsString( '<3', $data['message_html'] );
	}

	public function test_visitor_value_in_a_link_cannot_become_a_javascript_url() {
		$form = $this->make_form(
			array( $this->field( 'text', 'website', array( 'label' => '' ) ) ),
			array( 'settings' => array( 'success_message' => 'Thanks! <a href="{all_fields}">Your site</a> <a href="{page_url}">Back</a>' ) )
		);
		foreach ( array( 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'javascript&#58;alert(1)', 'data:text/html,x' ) as $bad ) {
			$data = $this->rest_submit( $form, array( 'website' => $bad ) );
			$this->assertTrue( $data['success'] );
			$this->assertStringNotContainsStringIgnoringCase( 'javascript', $data['message_html'], $bad );
			$this->assertStringNotContainsString( 'data:', $data['message_html'], $bad );
			$this->assertStringContainsString( '>Your site</a>', $data['message_html'] );
		}
	}

	public function test_visitor_text_that_looks_like_a_tag_is_not_expanded() {
		update_option( 'admin_email', 'secret-admin@example.org' );
		reset_phpmailer_instance(); // Ignore WordPress's own "admin email changed" notice.
		$form = $this->make_form(
			array( $this->field( 'text', 'name' ), $this->field( 'email', 'email' ) ),
			array(
				'settings'      => array( 'success_message' => 'Hello {field:name}' ),
				'notifications' => array(
					array( 'enabled' => true, 'name' => 'Reply', 'to' => '{field:email}', 'subject' => 'Hi {field:name}', 'message' => 'You said {field:name}' ),
				),
			)
		);
		$data = $this->rest_submit( $form, array( 'name' => '{admin_email} {field:email}', 'email' => 'ann@example.com' ) );
		$this->assertStringContainsString( 'Hello {admin_email} {field:email}', $data['message_html'] );
		$this->assertStringNotContainsString( 'secret-admin', $data['message_html'] );

		$email = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'You said {admin_email} {field:email}', $email->body, 'Same protection in emails.' );
		$this->assertStringNotContainsString( 'secret-admin', $email->body . $email->subject );
	}

	public function test_missing_and_unknown_field_tags_become_empty() {
		$data = $this->rest_submit( $this->form( 'Hi {field:message}{field:nope} on {site_name}.' ), array( 'name' => 'Ann', 'email' => 'ann@example.com' ) );
		$this->assertSame( "<p>Hi  on Test Blog.</p>\n", $data['message_html'] );
	}

	public function test_same_page_render_after_submission() {
		$form = $this->form();
		liforms()->processor->process( $form, array( 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
		$this->assertStringContainsString( 'A confirmation email was sent to ann@example.com.', liforms()->renderer->render( $form ) );
	}

	public function test_no_javascript_redirect_carries_the_filled_in_message() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/contact/';
		$form                      = $this->form();

		$redirect = liforms()->frontend->process_post( array( 'lif_form_id' => $form->id, 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ), array() );
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $form->id, $query['lif_sent'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $query['lif_msg'], 'Unguessable token, not the values themselves.' );
		$this->assertStringNotContainsString( 'ann@example.com', $redirect );

		// The redirected page load.
		$_SERVER['REQUEST_METHOD'] = 'GET';
		liforms()->processor->reset();
		$_GET = $query;
		$html = liforms()->renderer->render( $form );
		$this->assertStringContainsString( 'A confirmation email was sent to ann@example.com.', $html );

		// A token can't be used to show the message on a different form.
		$other = $this->form();
		$_GET  = array( 'lif_sent' => (string) $other->id, 'lif_msg' => $query['lif_msg'] );
		$this->assertStringNotContainsString( 'ann@example.com', liforms()->renderer->render( $other ) );

		// Expired or made-up tokens fall back to the message without values.
		$_GET = array( 'lif_sent' => (string) $form->id, 'lif_msg' => str_repeat( 'a', 32 ) );
		liforms()->renderer->reset();
		$this->assertStringContainsString( 'A confirmation email was sent to .', liforms()->renderer->render( $form ) );
		$_GET = array();
	}

	public function test_messages_without_tags_need_no_token() {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/contact/';
		$form                      = $this->form( 'Thanks!' );
		$redirect                  = liforms()->frontend->process_post( array( 'lif_form_id' => $form->id, 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ), array() );
		$this->assertStringNotContainsString( 'lif_msg', $redirect );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_hidden_form_on_success_shows_filled_message() {
		$form = $this->form( self::MESSAGE, array( 'hide_form_on_success' => true ) );
		liforms()->processor->process( $form, array( 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
		$html = liforms()->renderer->render( $form );
		$this->assertStringStartsWith( '<div id="lif-form-' . $form->id . '" class="lif-message lif-message--success"', $html );
		$this->assertStringContainsString( 'ann@example.com', $html );
	}
}
