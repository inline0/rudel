<?php
/**
 * WP-CLI command to restore sandbox snapshots.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\SnapshotManager;
use WP_CLI;

/**
 * Restore a sandbox from a snapshot.
 */
class RestoreCommand extends AbstractEnvironmentCommand {

	/**
	 * Restore a sandbox from a snapshot.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to restore.
	 *
	 * --snapshot=<name>
	 * : Snapshot name to restore from.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel restore my-sandbox-a1b2 --snapshot=before-update --force
	 *     Success: Sandbox restored from snapshot: before-update
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$sandbox       = $this->require_environment( $args[0] );
		$snapshot_name = $assoc_args['snapshot'];
		$force         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			WP_CLI::confirm( "Are you sure you want to restore sandbox '{$sandbox->name}' from snapshot '{$snapshot_name}'?" );
		}

		try {
			$this->snapshot_manager( $sandbox )->restore( $snapshot_name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox restored from snapshot: {$snapshot_name}" );
	}

	/**
	 * Create a snapshot manager instance.
	 *
	 * @param \Rudel\Environment $sandbox Sandbox being managed.
	 * @return SnapshotManager
	 */
	protected function snapshot_manager( \Rudel\Environment $sandbox ): SnapshotManager {
		return new SnapshotManager( $sandbox );
	}
}
