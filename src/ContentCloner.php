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
	 * Copy a selected set of top-level directories from one root to another.
	 *
	 * @param string   $source_root      Absolute source root path.
	 * @param string   $target_root      Absolute target root path.
	 * @param string[] $directory_names  Top-level directory names to copy.
	 * @return void
	 */
	public function copy_named_directories( string $source_root, string $target_root, array $directory_names ): void {
		$directory_names = array_values(
			array_filter(
				array_map( 'strval', $directory_names ),
				static fn ( string $name ): bool => '' !== $name
			)
		);

		if ( empty( $directory_names ) ) {
			return;
		}

		if ( ! is_dir( $target_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for batched content cloning.
			mkdir( $target_root, 0755, true );
		}

		if ( $this->copy_named_directories_with_tar( $source_root, $target_root, $directory_names ) || $this->copy_named_directories_with_phar( $source_root, $target_root, $directory_names ) ) {
			return;
		}

		foreach ( $directory_names as $name ) {
			$this->copy_directory( $source_root . '/' . $name, $target_root . '/' . $name );
		}
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

		if ( null === $excluded_path && $this->is_directory_empty( $target ) ) {
			if ( $this->copy_directory_with_tar( $source, $target ) || $this->copy_directory_with_phar( $source, $target ) ) {
				return;
			}
		}

		$this->copy_directory_recursive( $source, $target, $excluded_path );
	}

	/**
	 * Copy one directory tree using native tar streaming when available.
	 *
	 * This is dramatically faster than PHP-level per-file copies on Docker bind
	 * mounts, but only safe when the target starts empty and we do not need the
	 * nested-target exclusion behavior handled by the recursive PHP fallback.
	 *
	 * @param string $source Absolute source directory path.
	 * @param string $target Absolute target directory path.
	 * @return bool True when the native copy succeeded.
	 */
	private function copy_directory_with_tar( string $source, string $target ): bool {
		if ( '\\' === DIRECTORY_SEPARATOR || ! function_exists( 'proc_open' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}

		$exclude_args = array_map(
			static fn ( string $filename ): string => '--exclude=' . escapeshellarg( $filename ),
			array( '.git', '.coverage', 'node_modules' )
		);

		$command = sprintf(
			'tar -h -C %1$s %2$s -cf - . | tar -C %3$s -xf -',
			escapeshellarg( $source ),
			implode( ' ', $exclude_args ),
			escapeshellarg( $target )
		);

		$process = proc_open(
			array( 'sh', '-lc', $command ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return false;
		}

		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		return 0 === proc_close( $process );
	}

	/**
	 * Copy a selected set of top-level directories using one tar stream.
	 *
	 * @param string   $source_root      Absolute source root path.
	 * @param string   $target_root      Absolute target root path.
	 * @param string[] $directory_names  Top-level directory names to copy.
	 * @return bool True when the native copy succeeded.
	 */
	private function copy_named_directories_with_tar( string $source_root, string $target_root, array $directory_names ): bool {
		if ( '\\' === DIRECTORY_SEPARATOR || ! function_exists( 'proc_open' ) || ! function_exists( 'escapeshellarg' ) ) {
			return false;
		}

		$exclude_args = array_map(
			static fn ( string $filename ): string => '--exclude=' . escapeshellarg( $filename ),
			array( '.git', '.coverage', 'node_modules' )
		);

		$entry_args = implode(
			' ',
			array_map(
				static fn ( string $name ): string => escapeshellarg( $name ),
				$directory_names
			)
		);

		$command = sprintf(
			'tar -h -C %1$s %2$s -cf - %3$s | tar -C %4$s -xf -',
			escapeshellarg( $source_root ),
			implode( ' ', $exclude_args ),
			$entry_args,
			escapeshellarg( $target_root )
		);

		$process = proc_open(
			array( 'sh', '-lc', $command ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return false;
		}

		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		return 0 === proc_close( $process );
	}

	/**
	 * Copy one directory tree by building and extracting a temporary tar archive.
	 *
	 * @param string $source Absolute source directory path.
	 * @param string $target Absolute target directory path.
	 * @return bool True when the archive copy succeeded.
	 */
	private function copy_directory_with_phar( string $source, string $target ): bool {
		if ( ! class_exists( 'PharData' ) ) {
			return false;
		}

		$archive_path = $this->temporary_archive_path();
		if ( null === $archive_path ) {
			return false;
		}

		try {
			$archive = new \PharData( $archive_path );
			if ( ! $this->add_directory_contents_to_archive( $archive, $source ) ) {
				unset( $archive );
				@unlink( $archive_path );
				return false;
			}

			$archive->extractTo( $target, null, true );
			unset( $archive );
			@unlink( $archive_path );

			return true;
		} catch ( \Throwable $exception ) {
			unset( $archive );
			@unlink( $archive_path );

			return false;
		}
	}

	/**
	 * Copy a selected set of top-level directories by building and extracting a
	 * temporary tar archive through the Phar extension.
	 *
	 * @param string   $source_root      Absolute source root path.
	 * @param string   $target_root      Absolute target root path.
	 * @param string[] $directory_names  Top-level directory names to copy.
	 * @return bool True when the archive copy succeeded.
	 */
	private function copy_named_directories_with_phar( string $source_root, string $target_root, array $directory_names ): bool {
		if ( ! class_exists( 'PharData' ) ) {
			return false;
		}

		$archive_path = $this->temporary_archive_path();
		if ( null === $archive_path ) {
			return false;
		}

		try {
			$archive = new \PharData( $archive_path );

			foreach ( $directory_names as $name ) {
				if ( ! $this->add_path_to_archive( $archive, $source_root . '/' . $name, $name ) ) {
					unset( $archive );
					@unlink( $archive_path );
					return false;
				}
			}

			$archive->extractTo( $target_root, null, true );
			unset( $archive );
			@unlink( $archive_path );

			return true;
		} catch ( \Throwable $exception ) {
			unset( $archive );
			@unlink( $archive_path );

			return false;
		}
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
	 * Whether a directory currently has any entries.
	 *
	 * @param string $directory Absolute directory path.
	 * @return bool
	 */
	private function is_directory_empty( string $directory ): bool {
		try {
			$iterator = new \FilesystemIterator( $directory, \FilesystemIterator::SKIP_DOTS );
		} catch ( \UnexpectedValueException $exception ) {
			return true;
		}

		return ! $iterator->valid();
	}

	/**
	 * Add one filesystem path to a tar archive while preserving the clone skip rules.
	 *
	 * @param \PharData $archive      Writable tar archive.
	 * @param string    $source_path  Absolute source path.
	 * @param string    $archive_path Relative archive path.
	 * @return bool False when the tree contains symlinks and must fall back.
	 */
	private function add_path_to_archive( \PharData $archive, string $source_path, string $archive_path ): bool {
		if ( $this->should_skip_item( basename( $source_path ) ) ) {
			return true;
		}

		if ( is_link( $source_path ) ) {
			return false;
		}

		if ( is_dir( $source_path ) ) {
			$archive->addEmptyDir( $archive_path );

			try {
				$iterator = new \FilesystemIterator( $source_path, \FilesystemIterator::SKIP_DOTS );
			} catch ( \UnexpectedValueException $exception ) {
				return false;
			}

			foreach ( $iterator as $item ) {
				if ( ! $this->add_path_to_archive( $archive, $item->getPathname(), $archive_path . '/' . $item->getFilename() ) ) {
					return false;
				}
			}

			return true;
		}

		if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			return true;
		}

		$archive->addFile( $source_path, $archive_path );
		return true;
	}

	/**
	 * Add the immediate contents of one directory to an archive root.
	 *
	 * @param \PharData $archive Writable tar archive.
	 * @param string    $source  Absolute source directory path.
	 * @return bool False when the tree contains symlinks and must fall back.
	 */
	private function add_directory_contents_to_archive( \PharData $archive, string $source ): bool {
		try {
			$iterator = new \FilesystemIterator( $source, \FilesystemIterator::SKIP_DOTS );
		} catch ( \UnexpectedValueException $exception ) {
			return ! is_dir( $source );
		}

		foreach ( $iterator as $item ) {
			if ( ! $this->add_path_to_archive( $archive, $item->getPathname(), $item->getFilename() ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Create a unique temporary archive path for one batched copy.
	 *
	 * @return string|null Absolute archive path, or null when no temp file can be reserved.
	 */
	private function temporary_archive_path(): ?string {
		$temp_path = tempnam( sys_get_temp_dir(), 'rudel-copy-' );
		if ( false === $temp_path ) {
			return null;
		}

		@unlink( $temp_path );
		return $temp_path . '.tar';
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( is_link( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content root before recloning content.
			unlink( $dir );
			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();
			if ( $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content entry before recloning content.
				unlink( $item_path );
				continue;
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing empty directory during content clone.
				rmdir( $item_path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file during content clone.
				unlink( $item_path );
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
