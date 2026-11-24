<?php
/**
 * Mailchimp signup action (Marketing API v3).
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Mailchimp client.
 */
class Mailchimp {

	/** @var string */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key in the form "xxxx-us21".
	 */
	public function __construct( $api_key ) {
		$this->api_key = trim( (string) $api_key );
	}

	/**
	 * Data centre suffix from the key ("us21"), or '' if malformed.
	 *
	 * @return string
	 */
	public function datacenter() {
		if ( ! preg_match( '/-([a-z]+[0-9]+)$/', $this->api_key, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * API base URL.
	 *
	 * @return string
	 */
	public function base_url() {
		return 'https://' . $this->datacenter() . '.api.mailchimp.com/3.0/';
	}

	/**
	 * Subscribe (or update) the submitter.
	 *
	 * @param Form  $form   Form.
	 * @param array $values Clean submitted values.
	 * @return true|null|\WP_Error True on success, null when skipped, WP_Error on failure.
	 */
	public function subscribe( Form $form, array $values ) {
		$settings = $form->mailchimp;
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}
		if ( '' !== $settings['consent_field'] && empty( $values[ $settings['consent_field'] ] ) ) {
			return null; // Visitor did not opt in.
		}
		if ( '' === $this->datacenter() ) {
			return new \WP_Error( 'liforms_mailchimp_key', __( 'Mailchimp API key is missing or invalid.', 'launch-interactive-forms' ) );
		}
		if ( '' === $settings['list_id'] ) {
			return new \WP_Error( 'liforms_mailchimp_list', __( 'No Mailchimp audience selected.', 'launch-interactive-forms' ) );
		}
		$email = isset( $values[ $settings['email_field'] ] ) ? sanitize_email( (string) $values[ $settings['email_field'] ] ) : '';
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'liforms_mailchimp_email', __( 'No valid email address to subscribe.', 'launch-interactive-forms' ) );
		}

		$merge = array();
		foreach ( $settings['merge_fields'] as $map ) {
			if ( isset( $values[ $map['field'] ] ) ) {
				$value                = $values[ $map['field'] ];
				$merge[ $map['tag'] ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		$body = array(
			'email_address' => $email,
			'status_if_new' => $settings['double_optin'] ? 'pending' : 'subscribed',
		);
		if ( $merge ) {
			$body['merge_fields'] = $merge;
		}

		/**
		 * Filters the Mailchimp member payload.
		 *
		 * @param array $body   Payload.
		 * @param Form  $form   Form.
		 * @param array $values Values.
		 */
		$body = apply_filters( 'liforms_mailchimp_member', $body, $form, $values );

		$hash   = md5( strtolower( $email ) );
		$member = 'lists/' . rawurlencode( $settings['list_id'] ) . '/members/' . $hash;
		$result = $this->request( 'PUT', $member, $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tags = array_filter( array_map( 'trim', explode( ',', $settings['tags'] ) ), 'strlen' );
		if ( $tags ) {
			$result = $this->request(
				'POST',
				$member . '/tags',
				array(
					'tags' => array_map(
						static function ( $tag ) {
							return array(
								'name'   => $tag,
								'status' => 'active',
							);
						},
						array_values( $tags )
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Fetch audiences for the builder dropdown.
	 *
	 * @return array|\WP_Error List of {id, name}.
	 */
	public function get_lists() {
		if ( '' === $this->datacenter() ) {
			return new \WP_Error( 'liforms_mailchimp_key', __( 'Mailchimp API key is missing or invalid.', 'launch-interactive-forms' ) );
		}
		$data = $this->request( 'GET', 'lists?count=1000&fields=lists.id,lists.name' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$lists = array();
		foreach ( (array) ( $data['lists'] ?? array() ) as $list ) {
			$lists[] = array(
				'id'   => (string) $list['id'],
				'name' => (string) $list['name'],
			);
		}
		return $lists;
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to /3.0/.
	 * @param array|null $body   JSON body.
	 * @return array|\WP_Error Decoded response.
	 */
	public function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'launchforms:' . $this->api_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) ? ( $data['detail'] ?? $data['title'] ?? '' ) : '';
			/* translators: 1: HTTP status, 2: Mailchimp error detail. */
			return new \WP_Error( 'liforms_mailchimp_http', trim( sprintf( __( 'Mailchimp error %1$d: %2$s', 'launch-interactive-forms' ), $code, $detail ) ) );
		}
		return is_array( $data ) ? $data : array();
	}
}
