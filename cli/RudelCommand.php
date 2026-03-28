<?php
/**
 * WP-CLI commands for Rudel sandbox management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\EnvironmentManager;
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
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $manager;

	/**
	 * Constructor.
	 *
	 * @param EnvironmentManager|null $manager Optional manager instance for dependency injection.
	 */
	public function __construct( ?EnvironmentManager $manager = null ) {
		$this->manager = $manager ?? new EnvironmentManager();
	}

	/**
	 * Create a new sandbox.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Human-readable name. Auto-generated from --github repo or random if omitted.
	 *
	 * [--github=<repo>]
	 * : GitHub repository (owner/repo). Creates a branch and downloads files into the sandbox.
	 *
	 * [--template=<template>]
	 * : Template to use. Default: blank.
	 * ---
	 * default: blank
	 * ---
	 *
	 * [--engine=<engine>]
	 * : Database engine for the sandbox. Use 'subsite' on multisite installations to create a sub-site.
	 * ---
	 * default: mysql
	 * options:
	 *   - mysql
	 *   - sqlite
	 *   - subsite
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
	 * [--type=<type>]
	 * : Content type for --github downloads: 'theme' or 'plugin'.
	 * ---
	 * default: theme
	 * options:
	 *   - theme
	 *   - plugin
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel create
	 *     Success: Sandbox created: sandbox-a1b2
	 *
	 *     $ wp rudel create --name="my-sandbox"
	 *     Success: Sandbox created: my-sandbox-a1b2
	 *
	 *     $ wp rudel create --github=inline0/my-theme
	 *     Success: Sandbox created: my-theme-c3d4
	 *
	 *     $ wp rudel create --clone-all
	 *     Success: Sandbox created: sandbox-e5f6
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @throws \RuntimeException If sandbox creation fails.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ): void {
		$github_repo = $assoc_args['github'] ?? null;

		// Derive name: explicit > GitHub repo name > "sandbox".
		if ( ! empty( $assoc_args['name'] ) ) {
			$name = $assoc_args['name'];
		} elseif ( $github_repo ) {
			$name = basename( $github_repo );
		} else {
			$name = 'sandbox';
		}

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

		// GitHub worktree: create branch and download repo files.
		if ( $github_repo ) {
			try {
				$github    = new \Rudel\GitHubIntegration( $github_repo );
				$branch    = $sandbox->get_git_branch();
				$repo_name = basename( $github_repo );

				WP_CLI::log( '' );
				WP_CLI::log( "  GitHub: {$github_repo}" );

				// Create branch.
				try {
					$github->create_branch( $branch );
					WP_CLI::log( "  Branch: {$branch} (created)" );
				} catch ( \RuntimeException $e ) {
					if ( str_contains( $e->getMessage(), 'Reference already exists' ) ) {
						WP_CLI::log( "  Branch: {$branch} (exists)" );
					} else {
						throw $e;
					}
				}

				// Download repo files into sandbox wp-content.
				$content_type = $assoc_args['type'] ?? 'theme';
				$type_dir     = 'plugin' === $content_type ? 'plugins' : 'themes';
				$download_dir = $sandbox->get_wp_content_path() . '/' . $type_dir . '/' . $repo_name;
				if ( ! is_dir( $download_dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating directory for GitHub download.
					mkdir( $download_dir, 0755, true );
				}

				$file_count = $github->download( $branch, $download_dir );
				WP_CLI::log( "  Downloaded: {$file_count} files into {$type_dir}/{$repo_name}/" );

				// Store GitHub metadata.
				$clone_source                = $sandbox->clone_source ?? array();
				$clone_source['github_repo'] = $github_repo;
				$clone_source['github_dir']  = $type_dir . '/' . $repo_name;
				$sandbox->update_meta( 'clone_source', $clone_source );
			} catch ( \Throwable $e ) {
				WP_CLI::warning( "GitHub setup failed: {$e->getMessage()}" );
				WP_CLI::warning( 'Sandbox was created but GitHub worktree was not set up.' );
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

		$data         = $sandbox->to_array();
		$data['size'] = $this->format_size( $sandbox->get_size() );
		if ( $sandbox->is_subsite() ) {
			$data['db_path'] = 'N/A (multisite sub-site)';
		} else {
			$data['db_path'] = $sandbox->get_db_path() ?? 'N/A (MySQL)';
		}
		$data['url']        = $sandbox->get_url();
		$data['wp_content'] = $sandbox->is_subsite() ? 'shared (network)' : $sandbox->get_wp_content_path();

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

		$active_sandbox = \Rudel\Rudel::is_sandbox() ? \Rudel\Rudel::id() : 'none';

		$items = array(
			array(
				'Field' => 'Bootstrap installed',
				'Value' => $writer->is_installed() ? 'yes' : 'no',
			),
			array(
				'Field' => 'Current sandbox',
				'Value' => $active_sandbox,
			),
			array(
				'Field' => 'Sandboxes directory',
				'Value' => $this->manager->get_environments_dir(),
			),
			array(
				'Field' => 'Active sandboxes',
				'Value' => (string) count( $sandboxes ),
			),
			array(
				'Field' => 'Multisite',
				'Value' => function_exists( 'is_multisite' ) && is_multisite() ? 'yes' : 'no',
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
	 * Clean up expired or merged sandboxes.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be removed without actually deleting.
	 *
	 * [--max-age-days=<days>]
	 * : Override the configured max age in days.
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
	public function cleanup( $args, $assoc_args ): void {
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$merged  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'merged', false );

		if ( $merged ) {
			$result = $this->manager->cleanup_merged( array( 'dry_run' => $dry_run ) );
			$label  = 'with merged branches';
		} else {
			$result = $this->manager->cleanup(
				array(
					'dry_run'      => $dry_run,
					'max_age_days' => (int) ( $assoc_args['max-age-days'] ?? 0 ),
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
			WP_CLI::log( "  {$id}" );
		}

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $id ) {
				WP_CLI::warning( "Failed to remove: {$id}" );
			}
		}
	}

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
	public function logs( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

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
	public function promote( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

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

	/**
	 * Push sandbox file changes to GitHub.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--github=<repo>]
	 * : GitHub repository (owner/repo). Remembered after first use.
	 *
	 * [--message=<message>]
	 * : Commit message.
	 * ---
	 * default: Update from Rudel sandbox
	 * ---
	 *
	 * [--dir=<dir>]
	 * : Subdirectory within wp-content to push (e.g. themes/my-theme). Defaults to all of wp-content.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel push my-sandbox-a1b2 --github=inline0/my-theme --dir=themes/my-theme --message="Add header template"
	 *     Success: Pushed to rudel/my-sandbox-a1b2 (abc1234)
	 *
	 *     # Subsequent pushes remember the repo:
	 *     $ wp rudel push my-sandbox-a1b2 --message="Fix typo"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @throws \RuntimeException If the sandbox is not found or push fails.
	 * @when after_wp_load
	 */
	public function push( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$repo = $assoc_args['github'] ?? $sandbox->get_github_repo();
		if ( ! $repo ) {
			WP_CLI::error( 'GitHub repo required. Pass --github=owner/repo (only needed on first push).' );
		}

		$message = $assoc_args['message'] ?? 'Update from Rudel sandbox';
		$subdir  = $assoc_args['dir'] ?? $sandbox->clone_source['github_dir'] ?? '';
		$branch  = $sandbox->get_git_branch();

		$local_dir = $sandbox->get_wp_content_path();
		if ( '' !== $subdir ) {
			$local_dir .= '/' . ltrim( $subdir, '/' );
		}

		if ( ! is_dir( $local_dir ) ) {
			WP_CLI::error( "Directory not found: {$local_dir}" );
		}

		try {
			$github = new \Rudel\GitHubIntegration( $repo );

			// Create branch if it doesn't exist.
			WP_CLI::log( "Ensuring branch {$branch} exists..." );
			try {
				$github->create_branch( $branch );
				WP_CLI::log( '  Branch created.' );
			} catch ( \RuntimeException $e ) {
				if ( str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					WP_CLI::log( '  Branch already exists.' );
				} else {
					throw $e;
				}
			}

			WP_CLI::log( 'Pushing changes...' );
			$sha = $github->push( $branch, $local_dir, $message );

			if ( $sha ) {
				// Remember the repo for future push/pr commands.
				if ( ! $sandbox->get_github_repo() ) {
					$clone_source                = $sandbox->clone_source ?? array();
					$clone_source['github_repo'] = $repo;
					$sandbox->update_meta( 'clone_source', $clone_source );
				}
				WP_CLI::success( "Pushed to {$branch} ({$sha})" );
			} else {
				WP_CLI::log( 'No changes to push.' );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Create a GitHub pull request from a sandbox branch.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--github=<repo>]
	 * : GitHub repository (owner/repo). Uses stored repo from previous push if omitted.
	 *
	 * [--title=<title>]
	 * : PR title. Defaults to the sandbox name.
	 *
	 * [--body=<body>]
	 * : PR description.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel pr my-sandbox-a1b2 --github=inline0/my-theme --title="Add header template"
	 *     Success: PR #3 created: https://github.com/inline0/my-theme/pull/3
	 *
	 *     # If repo was stored from a previous push:
	 *     $ wp rudel pr my-sandbox-a1b2 --title="Add header template"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand pr
	 * @when after_wp_load
	 */
	public function pr( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$repo = $assoc_args['github'] ?? $sandbox->get_github_repo();
		if ( ! $repo ) {
			WP_CLI::error( 'GitHub repo required. Pass --github=owner/repo or push first to store it.' );
		}

		$branch = $sandbox->get_git_branch();
		$title  = $assoc_args['title'] ?? $sandbox->name;
		$body   = $assoc_args['body'] ?? "Created from Rudel sandbox `{$sandbox->id}`";

		try {
			$github = new \Rudel\GitHubIntegration( $repo );
			$pr     = $github->create_pr( $branch, $title, $body );

			WP_CLI::success( "PR #{$pr['number']} created: {$pr['html_url']}" );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
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
