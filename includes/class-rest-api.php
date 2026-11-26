<?php
/**
 * REST endpoints: public submission endpoint and admin helpers.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * REST API.
 */
class Rest_Api {

	const NS = 'liforms/v1';

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true', // Public form; protected by honeypot / captcha.
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/forms/(?P<id>\d+)/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					$post = get_post( (int) $request['id'] );
					return $post && Forms::POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID );
				},
				'args'                => array(
					'id'        => array(
						'type'     => 'integer',
						'required' => true,
					),
					'config'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'className' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/mailchimp/lists',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mailchimp_lists' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/zoho/fields',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'zoho_fields' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
	}

	/**
	 * Render a form from the block editor's unsaved configuration, so the block preview
	 * shows changes before the page is saved. Nothing is stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$form  = new Form( $id, get_the_title( $id ), (string) $request['config'] );
		$class = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', 'wp-block-liforms-form ' . $request['className'] ) ) ) );
		return rest_ensure_response( array( 'html' => $this->plugin->renderer->render( $form, array( 'class' => $class ) ) ) );
	}

	/**
	 * Handle a JavaScript submission.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit( \WP_REST_Request $request ) {
		$form = $this->plugin->forms->get( (int) $request['id'] );
		if ( ! $form ) {
			return new \WP_Error( 'liforms_form_not_found', __( 'Form not found.', 'launch-interactive-forms' ), array( 'status' => 404 ) );
		}

		$files  = $request->get_file_params();
		$result = $this->plugin->processor->process(
			$form,
			(array) $request->get_body_params(),
			Uploads::normalize_files( $files['lif'] ?? null ),
			array(
				'page_url' => esc_url_raw( (string) $request->get_header( 'referer' ) ),
				'ip'       => Frontend::client_ip(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'      => $result->success,
				'message'      => $result->message,
				'message_html' => $result->success ? $this->plugin->renderer->success_message( $form, $result ) : '',
				'errors'       => (object) $result->errors,
				'redirect'     => $result->success ? $result->redirect : '',
				'hide_form'    => (bool) $form->settings['hide_form_on_success'],
			),
			$result->status ? $result->status : ( $result->success ? 200 : 422 )
		);
	}

	/**
	 * Mailchimp audiences for the builder.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mailchimp_lists() {
		$lists = $this->plugin->processor->mailchimp()->get_lists();
		if ( is_wp_error( $lists ) ) {
			$lists->add_data( array( 'status' => 400 ) );
			return $lists;
		}
		return rest_ensure_response( $lists );
	}

	/**
	 * Zoho CRM lead fields for the builder.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function zoho_fields() {
		$fields = $this->plugin->zoho->lead_fields();
		if ( is_wp_error( $fields ) ) {
			$fields->add_data( array( 'status' => 400 ) );
			return $fields;
		}
		return rest_ensure_response( $fields );
	}
}
