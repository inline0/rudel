<?php
/**
 * Plugin Name: Rudel
 * Description: Sandboxed WordPress environments with isolated databases and content.
 * Version: 0.1.0
 * Author: Rudel
 * License: GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires at least: 6.4
 *
 * @package Rudel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RUDEL_VERSION', '0.1.0' );
define( 'RUDEL_PLUGIN_FILE', __FILE__ );
define( 'RUDEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUDEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$rudel_autoload = RUDEL_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $rudel_autoload ) ) {
	// Composer package: vendor/rudel/rudel/ -> vendor/autoload.php.
	$rudel_autoload = dirname( __DIR__, 2 ) . '/autoload.php';
}
if ( file_exists( $rudel_autoload ) ) {
	require_once $rudel_autoload;
}
unset( $rudel_autoload );

register_activation_hook(
	__FILE__,
	function () {
		$writer = new Rudel\ConfigWriter();
		$writer->install();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		$writer = new Rudel\ConfigWriter();
		$writer->uninstall();
	}
);

if ( ! defined( 'RUDEL_CLI_COMMAND' ) ) {
	define( 'RUDEL_CLI_COMMAND', 'rudel' );
}

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( RUDEL_CLI_COMMAND, Rudel\CLI\RudelCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' template', Rudel\CLI\TemplateCommand::class );
}
