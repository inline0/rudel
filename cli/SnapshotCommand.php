<?php
/**
 * WP-CLI command to create sandbox snapshots.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\SnapshotManager;
use WP_CLI;

/**
 * Create a snapshot of a sandbox.
 */
class SnapshotCommand extends AbstractEnvironmentCommand {

	/**
	 * Create a snapshot of a sandbox.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to snapshot.
	 *
	 * --name=<name>
	 * : Name for the snapshot.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel snapshot my-sandbox-a1b2 --name=before-update
	 *     Success: Snapshot created: before-update
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$sandbox = $this->require_environment( $args[0] );
		$name    = $assoc_args['name'];

		try {
			$meta = $this->snapshot_manager( $sandbox )->create( $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Snapshot created: {$meta['name']}" );
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
