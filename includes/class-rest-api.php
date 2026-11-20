<?php
/**
 * REST endpoints: public submission endpoint and admin helpers.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * REST API.
 */
class Rest_Api {

	const NS = 'launch-forms/v1';

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
			'/mailchimp/lists',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'mailchimp_lists' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);
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
			return new \WP_Error( 'lf_form_not_found', __( 'Form not found.', 'launch-forms' ), array( 'status' => 404 ) );
		}

		$files  = $request->get_file_params();
		$result = $this->plugin->processor->process(
			$form,
			(array) $request->get_body_params(),
			Uploads::normalize_files( $files['lf'] ?? null ),
			array(
				'page_url' => esc_url_raw( (string) $request->get_header( 'referer' ) ),
				'ip'       => Frontend::client_ip(),
			)
		);

		return new \WP_REST_Response(
			array(
				'success'      => $result->success,
				'message'      => $result->message,
				'message_html' => $result->success ? $this->plugin->renderer->success_message( $form ) : '',
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
}
