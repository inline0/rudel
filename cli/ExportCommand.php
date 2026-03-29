<?php
/**
 * WP-CLI command to export sandboxes.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use WP_CLI;

/**
 * Export a sandbox as a zip archive.
 */
class ExportCommand extends AbstractEnvironmentCommand {

	/**
	 * Export a sandbox as a zip archive.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to export.
	 *
	 * --output=<path>
	 * : Output path for the zip file.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel export my-sandbox-a1b2 --output=/tmp/sandbox.zip
	 *     Success: Sandbox exported to /tmp/sandbox.zip
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$id          = $args[0];
		$output_path = $assoc_args['output'];

		try {
			$this->manager->export( $id, $output_path );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox exported to {$output_path}" );
	}
}
