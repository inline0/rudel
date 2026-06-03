<?php
/**
 * Manages wp-config.php bootstrap line injection.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Injects and removes the runtime bootstrap require line in wp-config.php.
 */
class ConfigWriter {

	/**
	 * Runtime profile to install.
	 *
	 * @var RuntimeProfile|null
	 */
	private ?RuntimeProfile $profile;

	/**
	 * Initialize dependencies.
	 *
	 * @param RuntimeProfile|null $profile Runtime profile to install.
	 */
	public function __construct( ?RuntimeProfile $profile = null ) {
		$this->profile = $profile;
	}

	/**
	 * Inject the bootstrap require line into wp-config.php.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If wp-config.php is not writable.
	 */
	public function install(): void {
		$config_path = $this->get_config_path();
		$profile     = $this->profile();

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

		$this->write_profile_config( $profile );

		$bootstrap_path     = dirname( RUDEL_PLUGIN_FILE ) . '/bootstrap.php';
		$profile_path       = $profile->bootstrap_config_path();
		$wp_config_constant = $profile->constant( 'wp_config_path' );
		$line               = "if ( ! defined( '{$wp_config_constant}' ) ) { define( '{$wp_config_constant}', __FILE__ ); } \$GLOBALS['rudel_runtime_profile'] = require '{$profile_path}'; require_once '{$bootstrap_path}'; " . $profile->wp_config_marker();

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
		$lines  = explode( "\n", $contents );
		$marker = $this->profile()->wp_config_marker();
		$lines  = array_filter( $lines, fn( $line ) => ! str_contains( $line, $marker ) );
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
		$contents = file_get_contents( $config_path );

		return false !== $contents && str_contains( $contents, $this->profile()->wp_config_marker() );
	}

	/**
	 * Resolve the runtime profile to install.
	 *
	 * @return RuntimeProfile
	 * @throws \RuntimeException If no runtime profile is available.
	 */
	private function profile(): RuntimeProfile {
		if ( null !== $this->profile ) {
			return $this->profile;
		}

		try {
			$this->profile = RuntimeProfile::current();
			return $this->profile;
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$filtered = Hooks::filter( 'rudel_runtime_profile', null );
		if ( $filtered instanceof RuntimeProfile ) {
			$this->profile = $filtered;
			return $this->profile;
		}
		if ( is_array( $filtered ) ) {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
			/** @var array<string, mixed> $filtered */
			$this->profile = RuntimeProfile::from_array( $filtered );
			return $this->profile;
		}

		throw new \RuntimeException( 'A runtime profile is required before installing the runtime bootstrap.' );
	}

	/**
	 * Write the generated profile config file loaded from wp-config.php.
	 *
	 * @param RuntimeProfile $profile Runtime profile.
	 * @return void
	 */
	private function write_profile_config( RuntimeProfile $profile ): void {
		$profile_path = $profile->bootstrap_config_path();
		$directory    = dirname( $profile_path );

		if ( ! is_dir( $directory ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating generated profile config directory.
			mkdir( $directory, 0755, true );
		}

		$contents = "<?php\nreturn " . var_export( $profile->to_array(), true ) . ";\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing generated runtime profile config.
		file_put_contents( $profile_path, $contents );
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
		$backup_path = $config_path . '.runtime-backup-' . gmdate( 'Y-m-d-His' );
		copy( $config_path, $backup_path );
	}
}
