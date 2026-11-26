<?php
/**
 * Zoho CRM: connecting, tokens, field list and lead creation.
 *
 * @covers \LaunchInteractiveForms\Zoho
 * @covers \LaunchInteractiveForms\Form_Config
 * @covers \LaunchInteractiveForms\Admin
 */

use LaunchInteractiveForms\Form_Config;
use LaunchInteractiveForms\Settings;
use LaunchInteractiveForms\Submissions;
use LaunchInteractiveForms\Zoho;

class ZohoTest extends LF_TestCase {

	/** @var Zoho */
	private $zoho;

	public function set_up() {
		parent::set_up();
		delete_option( Zoho::OPTION );
		delete_transient( Zoho::TOKEN_TRANSIENT );
		delete_transient( Zoho::FIELDS_TRANSIENT );
		$this->zoho = liforms()->zoho;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** Store a working connection without going through Zoho. */
	private function connect() {
		update_option(
			Zoho::OPTION,
			array(
				'region'          => 'com.au',
				'client_id'       => '1000.CLIENT',
				'client_secret'   => Settings::encrypt( 'client-secret' ),
				'refresh_token'   => Settings::encrypt( 'refresh-token' ),
				'accounts_server' => 'https://accounts.zoho.com.au',
				'api_domain'      => 'https://www.zohoapis.com.au',
				'connected_at'    => time(),
			)
		);
	}

	/**
	 * Mock Zoho: token refreshes succeed; CRM calls get $crm( $url, $body ).
	 */
	private function mock_zoho( callable $crm ) {
		$this->mock_http(
			static function ( $url, $args ) use ( $crm ) {
				if ( false !== strpos( $url, '/oauth/v2/token' ) ) {
					return array(
						'body' => array(
							'access_token' => 'access-' . count( (array) $args['body'] ),
							'expires_in'   => 3600,
							'api_domain'   => 'https://www.zohoapis.com.au',
						),
					);
				}
				return $crm( $url, json_decode( (string) ( $args['body'] ?? '' ), true ), $args );
			}
		);
	}

	private function crm_requests() {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $r ) {
					return false !== strpos( $r['url'], '/crm/v8/' );
				}
			)
		);
	}

	private function lead_form( array $zoho = array() ) {
		return $this->make_form(
			array(
				$this->field( 'text', 'name' ),
				$this->field( 'email', 'email' ),
				$this->field( 'text', 'company' ),
				$this->field( 'textarea', 'message' ),
				$this->field( 'consent', 'agree' ),
			),
			array(
				'zoho' => array_merge(
					array(
						'enabled'   => true,
						'field_map' => array(
							array( 'zoho' => Zoho::FULL_NAME, 'field' => 'name' ),
							array( 'zoho' => 'Email', 'field' => 'email' ),
							array( 'zoho' => 'Company', 'field' => 'company' ),
						),
					),
					$zoho
				),
			)
		);
	}

	private function success( $action = 'insert' ) {
		return array(
			'code' => 'update' === $action ? 200 : 201,
			'body' => array(
				'data' => array(
					array(
						'code'    => 'SUCCESS',
						'action'  => $action,
						'details' => array( 'id' => '5725767000000524157' ),
						'status'  => 'success',
					),
				),
			),
		);
	}

	/* ------------------------------------------------------------------ connecting */

	public function test_connect_sends_the_admin_to_their_regions_zoho_with_a_state_nonce() {
		$url = $this->zoho->start_connect( 'com.au', '1000.ABC', 'shh' );
		$this->assertStringStartsWith( 'https://accounts.zoho.com.au/oauth/v2/auth?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( '1000.ABC', $q['client_id'] );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( Zoho::redirect_uri(), $q['redirect_uri'] );
		$this->assertStringContainsString( 'redirect_uri=' . rawurlencode( Zoho::redirect_uri() ) . '&', $url, 'The redirect URI is encoded, so its own ?action= stays inside it.' );
		$this->assertStringContainsString( 'ZohoCRM.modules.leads', $q['scope'] );
		$this->assertSame( 1, wp_verify_nonce( $q['state'], 'liforms_zoho_connect' ) );

		$saved = get_option( Zoho::OPTION );
		$this->assertStringStartsWith( 'lfenc1:', $saved['client_secret'], 'The secret is stored encrypted.' );
		$this->assertSame( 'shh', $this->zoho->client_secret() );
	}

	public function test_connect_needs_a_client_id_and_secret() {
		$this->assertWPError( $this->zoho->start_connect( 'com', '', '' ) );
	}

	public function test_callback_without_this_sites_state_is_refused() {
		$this->mock_zoho( '__return_empty_array' );
		$result = $this->zoho->finish_connect( array( 'code' => 'abc', 'state' => 'forged' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'liforms_zoho_state', $result->get_error_code() );
		$this->assertSame( array(), $this->http_requests, 'Nothing is sent to Zoho.' );
		$this->assertFalse( $this->zoho->is_connected() );
	}

	public function test_callback_stores_encrypted_tokens_from_the_accounts_server_zoho_names() {
		$this->zoho->start_connect( 'com', '1000.ABC', 'shh' );
		$this->mock_http(
			static function () {
				return array(
					'body' => array(
						'access_token'  => 'acc',
						'refresh_token' => 'ref',
						'api_domain'    => 'https://www.zohoapis.com.au',
						'expires_in'    => 3600,
					),
				);
			}
		);
		$result = $this->zoho->finish_connect(
			array(
				'code'            => '1000.code',
				'state'           => wp_create_nonce( 'liforms_zoho_connect' ),
				'accounts-server' => 'https://accounts.zoho.com.au',
			)
		);
		$this->assertTrue( $result );
		$this->assertSame( 'https://accounts.zoho.com.au/oauth/v2/token', $this->http_requests[0]['url'] );
		$body = $this->http_requests[0]['args']['body'];
		$this->assertSame( 'authorization_code', $body['grant_type'] );
		$this->assertSame( 'shh', $body['client_secret'] );
		$this->assertSame( Zoho::redirect_uri(), $body['redirect_uri'] );

		$this->assertTrue( $this->zoho->is_connected() );
		$saved = get_option( Zoho::OPTION );
		$this->assertSame( 'com.au', $saved['region'], 'The region follows where the account lives.' );
		$this->assertSame( 'https://www.zohoapis.com.au', $saved['api_domain'] );
		$this->assertStringNotContainsString( 'ref', $saved['refresh_token'] );
		$this->assertSame( 'acc', $this->zoho->access_token(), 'The first access token is cached.' );
	}

	public function test_callback_never_sends_the_secret_to_an_unknown_server() {
		$this->zoho->start_connect( 'eu', '1000.ABC', 'shh' );
		$this->mock_http(
			static function () {
				return array(
					'body' => array(
						'access_token'  => 'acc',
						'refresh_token' => 'ref',
						'api_domain'    => 'https://www.zohoapis.eu',
					),
				);
			}
		);
		$this->zoho->finish_connect(
			array(
				'code'            => 'c',
				'state'           => wp_create_nonce( 'liforms_zoho_connect' ),
				'accounts-server' => 'https://evil.example/zoho',
			)
		);
		$this->assertSame( 'https://accounts.zoho.eu/oauth/v2/token', $this->http_requests[0]['url'], 'Falls back to the chosen region.' );
	}

	public function test_callback_refuses_an_api_domain_that_is_not_zoho() {
		$this->zoho->start_connect( 'com', '1000.ABC', 'shh' );
		$this->mock_http(
			static function () {
				return array(
					'body' => array(
						'access_token'  => 'acc',
						'refresh_token' => 'ref',
						'api_domain'    => 'https://www.zohoapis.com.evil.example',
					),
				);
			}
		);
		$result = $this->zoho->finish_connect( array( 'code' => 'c', 'state' => wp_create_nonce( 'liforms_zoho_connect' ) ) );
		$this->assertWPError( $result );
		$this->assertFalse( $this->zoho->is_connected() );
	}

	public function test_callback_reports_zoho_errors() {
		$this->zoho->start_connect( 'com', '1000.ABC', 'shh' );
		$this->mock_http(
			static function () {
				return array( 'body' => array( 'error' => 'invalid_client' ) );
			}
		);
		$result = $this->zoho->finish_connect( array( 'code' => 'c', 'state' => wp_create_nonce( 'liforms_zoho_connect' ) ) );
		$this->assertStringContainsString( 'invalid_client', $result->get_error_message() );

		$denied = $this->zoho->finish_connect( array( 'error' => 'access_denied', 'state' => wp_create_nonce( 'liforms_zoho_connect' ) ) );
		$this->assertStringContainsString( 'access_denied', $denied->get_error_message() );
	}

	public function test_disconnect_forgets_tokens_but_keeps_the_client() {
		$this->connect();
		$this->zoho->disconnect();
		$this->assertFalse( $this->zoho->is_connected() );
		$this->assertSame( '1000.CLIENT', $this->zoho->client_id() );
		$this->assertSame( 'client-secret', $this->zoho->client_secret() );
	}

	/* ------------------------------------------------------------------ tokens */

	public function test_access_token_is_refreshed_once_and_cached() {
		$this->connect();
		$this->mock_zoho( '__return_empty_array' );
		$this->assertSame( 'access-4', $this->zoho->access_token() );
		$this->assertSame( 'access-4', $this->zoho->access_token() );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( 'https://accounts.zoho.com.au/oauth/v2/token', $this->http_requests[0]['url'] );
		$this->assertSame( 'refresh_token', $this->http_requests[0]['args']['body']['grant_type'] );
		$this->assertSame( 'refresh-token', $this->http_requests[0]['args']['body']['refresh_token'] );
		$this->assertStringStartsWith( 'lfenc1:', get_transient( Zoho::TOKEN_TRANSIENT ), 'Cached token is encrypted.' );
	}

	public function test_an_expired_token_is_refreshed_and_the_call_retried() {
		$this->connect();
		set_transient( Zoho::TOKEN_TRANSIENT, Settings::encrypt( 'stale' ), 600 );
		$calls = 0;
		$this->mock_zoho(
			function ( $url, $body, $args ) use ( &$calls ) {
				++$calls;
				if ( 'Zoho-oauthtoken stale' === $args['headers']['Authorization'] ) {
					return array(
						'code' => 401,
						'body' => array( 'code' => 'INVALID_TOKEN' ),
					);
				}
				return $this->success();
			}
		);
		$this->assertSame( 'Lead created (5725767000000524157)', $this->zoho->create_lead( $this->lead_form(), array( 'name' => 'Ann Lee' ), array() ) );
		$this->assertSame( 2, $calls );
	}

	public function test_not_connected_is_an_error_not_a_crash() {
		$result = $this->zoho->create_lead( $this->lead_form(), array( 'name' => 'Ann' ), array() );
		$this->assertSame( 'liforms_zoho_not_connected', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------ fields */

	public function test_lead_fields_lists_mappable_fields_required_first() {
		$this->connect();
		$this->mock_zoho(
			static function () {
				return array(
					'body' => array(
						'fields' => array(
							array( 'api_name' => 'Email', 'field_label' => 'Email', 'data_type' => 'email' ),
							array( 'api_name' => 'Last_Name', 'field_label' => 'Last Name', 'data_type' => 'text', 'system_mandatory' => true ),
							array( 'api_name' => 'Owner', 'field_label' => 'Lead Owner', 'data_type' => 'ownerlookup' ),
							array( 'api_name' => 'Created_Time', 'field_label' => 'Created', 'data_type' => 'datetime', 'read_only' => true ),
							array( 'api_name' => 'Lead_Source', 'field_label' => 'Lead Source', 'data_type' => 'picklist' ),
						),
					),
				);
			}
		);
		$fields = $this->zoho->lead_fields();
		$this->assertSame( array( 'Last_Name', 'Email', 'Lead_Source' ), wp_list_pluck( $fields, 'api_name' ) );
		$this->assertTrue( $fields[0]['required'] );
		$this->assertStringEndsWith( '/crm/v8/settings/fields?module=Leads', $this->crm_requests()[0]['url'] );
		$this->zoho->lead_fields();
		$this->assertCount( 1, $this->crm_requests(), 'Cached.' );
	}

	public function test_fields_route_is_for_editors_only() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/liforms/v1/zoho/fields' ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/liforms/v1/zoho/fields' ) )->get_status() );
	}

	/* ------------------------------------------------------------------ leads */

	public function test_lead_is_built_from_the_map() {
		$form   = $this->lead_form( array( 'lead_source' => 'Website' ) );
		$record = $this->zoho->build_lead( $form, array( 'name' => 'Ann Marie Lee', 'email' => 'ann@example.com', 'company' => 'Acme' ), array() );
		$this->assertSame(
			array(
				'First_Name'  => 'Ann Marie',
				'Last_Name'   => 'Lee',
				'Email'       => 'ann@example.com',
				'Company'     => 'Acme',
				'Lead_Source' => 'Website',
			),
			$record
		);
	}

	public function test_a_lead_without_a_last_name_still_gets_one() {
		$form = $this->lead_form();
		$this->assertSame( 'ann@example.com', $this->zoho->build_lead( $form, array( 'email' => 'ann@example.com' ), array() )['Last_Name'] );
		$this->assertSame( 'Prince', $this->zoho->build_lead( $form, array( 'name' => 'Prince' ), array() )['Last_Name'] );
		$this->assertArrayNotHasKey( 'First_Name', $this->zoho->build_lead( $form, array( 'name' => 'Prince' ), array() ) );
	}

	public function test_values_are_converted_for_zoho_field_types() {
		$this->assertSame( array( 'A', 'B' ), Zoho::convert( array( 'A', 'B' ), 'multiselectpicklist' ) );
		$this->assertSame( 'A, B', Zoho::convert( array( 'A', 'B' ), 'text' ) );
		$this->assertTrue( Zoho::convert( '1', 'boolean' ) );
		$this->assertFalse( Zoho::convert( '', 'boolean' ) );
		$this->assertSame( 42, Zoho::convert( '42', 'integer' ) );
		$this->assertSame( 9.5, Zoho::convert( '9.5', 'currency' ) );
	}

	public function test_description_can_include_every_field() {
		$form    = $this->lead_form( array( 'description_all_fields' => true ) );
		$entries = array(
			array( 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Ann' ),
			array( 'name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'value' => 'Hi' ),
		);
		$this->assertSame( "Name: Ann\nMessage: Hi", $this->zoho->build_lead( $form, array( 'name' => 'Ann' ), $entries )['Description'] );
	}

	public function test_existing_leads_are_updated_by_email() {
		$this->connect();
		$this->mock_zoho(
			function () {
				return $this->success( 'update' );
			}
		);
		$result = $this->zoho->create_lead( $this->lead_form(), array( 'name' => 'Ann Lee', 'email' => 'ann@example.com' ), array() );
		$this->assertSame( 'Lead updated (5725767000000524157)', $result );
		$request = $this->crm_requests()[0];
		$this->assertSame( 'https://www.zohoapis.com.au/crm/v8/Leads/upsert', $request['url'] );
		$this->assertSame( 'Zoho-oauthtoken access-4', $request['args']['headers']['Authorization'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( array( 'Email' ), $body['duplicate_check_fields'] );
		$this->assertArrayNotHasKey( 'trigger', $body, 'Workflow rules run by default.' );
	}

	public function test_insert_when_updating_is_off_or_there_is_no_email_and_workflows_can_be_skipped() {
		$this->connect();
		$this->mock_zoho(
			function () {
				return $this->success();
			}
		);
		$this->zoho->create_lead( $this->lead_form( array( 'update_existing' => false, 'run_workflows' => false ) ), array( 'name' => 'Ann', 'email' => 'ann@example.com' ), array() );
		$this->zoho->create_lead( $this->lead_form(), array( 'name' => 'Bob' ), array() );
		$requests = $this->crm_requests();
		$this->assertSame( 'https://www.zohoapis.com.au/crm/v8/Leads', $requests[0]['url'] );
		$this->assertSame( array(), json_decode( $requests[0]['args']['body'], true )['trigger'] );
		$this->assertSame( 'https://www.zohoapis.com.au/crm/v8/Leads', $requests[1]['url'] );
	}

	public function test_consent_field_gates_the_lead() {
		$this->connect();
		$this->mock_zoho(
			function () {
				return $this->success();
			}
		);
		$form = $this->lead_form( array( 'consent_field' => 'agree' ) );
		$this->assertNull( $this->zoho->create_lead( $form, array( 'name' => 'Ann', 'agree' => '' ), array() ) );
		$this->assertSame( array(), $this->crm_requests() );
		$this->assertIsString( $this->zoho->create_lead( $form, array( 'name' => 'Ann', 'agree' => '1' ), array() ) );

		$removed = $this->lead_form( array( 'consent_field' => 'gone' ) );
		$this->assertNull( $this->zoho->create_lead( $removed, array( 'name' => 'Ann' ), array() ), 'A deleted consent field sends nothing.' );
	}

	public function test_zoho_errors_are_readable() {
		$this->connect();
		$this->mock_zoho(
			static function () {
				return array(
					'code' => 400,
					'body' => array(
						'data' => array(
							array(
								'code'    => 'MANDATORY_NOT_FOUND',
								'details' => array( 'api_name' => 'Company' ),
								'message' => 'required field not found',
								'status'  => 'error',
							),
						),
					),
				);
			}
		);
		$result = $this->zoho->create_lead( $this->lead_form(), array( 'name' => 'Ann' ), array() );
		$this->assertSame( 'Zoho CRM error 400: MANDATORY_NOT_FOUND required field not found (Company)', $result->get_error_message() );
	}

	public function test_submission_logs_the_zoho_result() {
		$this->connect();
		$this->mock_zoho(
			function () {
				return $this->success();
			}
		);
		$form   = $this->lead_form();
		$result = liforms()->processor->process( $form, array( 'lif' => array( 'name' => 'Ann Lee', 'email' => 'ann@example.com' ) ), array(), array( 'page_url' => 'https://example.org/' ) );
		$this->assertTrue( $result->success );
		$sub = get_posts( array( 'post_type' => Submissions::POST_TYPE, 'post_parent' => $form->id ) )[0];
		$log = liforms()->submissions->get_log( $sub->ID );
		$zoho = array_values( wp_list_filter( $log, array( 'action' => 'zoho' ) ) )[0];
		$this->assertTrue( $zoho['success'] );
		$this->assertSame( 'Lead created (5725767000000524157)', $zoho['message'] );
	}

	/* ------------------------------------------------------------------ config */

	public function test_zoho_settings_are_sanitised() {
		$config = Form_Config::sanitize(
			array(
				'fields' => array( array( 'type' => 'text', 'name' => 'name' ), array( 'type' => 'email', 'name' => 'email' ) ),
				'zoho'   => array(
					'enabled'       => '1',
					'consent_field' => 'ghost',
					'lead_source'   => '<b>Web</b>',
					'field_map'     => array(
						array( 'zoho' => 'Last_Name<script>', 'type' => 'TEXT!', 'field' => 'name' ),
						array( 'zoho' => 'Last_Name', 'field' => 'email' ),
						array( 'zoho' => 'Phone', 'field' => 'ghost' ),
						array( 'zoho' => 'Email', 'field' => 'email' ),
						'nonsense',
					),
				),
			)
		);
		$zoho = $config['zoho'];
		$this->assertTrue( $zoho['enabled'] );
		$this->assertSame( 'ghost', $zoho['consent_field'], 'Kept so a deleted consent field never sends everyone.' );
		$this->assertSame( 'Web', $zoho['lead_source'] );
		$this->assertSame(
			array(
				array( 'zoho' => 'Last_Namescript', 'type' => 'text', 'field' => 'name' ),
				array( 'zoho' => 'Last_Name', 'type' => '', 'field' => 'email' ),
				array( 'zoho' => 'Email', 'type' => '', 'field' => 'email' ),
			),
			$zoho['field_map']
		);
		$this->assertTrue( $zoho['update_existing'] );
		$this->assertTrue( $zoho['run_workflows'] );
	}

	/* ------------------------------------------------------------------ settings page */

	public function test_settings_page_offers_connecting_then_disconnecting() {
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="liforms_zoho_connect"', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( Zoho::redirect_uri() ) . '"', $html );
		$this->assertStringNotContainsString( 'onfocus=', $html );

		$this->connect();
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'value="liforms_zoho_disconnect"', $html );
		$this->assertStringContainsString( 'Australia', $html );
		$this->assertNotFalse( has_action( 'admin_post_liforms_zoho_callback' ) );
	}
}
