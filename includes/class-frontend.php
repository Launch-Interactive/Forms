<?php
/**
 * Front-end integration: assets, shortcode, block and the no-JavaScript POST fallback.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end hooks.
 */
class Frontend {

	const SHORTCODE = 'liforms';

	/** Transient prefix for filled-in success messages carried across the no-JS redirect. */
	const MESSAGE_TRANSIENT = 'liforms_msg_';

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
		add_action( 'init', array( $this, 'register_assets' ), 5 );
		add_action( 'init', array( $this, 'register_block' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		// template_redirect (not wp_loaded/init): REST and admin-ajax requests never reach it, so
		// JavaScript submissions to the REST endpoint are not also handled here.
		add_action( 'template_redirect', array( $this, 'handle_post' ), 1 );
	}

	/**
	 * Register scripts and styles.
	 */
	public function register_assets() {
		$ver = LIFORMS_VERSION;
		$css = $this->plugin->settings->get( 'load_css' ) ? LIFORMS_URL . 'assets/css/liforms.css' : false;
		wp_register_style( 'liforms', $css, array(), $ver );

		// The conditional logic script is added as a dependency only when a form on the page uses it (see enqueue_assets()).
		wp_register_script( 'liforms-conditional', LIFORMS_URL . 'assets/js/conditional-logic.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_register_script( 'liforms', LIFORMS_URL . 'assets/js/frontend.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'liforms',
			'liformsConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'liforms/v1/forms/' ) ),
				'i18n'    => array(
					'error' => __( 'Something went wrong. Please try again.', 'launch-interactive-forms' ),
				),
			)
		);

		wp_register_script( 'liforms-builder', LIFORMS_URL . 'assets/js/builder.js', array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-a11y' ), $ver, true );
		wp_register_style( 'liforms-builder', LIFORMS_URL . 'assets/css/builder.css', array( 'wp-components', 'dashicons' ), $ver ); // Dashicons: the block editor canvas iframe doesn't load them.
		wp_register_script(
			'liforms-block-editor',
			LIFORMS_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-core-data', 'wp-server-side-render', 'wp-i18n', 'wp-api-fetch', 'liforms-builder' ),
			$ver,
			true
		);
		wp_set_script_translations( 'liforms-builder', 'launch-interactive-forms' );
		wp_set_script_translations( 'liforms-block-editor', 'launch-interactive-forms' );
	}

	/**
	 * Register the block (server-rendered so every page always shows the current form).
	 */
	public function register_block() {
		register_block_type(
			LIFORMS_DIR . 'blocks/form',
			array( 'render_callback' => array( $this, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		if ( ! $id ) {
			return '';
		}
		$class = trim( 'wp-block-liforms-form ' . ( isset( $attributes['className'] ) ? (string) $attributes['className'] : '' ) );
		return $this->plugin->renderer->render_id( $id, array( 'class' => $class ) );
	}

	/**
	 * [liforms id="123" class="optional"] shortcode for classic content and page builders.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'class' => '',
			),
			$atts,
			self::SHORTCODE
		);
		$class = implode( ' ', array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) ) );
		return $this->plugin->renderer->render_id( absint( $atts['id'] ), array( 'class' => $class ) );
	}

	/**
	 * Enqueue front-end assets for a rendered form.
	 *
	 * @param Form $form Form.
	 */
	public function enqueue_assets( Form $form ) {
		if ( ! wp_script_is( 'liforms', 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( 'liforms' );
		wp_enqueue_script( 'liforms' );
		if ( $form->has_conditions() ) {
			// Make frontend.js depend on the conditional logic script, so it's printed (and run) first.
			$script = wp_scripts()->query( 'liforms', 'registered' );
			if ( $script && ! in_array( 'liforms-conditional', $script->deps, true ) ) {
				$script->deps[] = 'liforms-conditional';
			}
		}
		if ( is_user_logged_in() ) {
			wp_add_inline_script( 'liforms', 'window.liformsConfig&&(window.liformsConfig.nonce=' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ');', 'before' );
		}
	}

	/**
	 * Handle a classic (no JavaScript) POST submission.
	 */
	public function handle_post() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$redirect = $this->process_post( $_POST, $_FILES ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Process POST data. Returns a redirect URL on success, null otherwise (errors are rendered
	 * inline by the renderer later in the same request).
	 *
	 * @param array $post  Raw (slashed) $_POST.
	 * @param array $files Raw $_FILES.
	 * @return string|null
	 */
	public function process_post( array $post, array $files ) {
		if ( empty( $post['lif_form_id'] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return null;
		}
		$form = $this->plugin->forms->get( absint( $post['lif_form_id'] ) );
		if ( ! $form ) {
			return null;
		}

		$current = self::current_url();
		$result  = $this->plugin->processor->process(
			$form,
			wp_unslash( $post ),
			Uploads::normalize_files( $files['lif'] ?? null ),
			array(
				'page_url' => remove_query_arg( array( 'lif_sent', 'lif_msg', 'lif_test' ), $current ),
				'ip'       => self::client_ip(),
				'user_id'  => self::verified_user_id( $form->id, $post ),
				'monitor'  => Monitor::from_server(),
			)
		);
		if ( ! $result->success ) {
			return null;
		}

		if ( '' !== $result->redirect ) {
			// Admin-configured redirect: allow its host for this redirect only.
			$host = wp_parse_url( $result->redirect, PHP_URL_HOST );
			if ( $host ) {
				add_filter(
					'allowed_redirect_hosts',
					static function ( $hosts ) use ( $host ) {
						$hosts[] = $host;
						return $hosts;
					}
				);
			}
			return $result->redirect;
		}
		$args = array( 'lif_sent' => $form->id );
		if ( null !== $result->monitor ) {
			$args['lif_test'] = 1; // Automated test: the success page skips the analytics event.
		}
		if ( false !== strpos( $form->settings['success_message'], '{' ) && ! $result->spam ) {
			// The message uses merge tags: keep the filled-in version for a few minutes under an
			// unguessable token, since the submitted values are gone after the redirect.
			$token = bin2hex( random_bytes( 16 ) );
			set_transient(
				self::MESSAGE_TRANSIENT . $token,
				array(
					'form' => $form->id,
					'html' => $this->plugin->renderer->success_message( $form, $result ),
				),
				10 * MINUTE_IN_SECONDS
			);
			$args['lif_msg'] = $token;
		}
		return add_query_arg( $args, remove_query_arg( array( 'lif_sent', 'lif_msg', 'lif_test' ), $current ) ) . '#lif-form-' . $form->id;
	}

	/**
	 * The logged-in user to credit a plain POST submission to. Another site can make a visitor's
	 * browser post a form (with their cookies), so the user is only recorded when the form carried
	 * a nonce issued to them; otherwise the submission is anonymous.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $post    Raw POST data.
	 * @return int
	 */
	public static function verified_user_id( $form_id, array $post ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return 0;
		}
		$nonce = isset( $post['lif_nonce'] ) && is_string( $post['lif_nonce'] ) ? sanitize_key( $post['lif_nonce'] ) : '';
		return wp_verify_nonce( $nonce, 'liforms_submit_' . (int) $form_id ) ? $user_id : 0;
	}

	/**
	 * URL of the current request.
	 *
	 * @return string
	 */
	public static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/**
	 * Visitor IP (passed to captcha providers and hashed for rate limiting; never stored).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limiting and captcha checks. Only use a forwarded
		 * header (e.g. CF-Connecting-IP) here if the site really is behind that proxy, as
		 * visitors can set those headers themselves.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'liforms_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
