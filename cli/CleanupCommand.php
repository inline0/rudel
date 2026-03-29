<?php
/**
 * WP-CLI command to clean up sandboxes.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use WP_CLI;

/**
 * Clean up expired or merged sandboxes.
 */
class CleanupCommand extends AbstractEnvironmentCommand {

	/**
	 * Clean up expired or merged sandboxes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be removed without actually deleting.
	 *
	 * [--max-age-days=<days>]
	 * : Override the configured max age in days.

	 * [--max-idle-days=<days>]
	 * : Override the configured max idle time in days.
	 *
	 * [--merged]
	 * : Remove sandboxes whose git branches have been merged into main.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel cleanup --max-age-days=30
	 *     Removed 2 sandbox(es).
	 *
	 *     $ wp rudel cleanup --merged
	 *     Removed 1 sandbox(es) with merged branches.
	 *
	 *     $ wp rudel cleanup --merged --dry-run
	 *     Would remove 1 sandbox(es) with merged branches.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$merged  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'merged', false );

		if ( $merged ) {
			$result = $this->manager->cleanup_merged( array( 'dry_run' => $dry_run ) );
			$label  = 'with merged branches';
		} else {
			$result = $this->manager->cleanup(
				array(
					'dry_run'       => $dry_run,
					'max_age_days'  => (int) ( $assoc_args['max-age-days'] ?? 0 ),
					'max_idle_days' => (int) ( $assoc_args['max-idle-days'] ?? 0 ),
				)
			);
			$label  = '';
		}

		$count = count( $result['removed'] );
		if ( $dry_run ) {
			WP_CLI::log( "Would remove {$count} sandbox(es) {$label}." );
		} else {
			WP_CLI::success( "Removed {$count} sandbox(es) {$label}." );
		}

		foreach ( $result['removed'] as $id ) {
			$reason = $result['reasons'][ $id ] ?? null;
			if ( is_string( $reason ) ) {
				$reason = \Rudel\EnvironmentPolicy::describe_cleanup_reason( $reason );
				WP_CLI::log( "  {$id} ({$reason})" );
				continue;
			}

			WP_CLI::log( "  {$id}" );
		}

		foreach ( $result['errors'] as $id ) {
			WP_CLI::warning( "Failed to remove: {$id}" );
		}
	}
}
