<?php
/**
 * Database cloner: copies host MySQL database into sandbox SQLite.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Clones the host WordPress MySQL database into a sandbox SQLite database
 * using the bundled WP_SQLite_Translator for DDL and DML translation.
 */
class DatabaseCloner {

	/**
	 * Default number of rows to process per chunk.
	 *
	 * @var int
	 */
	private const DEFAULT_CHUNK_SIZE = 500;

	/**
	 * Absolute path to the Rudel plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $plugin_dir Optional override for the plugin directory path.
	 */
	public function __construct( ?string $plugin_dir = null ) {
		$this->plugin_dir = $plugin_dir ?? ( defined( 'RUDEL_PLUGIN_DIR' ) ? RUDEL_PLUGIN_DIR : dirname( __DIR__ ) . '/' );
	}

	/**
	 * Clone the host MySQL database into a sandbox SQLite database.
	 *
	 * @param string $sqlite_db_path Absolute path to the target SQLite database file.
	 * @param string $target_prefix  Table prefix for the sandbox.
	 * @param string $sandbox_url    Full URL for the sandbox (for URL rewriting).
	 * @param array  $options        Optional settings: 'chunk_size' => int.
	 * @return array{tables_cloned: int, rows_cloned: int, is_multisite: bool} Clone statistics.
	 *
	 * @throws \RuntimeException If cloning fails.
	 */
	public function clone_database(
		string $sqlite_db_path,
		string $target_prefix,
		string $sandbox_url,
		array $options = array()
	): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			throw new \RuntimeException( 'Global $wpdb is not available. Database cloning requires a running WordPress environment.' );
		}

		$chunk_size    = $options['chunk_size'] ?? self::DEFAULT_CHUNK_SIZE;
		$source_prefix = $wpdb->prefix;
		$host_url      = $this->get_host_url();
		$tables        = $this->discover_tables( $wpdb, $source_prefix );

		if ( empty( $tables ) ) {
			throw new \RuntimeException( 'No tables found with prefix: ' . $source_prefix );
		}

		$this->load_translator_classes();

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database creation requires PDO; $wpdb is MySQL-only.
		$pdo = new \PDO( 'sqlite:' . $sqlite_db_path );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$translator  = $this->create_translator( $pdo );
		$total_rows  = 0;
		$table_count = 0;

		foreach ( $tables as $table ) {
			$target_table = $this->rename_prefix( $table, $source_prefix, $target_prefix );

			$this->clone_table_structure( $wpdb, $translator, $table, $source_prefix, $target_prefix );
			$rows        = $this->clone_table_data( $wpdb, $translator, $table, $target_table, $source_prefix, $target_prefix, $chunk_size );
			$total_rows += $rows;
			++$table_count;
		}

		$this->rewrite_urls( $pdo, $target_prefix, $host_url, $sandbox_url );
		$this->rewrite_table_prefix_in_data( $pdo, $target_prefix, $source_prefix, $target_prefix );

		$is_multisite = $this->table_exists( $pdo, "{$target_prefix}blogs" );

		$pdo = null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting permissions on generated database file.
		chmod( $sqlite_db_path, 0664 );

		return array(
			'tables_cloned' => $table_count,
			'rows_cloned'   => $total_rows,
			'is_multisite'  => $is_multisite,
		);
	}

	/**
	 * Discover all tables with the given prefix.
	 *
	 * @param \wpdb  $wpdb   WordPress database object.
	 * @param string $prefix Table prefix to match.
	 * @return string[] Array of table names.
	 */
	public function discover_tables( $wpdb, string $prefix ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time metadata query.
		$results = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Clone a single table's structure from MySQL to SQLite.
	 *
	 * @param \wpdb  $wpdb          WordPress database object.
	 * @param object $translator    WP_SQLite_Translator instance.
	 * @param string $table         Source table name.
	 * @param string $source_prefix Source table prefix.
	 * @param string $target_prefix Target table prefix.
	 * @return void
	 *
	 * @throws \RuntimeException If the CREATE TABLE DDL cannot be retrieved.
	 */
	public function clone_table_structure( $wpdb, $translator, string $table, string $source_prefix, string $target_prefix ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection on validated table name from SHOW TABLES.
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", \ARRAY_N );
		if ( ! $row || empty( $row[1] ) ) {
			throw new \RuntimeException( 'Failed to get CREATE TABLE for: ' . $table );
		}

		$ddl = $row[1];
		$ddl = $this->rename_prefix_in_ddl( $ddl, $table, $source_prefix, $target_prefix );

		$translator->query( $ddl );
	}

	/**
	 * Clone a single table's data from MySQL to SQLite.
	 *
	 * @param \wpdb  $wpdb          WordPress database object.
	 * @param object $translator    WP_SQLite_Translator instance.
	 * @param string $source_table  Source table name.
	 * @param string $target_table  Target table name.
	 * @param string $source_prefix Source table prefix.
	 * @param string $target_prefix Target table prefix.
	 * @param int    $chunk_size    Number of rows per chunk.
	 * @return int Total number of rows cloned.
	 */
	public function clone_table_data( $wpdb, $translator, string $source_table, string $target_table, string $source_prefix, string $target_prefix, int $chunk_size = 500 ): int {
		$offset     = 0;
		$total_rows = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic table/column names from validated SHOW TABLES results; all values go through wpdb::prepare.
		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$source_table}` LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				),
				\ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$columns      = array_keys( $row );
				$placeholders = implode( ', ', array_fill( 0, count( $columns ), '%s' ) );
				$col_list     = '`' . implode( '`, `', $columns ) . '`';

				$insert = $wpdb->prepare(
					"INSERT INTO `{$target_table}` ({$col_list}) VALUES ({$placeholders})",
					...array_values( $row )
				);

				$translator->query( $insert );
			}

			$total_rows += count( $rows );
			$offset     += $chunk_size;

			if ( count( $rows ) < $chunk_size ) {
				break;
			}
		}
		// phpcs:enable

		return $total_rows;
	}

	/**
	 * Rename the table prefix in a CREATE TABLE DDL statement.
	 *
	 * @param string $ddl           The CREATE TABLE SQL.
	 * @param string $table         The original table name.
	 * @param string $source_prefix Source table prefix.
	 * @param string $target_prefix Target table prefix.
	 * @return string Modified DDL.
	 */
	public function rename_prefix_in_ddl( string $ddl, string $table, string $source_prefix, string $target_prefix ): string {
		$target_table = $this->rename_prefix( $table, $source_prefix, $target_prefix );
		return str_replace( "`{$table}`", "`{$target_table}`", $ddl );
	}

	/**
	 * Rename the table prefix in a table name.
	 *
	 * @param string $table         Table name.
	 * @param string $source_prefix Source prefix.
	 * @param string $target_prefix Target prefix.
	 * @return string Renamed table.
	 */
	public function rename_prefix( string $table, string $source_prefix, string $target_prefix ): string {
		if ( str_starts_with( $table, $source_prefix ) ) {
			return $target_prefix . substr( $table, strlen( $source_prefix ) );
		}
		return $table;
	}

	/**
	 * Rewrite URLs in cloned data to point to the sandbox.
	 *
	 * @param \PDO   $pdo           SQLite PDO connection.
	 * @param string $prefix        Table prefix.
	 * @param string $host_url      Host URL to search for.
	 * @param string $sandbox_url   Sandbox URL to replace with.
	 * @return void
	 */
	public function rewrite_urls( \PDO $pdo, string $prefix, string $host_url, string $sandbox_url ): void {
		$host_url    = rtrim( $host_url, '/' );
		$sandbox_url = rtrim( $sandbox_url, '/' );

		if ( $host_url === $sandbox_url ) {
			return;
		}

		$simple_updates = array(
			array( "{$prefix}posts", 'guid' ),
		);

		foreach ( $simple_updates as list( $table, $column ) ) {
			if ( ! $this->table_exists( $pdo, $table ) ) {
				continue;
			}
			$this->sqlite_exec(
				$pdo,
				"UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, ?, ?) WHERE `{$column}` LIKE ?",
				array( $host_url, $sandbox_url, '%' . $host_url . '%' )
			);
		}

		$serialized_updates = array(
			array( "{$prefix}options", 'option_value', 'option_id' ),
			array( "{$prefix}postmeta", 'meta_value', 'meta_id' ),
			array( "{$prefix}usermeta", 'meta_value', 'umeta_id' ),
			array( "{$prefix}posts", 'post_content', 'ID' ),
			array( "{$prefix}comments", 'comment_content', 'comment_ID' ),
		);

		foreach ( $serialized_updates as list( $table, $column, $pk ) ) {
			if ( ! $this->table_exists( $pdo, $table ) ) {
				continue;
			}
			$this->rewrite_urls_in_column( $pdo, $table, $column, $pk, $host_url, $sandbox_url );
		}

		// Multisite stores most site content in per-blog tables, so the same rewrite pass has to run for every discovered blog.
		$blog_ids = $this->discover_blog_ids( $pdo, $prefix );
		foreach ( $blog_ids as $blog_id ) {
			$blog_simple = array(
				array( "{$prefix}{$blog_id}_posts", 'guid' ),
			);
			foreach ( $blog_simple as list( $table, $column ) ) {
				if ( ! $this->table_exists( $pdo, $table ) ) {
					continue;
				}
				$this->sqlite_exec(
					$pdo,
					"UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, ?, ?) WHERE `{$column}` LIKE ?",
					array( $host_url, $sandbox_url, '%' . $host_url . '%' )
				);
			}

			$blog_serialized = array(
				array( "{$prefix}{$blog_id}_options", 'option_value', 'option_id' ),
				array( "{$prefix}{$blog_id}_postmeta", 'meta_value', 'meta_id' ),
				array( "{$prefix}{$blog_id}_posts", 'post_content', 'ID' ),
				array( "{$prefix}{$blog_id}_comments", 'comment_content', 'comment_ID' ),
			);
			foreach ( $blog_serialized as list( $table, $column, $pk ) ) {
				if ( ! $this->table_exists( $pdo, $table ) ) {
					continue;
				}
				$this->rewrite_urls_in_column( $pdo, $table, $column, $pk, $host_url, $sandbox_url );
			}
		}

		if ( $this->table_exists( $pdo, "{$prefix}sitemeta" ) ) {
			$this->rewrite_urls_in_column( $pdo, "{$prefix}sitemeta", 'meta_value', 'meta_id', $host_url, $sandbox_url );
		}

		// Multisite also stores site paths separately from full URLs, so URL replacement alone is not enough.
		if ( $this->table_exists( $pdo, "{$prefix}blogs" ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-testable without WordPress.
			$host_parsed = parse_url( $host_url, PHP_URL_PATH );
			$host_path   = rtrim( $host_parsed ? $host_parsed : '/', '/' ) . '/';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-testable without WordPress.
			$sbx_parsed   = parse_url( $sandbox_url, PHP_URL_PATH );
			$sandbox_path = rtrim( $sbx_parsed ? $sbx_parsed : '/', '/' ) . '/';

			if ( $host_path !== $sandbox_path ) {
				$this->sqlite_exec(
					$pdo,
					"UPDATE `{$prefix}blogs` SET `path` = ? || SUBSTR(`path`, LENGTH(?) + 1) WHERE `path` LIKE ?",
					array( $sandbox_path, $host_path, $host_path . '%' )
				);
			}
		}
	}

	/**
	 * Rewrite table prefix references embedded in data values.
	 *
	 * WordPress embeds the table prefix in meta_key names and option_names.
	 *
	 * @param \PDO   $pdo           SQLite PDO connection.
	 * @param string $prefix        Current table prefix (used for table names).
	 * @param string $source_prefix The source (host) prefix to find.
	 * @param string $target_prefix The target (sandbox) prefix to replace with.
	 * @return void
	 */
	public function rewrite_table_prefix_in_data( \PDO $pdo, string $prefix, string $source_prefix, string $target_prefix ): void {
		if ( $source_prefix === $target_prefix ) {
			return;
		}

		// WordPress bakes table prefixes into role and capability keys, so renaming tables alone leaves stale references behind.
		if ( $this->table_exists( $pdo, "{$prefix}usermeta" ) ) {
			$this->sqlite_exec(
				$pdo,
				"UPDATE `{$prefix}usermeta` SET `meta_key` = REPLACE(`meta_key`, ?, ?) WHERE `meta_key` LIKE ?",
				array( $source_prefix, $target_prefix, $source_prefix . '%' )
			);
		}

		if ( $this->table_exists( $pdo, "{$prefix}options" ) ) {
			$this->sqlite_exec(
				$pdo,
				"UPDATE `{$prefix}options` SET `option_name` = REPLACE(`option_name`, ?, ?) WHERE `option_name` LIKE ?",
				array( $source_prefix, $target_prefix, $source_prefix . '%' )
			);
		}

		$blog_ids = $this->discover_blog_ids( $pdo, $prefix );
		foreach ( $blog_ids as $blog_id ) {
			$blog_options = "{$prefix}{$blog_id}_options";
			if ( $this->table_exists( $pdo, $blog_options ) ) {
				$this->sqlite_exec(
					$pdo,
					"UPDATE `{$blog_options}` SET `option_name` = REPLACE(`option_name`, ?, ?) WHERE `option_name` LIKE ?",
					array( $source_prefix, $target_prefix, $source_prefix . '%' )
				);
			}
		}
	}

	/**
	 * Discover per-blog table IDs from the SQLite database.
	 *
	 * Scans sqlite_master for tables matching {prefix}N_ where N is a numeric blog ID.
	 *
	 * @param \PDO   $pdo    SQLite PDO connection.
	 * @param string $prefix Table prefix.
	 * @return int[] Array of blog ID integers.
	 */
	private function discover_blog_ids( \PDO $pdo, string $prefix ): array {
		$escaped = preg_quote( $prefix, '/' );
		$stmt    = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table'" );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO fetch.
		$tables = $stmt->fetchAll( \PDO::FETCH_COLUMN );
		$ids    = array();

		foreach ( $tables as $table ) {
			if ( preg_match( '/^' . $escaped . '(\d+)_/', $table, $m ) ) {
				$ids[ (int) $m[1] ] = true;
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Rewrite URLs in a single column, handling serialized data.
	 *
	 * @param \PDO   $pdo         SQLite PDO connection. phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Operating on SQLite via PDO.
	 * @param string $table       Table name.
	 * @param string $column      Column name.
	 * @param string $pk          Primary key column name.
	 * @param string $host_url    Host URL to search for.
	 * @param string $sandbox_url Sandbox URL to replace with.
	 * @return void
	 */
	private function rewrite_urls_in_column( // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- Operating on SQLite database via PDO; not MySQL access.
		\PDO $pdo,
		string $table,
		string $column,
		string $pk,
		string $host_url,
		string $sandbox_url
	): void {
		$stmt = $pdo->prepare(
			"SELECT `{$pk}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE ?"
		);
		$stmt->execute( array( '%' . $host_url . '%' ) );
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO fetch mode constant.
		$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

		foreach ( $rows as $row ) {
			$value     = $row[ $column ];
			$new_value = $this->search_replace_value( $value, $host_url, $sandbox_url );

			if ( $new_value !== $value ) {
				$this->sqlite_exec(
					$pdo,
					"UPDATE `{$table}` SET `{$column}` = ? WHERE `{$pk}` = ?",
					array( $new_value, $row[ $pk ] )
				);
			}
		}
	}

	/**
	 * Search and replace in a value, handling serialized data.
	 *
	 * @param string $value   The value to process.
	 * @param string $search  The string to search for.
	 * @param string $replace The replacement string.
	 * @return string The processed value.
	 */
	public function search_replace_value( string $value, string $search, string $replace ): string {
		return SerializedSearchReplace::apply( $value, $search, $replace );
	}

	/**
	 * Get the host WordPress URL.
	 *
	 * @return string Host URL without trailing slash.
	 */
	private function get_host_url(): string {
		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' );
		}
		if ( function_exists( 'home_url' ) ) {
			return rtrim( home_url(), '/' );
		}
		return 'http://localhost';
	}

	/**
	 * Load the WP_SQLite_Translator classes.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the translator files are not found.
	 */
	private function load_translator_classes(): void {
		$base = $this->plugin_dir . 'lib/sqlite-database-integration/wp-includes/sqlite';

		$files = array(
			$base . '/class-wp-sqlite-token.php',
			$base . '/class-wp-sqlite-pdo-user-defined-functions.php',
			$base . '/class-wp-sqlite-lexer.php',
			$base . '/class-wp-sqlite-query-rewriter.php',
			$base . '/class-wp-sqlite-translator.php',
		);

		foreach ( $files as $file ) {
			if ( ! file_exists( $file ) ) {
				throw new \RuntimeException( 'SQLite translator file not found: ' . $file );
			}
			require_once $file;
		}
	}

	/**
	 * Create a WP_SQLite_Translator instance for the given PDO connection.
	 *
	 * @param \PDO $pdo SQLite PDO connection.
	 * @return object WP_SQLite_Translator instance.
	 */
	private function create_translator( \PDO $pdo ): object {
		return new \WP_SQLite_Translator( $pdo );
	}

	/**
	 * Check if a table exists in the SQLite database.
	 *
	 * @param \PDO   $pdo   SQLite PDO connection.
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( \PDO $pdo, string $table ): bool {
		$stmt = $pdo->prepare( "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?" );
		$stmt->execute( array( $table ) );
		return (int) $stmt->fetchColumn() > 0;
	}

	/**
	 * Execute a prepared statement on the SQLite PDO connection.
	 *
	 * @param \PDO   $pdo    SQLite PDO connection.
	 * @param string $sql    SQL statement with placeholders.
	 * @param array  $params Bound parameters.
	 * @return void
	 */
	private function sqlite_exec( \PDO $pdo, string $sql, array $params = array() ): void {
		$stmt = $pdo->prepare( $sql );
		$stmt->execute( $params );
	}
}
