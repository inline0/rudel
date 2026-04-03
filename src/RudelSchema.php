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
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		);
	}
}
