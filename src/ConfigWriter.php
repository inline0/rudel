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
	private const MARKER = '// Rudel environment bootstrap';

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
		$line           = "if ( ! defined( 'RUDEL_WP_CONFIG_PATH' ) ) { define( 'RUDEL_WP_CONFIG_PATH', __FILE__ ); } require_once '{$bootstrap_path}'; " . self::MARKER;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local wp-config.php.
		$contents = file_get_contents( $config_path );
		if ( false === $contents ) {
			throw new \RuntimeException( sprintf( 'Failed to read wp-config.php: %s', $config_path ) );
		}

		// Run immediately before wp-settings.php so dynamic DB constants from wp-config.php are already available.
		$result = preg_replace(
			'/^(\s*require(?:_once)?\s.*wp-settings\.php.*$)/mi',
			"{$line}\n$1",
			$contents,
			1
		);
		if ( null === $result ) {
			throw new \RuntimeException( 'Failed to inject bootstrap line into wp-config.php' );
		}

		if ( $result === $contents ) {
			$result = preg_replace(
				'/^<\?php\s*/i',
				"<?php\n{$line}\n",
				$contents,
				1
			);
			if ( null === $result ) {
				throw new \RuntimeException( 'Failed to inject bootstrap line into wp-config.php' );
			}
		}

		$contents = $result;

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
		if ( false === $contents ) {
			return;
		}
		$lines = explode( "\n", $contents );
		$lines = array_filter( $lines, fn( $line ) => ! str_contains( $line, self::MARKER ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing local wp-config.php.
		file_put_contents( $config_path, implode( "\n", $lines ) );
		$loader = $this->git_loader_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only Rudel's generated mu-plugin during host deactivation.
		if ( is_file( $loader ) ) {
			unlink( $loader );
		}
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
		$contents = file_get_contents( $config_path );

		return false !== $contents && str_contains( $contents, self::MARKER );
	}

	/**
	 * Install the request-selected Git plugin loader in WordPress's global plugin scope.
	 *
	 * @throws \RuntimeException If the loader cannot be installed safely.
	 * @return void
	 */
	public function install_git_loader(): void {
		$path     = $this->git_loader_path();
		$contents = <<<'PHP'
<?php
/** Rudel Git checkout loader. */
if ( ! defined( 'RUDEL_GIT_PLUGIN_FILE' ) || ! defined( 'RUDEL_BOOTSTRAP_PLUGIN_DIR' ) ) {
	return;
}
require_once RUDEL_BOOTSTRAP_PLUGIN_DIR . '/rudel.php';
rudel_enable_sandbox_logging( RUDEL_PATH );
get_option( 'active_plugins', array() );
if ( ! empty( $GLOBALS['rudel_git_plugin_active'] ) ) {
	require_once RUDEL_GIT_PLUGIN_FILE;
}
PHP;
		// phpcs:disable WordPress.WP.AlternativeFunctions -- The loader is a filesystem entry point consumed by WordPress before ordinary plugins.
		$existing = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( $existing === $contents . "\n" ) {
			return;
		}
		if ( false !== $existing && ! str_contains( $existing, 'Rudel Git checkout loader' ) ) {
			throw new \RuntimeException( 'Git loader path is occupied: ' . $path );
		}
		if ( ! is_dir( dirname( $path ) ) && ! mkdir( dirname( $path ), 0755, true ) ) {
			throw new \RuntimeException( 'Cannot create mu-plugin directory.' );
		}
		if ( false === file_put_contents( $path, $contents . "\n" ) ) {
			throw new \RuntimeException( 'Cannot install Git plugin loader.' );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
	}

	/**
	 * Filesystem entry point for selected Git plugins.
	 *
	 * @return string Loader path.
	 */
	private function git_loader_path(): string {
		$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		return rtrim( $content, '/' ) . '/mu-plugins/rudel-git-checkout.php';
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
			// Some installs keep wp-config.php above ABSPATH.
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
