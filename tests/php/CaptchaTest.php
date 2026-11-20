<?php
/**
 * @covers \LaunchForms\Captcha
 */

use LaunchForms\Captcha;

class CaptchaTest extends LF_TestCase {

	private function captcha() {
		return launch_forms()->captcha;
	}

	private function recaptcha_form( $version = 'v2' ) {
		launch_forms()->settings->update(
			array(
				'recaptcha_site_key'   => 'site-key',
				'recaptcha_secret_key' => 'secret-key',
				'recaptcha_version'    => $version,
				'recaptcha_threshold'  => 0.5,
			)
		);
		return $this->contact_form( array( 'settings' => array( 'captcha' => 'recaptcha' ) ) );
	}

	private function turnstile_form() {
		launch_forms()->settings->update( array( 'turnstile_site_key' => 'ts-site', 'turnstile_secret_key' => 'ts-secret' ) );
		return $this->contact_form( array( 'settings' => array( 'captcha' => 'turnstile' ) ) );
	}

	public function test_no_captcha_when_not_selected_or_not_configured() {
		$form = $this->contact_form( array( 'settings' => array( 'captcha' => 'recaptcha' ) ) );
		$this->assertSame( 'none', $this->captcha()->provider_for( $form ), 'Keys missing → disabled.' );
		$this->assertSame( '', $this->captcha()->render( $form ) );
		$this->assertTrue( $this->captcha()->verify( $form, array() ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_recaptcha_v2_markup_and_script() {
		$form = $this->recaptcha_form();
		$html = $this->captcha()->render( $form );
		$this->assertStringContainsString( '<div class="g-recaptcha" data-sitekey="site-key"></div>', $html );
		$this->assertTrue( wp_script_is( 'launch-forms-recaptcha', 'enqueued' ) );
		$this->assertSame( 'https://www.google.com/recaptcha/api.js', wp_scripts()->registered['launch-forms-recaptcha']->src );
		$this->assertStringContainsString( 'g-recaptcha', launch_forms()->renderer->render( $form ) );
	}

	public function test_recaptcha_v3_markup_and_script() {
		$form = $this->recaptcha_form( 'v3' );
		$html = $this->captcha()->render( $form );
		$this->assertStringContainsString( 'class="lf-recaptcha-v3" data-sitekey="site-key" data-action="launch_forms_submit"', $html );
		$this->assertTrue( wp_script_is( 'launch-forms-recaptcha-v3', 'enqueued' ) );
		$this->assertStringContainsString( 'render=site-key', wp_scripts()->registered['launch-forms-recaptcha-v3']->src );
	}

	public function test_turnstile_markup_and_script() {
		$html = $this->captcha()->render( $this->turnstile_form() );
		$this->assertStringContainsString( '<div class="cf-turnstile" data-sitekey="ts-site"', $html );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/api.js', wp_scripts()->registered['launch-forms-turnstile']->src );
	}

	public function test_missing_token_fails_without_http_call() {
		$this->assertWPError( $this->captcha()->verify( $this->turnstile_form(), array() ) );
		$this->assertWPError( $this->captcha()->verify( $this->turnstile_form(), array( 'cf-turnstile-response' => array( 'x' ) ) ) );
		$this->assertSame( array(), $this->http_requests );
	}

	public function test_recaptcha_v2_verification_request() {
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'success' => true ) );
			}
		);
		$result = $this->captcha()->verify( $this->recaptcha_form(), array( 'g-recaptcha-response' => 'tok' ), '203.0.113.5' );
		$this->assertTrue( $result );
		$this->assertSame( Captcha::RECAPTCHA_VERIFY, $this->http_requests[0]['url'] );
		$this->assertSame(
			array( 'secret' => 'secret-key', 'response' => 'tok', 'remoteip' => '203.0.113.5' ),
			$this->http_requests[0]['args']['body']
		);
	}

	public function test_recaptcha_v3_score_and_action() {
		$form     = $this->recaptcha_form( 'v3' );
		$response = array();
		$this->mock_http(
			static function () use ( &$response ) {
				return array( 'body' => $response );
			}
		);
		$response = array( 'success' => true, 'score' => 0.9, 'action' => 'launch_forms_submit' );
		$this->assertTrue( $this->captcha()->verify( $form, array( 'g-recaptcha-response' => 't' ) ) );
		$response = array( 'success' => true, 'score' => 0.2, 'action' => 'launch_forms_submit' );
		$this->assertWPError( $this->captcha()->verify( $form, array( 'g-recaptcha-response' => 't' ) ) );
		$response = array( 'success' => true, 'score' => 0.9, 'action' => 'login' );
		$this->assertWPError( $this->captcha()->verify( $form, array( 'g-recaptcha-response' => 't' ) ) );
	}

	public function test_turnstile_verification() {
		$ok = true;
		$this->mock_http(
			static function () use ( &$ok ) {
				return array( 'body' => array( 'success' => $ok ) );
			}
		);
		$form = $this->turnstile_form();
		$this->assertTrue( $this->captcha()->verify( $form, array( 'cf-turnstile-response' => 'tok' ) ) );
		$this->assertSame( Captcha::TURNSTILE_VERIFY, $this->http_requests[0]['url'] );
		$this->assertSame( 'ts-secret', $this->http_requests[0]['args']['body']['secret'] );
		$ok = false;
		$this->assertWPError( $this->captcha()->verify( $form, array( 'cf-turnstile-response' => 'tok' ) ) );
	}

	public function test_provider_unreachable_fails_closed() {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'timeout' );
			}
		);
		$result = $this->captcha()->verify( $this->turnstile_form(), array( 'cf-turnstile-response' => 'tok' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'lf_captcha_unreachable', $result->get_error_code() );
	}

	public function test_garbage_response_fails() {
		$this->mock_http(
			static function () {
				return array( 'body' => 'not json' );
			}
		);
		$this->assertWPError( $this->captcha()->verify( $this->turnstile_form(), array( 'cf-turnstile-response' => 'tok' ) ) );
	}
}
