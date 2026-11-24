<?php
/**
 * Admin screens: form builder screen, settings page and test email tool.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Admin {

	const SETTINGS_PAGE = 'launch-forms-settings';

	/** @var Plugin */
	private $plugin;

	/** @var string Hook suffix of the settings page. */
	private $settings_hook = '';

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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'add_meta_boxes_' . Forms::POST_TYPE, array( $this, 'meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor' ) );
		add_action( 'admin_post_launch_forms_test_email', array( $this, 'handle_test_email' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health_tests' ) );
		add_filter( 'manage_' . Forms::POST_TYPE . '_posts_columns', array( $this, 'form_columns' ) );
		add_action( 'manage_' . Forms::POST_TYPE . '_posts_custom_column', array( $this, 'form_column' ), 10, 2 );
	}

	/**
	 * Settings submenu.
	 */
	public function menu() {
		$this->settings_hook = (string) add_submenu_page(
			'edit.php?post_type=' . Forms::POST_TYPE,
			__( 'Form Settings', 'launch-interactive-forms' ),
			__( 'Settings', 'launch-interactive-forms' ),
			Plugin::manage_capability(),
			self::SETTINGS_PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Data the builder needs (used on the Forms screen and in the block editor).
	 *
	 * @return array
	 */
	public function builder_data() {
		return array(
			'fieldTypes'          => Form_Config::field_type_labels(),
			'optionTypes'         => Form_Config::OPTION_TYPES,
			'widths'              => Form_Config::width_labels(),
			'countries'           => Countries::names(),
			'operators'           => array(
				'is'           => __( 'is', 'launch-interactive-forms' ),
				'is_not'       => __( 'is not', 'launch-interactive-forms' ),
				'contains'     => __( 'contains', 'launch-interactive-forms' ),
				'not_contains' => __( 'does not contain', 'launch-interactive-forms' ),
				'empty'        => __( 'is empty', 'launch-interactive-forms' ),
				'not_empty'    => __( 'is not empty', 'launch-interactive-forms' ),
				'greater_than' => __( 'is greater than', 'launch-interactive-forms' ),
				'less_than'    => __( 'is less than', 'launch-interactive-forms' ),
			),
			'defaults'            => Form_Config::defaults(),
			'defaultFileTypes'    => Form_Config::DEFAULT_FILE_TYPES,
			'blockedFileTypes'    => Uploads::BLOCKED_EXTENSIONS,
			'recaptchaVersion'    => (string) $this->plugin->settings->get( 'recaptcha_version' ),
			'captcha'             => array(
				'recaptcha' => $this->plugin->captcha->is_configured( 'recaptcha' ),
				'turnstile' => $this->plugin->captcha->is_configured( 'turnstile' ),
			),
			'mailchimpConfigured' => '' !== (string) $this->plugin->settings->get( 'mailchimp_api_key' ),
			'settingsUrl'         => current_user_can( Plugin::manage_capability() ) ? admin_url( 'edit.php?post_type=' . Forms::POST_TYPE . '&page=' . self::SETTINGS_PAGE ) : '',
			'formsUrl'            => admin_url( 'edit.php?post_type=' . Forms::POST_TYPE ),
		);
	}

	/**
	 * Expose builder data to the builder script.
	 */
	private function localize_builder() {
		wp_add_inline_script( 'launch-forms-builder', 'window.launchFormsBuilderData = ' . wp_json_encode( $this->builder_data() ) . ';', 'before' );
	}

	/**
	 * Enqueue the builder on the form edit screen.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( '' !== $this->settings_hook && $this->settings_hook === $hook ) {
			wp_enqueue_script( 'launch-forms-settings', LAUNCH_FORMS_URL . 'assets/js/settings.js', array(), LAUNCH_FORMS_VERSION, true );
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || Forms::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'launch-forms-admin', LAUNCH_FORMS_URL . 'assets/js/admin.js', array( 'launch-forms-builder' ), LAUNCH_FORMS_VERSION, true );
		wp_enqueue_style( 'launch-forms-builder' );
		$this->localize_builder();
	}

	/**
	 * Builder data and styles for the block editor.
	 */
	public function enqueue_block_editor() {
		wp_enqueue_style( 'launch-forms-builder' );
		$this->localize_builder();
	}

	/**
	 * Builder and usage boxes on the form edit screen.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function meta_boxes( $post ) {
		remove_meta_box( 'postcustom', Forms::POST_TYPE, 'normal' );
		remove_meta_box( 'slugdiv', Forms::POST_TYPE, 'normal' );
		add_meta_box( 'launch-forms-builder', __( 'Form builder', 'launch-interactive-forms' ), array( $this, 'render_builder_box' ), Forms::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'launch-forms-usage', __( 'Use this form', 'launch-interactive-forms' ), array( $this, 'render_usage_box' ), Forms::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Builder mount point + hidden input holding the JSON config.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_builder_box( $post ) {
		$config = Form_Config::from_json( get_post_meta( $post->ID, Forms::META_KEY, true ) );
		wp_nonce_field( 'launch_forms_builder_' . $post->ID, 'launch_forms_builder_nonce' );
		echo '<textarea name="' . esc_attr( Forms::META_KEY ) . '" id="launch-forms-config" hidden>' . esc_textarea( wp_json_encode( $config ) ) . '</textarea>';
		echo '<div id="launch-forms-builder-root"><p>' . esc_html__( 'Loading builder…', 'launch-interactive-forms' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'The form builder requires JavaScript.', 'launch-interactive-forms' ) . '</p></noscript>';
	}

	/**
	 * Shortcode / block usage hints.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_usage_box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Publish the form to get its shortcode.', 'launch-interactive-forms' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Block editor: add the "Form" block and pick this form.', 'launch-interactive-forms' ) . '</p>';
		echo '<p>' . esc_html__( 'Shortcode (Elementor, classic editor, widgets):', 'launch-interactive-forms' ) . '</p>';
		echo '<p><input type="text" readonly class="widefat code" onclick="this.select()" value="' . esc_attr( '[' . Frontend::SHORTCODE . ' id="' . $post->ID . '"]' ) . '"></p>';
		echo '<p>' . esc_html__( 'Changes apply everywhere the form is used.', 'launch-interactive-forms' ) . '</p>';
	}

	/**
	 * Extra list columns on the Forms screen.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function form_columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['lf_shortcode']   = __( 'Shortcode', 'launch-interactive-forms' );
		$columns['lf_submissions'] = __( 'Submissions', 'launch-interactive-forms' );
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public function form_column( $column, $post_id ) {
		if ( 'lf_shortcode' === $column ) {
			echo '<code>' . esc_html( '[' . Frontend::SHORTCODE . ' id="' . $post_id . '"]' ) . '</code>';
		}
		if ( 'lf_submissions' === $column ) {
			$count = $this->plugin->submissions->count_for_form( $post_id );
			if ( current_user_can( Plugin::manage_capability() ) ) {
				echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Submissions::POST_TYPE . '&lf_form=' . $post_id ) ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
			} else {
				echo esc_html( number_format_i18n( $count ) );
			}
		}
	}

	/**
	 * Settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( Plugin::manage_capability() ) ) {
			return;
		}
		$s      = $this->plugin->settings->all();
		$name   = static function ( $key ) {
			return Settings::OPTION . '[' . $key . ']';
		};
		$notice = get_transient( 'launch_forms_test_email_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'launch_forms_test_email_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form Settings', 'launch-interactive-forms' ); ?></h1>
			<?php settings_errors(); // Shows "Settings saved." (only automatic on Settings menu pages). ?>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo $notice['success'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'launch_forms' ); ?>

				<h2><?php esc_html_e( 'General', 'launch-interactive-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Structural CSS', 'launch-interactive-forms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'load_css' ) ); ?>" value="1" <?php checked( $s['load_css'] ); ?>> <?php esc_html_e( 'Load the small layout stylesheet (forms otherwise rely entirely on your theme)', 'launch-interactive-forms' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-required-marker"><?php esc_html_e( 'Required field marker', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-required-marker" class="regular-text" type="text" maxlength="50" name="<?php echo esc_attr( $name( 'required_marker' ) ); ?>" value="<?php echo esc_attr( $s['required_marker'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown after the label of required fields on every form, e.g. * or (required). Leave empty to show nothing; browsers and screen readers still know the field is required.', 'launch-interactive-forms' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'launch-interactive-forms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'delete_data_on_uninstall' ) ); ?>" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all forms, submissions and uploaded files when the plugin is deleted', 'launch-interactive-forms' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Google reCAPTCHA', 'launch-interactive-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lf-rc-version"><?php esc_html_e( 'Version', 'launch-interactive-forms' ); ?></label></th>
						<td><select id="lf-rc-version" name="<?php echo esc_attr( $name( 'recaptcha_version' ) ); ?>">
							<option value="v2" <?php selected( $s['recaptcha_version'], 'v2' ); ?>><?php esc_html_e( 'v2 checkbox', 'launch-interactive-forms' ); ?></option>
							<option value="v3" <?php selected( $s['recaptcha_version'], 'v3' ); ?>><?php esc_html_e( 'v3 invisible (score)', 'launch-interactive-forms' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-rc-site"><?php esc_html_e( 'Site key', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-rc-site" class="regular-text" type="text" name="<?php echo esc_attr( $name( 'recaptcha_site_key' ) ); ?>" value="<?php echo esc_attr( $s['recaptcha_site_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-rc-secret"><?php esc_html_e( 'Secret key', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-rc-secret" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name( 'recaptcha_secret_key' ) ); ?>" value="<?php echo esc_attr( $s['recaptcha_secret_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-rc-threshold"><?php esc_html_e( 'v3 score threshold', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-rc-threshold" type="number" min="0" max="1" step="0.1" name="<?php echo esc_attr( $name( 'recaptcha_threshold' ) ); ?>" value="<?php echo esc_attr( $s['recaptcha_threshold'] ); ?>">
						<p class="description"><?php esc_html_e( 'Submissions scoring below this are rejected (0 = bot, 1 = human).', 'launch-interactive-forms' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cloudflare Turnstile', 'launch-interactive-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lf-ts-site"><?php esc_html_e( 'Site key', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-ts-site" class="regular-text" type="text" name="<?php echo esc_attr( $name( 'turnstile_site_key' ) ); ?>" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-ts-secret"><?php esc_html_e( 'Secret key', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-ts-secret" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name( 'turnstile_secret_key' ) ); ?>" value="<?php echo esc_attr( $s['turnstile_secret_key'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Mailchimp', 'launch-interactive-forms' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lf-mc-key"><?php esc_html_e( 'API key', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-mc-key" class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr( $name( 'mailchimp_api_key' ) ); ?>" value="<?php echo esc_attr( $s['mailchimp_api_key'] ); ?>">
						<p class="description"><?php esc_html_e( 'Mailchimp → Profile → Extras → API keys. Choose the audience per form in the form builder.', 'launch-interactive-forms' ); ?></p></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Any setting can also be defined in wp-config.php, e.g. define( \'LAUNCH_FORMS_MAILCHIMP_API_KEY\', \'...\' );', 'launch-interactive-forms' ); ?></p>

				<h2 id="lf-smtp"><?php esc_html_e( 'Email (SMTP)', 'launch-interactive-forms' ); ?></h2>
				<p><?php esc_html_e( 'Send all WordPress email (form notifications, password resets, everything) through your email provider\'s SMTP server instead of the web server. This usually stops emails going missing or landing in spam.', 'launch-interactive-forms' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom SMTP', 'launch-interactive-forms' ); ?></th>
						<td><label><input type="checkbox" id="lf-smtp-enabled" name="<?php echo esc_attr( $name( 'smtp_enabled' ) ); ?>" value="1" aria-controls="lf-smtp-details" <?php checked( $s['smtp_enabled'] ); ?>> <?php esc_html_e( 'Use Custom SMTP', 'launch-interactive-forms' ); ?></label></td>
					</tr>
				</table>
				<div id="lf-smtp-details"<?php echo $s['smtp_enabled'] ? '' : ' hidden'; ?>>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lf-smtp-host"><?php esc_html_e( 'SMTP host', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-smtp-host" class="regular-text" type="text" name="<?php echo esc_attr( $name( 'smtp_host' ) ); ?>" value="<?php echo esc_attr( $s['smtp_host'] ); ?>" placeholder="smtp.example.com" autocomplete="off" spellcheck="false"></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-encryption"><?php esc_html_e( 'Encryption', 'launch-interactive-forms' ); ?></label></th>
						<td><select id="lf-smtp-encryption" name="<?php echo esc_attr( $name( 'smtp_encryption' ) ); ?>">
							<?php foreach ( Settings::smtp_encryptions() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['smtp_encryption'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-port"><?php esc_html_e( 'Port', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-smtp-port" class="small-text" type="number" min="1" max="65535" name="<?php echo esc_attr( $name( 'smtp_port' ) ); ?>" value="<?php echo esc_attr( $s['smtp_port'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Authentication', 'launch-interactive-forms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'smtp_auth' ) ); ?>" value="1" <?php checked( $s['smtp_auth'] ); ?>> <?php esc_html_e( 'The server needs a username and password (almost always on)', 'launch-interactive-forms' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-user"><?php esc_html_e( 'Username', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-smtp-user" class="regular-text" type="text" name="<?php echo esc_attr( $name( 'smtp_username' ) ); ?>" value="<?php echo esc_attr( $s['smtp_username'] ); ?>" autocomplete="off" spellcheck="false">
						<p class="description"><?php esc_html_e( 'Usually your full email address.', 'launch-interactive-forms' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-pass"><?php esc_html_e( 'Password', 'launch-interactive-forms' ); ?></label></th>
						<td>
							<?php if ( defined( 'LAUNCH_FORMS_SMTP_PASSWORD' ) ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (LAUNCH_FORMS_SMTP_PASSWORD).', 'launch-interactive-forms' ); ?></p>
							<?php else : ?>
								<input id="lf-smtp-pass" class="regular-text" type="password" name="<?php echo esc_attr( $name( 'smtp_password_new' ) ); ?>" value="" autocomplete="new-password" placeholder="<?php echo $this->plugin->settings->has_smtp_password() ? esc_attr__( 'Saved. Leave blank to keep it.', 'launch-interactive-forms' ) : ''; ?>">
								<?php if ( $this->plugin->settings->has_smtp_password() ) : ?>
									<p><label><input type="checkbox" name="<?php echo esc_attr( $name( 'smtp_password_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Remove the saved password', 'launch-interactive-forms' ); ?></label></p>
									<?php if ( '' === $this->plugin->settings->smtp_password() ) : ?>
										<p class="description" style="color:#b32d2e"><?php esc_html_e( 'The saved password can no longer be read (the security keys in wp-config.php have changed). Please enter it again.', 'launch-interactive-forms' ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Stored encrypted and never shown again. With Gmail or Microsoft 365 you may need an app password.', 'launch-interactive-forms' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-from"><?php esc_html_e( 'From email', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-smtp-from" class="regular-text" type="email" name="<?php echo esc_attr( $name( 'smtp_from_email' ) ); ?>" value="<?php echo esc_attr( $s['smtp_from_email'] ); ?>" placeholder="<?php echo esc_attr( $this->plugin->test_email->from_address() ); ?>">
						<p class="description"><?php esc_html_e( 'Use an address your SMTP account is allowed to send from, otherwise the server may reject emails or they may be marked as spam.', 'launch-interactive-forms' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="lf-smtp-from-name"><?php esc_html_e( 'From name', 'launch-interactive-forms' ); ?></label></th>
						<td><input id="lf-smtp-from-name" class="regular-text" type="text" name="<?php echo esc_attr( $name( 'smtp_from_name' ) ); ?>" value="<?php echo esc_attr( $s['smtp_from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force From', 'launch-interactive-forms' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'smtp_force_from' ) ); ?>" value="1" <?php checked( $s['smtp_force_from'] ); ?>> <?php esc_html_e( 'Use this From email and name on every email, even when a plugin or form notification sets its own', 'launch-interactive-forms' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended: most SMTP providers only accept their own addresses. Replies still go to the Reply-To address set in each notification.', 'launch-interactive-forms' ); ?></p></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Save, then use "Send a test email" below to check the settings.', 'launch-interactive-forms' ); ?></p>
				</div>


				<?php submit_button(); ?>
			</form>

			<hr>
			<h2 id="lf-test-email"><?php esc_html_e( 'Send a test email', 'launch-interactive-forms' ); ?></h2>
			<p><?php esc_html_e( 'Check that WordPress can send email before relying on form notifications.', 'launch-interactive-forms' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="launch_forms_test_email">
				<?php wp_nonce_field( 'launch_forms_test_email' ); ?>
				<label for="lf-test-to" class="screen-reader-text"><?php esc_html_e( 'Recipient', 'launch-interactive-forms' ); ?></label>
				<input id="lf-test-to" type="email" class="regular-text" name="to" required value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
				<?php submit_button( __( 'Send test email', 'launch-interactive-forms' ), 'secondary', 'submit', false ); ?>
				<p class="description">
					<?php
					/* translators: %s: from address. */
					echo esc_html( sprintf( __( 'Emails are sent from: %s', 'launch-interactive-forms' ), $this->plugin->test_email->from_address() ) );
					?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Register the Site Health check.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function site_health_tests( $tests ) {
		$tests['direct']['launch_forms_private_uploads'] = array(
			'label' => __( 'Launch Interactive Forms uploads are private', 'launch-interactive-forms' ),
			'test'  => array( $this, 'site_health_private_uploads' ),
		);
		return $tests;
	}

	/**
	 * Site Health: can uploaded form files be downloaded without logging in?
	 *
	 * @return array
	 */
	public function site_health_private_uploads() {
		$status = $this->plugin->uploads->public_access_status();
		$badge  = array(
			'label' => __( 'Security', 'launch-interactive-forms' ),
			'color' => 'blue',
		);
		$result = array(
			'label'       => __( 'Files uploaded through forms are not publicly accessible', 'launch-interactive-forms' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'Launch Interactive Forms requested a test file from its private upload folder and the web server refused it.', 'launch-interactive-forms' ) . '</p>',
			'actions'     => '',
			'test'        => 'launch_forms_private_uploads',
		);
		if ( 'outside' === $status ) {
			$result['description'] = '<p>' . esc_html__( 'Form uploads are stored outside the public web folder, so they cannot be downloaded directly.', 'launch-interactive-forms' ) . '</p>';
		} elseif ( 'exposed' === $status ) {
			$result['status']      = 'critical';
			$result['badge']['color'] = 'red';
			$result['label']       = __( 'Files uploaded through forms can be downloaded without logging in', 'launch-interactive-forms' );
			$result['description'] = '<p>' . esc_html__( 'Your web server ignores the .htaccess rules that protect the upload folder (common on nginx). Stored files have unguessable names, but they should still be blocked.', 'launch-interactive-forms' ) . '</p>'
				. '<p>' . esc_html__( 'Fix it by adding this rule to the nginx server block:', 'launch-interactive-forms' ) . '</p>'
				. '<p><code>location ~* /wp-content/uploads/launch-interactive-forms/ { deny all; }</code></p>'
				. '<p>' . esc_html__( 'Or move uploads outside the web folder by adding this to wp-config.php, with a folder of your choice:', 'launch-interactive-forms' ) . '</p>'
				. '<p><code>define( \'LAUNCH_FORMS_PRIVATE_DIR\', \'/path/outside/public_html/launch-forms\' );</code></p>';
		} elseif ( 'unknown' === $status ) {
			$result['status']      = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['label']       = __( 'Launch Interactive Forms could not check whether uploaded files are private', 'launch-interactive-forms' );
			$result['description'] = '<p>' . esc_html__( 'The site could not request a test file from itself (loopback requests may be blocked). If the site runs on nginx, make sure the launch-forms-private upload folder is denied, or set LAUNCH_FORMS_PRIVATE_DIR to a folder outside the web root.', 'launch-interactive-forms' ) . '</p>';
		}
		return $result;
	}

	/**
	 * Handle the test email form.
	 */
	public function handle_test_email() {
		if ( ! current_user_can( Plugin::manage_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'launch-interactive-forms' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'launch_forms_test_email' );
		$to     = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		$result = $this->plugin->test_email->send( $to );
		set_transient( 'launch_forms_test_email_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Forms::POST_TYPE . '&page=' . self::SETTINGS_PAGE . '#lf-test-email' ) );
		exit;
	}
}
