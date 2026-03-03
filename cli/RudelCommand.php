<?php
/**
 * WP-CLI commands for Rudel sandbox management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\SandboxManager;
use WP_CLI;

/**
 * Manage Rudel sandboxes.
 *
 * ## EXAMPLES
 *
 *     # Create a new sandbox
 *     $ wp rudel create --name="my-sandbox"
 *
 *     # List all sandboxes
 *     $ wp rudel list
 *
 *     # Show sandbox details
 *     $ wp rudel info my-sandbox-a1b2
 *
 *     # Delete a sandbox
 *     $ wp rudel destroy my-sandbox-a1b2 --force
 */
class RudelCommand extends \WP_CLI_Command {

	/**
	 * Sandbox manager instance.
	 *
	 * @var SandboxManager
	 */
	private SandboxManager $manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->manager = new SandboxManager();
	}

	/**
	 * Create a new sandbox.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Human-readable name for the sandbox.
	 *
	 * [--template=<template>]
	 * : Template to use. Default: blank.
	 * ---
	 * default: blank
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel create --name="my-sandbox"
	 *     Success: Sandbox created: my-sandbox-a1b2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ): void {
		$name     = $assoc_args['name'];
		$template = $assoc_args['template'] ?? 'blank';

		WP_CLI::log( "Creating sandbox '{$name}'..." );

		try {
			$sandbox = $this->manager->create( $name, array( 'template' => $template ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox created: {$sandbox->id}" );
		WP_CLI::log( '' );
		WP_CLI::log( "  Path: {$sandbox->path}" );
		WP_CLI::log( "  URL:  {$sandbox->get_url()}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'To use this sandbox:' );
		WP_CLI::log( "  cd {$sandbox->path}" );
		WP_CLI::log( '  wp post list' );
	}

	/**
	 * List all sandboxes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel list
	 *     $ wp rudel list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ): void {
		$sandboxes = $this->manager->list();

		if ( empty( $sandboxes ) ) {
			WP_CLI::log( 'No sandboxes found.' );
			return;
		}

		$items = array_map(
			function ( $sandbox ) {
				$size = $sandbox->get_size();
				return array(
					'id'       => $sandbox->id,
					'name'     => $sandbox->name,
					'status'   => $sandbox->status,
					'template' => $sandbox->template,
					'created'  => $sandbox->created_at,
					'size'     => $this->format_size( $size ),
					'path'     => $sandbox->path,
				);
			},
			$sandboxes
		);

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'status', 'template', 'created', 'size' ) );
	}

	/**
	 * Show sandbox details.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel info my-sandbox-a1b2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function info( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$data               = $sandbox->to_array();
		$data['size']       = $this->format_size( $sandbox->get_size() );
		$data['db_path']    = $sandbox->get_db_path();
		$data['url']        = $sandbox->get_url();
		$data['wp_content'] = $sandbox->get_wp_content_path();

		$format = $assoc_args['format'] ?? 'table';

		if ( 'table' === $format ) {
			$items = array();
			foreach ( $data as $key => $value ) {
				$items[] = array(
					'Field' => $key,
					'Value' => $value,
				);
			}
			WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
		} else {
			WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
		}
	}

	/**
	 * Destroy a sandbox.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to destroy.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel destroy my-sandbox-a1b2 --force
	 *     Success: Sandbox destroyed: my-sandbox-a1b2
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function destroy( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			WP_CLI::confirm( "Are you sure you want to destroy sandbox '{$sandbox->name}' ({$id})?" );
		}

		if ( $this->manager->destroy( $id ) ) {
			WP_CLI::success( "Sandbox destroyed: {$id}" );
		} else {
			WP_CLI::error( "Failed to destroy sandbox: {$id}" );
		}
	}

	/**
	 * Show Rudel status and configuration.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ): void {
		$writer      = new \Rudel\ConfigWriter();
		$sandboxes   = $this->manager->list();
		$sqlite_path = defined( 'RUDEL_PLUGIN_DIR' )
			? RUDEL_PLUGIN_DIR . 'lib/sqlite-database-integration'
			: dirname( __DIR__ ) . '/lib/sqlite-database-integration';

		$items = array(
			array(
				'Field' => 'Bootstrap installed',
				'Value' => $writer->is_installed() ? 'yes' : 'no',
			),
			array(
				'Field' => 'Sandboxes directory',
				'Value' => $this->manager->get_sandboxes_dir(),
			),
			array(
				'Field' => 'Active sandboxes',
				'Value' => (string) count( $sandboxes ),
			),
			array(
				'Field' => 'SQLite integration',
				'Value' => is_dir( $sqlite_path ) ? 'installed' : 'not installed',
			),
			array(
				'Field' => 'PHP version',
				'Value' => PHP_VERSION,
			),
			array(
				'Field' => 'SQLite3 extension',
				'Value' => extension_loaded( 'sqlite3' ) ? 'loaded' : 'not loaded',
			),
			array(
				'Field' => 'PDO SQLite',
				'Value' => extension_loaded( 'pdo_sqlite' ) ? 'loaded' : 'not loaded',
			),
		);

		WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
	}

	/**
	 * Format a byte count into a human-readable string.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Formatted size string.
	 */
	private function format_size( int $bytes ): string {
		$units      = array( 'B', 'KB', 'MB', 'GB' );
		$i          = 0;
		$size       = (float) $bytes;
		$unit_count = count( $units );
		while ( $size >= 1024 && $i < $unit_count - 1 ) {
			$size /= 1024;
			++$i;
		}
		return round( $size, 1 ) . ' ' . $units[ $i ];
	}
}
