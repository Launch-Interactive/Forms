<?php
/**
 * Uninstall: remove settings, and optionally all forms, submissions and private files.
 *
 * @package LaunchInteractiveForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$liforms_settings = get_option( 'liforms_settings', get_option( 'launch_forms_settings', array() ) );

if ( ! empty( $liforms_settings['delete_data_on_uninstall'] ) ) {
	// Current post types, plus the older names in case the upgrade never ran.
	foreach ( array( 'liforms_form', 'liforms_entry', 'launch_forms_form', 'launch_forms_entry', 'lf_form', 'lf_submission' ) as $liforms_type ) {
		$liforms_ids = get_posts(
			array(
				'post_type'      => $liforms_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $liforms_ids as $liforms_id ) {
			wp_delete_post( $liforms_id, true );
		}
	}

	$liforms_base = trailingslashit( wp_upload_dir( null, false )['basedir'] );
	$liforms_dirs = defined( 'LIFORMS_PRIVATE_DIR' ) && LIFORMS_PRIVATE_DIR
		? array( LIFORMS_PRIVATE_DIR )
		: array( $liforms_base . 'launch-interactive-forms', $liforms_base . 'launch-forms-private' );
	foreach ( $liforms_dirs as $liforms_dir ) {
		$liforms_dir = trailingslashit( $liforms_dir );
		if ( ! is_dir( $liforms_dir ) ) {
			continue;
		}
		// Only remove files this plugin wrote (random 32-character names and the three
		// protection files), in case LIFORMS_PRIVATE_DIR points at a shared folder.
		foreach ( (array) scandir( $liforms_dir ) as $liforms_file ) {
			$liforms_marks = array(
				'.htaccess'  => 'Forms: deny all direct access.',
				'web.config' => '<deny users="*" />',
				'index.php'  => '// Silence is golden.',
			);
			$liforms_ours  = preg_match( '/^[a-f0-9]{32}$/', (string) $liforms_file )
				|| ( isset( $liforms_marks[ $liforms_file ] ) && filesize( $liforms_dir . $liforms_file ) < 1024
					&& false !== strpos( (string) file_get_contents( $liforms_dir . $liforms_file ), $liforms_marks[ $liforms_file ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $liforms_ours && is_file( $liforms_dir . $liforms_file ) ) {
				wp_delete_file( $liforms_dir . $liforms_file );
			}
		}
		@rmdir( $liforms_dir ); // phpcs:ignore
	}
}

foreach ( array( 'liforms_settings', 'liforms_db_version', 'launch_forms_settings', 'launch_forms_db_version' ) as $liforms_option ) {
	delete_option( $liforms_option );
}
