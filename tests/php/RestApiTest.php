<?php
/**
 * @covers \LaunchForms\Rest_Api
 * @covers \LaunchForms\Forms_Rest_Controller
 */

use LaunchForms\Forms;

class RestApiTest extends LF_TestCase {

	/** @var WP_REST_Server */
	private $server;

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	private function submit( $form_id, array $body, array $files = array() ) {
		$request = new WP_REST_Request( 'POST', '/launch-forms/v1/forms/' . $form_id . '/submit' );
		$request->set_body_params( $body );
		$request->set_file_params( $files );
		$request->set_header( 'referer', 'https://example.org/contact/' );
		return $this->server->dispatch( $request );
	}

	public function test_routes_registered() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/launch-forms/v1/forms/(?P<id>\d+)/submit', $routes );
		$this->assertArrayHasKey( '/launch-forms/v1/mailchimp/lists', $routes );
	}

	public function test_successful_submit() {
		$form     = $this->contact_form( array( 'settings' => array( 'success_message' => '<p>Thanks <em>pal</em></p>' ) ) );
		$response = $this->submit( $form->id, array( 'lf' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Thanks pal', $data['message'] );
		$this->assertStringContainsString( '<em>pal</em>', $data['message_html'] );
		$this->assertTrue( $data['hide_form'] );
		$this->assertSame( '', $data['redirect'] );
		$this->assertCount( 1, $this->sent_emails() );
		$sub = get_posts( array( 'post_type' => 'launch_forms_entry', 'post_parent' => $form->id ) );
		$this->assertSame( 'https://example.org/contact/', get_post_meta( $sub[0]->ID, '_launch_forms_page_url', true ) );
	}

	public function test_validation_errors_return_422() {
		$form     = $this->contact_form();
		$response = $this->submit( $form->id, array( 'lf' => array( 'email' => 'nope' ) ) );
		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$errors = (array) $data['errors'];
		$this->assertSame( array( 'name', 'email' ), array_keys( $errors ) );
		$this->assertSame( '', $data['message_html'] );
	}

	public function test_unknown_or_unpublished_form_404() {
		$this->assertSame( 404, $this->submit( 999999, array() )->get_status() );
		$draft = self::factory()->post->create( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'draft' ) );
		$this->assertSame( 404, $this->submit( $draft, array() )->get_status() );
	}

	public function test_submit_with_file_upload() {
		$form  = $this->make_form( array( $this->field( 'file', 'photo', array( 'allowed_types' => 'png', 'required' => true ) ) ) );
		$file  = $this->fake_upload( 'me.png', $this->png_bytes() );
		$files = array(
			'lf' => array(
				'name'     => array( 'photo' => $file['name'] ),
				'type'     => array( 'photo' => 'image/png' ),
				'tmp_name' => array( 'photo' => $file['tmp_name'] ),
				'error'    => array( 'photo' => 0 ),
				'size'     => array( 'photo' => $file['size'] ),
			),
		);
		$response = $this->submit( $form->id, array( 'lf' => array() ), $files );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}

	private function preview( $form_id, $config, $class = '' ) {
		$request = new WP_REST_Request( 'POST', '/launch-forms/v1/forms/' . $form_id . '/preview' );
		$request->set_body_params( array( 'config' => is_string( $config ) ? $config : wp_json_encode( $config ), 'className' => $class ) );
		return $this->server->dispatch( $request );
	}

	public function test_block_preview_renders_unsaved_changes_without_saving() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$form   = $this->contact_form();
		$config = array(
			'fields' => array_merge( $form->fields, array( $this->field( 'text', 'company', array( 'label' => 'Company' ) ) ) ),
		);

		$response = $this->preview( $form->id, $config, 'is-style-wide bad"class' );
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];
		$this->assertStringContainsString( 'name="lf[company]"', $html, 'The field added in the editor shows.' );
		$this->assertStringContainsString( 'class="lf-form wp-block-launch-forms-form is-style-wide badclass"', $html );

		$saved = launch_forms()->forms->get( $form->id );
		$this->assertNull( $saved->get_field( 'company' ), 'Nothing is saved.' );
		$this->assertStringNotContainsString( 'lf[company]', launch_forms()->renderer->render( $saved ) );
	}

	public function test_block_preview_sanitises_the_config() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$form = $this->contact_form();
		$html = $this->preview( $form->id, array( 'fields' => array( array( 'type' => 'content', 'content' => '<p>Hi</p><script>alert(1)</script>' ) ) ) )->get_data()['html'];
		$this->assertStringContainsString( '<p>Hi</p>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( 200, $this->preview( $form->id, 'not json' )->get_status(), 'Bad JSON gives an empty form, not an error.' );
	}

	public function test_block_preview_requires_permission_to_edit_the_form() {
		$form = $this->contact_form();
		$this->assertSame( 401, $this->preview( $form->id, array() )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->preview( $form->id, array() )->get_status(), 'Authors cannot edit forms.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 403, $this->preview( $page, array() )->get_status(), 'Only forms can be previewed.' );
		$this->assertSame( 403, $this->preview( 999999, array() )->get_status() );
	}

	public function test_mailchimp_lists_requires_permission() {
		$request = new WP_REST_Request( 'GET', '/launch-forms/v1/mailchimp/lists' );
		$this->assertSame( 401, $this->server->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->server->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		launch_forms()->settings->update( array( 'mailchimp_api_key' => 'k-us1' ) );
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'lists' => array( array( 'id' => 'a1', 'name' => 'Main' ) ) ) );
			}
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( array( 'id' => 'a1', 'name' => 'Main' ) ), $response->get_data() );
	}

	public function test_mailchimp_lists_error_is_400() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/launch-forms/v1/mailchimp/lists' ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Form configs contain notification addresses and must not be public via /wp/v2.
	 */
	public function test_form_definitions_are_not_publicly_readable() {
		$form = $this->contact_form();
		$this->assertSame( 401, $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/launch-forms' ) )->get_status() );
		$this->assertSame( 401, $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/launch-forms/' . $form->id ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->server->dispatch( new WP_REST_Request( 'GET', '/wp/v2/launch-forms/' . $form->id ) )->get_status() );
	}

	/**
	 * The block editor builds forms through the REST API (core-data).
	 */
	public function test_editors_can_create_and_update_forms_via_rest() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/launch-forms' );
		$request->set_body_params(
			array(
				'title'  => 'Built in Gutenberg',
				'status' => 'publish',
				'meta'   => array( Forms::META_KEY => wp_json_encode( array( 'fields' => array( array( 'type' => 'email', 'label' => 'Email "address"' ) ) ) ) ),
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$id   = $response->get_data()['id'];
		$form = launch_forms()->forms->get( $id );
		$this->assertSame( 'email_address', $form->fields[0]['name'] );
		$this->assertSame( 'Email "address"', $form->fields[0]['label'] );

		$get  = new WP_REST_Request( 'GET', '/wp/v2/launch-forms/' . $id );
		$get->set_param( 'context', 'edit' );
		$data = $this->server->dispatch( $get )->get_data();
		$this->assertArrayHasKey( Forms::META_KEY, $data['meta'] );
		$this->assertSame( 'email_address', json_decode( $data['meta'][ Forms::META_KEY ], true )['fields'][0]['name'] );

		$update = new WP_REST_Request( 'POST', '/wp/v2/launch-forms/' . $id );
		$update->set_body_params( array( 'meta' => array( Forms::META_KEY => '{"fields":[{"type":"evil"}],"settings":{"submit_label":"Send it"}}' ) ) );
		$this->assertSame( 200, $this->server->dispatch( $update )->get_status() );
		$form = launch_forms()->forms->get( $id );
		$this->assertSame( array(), $form->fields, 'Config is sanitised on REST writes too.' );
		$this->assertSame( 'Send it', $form->settings['submit_label'] );
	}

	public function test_authors_cannot_edit_forms_via_rest() {
		$form = $this->contact_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$update = new WP_REST_Request( 'POST', '/wp/v2/launch-forms/' . $form->id );
		$update->set_body_params( array( 'meta' => array( Forms::META_KEY => '{}' ) ) );
		$this->assertContains( $this->server->dispatch( $update )->get_status(), array( 401, 403 ) );
	}
}
