<?php
/**
 * Moving 1.x ("lf_") and 2.0 ("launch_forms_") data to the "liforms" names.
 *
 * @covers \LaunchInteractiveForms\Upgrade
 */

use LaunchInteractiveForms\Forms;
use LaunchInteractiveForms\Settings;
use LaunchInteractiveForms\Submissions;
use LaunchInteractiveForms\Upgrade;
use LaunchInteractiveForms\Uploads;

class UpgradeTest extends LF_TestCase {

	private function old_post( $type, array $meta, $content = '' ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => $type,
				'post_content' => $content,
			)
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, wp_slash( $value ) );
		}
		return $id;
	}

	private function upgrade( array $ids ) {
		delete_option( Upgrade::OPTION );
		Upgrade::maybe_run();
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
	}

	public function test_1x_forms_and_submissions_move_to_the_new_names() {
		$form    = $this->old_post( 'lf_form', array( 'launch_forms_config' => '{}' ) );
		$sub     = $this->old_post(
			'lf_submission',
			array(
				'_lf_form_id'  => $form,
				'_lf_entries'  => '[]',
				'_lf_log'      => '[]',
				'_lf_page_url' => 'https://example.org/contact/',
			)
		);
		$foreign = $this->old_post( 'lf_form', array( 'someone_else' => '1' ) );
		$this->upgrade( array( $form, $sub, $foreign ) );

		$this->assertSame( Forms::POST_TYPE, get_post_type( $form ) );
		$this->assertSame( '{}', get_post_meta( $form, Forms::META_KEY, true ) );
		$this->assertSame( Submissions::POST_TYPE, get_post_type( $sub ) );
		$this->assertSame( 'lf_form', get_post_type( $foreign ), 'Posts without our config are not ours to move.' );
		$this->assertSame( (string) $form, get_post_meta( $sub, Submissions::META_FORM, true ) );
		$this->assertSame( 'https://example.org/contact/', get_post_meta( $sub, Submissions::META_PAGE, true ) );
		$this->assertSame( '', get_post_meta( $sub, '_lf_entries', true ) );
		$this->assertSame( Upgrade::VERSION, (int) get_option( Upgrade::OPTION ) );
	}

	public function test_2_0_data_moves_to_the_new_names() {
		update_option( 'launch_forms_settings', array( 'required_marker' => '!' ) );
		delete_option( Settings::OPTION );
		update_option( 'launch_forms_db_version', 2 );
		$form = $this->old_post( 'launch_forms_form', array( 'launch_forms_config' => '{"x":1}' ) );
		$sub  = $this->old_post(
			'launch_forms_entry',
			array(
				'_launch_forms_form_id' => $form,
				'_launch_forms_entries' => '[]',
			)
		);
		$this->upgrade( array( $form, $sub ) );

		$this->assertSame( Forms::POST_TYPE, get_post_type( $form ) );
		$this->assertSame( '{"x":1}', get_post_meta( $form, Forms::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( $form, 'launch_forms_config', true ) );
		$this->assertSame( Submissions::POST_TYPE, get_post_type( $sub ) );
		$this->assertSame( (string) $form, get_post_meta( $sub, Submissions::META_FORM, true ) );
		$this->assertSame( array( 'required_marker' => '!' ), get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( 'launch_forms_settings' ) );
		$this->assertFalse( get_option( 'launch_forms_db_version' ) );
		$this->assertNotNull( liforms()->forms->get( $form ), 'The form loads under its new post type.' );
	}

	public function test_blocks_and_shortcodes_are_updated_everywhere() {
		$page = $this->old_post( 'page', array( '_elementor_data' => '[{"settings":{"shortcode":"[launch_form id=\"7\"]"}}]' ), "<!-- wp:launch-forms/form {\"formId\":7} /-->\n[launch_form id=\"7\"] [launch_form]" );
		update_option( 'widget_block', array( 2 => array( 'content' => '<!-- wp:launch-forms/form {"formId":7} /-->' ), '_multiwidget' => 1 ) );
		$this->upgrade( array( $page ) );

		$this->assertSame( "<!-- wp:liforms/form {\"formId\":7} /-->\n[liforms id=\"7\"] [liforms]", get_post( $page )->post_content );
		$this->assertSame( '[{"settings":{"shortcode":"[liforms id=\"7\"]"}}]', get_post_meta( $page, '_elementor_data', true ) );
		$this->assertSame( '<!-- wp:liforms/form {"formId":7} /-->', get_option( 'widget_block' )[2]['content'] );
	}

	public function test_runs_once() {
		update_option( Upgrade::OPTION, Upgrade::VERSION );
		$form = $this->old_post( 'lf_form', array( 'launch_forms_config' => '{}' ) );
		Upgrade::maybe_run();
		clean_post_cache( $form );
		$this->assertSame( 'lf_form', get_post_type( $form ) );
	}

	public function test_files_move_from_the_old_private_folder() {
		$base = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$old  = $base . Uploads::LEGACY_DIR_NAME . '/';
		$new  = $base . Uploads::DIR_NAME . '/';
		$this->assertDirectoryDoesNotExist( $old );
		$had_new = is_dir( $new );
		wp_mkdir_p( $old );
		$file = str_repeat( 'b', 32 );
		file_put_contents( $old . $file, 'upload' );
		delete_option( Upgrade::OPTION );

		Upgrade::maybe_run();

		$this->assertSame( 'upload', file_get_contents( $new . $file ) );
		$this->assertFileDoesNotExist( $old . $file );

		unlink( $new . $file );
		if ( is_dir( $old ) ) {
			rmdir( $old );
		}
		if ( ! $had_new ) {
			foreach ( (array) glob( $new . '{,.}*', GLOB_BRACE ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			rmdir( $new );
		}
	}
}

