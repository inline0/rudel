<?php
/**
 * Environment content layout helpers.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages opt-in shared wp-content directories for environments.
 */
class EnvironmentContentLayout {

	/**
	 * Directories that can be shared with the host instead of copied locally.
	 *
	 * @var array<int, string>
	 */
	private const SHAREABLE_DIRECTORIES = array( 'plugins', 'uploads' );

	/**
	 * Environment-local directories that should point back to the host.
	 *
	 * @param Environment $environment Environment instance.
	 * @return array<int, string>
	 */
	public static function shared_directories( Environment $environment ): array {
		$directories = array();

		if ( $environment->shared_plugins ) {
			$directories[] = 'plugins';
		}

		if ( $environment->shared_uploads ) {
			$directories[] = 'uploads';
		}

		return $directories;
	}

	/**
	 * Whether a top-level wp-content directory is shared for this environment.
	 *
	 * @param Environment $environment Environment instance.
	 * @param string      $directory Directory name such as plugins or uploads.
	 * @return bool
	 */
	public static function is_shared_directory( Environment $environment, string $directory ): bool {
		return in_array( $directory, self::shared_directories( $environment ), true );
	}

	/**
	 * Ensure the env-local wp-content layout matches the environment policy.
	 *
	 * @param Environment $environment Environment instance.
	 * @return void
	 */
	public static function materialize_for_environment( Environment $environment ): void {
		self::materialize_for_path(
			$environment->get_wp_content_path(),
			$environment->shared_plugins,
			$environment->shared_uploads
		);
	}

	/**
	 * Ensure plugins/uploads match the requested shared layout for one wp-content path.
	 *
	 * @param string $wp_content_path Absolute env-local wp-content path.
	 * @param bool   $shared_plugins Whether plugins should be shared with the host.
	 * @param bool   $shared_uploads Whether uploads should be shared with the host.
	 * @return void
	 */
	public static function materialize_for_path( string $wp_content_path, bool $shared_plugins, bool $shared_uploads ): void {
		if ( ! is_dir( $wp_content_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Environment scaffolding uses direct filesystem writes.
			mkdir( $wp_content_path, 0755, true );
		}

		$layout = array(
			'plugins' => $shared_plugins,
			'uploads' => $shared_uploads,
		);

		foreach ( $layout as $directory => $shared ) {
			$target = $wp_content_path . '/' . $directory;

			if ( $shared ) {
				self::replace_with_host_link( $directory, $target );
				continue;
			}

			if ( is_link( $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Converting a shared directory back to an isolated local directory.
				unlink( $target );
			}

			if ( ! is_dir( $target ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating isolated directory layout.
				mkdir( $target, 0755, true );
			}
		}
	}

	/**
	 * Copy only env-owned wp-content entries into an export/backup target.
	 *
	 * Shared plugins/uploads stay external to the environment and are therefore excluded.
	 *
	 * @param Environment $environment Environment instance.
	 * @param string      $target_content Absolute target wp-content path.
	 * @return void
	 */
	public static function copy_owned_wp_content( Environment $environment, string $target_content ): void {
		$source_content = $environment->get_wp_content_path();
		$shared         = array_fill_keys( self::shared_directories( $environment ), true );
		$cloner         = new ContentCloner();

		if ( ! is_dir( $source_content ) ) {
			return;
		}

		if ( ! is_dir( $target_content ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating export target for environment-owned content.
			mkdir( $target_content, 0755, true );
		}

		$entries = new \FilesystemIterator( $source_content, \FilesystemIterator::SKIP_DOTS );
		foreach ( $entries as $entry ) {
			$name = $entry->getFilename();
			if ( isset( $shared[ $name ] ) ) {
				continue;
			}

			$source_path = $entry->getPathname();
			$target_path = $target_content . '/' . $name;

			if ( $entry->isDir() || ( $entry->isLink() && is_dir( (string) realpath( $source_path ) ) ) ) {
				$cloner->copy_directory( $source_path, $target_path );
				continue;
			}

			$resolved = $entry->isLink() ? realpath( $source_path ) : $source_path;
			if ( false === $resolved || ! is_file( $resolved ) ) {
				continue;
			}

			$target_dir = dirname( $target_path );
			if ( ! is_dir( $target_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for exported file.
				mkdir( $target_dir, 0755, true );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying environment-owned file into export target.
			copy( $resolved, $target_path );
		}
	}

	/**
	 * Whether an environment-level tracked Git directory conflicts with shared plugins.
	 *
	 * @param bool        $shared_plugins Shared-plugins flag.
	 * @param string|null $git_dir Optional tracked wp-content subdirectory.
	 * @return bool
	 */
	public static function conflicts_with_shared_plugins( bool $shared_plugins, ?string $git_dir ): bool {
		if ( ! $shared_plugins || null === $git_dir ) {
			return false;
		}

		$git_dir = trim( str_replace( '\\', '/', $git_dir ), '/' );
		return '' !== $git_dir && ( 'plugins' === $git_dir || str_starts_with( $git_dir, 'plugins/' ) );
	}

	/**
	 * Host wp-content directory path.
	 *
	 * @return string
	 */
	public static function host_wp_content_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( WP_CONTENT_DIR, '/' );
		}

		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' ) . '/wp-content';
		}

		return dirname( __DIR__, 3 ) . '/wp-content';
	}

	/**
	 * Replace an env-local directory with a symlink to the host directory.
	 *
	 * @param string $directory Top-level wp-content directory name.
	 * @param string $target Absolute env-local path.
	 * @return void
	 * @throws \InvalidArgumentException When the requested directory is not shareable.
	 * @throws \RuntimeException When the shared-directory symlink cannot be created.
	 */
	private static function replace_with_host_link( string $directory, string $target ): void {
		if ( ! in_array( $directory, self::SHAREABLE_DIRECTORIES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unsupported shared content directory: %s', $directory ) );
		}

		$source = self::host_wp_content_dir() . '/' . $directory;

		if ( ! is_dir( $source ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Ensuring the shared host directory exists before linking it.
			mkdir( $source, 0755, true );
		}

		if ( is_link( $target ) ) {
			$link_target = readlink( $target );
			if ( is_string( $link_target ) && $link_target === $source ) {
				return;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Replacing outdated shared-directory symlink.
			unlink( $target );
		} elseif ( file_exists( $target ) ) {
			self::delete_path( $target );
		}

		$target_parent = dirname( $target );
		if ( ! is_dir( $target_parent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Ensuring parent directory exists before linking shared content.
			mkdir( $target_parent, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.symlink_symlink -- Shared content roots are implemented as opt-in local symlinks.
		if ( ! symlink( $source, $target ) ) {
			throw new \RuntimeException( sprintf( 'Failed to link shared %s directory: %s', $directory, $target ) );
		}
	}

	/**
	 * Delete one file, symlink, or directory tree.
	 *
	 * @param string $path Absolute path.
	 * @return bool True when something was removed.
	 */
	private static function delete_path( string $path ): bool {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file-system entries during layout changes.
			return unlink( $path );
		}

		if ( ! is_dir( $path ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();

			if ( $item->isLink() || $item->isFile() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file-system entries during layout changes.
				unlink( $item_path );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing directory during layout changes.
			rmdir( $item_path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing directory during layout changes.
		return rmdir( $path );
	}
}
