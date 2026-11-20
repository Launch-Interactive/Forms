<?php
/**
 * Uninstall: remove settings, and optionally all forms, submissions and private files.
 *
 * @package LaunchForms
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$launch_forms_settings = get_option( 'launch_forms_settings', array() );

if ( ! empty( $launch_forms_settings['delete_data_on_uninstall'] ) ) {
	foreach ( array( 'lf_form', 'lf_submission' ) as $launch_forms_type ) {
		$launch_forms_ids = get_posts(
			array(
				'post_type'      => $launch_forms_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $launch_forms_ids as $launch_forms_id ) {
			wp_delete_post( $launch_forms_id, true );
		}
	}

	$launch_forms_dir = defined( 'LAUNCH_FORMS_PRIVATE_DIR' ) && LAUNCH_FORMS_PRIVATE_DIR ? LAUNCH_FORMS_PRIVATE_DIR : wp_upload_dir( null, false )['basedir'] . '/launch-forms-private';
	$launch_forms_dir = trailingslashit( $launch_forms_dir );
	if ( is_dir( $launch_forms_dir ) ) {
		// Only remove files this plugin wrote (random 32-character names and the three
		// protection files), in case LAUNCH_FORMS_PRIVATE_DIR points at a shared folder.
		foreach ( (array) scandir( $launch_forms_dir ) as $launch_forms_file ) {
			$launch_forms_marks = array(
				'.htaccess'  => '# Launch Forms: deny all direct access.',
				'web.config' => '<deny users="*" />',
				'index.php'  => '// Silence is golden.',
			);
			$launch_forms_ours  = preg_match( '/^[a-f0-9]{32}$/', (string) $launch_forms_file )
				|| ( isset( $launch_forms_marks[ $launch_forms_file ] ) && filesize( $launch_forms_dir . $launch_forms_file ) < 1024
					&& false !== strpos( (string) file_get_contents( $launch_forms_dir . $launch_forms_file ), $launch_forms_marks[ $launch_forms_file ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $launch_forms_ours && is_file( $launch_forms_dir . $launch_forms_file ) ) {
				wp_delete_file( $launch_forms_dir . $launch_forms_file );
			}
		}
		@rmdir( $launch_forms_dir ); // phpcs:ignore
	}
}

delete_option( 'launch_forms_settings' );
