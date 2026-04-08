<?php
/**
 * Rudel schema management.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Ensures the runtime tables exist.
 */
class RudelSchema {

	/**
	 * Stores already initialized in this process.
	 *
	 * @var array<string, bool>
	 */
	private static array $ensured = array();

	/**
	 * Ensure the Rudel schema exists for the active store.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return void
	 */
	public static function ensure( DatabaseStore $store ): void {
		$key = $store->cache_key();
		if ( isset( self::$ensured[ $key ] ) ) {
			return;
		}

		foreach ( self::mysql_statements( $store ) as $sql ) {
			$store->execute( $sql );
		}

		self::upgrade_existing_tables( $store );

		self::$ensured[ $key ] = true;
	}

	/**
	 * Reset the per-process ensure cache.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ensured = array();
	}

	/**
	 * MySQL schema.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return array<int, string>
	 */
	private static function mysql_statements( DatabaseStore $store ): array {
		return array(
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'environments' ) . ' (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				app_id BIGINT UNSIGNED NULL,
				slug VARCHAR(64) NOT NULL,
				name VARCHAR(191) NOT NULL,
				path VARCHAR(255) NOT NULL,
				type VARCHAR(20) NOT NULL,
				engine VARCHAR(20) NOT NULL,
				template VARCHAR(64) NOT NULL,
				status VARCHAR(32) NOT NULL,
				multisite TINYINT(1) NOT NULL DEFAULT 0,
				blog_id BIGINT UNSIGNED NULL,
				clone_source LONGTEXT NULL,
				owner VARCHAR(191) NULL,
				labels LONGTEXT NULL,
				purpose LONGTEXT NULL,
				is_protected TINYINT(1) NOT NULL DEFAULT 0,
				expires_at VARCHAR(32) NULL,
				last_used_at VARCHAR(32) NULL,
				source_environment_slug VARCHAR(64) NULL,
				source_environment_type VARCHAR(20) NULL,
				last_deployed_from_slug VARCHAR(64) NULL,
				last_deployed_from_type VARCHAR(20) NULL,
				last_deployed_at VARCHAR(32) NULL,
				tracked_git_remote VARCHAR(191) NULL,
				tracked_git_branch VARCHAR(191) NULL,
				tracked_git_dir VARCHAR(191) NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_env_slug (slug),
				UNIQUE KEY rudel_env_path (path),
				KEY rudel_env_type (type),
				KEY rudel_env_status (status),
				KEY rudel_env_app (app_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'apps' ) . ' (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment_id BIGINT UNSIGNED NOT NULL,
				slug VARCHAR(64) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_environment (environment_id),
				UNIQUE KEY rudel_app_slug (slug)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_domains' ) . ' (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				app_id BIGINT UNSIGNED NOT NULL,
				domain VARCHAR(191) NOT NULL,
				is_primary TINYINT(1) NOT NULL DEFAULT 0,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_domain (domain),
				KEY rudel_app_domain_app (app_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'worktrees' ) . ' (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment_id BIGINT UNSIGNED NOT NULL,
				content_type VARCHAR(32) NOT NULL,
				name VARCHAR(191) NOT NULL,
				metadata_name VARCHAR(191) NULL,
				branch VARCHAR(191) NOT NULL,
				repo_path VARCHAR(255) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_worktree_environment_name (environment_id, content_type, name),
				KEY rudel_worktree_environment (environment_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_deployments' ) . ' (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				deployment_key VARCHAR(64) NOT NULL,
				app_id BIGINT UNSIGNED NOT NULL,
				environment_id BIGINT UNSIGNED NULL,
				app_slug VARCHAR(64) NOT NULL,
				app_name VARCHAR(191) NOT NULL,
				app_domains LONGTEXT NULL,
				sandbox_slug VARCHAR(64) NOT NULL,
				sandbox_name VARCHAR(191) NOT NULL,
				source_environment_type VARCHAR(20) NOT NULL,
				backup_name VARCHAR(191) NULL,
				tables_copied INT NULL,
				label VARCHAR(191) NULL,
				notes LONGTEXT NULL,
				git_remote VARCHAR(191) NULL,
				git_branch VARCHAR(191) NULL,
				git_base_branch VARCHAR(191) NULL,
				git_dir VARCHAR(191) NULL,
				deployed_at VARCHAR(32) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_deployment_key (deployment_key),
				KEY rudel_app_deployment_app (app_id),
				KEY rudel_app_deployment_environment (environment_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		);
	}

	/**
	 * Add newly introduced columns to existing installs and backfill safe defaults.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return void
	 */
	private static function upgrade_existing_tables( DatabaseStore $store ): void {
		$environments = $store->table( 'environments' );
		self::ensure_column( $store, $environments, 'tracked_git_remote', 'VARCHAR(191) NULL' );
		self::ensure_column( $store, $environments, 'tracked_git_branch', 'VARCHAR(191) NULL' );
		self::ensure_column( $store, $environments, 'tracked_git_dir', 'VARCHAR(191) NULL' );
		self::backfill_column( $store, $environments, 'tracked_git_remote', 'tracked_github_repo' );
		self::backfill_column( $store, $environments, 'tracked_git_branch', 'tracked_github_branch' );
		self::backfill_column( $store, $environments, 'tracked_git_dir', 'tracked_github_dir' );

		$worktrees = $store->table( 'worktrees' );
		self::ensure_column( $store, $worktrees, 'metadata_name', 'VARCHAR(191) NULL' );
		self::backfill_column( $store, $worktrees, 'metadata_name', 'name' );

		$deployments = $store->table( 'app_deployments' );
		self::ensure_column( $store, $deployments, 'git_remote', 'VARCHAR(191) NULL' );
		self::ensure_column( $store, $deployments, 'git_branch', 'VARCHAR(191) NULL' );
		self::ensure_column( $store, $deployments, 'git_base_branch', 'VARCHAR(191) NULL' );
		self::ensure_column( $store, $deployments, 'git_dir', 'VARCHAR(191) NULL' );
		self::backfill_column( $store, $deployments, 'git_remote', 'github_repo' );
		self::backfill_column( $store, $deployments, 'git_branch', 'github_branch' );
		self::backfill_column( $store, $deployments, 'git_base_branch', 'github_base_branch' );
		self::backfill_column( $store, $deployments, 'git_dir', 'github_dir' );
	}

	/**
	 * Add one column to an existing runtime table when it is still missing.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @param string        $table Full table name.
	 * @param string        $column Column name.
	 * @param string        $definition SQL column definition without the column name.
	 * @return void
	 */
	private static function ensure_column( DatabaseStore $store, string $table, string $column, string $definition ): void {
		if ( self::column_exists( $store, $table, $column ) ) {
			return;
		}

		$store->execute(
			'ALTER TABLE ' . self::quote_identifier( $table ) . ' ADD COLUMN ' . self::quote_identifier( $column ) . ' ' . $definition
		);
	}

	/**
	 * Copy old column values into the new target column when the target is still empty.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @param string        $table Full table name.
	 * @param string        $target New column name.
	 * @param string        $source Legacy column name.
	 * @return void
	 */
	private static function backfill_column( DatabaseStore $store, string $table, string $target, string $source ): void {
		if ( ! self::column_exists( $store, $table, $target ) || ! self::column_exists( $store, $table, $source ) ) {
			return;
		}

		$rows = $store->fetch_all(
			'SELECT id, ' . self::quote_identifier( $target ) . ', ' . self::quote_identifier( $source ) . ' FROM ' . self::quote_identifier( $table )
		);

		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id <= 0 ) {
				continue;
			}

			$target_value = $row[ $target ] ?? null;
			$source_value = $row[ $source ] ?? null;
			if ( null !== $target_value && '' !== trim( (string) $target_value ) ) {
				continue;
			}

			if ( null === $source_value || '' === trim( (string) $source_value ) ) {
				continue;
			}

			$store->update(
				$table,
				array( $target => (string) $source_value ),
				array( 'id' => $id )
			);
		}
	}

	/**
	 * Whether one runtime table already has the requested column.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @param string        $table Full table name.
	 * @param string        $column Column name.
	 * @return bool
	 */
	private static function column_exists( DatabaseStore $store, string $table, string $column ): bool {
		$row = $store->fetch_row(
			'SHOW COLUMNS FROM ' . self::quote_identifier( $table ) . ' LIKE ?',
			array( $column )
		);

		return is_array( $row ) && ! empty( $row );
	}

	/**
	 * Quote one SQL identifier.
	 *
	 * @param string $identifier Table or column name.
	 * @return string
	 */
	private static function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}
}
