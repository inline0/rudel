<?php
/**
 * Configuration manager for Rudel settings.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Reads and writes Rudel configuration from a JSON file.
 */
class RudelConfig {

	/**
	 * Default configuration values.
	 *
	 * @var array<string, int>
	 */
	private const DEFAULTS = array(
		'max_sandboxes'                       => 0,
		'max_age_days'                        => 0,
		'max_disk_mb'                         => 0,
		'default_ttl_days'                    => 0,
		'max_idle_days'                       => 0,
		'auto_cleanup_enabled'                => 1,
		'auto_cleanup_merged'                 => 0,
		'auto_app_backups_enabled'            => 0,
		'auto_app_backup_interval_hours'      => 24,
		'auto_app_backup_retention_count'     => 0,
		'auto_app_deployment_retention_count' => 0,
		'expiring_environment_notice_days'    => 0,
		'auto_snapshot_before_restore'        => 1,
		'auto_backup_before_app_restore'      => 1,
	);

	/**
	 * Absolute path to the config file.
	 *
	 * @var string
	 */
	private string $config_path;

	/**
	 * Loaded configuration values.
	 *
	 * @var array<string, int>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param string|null $config_path Optional override for the config file path.
	 */
	public function __construct( ?string $config_path = null ) {
		$this->config_path = $config_path ?? $this->get_default_config_path();
		$this->data        = $this->load();
	}

	/**
	 * Get a configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return int Configuration value, or 0 if not set.
	 */
	public function get( string $key ): int {
		return $this->data[ $key ] ?? ( self::DEFAULTS[ $key ] ?? 0 );
	}

	/**
	 * Set a configuration value.
	 *
	 * @param string $key   Configuration key.
	 * @param int    $value Configuration value.
	 * @return void
	 */
	public function set( string $key, int $value ): void {
		$this->data[ $key ] = $value;
	}

	/**
	 * Get all configuration values with defaults applied.
	 *
	 * @return array<string, int> All configuration values.
	 */
	public function all(): array {
		return array_merge( self::DEFAULTS, $this->data );
	}

	/**
	 * Save configuration to disk.
	 *
	 * @return void
	 */
	public function save(): void {
		$dir = dirname( $this->config_path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating config directory.
			mkdir( $dir, 0755, true );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing Rudel config file.
		file_put_contents(
			$this->config_path,
			json_encode( $this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable
	}

	/**
	 * Get the config file path.
	 *
	 * @return string Absolute path.
	 */
	public function get_config_path(): string {
		return $this->config_path;
	}

	/**
	 * Load configuration from disk.
	 *
	 * @return array<string, int> Loaded configuration data.
	 */
	private function load(): array {
		if ( ! file_exists( $this->config_path ) ) {
			return self::DEFAULTS;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local config file.
		$raw  = file_get_contents( $this->config_path );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return self::DEFAULTS;
		}

		return array_merge( self::DEFAULTS, $data );
	}

	/**
	 * Determine the default config file path.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_config_path(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( WP_CONTENT_DIR, '/' ) . '/rudel-config.json';
		}
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' ) . '/wp-content/rudel-config.json';
		}
		return dirname( __DIR__, 3 ) . '/wp-content/rudel-config.json';
	}
}
