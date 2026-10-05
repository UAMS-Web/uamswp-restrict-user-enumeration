<?php
/**
 * Shared bootstrap logic for both suites.
 *
 * The kill switch (FR5) is decided when the plugin file is first included and a
 * constant cannot be undefined afterwards, so the two states need two PHP
 * processes. tests/bootstrap.php loads the plugin normally;
 * tests/bootstrap-killswitch.php defines UAMSWP_RUE_DISABLE first. Everything
 * else about the two runs is identical, and lives here.
 *
 * @package UAMSWP_Restrict_User_Enumeration
 */

/**
 * Load the WordPress test suite with this plugin installed as an mu-plugin.
 *
 * @return void
 */
function uamswp_rue_tests_bootstrap() {
	$tests_dir = getenv( 'WP_TESTS_DIR' );

	if ( ! $tests_dir ) {
		$tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}

	if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
		echo "Could not find the WordPress test suite in {$tests_dir}." . PHP_EOL;
		echo 'Run bin/install-wp-tests.sh, or set WP_TESTS_DIR.' . PHP_EOL;
		exit( 1 );
	}

	require_once $tests_dir . '/includes/functions.php';

	// mu-plugins load on muplugins_loaded, which is where this one has to be
	// registered for the hook ordering the tests assert to be the real thing.
	tests_add_filter(
		'muplugins_loaded',
		function () {
			require dirname( __DIR__ ) . '/uamswp-restrict-user-enumeration.php';
		}
	);

	require $tests_dir . '/includes/bootstrap.php';

	require_once $tests_dir . '/includes/testcase-rest-api.php';
	require_once __DIR__ . '/class-uamswp-rue-redirect-exception.php';
}
