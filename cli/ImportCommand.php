<?php
/**
 * WP-CLI command to import sandboxes.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use WP_CLI;

/**
 * Import a sandbox from a zip archive.
 */
class ImportCommand extends AbstractEnvironmentCommand {

	/**
	 * Import a sandbox from a zip archive.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the zip file to import.
	 *
	 * --name=<name>
	 * : Human-readable name for the imported sandbox.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel import /tmp/sandbox.zip --name=imported-sandbox
	 *     Success: Sandbox imported: imported-sandbox-a1b2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$zip_path = $args[0];
		$name     = $assoc_args['name'];

		try {
			$sandbox = $this->manager->import( $zip_path, $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox imported: {$sandbox->id}" );
		WP_CLI::log( "  Path: {$sandbox->path}" );
	}
}
