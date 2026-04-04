<?php
/**
 * MySQL cloner: copies host MySQL tables with a new prefix for sandbox isolation.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Clones MySQL tables within the same database using a sandbox-specific prefix.
 */
class MySQLCloner {

	/**
	 * Default number of rows to process per chunk.
	 *
	 * @var int
	 */
	private const DEFAULT_CHUNK_SIZE = 500;

	/**
	 * Clone host MySQL tables to a new prefix within the same database.
	 *
	 * @param string $target_prefix Table prefix for the sandbox.
	 * @param string $sandbox_url   Full URL for the sandbox (for URL rewriting).
	 * @param array  $options       Optional settings: 'chunk_size' => int.
	 * @return array{tables_cloned: int, rows_cloned: int, is_multisite: bool} Clone statistics.
	 *
	 * @throws \RuntimeException If cloning fails.
	 */
	public function clone_database(
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

		$total_rows  = 0;
		$table_count = 0;

		foreach ( $tables as $table ) {
			$target_table = $target_prefix . substr( $table, strlen( $source_prefix ) );

			$this->clone_table( $wpdb, $table, $target_table );
			$rows        = $this->count_rows( $wpdb, $target_table );
			$total_rows += $rows;
			++$table_count;
		}

		$this->rewrite_urls( $wpdb, $target_prefix, $host_url, $sandbox_url );
		$this->rewrite_table_prefix_in_data( $wpdb, $target_prefix, $source_prefix, $target_prefix );

		$is_multisite = $this->table_exists_mysql( $wpdb, "{$target_prefix}blogs" );

		return array(
			'tables_cloned' => $table_count,
			'rows_cloned'   => $total_rows,
			'is_multisite'  => $is_multisite,
		);
	}

	/**
	 * Clone a single table (structure + data) within MySQL.
	 *
	 * @param \wpdb  $wpdb         WordPress database object.
	 * @param string $source_table Source table name.
	 * @param string $target_table Target table name.
	 * @return void
	 */
	private function clone_table( $wpdb, string $source_table, string $target_table ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names from validated SHOW TABLES results.
		$wpdb->query( "CREATE TABLE `{$target_table}` LIKE `{$source_table}`" );
		$wpdb->query( "INSERT INTO `{$target_table}` SELECT * FROM `{$source_table}`" );
		// phpcs:enable
	}

	/**
	 * Count rows in a table.
	 *
	 * @param \wpdb  $wpdb WordPress database object.
	 * @param string $table Table name.
	 * @return int Row count.
	 */
	private function count_rows( $wpdb, string $table ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table name from validated SHOW TABLES results.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	/**
	 * Drop all tables with the given prefix.
	 *
	 * @param string   $prefix           Table prefix to match. Must start with 'rudel_'.
	 * @param string[] $exclude_prefixes Optional table prefixes to preserve.
	 * @return int Number of tables dropped.
	 *
	 * @throws \RuntimeException If the prefix does not start with 'rudel_'.
	 */
	public function drop_tables( string $prefix, array $exclude_prefixes = array() ): int {
		// Safety: never drop tables that don't start with 'rudel_' or 'rudel_backup_'.
		if ( ! str_starts_with( $prefix, 'rudel_' ) ) {
			throw new \RuntimeException(
				sprintf( 'Refusing to drop tables with prefix "%s": only rudel_* prefixes are allowed.', $prefix )
			);
		}

		global $wpdb;

		$tables = $this->discover_tables( $wpdb, $prefix, $exclude_prefixes );
		$count  = 0;

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names from validated SHOW TABLES results.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			++$count;
		}

		return $count;
	}

	/**
	 * Copy tables from one prefix to another within MySQL.
	 *
	 * @param string   $source_prefix    Source table prefix.
	 * @param string   $target_prefix    Target table prefix.
	 * @param string[] $exclude_prefixes Optional source table prefixes to exclude.
	 * @return int Number of tables copied.
	 */
	public function copy_tables( string $source_prefix, string $target_prefix, array $exclude_prefixes = array() ): int {
		global $wpdb;

		$tables = $this->discover_tables( $wpdb, $source_prefix, $exclude_prefixes );
		$count  = 0;

		foreach ( $tables as $table ) {
			$target_table = $target_prefix . substr( $table, strlen( $source_prefix ) );
			$this->clone_table( $wpdb, $table, $target_table );
			++$count;
		}

		return $count;
	}

	/**
	 * Discover all tables with the given prefix.
	 *
	 * @param \wpdb    $wpdb             WordPress database object.
	 * @param string   $prefix           Table prefix to match.
	 * @param string[] $exclude_prefixes Optional table prefixes to exclude from the result.
	 * @return string[] Array of table names.
	 */
	public function discover_tables( $wpdb, string $prefix, array $exclude_prefixes = array() ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time metadata query.
		$results = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' )
		);

		if ( ! is_array( $results ) ) {
			return array();
		}

		if ( empty( $exclude_prefixes ) ) {
			return $results;
		}

		return array_values(
			array_filter(
				$results,
				static function ( string $table ) use ( $exclude_prefixes ): bool {
					foreach ( $exclude_prefixes as $exclude_prefix ) {
						if ( '' !== $exclude_prefix && str_starts_with( $table, $exclude_prefix ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Rewrite URLs in cloned MySQL tables.
	 *
	 * @param \wpdb  $wpdb        WordPress database object.
	 * @param string $prefix      Table prefix.
	 * @param string $host_url    Host URL to search for.
	 * @param string $sandbox_url Sandbox URL to replace with.
	 * @return void
	 */
	public function rewrite_urls( $wpdb, string $prefix, string $host_url, string $sandbox_url ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table/column names from validated SHOW TABLES; all values go through wpdb::prepare.
		$host_url    = rtrim( $host_url, '/' );
		$sandbox_url = rtrim( $sandbox_url, '/' );

		if ( $host_url === $sandbox_url ) {
			return;
		}

		$simple_updates = array(
			array( "{$prefix}posts", 'guid' ),
		);

		foreach ( $simple_updates as list( $table, $column ) ) {
			if ( ! $this->table_exists_mysql( $wpdb, $table ) ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, %s, %s) WHERE `{$column}` LIKE %s",
					$host_url,
					$sandbox_url,
					'%' . $wpdb->esc_like( $host_url ) . '%'
				)
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
			if ( ! $this->table_exists_mysql( $wpdb, $table ) ) {
				continue;
			}
			$this->rewrite_urls_in_column( $wpdb, $table, $column, $pk, $host_url, $sandbox_url );
		}

		if ( $this->table_exists_mysql( $wpdb, "{$prefix}options" ) ) {
			// The environment entrypoint must always land on the sandbox URL even if the generic search/replace misses these canonical options.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$prefix}options` SET `option_value` = %s WHERE `option_name` IN ('siteurl', 'home')",
					$sandbox_url
				)
			);
		}

		// Multisite stores most site content in per-blog tables, so the same rewrite pass has to run for every discovered blog.
		$blog_ids = $this->discover_blog_ids( $wpdb, $prefix );
		foreach ( $blog_ids as $blog_id ) {
			$blog_simple = array(
				array( "{$prefix}{$blog_id}_posts", 'guid' ),
			);
			foreach ( $blog_simple as list( $table, $column ) ) {
				if ( ! $this->table_exists_mysql( $wpdb, $table ) ) {
					continue;
				}
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, %s, %s) WHERE `{$column}` LIKE %s",
						$host_url,
						$sandbox_url,
						'%' . $wpdb->esc_like( $host_url ) . '%'
					)
				);
			}

			$blog_serialized = array(
				array( "{$prefix}{$blog_id}_options", 'option_value', 'option_id' ),
				array( "{$prefix}{$blog_id}_postmeta", 'meta_value', 'meta_id' ),
				array( "{$prefix}{$blog_id}_posts", 'post_content', 'ID' ),
				array( "{$prefix}{$blog_id}_comments", 'comment_content', 'comment_ID' ),
			);
			foreach ( $blog_serialized as list( $table, $column, $pk ) ) {
				if ( ! $this->table_exists_mysql( $wpdb, $table ) ) {
					continue;
				}
				$this->rewrite_urls_in_column( $wpdb, $table, $column, $pk, $host_url, $sandbox_url );
			}
		}

		if ( $this->table_exists_mysql( $wpdb, "{$prefix}sitemeta" ) ) {
			$this->rewrite_urls_in_column( $wpdb, "{$prefix}sitemeta", 'meta_value', 'meta_id', $host_url, $sandbox_url );
		}

		// Multisite also stores site paths separately from full URLs, so URL replacement alone is not enough.
		if ( $this->table_exists_mysql( $wpdb, "{$prefix}blogs" ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-testable without WordPress.
			$host_parsed = parse_url( $host_url, PHP_URL_PATH );
			$host_path   = rtrim( $host_parsed ? $host_parsed : '/', '/' ) . '/';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Unit-testable without WordPress.
			$sbx_parsed   = parse_url( $sandbox_url, PHP_URL_PATH );
			$sandbox_path = rtrim( $sbx_parsed ? $sbx_parsed : '/', '/' ) . '/';

			if ( $host_path !== $sandbox_path ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$prefix}blogs` SET `path` = CONCAT(%s, SUBSTRING(`path`, LENGTH(%s) + 1)) WHERE `path` LIKE %s",
						$sandbox_path,
						$host_path,
						$wpdb->esc_like( $host_path ) . '%'
					)
				);
			}
		}
	}
	// phpcs:enable

	/**
	 * Rewrite table prefix references embedded in data values.
	 *
	 * @param \wpdb  $wpdb          WordPress database object.
	 * @param string $prefix        Current table prefix (used for table names).
	 * @param string $source_prefix The source (host) prefix to find.
	 * @param string $target_prefix The target (sandbox) prefix to replace with.
	 * @return void
	 */
	public function rewrite_table_prefix_in_data( $wpdb, string $prefix, string $source_prefix, string $target_prefix ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names from validated SHOW TABLES.
		if ( $source_prefix === $target_prefix ) {
			return;
		}

		// WordPress bakes table prefixes into role and capability keys, so renaming tables alone leaves stale references behind.
		if ( $this->table_exists_mysql( $wpdb, "{$prefix}usermeta" ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$prefix}usermeta` SET `meta_key` = REPLACE(`meta_key`, %s, %s) WHERE `meta_key` LIKE %s",
					$source_prefix,
					$target_prefix,
					$wpdb->esc_like( $source_prefix ) . '%'
				)
			);
		}

		if ( $this->table_exists_mysql( $wpdb, "{$prefix}options" ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$prefix}options` SET `option_name` = REPLACE(`option_name`, %s, %s) WHERE `option_name` LIKE %s",
					$source_prefix,
					$target_prefix,
					$wpdb->esc_like( $source_prefix ) . '%'
				)
			);
		}

		$blog_ids = $this->discover_blog_ids( $wpdb, $prefix );
		foreach ( $blog_ids as $blog_id ) {
			$blog_options = "{$prefix}{$blog_id}_options";
			if ( $this->table_exists_mysql( $wpdb, $blog_options ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$blog_options}` SET `option_name` = REPLACE(`option_name`, %s, %s) WHERE `option_name` LIKE %s",
						$source_prefix,
						$target_prefix,
						$wpdb->esc_like( $source_prefix ) . '%'
					)
				);
			}
		}
	}
	// phpcs:enable

	/**
	 * Discover per-blog table IDs from MySQL.
	 *
	 * @param \wpdb  $wpdb   WordPress database object.
	 * @param string $prefix Table prefix.
	 * @return int[] Array of blog ID integers.
	 */
	private function discover_blog_ids( $wpdb, string $prefix ): array {
		$tables  = $this->discover_tables( $wpdb, $prefix );
		$ids     = array();
		$escaped = preg_quote( $prefix, '/' );

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
	 * @param \wpdb  $wpdb        WordPress database object.
	 * @param string $table       Table name.
	 * @param string $column      Column name.
	 * @param string $pk          Primary key column name.
	 * @param string $host_url    Host URL to search for.
	 * @param string $sandbox_url Sandbox URL to replace with.
	 * @return void
	 */
	private function rewrite_urls_in_column(
		$wpdb,
		string $table,
		string $column,
		string $pk,
		string $host_url,
		string $sandbox_url
	): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table/column names from validated SHOW TABLES.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `{$pk}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE %s",
				'%' . $wpdb->esc_like( $host_url ) . '%'
			),
			\ARRAY_A
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$value     = $row[ $column ];
			$new_value = $this->search_replace_value( $value, $host_url, $sandbox_url );

			if ( $new_value !== $value ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$table}` SET `{$column}` = %s WHERE `{$pk}` = %s",
						$new_value,
						$row[ $pk ]
					)
				);
			}
		// phpcs:enable
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
	 * Check if a table exists in MySQL.
	 *
	 * @param \wpdb  $wpdb  WordPress database object.
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists_mysql( $wpdb, string $table ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time metadata query.
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
		return null !== $result;
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
}
