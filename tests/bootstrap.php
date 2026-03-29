<?php
/**
 * Test bootstrap for Rudel.
 *
 * Sets up temp directories, defines stubs for WordPress constants
 * that the source code references, and loads the Composer autoloader.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

// WordPress constants used by production code.
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 1 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 1 );
}

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['rudel_test_actions'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		$GLOBALS['rudel_test_filters'][] = array(
			'hook'  => $hook,
			'value' => $value,
			'args'  => $args,
		);

		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

// Global temp directory for all tests -- each test class manages its own subdirectory.
define( 'RUDEL_TEST_TMPDIR', sys_get_temp_dir() . '/rudel-tests-' . getmypid() );

// Ensure a clean slate
if ( is_dir( RUDEL_TEST_TMPDIR ) ) {
	exec( 'rm -rf ' . escapeshellarg( RUDEL_TEST_TMPDIR ) );
}
mkdir( RUDEL_TEST_TMPDIR, 0755, true );

// Cleanup on shutdown
register_shutdown_function(
	function () {
		if ( is_dir( RUDEL_TEST_TMPDIR ) ) {
			exec( 'rm -rf ' . escapeshellarg( RUDEL_TEST_TMPDIR ) );
		}
	}
);
