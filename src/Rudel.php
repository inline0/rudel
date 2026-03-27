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
	 * Whether the current request is running inside a sandbox.
	 *
	 * @return bool
	 */
	public static function is_sandbox(): bool {
		return defined( 'RUDEL_SANDBOX_ID' ) && '' !== RUDEL_SANDBOX_ID;
	}

	/**
	 * Get the current sandbox ID, or null if not in a sandbox.
	 *
	 * @return string|null
	 */
	public static function id(): ?string {
		return self::is_sandbox() ? RUDEL_SANDBOX_ID : null;
	}

	/**
	 * Get the current sandbox's filesystem path, or null if not in a sandbox.
	 *
	 * @return string|null
	 */
	public static function path(): ?string {
		return defined( 'RUDEL_SANDBOX_PATH' ) ? RUDEL_SANDBOX_PATH : null;
	}

	/**
	 * Get the current sandbox's database engine, or null if not in a sandbox.
	 *
	 * @return string|null One of 'mysql', 'sqlite', 'subsite', or null.
	 */
	public static function engine(): ?string {
		if ( ! self::is_sandbox() ) {
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
	 * Get the current sandbox's table prefix, or null if not in a sandbox.
	 *
	 * @return string|null
	 */
	public static function table_prefix(): ?string {
		return defined( 'RUDEL_TABLE_PREFIX' ) ? RUDEL_TABLE_PREFIX : null;
	}

	/**
	 * Get the current sandbox's URL, or null if not in a sandbox.
	 *
	 * @return string|null
	 */
	public static function url(): ?string {
		if ( ! self::is_sandbox() ) {
			return null;
		}

		$prefix = defined( 'RUDEL_PATH_PREFIX' ) ? RUDEL_PATH_PREFIX : '__rudel';

		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' ) . '/' . $prefix . '/' . RUDEL_SANDBOX_ID . '/';
		}

		return '/' . $prefix . '/' . RUDEL_SANDBOX_ID . '/';
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
	 * Whether outbound email is disabled in the current sandbox.
	 *
	 * @return bool True if email is blocked.
	 */
	public static function is_email_disabled(): bool {
		return self::is_sandbox() && defined( 'RUDEL_DISABLE_EMAIL' ) && RUDEL_DISABLE_EMAIL;
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
			'id'             => self::id(),
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

	// Sandbox management.

	/**
	 * Get the SandboxManager instance.
	 *
	 * @return SandboxManager
	 */
	private static function manager(): SandboxManager {
		static $manager = null;
		if ( null === $manager ) {
			$manager = new SandboxManager();
		}
		return $manager;
	}

	/**
	 * List all sandboxes.
	 *
	 * @return Sandbox[] Array of sandbox instances.
	 */
	public static function all(): array {
		return self::manager()->list();
	}

	/**
	 * Get a single sandbox by ID.
	 *
	 * @param string $id Sandbox identifier.
	 * @return Sandbox|null Sandbox instance or null if not found.
	 */
	public static function get( string $id ): ?Sandbox {
		return self::manager()->get( $id );
	}

	/**
	 * Create a new sandbox.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $options Optional settings (engine, template, clone flags).
	 * @return Sandbox The newly created sandbox.
	 *
	 * @throws \RuntimeException If creation fails.
	 */
	public static function create( string $name, array $options = array() ): Sandbox {
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
	 * @return Sandbox The imported sandbox.
	 *
	 * @throws \RuntimeException If the zip is invalid or import fails.
	 */
	public static function import( string $zip_path, string $name ): Sandbox {
		return self::manager()->import( $zip_path, $name );
	}

	/**
	 * Clean up expired sandboxes.
	 *
	 * @param array $options Options: 'dry_run' (bool), 'max_age_days' (int override).
	 * @return array{removed: string[], skipped: string[], errors: string[]} Cleanup results.
	 */
	public static function cleanup( array $options = array() ): array {
		return self::manager()->cleanup( $options );
	}

	/**
	 * Get the sandboxes directory path.
	 *
	 * @return string Absolute path.
	 */
	public static function sandboxes_dir(): string {
		return self::manager()->get_sandboxes_dir();
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
}
