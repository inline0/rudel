<?php
/**
 * WP-CLI command to inspect sandbox logs.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use WP_CLI;

/**
 * View a sandbox's error log.
 */
class LogsCommand extends AbstractEnvironmentCommand {

	/**
	 * View a sandbox's error log.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--lines=<lines>]
	 * : Number of lines to show from the end of the log.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--follow]
	 * : Continuously watch for new log entries (like tail -f).
	 *
	 * [--clear]
	 * : Clear the log file instead of displaying it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel logs my-sandbox-a1b2
	 *     $ wp rudel logs my-sandbox-a1b2 --lines=100
	 *     $ wp rudel logs my-sandbox-a1b2 --follow
	 *     $ wp rudel logs my-sandbox-a1b2 --clear
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$sandbox  = $this->require_environment( $args[0] );
		$log_path = $sandbox->get_wp_content_path() . '/debug.log';

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'clear', false ) ) {
			if ( file_exists( $log_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Clearing log file.
				file_put_contents( $log_path, '' );
				WP_CLI::success( 'Log cleared.' );
			} else {
				WP_CLI::log( 'No log file to clear.' );
			}
			return;
		}

		if ( ! file_exists( $log_path ) ) {
			WP_CLI::log( 'No log file yet. Errors will appear in:' );
			WP_CLI::log( "  {$log_path}" );
			return;
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'follow', false ) ) {
			WP_CLI::log( "Following {$log_path} (Ctrl+C to stop)" );
			WP_CLI::log( '' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru -- Intentional: tail -f for live log following.
			passthru( 'tail -f ' . escapeshellarg( $log_path ) );
			return;
		}

		$lines = (int) ( $assoc_args['lines'] ?? 50 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading log file.
		$content = file_get_contents( $log_path );
		if ( '' === $content ) {
			WP_CLI::log( 'Log file is empty.' );
			return;
		}

		$all_lines = explode( "\n", rtrim( $content, "\n" ) );
		$total     = count( $all_lines );
		$show      = array_slice( $all_lines, -$lines );

		if ( $total > $lines ) {
			WP_CLI::log( "Showing last {$lines} of {$total} lines:" );
			WP_CLI::log( '' );
		}

		foreach ( $show as $line ) {
			WP_CLI::log( $line );
		}
	}
}
