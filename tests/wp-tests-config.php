<?php
/**
 * WordPress test-suite configuration (SQLite, no MySQL required).
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/.wp-tests/wordpress/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_ENGINE', 'sqlite' );
define( 'DB_DIR', dirname( __DIR__ ) . '/.wp-tests/database/' );
define( 'DB_FILE', 'tests.sqlite' );

// Required by the test suite even though SQLite ignores them.
define( 'DB_NAME', 'launch_forms_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
