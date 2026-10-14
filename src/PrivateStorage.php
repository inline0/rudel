<?php
/**
 * Protect environment files that must not be served as public assets.
 *
 * @package Rudel
 */

namespace Rudel;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Used before WordPress boots; native filesystem functions are required.

/** Filesystem protection shared by bootstrap and lifecycle operations. */
class PrivateStorage {

	/** PHP stops before any log content, including embedded PHP fragments. */
	public const LOG_HEADER = "<?php http_response_code(403); exit; __halt_compiler();\n";

	/**
	 * Install Apache denial and a PHP directory entry guard.
	 *
	 * @param string $directory Private directory.
	 * @return void
	 * @throws \RuntimeException When protection cannot be written.
	 */
	public static function protect( string $directory ): void {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0755, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'Cannot create private directory: ' . $directory );
		}
		self::write_guard( $directory . '/.htaccess', "Require all denied\n" );
		self::write_guard( $directory . '/index.php', "<?php http_response_code(403); exit;\n" );
	}

	/**
	 * Protect private collections without blocking theme and plugin assets.
	 *
	 * @param string $path Environment root.
	 * @return void
	 */
	public static function environment( string $path ): void {
		foreach ( array( 'logs', 'snapshots', 'backups', 'metadata', 'tmp' ) as $directory ) {
			self::protect( $path . '/' . $directory );
		}
		self::write_guard( $path . '/.htaccess', "Options -Indexes\n<Files \"debug.log\">\nRequire all denied\n</Files>\n" );
	}

	/**
	 * Initialize a guarded log and migrate the previous plaintext log.
	 *
	 * @param string $path Environment root.
	 * @return string Log filename.
	 * @throws \RuntimeException When the log cannot be initialized or migrated.
	 */
	public static function log_file( string $path ): string {
		self::environment( $path );
		$filename = $path . '/logs/debug.php';
		if ( ! is_file( $filename ) ) {
			$handle = fopen( $filename, 'x' );
			if ( false !== $handle ) {
				$written = fwrite( $handle, self::LOG_HEADER );
				fclose( $handle );
				if ( strlen( self::LOG_HEADER ) !== $written ) {
					throw new \RuntimeException( 'Cannot initialize sandbox log.' );
				}
			} elseif ( ! is_file( $filename ) ) {
				throw new \RuntimeException( 'Cannot open sandbox log.' );
			}
		}
		$legacy = $path . '/debug.log';
		if ( is_file( $legacy ) ) {
			$content = file_get_contents( $legacy );
			if ( false === $content || false === file_put_contents( $filename, $content, FILE_APPEND | LOCK_EX ) || ! unlink( $legacy ) ) {
				throw new \RuntimeException( 'Cannot migrate sandbox log.' );
			}
		}
		return $filename;
	}

	/**
	 * Create a guard once, avoiding writes on ordinary selected requests.
	 *
	 * @param string $path Guard filename.
	 * @param string $content Guard contents.
	 * @return void
	 * @throws \RuntimeException When a guard cannot be installed.
	 */
	private static function write_guard( string $path, string $content ): void {
		if ( is_file( $path ) ) {
			return;
		}
		if ( false === file_put_contents( $path, $content, LOCK_EX ) ) {
			throw new \RuntimeException( 'Cannot protect private storage: ' . $path );
		}
	}
}
