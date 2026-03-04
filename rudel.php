<?php
/**
 * Plugin Name: Rudel
 * Description: Sandboxed WordPress environments powered by SQLite.
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

require_once RUDEL_PLUGIN_DIR . 'vendor/autoload.php';

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

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'rudel', Rudel\CLI\RudelCommand::class );
	WP_CLI::add_command( 'rudel template', Rudel\CLI\TemplateCommand::class );
}
