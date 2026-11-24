<?php
/**
 * Moving pre-2.0 data to the new names.
 *
 * @covers \LaunchForms\Upgrade
 */

use LaunchForms\Forms;
use LaunchForms\Submissions;
use LaunchForms\Upgrade;
use LaunchForms\Uploads;

class UpgradeTest extends LF_TestCase {

	private function old_post( $type, array $meta ) {
		$id = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_title'  => $type,
			)
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}
		return $id;
	}

	public function test_old_forms_and_submissions_move_to_the_new_names() {
		$form    = $this->old_post( 'lf_form', array( Forms::META_KEY => '{}' ) );
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
		delete_option( Upgrade::OPTION );

		Upgrade::maybe_run();
		clean_post_cache( $form );
		clean_post_cache( $sub );
		clean_post_cache( $foreign );

		$this->assertSame( Forms::POST_TYPE, get_post_type( $form ) );
		$this->assertSame( Submissions::POST_TYPE, get_post_type( $sub ) );
		$this->assertSame( 'lf_form', get_post_type( $foreign ), 'Posts without our config are not ours to move.' );
		$this->assertSame( (string) $form, get_post_meta( $sub, Submissions::META_FORM, true ) );
		$this->assertSame( 'https://example.org/contact/', get_post_meta( $sub, Submissions::META_PAGE, true ) );
		$this->assertSame( '', get_post_meta( $sub, '_lf_entries', true ) );
		$this->assertSame( Upgrade::VERSION, (int) get_option( Upgrade::OPTION ) );
		$this->assertNotNull( launch_forms()->forms->get( $form ), 'The form loads under its new post type.' );
	}

	public function test_runs_once() {
		update_option( Upgrade::OPTION, Upgrade::VERSION );
		$form = $this->old_post( 'lf_form', array( Forms::META_KEY => '{}' ) );
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
