<?php
/**
 * PHPUnit bootstrap file for setting up WordPress testing.
 *
 * For integration tests, not unit tests.
 */

define('DOING_TL_VENDOR_TESTS', true); // Added by TrustedLogin
if( ! defined('WP_RUN_CORE_TESTS')){
	define('WP_RUN_CORE_TESTS', false );
}
if( ! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')){
	define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname(__FILE__,2).'/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php');
}

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
	$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (! file_exists($_tests_dir . '/includes/functions.php')) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?";
	exit(1);
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin()
{
	require dirname(dirname(__FILE__)) . '/trustedlogin-vendor.php';
	try {
		$dotenv = Dotenv\Dotenv::createImmutable(dirname(dirname(__FILE__)));
		$dotenv->load();

	} catch (\Throwable $th) {
		echo 'You must set .env. See README.md';
		throw $th;
		exit;
	}
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
