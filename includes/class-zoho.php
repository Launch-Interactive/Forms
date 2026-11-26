<?php
/**
 * Zoho CRM: connect with OAuth and create (or update) a lead when a form is submitted.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Zoho CRM client (REST API v8).
 *
 * The site connects once under Forms → Settings with its own Zoho API client (client ID and
 * secret). Zoho returns a refresh token, stored encrypted, which is exchanged for short-lived
 * access tokens as needed.
 */
class Zoho {

	/** Option holding the connection (tokens are encrypted). */
	const OPTION = 'liforms_zoho';

	/** Transient caching the current access token (encrypted). */
	const TOKEN_TRANSIENT = 'liforms_zoho_token';

	/** Transient caching the Leads field list for the builder. */
	const FIELDS_TRANSIENT = 'liforms_zoho_fields';

	/** Pseudo field: one "Name" form field split into First_Name and Last_Name. */
	const FULL_NAME = '__full_name';

	/** API version used for every CRM call. */
	const API_PATH = '/crm/v8/';

	/** Field types the builder can't map to (computed, lookups and the like). */
	const UNMAPPABLE_TYPES = array( 'lookup', 'ownerlookup', 'userlookup', 'multiselectlookup', 'formula', 'autonumber', 'profileimage', 'fileupload', 'imageupload', 'subform', 'rollup_summary', 'multiuserlookup' );

	/** Zoho data centres: accounts server per region. */
	const REGIONS = array(
		'com'    => 'https://accounts.zoho.com',
		'com.au' => 'https://accounts.zoho.com.au',
		'eu'     => 'https://accounts.zoho.eu',
		'in'     => 'https://accounts.zoho.in',
		'jp'     => 'https://accounts.zoho.jp',
		'ca'     => 'https://accounts.zohocloud.ca',
		'sa'     => 'https://accounts.zoho.sa',
		'com.cn' => 'https://accounts.zoho.com.cn',
	);

	/** API domains Zoho may hand back; anything else is refused so tokens never go elsewhere. */
	const API_DOMAIN_PATTERN = '#^https://www\.zohoapis\.(com|com\.au|eu|in|jp|ca|sa|com\.cn)$#';

	/** @var Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings (for encryption helpers).
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Region labels for the settings screen.
	 *
	 * @return array<string,string>
	 */
	public static function region_labels() {
		return array(
			'com'    => __( 'United States (zoho.com)', 'launch-interactive-forms' ),
			'com.au' => __( 'Australia (zoho.com.au)', 'launch-interactive-forms' ),
			'eu'     => __( 'Europe (zoho.eu)', 'launch-interactive-forms' ),
			'in'     => __( 'India (zoho.in)', 'launch-interactive-forms' ),
			'jp'     => __( 'Japan (zoho.jp)', 'launch-interactive-forms' ),
			'ca'     => __( 'Canada (zohocloud.ca)', 'launch-interactive-forms' ),
			'sa'     => __( 'Saudi Arabia (zoho.sa)', 'launch-interactive-forms' ),
			'com.cn' => __( 'China (zoho.com.cn)', 'launch-interactive-forms' ),
		);
	}

	/* ------------------------------------------------------------------ connection */

	/**
	 * Stored connection merged with defaults.
	 *
	 * @return array
	 */
	public function connection() {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'region'          => 'com',
				'client_id'       => '',
				'client_secret'   => '', // Encrypted.
				'refresh_token'   => '', // Encrypted.
				'accounts_server' => '',
				'api_domain'      => '',
				'connected_at'    => 0,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Save connection values (merged).
	 *
	 * @param array $values Values.
	 */
	private function save( array $values ) {
		update_option( self::OPTION, array_merge( $this->connection(), $values ), false );
	}

	/**
	 * Client ID: LIFORMS_ZOHO_CLIENT_ID if defined, otherwise the saved one.
	 *
	 * @return string
	 */
	public function client_id() {
		return defined( 'LIFORMS_ZOHO_CLIENT_ID' ) ? (string) LIFORMS_ZOHO_CLIENT_ID : (string) $this->connection()['client_id'];
	}

	/**
	 * Client secret: LIFORMS_ZOHO_CLIENT_SECRET if defined, otherwise the saved (decrypted) one.
	 *
	 * @return string
	 */
	public function client_secret() {
		return defined( 'LIFORMS_ZOHO_CLIENT_SECRET' ) ? (string) LIFORMS_ZOHO_CLIENT_SECRET : Settings::decrypt( (string) $this->connection()['client_secret'] );
	}

	/**
	 * Whether a refresh token and API domain are stored.
	 *
	 * @return bool
	 */
	public function is_connected() {
		$c = $this->connection();
		return '' !== (string) $c['refresh_token'] && '' !== (string) $c['api_domain'];
	}

	/**
	 * The redirect URI to register with the Zoho API client.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=liforms_zoho_callback' );
	}

	/**
	 * Accounts server for a region.
	 *
	 * @param string $region Region key.
	 * @return string
	 */
	public static function accounts_for( $region ) {
		return self::REGIONS[ $region ] ?? self::REGIONS['com'];
	}

	/**
	 * Scopes requested: create and update leads (upsert), and read field names for mapping.
	 *
	 * @return string
	 */
	public static function scopes() {
		/**
		 * Filters the OAuth scopes requested from Zoho.
		 *
		 * @param string $scopes Comma-separated scopes.
		 */
		return (string) apply_filters( 'liforms_zoho_scopes', 'ZohoCRM.modules.leads.ALL,ZohoCRM.settings.fields.READ' );
	}

	/**
	 * Save the client details and build the Zoho consent URL.
	 *
	 * @param string $region        Region key.
	 * @param string $client_id     Client ID ('' keeps the saved/constant one).
	 * @param string $client_secret Client secret ('' keeps the saved/constant one).
	 * @return string|\WP_Error URL to send the admin to.
	 */
	public function start_connect( $region, $client_id, $client_secret ) {
		$region = array_key_exists( $region, self::REGIONS ) ? $region : 'com';
		$update = array( 'region' => $region );
		if ( '' !== $client_id ) {
			$update['client_id'] = $client_id;
		}
		if ( '' !== $client_secret ) {
			$update['client_secret'] = Settings::encrypt( $client_secret );
		}
		$this->save( $update );

		if ( '' === $this->client_id() || '' === $this->client_secret() ) {
			return new \WP_Error( 'liforms_zoho_client', __( 'Enter the Zoho client ID and client secret.', 'launch-interactive-forms' ) );
		}

		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'scope'         => self::scopes(),
					'client_id'     => $this->client_id(),
					'response_type' => 'code',
					'access_type'   => 'offline',
					'prompt'        => 'consent',
					'redirect_uri'  => self::redirect_uri(),
					'state'         => wp_create_nonce( 'liforms_zoho_connect' ),
				)
			),
			self::accounts_for( $region ) . '/oauth/v2/auth'
		);
	}

	/**
	 * Finish connecting: exchange the code Zoho sent back for tokens.
	 *
	 * @param array $query Callback query args (code, state, accounts-server, error).
	 * @return true|\WP_Error
	 */
	public function finish_connect( array $query ) {
		if ( ! wp_verify_nonce( (string) ( $query['state'] ?? '' ), 'liforms_zoho_connect' ) ) {
			return new \WP_Error( 'liforms_zoho_state', __( 'The Zoho connection request expired or did not come from this site. Please try again.', 'launch-interactive-forms' ) );
		}
		if ( ! empty( $query['error'] ) ) {
			/* translators: %s: error code from Zoho, e.g. access_denied. */
			return new \WP_Error( 'liforms_zoho_denied', sprintf( __( 'Zoho did not allow the connection (%s).', 'launch-interactive-forms' ), sanitize_key( (string) $query['error'] ) ) );
		}
		$code = preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) ( $query['code'] ?? '' ) );
		if ( '' === $code ) {
			return new \WP_Error( 'liforms_zoho_code', __( 'Zoho did not send an authorisation code.', 'launch-interactive-forms' ) );
		}

		// Zoho says which data centre the account lives in. Only accept known Zoho servers, so the
		// client secret is never sent anywhere else.
		$accounts = untrailingslashit( (string) ( $query['accounts-server'] ?? '' ) );
		if ( ! in_array( $accounts, self::REGIONS, true ) ) {
			$accounts = self::accounts_for( $this->connection()['region'] );
		}

		$data = $this->token_request(
			$accounts,
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new \WP_Error( 'liforms_zoho_refresh', __( 'Zoho did not return a refresh token. Remove this site from Connected Apps in your Zoho account and connect again.', 'launch-interactive-forms' ) );
		}
		$api_domain = untrailingslashit( (string) ( $data['api_domain'] ?? '' ) );
		if ( ! preg_match( self::API_DOMAIN_PATTERN, $api_domain ) ) {
			return new \WP_Error( 'liforms_zoho_domain', __( 'Zoho returned an unexpected API address.', 'launch-interactive-forms' ) );
		}

		$region = array_search( $accounts, self::REGIONS, true );
		$this->save(
			array(
				'region'          => false !== $region ? $region : $this->connection()['region'],
				'refresh_token'   => Settings::encrypt( (string) $data['refresh_token'] ),
				'accounts_server' => $accounts,
				'api_domain'      => $api_domain,
				'connected_at'    => time(),
			)
		);
		$this->cache_access_token( $data );
		delete_transient( self::FIELDS_TRANSIENT );
		return true;
	}

	/**
	 * Forget the tokens (the client ID and secret stay, so reconnecting is one click).
	 */
	public function disconnect() {
		$this->save(
			array(
				'refresh_token'   => '',
				'accounts_server' => '',
				'api_domain'      => '',
				'connected_at'    => 0,
			)
		);
		delete_transient( self::TOKEN_TRANSIENT );
		delete_transient( self::FIELDS_TRANSIENT );
	}

	/* ------------------------------------------------------------------ tokens */

	/**
	 * POST to the accounts server's token endpoint.
	 *
	 * @param string $accounts Accounts server URL.
	 * @param array  $body     Form fields.
	 * @return array|\WP_Error Decoded response.
	 */
	private function token_request( $accounts, array $body ) {
		$response = wp_remote_post(
			$accounts . '/oauth/v2/token',
			array(
				'timeout' => 15,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! empty( $data['error'] ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'HTTP ' . wp_remote_retrieve_response_code( $response );
			/* translators: %s: Zoho error code, e.g. invalid_client. */
			return new \WP_Error( 'liforms_zoho_token', sprintf( __( 'Zoho refused the token request (%s).', 'launch-interactive-forms' ), sanitize_text_field( $error ) ) );
		}
		return $data;
	}

	/**
	 * Cache an access token from a token response.
	 *
	 * @param array $data Token response.
	 */
	private function cache_access_token( array $data ) {
		$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 );
		set_transient( self::TOKEN_TRANSIENT, Settings::encrypt( (string) $data['access_token'] ), $ttl );
	}

	/**
	 * A valid access token, refreshing it when needed.
	 *
	 * @param bool $force Ignore the cached token.
	 * @return string|\WP_Error
	 */
	public function access_token( $force = false ) {
		if ( ! $this->is_connected() ) {
			return new \WP_Error( 'liforms_zoho_not_connected', __( 'Zoho CRM is not connected. Connect it under Forms → Settings.', 'launch-interactive-forms' ) );
		}
		if ( ! $force ) {
			$cached = Settings::decrypt( (string) get_transient( self::TOKEN_TRANSIENT ) );
			if ( '' !== $cached ) {
				return $cached;
			}
		}
		$c    = $this->connection();
		$data = $this->token_request(
			$c['accounts_server'],
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => Settings::decrypt( (string) $c['refresh_token'] ),
				'client_id'     => $this->client_id(),
				'client_secret' => $this->client_secret(),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$this->cache_access_token( $data );
		return (string) $data['access_token'];
	}

	/**
	 * Call the CRM API. Retries once with a fresh token if Zoho says the token is invalid.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path after /crm/v8/.
	 * @param array|null $body   JSON body.
	 * @return array{code:int,data:array}|\WP_Error
	 */
	public function request( $method, $path, $body = null ) {
		$token = $this->access_token();
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$args = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Zoho-oauthtoken ' . $token,
					'Content-Type'  => 'application/json',
				),
			);
			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}
			$response = wp_remote_request( $this->connection()['api_domain'] . self::API_PATH . $path, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$data = is_array( $data ) ? $data : array();
			if ( 401 === $code && 0 === $attempt ) {
				delete_transient( self::TOKEN_TRANSIENT );
				$token = $this->access_token( true );
				continue;
			}
			return array(
				'code' => $code,
				'data' => $data,
			);
		}
		return new \WP_Error( 'liforms_zoho_auth', __( 'Zoho CRM rejected the access token.', 'launch-interactive-forms' ) );
	}

	/* ------------------------------------------------------------------ fields */

	/**
	 * Lead fields the builder can map to: api name, label, type and whether Zoho requires it.
	 *
	 * @return array|\WP_Error List of {api_name, label, type, required}.
	 */
	public function lead_fields() {
		$cached = get_transient( self::FIELDS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$result = $this->request( 'GET', 'settings/fields?module=Leads' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 200 !== $result['code'] || ! isset( $result['data']['fields'] ) ) {
			return self::api_error( $result );
		}
		$fields = array();
		foreach ( (array) $result['data']['fields'] as $f ) {
			if ( ! is_array( $f ) || empty( $f['api_name'] ) || ! empty( $f['read_only'] ) || ! empty( $f['field_read_only'] ) || in_array( $f['data_type'] ?? '', self::UNMAPPABLE_TYPES, true ) ) {
				continue;
			}
			$fields[] = array(
				'api_name' => (string) $f['api_name'],
				'label'    => (string) ( $f['field_label'] ?? $f['display_label'] ?? $f['api_name'] ),
				'type'     => (string) ( $f['data_type'] ?? 'text' ),
				'required' => ! empty( $f['system_mandatory'] ) || 'Last_Name' === $f['api_name'],
			);
		}
		usort(
			$fields,
			static function ( $a, $b ) {
				return $b['required'] <=> $a['required'] ?: strcasecmp( $a['label'], $b['label'] );
			}
		);
		set_transient( self::FIELDS_TRANSIENT, $fields, 10 * MINUTE_IN_SECONDS );
		return $fields;
	}

	/* ------------------------------------------------------------------ leads */

	/**
	 * Build the lead record from submitted values.
	 *
	 * @param Form  $form    Form.
	 * @param array $values  Clean submitted values.
	 * @param array $entries Entries (for the Description summary).
	 * @return array
	 */
	public function build_lead( Form $form, array $values, array $entries ) {
		$settings = $form->zoho;
		$record   = array();
		foreach ( $settings['field_map'] as $map ) {
			$value = $values[ $map['field'] ] ?? null;
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			if ( self::FULL_NAME === $map['zoho'] ) {
				$parts = preg_split( '/\s+/', trim( (string) $value ) );
				$last  = array_pop( $parts );
				if ( $parts ) {
					$record['First_Name'] = $record['First_Name'] ?? implode( ' ', $parts );
				}
				$record['Last_Name'] = $record['Last_Name'] ?? $last;
				continue;
			}
			$record[ $map['zoho'] ] = self::convert( $value, $map['type'] );
		}

		if ( '' !== $settings['lead_source'] && ! isset( $record['Lead_Source'] ) ) {
			$record['Lead_Source'] = $settings['lead_source'];
		}
		if ( $settings['description_all_fields'] ) {
			$summary               = trim( Notifications::all_fields_text( $entries ) );
			$record['Description'] = isset( $record['Description'] ) ? $record['Description'] . "\n\n" . $summary : $summary;
		}
		// Zoho requires a last name; don't lose the lead when the form has none.
		if ( empty( $record['Last_Name'] ) ) {
			$record['Last_Name'] = ! empty( $record['Email'] ) ? (string) $record['Email'] : __( 'Website enquiry', 'launch-interactive-forms' );
		}

		/**
		 * Filters the Zoho CRM lead before it's sent.
		 *
		 * @param array $record Lead fields keyed by Zoho API name.
		 * @param Form  $form   Form.
		 * @param array $values Submitted values.
		 */
		return (array) apply_filters( 'liforms_zoho_lead', $record, $form, $values );
	}

	/**
	 * Convert a submitted value to what a Zoho field type expects.
	 *
	 * @param string|array $value Value.
	 * @param string       $type  Zoho data type ('' when unknown).
	 * @return mixed
	 */
	public static function convert( $value, $type ) {
		switch ( $type ) {
			case 'multiselectpicklist':
				return array_values( array_map( 'strval', (array) $value ) );
			case 'boolean':
				return '' !== ( is_array( $value ) ? implode( '', $value ) : (string) $value );
			case 'integer':
			case 'bigint':
				return (int) $value;
			case 'double':
			case 'currency':
			case 'decimal':
			case 'percent':
				return (float) $value;
		}
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * Create (or update) the lead for a submission.
	 *
	 * @param Form  $form    Form.
	 * @param array $values  Clean submitted values.
	 * @param array $entries Entries.
	 * @return string|null|\WP_Error Log message on success, null when skipped, WP_Error on failure.
	 */
	public function create_lead( Form $form, array $values, array $entries ) {
		$settings = $form->zoho;
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}
		if ( '' !== $settings['consent_field'] && empty( $values[ $settings['consent_field'] ] ) ) {
			return null; // Visitor did not agree.
		}

		$record = $this->build_lead( $form, $values, $entries );
		$body   = array( 'data' => array( $record ) );
		if ( ! $settings['run_workflows'] ) {
			$body['trigger'] = array();
		}
		$upsert = $settings['update_existing'] && ! empty( $record['Email'] );
		if ( $upsert ) {
			$body['duplicate_check_fields'] = array( 'Email' );
		}

		$result = $this->request( 'POST', $upsert ? 'Leads/upsert' : 'Leads', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row = $result['data']['data'][0] ?? null;
		if ( ! is_array( $row ) || 'success' !== ( $row['status'] ?? '' ) ) {
			return self::api_error( $result );
		}
		$id = (string) ( $row['details']['id'] ?? '' );
		return 'update' === ( $row['action'] ?? '' )
			/* translators: %s: Zoho record ID. */
			? sprintf( __( 'Lead updated (%s)', 'launch-interactive-forms' ), $id )
			/* translators: %s: Zoho record ID. */
			: sprintf( __( 'Lead created (%s)', 'launch-interactive-forms' ), $id );
	}

	/**
	 * Turn a failed API response into a readable error.
	 *
	 * @param array $result {code, data}.
	 * @return \WP_Error
	 */
	private static function api_error( array $result ) {
		$row    = $result['data']['data'][0] ?? $result['data'];
		$code   = is_array( $row ) ? (string) ( $row['code'] ?? '' ) : '';
		$detail = is_array( $row ) ? (string) ( $row['message'] ?? '' ) : '';
		if ( is_array( $row ) && ! empty( $row['details']['api_name'] ) ) {
			$detail .= ' (' . $row['details']['api_name'] . ')';
		}
		/* translators: 1: HTTP status, 2: Zoho error code and message. */
		return new \WP_Error( 'liforms_zoho_api', trim( sprintf( __( 'Zoho CRM error %1$d: %2$s', 'launch-interactive-forms' ), $result['code'], trim( $code . ' ' . $detail ) ) ) );
	}
}
