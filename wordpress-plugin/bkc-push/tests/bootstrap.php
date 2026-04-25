<?php
/**
 * PHPUnit bootstrap for bkc-push plugin tests.
 *
 * Loads the WordPress test library when available (for WP_UnitTestCase), or
 * sets up minimal stubs so unit tests that do not need full WP can run without
 * a database.
 *
 * @package bkc-push
 */

// Define the FCM service account path to the test fixture.
define( 'BKC_FCM_SERVICE_ACCOUNT_PATH', __DIR__ . '/fixtures/fcm-service-account.json' );

// Try to load wordpress-tests-lib (wp-tests-config.php must be on the path or
// WP_TESTS_DIR must be set).
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	// Full WordPress test suite is available.
	require_once $_tests_dir . '/includes/functions.php';

	/**
	 * Manually load the plugin under test.
	 */
	function _manually_load_plugin(): void {
		define( 'ABSPATH', dirname( __DIR__, 4 ) . '/wordpress/' );
		define( 'BKC_PUSH_VERSION', '1.0.0' );
		define( 'BKC_PUSH_DIR', dirname( __DIR__ ) . '/' );
		define( 'BKC_PUSH_URL', 'http://localhost/wp-content/plugins/bkc-push/' );

		require dirname( __DIR__ ) . '/bkc-push.php';
	}

	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

	require $_tests_dir . '/includes/bootstrap.php';
} else {
	// Minimal stubs for tests that do not require the full WP stack.
	_bkc_load_wp_stubs();
	_bkc_load_plugin_classes();
}

/**
 * Load lightweight WordPress function stubs for offline unit tests.
 */
function _bkc_load_wp_stubs(): void {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'BKC_PUSH_VERSION' ) ) {
		define( 'BKC_PUSH_VERSION', '1.0.0' );
	}
	if ( ! defined( 'BKC_PUSH_DIR' ) ) {
		define( 'BKC_PUSH_DIR', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'BKC_PUSH_URL' ) ) {
		define( 'BKC_PUSH_URL', 'http://localhost/wp-content/plugins/bkc-push/' );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}

	require_once __DIR__ . '/stubs/wp-stubs.php';
}

/**
 * Load all plugin class files.
 */
function _bkc_load_plugin_classes(): void {
	$includes = dirname( __DIR__ ) . '/includes/';
	require_once $includes . 'class-bkc-groups.php';
	require_once $includes . 'class-bkc-rate-limiter.php';
	require_once $includes . 'class-bkc-subscriptions.php';
	require_once $includes . 'class-bkc-campaigns.php';
	require_once $includes . 'class-bkc-events.php';
	require_once $includes . 'class-bkc-fcm-client.php';
	require_once $includes . 'class-bkc-dispatcher.php';
	require_once $includes . 'class-bkc-stats-rollup.php';
	require_once $includes . 'class-bkc-rest-api.php';
}
