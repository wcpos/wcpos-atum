<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

$polyfills_path = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( file_exists( $polyfills_path ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
}

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$plugin_dir = dirname( __DIR__ );

		$plugins = array(
			$plugin_dir . '/../woocommerce/woocommerce.php',
			$plugin_dir . '/../woocommerce-pos/woocommerce-pos.php',
			$plugin_dir . '/../woocommerce-pos-pro/woocommerce-pos-pro.php',
			$plugin_dir . '/wcpos-atum.php',
		);

		foreach ( $plugins as $plugin ) {
			if ( file_exists( $plugin ) ) {
				require_once $plugin;
			}
		}
	}
);

require $_tests_dir . '/includes/bootstrap.php';
