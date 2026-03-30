<?php
/**
 * WP-CLI commands for core Rudel sandbox management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\Environment;
use Rudel\GitHubIntegration;
use Rudel\Rudel;
use Rudel\RudelConfig;
use WP_CLI;

/**
 * Manage Rudel sandboxes.
 *
 * The command name defaults to "rudel" but can be changed by defining
 * RUDEL_CLI_COMMAND in wp-config.php before the plugin loads.
 */
class RudelCommand extends AbstractEnvironmentCommand {

	use HandlesEnvironmentPolicy;

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
	 * [--owner=<owner>]
	 * : Optional owner for stewardship and policy.
	 *
	 * [--labels=<labels>]
	 * : Comma-separated labels for grouping and cleanup policy.
	 *
	 * [--purpose=<purpose>]
	 * : Optional description of why the sandbox exists.
	 *
	 * [--protected]
	 * : Exclude this sandbox from automated cleanup.
	 *
	 * [--ttl-days=<days>]
	 * : Set an expiry relative to creation time.
	 *
	 * [--expires-at=<timestamp>]
	 * : Set an explicit expiry timestamp.
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
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ): void {
		$github_repo = $assoc_args['github'] ?? null;
		$name        = $this->resolve_create_name( $assoc_args, $github_repo );
		$options     = $this->build_create_options( $assoc_args );
		$clone_from  = $options['clone_from'] ?? null;
		$has_clone   = $options['clone_db'] || $options['clone_themes'] || $options['clone_plugins'] || $options['clone_uploads'];

		$this->log_create_plan( $name, $options, $clone_from, $has_clone );

		try {
			$sandbox = $this->manager->create( $name, $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox created: {$sandbox->id}" );

		if ( $sandbox->clone_source ) {
			$this->log_clone_summary( $sandbox->clone_source );
		}

		if ( $github_repo ) {
			$this->setup_github_checkout( $sandbox, $github_repo, $assoc_args );
		}

		$this->log_sandbox_ready( $sandbox );
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
			function ( Environment $sandbox ): array {
				return array(
					'id'        => $sandbox->id,
					'name'      => $sandbox->name,
					'owner'     => $sandbox->owner ?? '',
					'protected' => $this->format_protection( $sandbox->is_protected() ),
					'expires'   => $sandbox->expires_at ?? '',
					'engine'    => $sandbox->engine,
					'status'    => $sandbox->status,
					'template'  => $sandbox->template,
					'created'   => $sandbox->created_at,
					'size'      => $this->format_size( $sandbox->get_size() ),
					'path'      => $sandbox->path,
				);
			},
			$sandboxes
		);

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'owner', 'protected', 'expires', 'engine', 'status', 'template', 'created', 'size' ) );
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
		$sandbox = $this->require_environment( $args[0] );
		$data    = $sandbox->to_array();

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
			return;
		}

		WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
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
		$sandbox = $this->require_environment( $id );
		$force   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			WP_CLI::confirm( "Are you sure you want to destroy sandbox '{$sandbox->name}' ({$id})?" );
		}

		if ( $this->manager->destroy( $id ) ) {
			WP_CLI::success( "Sandbox destroyed: {$id}" );
			return;
		}

		WP_CLI::error( "Failed to destroy sandbox: {$id}" );
	}

	/**
	 * Update sandbox metadata and policy.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--owner=<owner>]
	 * : Set or clear the owner.
	 *
	 * [--labels=<labels>]
	 * : Comma-separated labels.
	 *
	 * [--purpose=<purpose>]
	 * : Set or clear the purpose.
	 *
	 * [--protected]
	 * : Exclude this sandbox from automated cleanup.
	 *
	 * [--unprotected]
	 * : Remove cleanup protection.
	 *
	 * [--ttl-days=<days>]
	 * : Set an expiry relative to now.
	 *
	 * [--expires-at=<timestamp>]
	 * : Set an explicit expiry timestamp.
	 *
	 * [--clear-expiry]
	 * : Remove any explicit expiry.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ): void {
		$id      = $args[0];
		$changes = $this->build_policy_changes( $assoc_args );

		if ( empty( $changes ) ) {
			WP_CLI::error( 'No metadata changes were provided.' );
		}

		try {
			$sandbox = $this->manager->update( $id, $changes );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox updated: {$sandbox->id}" );
		WP_CLI::log( '' );
		WP_CLI::log( '  Owner:     ' . ( $sandbox->owner ?? '-' ) );
		WP_CLI::log( '  Protected: ' . $this->format_protection( $sandbox->is_protected() ) );
		WP_CLI::log( '  Expires:   ' . ( $sandbox->expires_at ?? '-' ) );
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
		$writer         = new \Rudel\ConfigWriter();
		$sandboxes      = $this->manager->list();
		$apps           = ( new \Rudel\AppManager() )->list();
		$config         = new RudelConfig();
		$sqlite_path    = defined( 'RUDEL_PLUGIN_DIR' )
			? RUDEL_PLUGIN_DIR . 'lib/sqlite-database-integration'
			: dirname( __DIR__ ) . '/lib/sqlite-database-integration';
		$automation_on  = $config->get( 'auto_cleanup_enabled' ) > 0
			|| $config->get( 'auto_cleanup_merged' ) > 0
			|| $config->get( 'auto_app_backups_enabled' ) > 0
			|| $config->get( 'auto_app_backup_retention_count' ) > 0
			|| $config->get( 'auto_app_deployment_retention_count' ) > 0
			|| $config->get( 'expiring_environment_notice_days' ) > 0;
		$active_sandbox = Rudel::is_sandbox() ? Rudel::id() : 'none';

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
				'Field' => 'Active apps',
				'Value' => (string) count( $apps ),
			),
			array(
				'Field' => 'Config file',
				'Value' => $config->get_config_path(),
			),
			array(
				'Field' => 'Default TTL days',
				'Value' => (string) $config->get( 'default_ttl_days' ),
			),
			array(
				'Field' => 'Max age days',
				'Value' => (string) $config->get( 'max_age_days' ),
			),
			array(
				'Field' => 'Max idle days',
				'Value' => (string) $config->get( 'max_idle_days' ),
			),
			array(
				'Field' => 'Auto cleanup',
				'Value' => $config->get( 'auto_cleanup_enabled' ) > 0 ? 'yes' : 'no',
			),
			array(
				'Field' => 'Auto cleanup merged',
				'Value' => $config->get( 'auto_cleanup_merged' ) > 0 ? 'yes' : 'no',
			),
			array(
				'Field' => 'Auto app backups',
				'Value' => $config->get( 'auto_app_backups_enabled' ) > 0 ? 'yes' : 'no',
			),
			array(
				'Field' => 'App backup interval',
				'Value' => (string) $config->get( 'auto_app_backup_interval_hours' ) . 'h',
			),
			array(
				'Field' => 'App backup retention',
				'Value' => (string) $config->get( 'auto_app_backup_retention_count' ),
			),
			array(
				'Field' => 'App deployment retention',
				'Value' => (string) $config->get( 'auto_app_deployment_retention_count' ),
			),
			array(
				'Field' => 'Expiry notice days',
				'Value' => (string) $config->get( 'expiring_environment_notice_days' ),
			),
			array(
				'Field' => 'Automation scheduled',
				'Value' => $automation_on ? 'yes' : 'no',
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
	 * Resolve the create name from CLI arguments.
	 *
	 * @param array       $assoc_args  Command arguments.
	 * @param string|null $github_repo GitHub repository slug.
	 * @return string
	 */
	private function resolve_create_name( array $assoc_args, ?string $github_repo ): string {
		if ( ! empty( $assoc_args['name'] ) ) {
			return $assoc_args['name'];
		}
		if ( $github_repo ) {
			return basename( $github_repo );
		}
		return 'sandbox';
	}

	/**
	 * Build normalized create options for the environment manager.
	 *
	 * @param array $assoc_args Command arguments.
	 * @return array<string, mixed>
	 */
	private function build_create_options( array $assoc_args ): array {
		$clone_all  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-all', false );
		$clone_from = $assoc_args['clone-from'] ?? null;
		$options    = array_merge(
			array(
				'engine'        => $assoc_args['engine'] ?? 'mysql',
				'template'      => $assoc_args['template'] ?? 'blank',
				'clone_db'      => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-db', false ),
				'clone_themes'  => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-themes', false ),
				'clone_plugins' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-plugins', false ),
				'clone_uploads' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-uploads', false ),
			),
			$this->build_policy_changes( $assoc_args )
		);

		if ( $clone_from ) {
			$options['clone_from'] = $clone_from;
		}

		return $options;
	}

	/**
	 * Log what kind of sandbox create operation is about to run.
	 *
	 * @param string      $name       Sandbox name.
	 * @param array       $options    Normalized create options.
	 * @param string|null $clone_from Source sandbox ID when cloning from another environment.
	 * @param bool        $has_clone  Whether any host clone flags are enabled.
	 * @return void
	 */
	private function log_create_plan( string $name, array $options, ?string $clone_from, bool $has_clone ): void {
		if ( $clone_from ) {
			WP_CLI::log( "Creating sandbox '{$name}' cloned from '{$clone_from}'..." );
			return;
		}

		if ( ! $has_clone ) {
			WP_CLI::log( "Creating sandbox '{$name}'..." );
			return;
		}

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
	}

	/**
	 * Log clone metadata after sandbox creation.
	 *
	 * @param array<string, mixed> $clone_source Clone metadata.
	 * @return void
	 */
	private function log_clone_summary( array $clone_source ): void {
		WP_CLI::log( '' );
		WP_CLI::log( '  Clone summary:' );
		if ( ! empty( $clone_source['db_cloned'] ) ) {
			WP_CLI::log( "    Database: {$clone_source['tables_cloned']} tables, {$clone_source['rows_cloned']} rows" );
		}
		if ( ! empty( $clone_source['themes_cloned'] ) ) {
			WP_CLI::log( '    Themes: copied' );
		}
		if ( ! empty( $clone_source['plugins_cloned'] ) ) {
			WP_CLI::log( '    Plugins: copied' );
		}
		if ( ! empty( $clone_source['uploads_cloned'] ) ) {
			WP_CLI::log( '    Uploads: copied' );
		}
	}

	/**
	 * Configure GitHub content for a newly created sandbox.
	 *
	 * @param Environment          $sandbox     Created sandbox.
	 * @param string               $github_repo Repository slug.
	 * @param array<string, mixed> $assoc_args  Command arguments.
	 * @return void
	 */
	private function setup_github_checkout( Environment $sandbox, string $github_repo, array $assoc_args ): void {
		try {
			$github    = new GitHubIntegration( $github_repo );
			$branch    = $sandbox->get_git_branch();
			$repo_name = basename( $github_repo );

			WP_CLI::log( '' );
			WP_CLI::log( "  GitHub: {$github_repo}" );

			try {
				$github->create_branch( $branch );
				WP_CLI::log( "  Branch: {$branch} (created)" );
			} catch ( \RuntimeException $e ) {
				if ( str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					WP_CLI::log( "  Branch: {$branch} (exists)" );
				} else {
					WP_CLI::warning( "GitHub setup failed: {$e->getMessage()}" );
					WP_CLI::warning( 'Sandbox was created but GitHub worktree was not set up.' );
					return;
				}
			}

			$content_type = $assoc_args['type'] ?? 'theme';
			$type_dir     = 'plugin' === $content_type ? 'plugins' : 'themes';
			$download_dir = $sandbox->get_wp_content_path() . '/' . $type_dir . '/' . $repo_name;
			if ( ! is_dir( $download_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating directory for GitHub download.
				mkdir( $download_dir, 0755, true );
			}

			$file_count = $github->download( $branch, $download_dir );
			WP_CLI::log( "  Downloaded: {$file_count} files into {$type_dir}/{$repo_name}/" );

			$clone_source                = $sandbox->clone_source ?? array();
			$clone_source['github_repo'] = $github_repo;
			$clone_source['github_dir']  = $type_dir . '/' . $repo_name;
			$sandbox->update_meta( 'clone_source', $clone_source );
		} catch ( \Throwable $e ) {
			WP_CLI::warning( "GitHub setup failed: {$e->getMessage()}" );
			WP_CLI::warning( 'Sandbox was created but GitHub worktree was not set up.' );
		}
	}

	/**
	 * Log the final sandbox path and quick usage hint.
	 *
	 * @param Environment $sandbox Created sandbox.
	 * @return void
	 */
	private function log_sandbox_ready( Environment $sandbox ): void {
		WP_CLI::log( '' );
		WP_CLI::log( "  Path: {$sandbox->path}" );
		WP_CLI::log( "  URL:  {$sandbox->get_url()}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'To use this sandbox:' );
		WP_CLI::log( "  cd {$sandbox->path}" );
		WP_CLI::log( '  wp post list' );
	}
}
