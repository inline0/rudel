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
	 * @param string $sandbox_id         Optional sandbox ID for git worktree branch naming.
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
	 * @return void
	 */
	private function copy_directory_recursive( string $source, string $target, ?string $excluded_path = null ): void {
		if ( ! is_dir( $target ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for sandbox content cloning.
			mkdir( $target, 0755, true );
		}

		$iterator = new \FilesystemIterator( $source, \FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $item ) {
			$source_path  = $item->getPathname();
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

			if ( $item->isDir() ) {
				$this->copy_directory_recursive( $source_path, $target_path, $excluded_path );
			} else {
				$target_dir = dirname( $target_path );
				if ( ! is_dir( $target_dir ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for copied file.
					mkdir( $target_dir, 0755, true );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying file to sandbox.
				copy( $source_path, $target_path );
			}
		}
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
	 * Get the host WordPress wp-content directory path.
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
