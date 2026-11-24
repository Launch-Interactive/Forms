<?php
/**
 * @covers \LaunchInteractiveForms\Submissions
 */

use LaunchInteractiveForms\Submissions;

class SubmissionsTest extends LF_TestCase {

	private function submission_with_file( &$record = null ) {
		$form   = $this->make_form( array( $this->field( 'file', 'cv', array( 'allowed_types' => 'pdf' ) ) ) );
		$result = liforms()->processor->process( $form, array( 'lif' => array() ), array( 'cv' => $this->fake_upload( 'cv.pdf', $this->pdf_bytes() ) ) );
		$this->assertTrue( $result->success );
		$record = liforms()->submissions->get_entries( $result->submission_id )[0]['value'];
		return $result->submission_id;
	}

	public function test_post_type_is_private_and_not_creatable() {
		$type = get_post_type_object( Submissions::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertFalse( $type->show_in_rest );
		$this->assertSame( 'do_not_allow', $type->cap->create_posts );
	}

	public function test_only_admins_can_read_submissions() {
		$id = liforms()->submissions->create( $this->contact_form(), array(), array() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( current_user_can( 'edit_post', $id ) );
		$this->assertFalse( current_user_can( 'delete_post', $id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post', $id ) );
	}

	public function test_capability_is_filterable() {
		add_filter(
			'liforms_manage_capability',
			static function () {
				return 'edit_pages';
			}
		);
		$this->assertSame( 'edit_pages', LaunchInteractiveForms\Plugin::manage_capability() );
	}

	public function test_create_and_read() {
		$form = $this->contact_form();
		$id   = liforms()->submissions->create(
			$form,
			array( array( 'name' => 'name', 'label' => 'Name', 'type' => 'text', 'value' => 'Quote "me" \\ ok' ) ),
			array( 'page_url' => 'https://example.org/x', 'user_id' => 0 )
		);
		$post = get_post( $id );
		$this->assertSame( $form->id, $post->post_parent );
		$this->assertSame( 'Contact submission', $post->post_title );
		$this->assertSame( 'Quote "me" \\ ok', liforms()->submissions->get_entries( $id )[0]['value'], 'Slashes and quotes survive storage.' );
		liforms()->submissions->add_log( $id, array( array( 'name' => 'a' ) ) );
		liforms()->submissions->add_log( $id, array( array( 'name' => 'b' ) ) );
		$this->assertSame( array( 'a', 'b' ), wp_list_pluck( liforms()->submissions->get_log( $id ), 'name' ) );
		$this->assertSame( 1, liforms()->submissions->count_for_form( $form->id ) );
	}

	public function test_download_requires_capability() {
		$id = $this->submission_with_file( $record );
		wp_set_current_user( 0 );
		$this->assertSame( 'liforms_forbidden', liforms()->submissions->resolve_download( $id, $record['file'] )->get_error_code() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'liforms_forbidden', liforms()->submissions->resolve_download( $id, $record['file'] )->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$download = liforms()->submissions->resolve_download( $id, $record['file'] );
		$this->assertSame( 'cv.pdf', $download['name'] );
		$this->assertSame( $this->pdf_bytes(), file_get_contents( $download['path'] ) );
	}

	public function test_download_only_serves_files_belonging_to_that_submission() {
		$first  = $this->submission_with_file( $first_record );
		$second = $this->submission_with_file( $second_record );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( liforms()->submissions->resolve_download( $first, $second_record['file'] ) );
		$this->assertWPError( liforms()->submissions->resolve_download( $first, '../../wp-config.php' ) );
		$this->assertWPError( liforms()->submissions->resolve_download( self::factory()->post->create(), $first_record['file'] ) );
	}

	public function test_download_url_is_nonced_admin_post() {
		$url = liforms()->submissions->download_url( 12, str_repeat( 'a', 32 ) );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=liforms_download', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Run the download handler with the given query and return the wp_die exception.
	 */
	private function download_die( array $query ) {
		$_GET = $query;
		try {
			liforms()->submissions->handle_download();
		} catch ( WPDieException $e ) {
			return $e;
		} finally {
			$_GET = array();
		}
		$this->fail( 'Expected the download to be refused.' );
	}

	public function test_logged_out_download_links_go_to_login_instead_of_a_blank_error() {
		$this->assertNotFalse( has_action( 'admin_post_nopriv_liforms_download', array( liforms()->submissions, 'handle_logged_out_download' ) ) );
	}

	public function test_download_refusals_explain_themselves() {
		$id = $this->submission_with_file( $record );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$nonce = wp_create_nonce( 'liforms_download_' . $id );
		$e     = $this->download_die( array( 'submission' => $id, 'file' => $record['file'], '_wpnonce' => $nonce ) );
		$this->assertStringContainsString( 'does not have permission', $e->getMessage() );
		$this->assertSame( 403, $e->getCode() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$e = $this->download_die( array( 'submission' => $id, 'file' => $record['file'], '_wpnonce' => 'stale' ) );
		$this->assertStringContainsString( 'expired', $e->getMessage() );
		$this->assertSame( 403, $e->getCode() );

		$nonce = wp_create_nonce( 'liforms_download_' . $id );
		$e     = $this->download_die( array( 'submission' => $id, 'file' => str_repeat( 'b', 32 ), '_wpnonce' => $nonce ) );
		$this->assertStringContainsString( 'no longer available', $e->getMessage() );
		$this->assertSame( 404, $e->getCode() );
	}

	public function test_files_deleted_with_submission_but_not_on_trash() {
		$id   = $this->submission_with_file( $record );
		$path = liforms()->uploads->path( $record['file'] );
		wp_trash_post( $id );
		$this->assertFileExists( $path, 'Trash is recoverable, keep files.' );
		wp_delete_post( $id, true );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_admin_columns_and_view_box() {
		$id = $this->submission_with_file( $record );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$columns = liforms()->submissions->columns( array( 'cb' => '<input>', 'title' => 'T', 'date' => 'D' ) );
		$this->assertSame( array( 'cb', 'title', 'liforms_form', 'liforms_summary', 'date' ), array_keys( $columns ) );

		ob_start();
		liforms()->submissions->column( 'liforms_summary', $id );
		liforms()->submissions->render_data_box( get_post( $id ) );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'cv.pdf', $html );
		$this->assertStringContainsString( 'action=liforms_download', $html );
		$this->assertStringNotContainsString( $this->private_dir, $html, 'Never expose the storage path.' );
	}

	public function test_row_actions() {
		$id      = liforms()->submissions->create( $this->contact_form(), array() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$actions = liforms()->submissions->row_actions( array( 'edit' => 'x', 'inline hide-if-no-js' => 'q', 'trash' => 't' ), get_post( $id ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $actions );
		$this->assertStringContainsString( 'View', $actions['edit'] );
	}
}
