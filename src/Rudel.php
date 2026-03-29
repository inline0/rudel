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
		return defined( 'RUDEL_ID' ) && '' !== RUDEL_ID;
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
		return self::is_environment() && defined( 'RUDEL_IS_APP' ) && RUDEL_IS_APP;
	}

	/**
	 * Get the current sandbox ID, or null if not in a sandbox.
	 *
	 * @return string|null
	 */
	public static function id(): ?string {
		return self::is_sandbox() ? RUDEL_ID : null;
	}

	/**
	 * Get the current app ID, or null if not in an app.
	 *
	 * @return string|null
	 */
	public static function app_id(): ?string {
		return self::is_app() ? RUDEL_ID : null;
	}

	/**
	 * Get the current environment's filesystem path, or null if none is active.
	 *
	 * @return string|null
	 */
	public static function path(): ?string {
		return defined( 'RUDEL_PATH' ) ? RUDEL_PATH : null;
	}

	/**
	 * Get the current environment's database engine, or null if none is active.
	 *
	 * @return string|null One of 'mysql', 'sqlite', 'subsite', or null.
	 */
	public static function engine(): ?string {
		if ( ! self::is_environment() ) {
			return null;
		}

		$meta_file = self::path() . '/.rudel.json';
		if ( ! file_exists( $meta_file ) ) {
			return 'mysql';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local metadata.
		$meta = json_decode( file_get_contents( $meta_file ), true );
		return $meta['engine'] ?? 'mysql';
	}

	/**
	 * Get the current environment's table prefix, or null if none is active.
	 *
	 * @return string|null
	 */
	public static function table_prefix(): ?string {
		return defined( 'RUDEL_TABLE_PREFIX' ) ? RUDEL_TABLE_PREFIX : null;
	}

	/**
	 * Get the current environment's URL, or null if none is active.
	 *
	 * @return string|null
	 */
	public static function url(): ?string {
		if ( ! self::is_environment() ) {
			return null;
		}

		if ( self::is_app() ) {
			return defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) . '/' : null;
		}

		$prefix = defined( 'RUDEL_PATH_PREFIX' ) ? RUDEL_PATH_PREFIX : '__rudel';

		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' ) . '/' . $prefix . '/' . RUDEL_ID . '/';
		}

		return '/' . $prefix . '/' . RUDEL_ID . '/';
	}

	/**
	 * Get a URL that exits the current sandbox and returns to the host.
	 *
	 * @return string URL with ?adminExit parameter.
	 */
	public static function exit_url(): string {
		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' ) . '/?adminExit';
		}
		return '/?adminExit';
	}

	/**
	 * Whether outbound email is disabled in the current environment.
	 *
	 * @return bool True if email is blocked.
	 */
	public static function is_email_disabled(): bool {
		return self::is_environment() && defined( 'RUDEL_DISABLE_EMAIL' ) && RUDEL_DISABLE_EMAIL;
	}

	/**
	 * Get the path to the current sandbox's debug log.
	 *
	 * @return string|null Absolute path to debug.log, or null if not in a sandbox.
	 */
	public static function log_path(): ?string {
		$path = self::path();
		return $path ? $path . '/wp-content/debug.log' : null;
	}

	/**
	 * Get the Rudel plugin version.
	 *
	 * @return string|null
	 */
	public static function version(): ?string {
		return defined( 'RUDEL_VERSION' ) ? RUDEL_VERSION : null;
	}

	/**
	 * Get the configured CLI command name.
	 *
	 * @return string
	 */
	public static function cli_command(): string {
		return defined( 'RUDEL_CLI_COMMAND' ) ? RUDEL_CLI_COMMAND : 'rudel';
	}

	/**
	 * Get the configured URL path prefix.
	 *
	 * @return string
	 */
	public static function path_prefix(): string {
		return defined( 'RUDEL_PATH_PREFIX' ) ? RUDEL_PATH_PREFIX : '__rudel';
	}

	/**
	 * Get all sandbox context as an array. Useful for debugging or passing to templates.
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
			'path_prefix'    => self::path_prefix(),
		);
	}

	/**
	 * Get the EnvironmentManager instance.
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
	 * Get the AppManager instance.
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
	 * Promote a sandbox to replace the host site.
	 *
	 * @param string $id         Sandbox identifier.
	 * @param string $backup_dir Directory to store the host backup.
	 * @return array{backup_path: string, tables_copied: int} Promotion results.
	 *
	 * @throws \RuntimeException If the sandbox is not found or promotion fails.
	 */
	public static function promote( string $id, string $backup_dir ): array {
		return self::manager()->promote( $id, $backup_dir );
	}

	/**
	 * Export a sandbox as a zip archive.
	 *
	 * @param string $id          Sandbox identifier.
	 * @param string $output_path Absolute path for the output zip file.
	 * @return void
	 *
	 * @throws \RuntimeException If the sandbox is not found or export fails.
	 */
	public static function export( string $id, string $output_path ): void {
		self::manager()->export( $id, $output_path );
	}

	/**
	 * Import a sandbox from a zip archive.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @param string $name     Human-readable name for the imported sandbox.
	 * @return Environment The imported sandbox.
	 *
	 * @throws \RuntimeException If the zip is invalid or import fails.
	 */
	public static function import( string $zip_path, string $name ): Environment {
		return self::manager()->import( $zip_path, $name );
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
	 * Get the sandboxes directory path.
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
	 * @return array<string, mixed>
	 */
	public static function deploy_sandbox_to_app( string $app_id, string $sandbox_id, ?string $backup_name = null ): array {
		return self::app_manager()->deploy( $app_id, $sandbox_id, $backup_name );
	}

	/**
	 * Get the apps directory path.
	 *
	 * @return string Absolute path.
	 */
	public static function apps_dir(): string {
		return self::app_manager()->get_apps_dir();
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

		$path = self::path();
		if ( ! $path ) {
			return;
		}

		$environment = Environment::from_path( $path );
		if ( $environment ) {
			$environment->touch_last_used();
		}
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
	 * Run scheduled cleanup tasks immediately.
	 *
	 * @return array<string, array<string, array<int, string>|array<string, string>>>
	 */
	public static function run_scheduled_cleanup(): array {
		return Automation::run();
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

		$context = array(
			'environment' => $sandbox,
			'repo'        => $repo,
			'message'     => $message,
			'subdir'      => $subdir,
		);
		Hooks::action( 'rudel_before_environment_push', $context );

		try {
			$github = new GitHubIntegration( $repo );
			$branch = $sandbox->get_git_branch();

			// Repeat pushes are normal, so an existing sandbox branch is not an error.
			try {
				$github->create_branch( $branch );
			} catch ( \RuntimeException $e ) {
				if ( ! str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					throw $e;
				}
			}

			$local_dir = $sandbox->get_wp_content_path();
			if ( '' !== $subdir ) {
				$local_dir .= '/' . ltrim( $subdir, '/' );
			}

			$sha = $github->push( $branch, $local_dir, $message );

			// Remember the repo after the first successful push so later calls can omit it.
			if ( $sha && ! $sandbox->get_github_repo() ) {
				$clone_source                = $sandbox->clone_source ?? array();
				$clone_source['github_repo'] = $repo;
				$sandbox->update_meta( 'clone_source', $clone_source );
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

			$result = $github->create_pr( $branch, $title, $body );
			Hooks::action( 'rudel_after_environment_pr', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_pr_failed', $context, $e );
			throw $e;
		}
	}
}
