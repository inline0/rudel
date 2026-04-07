<?php
/**
 * Environment user isolation workflows.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Provisions and copies per-environment user tables.
 */
class EnvironmentUserIsolationService {

	/**
	 * Derived isolated-user metadata for one multisite blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array{user_scope: string, users_table: string, usermeta_table: string}
	 */
	public function metadata_for_blog( int $blog_id ): array {
		return array(
			'user_scope'     => 'isolated',
			'users_table'    => Environment::users_table_for_blog( $blog_id ),
			'usermeta_table' => Environment::usermeta_table_for_blog( $blog_id ),
		);
	}

	/**
	 * Seed one new environment from the host network users.
	 *
	 * @param Environment $target Target environment.
	 * @return void
	 */
	public function clone_from_host( Environment $target ): void {
		if ( ! $target->uses_isolated_users() ) {
			return;
		}

		$this->copy_users_between_tables(
			$this->host_users_table(),
			$this->host_usermeta_table(),
			$this->host_blog_prefix(),
			(string) $target->get_users_table(),
			(string) $target->get_usermeta_table(),
			$target->get_table_prefix()
		);
	}

	/**
	 * Clone isolated users from one environment into another.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return void
	 */
	public function clone_from_environment( Environment $source, Environment $target ): void {
		if ( ! $target->uses_isolated_users() ) {
			return;
		}

		if ( ! $source->uses_isolated_users() ) {
			$this->clone_from_host( $target );
			return;
		}

		$source_users    = $source->get_users_table();
		$source_usermeta = $source->get_usermeta_table();

		if ( null === $source_users || null === $source_usermeta || ! $this->table_exists( $source_users ) || ! $this->table_exists( $source_usermeta ) ) {
			$this->clone_from_host( $target );
			return;
		}

		$this->copy_users_between_tables(
			$source_users,
			$source_usermeta,
			$source->get_table_prefix(),
			(string) $target->get_users_table(),
			(string) $target->get_usermeta_table(),
			$target->get_table_prefix()
		);
	}

	/**
	 * Replace target isolated users with the source environment users.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return void
	 */
	public function replace( Environment $source, Environment $target ): void {
		$this->clone_from_environment( $source, $target );
	}

	/**
	 * Copy one environment's isolated users into snapshot tables.
	 *
	 * @param Environment $environment Environment being snapshotted.
	 * @param string      $snapshot_prefix Prefix already used for the DB snapshot tables.
	 * @return array{users_table: string, usermeta_table: string}|array{}
	 */
	public function snapshot( Environment $environment, string $snapshot_prefix ): array {
		if ( ! $environment->uses_isolated_users() ) {
			return array();
		}

		$users_table    = $snapshot_prefix . 'users';
		$usermeta_table = $snapshot_prefix . 'usermeta';

		$this->copy_users_between_tables(
			(string) $environment->get_users_table(),
			(string) $environment->get_usermeta_table(),
			$environment->get_table_prefix(),
			$users_table,
			$usermeta_table,
			$environment->get_table_prefix()
		);

		return array(
			'users_table'    => $users_table,
			'usermeta_table' => $usermeta_table,
		);
	}

	/**
	 * Restore isolated users from one snapshot payload.
	 *
	 * @param Environment          $environment Target environment.
	 * @param array<string, mixed> $snapshot Snapshot metadata.
	 * @return void
	 */
	public function restore_snapshot( Environment $environment, array $snapshot ): void {
		if ( ! $environment->uses_isolated_users() ) {
			return;
		}

		$users_table    = isset( $snapshot['users_table'] ) && is_string( $snapshot['users_table'] ) ? $snapshot['users_table'] : null;
		$usermeta_table = isset( $snapshot['usermeta_table'] ) && is_string( $snapshot['usermeta_table'] ) ? $snapshot['usermeta_table'] : null;

		if ( null === $users_table || null === $usermeta_table || ! $this->table_exists( $users_table ) || ! $this->table_exists( $usermeta_table ) ) {
			return;
		}

		$this->copy_users_between_tables(
			$users_table,
			$usermeta_table,
			$environment->get_table_prefix(),
			(string) $environment->get_users_table(),
			(string) $environment->get_usermeta_table(),
			$environment->get_table_prefix()
		);
	}

	/**
	 * Drop isolated users for one environment.
	 *
	 * @param Environment $environment Environment being removed.
	 * @return void
	 */
	public function drop( Environment $environment ): void {
		if ( ! $environment->uses_isolated_users() ) {
			return;
		}

		$this->drop_tables(
			array(
				(string) $environment->get_users_table(),
				(string) $environment->get_usermeta_table(),
			)
		);
	}

	/**
	 * Drop one snapshot's temporary user tables.
	 *
	 * @param array<string, mixed> $snapshot Snapshot metadata.
	 * @return void
	 */
	public function drop_snapshot( array $snapshot ): void {
		$tables = array();

		if ( isset( $snapshot['users_table'] ) && is_string( $snapshot['users_table'] ) && '' !== $snapshot['users_table'] ) {
			$tables[] = $snapshot['users_table'];
		}

		if ( isset( $snapshot['usermeta_table'] ) && is_string( $snapshot['usermeta_table'] ) && '' !== $snapshot['usermeta_table'] ) {
			$tables[] = $snapshot['usermeta_table'];
		}

		$this->drop_tables( $tables );
	}

	/**
	 * Copy one users/usermeta pair into a new isolated target.
	 *
	 * @param string $source_users Source users table.
	 * @param string $source_usermeta Source usermeta table.
	 * @param string $source_blog_prefix Source blog table prefix.
	 * @param string $target_users Target users table.
	 * @param string $target_usermeta Target usermeta table.
	 * @param string $target_blog_prefix Target blog table prefix.
	 * @return void
	 */
	private function copy_users_between_tables(
		string $source_users,
		string $source_usermeta,
		string $source_blog_prefix,
		string $target_users,
		string $target_usermeta,
		string $target_blog_prefix
	): void {
		$this->copy_table( $source_users, $target_users, true );
		$this->copy_table( $source_usermeta, $target_usermeta, true );

		if ( $source_blog_prefix !== $target_blog_prefix ) {
			$this->rewrite_usermeta_blog_prefix( $target_usermeta, $source_blog_prefix, $target_blog_prefix );
		}
	}

	/**
	 * Copy one table by structure and data.
	 *
	 * @param string $source_table Source table.
	 * @param string $target_table Target table.
	 * @param bool   $replace_existing Whether the target should be dropped first.
	 * @return void
	 */
	private function copy_table( string $source_table, string $target_table, bool $replace_existing = false ): void {
		global $wpdb;

		$source_table = $this->validated_table_name( $source_table );
		$target_table = $this->validated_table_name( $target_table );

		if ( $replace_existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Derived Rudel table names are validated before interpolation.
			$wpdb->query( "DROP TABLE IF EXISTS `{$target_table}`" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Derived Rudel table names are validated before interpolation.
		$wpdb->query( "CREATE TABLE `{$target_table}` LIKE `{$source_table}`" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Derived Rudel table names are validated before interpolation.
		$wpdb->query( "INSERT INTO `{$target_table}` SELECT * FROM `{$source_table}`" );
	}

	/**
	 * Rewrite per-site capability keys inside one isolated usermeta table.
	 *
	 * @param string $table Usermeta table.
	 * @param string $source_blog_prefix Source blog prefix.
	 * @param string $target_blog_prefix Target blog prefix.
	 * @return void
	 */
	private function rewrite_usermeta_blog_prefix( string $table, string $source_blog_prefix, string $target_blog_prefix ): void {
		global $wpdb;

		$table              = $this->validated_table_name( $table );
		$source_blog_prefix = $this->validated_prefix( $source_blog_prefix );
		$target_blog_prefix = $this->validated_prefix( $target_blog_prefix );

		// WordPress scopes roles and site-specific user settings through meta_key
		// prefixes, so cloned isolated users have to adopt the target blog prefix.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Derived Rudel table names are validated before interpolation and value inputs still use $wpdb->prepare().
		$sql = "UPDATE `{$table}` SET `meta_key` = REPLACE(`meta_key`, %s, %s) WHERE `meta_key` LIKE %s";
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				$source_blog_prefix,
				$target_blog_prefix,
				$wpdb->esc_like( $source_blog_prefix ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Drop a set of validated tables.
	 *
	 * @param array<int, string> $tables Table names.
	 * @return void
	 */
	private function drop_tables( array $tables ): void {
		global $wpdb;

		foreach ( $tables as $table ) {
			if ( '' === $table ) {
				continue;
			}

			$table = $this->validated_table_name( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Derived Rudel table names are validated before interpolation.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Whether one validated table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		$table = $this->validated_table_name( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time metadata query.
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return null !== $result;
	}

	/**
	 * Host users table.
	 *
	 * @return string
	 */
	private function host_users_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'users';
	}

	/**
	 * Host usermeta table.
	 *
	 * @return string
	 */
	private function host_usermeta_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'usermeta';
	}

	/**
	 * Host multisite blog prefix Rudel clones from by default.
	 *
	 * @return string
	 */
	private function host_blog_prefix(): string {
		global $wpdb;

		return $wpdb->base_prefix;
	}

	/**
	 * Validate one derived SQL table name before interpolation.
	 *
	 * @param string $table Table name.
	 * @return string
	 * @throws \RuntimeException When the derived table name contains unsafe characters.
	 */
	private function validated_table_name( string $table ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			throw new \RuntimeException( sprintf( 'Invalid isolated users table name: %s', $table ) );
		}

		return $table;
	}

	/**
	 * Validate one table-prefix string before using it in metadata rewrites.
	 *
	 * @param string $prefix Table prefix.
	 * @return string
	 * @throws \RuntimeException When the derived prefix contains unsafe characters.
	 */
	private function validated_prefix( string $prefix ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new \RuntimeException( sprintf( 'Invalid isolated users table prefix: %s', $prefix ) );
		}

		return $prefix;
	}
}
