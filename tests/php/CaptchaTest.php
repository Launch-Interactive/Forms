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
		return $this->form_with( 'recaptcha' );
	}

	/**
	 * Name, captcha, email, message: the captcha sits between fields.
	 */
	private function form_with( $type, array $captcha = array() ) {
		return $this->make_form(
			array(
				$this->field( 'text', 'name', array( 'required' => true ) ),
				array_merge( array( 'type' => $type ), $captcha ),
				$this->field( 'email', 'email', array( 'required' => true ) ),
				$this->field( 'textarea', 'message' ),
			)
		);
	}

	private function turnstile_form() {
		launch_forms()->settings->update( array( 'turnstile_site_key' => 'ts-site', 'turnstile_secret_key' => 'ts-secret' ) );
		return $this->form_with( 'turnstile' );
	}

	public function test_no_captcha_when_not_selected_or_not_configured() {
		$this->assertSame( 'none', $this->captcha()->provider_for( $this->contact_form() ), 'No captcha field.' );
		$form = $this->form_with( 'recaptcha' );
		$this->assertSame( 'none', $this->captcha()->provider_for( $form ), 'Keys missing → disabled.' );
		$this->assertStringNotContainsString( 'lf-field--captcha', launch_forms()->renderer->render( $form ), 'Field not shown without keys.' );
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

	public function test_captcha_renders_where_it_is_placed_with_its_width() {
		launch_forms()->settings->update( array( 'turnstile_site_key' => 'ts-site', 'turnstile_secret_key' => 'ts-secret' ) );
		$form = $this->form_with( 'turnstile', array( 'width' => 'half', 'css_class' => 'my-captcha' ) );
		$html = launch_forms()->renderer->render( $form );

		$name    = strpos( $html, 'name="lf[name]"' );
		$captcha = strpos( $html, 'class="cf-turnstile"' );
		$email   = strpos( $html, 'name="lf[email]"' );
		$this->assertTrue( $name < $captcha && $captcha < $email, 'Between Name and Email, as placed.' );
		$this->assertSame( 1, substr_count( $html, 'cf-turnstile' ), 'Not also added at the end.' );
		$this->assertMatchesRegularExpression( '/<div class="lf-field lf-field--turnstile lf-field--half my-captcha lf-field--captcha" data-lf-name="turnstile"><div class="lf-captcha"><div class="cf-turnstile"/', $html );
		$this->assertLessThan( strpos( $html, 'type="submit"' ), $captcha );
	}

	public function test_only_one_captcha_and_never_conditional() {
		$config = LaunchForms\Form_Config::sanitize(
			array(
				'fields' => array(
					array( 'type' => 'text', 'name' => 'name' ),
					array(
						'type'       => 'recaptcha',
						'required'   => true,
						'conditions' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'name', 'operator' => 'empty', 'value' => '' ) ) ),
					),
					array( 'type' => 'turnstile' ),
					array( 'type' => 'recaptcha' ),
					array(
						'type'       => 'text',
						'name'       => 'other',
						'conditions' => array( 'enabled' => true, 'rules' => array( array( 'field' => 'recaptcha', 'operator' => 'not_empty', 'value' => '' ) ) ),
					),
				),
			)
		);
		$this->assertSame( array( 'text', 'recaptcha', 'text' ), wp_list_pluck( $config['fields'], 'type' ) );
		$this->assertFalse( $config['fields'][1]['conditions']['enabled'], 'A hidden captcha would let bots skip it.' );
		$this->assertSame( array(), $config['fields'][1]['conditions']['rules'] );
		$this->assertFalse( $config['fields'][1]['required'] );
		$this->assertFalse( $config['fields'][2]['conditions']['enabled'], 'Rules cannot depend on a captcha.' );
	}

	public function test_forms_using_the_old_captcha_setting_get_a_field_at_the_end() {
		$config = LaunchForms\Form_Config::sanitize(
			array(
				'fields'   => array( array( 'type' => 'text', 'name' => 'name' ), array( 'type' => 'email', 'name' => 'email' ) ),
				'settings' => array( 'captcha' => 'turnstile' ),
			)
		);
		$this->assertSame( array( 'text', 'email', 'turnstile' ), wp_list_pluck( $config['fields'], 'type' ) );
		$this->assertSame( 'none', $config['settings']['captcha'] );

		$again = LaunchForms\Form_Config::sanitize( $config );
		$this->assertCount( 3, $again['fields'], 'Migrating twice changes nothing.' );

		$placed = LaunchForms\Form_Config::sanitize(
			array(
				'fields'   => array( array( 'type' => 'recaptcha' ), array( 'type' => 'text', 'name' => 'name' ) ),
				'settings' => array( 'captcha' => 'turnstile' ),
			)
		);
		$this->assertSame( array( 'recaptcha', 'text' ), wp_list_pluck( $placed['fields'], 'type' ), 'An existing captcha field wins.' );
	}

	public function test_failed_check_shows_the_error_at_the_captcha() {
		$form = $this->recaptcha_form();
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'success' => false ) );
			}
		);
		$result = launch_forms()->processor->process( $form, array( 'lf' => array( 'name' => 'Ann', 'email' => 'ann@example.com' ), 'g-recaptcha-response' => 'bad' ) );
		$this->assertFalse( $result->success );
		$this->assertArrayHasKey( 'recaptcha', $result->errors );

		$html = launch_forms()->renderer->render( $form );
		$this->assertMatchesRegularExpression( '/data-lf-name="recaptcha">.*class="g-recaptcha".*<p class="lf-error"[^>]*>Please complete the spam check/s', $html );
		$this->assertStringContainsString( 'value="Ann"', $html, 'What they typed is kept.' );
	}

	public function test_captcha_fields_collect_no_value() {
		$form   = $this->form_with( 'turnstile' );
		$result = launch_forms()->processor->process( $form, array( 'lf' => array( 'name' => 'Ann', 'email' => 'ann@example.com', 'turnstile' => 'sneaky' ) ) );
		$this->assertTrue( $result->success, 'Keys not set, so no check runs.' );
		$this->assertSame( array( 'name', 'email', 'message' ), wp_list_pluck( launch_forms()->submissions->get_entries( $result->submission_id ), 'name' ) );
	}
}
