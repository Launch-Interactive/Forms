<?php
/**
 * One-time data upgrades between plugin versions.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per site, the first time a newer version loads.
 */
final class Upgrade {

	/** Option holding the data version this site has been upgraded to. */
	const OPTION = 'launch_forms_db_version';

	/** Current data version. */
	const VERSION = 2;

	/**
	 * Run any upgrades this site hasn't had yet.
	 */
	public static function maybe_run() {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		if ( self::run() ) {
			update_option( self::OPTION, self::VERSION, true );
		}
	}

	/**
	 * Version 2 (plugin 2.0.0): the post types, submission meta and private upload folder
	 * were renamed to longer, plugin-specific names. Only rows that are clearly ours are
	 * moved: forms that carry the plugin's config and submissions that carry its entries.
	 *
	 * @return bool Whether everything was moved (false means try again next time).
	 */
	public static function run() {
		global $wpdb;

		$moved = 0;
		$types = array(
			'lf_form'       => array( Forms::POST_TYPE, Forms::META_KEY ),
			'lf_submission' => array( Submissions::POST_TYPE, '_lf_entries' ),
		);
		foreach ( $types as $old => list( $new, $marker ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time rename; caches are flushed below.
			$moved += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s SET p.post_type = %s WHERE p.post_type = %s",
					$marker,
					$new,
					$old
				)
			);
		}

		$meta = array(
			'_lf_form_id'  => Submissions::META_FORM,
			'_lf_entries'  => Submissions::META_ENTRIES,
			'_lf_log'      => Submissions::META_LOG,
			'_lf_page_url' => Submissions::META_PAGE,
		);
		foreach ( $meta as $old => $new ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time rename; caches are flushed below.
			$moved += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = %s SET m.meta_key = %s WHERE m.meta_key = %s",
					Submissions::POST_TYPE,
					$new,
					$old
				)
			);
		}

		if ( $moved ) {
			wp_cache_flush();
		}

		return self::move_private_dir();
	}

	/**
	 * Move uploaded files from the old default folder (uploads/launch-forms-private) to the
	 * new one. Folders set with LAUNCH_FORMS_PRIVATE_DIR or the filter are left alone.
	 *
	 * @return bool Whether nothing is left to move.
	 */
	private static function move_private_dir() {
		if ( defined( 'LAUNCH_FORMS_PRIVATE_DIR' ) && LAUNCH_FORMS_PRIVATE_DIR ) {
			return true;
		}
		$base = trailingslashit( wp_upload_dir( null, false )['basedir'] );
		$old  = $base . Uploads::LEGACY_DIR_NAME . '/';
		$new  = $base . Uploads::DIR_NAME . '/';
		if ( ! is_dir( $old ) ) {
			return true;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Moving files within uploads; WP_Filesystem may need credentials here.
		if ( ! file_exists( $new ) ) {
			return @rename( $old, $new ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ok = true;
		foreach ( (array) scandir( $old ) as $file ) {
			if ( preg_match( '/^[a-f0-9]{32}$/', (string) $file ) && ! file_exists( $new . $file ) ) {
				$ok = @rename( $old . $file, $new . $file ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		// phpcs:enable
		return $ok;
	}
}
