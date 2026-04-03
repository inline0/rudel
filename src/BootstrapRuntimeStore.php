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
	 * Database driver.
	 *
	 * @var string
	 */
	private string $driver = 'mysql';

	/**
	 * MySQL configuration.
	 *
	 * @var array<string, string>
	 */
	private array $mysql = array();

	/**
	 * SQLite path.
	 *
	 * @var string|null
	 */
	private ?string $sqlite_path = null;

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

		if ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			$this->driver = 'sqlite';
		} elseif ( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ) {
			$this->driver = 'sqlite';
		} elseif ( isset( $config['DB_ENGINE'] ) && 'sqlite' === $config['DB_ENGINE'] ) {
			$this->driver = 'sqlite';
		} elseif ( isset( $config['DATABASE_TYPE'] ) && 'sqlite' === $config['DATABASE_TYPE'] ) {
			$this->driver = 'sqlite';
		}

		$this->prefix = $this->resolve_prefix( $config_path, $config );

		if ( 'sqlite' === $this->driver ) {
			$db_dir  = defined( 'DB_DIR' ) && is_string( DB_DIR ) ? DB_DIR : ( $config['DB_DIR'] ?? null );
			$db_file = defined( 'DB_FILE' ) && is_string( DB_FILE ) ? DB_FILE : ( $config['DB_FILE'] ?? 'wordpress.db' );
			if ( is_string( $db_dir ) && '' !== $db_dir ) {
				$this->sqlite_path = rtrim( $db_dir, '/' ) . '/' . $db_file;
			}
			return;
		}

		$this->mysql = array(
			'host'     => defined( 'DB_HOST' ) && is_string( DB_HOST ) ? DB_HOST : ( $config['DB_HOST'] ?? 'localhost' ),
			'name'     => defined( 'DB_NAME' ) && is_string( DB_NAME ) ? DB_NAME : ( $config['DB_NAME'] ?? '' ),
			'user'     => defined( 'DB_USER' ) && is_string( DB_USER ) ? DB_USER : ( $config['DB_USER'] ?? '' ),
			'password' => defined( 'DB_PASSWORD' ) && is_string( DB_PASSWORD ) ? DB_PASSWORD : ( $config['DB_PASSWORD'] ?? '' ),
		);
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
		if ( 'sqlite' === $this->driver ) {
			return $this->fetch_sqlite_row( $sql, $params );
		}

		return $this->fetch_mysql_row( $sql, $params );
	}

	/**
	 * Fetch one row from SQLite.
	 *
	 * @param string $sql SQL query.
	 * @param array  $params Bound params.
	 * @return array<string, mixed>|null
	 */
	private function fetch_sqlite_row( string $sql, array $params ): ?array {
		if ( null === $this->sqlite_path || ! file_exists( $this->sqlite_path ) ) {
			return null;
		}

		$pdo = new \PDO( 'sqlite:' . $this->sqlite_path );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$stmt = $pdo->prepare( $sql );
		$stmt->execute( array_values( $params ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );
		$pdo = null;

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch one row from MySQL.
	 *
	 * @param string $sql SQL query.
	 * @param array  $params Bound params.
	 * @return array<string, mixed>|null
	 */
	private function fetch_mysql_row( string $sql, array $params ): ?array {
		if ( '' === $this->mysql['name'] || '' === $this->mysql['user'] ) {
			return null;
		}

		$connection = mysqli_init();
		if ( false === $connection ) {
			return null;
		}

		$host = $this->mysql['host'];
		$port = 3306;
		$socket = null;

		if ( str_contains( $host, ':' ) ) {
			$parts = explode( ':', $host );
			$host  = $parts[0];
			if ( isset( $parts[1] ) && ctype_digit( $parts[1] ) ) {
				$port = (int) $parts[1];
			} elseif ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$socket = $parts[1];
			}
		}

		if ( ! @mysqli_real_connect( $connection, $host, $this->mysql['user'], $this->mysql['password'], $this->mysql['name'], $port, $socket ) ) {
			mysqli_close( $connection );
			return null;
		}

		$stmt = mysqli_prepare( $connection, $sql );
		if ( false === $stmt ) {
			mysqli_close( $connection );
			return null;
		}

		if ( ! empty( $params ) && ! $this->bind_mysql_params( $stmt, $params ) ) {
			mysqli_stmt_close( $stmt );
			mysqli_close( $connection );
			return null;
		}

		mysqli_stmt_execute( $stmt );
		$result = mysqli_stmt_get_result( $stmt );
		$row    = false !== $result ? mysqli_fetch_assoc( $result ) : null;
		mysqli_stmt_close( $stmt );
		mysqli_close( $connection );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Bind positional params for one mysqli statement.
	 *
	 * mysqli requires references for the variadic values, so the bootstrap cannot
	 * rely on a simple splat here even though the queries are tiny.
	 *
	 * @param \mysqli_stmt $stmt Prepared statement.
	 * @param array<int, mixed> $params Positional params.
	 * @return bool
	 */
	private function bind_mysql_params( \mysqli_stmt $stmt, array $params ): bool {
		$types = str_repeat( 's', count( $params ) );
		$args  = array( $stmt, $types );

		foreach ( array_values( $params ) as $index => $value ) {
			$params[ $index ] = is_scalar( $value ) || null === $value ? (string) $value : '';
			$args[]           = &$params[ $index ];
		}

		return (bool) call_user_func_array( 'mysqli_stmt_bind_param', $args );
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

		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return array();
		}

		$config = array();
		foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_DIR', 'DB_FILE', 'DB_ENGINE', 'DATABASE_TYPE' ) as $constant ) {
			if ( preg_match( "/define\\(\\s*['\\\"]" . preg_quote( $constant, '/' ) . "['\\\"]\\s*,\\s*['\\\"]([^'\\\"]+)['\\\"]\\s*\\)/", $contents, $match ) ) {
				$config[ $constant ] = $match[1];
			}
		}

		return $config;
	}

	/**
	 * Resolve the WordPress base prefix.
	 *
	 * @param string|null         $config_path Config path.
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
			$contents = file_get_contents( $config_path );
			if ( false !== $contents && preg_match( "/\\$table_prefix\\s*=\\s*['\\\"]([^'\\\"]+)['\\\"]\\s*;/", $contents, $match ) ) {
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
