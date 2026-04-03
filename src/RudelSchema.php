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

		foreach ( self::statements( $store ) as $sql ) {
			$store->execute( $sql );
		}

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
	 * Schema statements for the active driver.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return array<int, string>
	 */
	private static function statements( DatabaseStore $store ): array {
		if ( 'sqlite' === $store->driver() ) {
			return self::sqlite_statements( $store );
		}

		return self::mysql_statements( $store );
	}

	/**
	 * MySQL schema.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return array<int, string>
	 */
	private static function mysql_statements( DatabaseStore $store ): array {
		return array(
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'environments' ) . " (
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
				tracked_github_repo VARCHAR(191) NULL,
				tracked_github_branch VARCHAR(191) NULL,
				tracked_github_dir VARCHAR(191) NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_env_slug (slug),
				UNIQUE KEY rudel_env_path (path),
				KEY rudel_env_type (type),
				KEY rudel_env_status (status),
				KEY rudel_env_app (app_id)
			)",
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'apps' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment_id BIGINT UNSIGNED NOT NULL,
				slug VARCHAR(64) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_environment (environment_id),
				UNIQUE KEY rudel_app_slug (slug)
			)",
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_domains' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				app_id BIGINT UNSIGNED NOT NULL,
				domain VARCHAR(191) NOT NULL,
				is_primary TINYINT(1) NOT NULL DEFAULT 0,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_domain (domain),
				KEY rudel_app_domain_app (app_id)
			)",
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'worktrees' ) . " (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				environment_id BIGINT UNSIGNED NOT NULL,
				content_type VARCHAR(32) NOT NULL,
				name VARCHAR(191) NOT NULL,
				branch VARCHAR(191) NOT NULL,
				repo_path VARCHAR(255) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_worktree_environment_name (environment_id, content_type, name),
				KEY rudel_worktree_environment (environment_id)
			)",
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_deployments' ) . " (
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
				github_repo VARCHAR(191) NULL,
				github_branch VARCHAR(191) NULL,
				github_base_branch VARCHAR(191) NULL,
				github_dir VARCHAR(191) NULL,
				deployed_at VARCHAR(32) NOT NULL,
				created_at VARCHAR(32) NOT NULL,
				updated_at VARCHAR(32) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rudel_app_deployment_key (deployment_key),
				KEY rudel_app_deployment_app (app_id),
				KEY rudel_app_deployment_environment (environment_id)
			)",
		);
	}

	/**
	 * SQLite schema.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return array<int, string>
	 */
	private static function sqlite_statements( DatabaseStore $store ): array {
		return array(
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'environments' ) . " (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				app_id INTEGER NULL,
				slug TEXT NOT NULL UNIQUE,
				name TEXT NOT NULL,
				path TEXT NOT NULL UNIQUE,
				type TEXT NOT NULL,
				engine TEXT NOT NULL,
				template TEXT NOT NULL,
				status TEXT NOT NULL,
				multisite INTEGER NOT NULL DEFAULT 0,
				blog_id INTEGER NULL,
				clone_source TEXT NULL,
				owner TEXT NULL,
				labels TEXT NULL,
				purpose TEXT NULL,
				is_protected INTEGER NOT NULL DEFAULT 0,
				expires_at TEXT NULL,
				last_used_at TEXT NULL,
				source_environment_slug TEXT NULL,
				source_environment_type TEXT NULL,
				last_deployed_from_slug TEXT NULL,
				last_deployed_from_type TEXT NULL,
				last_deployed_at TEXT NULL,
				tracked_github_repo TEXT NULL,
				tracked_github_branch TEXT NULL,
				tracked_github_dir TEXT NULL,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)",
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'environments' ) . '_type_idx ON ' . $store->table( 'environments' ) . ' (type)',
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'environments' ) . '_status_idx ON ' . $store->table( 'environments' ) . ' (status)',
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'environments' ) . '_app_idx ON ' . $store->table( 'environments' ) . ' (app_id)',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'apps' ) . " (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				environment_id INTEGER NOT NULL UNIQUE,
				slug TEXT NOT NULL UNIQUE,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)",
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_domains' ) . " (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				app_id INTEGER NOT NULL,
				domain TEXT NOT NULL UNIQUE,
				is_primary INTEGER NOT NULL DEFAULT 0,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)",
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'app_domains' ) . '_app_idx ON ' . $store->table( 'app_domains' ) . ' (app_id)',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'worktrees' ) . " (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				environment_id INTEGER NOT NULL,
				content_type TEXT NOT NULL,
				name TEXT NOT NULL,
				branch TEXT NOT NULL,
				repo_path TEXT NOT NULL,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)",
			'CREATE UNIQUE INDEX IF NOT EXISTS ' . $store->table( 'worktrees' ) . '_unique_idx ON ' . $store->table( 'worktrees' ) . ' (environment_id, content_type, name)',
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'worktrees' ) . '_environment_idx ON ' . $store->table( 'worktrees' ) . ' (environment_id)',
			'CREATE TABLE IF NOT EXISTS ' . $store->table( 'app_deployments' ) . " (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				deployment_key TEXT NOT NULL UNIQUE,
				app_id INTEGER NOT NULL,
				environment_id INTEGER NULL,
				app_slug TEXT NOT NULL,
				app_name TEXT NOT NULL,
				app_domains TEXT NULL,
				sandbox_slug TEXT NOT NULL,
				sandbox_name TEXT NOT NULL,
				source_environment_type TEXT NOT NULL,
				backup_name TEXT NULL,
				tables_copied INTEGER NULL,
				label TEXT NULL,
				notes TEXT NULL,
				github_repo TEXT NULL,
				github_branch TEXT NULL,
				github_base_branch TEXT NULL,
				github_dir TEXT NULL,
				deployed_at TEXT NOT NULL,
				created_at TEXT NOT NULL,
				updated_at TEXT NOT NULL
			)",
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'app_deployments' ) . '_app_idx ON ' . $store->table( 'app_deployments' ) . ' (app_id)',
			'CREATE INDEX IF NOT EXISTS ' . $store->table( 'app_deployments' ) . '_environment_idx ON ' . $store->table( 'app_deployments' ) . ' (environment_id)',
		);
	}
}
