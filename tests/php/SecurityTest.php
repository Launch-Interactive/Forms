<?php
/**
 * Hardening added after the security review: rate limiting, page URL checks, logged-in
 * attribution, the Site Health upload check and safe uninstall.
 *
 * @covers \LaunchForms\Processor
 * @covers \LaunchForms\Frontend
 * @covers \LaunchForms\Uploads
 * @covers \LaunchForms\Admin
 */

use LaunchForms\Frontend;
use LaunchForms\Processor;
use LaunchForms\Submissions;

class SecurityTest extends LF_TestCase {

	private function submit( $form, $ip = '203.0.113.5', array $values = array( 'name' => 'Ann', 'email' => 'ann@example.com' ), array $context = array() ) {
		return launch_forms()->processor->process( $form, array( 'lf' => $values ), array(), array_merge( array( 'ip' => $ip ), $context ) );
	}

	public function test_successful_submissions_are_rate_limited_per_ip() {
		$form = $this->contact_form();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $this->submit( $form )->success, "Submission $i should pass." );
		}
		$blocked = $this->submit( $form );
		$this->assertFalse( $blocked->success );
		$this->assertSame( 429, $blocked->status );
		$this->assertStringContainsString( 'Too many submissions', $blocked->message );
		$this->assertSame( array( 'name' => 'Ann', 'email' => 'ann@example.com', 'message' => '' ), $blocked->values, 'The visitor keeps what they typed.' );

		$this->assertTrue( $this->submit( $form, '198.51.100.7' )->success, 'Other visitors are unaffected.' );
		$this->assertCount( 11, get_posts( array( 'post_type' => Submissions::POST_TYPE, 'numberposts' => -1 ) ) );
	}

	public function test_validation_failures_do_not_use_up_the_allowance() {
		$form = $this->contact_form();
		for ( $i = 0; $i < 15; $i++ ) {
			$this->assertFalse( $this->submit( $form, '203.0.113.5', array( 'email' => 'bad' ) )->success );
		}
		$this->assertTrue( $this->submit( $form )->success );
	}

	public function test_rate_limit_is_filterable_and_can_be_disabled() {
		$form = $this->contact_form();
		add_filter(
			'launch_forms_rate_limit',
			static function () {
				return array( 'max' => 2, 'window' => 60 );
			}
		);
		$this->assertTrue( $this->submit( $form )->success );
		$this->assertTrue( $this->submit( $form )->success );
		$this->assertFalse( $this->submit( $form )->success );

		add_filter( 'launch_forms_rate_limit', '__return_false', 20 );
		$this->assertTrue( $this->submit( $form )->success );
	}

	public function test_rate_limit_does_not_store_raw_ips() {
		$this->submit( $this->contact_form() );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s", '%203.0.113.5%', '%203.0.113.5%' ) ) );
	}

	public function test_rest_returns_429_when_limited() {
		$form = $this->contact_form();
		add_filter(
			'launch_forms_rate_limit',
			static function () {
				return array( 'max' => 1, 'window' => 60 );
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$server                 = rest_get_server();
		$send                   = static function () use ( $server, $form ) {
			$request = new WP_REST_Request( 'POST', '/launch-forms/v1/forms/' . $form->id . '/submit' );
			$request->set_body_params( array( 'lf' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
			return $server->dispatch( $request );
		};
		$this->assertSame( 200, $send()->get_status() );
		$this->assertSame( 429, $send()->get_status() );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_page_url_from_another_site_is_not_stored() {
		$form   = $this->contact_form();
		$result = $this->submit( $form, '203.0.113.5', array( 'name' => 'Ann', 'email' => 'ann@example.com' ), array( 'page_url' => 'https://evil.example.net/phish' ) );
		$this->assertSame( '', get_post_meta( $result->submission_id, Submissions::META_PAGE, true ) );

		$this->assertSame( 'http://example.org/contact/', Processor::same_site_url( 'http://example.org/contact/' ) );
		$this->assertSame( '', Processor::same_site_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Processor::same_site_url( 'https://example.org.evil.net/' ) );
	}

	public function test_plain_post_only_credits_a_user_with_a_nonce() {
		$form = $this->contact_form();
		$this->assertSame( 0, Frontend::verified_user_id( $form->id, array() ), 'Logged out.' );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$this->assertSame( 0, Frontend::verified_user_id( $form->id, array() ), 'A cross-site POST carries no nonce.' );
		$this->assertSame( 0, Frontend::verified_user_id( $form->id, array( 'lf_nonce' => wp_create_nonce( 'launch_forms_submit_999' ) ) ), 'Nonce for another form.' );
		$this->assertSame( $user, Frontend::verified_user_id( $form->id, array( 'lf_nonce' => wp_create_nonce( 'launch_forms_submit_' . $form->id ) ) ) );

		$html = launch_forms()->renderer->render( $form );
		$this->assertStringContainsString( 'name="lf_nonce"', $html );
		wp_set_current_user( 0 );
		launch_forms()->renderer->reset();
		$this->assertStringNotContainsString( 'lf_nonce', launch_forms()->renderer->render( $form ), 'Logged-out pages stay cacheable.' );
	}

	/**
	 * Serve the private folder over "HTTP" by reading the requested file, or return a status.
	 *
	 * @param int $code Status to return when not serving the file.
	 */
	private function fake_web_server( $code ) {
		$dir = $this->private_dir;
		$this->mock_http(
			static function ( $url ) use ( $dir, $code ) {
				if ( 200 === $code ) {
					$path = trailingslashit( $dir ) . basename( wp_parse_url( $url, PHP_URL_PATH ) );
					return array(
						'code' => 200,
						'body' => file_exists( $path ) ? file_get_contents( $path ) : 'not found',
					);
				}
				return array(
					'code' => $code,
					'body' => 'Forbidden',
				);
			}
		);
	}

	public function test_site_health_reports_exposed_uploads() {
		$uploads = wp_upload_dir( null, false );
		$dir     = $uploads['basedir'] . '/launch-forms-private-test-' . wp_generate_password( 6, false );
		add_filter(
			'launch_forms_private_dir',
			static function () use ( $dir ) {
				return $dir;
			},
			20
		);
		$this->private_dir = $dir;

		$this->fake_web_server( 200 );
		$result = launch_forms()->admin->site_health_private_uploads();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'deny all', $result['description'] );
		$this->assertStringStartsWith( $uploads['baseurl'] . '/launch-forms-private-test-', $this->http_requests[0]['url'] );
		$this->assertSame( array( '.htaccess', 'index.php', 'web.config' ), array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ), 'The probe file is removed.' );
	}

	public function test_site_health_passes_when_server_blocks_uploads() {
		$uploads = wp_upload_dir( null, false );
		$dir     = $uploads['basedir'] . '/launch-forms-private-test-' . wp_generate_password( 6, false );
		add_filter(
			'launch_forms_private_dir',
			static function () use ( $dir ) {
				return $dir;
			},
			20
		);
		$this->private_dir = $dir;

		$this->fake_web_server( 403 );
		$this->assertSame( 'good', launch_forms()->admin->site_health_private_uploads()['status'] );
	}

	public function test_site_health_passes_when_uploads_are_outside_the_web_root() {
		// The base test case already points the private folder at the system temp dir.
		$this->mock_http(
			static function () {
				return array( 'code' => 200, 'body' => '' );
			}
		);
		$this->assertSame( 'outside', launch_forms()->uploads->public_access_status() );
		$this->assertSame( 'good', launch_forms()->admin->site_health_private_uploads()['status'] );
		$this->assertSame( array(), $this->http_requests, 'No request needed.' );
	}

	public function test_site_health_test_is_registered() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		$this->assertArrayHasKey( 'launch_forms_private_uploads', $tests['direct'] );
	}

	public function test_uninstall_only_deletes_files_the_plugin_created() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'launch-forms-private/';
		$created = ! is_dir( $dir );
		wp_mkdir_p( $dir );
		launch_forms()->uploads->protect_dir( $dir );
		$ours    = $dir . str_repeat( 'a', 32 );
		$foreign = $dir . 'keep-me.txt';
		file_put_contents( $ours, 'x' );
		file_put_contents( $foreign, 'not ours' );

		update_option( 'launch_forms_settings', array( 'delete_data_on_uninstall' => true ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'launch-forms/launch-forms.php' );
		}
		include LAUNCH_FORMS_DIR . 'uninstall.php';

		$this->assertFileDoesNotExist( $ours );
		$this->assertFileDoesNotExist( $dir . '.htaccess' );
		$this->assertFileExists( $foreign, 'Files the plugin did not write are left alone.' );
		$this->assertFalse( get_option( 'launch_forms_settings' ) );

		unlink( $foreign );
		if ( $created ) {
			rmdir( $dir );
		}
	}

	private function config_with( $notification_to, $list_id ) {
		return array(
			'fields'        => array( $this->field( 'email', 'email' ) ),
			'notifications' => array(
				array(
					'enabled' => true,
					'name'    => 'Admin',
					'to'      => $notification_to,
					'subject' => 'New',
					'message' => '{all_fields}',
					'attach_files' => true,
				),
			),
			'mailchimp'     => array( 'enabled' => true, 'list_id' => $list_id, 'email_field' => 'email' ),
		);
	}

	private function stored_config( $form_id ) {
		return LaunchForms\Form_Config::from_json( get_post_meta( $form_id, LaunchForms\Forms::META_KEY, true ) );
	}

	public function test_editors_cannot_change_where_submissions_are_sent() {
		$form = $this->make_form( array( $this->field( 'email', 'email' ) ), array_diff_key( $this->config_with( 'boss@example.org', 'list1' ), array( 'fields' => 1 ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $form->id ), 'Editors can still edit forms.' );

		$changed             = $this->config_with( 'editor@evil.example', 'list2' );
		$changed['fields'][] = $this->field( 'text', 'company' );
		launch_forms()->forms->save_config( $form->id, $changed );

		$stored = $this->stored_config( $form->id );
		$this->assertSame( array( 'email', 'company' ), wp_list_pluck( $stored['fields'], 'name' ), 'Field changes are saved.' );
		$this->assertSame( 'boss@example.org', $stored['notifications'][0]['to'], 'Recipients are kept.' );
		$this->assertSame( 'list1', $stored['mailchimp']['list_id'], 'Mailchimp settings are kept.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		launch_forms()->forms->save_config( $form->id, $changed );
		$stored = $this->stored_config( $form->id );
		$this->assertSame( 'editor@evil.example', $stored['notifications'][0]['to'], 'Admins can change recipients.' );
		$this->assertSame( 'list2', $stored['mailchimp']['list_id'] );
	}

	public function test_editors_cannot_change_recipients_through_the_rest_api() {
		$form = $this->make_form( array( $this->field( 'email', 'email' ) ), array_diff_key( $this->config_with( 'boss@example.org', 'list1' ), array( 'fields' => 1 ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$changed             = $this->config_with( 'editor@evil.example', 'list2' );
		$changed['fields'][] = $this->field( 'text', 'company' );
		$request             = new WP_REST_Request( 'POST', '/wp/v2/launch-forms/' . $form->id );
		$request->set_body_params( array( 'meta' => array( LaunchForms\Forms::META_KEY => wp_json_encode( $changed ) ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The save itself succeeds (as in the block editor).' );
		$stored = $this->stored_config( $form->id );
		$this->assertCount( 2, $stored['fields'] );
		$this->assertSame( 'boss@example.org', $stored['notifications'][0]['to'] );
		$this->assertSame( 'list1', $stored['mailchimp']['list_id'] );
	}

	public function test_new_forms_by_editors_get_default_notifications() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id     = launch_forms()->forms->create( 'Editor form', $this->config_with( 'editor@evil.example', 'list2' ) );
		$stored = $this->stored_config( $id );
		$this->assertSame( '{admin_email}', $stored['notifications'][0]['to'] );
		$this->assertFalse( (bool) $stored['mailchimp']['enabled'] );
		$this->assertCount( 1, $stored['fields'] );
	}

	public function test_builder_knows_whether_to_show_email_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( launch_forms()->admin->builder_data()['canEditActions'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( launch_forms()->admin->builder_data()['canEditActions'] );
	}
}
