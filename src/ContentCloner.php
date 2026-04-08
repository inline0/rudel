<?php
/**
 * Content cloner: copies wp-content subdirectories from host to sandbox.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Copies themes, plugins, and uploads from the host WordPress
 * wp-content directory into a sandbox's wp-content directory.
 */
class ContentCloner {

	/**
	 * Clone wp-content subdirectories from the host to the sandbox.
	 *
	 * @param string $sandbox_wp_content Absolute path to the sandbox wp-content directory.
	 * @param array  $options            Which directories to clone: 'themes', 'plugins', 'uploads' (bool each).
	 * @param string $sandbox_id         Optional environment ID for git worktree branch and metadata naming.
	 * @return array<string, mixed> Status per directory.
	 */
	public function clone_content( string $sandbox_wp_content, array $options = array(), string $sandbox_id = '' ): array {
		$host_wp_content = $this->get_host_wp_content_dir();
		$results         = array();
		$use_git         = '' !== $sandbox_id;

		$directories = array( 'themes', 'plugins', 'uploads' );

		foreach ( $directories as $dir ) {
			if ( empty( $options[ $dir ] ) ) {
				$results[ $dir ] = 'skipped';
				continue;
			}

			$source = $host_wp_content . '/' . $dir;
			$target = $sandbox_wp_content . '/' . $dir;

			if ( ! is_dir( $source ) ) {
				$results[ $dir ] = 'missing';
				continue;
			}

			// Start from a clean target so scaffolding does not interfere with worktrees or nested copies.
			if ( is_dir( $target ) ) {
				$this->delete_directory( $target );
			}

			// Only code directories benefit from worktrees; uploads must stay plain files because they are runtime data.
			if ( $use_git && 'uploads' !== $dir ) {
				$git             = new GitIntegration();
				$git_results     = $git->clone_with_worktrees( $source, $target, $sandbox_id );
				$results[ $dir ] = array(
					'status'    => 'copied',
					'worktrees' => $git_results['worktrees'],
					'copied'    => $git_results['copied'],
				);
			} else {
				$this->copy_directory( $source, $target );
				$results[ $dir ] = 'copied';
			}
		}

		return $results;
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source Absolute source directory path.
	 * @param string $target Absolute target directory path.
	 * @return void
	 */
	public function copy_directory( string $source, string $target ): void {
		if ( ! is_dir( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for sandbox content cloning.
			mkdir( $target, 0755, true );
		}

		$source_real   = realpath( $source );
		$target_real   = realpath( $target );
		$excluded_path = null;

		if (
			false !== $source_real
			&& false !== $target_real
			&& $target_real !== $source_real
			&& 0 === strpos( $target_real, $source_real . DIRECTORY_SEPARATOR )
		) {
			$excluded_path = $target_real;
		}

		$this->copy_directory_recursive( $source, $target, $excluded_path );
	}

	/**
	 * Recursively copy a directory, optionally skipping one nested target subtree.
	 *
	 * @param string      $source        Absolute source directory path.
	 * @param string      $target        Absolute target directory path.
	 * @param string|null $excluded_path Absolute path to skip while traversing.
	 * @throws \UnexpectedValueException When traversing an existing source directory fails.
	 * @return void
	 */
	private function copy_directory_recursive( string $source, string $target, ?string $excluded_path = null ): void {
		if ( ! is_dir( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for sandbox content cloning.
			mkdir( $target, 0755, true );
		}

		try {
			$iterator = new \FilesystemIterator( $source, \FilesystemIterator::SKIP_DOTS );
		} catch ( \UnexpectedValueException $exception ) {
			if ( ! is_dir( $source ) ) {
				return;
			}

			throw $exception;
		}

		foreach ( $iterator as $item ) {
			if ( $this->should_skip_item( $item->getFilename() ) ) {
				continue;
			}

			$source_path = $item->getPathname();
			if ( ! file_exists( $source_path ) && ! is_link( $source_path ) ) {
				continue;
			}
			$source_real  = realpath( $source_path );
			$compare_path = false !== $source_real ? $source_real : $source_path;

			if (
				null !== $excluded_path
				&& (
					$compare_path === $excluded_path
					|| str_starts_with( $excluded_path, $compare_path . DIRECTORY_SEPARATOR )
				)
			) {
				continue;
			}

			$target_path = $target . '/' . $item->getFilename();

			if ( $item->isLink() ) {
				$this->copy_symlink_target( $source_path, $target_path, $excluded_path );
				continue;
			}

			if ( $item->isDir() ) {
				if ( ! is_dir( $source_path ) ) {
					continue;
				}

				$this->copy_directory_recursive( $source_path, $target_path, $excluded_path );
			} else {
				if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
					continue;
				}

				$target_dir = dirname( $target_path );
				if ( ! is_dir( $target_dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for copied file.
					mkdir( $target_dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying file to sandbox.
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Source files can disappear mid-clone in active dev trees.
				@copy( $source_path, $target_path );
			}
		}
	}

	/**
	 * Copies a symlink target into the sandbox when it resolves cleanly.
	 *
	 * @param string      $source_path   Absolute symlink path.
	 * @param string      $target_path   Absolute target path in the clone.
	 * @param string|null $excluded_path Absolute path to skip while traversing.
	 * @return void
	 */
	private function copy_symlink_target( string $source_path, string $target_path, ?string $excluded_path = null ): void {
		$resolved = realpath( $source_path );
		if ( false === $resolved ) {
			return;
		}

		if ( is_dir( $resolved ) ) {
			$this->copy_directory_recursive( $resolved, $target_path, $excluded_path );
			return;
		}

		if ( ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			return;
		}

		$target_dir = dirname( $target_path );
		if ( ! is_dir( $target_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for copied symlink target.
			mkdir( $target_dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying the resolved symlink target into the sandbox.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Source files can disappear mid-clone in active dev trees.
		@copy( $resolved, $target_path );
	}

	/**
	 * Skips dev-only or transient items that should never become part of a cloned runtime.
	 *
	 * @param string $filename Current filesystem entry name.
	 * @return bool
	 */
	private function should_skip_item( string $filename ): bool {
		return in_array( $filename, array( '.git', '.coverage', 'node_modules' ), true );
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing empty directory during content clone.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file during content clone.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty directory.
		rmdir( $dir );
	}

	/**
	 * Host WordPress wp-content directory path.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	private function get_host_wp_content_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( WP_CONTENT_DIR, '/' );
		}
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' ) . '/wp-content';
		}
		return dirname( __DIR__, 3 ) . '/wp-content';
	}
}
