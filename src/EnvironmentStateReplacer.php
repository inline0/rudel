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
	 * @throws \InvalidArgumentException If the environments are not both subdomain-multisite sites.
	 */
	public function replace( Environment $source, Environment $target ): array {
		if ( ! $source->is_subsite() || ! $target->is_subsite() ) {
			throw new \InvalidArgumentException( 'Environment state replacement requires subdomain-multisite environments.' );
		}

		$tables_copied = $this->replace_mysql_environment_state( $source, $target );
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
		$mysql_cloner->drop_tables( $target_prefix, array( $target_prefix . 'snap_' ) );
		$tables = $mysql_cloner->copy_tables( $source_prefix, $target_prefix, array( $target_prefix . 'snap_' ) );
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
	 * Replace wp-content for the target environment.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return void
	 */
	private function replace_environment_content( Environment $source, Environment $target ): void {
		$source_worktrees = $this->worktree_map( $source );
		$target_worktrees = $this->worktree_map( $target );

		if ( ! empty( $source_worktrees ) || ! empty( $target_worktrees ) ) {
			$this->replace_environment_content_with_worktrees( $source, $target, $source_worktrees, $target_worktrees );
			return;
		}

		$target_content = $target->get_wp_content_path();
		if ( is_dir( $target_content ) ) {
			$this->delete_directory( $target_content );
		}

		( new ContentCloner() )->copy_directory( $source->get_wp_content_path(), $target_content );
	}

	/**
	 * Replace wp-content while preserving git worktrees for tracked code directories.
	 *
	 * @param Environment             $source Source environment.
	 * @param Environment             $target Target environment.
	 * @param array<string, string[]> $source_worktrees Worktree map grouped by top-level directory.
	 * @param array<string, string[]> $target_worktrees Worktree map grouped by top-level directory.
	 * @return void
	 */
	private function replace_environment_content_with_worktrees(
		Environment $source,
		Environment $target,
		array $source_worktrees,
		array $target_worktrees
	): void {
		$source_content = $source->get_wp_content_path();
		$target_content = $target->get_wp_content_path();

		if ( ! is_dir( $target_content ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating target wp-content before worktree-aware replacement.
			mkdir( $target_content, 0755, true );
		}

		$groups = array_unique(
			array_merge(
				array_keys( $source_worktrees ),
				array_keys( $target_worktrees )
			)
		);

		foreach ( $groups as $group ) {
			$this->sync_group_directory(
				$source_content . '/' . $group,
				$target_content . '/' . $group,
				$source_worktrees[ $group ] ?? array(),
				$target_worktrees[ $group ] ?? array()
			);
		}

		$this->sync_generic_directory(
			$source_content,
			$target_content,
			$groups,
			false
		);
	}

	/**
	 * Build a grouped worktree map keyed by top-level wp-content directory.
	 *
	 * @param Environment $environment Environment instance.
	 * @return array<string, string[]>
	 */
	private function worktree_map( Environment $environment ): array {
		$items = $environment->clone_source['git_worktrees'] ?? array();
		$map   = array();

		if ( ! is_array( $items ) ) {
			return $map;
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = isset( $item['type'] ) ? trim( (string) $item['type'] ) : '';
			$name = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';

			if ( '' === $type || '' === $name ) {
				continue;
			}

			$map[ $type ] ??= array();
			$map[ $type ][] = $name;
		}

		foreach ( $map as $type => $names ) {
			$map[ $type ] = array_values( array_unique( $names ) );
		}

		return $map;
	}

	/**
	 * Sync one top-level wp-content directory, preserving tracked worktree folders.
	 *
	 * @param string   $source_dir Source directory path.
	 * @param string   $target_dir Target directory path.
	 * @param string[] $source_worktrees Tracked child directories present in the source.
	 * @param string[] $target_worktrees Tracked child directories present in the target.
	 * @return void
	 */
	private function sync_group_directory(
		string $source_dir,
		string $target_dir,
		array $source_worktrees,
		array $target_worktrees
	): void {
		$protected = array_values( array_unique( array_merge( $source_worktrees, $target_worktrees ) ) );

		if ( ! is_dir( $source_dir ) ) {
			if ( is_dir( $target_dir ) ) {
				$entries = new \FilesystemIterator( $target_dir, \FilesystemIterator::SKIP_DOTS );
				foreach ( $entries as $entry ) {
					if ( in_array( $entry->getFilename(), $protected, true ) ) {
						continue;
					}
					$this->delete_path( $entry->getPathname() );
				}
			}

			return;
		}

		if ( ! is_dir( $target_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating target directory for worktree-aware replacement.
			mkdir( $target_dir, 0755, true );
		}

		$this->sync_generic_directory( $source_dir, $target_dir, $protected, false );

		foreach ( $protected as $name ) {
			$source_path = $source_dir . '/' . $name;
			$target_path = $target_dir . '/' . $name;

			if ( ! is_dir( $source_path ) ) {
				continue;
			}

			$this->sync_generic_directory( $source_path, $target_path, array( '.git' ), true );
		}
	}

	/**
	 * Sync one directory recursively while optionally preserving selected child entries.
	 *
	 * @param string   $source_dir Source directory path.
	 * @param string   $target_dir Target directory path.
	 * @param string[] $preserved_entries Entry names to keep intact at this directory level.
	 * @param bool     $preserve_git Whether nested `.git` entries must be skipped.
	 * @return void
	 */
	private function sync_generic_directory( string $source_dir, string $target_dir, array $preserved_entries, bool $preserve_git ): void {
		if ( ! is_dir( $source_dir ) ) {
			return;
		}

		if ( ! is_dir( $target_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating directory during content replacement.
			mkdir( $target_dir, 0755, true );
		}

		$preserved_lookup = array_fill_keys( $preserved_entries, true );

		$source_entries = new \FilesystemIterator( $source_dir, \FilesystemIterator::SKIP_DOTS );
		foreach ( $source_entries as $entry ) {
			$name = $entry->getFilename();
			if ( $preserve_git && '.git' === $name ) {
				continue;
			}
			if ( isset( $preserved_lookup[ $name ] ) && ! $entry->isDir() ) {
				continue;
			}

			$source_path = $entry->getPathname();
			$target_path = $target_dir . '/' . $name;

			if ( $entry->isDir() ) {
				if ( isset( $preserved_lookup[ $name ] ) ) {
					continue;
				}

				$this->sync_generic_directory( $source_path, $target_path, array(), $preserve_git );
				continue;
			}

			$this->copy_file( $source_path, $target_path );
		}

		$target_entries = new \FilesystemIterator( $target_dir, \FilesystemIterator::SKIP_DOTS );
		foreach ( $target_entries as $entry ) {
			$name = $entry->getFilename();
			if ( $preserve_git && '.git' === $name ) {
				continue;
			}
			if ( isset( $preserved_lookup[ $name ] ) ) {
				continue;
			}

			$source_path = $source_dir . '/' . $name;
			if ( file_exists( $source_path ) ) {
				continue;
			}

			$this->delete_path( $entry->getPathname() );
		}
	}

	/**
	 * Copy one file, creating parent directories when needed.
	 *
	 * @param string $source_path Source file path.
	 * @param string $target_path Target file path.
	 * @return void
	 */
	private function copy_file( string $source_path, string $target_path ): void {
		$target_dir = dirname( $target_path );
		if ( ! is_dir( $target_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for copied file.
			mkdir( $target_dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying file during environment replacement.
		copy( $source_path, $target_path );
	}

	/**
	 * Delete one file or directory tree.
	 *
	 * @param string $path File or directory path.
	 * @return void
	 */
	private function delete_path( string $path ): void {
		if ( is_dir( $path ) ) {
			$this->delete_directory( $path );
			return;
		}

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing stale file during environment replacement.
			unlink( $path );
		}
	}

	/**
	 * Build the runtime URL stored inside an environment's database.
	 *
	 * @param Environment $environment Environment instance.
	 * @return string
	 */
	private function environment_site_url( Environment $environment ): string {
		return rtrim( $environment->get_url(), '/' );
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
