<?php
/**
 * Plugin Name: Rudel
 * Description: The WordPress isolation layer for sandboxes and multi-tenant apps.
 * Version: 0.2.0
 * Author: Inline0
 * Author URI: https://inline0.com
 * License: GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires at least: 6.4
 *
 * @package Rudel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RUDEL_VERSION', '0.2.0' );
define( 'RUDEL_PLUGIN_FILE', __FILE__ );
define( 'RUDEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUDEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$rudel_autoload = RUDEL_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $rudel_autoload ) ) {
	// Composer can install Rudel as a library or as a plugin, so the autoloader does not always live under this directory.
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
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' app', Rudel\CLI\AppCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' cleanup', Rudel\CLI\CleanupCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' export', Rudel\CLI\ExportCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' import', Rudel\CLI\ImportCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' logs', Rudel\CLI\LogsCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' pr', Rudel\CLI\PrCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' promote', Rudel\CLI\PromoteCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' push', Rudel\CLI\PushCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' restore', Rudel\CLI\RestoreCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' snapshot', Rudel\CLI\SnapshotCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' template', Rudel\CLI\TemplateCommand::class );
}

if ( ! defined( 'RUDEL_RUNTIME_HOOKS_LOADED' ) ) {
	define( 'RUDEL_RUNTIME_HOOKS_LOADED', true );

	// Register this unconditionally so late-defined environment constants can still suppress mail before it leaves PHP.
	add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) {
			if ( ! Rudel\Rudel::is_email_disabled() ) {
				return $null;
			}

			$to = $atts['to'] ?? '';
			if ( is_array( $to ) ) {
				$to = implode( ', ', array_map( 'strval', $to ) );
			}

			$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';

			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'RUDEL_ID' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in sandbox debug.log.
				error_log( sprintf( 'Rudel: email blocked in sandbox %s (to: %s, subject: %s)', RUDEL_ID, $to, $subject ) );
			}

			return true;
		},
		10,
		2
	);

	if ( Rudel\Rudel::is_sandbox() || Rudel\Rudel::is_app() ) {
		add_action(
			'admin_bar_menu',
			function ( $wp_admin_bar ) {
				$is_app = Rudel\Rudel::is_app();
				$wp_admin_bar->add_node(
					array(
						'id'    => 'rudel-environment',
						'title' => '&#9632; ' . ( $is_app ? 'App' : 'Sandbox' ) . ': ' . ( $is_app ? Rudel\Rudel::app_id() : Rudel\Rudel::id() ),
						'href'  => $is_app ? Rudel\Rudel::url() : Rudel\Rudel::exit_url(),
						'meta'  => array(
							'title' => $is_app
								? 'Current Rudel app environment'
								: 'Click to exit sandbox and return to host',
						),
					)
				);
			},
			1
		);

		add_action(
			'wp_head',
			'rudel_admin_bar_styles'
		);
		add_action(
			'admin_head',
			'rudel_admin_bar_styles'
		);
	}
}

/**
 * Output admin bar styles for the sandbox indicator.
 *
 * @return void
 */
function rudel_admin_bar_styles() {
	$color = Rudel\Rudel::is_app() ? '#2271b1' : '#d63638';
	echo '<style>#wp-admin-bar-rudel-environment > a { background: ' . esc_attr( $color ) . ' !important; color: #fff !important; font-weight: 600 !important; }</style>';
}
