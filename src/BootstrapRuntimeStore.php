<?php
// phpcs:ignoreFile -- This file runs before WordPress can safely construct wpdb, so Rudel has one sanctioned direct-MySQL bootstrap path here.
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

	/** Environment table suffix. */
	private const ENVIRONMENTS_TABLE = 'environments';

	/** Apps table suffix. */
	private const APPS_TABLE = 'apps';

	/** App domains table suffix. */
	private const APP_DOMAINS_TABLE = 'app_domains';

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
	 * Direct MySQL connection for pre-WordPress lookups.
	 *
	 * @var \mysqli|null
	 */
	private $mysqli = null;

	/** WordPress base table prefix. */
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
			$this->mysqli = $this->bootstrap_mysqli();
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
		if ( null !== $this->wpdb ) {
			return $this->fetch_wpdb_row( $sql, $params );
		}

		if ( null === $this->mysqli ) {
			return null;
		}

		return $this->fetch_mysqli_row( $sql, $params );
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
	 * Fetch one row through a direct mysqli connection.
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound params.
	 * @return array<string, mixed>|null
	 */
	private function fetch_mysqli_row( string $sql, array $params ): ?array {
		$query = $this->prepare_mysqli_query( $sql, $params );

		$result = mysqli_query( $this->mysqli, $query );
		if ( false === $result ) {
			return null;
		}

		$row = mysqli_fetch_assoc( $result );
		mysqli_free_result( $result );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Bootstrap a direct MySQL connection from wp-config credentials.
	 *
	 * @return \mysqli|null
	 */
	private function bootstrap_mysqli() {
		if ( '' === $this->mysql['name'] || '' === $this->mysql['user'] ) {
			return null;
		}

		if ( ! extension_loaded( 'mysqli' ) ) {
			return null;
		}

		$parsed_host  = $this->parse_db_host( $this->mysql['host'] );
		$host         = $parsed_host['host'];
		$port         = $parsed_host['port'];
		$socket       = $parsed_host['socket'];
		$client_flags = defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0;

		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = mysqli_init();
		if ( false === $mysqli ) {
			return null;
		}

		$connected = mysqli_real_connect(
			$mysqli,
			$host,
			$this->mysql['user'],
			$this->mysql['password'],
			$this->mysql['name'],
			$port,
			$socket,
			$client_flags
		);

		if ( false === $connected ) {
			return null;
		}

		$charset = defined( 'DB_CHARSET' ) && is_string( DB_CHARSET ) && '' !== DB_CHARSET ? DB_CHARSET : 'utf8mb4';
		mysqli_set_charset( $mysqli, $charset );

		return $mysqli;
	}

	/**
	 * Parse DB_HOST into mysqli connection parts.
	 *
	 * @param string $host DB host string.
	 * @return array{host: string, port: int, socket: string|null}
	 */
	private function parse_db_host( string $host ): array {
		$socket = null;
		$port   = 0;

		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			if ( preg_match( '#^(?:\\[)?(?P<host>[0-9a-fA-F:]+)(?:\\]:(?P<port>[\\d]+))?#', $host, $match ) ) {
				$host = $match['host'];
				$port = ! empty( $match['port'] ) ? (int) $match['port'] : 0;
			}
		} elseif ( preg_match( '#^(?P<host>[^:/]*)(?::(?P<port>[\\d]+))?#', $host, $match ) ) {
			$host = $match['host'];
			$port = ! empty( $match['port'] ) ? (int) $match['port'] : 0;
		}

		return array(
			'host'   => $host,
			'port'   => $port,
			'socket' => $socket,
		);
	}

	/**
	 * Prepare a lookup query for mysqli without relying on wpdb.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound params.
	 * @return string
	 */
	private function prepare_mysqli_query( string $sql, array $params ): string {
		if ( empty( $params ) ) {
			return $sql;
		}

		$segments = explode( '?', $sql );
		$query    = array_shift( $segments );

		foreach ( $params as $index => $value ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Pre-WP bootstrap cannot prepare through wpdb, so values are escaped manually before interpolation into Rudel-owned lookup SQL.
			$query .= "'" . mysqli_real_escape_string( $this->mysqli, (string) $value ) . "'" . ( $segments[ $index ] ?? '' );
		}

		return $query;
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
		return $this->prefix . RuntimeTableConfig::table( $suffix );
	}
}
