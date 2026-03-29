<?php
/**
 * WP-CLI command to promote sandboxes to the host.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use WP_CLI;

/**
 * Promote a sandbox to replace the host site.
 */
class PromoteCommand extends AbstractEnvironmentCommand {

	/**
	 * Promote a sandbox to replace the host site.
	 *
	 * Copies the sandbox's database and wp-content to the host, rewriting
	 * all URLs and table prefixes. A backup of the host is created first.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to promote.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * [--backup-dir=<path>]
	 * : Directory for the host backup. Default: {environments_dir}/_backups/{timestamp}
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel promote my-sandbox-a1b2
	 *     Warning: This will replace the host site with the sandbox's state.
	 *     A backup will be created before proceeding.
	 *     Are you sure? [y/N] y
	 *     Success: Sandbox promoted to host. Backup at /path/to/backup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->require_environment( $id );

		if ( $sandbox->is_subsite() ) {
			WP_CLI::error( 'Promote is not supported for subsite-engine sandboxes.' );
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( ! $force ) {
			WP_CLI::warning( 'This will replace the host site with the sandbox\'s state.' );
			WP_CLI::log( 'A backup of the current host will be created before proceeding.' );
			WP_CLI::confirm( 'Are you sure?', $assoc_args );
		}

		$backup_dir = $assoc_args['backup-dir'] ?? $this->manager->get_environments_dir() . '/_backups/' . gmdate( 'Ymd_His' );
		WP_CLI::log( 'Backing up host...' );

		try {
			$result = $this->manager->promote( $id, $backup_dir );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( 'Sandbox promoted to host.' );
		WP_CLI::log( '' );
		WP_CLI::log( "  Backup: {$result['backup_path']}" );
		WP_CLI::log( "  Backup prefix: {$result['backup_prefix']}" );
		WP_CLI::log( "  Tables copied: {$result['tables_copied']}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'To undo, restore from the backup tables using the prefix above.' );
	}
}
