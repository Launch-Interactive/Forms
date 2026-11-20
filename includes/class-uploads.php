<?php
/**
 * Private file storage for uploads.
 *
 * Security model:
 *  - Files are stored in a dedicated directory (outside the web root when LAUNCH_FORMS_PRIVATE_DIR
 *    is defined, otherwise wp-content/uploads/launch-forms-private) that is locked with .htaccess,
 *    web.config and index.php.
 *  - Stored names are 128-bit random hex strings with no extension, so even on servers that ignore
 *    .htaccess (nginx) files cannot be guessed, listed or executed.
 *  - Files are never added to the media library and are only served through an authenticated,
 *    nonce-checked admin download handler as attachments.
 *  - Uploads are checked against a per-field extension allow-list, WordPress' allowed MIME types,
 *    the real file content (wp_check_filetype_and_ext), a hard block-list applied to every
 *    extension segment (catches "shell.php.jpg"), and size limits.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Upload validation and storage.
 */
class Uploads {

	const BLOCKED_EXTENSIONS = array(
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'inc',
		'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'cfm',
		'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'ps1', 'jar', 'apk',
		'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'swf',
		'htaccess', 'htpasswd', 'hta', 'ini', 'user',
	);

	/**
	 * Directory where private files live (ensured to exist and be protected).
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public function get_dir() {
		if ( defined( 'LAUNCH_FORMS_PRIVATE_DIR' ) && LAUNCH_FORMS_PRIVATE_DIR ) {
			$dir = LAUNCH_FORMS_PRIVATE_DIR;
		} else {
			$uploads = wp_upload_dir( null, false );
			$dir     = $uploads['basedir'] . '/launch-forms-private';
		}
		/**
		 * Filters the private upload directory. Point this outside the web root for best security.
		 *
		 * @param string $dir Directory.
		 */
		$dir = trailingslashit( (string) apply_filters( 'launch_forms_private_dir', $dir ) );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$this->protect_dir( $dir );
		return $dir;
	}

	/**
	 * Write deny rules and an index file into the directory.
	 *
	 * @param string $dir Directory with trailing slash.
	 */
	public function protect_dir( $dir ) {
		$files = array(
			'.htaccess'  => "# Launch Forms: deny all direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( $dir . $name ) ) {
				file_put_contents( $dir . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Convert PHP's $_FILES['lf'] structure into name => single-file array.
	 *
	 * @param mixed $files $_FILES['lf'] (or similar).
	 * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	public static function normalize_files( $files ) {
		$out = array();
		if ( ! is_array( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			return $out;
		}
		foreach ( $files['name'] as $field => $name ) {
			if ( is_array( $name ) ) {
				continue; // Only single-file inputs are supported.
			}
			$out[ (string) $field ] = array(
				'name'     => (string) $name,
				'type'     => (string) ( $files['type'][ $field ] ?? '' ),
				'tmp_name' => (string) ( $files['tmp_name'][ $field ] ?? '' ),
				'error'    => (int) ( $files['error'][ $field ] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $files['size'][ $field ] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Whether a file entry represents an actual upload attempt.
	 *
	 * @param array|null $file File entry.
	 * @return bool
	 */
	public static function was_provided( $file ) {
		return is_array( $file ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && '' !== (string) ( $file['name'] ?? '' );
	}

	/**
	 * Validate an uploaded file against a field's rules.
	 *
	 * @param array $field Field definition.
	 * @param array $file  Single normalised file.
	 * @return array|\WP_Error Info array {ext, type, name} on success.
	 */
	public function validate( array $field, array $file ) {
		$error = (int) $file['error'];
		if ( UPLOAD_ERR_OK !== $error ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE  => __( 'The file is too large.', 'launch-forms' ),
				UPLOAD_ERR_FORM_SIZE => __( 'The file is too large.', 'launch-forms' ),
				UPLOAD_ERR_PARTIAL   => __( 'The file was only partially uploaded. Please try again.', 'launch-forms' ),
			);
			return new \WP_Error( 'lf_upload_error', $messages[ $error ] ?? __( 'The file could not be uploaded.', 'launch-forms' ) );
		}

		$tmp = (string) $file['tmp_name'];
		if ( '' === $tmp || ! file_exists( $tmp ) || ! $this->is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'lf_upload_invalid', __( 'The file could not be uploaded.', 'launch-forms' ) );
		}

		$max_bytes = (int) min( $field['max_size'] * MB_IN_BYTES, wp_max_upload_size() ?: PHP_INT_MAX );
		$size      = (int) filesize( $tmp );
		if ( $size > $max_bytes || (int) $file['size'] > $max_bytes ) {
			/* translators: %s: size in MB. */
			return new \WP_Error( 'lf_upload_size', sprintf( __( 'The file must be smaller than %s MB.', 'launch-forms' ), round( $max_bytes / MB_IN_BYTES, 2 ) ) );
		}
		if ( 0 === $size ) {
			return new \WP_Error( 'lf_upload_empty', __( 'The file is empty.', 'launch-forms' ) );
		}

		$original = wp_basename( (string) $file['name'] );
		$type_err = new \WP_Error( 'lf_upload_type', __( 'This file type is not allowed.', 'launch-forms' ) );

		// Reject dangerous extensions anywhere in the name (e.g. "shell.php.jpg") and null bytes.
		if ( false !== strpos( $original, "\0" ) ) {
			return $type_err;
		}
		$segments = array_map( 'strtolower', array_slice( explode( '.', $original ), 1 ) );
		if ( empty( $segments ) || array_intersect( $segments, self::BLOCKED_EXTENSIONS ) ) {
			return $type_err;
		}

		$ext = end( $segments );
		if ( ! in_array( $ext, $field['allowed_types'], true ) ) {
			return $type_err;
		}

		// Must be a type WordPress allows, and the content must match the extension.
		$check = wp_check_filetype_and_ext( $tmp, $original, $this->allowed_mimes() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return $type_err;
		}
		$name = ! empty( $check['proper_filename'] ) ? $check['proper_filename'] : $original;
		$ext  = strtolower( (string) $check['ext'] );
		if ( ! in_array( $ext, $field['allowed_types'], true ) || in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
			return $type_err;
		}

		return array(
			'ext'  => $ext,
			'type' => (string) $check['type'],
			'name' => sanitize_file_name( $name ),
		);
	}

	/**
	 * Validate and store an uploaded file privately.
	 *
	 * @param array $field Field definition.
	 * @param array $file  Single normalised file.
	 * @return array|\WP_Error Stored record {file, name, size, type}.
	 */
	public function store( array $field, array $file ) {
		$info = $this->validate( $field, $file );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		$dir    = $this->get_dir();
		$stored = bin2hex( random_bytes( 16 ) );
		$dest   = $dir . $stored;
		$tmp    = (string) $file['tmp_name'];

		$moved = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $dest ) : ( $this->is_uploaded_file( $tmp ) && copy( $tmp, $dest ) );
		if ( ! $moved ) {
			return new \WP_Error( 'lf_upload_move', __( 'The file could not be saved.', 'launch-forms' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- Direct file access is required for private storage.

		return array(
			'file' => $stored,
			'name' => $info['name'],
			'size' => (int) filesize( $dest ),
			'type' => $info['type'],
		);
	}

	/**
	 * Absolute path for a stored file key, or null if invalid / missing.
	 *
	 * @param string $stored Stored key.
	 * @return string|null
	 */
	public function path( $stored ) {
		if ( ! is_string( $stored ) || ! preg_match( '/^[a-f0-9]{32}$/', $stored ) ) {
			return null;
		}
		$path = $this->get_dir() . $stored;
		return is_file( $path ) ? $path : null;
	}

	/**
	 * Delete a stored file.
	 *
	 * @param string $stored Stored key.
	 * @return bool
	 */
	public function delete( $stored ) {
		$path = $this->path( $stored );
		return $path ? wp_delete_file( $path ) || ! file_exists( $path ) : false;
	}

	/**
	 * Public URL of the private directory, or '' when it is outside the web root.
	 *
	 * @param string $dir Directory with trailing slash.
	 * @return string
	 */
	public function public_url_for_dir( $dir ) {
		$uploads = wp_upload_dir( null, false );
		$base    = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$dir     = wp_normalize_path( $dir );
		if ( 0 === strpos( $dir, $base ) ) {
			return trailingslashit( $uploads['baseurl'] ) . substr( $dir, strlen( $base ) );
		}
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $dir, $root ) ) {
			return site_url( '/' . substr( $dir, strlen( $root ) ) );
		}
		return '';
	}

	/**
	 * Check whether a stored file can be downloaded straight from the web server, by writing a
	 * harmless probe file and requesting it over HTTP.
	 *
	 * @return string 'protected', 'exposed', 'outside' (not under the web root) or 'unknown'.
	 */
	public function public_access_status() {
		$dir = $this->get_dir();
		$url = $this->public_url_for_dir( $dir );
		if ( '' === $url ) {
			return 'outside';
		}
		$name   = bin2hex( random_bytes( 16 ) );
		$marker = 'launch-forms-probe-' . $name;
		if ( false === file_put_contents( $dir . $name, $marker ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'unknown';
		}
		$response = wp_remote_get(
			$url . $name,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => false, // Loopback request to this same site.
			)
		);
		wp_delete_file( $dir . $name );
		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return false !== strpos( (string) wp_remote_retrieve_body( $response ), $marker ) ? 'exposed' : 'protected';
		}
		return $code >= 400 ? 'protected' : 'unknown';
	}

	/**
	 * WordPress' allowed MIME types, without anything that could execute in a browser.
	 *
	 * @return array
	 */
	private function allowed_mimes() {
		$mimes = get_allowed_mime_types();
		foreach ( array_keys( $mimes ) as $exts ) {
			if ( array_intersect( explode( '|', $exts ), self::BLOCKED_EXTENSIONS ) ) {
				unset( $mimes[ $exts ] );
			}
		}
		return $mimes;
	}

	/**
	 * Whether the temp file came from an HTTP upload. Filterable only so automated tests can
	 * supply fixture files; never filter this on a live site.
	 *
	 * @param string $tmp Temp path.
	 * @return bool
	 */
	private function is_uploaded_file( $tmp ) {
		return (bool) apply_filters( 'launch_forms_is_uploaded_file', is_uploaded_file( $tmp ), $tmp );
	}
}
