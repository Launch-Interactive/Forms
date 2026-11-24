<?php
/**
 * @covers \LaunchInteractiveForms\Settings
 */

use LaunchInteractiveForms\Settings;

class SettingsTest extends LF_TestCase {

	public function test_defaults() {
		$all = liforms()->settings->all();
		$this->assertTrue( $all['load_css'] );
		$this->assertSame( 'v2', $all['recaptcha_version'] );
		$this->assertSame( '', $all['mailchimp_api_key'] );
		$this->assertFalse( $all['delete_data_on_uninstall'] );
		$this->assertSame( '*', $all['required_marker'] );
	}

	public function test_required_marker_is_plain_short_text() {
		$s = liforms()->settings;
		$this->assertSame( '(required)', $s->sanitize( array( 'required_marker' => '  (required) ' ) )['required_marker'] );
		$this->assertSame( 'req', $s->sanitize( array( 'required_marker' => '<em>req</em>' ) )['required_marker'] );
		$this->assertSame( 50, mb_strlen( $s->sanitize( array( 'required_marker' => str_repeat( 'é', 80 ) ) )['required_marker'] ) );
		$this->assertSame( '', $s->sanitize( array( 'required_marker' => '' ) )['required_marker'], 'Can be emptied.' );
		$this->assertSame( '*', $s->sanitize( array() )['required_marker'], 'Missing falls back to the default.' );
	}

	public function test_sanitize() {
		$clean = liforms()->settings->sanitize(
			array(
				'load_css'             => '',
				'recaptcha_version'    => 'v9',
				'recaptcha_site_key'   => '6Le<script>abc',
				'recaptcha_threshold'  => '7',
				'turnstile_secret_key' => '0x4AAA-bb_cc',
				'mailchimp_api_key'    => "abc-us5\n",
				'unknown'              => 'dropped',
			)
		);
		$this->assertFalse( $clean['load_css'] );
		$this->assertSame( 'v2', $clean['recaptcha_version'] );
		$this->assertSame( '6Lescriptabc', $clean['recaptcha_site_key'] );
		$this->assertSame( 1.0, $clean['recaptcha_threshold'] );
		$this->assertSame( '0x4AAA-bb_cc', $clean['turnstile_secret_key'] );
		$this->assertSame( 'abc-us5', $clean['mailchimp_api_key'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
		$this->assertSame( array(), array_diff_key( $clean, Settings::defaults() ) );
		$this->assertSame( 0.0, liforms()->settings->sanitize( array( 'recaptcha_threshold' => -1 ) )['recaptcha_threshold'] );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( liforms()->settings->sanitize( 'garbage' ) ) );
	}

	public function test_update_and_get() {
		liforms()->settings->update( array( 'turnstile_site_key' => 'abc' ) );
		$this->assertSame( 'abc', liforms()->settings->get( 'turnstile_site_key' ) );
		$this->assertTrue( liforms()->settings->get( 'load_css' ), 'Other values preserved.' );
		$this->assertNull( liforms()->settings->get( 'nope' ) );
	}

	public function test_registered_with_settings_api() {
		liforms()->settings->register();
		$registered = get_registered_settings();
		$this->assertArrayHasKey( Settings::OPTION, $registered );
		update_option( Settings::OPTION, array( 'recaptcha_version' => 'v3', 'load_css' => '1', 'evil' => 1 ) );
		$saved = get_option( Settings::OPTION );
		$this->assertSame( 'v3', $saved['recaptcha_version'] );
		$this->assertArrayNotHasKey( 'evil', $saved );
	}
}
