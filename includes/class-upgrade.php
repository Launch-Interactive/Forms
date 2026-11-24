<?php
/**
 * One-time data upgrades between plugin versions.
 *
 * @package LaunchInteractiveForms
 */

namespace LaunchInteractiveForms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per site, the first time a newer version loads.
 */
final class Upgrade {

	/** Option holding the data version this site has been upgraded to. */
	const OPTION = 'liforms_db_version';

	/** Current data version. */
	const VERSION = 3;

	/** Old option names (1.x and 2.0 used the "launch_forms" prefix). */
	const LEGACY_OPTIONS = array(
		'launch_forms_settings' => Settings::OPTION,
	);

	/** Old names of the content markers: block comment and shortcode. */
	const CONTENT_REPLACEMENTS = array(
		'<!-- wp:launch-forms/form' => '<!-- wp:liforms/form',
		'[launch_form '             => '[liforms ',
		'[launch_form]'             => '[liforms]',
	);

	/**
	 * Run any upgrades this site hasn't had yet.
	 */
	public static function maybe_run() {
		if ( (int) get_option( self::OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		if ( self::run() ) {
			update_option( self::OPTION, self::VERSION, true );
			delete_option( 'launch_forms_db_version' );
		}
	}

	/**
	 * Move data saved under the old names (1.x "lf_", 2.0 "launch_forms_") to the "liforms"
	 * names. Only rows that are clearly ours are moved: forms that carry the plugin's config
	 * and submissions that carry its entries. Safe to run more than once.
	 *
	 * @return bool Whether everything was moved (false means try again next time).
	 */
	public static function run() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-time renames; caches are flushed below.

		foreach ( self::LEGACY_OPTIONS as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value ) {
				if ( false === get_option( $new, false ) ) {
					add_option( $new, $value );
				}
				delete_option( $old );
			}
		}

		$moved = 0;

		// Form config meta, then the form post type (identified by that config).
		$moved += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type IN ( %s, %s ) SET m.meta_key = %s WHERE m.meta_key = %s",
				'lf_form',
				'launch_forms_form',
				Forms::META_KEY,
				'launch_forms_config'
			)
		);
		$moved += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s SET p.post_type = %s WHERE p.post_type IN ( %s, %s )",
				Forms::META_KEY,
				Forms::POST_TYPE,
				'lf_form',
				'launch_forms_form'
			)
		);

		// Submissions (identified by their entries meta), then their meta.
		$moved += (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( %s, %s ) SET p.post_type = %s WHERE p.post_type IN ( %s, %s )",
				'_lf_entries',
				'_launch_forms_entries',
				Submissions::POST_TYPE,
				'lf_submission',
				'launch_forms_entry'
			)
		);
		$meta = array(
			'form_id'  => Submissions::META_FORM,
			'entries'  => Submissions::META_ENTRIES,
			'log'      => Submissions::META_LOG,
			'page_url' => Submissions::META_PAGE,
		);
		foreach ( $meta as $suffix => $new ) {
			$moved += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = %s SET m.meta_key = %s WHERE m.meta_key IN ( %s, %s )",
					Submissions::POST_TYPE,
					$new,
					'_lf_' . $suffix,
					'_launch_forms_' . $suffix
				)
			);
		}

		// Blocks and shortcodes in post content, and shortcodes in Elementor's page data.
		foreach ( self::CONTENT_REPLACEMENTS as $old => $new ) {
			$like   = '%' . $wpdb->esc_like( $old ) . '%';
			$moved += (int) $wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s", $old, $new, $like )
			);
			$moved += (int) $wpdb->query(
				$wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE( meta_value, %s, %s ) WHERE meta_key = %s AND meta_value LIKE %s", $old, $new, '_elementor_data', $like )
			);
		}
		// phpcs:enable

		// Widgets are serialized arrays, so they're updated in PHP rather than with REPLACE().
		foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $option ) {
			$value = get_option( $option );
			if ( is_array( $value ) ) {
				$updated = self::replace_deep( $value );
				if ( $updated !== $value ) {
					update_option( $option, $updated );
				}
			}
		}

		if ( $moved ) {
			wp_cache_flush();
			// Elementor keeps rendered copies of pages; let it rebuild them with the new names.
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}

		return self::move_private_dir();
	}

	/**
	 * Apply CONTENT_REPLACEMENTS to every string in a nested array.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function replace_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'replace_deep' ), $value );
		}
		return is_string( $value ) ? strtr( $value, self::CONTENT_REPLACEMENTS ) : $value;
	}

	/**
	 * Move uploaded files from the 1.x default folder (uploads/launch-forms-private) to the
	 * current one. Folders set with LIFORMS_PRIVATE_DIR are left alone.
	 *
	 * @return bool Whether nothing is left to move.
	 */
	private static function move_private_dir() {
		if ( defined( 'LIFORMS_PRIVATE_DIR' ) && LIFORMS_PRIVATE_DIR ) {
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
