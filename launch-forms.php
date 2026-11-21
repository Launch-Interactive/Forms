<?php
/**
 * Plugin Name:       Launch Forms
 * Description:       Lightweight form builder that outputs minimal HTML and inherits your theme's styling. Build forms in the block editor or the Forms screen, reuse them anywhere with a block or shortcode.
 * Version:           1.3.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Launch Forms
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       launch-forms
 *
 * @package LaunchForms
 */

defined( 'ABSPATH' ) || exit;

define( 'LAUNCH_FORMS_VERSION', '1.3.1' );
define( 'LAUNCH_FORMS_FILE', __FILE__ );
define( 'LAUNCH_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAUNCH_FORMS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'LaunchForms\\' ) ) {
			return;
		}
		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'LaunchForms\\' ) ) ) );
		$file = LAUNCH_FORMS_DIR . 'includes/class-' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Access the plugin container.
 *
 * @return \LaunchForms\Plugin
 */
function launch_forms() {
	return \LaunchForms\Plugin::instance();
}

launch_forms()->boot();

register_activation_hook( __FILE__, array( '\LaunchForms\Plugin', 'activate' ) );
