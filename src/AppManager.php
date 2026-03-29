<?php
/**
 * App manager: creates and manages permanent domain-routed WordPress environments.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages apps (permanent isolated WordPress environments routed by domain).
 * Delegates sandbox lifecycle operations to EnvironmentManager.
 */
class AppManager {

	/**
	 * Sandbox manager instance (handles the actual create/destroy/list).
	 *
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $manager;

	/**
	 * Absolute path to the apps directory.
	 *
	 * @var string
	 */
	private string $apps_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $apps_dir Optional override for the apps directory.
	 */
	public function __construct( ?string $apps_dir = null ) {
		$this->apps_dir = $apps_dir ?? $this->get_default_apps_dir();
		$this->manager  = new EnvironmentManager( $this->apps_dir );
	}

	/**
	 * Create a new app.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $domains Array of domain names for this app.
	 * @param array  $options Optional settings (engine, clone flags).
	 * @return Environment The newly created app environment.
	 *
	 * @throws \InvalidArgumentException If domains are invalid, conflicting, or subsite engine requested.
	 */
	public function create( string $name, array $domains, array $options = array() ): Environment {
		if ( empty( $domains ) ) {
			throw new \InvalidArgumentException( 'At least one domain is required for an app.' );
		}

		$domains = array_values(
			array_unique(
				array_map( array( $this, 'normalize_domain' ), $domains )
			)
		);

		foreach ( $domains as $domain ) {
			$this->validate_domain( $domain );
			$this->check_domain_conflict( $domain );
		}

		if ( 'subsite' === ( $options['engine'] ?? 'mysql' ) ) {
			throw new \InvalidArgumentException( 'Apps cannot use the subsite engine.' );
		}

		$options['type']        = 'app';
		$options['domains']     = $domains;
		$options['skip_limits'] = true;

		$app = $this->manager->create( $name, $options );

		$this->rebuild_domain_map();

		return $app;
	}

	/**
	 * List all apps.
	 *
	 * @return Environment[] Array of app instances.
	 */
	public function list(): array {
		return $this->manager->list();
	}

	/**
	 * Get a single app by ID.
	 *
	 * @param string $id App identifier.
	 * @return Environment|null App instance or null if not found.
	 */
	public function get( string $id ): ?Environment {
		return $this->manager->get( $id );
	}

	/**
	 * Destroy an app by ID.
	 *
	 * @param string $id App identifier.
	 * @return bool True on success.
	 */
	public function destroy( string $id ): bool {
		$result = $this->manager->destroy( $id );
		if ( $result ) {
			$this->rebuild_domain_map();
		}
		return $result;
	}

	/**
	 * Add a domain to an existing app.
	 *
	 * @param string $id     App identifier.
	 * @param string $domain Domain name to add.
	 * @return void
	 *
	 * @throws \RuntimeException If the app is not found or domain is invalid.
	 */
	public function add_domain( string $id, string $domain ): void {
		$app = $this->get( $id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		$domain = $this->normalize_domain( $domain );
		$this->validate_domain( $domain );
		$this->check_domain_conflict( $domain, $id );

		$domains   = array_map( array( $this, 'normalize_domain' ), $app->domains ?? array() );
		$domains[] = $domain;
		$app->update_meta( 'domains', array_values( array_unique( $domains ) ) );
		$this->rebuild_domain_map();
	}

	/**
	 * Remove a domain from an app.
	 *
	 * @param string $id     App identifier.
	 * @param string $domain Domain name to remove.
	 * @return void
	 *
	 * @throws \RuntimeException If the app is not found.
	 * @throws \InvalidArgumentException If removing the last domain.
	 */
	public function remove_domain( string $id, string $domain ): void {
		$app = $this->get( $id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		$domain  = $this->normalize_domain( $domain );
		$domains = array_map( array( $this, 'normalize_domain' ), $app->domains ?? array() );
		$domains = array_values( array_filter( $domains, fn( $d ) => $d !== $domain ) );

		if ( empty( $domains ) ) {
			throw new \InvalidArgumentException( 'Cannot remove the last domain from an app.' );
		}

		$app->update_meta( 'domains', $domains );
		$this->rebuild_domain_map();
	}

	/**
	 * Get the apps directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_apps_dir(): string {
		return $this->apps_dir;
	}

	/**
	 * Rebuild the domains.json mapping file from all app metadata.
	 *
	 * @return void
	 */
	public function rebuild_domain_map(): void {
		$map  = array();
		$apps = $this->list();

		foreach ( $apps as $app ) {
			if ( empty( $app->domains ) ) {
				continue;
			}
			foreach ( $app->domains as $domain ) {
				$map[ $this->normalize_domain( $domain ) ] = $app->id;
			}
		}

		$map_path = $this->apps_dir . '/domains.json';

		if ( ! is_dir( $this->apps_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating apps directory.
			mkdir( $this->apps_dir, 0755, true );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing domain map.
		file_put_contents(
			$map_path,
			json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable
	}

	/**
	 * Read the domain map.
	 *
	 * @return array<string, string> Domain to app ID mapping.
	 */
	public function get_domain_map(): array {
		$map_path = $this->apps_dir . '/domains.json';
		if ( ! file_exists( $map_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading domain map.
		$data = json_decode( file_get_contents( $map_path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$map = array();
		foreach ( $data as $domain => $id ) {
			if ( is_string( $domain ) && is_string( $id ) ) {
				$map[ $this->normalize_domain( $domain ) ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Validate a domain name.
	 *
	 * @param string $domain Domain to validate.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the domain is invalid.
	 */
	private function validate_domain( string $domain ): void {
		if ( ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid domain: %s', $domain ) );
		}
	}

	/**
	 * Normalize a domain name for metadata and lookup storage.
	 *
	 * @param string $domain Domain name from user input or metadata.
	 * @return string
	 */
	private function normalize_domain( string $domain ): string {
		return strtolower( trim( $domain ) );
	}

	/**
	 * Check for domain conflicts with existing apps.
	 *
	 * @param string      $domain    Domain to check.
	 * @param string|null $exclude_id App ID to exclude from the check (for updates).
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the domain is already mapped to another app.
	 */
	private function check_domain_conflict( string $domain, ?string $exclude_id = null ): void {
		$map = $this->get_domain_map();
		if ( isset( $map[ $domain ] ) && $map[ $domain ] !== $exclude_id ) {
			throw new \InvalidArgumentException(
				sprintf( 'Domain "%s" is already mapped to app "%s".', $domain, $map[ $domain ] )
			);
		}
	}

	/**
	 * Get the default apps directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_apps_dir(): string {
		if ( defined( 'RUDEL_APPS_DIR' ) ) {
			return RUDEL_APPS_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-apps';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-apps';
	}
}
