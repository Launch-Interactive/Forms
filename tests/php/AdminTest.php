<?php
/**
 * @covers \LaunchForms\Admin
 * @covers \LaunchForms\Plugin
 */

use LaunchForms\Forms;

class AdminTest extends LF_TestCase {

	public function test_post_type_registration() {
		$type = get_post_type_object( Forms::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertTrue( $type->show_ui );
		$this->assertTrue( $type->show_in_rest );
		$this->assertSame( 'launch-forms', $type->rest_base );
		$this->assertSame( 'edit_pages', $type->cap->edit_posts, 'Editors can build forms.' );
		$this->assertArrayHasKey( Forms::META_KEY, get_registered_meta_keys( 'post', Forms::POST_TYPE ) );
	}

	public function test_builder_data() {
		launch_forms()->settings->update( array( 'mailchimp_api_key' => 'x-us1', 'turnstile_site_key' => 'a', 'turnstile_secret_key' => 'b' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = launch_forms()->admin->builder_data();
		$this->assertArrayHasKey( 'file', $data['fieldTypes'] );
		$this->assertArrayHasKey( 'not_empty', $data['operators'] );
		$this->assertTrue( $data['mailchimpConfigured'] );
		$this->assertTrue( $data['captcha']['turnstile'] );
		$this->assertFalse( $data['captcha']['recaptcha'] );
		$this->assertContains( 'php', $data['blockedFileTypes'] );
		$this->assertStringContainsString( 'page=launch-forms-settings', $data['settingsUrl'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', launch_forms()->admin->builder_data()['settingsUrl'] );
	}

	public function test_builder_box_outputs_mount_point_nonce_and_config() {
		$form = $this->contact_form();
		ob_start();
		launch_forms()->admin->render_builder_box( get_post( $form->id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="launch-forms-builder-root"', $html );
		$this->assertStringContainsString( 'name="launch_forms_builder_nonce"', $html );
		$this->assertMatchesRegularExpression( '#<textarea name="launch_forms_config" id="launch-forms-config" hidden>(.+)</textarea>#s', $html );
		preg_match( '#hidden>(.+)</textarea>#s', $html, $m );
		$config = json_decode( html_entity_decode( $m[1] ), true );
		$this->assertSame( 'email', $config['fields'][1]['name'] );
	}

	public function test_usage_box_shows_shortcode() {
		$form = $this->contact_form();
		ob_start();
		launch_forms()->admin->render_usage_box( get_post( $form->id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( '[launch_form id=&quot;' . $form->id . '&quot;]', $html );
	}

	public function test_form_list_columns() {
		$form = $this->contact_form();
		launch_forms()->submissions->create( $form, array() );
		$cols = launch_forms()->admin->form_columns( array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );
		$this->assertSame( array( 'cb', 'title', 'lf_shortcode', 'lf_submissions', 'date' ), array_keys( $cols ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		launch_forms()->admin->form_column( 'lf_shortcode', $form->id );
		launch_forms()->admin->form_column( 'lf_submissions', $form->id );
		$html = ob_get_clean();
		$this->assertStringContainsString( '[launch_form id=&quot;' . $form->id . '&quot;]', $html );
		$this->assertStringContainsString( '>1</a>', $html );
	}

	public function test_builder_scripts_registered_with_dependencies() {
		$scripts = wp_scripts();
		$this->assertContains( 'wp-components', $scripts->registered['launch-forms-builder']->deps );
		$this->assertContains( 'launch-forms-builder', $scripts->registered['launch-forms-block-editor']->deps );
		$this->assertContains( 'wp-core-data', $scripts->registered['launch-forms-block-editor']->deps );
		$this->assertContains( 'wp-server-side-render', $scripts->registered['launch-forms-block-editor']->deps );
	}

	public function test_block_editor_assets_inline_builder_data() {
		launch_forms()->admin->enqueue_block_editor();
		$before = implode( '', (array) wp_scripts()->get_data( 'launch-forms-builder', 'before' ) );
		$this->assertStringContainsString( 'window.launchFormsBuilderData', $before );
		$this->assertTrue( wp_style_is( 'launch-forms-builder', 'enqueued' ) );
	}

	public function test_settings_menu_registered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		launch_forms()->admin->menu();
		global $submenu;
		$slugs = wp_list_pluck( $submenu[ 'edit.php?post_type=' . Forms::POST_TYPE ] ?? array(), 2 );
		$this->assertContains( 'launch-forms-settings', $slugs );
		set_current_screen( 'front' );
	}

	public function test_activation_creates_protected_dir() {
		LaunchForms\Plugin::activate();
		$this->assertFileExists( trailingslashit( $this->private_dir ) . '.htaccess' );
	}
}
