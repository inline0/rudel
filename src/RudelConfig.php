<?php
/**
 * Configuration manager for Rudel settings.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Reads and writes Rudel configuration from WordPress-native storage.
 */
class RudelConfig {

	/**
	 * Option name used for Rudel configuration.
	 */
	private const OPTION_NAME = 'rudel_config';

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
	 * Loaded configuration values.
	 *
	 * @var array<string, int>
	 */
	private array $data;

	public function __construct() {
		$this->data = $this->load();
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
	 * Save configuration to the WordPress options table.
	 *
	 * @return void
	 */
	public function save(): void {
		$data = $this->all();

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_NAME, $data, false );
			$this->data = $data;
			return;
		}

		$this->save_via_wpdb( $data );
		$this->data = $data;
	}

	/**
	 * Get the WordPress option key used for Rudel configuration.
	 *
	 * @return string
	 */
	public function option_name(): string {
		return self::OPTION_NAME;
	}

	/**
	 * Load configuration from WordPress-native storage.
	 *
	 * @return array<string, int> Loaded configuration data.
	 */
	private function load(): array {
		if ( function_exists( 'get_option' ) ) {
			$data = get_option( self::OPTION_NAME, array() );
			if ( ! is_array( $data ) ) {
				return self::DEFAULTS;
			}

			return $this->normalize( $data );
		}

		return $this->load_via_wpdb();
	}

	/**
	 * Load configuration directly from the WordPress options table.
	 *
	 * @return array<string, int>
	 */
	private function load_via_wpdb(): array {
		$table = $this->options_table();
		$store = new WpdbStore();
		$raw   = $store->fetch_var(
			'SELECT option_value FROM `' . $table . '` WHERE option_name = ? LIMIT 1',
			array( self::OPTION_NAME )
		);

		if ( ! is_string( $raw ) || '' === $raw ) {
			return self::DEFAULTS;
		}

		$data = $this->unserialize_value( $raw );

		if ( ! is_array( $data ) ) {
			return self::DEFAULTS;
		}

		return $this->normalize( $data );
	}

	/**
	 * Save configuration directly to the WordPress options table.
	 *
	 * @param array<string, int> $data Config payload.
	 * @return void
	 */
	private function save_via_wpdb( array $data ): void {
		$table = $this->options_table();
		$store = new WpdbStore();
		$raw   = $this->serialize_value( $data );
		$found = $store->fetch_var(
			'SELECT option_id FROM `' . $table . '` WHERE option_name = ? LIMIT 1',
			array( self::OPTION_NAME )
		);

		if ( null === $found ) {
			$store->insert(
				$table,
				array(
					'option_name'  => self::OPTION_NAME,
					'option_value' => $raw,
					'autoload'     => 'no',
				)
			);
			return;
		}

		$store->update(
			$table,
			array(
				'option_value' => $raw,
			),
			array( 'option_name' => self::OPTION_NAME )
		);
	}

	/**
	 * Resolve the host WordPress options table.
	 *
	 * @return string
	 */
	private function options_table(): string {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->base_prefix ) || ! is_string( $wpdb->base_prefix ) ) {
			throw new \RuntimeException( 'WordPress options table is not available.' );
		}

		return $wpdb->base_prefix . 'options';
	}

	/**
	 * Normalize persisted configuration to the supported key set.
	 *
	 * @param array<mixed, mixed> $data Raw configuration data.
	 * @return array<string, int>
	 */
	private function normalize( array $data ): array {
		$normalized = self::DEFAULTS;

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$normalized[ $key ] = (int) $data[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Serialize the config payload for wp_options storage.
	 *
	 * @param array<string, int> $data Config payload.
	 * @return string
	 */
	private function serialize_value( array $data ): string {
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $data );
		}

		return serialize( $data );
	}

	/**
	 * Unserialize a stored wp_options payload.
	 *
	 * @param string $raw Stored option value.
	 * @return mixed
	 */
	private function unserialize_value( string $raw ) {
		if ( function_exists( 'maybe_unserialize' ) ) {
			return maybe_unserialize( $raw );
		}

		$value = @unserialize( $raw, array( 'allowed_classes' => false ) );

		return false === $value && 'b:0;' !== $raw ? $raw : $value;
	}
}
