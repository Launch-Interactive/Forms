<?php
/**
 * @covers \LaunchInteractiveForms\Admin
 * @covers \LaunchInteractiveForms\Plugin
 */

use LaunchInteractiveForms\Forms;

class AdminTest extends LF_TestCase {

	public function test_post_type_registration() {
		$type = get_post_type_object( Forms::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertTrue( $type->show_ui );
		$this->assertTrue( $type->show_in_rest );
		$this->assertSame( 'liforms', $type->rest_base );
		$this->assertSame( 'edit_pages', $type->cap->edit_posts, 'Editors can build forms.' );
		$this->assertArrayHasKey( Forms::META_KEY, get_registered_meta_keys( 'post', Forms::POST_TYPE ) );
	}

	public function test_builder_data() {
		liforms()->settings->update( array( 'mailchimp_api_key' => 'x-us1', 'turnstile_site_key' => 'a', 'turnstile_secret_key' => 'b' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = liforms()->admin->builder_data();
		$this->assertArrayHasKey( 'file', $data['fieldTypes'] );
		$this->assertArrayHasKey( 'not_empty', $data['operators'] );
		$this->assertTrue( $data['mailchimpConfigured'] );
		$this->assertTrue( $data['captcha']['turnstile'] );
		$this->assertFalse( $data['captcha']['recaptcha'] );
		$this->assertContains( 'php', $data['blockedFileTypes'] );
		$this->assertStringContainsString( 'page=liforms-settings', $data['settingsUrl'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', liforms()->admin->builder_data()['settingsUrl'] );
	}

	public function test_builder_box_outputs_mount_point_nonce_and_config() {
		$form = $this->contact_form();
		ob_start();
		liforms()->admin->render_builder_box( get_post( $form->id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="liforms-builder-root"', $html );
		$this->assertStringContainsString( 'name="liforms_builder_nonce"', $html );
		$this->assertMatchesRegularExpression( '#<textarea name="liforms_config" id="liforms-config" hidden>(.+)</textarea>#s', $html );
		preg_match( '#hidden>(.+)</textarea>#s', $html, $m );
		$config = json_decode( html_entity_decode( $m[1] ), true );
		$this->assertSame( 'email', $config['fields'][1]['name'] );
	}

	public function test_usage_box_shows_shortcode() {
		$form = $this->contact_form();
		ob_start();
		liforms()->admin->render_usage_box( get_post( $form->id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( '[liforms id=&quot;' . $form->id . '&quot;]', $html );
	}

	public function test_form_list_columns() {
		$form = $this->contact_form();
		liforms()->submissions->create( $form, array() );
		$cols = liforms()->admin->form_columns( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );
		$this->assertSame( array( 'cb', 'title', 'liforms_shortcode', 'liforms_submissions', 'date' ), array_keys( $cols ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		liforms()->admin->form_column( 'liforms_shortcode', $form->id );
		liforms()->admin->form_column( 'liforms_submissions', $form->id );
		$html = ob_get_clean();
		$this->assertStringContainsString( '[liforms id=&quot;' . $form->id . '&quot;]', $html );
		$this->assertStringContainsString( '>1</a>', $html );
	}

	public function test_builder_scripts_registered_with_dependencies() {
		$scripts = wp_scripts();
		$this->assertContains( 'wp-components', $scripts->registered['liforms-builder']->deps );
		$this->assertContains( 'liforms-builder', $scripts->registered['liforms-block-editor']->deps );
		$this->assertContains( 'wp-core-data', $scripts->registered['liforms-block-editor']->deps );
		$this->assertContains( 'wp-server-side-render', $scripts->registered['liforms-block-editor']->deps );
	}

	public function test_block_editor_assets_inline_builder_data() {
		liforms()->admin->enqueue_block_editor();
		$before = implode( '', (array) wp_scripts()->get_data( 'liforms-builder', 'before' ) );
		$this->assertStringContainsString( 'window.liformsBuilderData', $before );
		$this->assertTrue( wp_style_is( 'liforms-builder', 'enqueued' ) );
	}

	public function test_settings_menu_registered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		liforms()->admin->menu();
		global $submenu;
		$slugs = wp_list_pluck( $submenu[ 'edit.php?post_type=' . Forms::POST_TYPE ] ?? array(), 2 );
		$this->assertContains( 'liforms-settings', $slugs );
		set_current_screen( 'front' );
	}

	public function test_activation_creates_protected_dir() {
		LaunchInteractiveForms\Plugin::activate();
		$this->assertFileExists( trailingslashit( $this->private_dir ) . '.htaccess' );
	}

	public function test_admin_post_actions_match_the_links_and_forms_that_use_them() {
		$this->assertNotFalse( has_action( 'admin_post_liforms_download' ) );
		$this->assertNotFalse( has_action( 'admin_post_nopriv_liforms_download' ) );
		$this->assertNotFalse( has_action( 'admin_post_liforms_test_email' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		liforms()->admin->render_settings_page();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="action" value="liforms_test_email"', $html );
		$this->assertStringContainsString( "name='option_page' value='liforms'", $html );
		liforms()->settings->register();
		$this->assertArrayHasKey( 'liforms', apply_filters( 'allowed_options', array() ), 'The settings form posts to the registered settings group.' );
	}
}
