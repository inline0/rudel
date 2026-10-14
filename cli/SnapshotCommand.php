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
	 * : Sandbox ID to snapshot, or delete to remove a snapshot.
	 *
	 * [<sandbox-id>]
	 * : Sandbox ID when the first argument is delete.
	 *
	 * --name=<name>
	 * : Name for the snapshot.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel snapshot my-sandbox-a1b2 --name=before-update
	 *     Success: Snapshot created: before-update
	 *
	 *     $ wp rudel snapshot delete my-sandbox-a1b2 --name=before-update
	 *     Success: Snapshot deleted: before-update
	 *
	 * @param list<string>         $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 * @throws \RuntimeException When the requested snapshot does not exist.
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$deleting = 'delete' === ( $args[0] ?? null );
		$id       = $deleting ? ( $args[1] ?? '' ) : ( $args[0] ?? '' );
		if ( '' === $id ) {
			WP_CLI::error( 'Provide a sandbox ID.' );
		}
		$sandbox = $this->require_environment( $id );
		$name    = is_string( $assoc_args['name'] ?? null ) ? $assoc_args['name'] : '';

		try {
			if ( $deleting ) {
				if ( ! $this->snapshot_manager( $sandbox )->delete( $name ) ) {
					throw new \RuntimeException( 'Snapshot not found: ' . $name );
				}
				WP_CLI::success( 'Snapshot deleted: ' . $name );
				return;
			}
			$meta = $this->snapshot_manager( $sandbox )->create( $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$meta_name = is_string( $meta['name'] ?? null ) ? $meta['name'] : $name;
		WP_CLI::success( "Snapshot created: {$meta_name}" );
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
