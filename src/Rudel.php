<?php
/**
 * Public API for Rudel.
 *
 * Static facade for checking sandbox state, reading context, and building UI.
 * All methods are safe to call from any context, including non-sandbox requests.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Main Rudel API class.
 *
 * Usage:
 *   use Rudel\Rudel;
 *
 *   if ( Rudel::is_sandbox() ) {
 *       echo 'Sandbox: ' . Rudel::id();
 *   }
 */
class Rudel {

	/**
	 * Whether any isolated Rudel environment is active.
	 *
	 * @return bool
	 */
	private static function is_environment(): bool {
		return null !== self::environment_id();
	}

	/**
	 * Active environment ID without assuming bootstrap constants exist.
	 *
	 * @return string|null
	 */
	private static function environment_id(): ?string {
		if ( ! defined( 'RUDEL_ID' ) ) {
			return null;
		}

		$id = constant( 'RUDEL_ID' );
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}

		return $id;
	}

	/**
	 * Read an optional string constant set by the bootstrap.
	 *
	 * @param string $constant Constant name.
	 * @return string|null
	 */
	private static function string_constant( string $constant ): ?string {
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Read an optional boolean constant set by the bootstrap.
	 *
	 * @param string $constant Constant name.
	 * @return bool
	 */
	private static function bool_constant( string $constant ): bool {
		return defined( $constant ) && (bool) constant( $constant );
	}

	/**
	 * Whether the current request is running inside a sandbox.
	 *
	 * @return bool
	 */
	public static function is_sandbox(): bool {
		return self::is_environment() && ! self::is_app();
	}

	/**
	 * Whether the current request is running inside an app.
	 *
	 * @return bool
	 */
	public static function is_app(): bool {
		return self::is_environment() && self::bool_constant( 'RUDEL_IS_APP' );
	}

	/**
	 * Current sandbox environment ID, or null outside sandbox requests.
	 *
	 * @return string|null
	 */
	public static function id(): ?string {
		return self::is_sandbox() ? self::environment_id() : null;
	}

	/**
	 * Current app ID, or null outside app context.
	 *
	 * @return string|null
	 */
	public static function app_id(): ?string {
		return self::is_app() ? self::environment_id() : null;
	}

	/**
	 * Current environment filesystem path, or null when none is active.
	 *
	 * @return string|null
	 */
	public static function path(): ?string {
		return self::string_constant( 'RUDEL_PATH' );
	}

	/**
	 * Current environment database engine, or null when none is active.
	 *
	 * @return string|null One of 'subsite' or null.
	 */
	public static function engine(): ?string {
		if ( ! self::is_environment() ) {
			return null;
		}

		return self::string_constant( 'RUDEL_ENGINE' ) ?? 'subsite';
	}

	/**
	 * Current environment table prefix, or null when none is active.
	 *
	 * @return string|null
	 */
	public static function table_prefix(): ?string {
		return self::string_constant( 'RUDEL_TABLE_PREFIX' );
	}

	/**
	 * Current environment URL, or null when none is active.
	 *
	 * @return string|null
	 */
	public static function url(): ?string {
		if ( ! self::is_environment() ) {
			return null;
		}

		$environment_url = self::string_constant( 'RUDEL_ENVIRONMENT_URL' );
		if ( null !== $environment_url && '' !== $environment_url ) {
			return rtrim( $environment_url, '/' ) . '/';
		}

		$environment_id = self::environment_id();
		if ( null === $environment_id ) {
			return null;
		}

		return Environment::multisite_url_for( $environment_id );
	}

	/**
	 * Get a URL that exits the current sandbox and returns to the host.
	 *
	 * @return string URL for the network host.
	 */
	public static function exit_url(): string {
		$host_url = self::string_constant( 'RUDEL_HOST_URL' );
		if ( null !== $host_url && '' !== $host_url ) {
			return rtrim( $host_url, '/' ) . '/';
		}

		$scheme = 'http';
		$host   = 'localhost';
		$port   = null;

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation without full WP helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : $scheme;
				$host   = isset( $parts['host'] ) ? (string) $parts['host'] : $host;
				$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
			}
		}

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$network_host = preg_replace( '/:\d+$/', '', (string) DOMAIN_CURRENT_SITE );
			if ( is_string( $network_host ) && '' !== $network_host ) {
				$host = $network_host;
			}
		}

		$url = $scheme . '://' . $host;
		if ( null !== $port ) {
			$url .= ':' . $port;
		}

		return rtrim( $url, '/' ) . '/';
	}

	/**
	 * Whether outbound email is disabled in the current environment.
	 *
	 * @return bool True if email is blocked.
	 */
	public static function is_email_disabled(): bool {
		return self::is_environment() && self::bool_constant( 'RUDEL_DISABLE_EMAIL' );
	}

	/**
	 * Debug log path for the current sandbox.
	 *
	 * @return string|null Absolute path to debug.log, or null if not in a sandbox.
	 */
	public static function log_path(): ?string {
		$path = self::path();
		return $path ? $path . '/wp-content/debug.log' : null;
	}

	/**
	 * Installed Rudel plugin version.
	 *
	 * @return string|null
	 */
	public static function version(): ?string {
		return defined( 'RUDEL_VERSION' ) ? RUDEL_VERSION : null;
	}

	/**
	 * Configured WP-CLI command name.
	 *
	 * @return string
	 */
	public static function cli_command(): string {
		if ( ! defined( 'RUDEL_CLI_COMMAND' ) ) {
			return 'rudel';
		}

		$command = trim( (string) constant( 'RUDEL_CLI_COMMAND' ) );
		return '' !== $command ? $command : 'rudel';
	}

	/**
	 * Bundle the active environment context for templates or diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public static function context(): array {
		return array(
			'is_sandbox'     => self::is_sandbox(),
			'is_app'         => self::is_app(),
			'id'             => self::id(),
			'app_id'         => self::app_id(),
			'path'           => self::path(),
			'engine'         => self::engine(),
			'table_prefix'   => self::table_prefix(),
			'url'            => self::url(),
			'exit_url'       => self::exit_url(),
			'email_disabled' => self::is_email_disabled(),
			'log_path'       => self::log_path(),
			'version'        => self::version(),
			'cli_command'    => self::cli_command(),
		);
	}

	/**
	 * Reuse one environment manager per request.
	 *
	 * @return EnvironmentManager
	 */
	private static function manager(): EnvironmentManager {
		static $manager = null;
		if ( null === $manager ) {
			$manager = new EnvironmentManager();
		}
		return $manager;
	}

	/**
	 * Reuse one app manager per request.
	 *
	 * @return AppManager
	 */
	private static function app_manager(): AppManager {
		static $app_manager = null;
		if ( null === $app_manager ) {
			$app_manager = new AppManager();
		}
		return $app_manager;
	}

	/**
	 * List all sandboxes.
	 *
	 * @return Environment[] Array of sandbox instances.
	 */
	public static function all(): array {
		return self::manager()->list();
	}

	/**
	 * Get a single sandbox by ID.
	 *
	 * @param string $id Sandbox identifier.
	 * @return Environment|null Sandbox instance or null if not found.
	 */
	public static function get( string $id ): ?Environment {
		return self::manager()->get( $id );
	}

	/**
	 * Create a new sandbox.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $options Optional settings (engine, template, clone flags).
	 * @return Environment The newly created sandbox.
	 *
	 * @throws \RuntimeException If creation fails.
	 */
	public static function create( string $name, array $options = array() ): Environment {
		return self::manager()->create( $name, $options );
	}

	/**
	 * Create a sandbox and seed it from a GitHub repository in one step.
	 *
	 * The CLI treats sandbox creation as successful even if the GitHub download fails,
	 * so this method returns both the sandbox and any GitHub error instead of rolling the sandbox back.
	 *
	 * @param string $repo GitHub repository in owner/repo format.
	 * @param array  $options Sandbox create options plus optional 'name' and 'type'.
	 * @return array{environment: Environment, github: array{repo: string, branch: string, branch_created: bool, repo_name: string, content_type: string, download_dir: string, downloaded_files: int, error: string|null}}
	 *
	 * @throws \RuntimeException If sandbox creation fails before the GitHub bootstrap step can run.
	 */
	public static function create_from_github( string $repo, array $options = array() ): array {
		$name         = isset( $options['name'] ) ? (string) $options['name'] : basename( $repo );
		$content_type = isset( $options['type'] ) ? (string) $options['type'] : 'theme';
		unset( $options['name'], $options['type'] );

		$sandbox = self::create( $name, $options );
		$branch  = $sandbox->get_git_branch();

		$result = array(
			'environment' => $sandbox,
			'github'      => array(
				'repo'             => $repo,
				'branch'           => $branch,
				'branch_created'   => false,
				'repo_name'        => basename( $repo ),
				'content_type'     => $content_type,
				'download_dir'     => '',
				'downloaded_files' => 0,
				'error'            => null,
			),
		);

		try {
			$github    = new GitHubIntegration( $repo );
			$repo_name = basename( $repo );

			try {
				$github->create_branch( $branch );
				$result['github']['branch_created'] = true;
			} catch ( \RuntimeException $e ) {
				if ( ! str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					throw $e;
				}
			}

			$type_dir                         = 'plugin' === $content_type ? 'plugins' : 'themes';
			$download_dir                     = $sandbox->get_runtime_content_path( $type_dir . '/' . $repo_name );
			$result['github']['download_dir'] = $download_dir;

			if ( ! is_dir( $download_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- GitHub checkouts create their own target tree.
				mkdir( $download_dir, 0755, true );
			}

			$result['github']['downloaded_files'] = $github->download( $branch, $download_dir );

			$clone_source                = $sandbox->clone_source ?? array();
			$clone_source['github_repo'] = $repo;
			$clone_source['github_dir']  = $type_dir . '/' . $repo_name;
			self::manager()->update(
				$sandbox->id,
				array(
					'clone_source' => $clone_source,
				)
			);
		} catch ( \Throwable $e ) {
			$result['github']['error'] = $e->getMessage();
		}

		return $result;
	}

	/**
	 * Destroy a sandbox by ID.
	 *
	 * @param string $id Sandbox identifier.
	 * @return bool True on success.
	 */
	public static function destroy( string $id ): bool {
		return self::manager()->destroy( $id );
	}

	/**
	 * Update sandbox metadata.
	 *
	 * @param string $id      Sandbox identifier.
	 * @param array  $changes Metadata changes.
	 * @return Environment
	 */
	public static function update( string $id, array $changes ): Environment {
		return self::manager()->update( $id, $changes );
	}

	/**
	 * Clean up expired sandboxes.
	 *
	 * @param array $options Options: 'dry_run' (bool), 'max_age_days' (int override), 'max_idle_days' (int override).
	 * @return array{removed: string[], skipped: string[], errors: string[]} Cleanup results.
	 */
	public static function cleanup( array $options = array() ): array {
		return self::manager()->cleanup( $options );
	}

	/**
	 * Clean up sandboxes whose git branches have been merged.
	 *
	 * @param array $options Options: 'dry_run' (bool).
	 * @return array{removed: string[], skipped: string[], errors: string[]} Cleanup results.
	 */
	public static function cleanup_merged( array $options = array() ): array {
		return self::manager()->cleanup_merged( $options );
	}

	/**
	 * Configured sandboxes directory path.
	 *
	 * @return string Absolute path.
	 */
	public static function environments_dir(): string {
		return self::manager()->get_environments_dir();
	}

	/**
	 * List all apps.
	 *
	 * @return Environment[] Array of app instances.
	 */
	public static function apps(): array {
		return self::app_manager()->list();
	}

	/**
	 * Get a single app by ID.
	 *
	 * @param string $id App identifier.
	 * @return Environment|null App instance or null if not found.
	 */
	public static function app( string $id ): ?Environment {
		return self::app_manager()->get( $id );
	}

	/**
	 * Create a new app.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $domains Domain names for the app.
	 * @param array  $options Optional settings (engine, clone flags).
	 * @return Environment The newly created app.
	 */
	public static function create_app( string $name, array $domains, array $options = array() ): Environment {
		return self::app_manager()->create( $name, $domains, $options );
	}

	/**
	 * Update app metadata.
	 *
	 * @param string $id      App identifier.
	 * @param array  $changes Metadata changes.
	 * @return Environment
	 */
	public static function update_app( string $id, array $changes ): Environment {
		return self::app_manager()->update( $id, $changes );
	}

	/**
	 * Create a sandbox from an app.
	 *
	 * @param string $app_id App identifier.
	 * @param string $name Sandbox name.
	 * @param array  $options Optional sandbox settings.
	 * @return Environment
	 */
	public static function create_sandbox_from_app( string $app_id, string $name, array $options = array() ): Environment {
		return self::app_manager()->create_sandbox( $app_id, $name, $options );
	}

	/**
	 * Destroy an app by ID.
	 *
	 * @param string $id App identifier.
	 * @return bool True on success.
	 */
	public static function destroy_app( string $id ): bool {
		return self::app_manager()->destroy( $id );
	}

	/**
	 * Add one domain to an app.
	 *
	 * @param string $app_id App identifier.
	 * @param string $domain Domain to add.
	 * @return void
	 */
	public static function add_app_domain( string $app_id, string $domain ): void {
		self::app_manager()->add_domain( $app_id, $domain );
	}

	/**
	 * Remove one domain from an app.
	 *
	 * @param string $app_id App identifier.
	 * @param string $domain Domain to remove.
	 * @return void
	 */
	public static function remove_app_domain( string $app_id, string $domain ): void {
		self::app_manager()->remove_domain( $app_id, $domain );
	}

	/**
	 * Create a backup of an app.
	 *
	 * @param string $app_id App identifier.
	 * @param string $name Backup name.
	 * @return array<string, mixed>
	 */
	public static function backup_app( string $app_id, string $name ): array {
		return self::app_manager()->backup( $app_id, $name );
	}

	/**
	 * List backups for an app.
	 *
	 * @param string $app_id App identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public static function app_backups( string $app_id ): array {
		return self::app_manager()->backups( $app_id );
	}

	/**
	 * List deployment records for an app.
	 *
	 * @param string $app_id App identifier.
	 * @return array<int, array<string, mixed>>
	 */
	public static function app_deployments( string $app_id ): array {
		return self::app_manager()->deployments( $app_id );
	}

	/**
	 * Build a dry-run deploy plan from a sandbox into an app.
	 *
	 * @param string      $app_id App identifier.
	 * @param string      $sandbox_id Sandbox identifier.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata.
	 * @return array<string, mixed>
	 */
	public static function plan_app_deploy( string $app_id, string $sandbox_id, ?string $backup_name = null, array $options = array() ): array {
		return self::app_manager()->plan_deploy( $app_id, $sandbox_id, $backup_name, $options );
	}

	/**
	 * Restore an app from a backup.
	 *
	 * @param string $app_id App identifier.
	 * @param string $name Backup name.
	 * @return void
	 */
	public static function restore_app( string $app_id, string $name ): void {
		self::app_manager()->restore( $app_id, $name );
	}

	/**
	 * Deploy a sandbox into an app.
	 *
	 * @param string      $app_id App identifier.
	 * @param string      $sandbox_id Sandbox identifier.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata such as label or notes.
	 * @return array<string, mixed>
	 */
	public static function deploy_sandbox_to_app( string $app_id, string $sandbox_id, ?string $backup_name = null, array $options = array() ): array {
		return self::app_manager()->deploy( $app_id, $sandbox_id, $backup_name, $options );
	}

	/**
	 * Roll an app back to the backup captured by a deployment record.
	 *
	 * @param string $app_id App identifier.
	 * @param string $deployment_id Deployment identifier.
	 * @param array  $options Optional rollback settings.
	 * @return array<string, mixed>
	 */
	public static function rollback_app_deployment( string $app_id, string $deployment_id, array $options = array() ): array {
		return self::app_manager()->rollback( $app_id, $deployment_id, $options );
	}

	/**
	 * Prune backups and deployment history for one app.
	 *
	 * @param string $app_id App identifier.
	 * @param array  $options Retention options.
	 * @return array{app_id: string, backups_removed: string[], deployments_removed: string[]}
	 */
	public static function prune_app_history( string $app_id, array $options = array() ): array {
		return self::app_manager()->prune_history( $app_id, $options );
	}

	/**
	 * Configured apps directory path.
	 *
	 * @return string Absolute path.
	 */
	public static function apps_dir(): string {
		return self::app_manager()->get_apps_dir();
	}

	/**
	 * Summarize runtime status in a machine-friendly shape.
	 *
	 * @return array<string, bool|int|string|null>
	 */
	public static function status(): array {
		$writer        = new ConfigWriter();
		$config        = new RudelConfig();
		$automation_on = $config->get( 'auto_cleanup_enabled' ) > 0
			|| $config->get( 'auto_cleanup_merged' ) > 0
			|| $config->get( 'auto_app_backups_enabled' ) > 0
			|| $config->get( 'auto_app_backup_retention_count' ) > 0
			|| $config->get( 'auto_app_deployment_retention_count' ) > 0
			|| $config->get( 'expiring_environment_notice_days' ) > 0;
		$type          = self::is_app() ? 'app' : ( self::is_sandbox() ? 'sandbox' : 'none' );
		$bootstrap_ok  = false;

		try {
			$bootstrap_ok = $writer->is_installed();
		} catch ( \RuntimeException $e ) {
			$bootstrap_ok = false;
		}

		return array(
			'bootstrap_installed'                 => $bootstrap_ok,
			'current_environment_id'              => self::environment_id(),
			'current_environment_type'            => $type,
			'sandboxes_directory'                 => self::environments_dir(),
			'active_sandboxes'                    => count( self::all() ),
			'active_apps'                         => count( self::apps() ),
			'config_storage'                      => 'wp_options',
			'config_option'                       => $config->option_name(),
			'default_ttl_days'                    => $config->get( 'default_ttl_days' ),
			'max_age_days'                        => $config->get( 'max_age_days' ),
			'max_idle_days'                       => $config->get( 'max_idle_days' ),
			'auto_cleanup'                        => $config->get( 'auto_cleanup_enabled' ) > 0,
			'auto_cleanup_merged'                 => $config->get( 'auto_cleanup_merged' ) > 0,
			'auto_app_backups'                    => $config->get( 'auto_app_backups_enabled' ) > 0,
			'auto_app_backup_interval_hours'      => $config->get( 'auto_app_backup_interval_hours' ),
			'auto_app_backup_retention_count'     => $config->get( 'auto_app_backup_retention_count' ),
			'auto_app_deployment_retention_count' => $config->get( 'auto_app_deployment_retention_count' ),
			'expiring_environment_notice_days'    => $config->get( 'expiring_environment_notice_days' ),
			'automation_scheduled'                => $automation_on,
			'multisite'                           => function_exists( 'is_multisite' ) && is_multisite(),
			'php_version'                         => PHP_VERSION,
		);
	}

	/**
	 * Record activity for the current environment.
	 *
	 * @return void
	 */
	public static function touch_current_environment(): void {
		if ( ! self::is_environment() ) {
			return;
		}

		$id = self::environment_id();
		if ( null === $id ) {
			return;
		}

		if ( self::is_app() ) {
			self::app_manager()->update( $id, array( 'last_used_at' => gmdate( 'c' ) ) );
			return;
		}

		self::manager()->update( $id, array( 'last_used_at' => gmdate( 'c' ) ) );
	}

	/**
	 * Read the tail of a sandbox debug log.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param int    $lines Number of lines to return from the end of the log.
	 * @return array{path: string, exists: bool, empty: bool, total_lines: int, lines: array<int, string>}
	 *
	 * @throws \RuntimeException If the sandbox does not exist.
	 */
	public static function read_log( string $sandbox_id, int $lines = 50 ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		$log_path = $sandbox->get_wp_content_path() . '/debug.log';
		if ( ! file_exists( $log_path ) ) {
			return array(
				'path'        => $log_path,
				'exists'      => false,
				'empty'       => true,
				'total_lines' => 0,
				'lines'       => array(),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading one sandbox-local debug log.
		$content = file_get_contents( $log_path );
		if ( '' === $content ) {
			return array(
				'path'        => $log_path,
				'exists'      => true,
				'empty'       => true,
				'total_lines' => 0,
				'lines'       => array(),
			);
		}

		$all_lines = explode( "\n", rtrim( $content, "\n" ) );

		return array(
			'path'        => $log_path,
			'exists'      => true,
			'empty'       => false,
			'total_lines' => count( $all_lines ),
			'lines'       => array_slice( $all_lines, -$lines ),
		);
	}

	/**
	 * Clear a sandbox debug log without deleting the file path the runtime expects.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @return array{path: string, existed: bool, cleared: bool}
	 *
	 * @throws \RuntimeException If the sandbox does not exist.
	 */
	public static function clear_log( string $sandbox_id ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		$log_path = $sandbox->get_wp_content_path() . '/debug.log';
		$existed  = file_exists( $log_path );

		if ( $existed ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Clearing one sandbox-local debug log.
			file_put_contents( $log_path, '' );
		}

		return array(
			'path'    => $log_path,
			'existed' => $existed,
			'cleared' => $existed,
		);
	}

	/**
	 * Create a snapshot of a sandbox.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param string $name       Snapshot name.
	 * @return array Snapshot metadata.
	 *
	 * @throws \RuntimeException If the sandbox is not found or snapshot fails.
	 */
	public static function snapshot( string $sandbox_id, string $name ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}
		$snap_manager = new SnapshotManager( $sandbox );
		return $snap_manager->create( $name );
	}

	/**
	 * Restore a sandbox from a snapshot.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param string $name       Snapshot name.
	 * @return void
	 *
	 * @throws \RuntimeException If the sandbox or snapshot is not found.
	 */
	public static function restore( string $sandbox_id, string $name ): void {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}
		$snap_manager = new SnapshotManager( $sandbox );
		$snap_manager->restore( $name );
	}

	/**
	 * List snapshots for a sandbox.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @return array[] Array of snapshot metadata arrays.
	 *
	 * @throws \RuntimeException If the sandbox is not found.
	 */
	public static function snapshots( string $sandbox_id ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}
		$snap_manager = new SnapshotManager( $sandbox );
		return $snap_manager->list_snapshots();
	}

	/**
	 * Save a sandbox as a reusable template.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param string $name       Template name.
	 * @param string $description Optional description.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If the sandbox does not exist.
	 */
	public static function save_template( string $sandbox_id, string $name, string $description = '' ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		$template_manager = new TemplateManager();
		return $template_manager->save( $sandbox, $name, $description );
	}

	/**
	 * List saved templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function templates(): array {
		$template_manager = new TemplateManager();
		return $template_manager->list_templates();
	}

	/**
	 * Delete one saved template.
	 *
	 * @param string $name Template name.
	 * @return bool
	 */
	public static function delete_template( string $name ): bool {
		$template_manager = new TemplateManager();
		return $template_manager->delete( $name );
	}

	/**
	 * Run scheduled cleanup tasks immediately.
	 *
	 * @return array<string, array<string, array<int, string>|array<string, string>>>
	 */
	public static function run_scheduled_cleanup(): array {
		return Automation::run();
	}

	/**
	 * Run all configured automation tasks immediately.
	 *
	 * @return array<string, mixed>
	 */
	public static function run_automation(): array {
		return Automation::run();
	}

	/**
	 * Documented Rudel hook catalog.
	 *
	 * @return array<string, array{type: string, args: string[]}>
	 */
	public static function hooks(): array {
		return HookCatalog::all();
	}

	/**
	 * Get a GitHubIntegration instance for a repository.
	 *
	 * @param string      $repo  GitHub repository (owner/repo).
	 * @param string|null $token GitHub token. Falls back to RUDEL_GITHUB_TOKEN.
	 * @return GitHubIntegration
	 *
	 * @throws \RuntimeException If no token is available.
	 */
	public static function github( string $repo, ?string $token = null ): GitHubIntegration {
		return new GitHubIntegration( $repo, $token );
	}

	/**
	 * Push a sandbox's files to GitHub.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param string $repo       GitHub repository (owner/repo). Optional if stored in metadata.
	 * @param string $message    Commit message.
	 * @param string $subdir     Subdirectory within wp-content to push (e.g. 'themes/my-theme').
	 * @return string|null Commit SHA on success, null if no changes.
	 *
	 * @throws \RuntimeException If the sandbox is not found or push fails.
	 * @throws \Throwable If push fails after lifecycle hooks begin.
	 */
	public static function push( string $sandbox_id, string $repo = '', string $message = 'Update from Rudel', string $subdir = '' ): ?string {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		$repo = '' !== $repo ? $repo : $sandbox->get_github_repo();
		if ( ! $repo ) {
			throw new \RuntimeException( 'GitHub repo required. Pass $repo or push via CLI first.' );
		}

		$subdir = '' !== $subdir ? $subdir : ( $sandbox->get_github_dir() ?? '' );

		$context = array(
			'environment' => $sandbox,
			'repo'        => $repo,
			'message'     => $message,
			'subdir'      => $subdir,
		);
		Hooks::action( 'rudel_before_environment_push', $context );

		try {
			$github      = new GitHubIntegration( $repo );
			$branch      = $sandbox->get_git_branch();
			$base_branch = $sandbox->get_github_base_branch();

			// Repeat pushes are normal, so an existing sandbox branch is not an error.
			try {
				$github->create_branch( $branch, $base_branch );
			} catch ( \RuntimeException $e ) {
				if ( ! str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					throw $e;
				}
			}

			$local_dir = $sandbox->get_runtime_content_path( $subdir );

			if ( ! is_dir( $local_dir ) ) {
				throw new \RuntimeException( sprintf( 'Directory not found: %s', $local_dir ) );
			}

			$sha = $github->push( $branch, $local_dir, $message );

			// Remember the repo after the first successful push so later calls can omit it.
			if ( $sha && ! $sandbox->get_github_repo() ) {
				$clone_source                = $sandbox->clone_source ?? array();
				$clone_source['github_repo'] = $repo;
				self::manager()->update(
					$sandbox->id,
					array(
						'clone_source' => $clone_source,
					)
				);
			}

			Hooks::action( 'rudel_after_environment_push', $sha, $context );

			return $sha;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_push_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Create a GitHub pull request from a sandbox branch.
	 *
	 * @param string $sandbox_id Sandbox identifier.
	 * @param string $title      PR title. Defaults to sandbox name.
	 * @param string $repo       GitHub repository. Optional if stored in metadata.
	 * @param string $body       PR description.
	 * @return array{number: int, url: string, html_url: string} PR data.
	 *
	 * @throws \RuntimeException If the sandbox is not found or PR creation fails.
	 * @throws \Throwable If PR creation fails after lifecycle hooks begin.
	 */
	public static function pr( string $sandbox_id, string $title = '', string $repo = '', string $body = '' ): array {
		$sandbox = self::get( $sandbox_id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		$repo = '' !== $repo ? $repo : $sandbox->get_github_repo();
		if ( ! $repo ) {
			throw new \RuntimeException( 'GitHub repo required. Pass $repo or push via CLI first.' );
		}

		$context = array(
			'environment' => $sandbox,
			'repo'        => $repo,
			'title'       => $title,
			'body'        => $body,
		);
		Hooks::action( 'rudel_before_environment_pr', $context );

		try {
			$github = new GitHubIntegration( $repo );
			$branch = $sandbox->get_git_branch();
			$title  = '' !== $title ? $title : $sandbox->name;
			$body   = '' !== $body ? $body : sprintf( 'Created from Rudel sandbox `%s`', $sandbox->id );

			$result = $github->create_pr( $branch, $title, $body, $sandbox->get_github_base_branch() );
			Hooks::action( 'rudel_after_environment_pr', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_pr_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Expose the serializable CLI command catalog for agent harnesses.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function cli_command_map(): array {
		return CliCommandMap::definitions();
	}

	/**
	 * Resolve one parsed CLI command into the PHP or shell plan the harness should execute.
	 *
	 * @param string|array<int, string> $path Command path with or without "wp" and the root command.
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, mixed>      $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function resolve_cli_command( $path, array $args = array(), array $assoc_args = array() ): array {
		return CliCommandMap::resolve( $path, $args, $assoc_args );
	}
}
