<?php
/**
 * Manages wp-config.php bootstrap line injection.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Injects and removes the Rudel bootstrap require line in wp-config.php.
 */
class ConfigWriter {

	/**
	 * Marker comment used to identify the injected line.
	 *
	 * @var string
	 */
	private const MARKER = '// Rudel sandbox bootstrap';

	/**
	 * Inject the bootstrap require line into wp-config.php.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If wp-config.php is not writable.
	 */
	public function install(): void {
		$config_path = $this->get_config_path();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct check required before wp-config.php modification.
		if ( ! is_writable( $config_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException(
				sprintf( 'wp-config.php is not writable: %s', $config_path )
			);
		}

		if ( $this->is_installed() ) {
			return;
		}

		$this->backup( $config_path );

		$bootstrap_path = dirname( RUDEL_PLUGIN_FILE ) . '/bootstrap.php';
		$line           = "require_once '{$bootstrap_path}'; " . self::MARKER;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local wp-config.php.
		$contents = file_get_contents( $config_path );
		$contents = preg_replace(
			'/^<\?php\s*/i',
			"<?php\n{$line}\n",
			$contents,
			1
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing local wp-config.php.
		file_put_contents( $config_path, $contents );
	}

	/**
	 * Remove the bootstrap require line from wp-config.php.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		$config_path = $this->get_config_path();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Direct check before wp-config.php modification.
		if ( ! is_writable( $config_path ) ) {
			return;
		}

		if ( ! $this->is_installed() ) {
			return;
		}

		$this->backup( $config_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local wp-config.php.
		$contents = file_get_contents( $config_path );
		$lines    = explode( "\n", $contents );
		$lines    = array_filter( $lines, fn( $line ) => ! str_contains( $line, self::MARKER ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing local wp-config.php.
		file_put_contents( $config_path, implode( "\n", $lines ) );
	}

	/**
	 * Check whether the bootstrap line is already present in wp-config.php.
	 *
	 * @return bool True if the marker line exists.
	 */
	public function is_installed(): bool {
		$config_path = $this->get_config_path();

		if ( ! is_readable( $config_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local wp-config.php.
		return str_contains( file_get_contents( $config_path ), self::MARKER );
	}

	/**
	 * Locate wp-config.php relative to ABSPATH.
	 *
	 * @return string Absolute path to wp-config.php.
	 *
	 * @throws \RuntimeException If wp-config.php cannot be found.
	 */
	private function get_config_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			$path = ABSPATH . 'wp-config.php';
			if ( file_exists( $path ) ) {
				return $path;
			}
			// One level up is a common WP setup.
			$path = dirname( ABSPATH ) . '/wp-config.php';
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		throw new \RuntimeException( 'Could not locate wp-config.php' );
	}

	/**
	 * Create a timestamped backup of wp-config.php.
	 *
	 * @param string $config_path Path to wp-config.php.
	 * @return void
	 */
	private function backup( string $config_path ): void {
		$backup_path = $config_path . '.rudel-backup-' . gmdate( 'Y-m-d-His' );
		copy( $config_path, $backup_path );
	}
}
