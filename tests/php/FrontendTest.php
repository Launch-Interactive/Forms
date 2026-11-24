<?php
/**
 * No-JavaScript submission path.
 *
 * @covers \LaunchInteractiveForms\Frontend
 */

class FrontendTest extends LF_TestCase {

	public function set_up() {
		parent::set_up();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_HOST']      = 'example.org';
		$_SERVER['REQUEST_URI']    = '/contact/?lif_sent=99&x=1';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.9';
	}

	public function tear_down() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	public function test_success_redirects_back_with_flag_and_anchor() {
		$form     = $this->contact_form();
		$redirect = liforms()->frontend->process_post(
			array(
				'lif_form_id' => (string) $form->id,
				'lif'         => array( 'name' => 'O\\\'Brien', 'email' => 'ob@example.com' ),
			),
			array()
		);
		$this->assertSame( 'http://example.org/contact/?x=1&lif_sent=' . $form->id . '#lif-form-' . $form->id, $redirect );
		$sub     = get_posts( array( 'post_type' => 'liforms_entry', 'post_parent' => $form->id ) );
		$entries = liforms()->submissions->get_entries( $sub[0]->ID );
		$this->assertSame( "O'Brien", $entries[0]['value'], 'POST data is unslashed once.' );
		$this->assertSame( 'http://example.org/contact/?x=1', get_post_meta( $sub[0]->ID, '_liforms_page_url', true ) );
	}

	public function test_custom_redirect_allows_configured_external_host() {
		$form     = $this->contact_form( array( 'settings' => array( 'redirect_url' => 'https://thanks.example.net/done' ) ) );
		$redirect = liforms()->frontend->process_post(
			array(
				'lif_form_id' => $form->id,
				'lif'         => array( 'name' => 'A', 'email' => 'a@example.com' ),
			),
			array()
		);
		$this->assertSame( 'https://thanks.example.net/done', $redirect );
		$this->assertSame( 'https://thanks.example.net/done', wp_validate_redirect( $redirect ) );
	}

	public function test_errors_render_inline_in_same_request() {
		$form = $this->contact_form();
		$this->assertNull( liforms()->frontend->process_post( array( 'lif_form_id' => $form->id, 'lif' => array( 'email' => 'bad' ) ), array() ) );
		$html = liforms()->renderer->render( $form );
		$this->assertStringContainsString( 'lif-message--error', $html );
		$this->assertStringContainsString( 'value="bad"', $html );
	}

	public function test_ignores_non_form_requests() {
		$this->assertNull( liforms()->frontend->process_post( array( 'foo' => 'bar' ), array() ) );
		$this->assertNull( liforms()->frontend->process_post( array( 'lif_form_id' => 999999 ), array() ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$form                      = $this->contact_form();
		$this->assertNull( liforms()->frontend->process_post( array( 'lif_form_id' => $form->id, 'lif' => array( 'name' => 'A', 'email' => 'a@example.com' ) ), array() ) );
		$this->assertCount( 0, $this->sent_emails() );
	}

	public function test_php_files_array_is_used() {
		$form  = $this->make_form( array( $this->field( 'file', 'doc', array( 'allowed_types' => 'txt', 'required' => true ) ) ) );
		$file  = $this->fake_upload( 'notes.txt', 'hello' );
		$files = array(
			'lif' => array(
				'name'     => array( 'doc' => 'notes.txt' ),
				'type'     => array( 'doc' => 'text/plain' ),
				'tmp_name' => array( 'doc' => $file['tmp_name'] ),
				'error'    => array( 'doc' => 0 ),
				'size'     => array( 'doc' => 5 ),
			),
		);
		$this->assertNotNull( liforms()->frontend->process_post( array( 'lif_form_id' => $form->id ), $files ) );
	}

	/**
	 * Regression: on wp_loaded REST_REQUEST is not yet defined, so the fallback handler used to
	 * swallow AJAX submissions sent to the REST endpoint.
	 */
	public function test_fallback_runs_on_template_redirect_not_before_rest_dispatch() {
		$this->assertFalse( has_action( 'wp_loaded', array( liforms()->frontend, 'handle_post' ) ) );
		$this->assertFalse( has_action( 'init', array( liforms()->frontend, 'handle_post' ) ) );
		$this->assertNotFalse( has_action( 'template_redirect', array( liforms()->frontend, 'handle_post' ) ) );
	}

	public function test_client_ip_validation() {
		$this->assertSame( '203.0.113.9', LaunchInteractiveForms\Frontend::client_ip() );
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( '', LaunchInteractiveForms\Frontend::client_ip() );
	}

	public function test_logged_in_users_get_rest_nonce_for_ajax() {
		wp_set_current_user( self::factory()->user->create() );
		liforms()->renderer->render( $this->contact_form() );
		$before = implode( '', (array) wp_scripts()->get_data( 'liforms', 'before' ) );
		$this->assertStringContainsString( 'liformsConfig.nonce', $before );
	}

	public function test_localized_rest_url() {
		$data = wp_scripts()->get_data( 'liforms', 'data' );
		$this->assertStringContainsString( 'liforms/v1/forms/', $data );
	}
}
