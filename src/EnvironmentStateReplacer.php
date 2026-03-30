<?php
/**
 * Environment state replacement helpers.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps destructive state-copying logic isolated from the broader environment lifecycle.
 */
class EnvironmentStateReplacer {

	/**
	 * Replace one environment's database and wp-content with another's.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return array{source_id: string, target_id: string, tables_copied: int}
	 *
	 * @throws \InvalidArgumentException If engines do not match or either environment uses subsite mode.
	 */
	public function replace( Environment $source, Environment $target ): array {
		if ( $source->is_subsite() || $target->is_subsite() ) {
			throw new \InvalidArgumentException( 'Environment state replacement does not support subsite environments.' );
		}

		if ( $source->engine !== $target->engine ) {
			throw new \InvalidArgumentException(
				sprintf( 'Cannot replace environment state across engines: source is %s, target is %s.', $source->engine, $target->engine )
			);
		}

		$tables_copied = 0;
		if ( $source->is_mysql() ) {
			$tables_copied = $this->replace_mysql_environment_state( $source, $target );
		} else {
			$this->replace_sqlite_environment_state( $source, $target );
		}

		$this->replace_environment_content( $source, $target );

		return array(
			'source_id'     => $source->id,
			'target_id'     => $target->id,
			'tables_copied' => $tables_copied,
		);
	}

	/**
	 * Copy MySQL-backed tables from one environment prefix to another.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return int Number of copied tables.
	 */
	private function replace_mysql_environment_state( Environment $source, Environment $target ): int {
		$mysql_cloner  = new MySQLCloner();
		$source_prefix = $source->get_table_prefix();
		$target_prefix = $target->get_table_prefix();
		$tables        = $mysql_cloner->copy_tables( $source_prefix, $target_prefix, array( $target_prefix . 'snap_' ) );
		$mysql_cloner->rewrite_urls(
			$GLOBALS['wpdb'],
			$target_prefix,
			$this->environment_site_url( $source ),
			$this->environment_site_url( $target )
		);
		$mysql_cloner->rewrite_table_prefix_in_data(
			$GLOBALS['wpdb'],
			$target_prefix,
			$source_prefix,
			$target_prefix
		);

		return $tables;
	}

	/**
	 * Replace the SQLite database file after rewriting runtime-specific values.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return void
	 *
	 * @throws \RuntimeException If the SQLite replacement cannot stage or promote the rewritten database.
	 */
	private function replace_sqlite_environment_state( Environment $source, Environment $target ): void {
		$source_db = $source->get_db_path();
		$target_db = $target->get_db_path();

		if ( ! $source_db || ! $target_db ) {
			throw new \RuntimeException( 'SQLite environment replacement requires both source and target databases.' );
		}

		$tmp_db = $target->path . '/tmp/' . $target->id . '-replace.db';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Replacing isolated SQLite database through a temporary file.
		if ( ! copy( $source_db, $tmp_db ) ) {
			throw new \RuntimeException( sprintf( 'Failed to copy SQLite database from source environment: %s', $source->id ) );
		}

		$source_prefix = $source->get_table_prefix();
		$target_prefix = $target->get_table_prefix();
		$source_url    = $this->environment_site_url( $source );
		$target_url    = $this->environment_site_url( $target );

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO access is required for isolated file rewriting.
		$pdo = new \PDO( 'sqlite:' . $tmp_db );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$cloner = new DatabaseCloner();
		$cloner->rewrite_urls( $pdo, $source_prefix, $source_url, $target_url );

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO access is required for isolated table renaming.
		$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$source_prefix}%'" )
			->fetchAll( \PDO::FETCH_COLUMN );
		// phpcs:enable

		foreach ( $tables as $old_table ) {
			$new_table = $target_prefix . substr( $old_table, strlen( $source_prefix ) );
			$pdo->exec( "ALTER TABLE `{$old_table}` RENAME TO `{$new_table}`" );
		}

		$cloner->rewrite_table_prefix_in_data( $pdo, $target_prefix, $source_prefix, $target_prefix );
		$pdo = null;

		if ( file_exists( $target_db ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Replacing isolated SQLite database file.
			unlink( $target_db );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Promoting rewritten SQLite database into place.
		rename( $tmp_db, $target_db );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Matching generated database permissions.
		chmod( $target_db, 0664 );
	}

	/**
	 * Replace wp-content for the target environment.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return void
	 */
	private function replace_environment_content( Environment $source, Environment $target ): void {
		$target_content = $target->get_wp_content_path();
		if ( is_dir( $target_content ) ) {
			$this->delete_directory( $target_content );
		}

		( new ContentCloner() )->copy_directory( $source->get_wp_content_path(), $target_content );
	}

	/**
	 * Build the runtime URL stored inside an environment's database.
	 *
	 * @param Environment $environment Environment instance.
	 * @return string
	 */
	private function environment_site_url( Environment $environment ): string {
		if ( $environment->is_app() && ! empty( $environment->domains ) ) {
			return 'https://' . $environment->domains[0];
		}

		$host = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		return $host . '/' . RUDEL_PATH_PREFIX . '/' . $environment->id;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing directory during environment replacement.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file during environment replacement.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing directory during environment replacement.
		rmdir( $dir );

		return true;
	}
}
