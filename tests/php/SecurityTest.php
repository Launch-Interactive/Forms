<?php
/**
 * Hardening added after the security review: rate limiting, page URL checks, logged-in
 * attribution, the Site Health upload check and safe uninstall.
 *
 * @covers \LaunchInteractiveForms\Processor
 * @covers \LaunchInteractiveForms\Frontend
 * @covers \LaunchInteractiveForms\Uploads
 * @covers \LaunchInteractiveForms\Admin
 */

use LaunchInteractiveForms\Frontend;
use LaunchInteractiveForms\Processor;
use LaunchInteractiveForms\Submissions;

class SecurityTest extends LF_TestCase {

	private function submit( $form, $ip = '203.0.113.5', array $values = array( 'name' => 'Ann', 'email' => 'ann@example.com' ), array $context = array() ) {
		return liforms()->processor->process( $form, array( 'lif' => $values ), array(), array_merge( array( 'ip' => $ip ), $context ) );
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
			'liforms_rate_limit',
			static function () {
				return array( 'max' => 2, 'window' => 60 );
			}
		);
		$this->assertTrue( $this->submit( $form )->success );
		$this->assertTrue( $this->submit( $form )->success );
		$this->assertFalse( $this->submit( $form )->success );

		add_filter( 'liforms_rate_limit', '__return_false', 20 );
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
			'liforms_rate_limit',
			static function () {
				return array( 'max' => 1, 'window' => 60 );
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$server                 = rest_get_server();
		$send                   = static function () use ( $server, $form ) {
			$request = new WP_REST_Request( 'POST', '/liforms/v1/forms/' . $form->id . '/submit' );
			$request->set_body_params( array( 'lif' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ) ) );
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
		$this->assertSame( 0, Frontend::verified_user_id( $form->id, array( 'lif_nonce' => wp_create_nonce( 'liforms_submit_999' ) ) ), 'Nonce for another form.' );
		$this->assertSame( $user, Frontend::verified_user_id( $form->id, array( 'lif_nonce' => wp_create_nonce( 'liforms_submit_' . $form->id ) ) ) );

		$html = liforms()->renderer->render( $form );
		$this->assertStringContainsString( 'name="lif_nonce"', $html );
		wp_set_current_user( 0 );
		liforms()->renderer->reset();
		$this->assertStringNotContainsString( 'lif_nonce', liforms()->renderer->render( $form ), 'Logged-out pages stay cacheable.' );
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
		$this->fake_web_server( 200 );
		$result = liforms()->admin->site_health_private_uploads();
		$this->assertSame( 'critical', $result['status'] );
		$folder = basename( $this->private_dir );
		$this->assertStringContainsString( 'location ^~ ' . wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) . '/' . $folder . '/ { deny all; }', $result['description'] );
		$this->assertStringNotContainsString( 'LIFORMS_PRIVATE_DIR', $result['description'], 'Files are never stored outside uploads.' );
		$this->assertStringStartsWith( $uploads['baseurl'] . '/' . $folder . '/', $this->http_requests[0]['url'] );
		$this->assertSame( array( '.htaccess', 'index.php', 'web.config' ), array_values( array_diff( scandir( $this->private_dir ), array( '.', '..' ) ) ), 'The probe file is removed.' );
	}

	public function test_site_health_passes_when_server_blocks_uploads() {
		$this->fake_web_server( 403 );
		$this->assertSame( 'good', liforms()->admin->site_health_private_uploads()['status'] );
	}

	public function test_private_folder_always_stays_in_uploads() {
		$base = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		foreach ( array( '../../etc', '/var/private', '.hidden', '' ) as $attempt ) {
			add_filter(
				'liforms_private_folder',
				static function () use ( $attempt ) {
					return $attempt;
				},
				30
			);
			$dir = liforms()->uploads->get_dir();
			remove_all_filters( 'liforms_private_folder', 30 );
			$this->assertStringStartsWith( $base, $dir, $attempt );
			$this->assertSame( 1, substr_count( substr( $dir, strlen( $base ) ), '/' ), "One folder directly in uploads for '$attempt'." );
			if ( is_dir( $dir ) && $dir !== $base . 'launch-interactive-forms/' && $dir !== trailingslashit( $this->private_dir ) ) {
				array_map( 'unlink', glob( $dir . '{,.}[!.,!..]*', GLOB_BRACE ) ?: array() );
				rmdir( $dir );
			}
		}
	}

	public function test_a_folder_with_no_web_address_is_reported_as_unknown() {
		$this->assertSame( '', liforms()->uploads->public_url_for_dir( '/srv/somewhere-else/' ), 'Only the uploads directory is mapped to a URL.' );
		$this->assertStringStartsWith( wp_upload_dir( null, false )['baseurl'] . '/', liforms()->uploads->public_url_for_dir( trailingslashit( $this->private_dir ) ) );
	}

	public function test_uninstall_only_deletes_files_the_plugin_created() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'launch-interactive-forms/';
		$created = ! is_dir( $dir );
		wp_mkdir_p( $dir );
		liforms()->uploads->protect_dir( $dir );
		$ours    = $dir . str_repeat( 'a', 32 );
		$foreign = $dir . 'keep-me.txt';
		file_put_contents( $ours, 'x' );
		file_put_contents( $foreign, 'not ours' );

		update_option( 'liforms_settings', array( 'delete_data_on_uninstall' => true ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'launch-interactive-forms/launch-interactive-forms.php' );
		}
		include LIFORMS_DIR . 'uninstall.php';

		$this->assertFileDoesNotExist( $ours );
		$this->assertFileDoesNotExist( $dir . '.htaccess' );
		$this->assertFileExists( $foreign, 'Files the plugin did not write are left alone.' );
		$this->assertFalse( get_option( 'liforms_settings' ) );

		unlink( $foreign );
		if ( $created ) {
			rmdir( $dir );
		}
	}
}
