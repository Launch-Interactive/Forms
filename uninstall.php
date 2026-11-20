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
		foreach ( (array) scandir( $launch_forms_dir ) as $launch_forms_file ) {
			if ( is_file( $launch_forms_dir . $launch_forms_file ) ) {
				wp_delete_file( $launch_forms_dir . $launch_forms_file );
			}
		}
		@rmdir( $launch_forms_dir ); // phpcs:ignore
	}
}

delete_option( 'launch_forms_settings' );
