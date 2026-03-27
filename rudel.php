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

// Sandbox-specific hooks (only when a sandbox is active).
if ( Rudel\Rudel::is_sandbox() ) {

	// Disable outbound email when RUDEL_DISABLE_EMAIL is true.
	if ( Rudel\Rudel::is_email_disabled() ) {
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in sandbox debug.log.
					error_log( sprintf( 'Rudel: email blocked in sandbox %s (to: %s, subject: %s)', RUDEL_SANDBOX_ID, $atts['to'], $atts['subject'] ) );
				}
				return true;
			},
			10,
			2
		);
	}

	// Admin bar indicator: show current sandbox with exit link.
	add_action(
		'admin_bar_menu',
		function ( $wp_admin_bar ) {
			$wp_admin_bar->add_node(
				array(
					'id'    => 'rudel-sandbox',
					'title' => '&#9632; Sandbox: ' . Rudel\Rudel::id(),
					'href'  => Rudel\Rudel::exit_url(),
					'meta'  => array( 'title' => 'Click to exit sandbox and return to host' ),
				)
			);
		},
		1
	);

	// Style the admin bar indicator.
	add_action(
		'wp_head',
		'rudel_admin_bar_styles'
	);
	add_action(
		'admin_head',
		'rudel_admin_bar_styles'
	);
}

/**
 * Output admin bar styles for the sandbox indicator.
 *
 * @return void
 */
function rudel_admin_bar_styles() {
	echo '<style>#wp-admin-bar-rudel-sandbox > a { background: #d63638 !important; color: #fff !important; font-weight: 600 !important; }</style>';
}
