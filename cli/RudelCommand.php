<?php
/**
 * WP-CLI commands for Rudel sandbox management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\SandboxManager;
use Rudel\SnapshotManager;
use WP_CLI;

/**
 * Manage Rudel sandboxes.
 *
 * The command name defaults to "rudel" but can be changed by defining
 * RUDEL_CLI_COMMAND in wp-config.php before the plugin loads.
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
	 *
	 * @param SandboxManager|null $manager Optional manager instance for dependency injection.
	 */
	public function __construct( ?SandboxManager $manager = null ) {
		$this->manager = $manager ?? new SandboxManager();
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
	 * [--engine=<engine>]
	 * : Database engine for the sandbox.
	 * ---
	 * default: mysql
	 * options:
	 *   - mysql
	 *   - sqlite
	 * ---
	 *
	 * [--clone-db]
	 * : Clone the host database into the sandbox.
	 *
	 * [--clone-themes]
	 * : Copy host themes into the sandbox.
	 *
	 * [--clone-plugins]
	 * : Copy host plugins into the sandbox.
	 *
	 * [--clone-uploads]
	 * : Copy host uploads into the sandbox.
	 *
	 * [--clone-all]
	 * : Clone everything (database, themes, plugins, uploads).
	 *
	 * [--clone-from=<id>]
	 * : Clone from an existing sandbox. Mutually exclusive with --clone-db/--clone-all.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel create --name="my-sandbox"
	 *     Success: Sandbox created: my-sandbox-a1b2
	 *
	 *     $ wp rudel create --name="full-clone" --clone-all
	 *     Success: Sandbox created: full-clone-c3d4
	 *
	 *     $ wp rudel create --name="db-only" --clone-db
	 *     Success: Sandbox created: db-only-e5f6
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

		$engine     = $assoc_args['engine'] ?? 'mysql';
		$clone_all  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-all', false );
		$clone_from = $assoc_args['clone-from'] ?? null;
		$options    = array(
			'engine'        => $engine,
			'template'      => $template,
			'clone_db'      => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-db', false ),
			'clone_themes'  => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-themes', false ),
			'clone_plugins' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-plugins', false ),
			'clone_uploads' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-uploads', false ),
		);

		if ( $clone_from ) {
			$options['clone_from'] = $clone_from;
		}

		$has_clone = $options['clone_db'] || $options['clone_themes'] || $options['clone_plugins'] || $options['clone_uploads'];

		if ( $clone_from ) {
			WP_CLI::log( "Creating sandbox '{$name}' cloned from '{$clone_from}'..." );
		} elseif ( $has_clone ) {
			WP_CLI::log( "Creating sandbox '{$name}' with cloned content..." );
			if ( $options['clone_db'] ) {
				WP_CLI::log( '  Cloning host database...' );
			}
			if ( $options['clone_themes'] ) {
				WP_CLI::log( '  Cloning themes...' );
			}
			if ( $options['clone_plugins'] ) {
				WP_CLI::log( '  Cloning plugins...' );
			}
			if ( $options['clone_uploads'] ) {
				WP_CLI::log( '  Cloning uploads...' );
			}
		} else {
			WP_CLI::log( "Creating sandbox '{$name}'..." );
		}

		try {
			$sandbox = $this->manager->create( $name, $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox created: {$sandbox->id}" );

		if ( $sandbox->clone_source ) {
			$src = $sandbox->clone_source;
			WP_CLI::log( '' );
			WP_CLI::log( '  Clone summary:' );
			if ( ! empty( $src['db_cloned'] ) ) {
				WP_CLI::log( "    Database: {$src['tables_cloned']} tables, {$src['rows_cloned']} rows" );
			}
			if ( ! empty( $src['themes_cloned'] ) ) {
				WP_CLI::log( '    Themes: copied' );
			}
			if ( ! empty( $src['plugins_cloned'] ) ) {
				WP_CLI::log( '    Plugins: copied' );
			}
			if ( ! empty( $src['uploads_cloned'] ) ) {
				WP_CLI::log( '    Uploads: copied' );
			}
		}

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
					'engine'   => $sandbox->engine,
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
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'engine', 'status', 'template', 'created', 'size' ) );
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
		$data['db_path']    = $sandbox->get_db_path() ?? 'N/A (MySQL)';
		$data['url']        = $sandbox->get_url();
		$data['wp_content'] = $sandbox->get_wp_content_path();

		$format = $assoc_args['format'] ?? 'table';

		if ( 'table' === $format ) {
			$items = array();
			foreach ( $data as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
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
	public function snapshot( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$name = $assoc_args['name'];

		try {
			$snap_manager = new SnapshotManager( $sandbox );
			$meta         = $snap_manager->create( $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Snapshot created: {$meta['name']}" );
	}

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
	public function restore( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$snapshot_name = $assoc_args['snapshot'];
		$force         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			WP_CLI::confirm( "Are you sure you want to restore sandbox '{$sandbox->name}' from snapshot '{$snapshot_name}'?" );
		}

		try {
			$snap_manager = new SnapshotManager( $sandbox );
			$snap_manager->restore( $snapshot_name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox restored from snapshot: {$snapshot_name}" );
	}

	/**
	 * Clean up expired sandboxes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be removed without actually deleting.
	 *
	 * [--max-age-days=<days>]
	 * : Override the configured max age in days.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel cleanup --max-age-days=30
	 *     Removed 2 sandbox(es).
	 *
	 *     $ wp rudel cleanup --dry-run --max-age-days=7
	 *     Would remove 3 sandbox(es).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function cleanup( $args, $assoc_args ): void {
		$options = array(
			'dry_run'      => \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			'max_age_days' => (int) ( $assoc_args['max-age-days'] ?? 0 ),
		);

		$result = $this->manager->cleanup( $options );

		if ( $options['dry_run'] ) {
			$count = count( $result['removed'] );
			WP_CLI::log( "Would remove {$count} sandbox(es)." );
			foreach ( $result['removed'] as $id ) {
				WP_CLI::log( "  {$id}" );
			}
		} else {
			$count = count( $result['removed'] );
			WP_CLI::success( "Removed {$count} sandbox(es)." );
			foreach ( $result['removed'] as $id ) {
				WP_CLI::log( "  {$id}" );
			}
		}

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $id ) {
				WP_CLI::warning( "Failed to remove: {$id}" );
			}
		}
	}

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
	public function export( $args, $assoc_args ): void {
		$id          = $args[0];
		$output_path = $assoc_args['output'];

		try {
			$this->manager->export( $id, $output_path );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox exported to {$output_path}" );
	}

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
	 * @subcommand import
	 * @when after_wp_load
	 */
	public function import_( $args, $assoc_args ): void {
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
