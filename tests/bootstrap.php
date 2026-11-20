<?php
/**
 * PHPUnit bootstrap: loads WordPress' test suite on SQLite and activates the plugin.
 */

$root = dirname( __DIR__ );

if ( ! file_exists( $root . '/.wp-tests/wordpress/wp-content/db.php' ) ) {
	fwrite( STDERR, "Test WordPress install missing. Run: composer install-wp-tests\n" );
	exit( 1 );
}

require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $root ) {
		require $root . '/launch-forms.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/php/class-lf-testcase.php';
