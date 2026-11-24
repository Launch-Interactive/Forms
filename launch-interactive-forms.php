<?php
/**
 * Plugin Name:       Launch Interactive Forms
 * Plugin URI:        https://github.com/Launch-Interactive/launch-interactive-forms
 * Description:       Lightweight form builder that outputs minimal HTML and inherits your theme's styling. Build forms in the block editor or the Forms screen, reuse them anywhere with a block or shortcode.
 * Version:           2.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Launch Interactive
 * Author URI:        https://github.com/Launch-Interactive
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       launch-interactive-forms
 *
 * @package LaunchInteractiveForms
 */

defined( 'ABSPATH' ) || exit;

define( 'LIFORMS_VERSION', '2.1.0' );
define( 'LIFORMS_FILE', __FILE__ );
define( 'LIFORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIFORMS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'LaunchInteractiveForms\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'LaunchInteractiveForms\\' ) ) ) );
		$file = LIFORMS_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Access the plugin container.
 *
 * @return \LaunchInteractiveForms\Plugin
 */
function liforms() {
	return \LaunchInteractiveForms\Plugin::instance();
}

liforms()->boot();

register_activation_hook( __FILE__, array( '\LaunchInteractiveForms\Plugin', 'activate' ) );
