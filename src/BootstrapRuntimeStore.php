<?php
/**
 * Bootstrap runtime store.
 *
 * Loaded manually from bootstrap.php before WordPress core.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Resolves Rudel runtime records before WordPress has booted.
 */
class BootstrapRuntimeStore {

	/**
	 * Environment table suffix.
	 */
	private const ENVIRONMENTS_TABLE = 'rudel_environments';

	/**
	 * Apps table suffix.
	 */
	private const APPS_TABLE = 'rudel_apps';

	/**
	 * App domains table suffix.
	 */
	private const APP_DOMAINS_TABLE = 'rudel_app_domains';

	/**
	 * MySQL configuration.
	 *
	 * @var array<string, string>
	 */
	private array $mysql = array();

	/**
	 * WordPress database object when already available.
	 *
	 * @var \wpdb|null
	 */
	private ?object $wpdb = null;

	/**
	 * Base table prefix.
	 *
	 * @var string
	 */
	private string $prefix = 'wp_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$config_path = defined( 'RUDEL_WP_CONFIG_PATH' ) && is_string( RUDEL_WP_CONFIG_PATH ) ? RUDEL_WP_CONFIG_PATH : null;
		$config      = $this->parse_config_file( $config_path );

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$this->wpdb = $GLOBALS['wpdb'];
		}

		$this->prefix = $this->resolve_prefix( $config_path, $config );
		$this->mysql  = array(
			'host'     => defined( 'DB_HOST' ) && is_string( DB_HOST ) ? DB_HOST : ( $config['DB_HOST'] ?? 'localhost' ),
			'name'     => defined( 'DB_NAME' ) && is_string( DB_NAME ) ? DB_NAME : ( $config['DB_NAME'] ?? '' ),
			'user'     => defined( 'DB_USER' ) && is_string( DB_USER ) ? DB_USER : ( $config['DB_USER'] ?? '' ),
			'password' => defined( 'DB_PASSWORD' ) && is_string( DB_PASSWORD ) ? DB_PASSWORD : ( $config['DB_PASSWORD'] ?? '' ),
		);

		if ( null === $this->wpdb ) {
			$this->wpdb = $this->bootstrap_wpdb( $config_path );
		}
	}

	/**
	 * Resolve one environment by slug.
	 *
	 * @param string $slug Environment slug.
	 * @return array<string, mixed>|null
	 */
	public function environment_by_slug( string $slug ): ?array {
		return $this->fetch_environment(
			'SELECT id, app_id, slug, path, type, engine, multisite, blog_id FROM ' . $this->table( self::ENVIRONMENTS_TABLE ) . ' WHERE slug = ? LIMIT 1',
			array( $slug )
		);
	}

	/**
	 * Resolve one app environment by mapped domain.
	 *
	 * @param string $domain Normalized domain.
	 * @return array<string, mixed>|null
	 */
	public function app_by_domain( string $domain ): ?array {
		return $this->fetch_environment(
			'SELECT e.id, e.app_id, e.slug, e.path, e.type, e.engine, e.multisite, e.blog_id
			 FROM ' . $this->table( self::APP_DOMAINS_TABLE ) . ' d
			 INNER JOIN ' . $this->table( self::APPS_TABLE ) . ' a ON a.id = d.app_id
			 INNER JOIN ' . $this->table( self::ENVIRONMENTS_TABLE ) . ' e ON e.id = a.environment_id
			 WHERE d.domain = ? LIMIT 1',
			array( strtolower( trim( $domain ) ) )
		);
	}

	/**
	 * Run one environment lookup.
	 *
	 * @param string $sql SQL with one ? placeholder.
	 * @param array  $params Bound params.
	 * @return array<string, mixed>|null
	 */
	private function fetch_environment( string $sql, array $params ): ?array {
		if ( null === $this->wpdb ) {
			return null;
		}

		return $this->fetch_wpdb_row( $sql, $params );
	}

	/**
	 * Fetch one row from an already-loaded WordPress DB object.
	 *
	 * @param string $sql SQL query.
	 * @param array  $params Bound params.
	 * @return array<string, mixed>|null
	 */
	private function fetch_wpdb_row( string $sql, array $params ): ?array {
		return ( new WpdbStore( $this->wpdb ) )->fetch_row( $sql, $params );
	}

	/**
	 * Bootstrap a temporary wpdb instance from wp-config credentials.
	 *
	 * The sandbox bootstrap runs before WordPress has initialized globals, but
	 * Rudel runtime state still needs to be queried through the same MySQL
	 * connection model WordPress uses once core has booted.
	 *
	 * @param string|null $config_path wp-config.php path when known.
	 * @return \wpdb|null
	 */
	private function bootstrap_wpdb( ?string $config_path ): ?object {
		if ( '' === $this->mysql['name'] || '' === $this->mysql['user'] ) {
			return null;
		}

		if ( ! extension_loaded( 'mysqli' ) ) {
			return null;
		}

		if ( ! class_exists( '\wpdb', false ) ) {
			$wpdb_class = $this->wpdb_class_path( $config_path );
			if ( null === $wpdb_class ) {
				return null;
			}

			require_once $wpdb_class;
		}

		if ( ! class_exists( '\wpdb', false ) ) {
			return null;
		}

		try {
			$wpdb = new \wpdb(
				$this->mysql['user'],
				$this->mysql['password'],
				$this->mysql['name'],
				$this->mysql['host']
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( method_exists( $wpdb, 'suppress_errors' ) ) {
			$wpdb->suppress_errors( true );
		}

		if ( method_exists( $wpdb, 'set_prefix' ) ) {
			$wpdb->set_prefix( $this->prefix, false );
		} else {
			$wpdb->prefix      = $this->prefix;
			$wpdb->base_prefix = $this->prefix;
		}

		return $wpdb;
	}

	/**
	 * Locate WordPress's wpdb class file from the known bootstrap roots.
	 *
	 * @param string|null $config_path wp-config.php path when known.
	 * @return string|null
	 */
	private function wpdb_class_path( ?string $config_path ): ?string {
		$candidates = array();

		if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
			$candidates[] = rtrim( ABSPATH, '/' ) . '/wp-includes/class-wpdb.php';
		}

		if ( null !== $config_path ) {
			$candidates[] = dirname( $config_path ) . '/wp-includes/class-wpdb.php';
		}

		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Parse literal config values from wp-config.php.
	 *
	 * @param string|null $path Config path.
	 * @return array<string, string>
	 */
	private function parse_config_file( ?string $path ): array {
		if ( null === $path || ! file_exists( $path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pre-WP bootstrap reads one local config file directly.
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array();
		}

		$config = array();
		foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
			if ( preg_match( "/define\\(\\s*['\\\"]" . preg_quote( $constant, '/' ) . "['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]\\s*\\)/", $contents, $match ) ) {
				$config[ $constant ] = $match[1];
			}
		}

		return $config;
	}

	/**
	 * Resolve the WordPress base prefix.
	 *
	 * @param string|null           $config_path Config path.
	 * @param array<string, string> $config Parsed config.
	 * @return string
	 */
	private function resolve_prefix( ?string $config_path, array $config ): string {
		if ( isset( $GLOBALS['table_prefix'] ) && is_string( $GLOBALS['table_prefix'] ) && '' !== $GLOBALS['table_prefix'] ) {
			return $GLOBALS['table_prefix'];
		}

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$prefix = $GLOBALS['wpdb']->base_prefix ?? $GLOBALS['wpdb']->prefix ?? null;
			if ( is_string( $prefix ) && '' !== $prefix ) {
				return $prefix;
			}
		}

		if ( null !== $config_path && file_exists( $config_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pre-WP bootstrap reads one local config file directly.
			$contents = file_get_contents( $config_path );
			if ( false !== $contents && preg_match( "/\\\$table_prefix\\s*=\\s*['\\\"]([^'\\\"]+)['\\\"]\\s*;/", $contents, $match ) ) {
				return $match[1];
			}
		}

		return 'wp_';
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $suffix Logical table suffix.
	 * @return string
	 */
	private function table( string $suffix ): string {
		return $this->prefix . $suffix;
	}
}
