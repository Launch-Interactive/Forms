<?php
/**
 * @covers \LaunchForms\Uploads
 */

use LaunchForms\Form_Config;
use LaunchForms\Uploads;

class UploadsTest extends LF_TestCase {

	/** @var Uploads */
	private $uploads;

	public function set_up() {
		parent::set_up();
		$this->uploads = launch_forms()->uploads;
	}

	private function file_field( array $extra = array() ) {
		return Form_Config::sanitize_field( array_merge( array( 'type' => 'file', 'name' => 'doc', 'allowed_types' => 'pdf,png,jpg,txt' ), $extra ) );
	}

	public function test_private_dir_is_protected() {
		$dir = $this->uploads->get_dir();
		$this->assertDirectoryExists( $dir );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', file_get_contents( $dir . '.htaccess' ) );
		$this->assertStringContainsString( 'Options -Indexes', file_get_contents( $dir . '.htaccess' ) );
		$this->assertFileExists( $dir . 'index.php' );
		$this->assertStringContainsString( '<deny users="*"', file_get_contents( $dir . 'web.config' ) );
	}

	public function test_default_dir_is_in_uploads_not_media_library_path() {
		remove_all_filters( 'launch_forms_private_dir' );
		$dir = $this->uploads->get_dir();
		$this->assertSame( trailingslashit( wp_upload_dir()['basedir'] ) . 'launch-forms-private/', $dir );
		$this->assertFileExists( $dir . '.htaccess' );
	}

	public function test_stores_valid_png_with_random_name_and_no_extension() {
		$record = $this->uploads->store( $this->file_field(), $this->fake_upload( 'photo.png', $this->png_bytes() ) );
		$this->assertIsArray( $record );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $record['file'] );
		$this->assertSame( 'photo.png', $record['name'] );
		$this->assertSame( 'image/png', $record['type'] );
		$path = $this->uploads->path( $record['file'] );
		$this->assertFileExists( $path );
		$this->assertStringStartsWith( $this->uploads->get_dir(), $path );
		$this->assertSame( $this->png_bytes(), file_get_contents( $path ) );
		$this->assertSame( 0, (int) ( new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'any' ) ) )->found_posts, 'Never added to the media library.' );
	}

	public function test_stored_names_are_unique() {
		$a = $this->uploads->store( $this->file_field(), $this->fake_upload( 'a.pdf', $this->pdf_bytes() ) );
		$b = $this->uploads->store( $this->file_field(), $this->fake_upload( 'a.pdf', $this->pdf_bytes() ) );
		$this->assertNotSame( $a['file'], $b['file'] );
	}

	public function test_accepts_pdf_and_text() {
		$this->assertIsArray( $this->uploads->validate( $this->file_field(), $this->fake_upload( 'doc.pdf', $this->pdf_bytes() ) ) );
		$this->assertIsArray( $this->uploads->validate( $this->file_field(), $this->fake_upload( 'notes.txt', "hello\nworld" ) ) );
	}

	/**
	 * @dataProvider dangerous_provider
	 */
	public function test_rejects_dangerous_files( $name, $contents, $types ) {
		$field  = $this->file_field( array( 'allowed_types' => $types ) );
		$field['allowed_types'][] = 'php'; // Even if the allow-list were tampered with.
		$result = $this->uploads->validate( $field, $this->fake_upload( $name, $contents ) );
		$this->assertWPError( $result, $name );
	}

	public function dangerous_provider() {
		$php = '<?php echo shell_exec($_GET["c"]); ?>';
		return array(
			'php'                => array( 'shell.php', $php, 'pdf' ),
			'phtml'              => array( 'shell.phtml', $php, 'pdf' ),
			'double extension'   => array( 'shell.php.jpg', $php, 'jpg' ),
			'php as jpg content' => array( 'photo.jpg', $php, 'jpg' ),
			'php as png content' => array( 'photo.png', $php, 'png' ),
			'php as pdf content' => array( 'doc.pdf', $php, 'pdf' ),
			'html'               => array( 'page.html', '<script>alert(1)</script>', 'pdf' ),
			'svg'                => array( 'image.svg', '<svg onload="alert(1)"></svg>', 'pdf' ),
			'htaccess'           => array( '.htaccess', 'SetHandler application/x-httpd-php', 'pdf' ),
			'no extension'       => array( 'README', 'text', 'txt' ),
			'uppercase PHP'      => array( 'SHELL.PHP', $php, 'pdf' ),
			'null byte'          => array( "a.php\0.jpg", $php, 'jpg' ),
		);
	}

	public function test_rejects_types_not_in_field_allow_list() {
		$field = $this->file_field( array( 'allowed_types' => 'pdf' ) );
		$this->assertWPError( $this->uploads->validate( $field, $this->fake_upload( 'photo.png', $this->png_bytes() ) ) );
	}

	public function test_enforces_size_limit() {
		$field  = $this->file_field( array( 'max_size' => 0.001 ) ); // ~1 KB.
		$result = $this->uploads->validate( $field, $this->fake_upload( 'big.txt', str_repeat( 'a', 5000 ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'lf_upload_size', $result->get_error_code() );
		$this->assertWPError( $this->uploads->validate( $field, $this->fake_upload( 'empty.txt', '' ) ) );
	}

	public function test_rejects_php_upload_errors_and_non_uploaded_files() {
		$file          = $this->fake_upload( 'a.txt', 'x' );
		$file['error'] = UPLOAD_ERR_PARTIAL;
		$this->assertSame( 'lf_upload_error', $this->uploads->validate( $this->file_field(), $file )->get_error_code() );

		remove_all_filters( 'launch_forms_is_uploaded_file' );
		$result = $this->uploads->store( $this->file_field(), $this->fake_upload( 'a.txt', 'x' ) );
		$this->assertWPError( $result, 'Files that did not arrive via HTTP upload must be refused.' );
	}

	public function test_path_rejects_traversal_and_bad_keys() {
		$this->assertNull( $this->uploads->path( '../../wp-config.php' ) );
		$this->assertNull( $this->uploads->path( 'index.php' ) );
		$this->assertNull( $this->uploads->path( str_repeat( 'a', 32 ) ) ); // Valid shape but missing.
		$this->assertNull( $this->uploads->path( array() ) );
	}

	public function test_delete() {
		$record = $this->uploads->store( $this->file_field(), $this->fake_upload( 'a.txt', 'abc' ) );
		$path   = $this->uploads->path( $record['file'] );
		$this->assertTrue( $this->uploads->delete( $record['file'] ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( $this->uploads->delete( 'nothex' ) );
	}

	public function test_normalize_files_structure() {
		$files = array(
			'name'     => array( 'cv' => 'cv.pdf', 'multi' => array( 'a', 'b' ) ),
			'type'     => array( 'cv' => 'application/pdf' ),
			'tmp_name' => array( 'cv' => '/tmp/x' ),
			'error'    => array( 'cv' => 0 ),
			'size'     => array( 'cv' => 10 ),
		);
		$this->assertSame(
			array(
				'cv' => array(
					'name'     => 'cv.pdf',
					'type'     => 'application/pdf',
					'tmp_name' => '/tmp/x',
					'error'    => 0,
					'size'     => 10,
				),
			),
			Uploads::normalize_files( $files )
		);
		$this->assertSame( array(), Uploads::normalize_files( null ) );
		$this->assertFalse( Uploads::was_provided( array( 'name' => '', 'error' => UPLOAD_ERR_NO_FILE ) ) );
	}
}
