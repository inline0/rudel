<?php
/**
 * Environment theme overlay helpers.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Copies and resolves environment-owned active themes.
 */
class ThemeOverlay {

	/**
	 * Resolve the requested active theme slug.
	 *
	 * @param string|null $requested_slug Explicit requested theme slug.
	 * @return string|null Theme slug, or null when no theme can be resolved.
	 */
	public function resolve_theme_slug( ?string $requested_slug = null ): ?string {
		if ( null !== $requested_slug && '' !== trim( $requested_slug ) ) {
			return $this->normalize_slug( $requested_slug );
		}

		if ( function_exists( 'get_stylesheet' ) ) {
			$stylesheet = get_stylesheet();
			if ( is_string( $stylesheet ) && '' !== trim( $stylesheet ) ) {
				return $this->normalize_slug( $stylesheet );
			}
		}

		if ( function_exists( 'get_option' ) ) {
			$stylesheet = get_option( 'stylesheet' );
			if ( is_scalar( $stylesheet ) && '' !== trim( (string) $stylesheet ) ) {
				return $this->normalize_slug( (string) $stylesheet );
			}
		}

		return null;
	}

	/**
	 * Copy one active theme into an environment-owned theme root.
	 *
	 * @param string $theme_slug Theme slug.
	 * @param string $target_root Environment theme root.
	 * @return void
	 *
	 * @throws \RuntimeException When the source theme cannot be found.
	 */
	public function copy_theme( string $theme_slug, string $target_root ): void {
		$theme_slug = $this->normalize_slug( $theme_slug );
		$source     = $this->host_theme_path( $theme_slug );

		if ( null === $source || ! is_dir( $source ) ) {
			throw new \RuntimeException( sprintf( 'Theme not found: %s', $theme_slug ) );
		}

		$template_slug = $this->template_slug_for_theme( $theme_slug, dirname( $source ) );
		$template_path = $template_slug !== $theme_slug ? $this->host_theme_path( $template_slug ) : null;
		if ( $template_slug !== $theme_slug && ( null === $template_path || ! is_dir( $template_path ) ) ) {
			throw new \RuntimeException( sprintf( 'Parent theme not found: %s', $template_slug ) );
		}

		if ( ! is_dir( $target_root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating environment-owned theme root.
			mkdir( $target_root, 0755, true );
		}

		$target = rtrim( $target_root, '/' ) . '/' . $theme_slug;
		if ( is_dir( $target ) ) {
			$this->delete_directory( $target );
		}

		( new ContentCloner() )->copy_directory( $source, $target );

		if ( null !== $template_path && $template_slug !== $theme_slug ) {
			$template_target = rtrim( $target_root, '/' ) . '/' . $template_slug;
			if ( is_dir( $template_target ) ) {
				$this->delete_directory( $template_target );
			}
			( new ContentCloner() )->copy_directory( $template_path, $template_target );
		}
	}

	/**
	 * Resolve the template slug for a theme.
	 *
	 * @param string      $theme_slug Theme slug.
	 * @param string|null $theme_root Optional theme root to inspect first.
	 * @return string Template slug.
	 */
	public function template_slug_for_theme( string $theme_slug, ?string $theme_root = null ): string {
		$theme_slug = $this->normalize_slug( $theme_slug );
		$root       = null !== $theme_root && '' !== $theme_root ? rtrim( $theme_root, '/' ) : null;
		$theme_path = null !== $root ? $root . '/' . $theme_slug : $this->host_theme_path( $theme_slug );

		if ( null === $theme_path || ! is_dir( $theme_path ) ) {
			return $theme_slug;
		}

		$style_css = $theme_path . '/style.css';
		if ( ! is_file( $style_css ) ) {
			return $theme_slug;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local theme header before WordPress theme APIs are available.
		$contents = file_get_contents( $style_css );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return $theme_slug;
		}

		if ( 1 === preg_match( '/^[ \t\/*#@]*Template:\s*([A-Za-z0-9_.-]+)/mi', $contents, $matches ) ) {
			return $this->normalize_slug( $matches[1] );
		}

		return $theme_slug;
	}

	/**
	 * Path where environment-owned themes live.
	 *
	 * @param Environment|string $environment Environment object or environment path.
	 * @return string Absolute theme root path.
	 */
	public static function theme_root_for( $environment ): string {
		$path = $environment instanceof Environment ? $environment->path : (string) $environment;
		return rtrim( $path, '/' ) . '/themes';
	}

	/**
	 * Public URL for environment-owned theme files.
	 *
	 * @param string $environment_url Environment request URL.
	 * @param string $environment_id Environment ID.
	 * @return string Theme root URL.
	 */
	public static function theme_root_uri_for( string $environment_url, string $environment_id ): string {
		return rtrim( $environment_url, '/' ) . '/wp-content/rudel-environments/' . rawurlencode( $environment_id ) . '/themes';
	}

	/**
	 * Normalize a theme slug.
	 *
	 * @param string $slug Raw slug.
	 * @return string Safe slug.
	 * @throws \InvalidArgumentException When the slug contains unsafe characters.
	 */
	private function normalize_slug( string $slug ): string {
		$slug = trim( $slug );
		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $slug ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid theme slug: %s', $slug ) );
		}

		return $slug;
	}

	/**
	 * Resolve a host theme directory by slug.
	 *
	 * @param string $theme_slug Theme slug.
	 * @return string|null Absolute source path.
	 */
	private function host_theme_path( string $theme_slug ): ?string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$path = rtrim( WP_CONTENT_DIR, '/' ) . '/themes/' . $theme_slug;
			if ( is_dir( $path ) ) {
				return $path;
			}
		}

		if ( function_exists( 'get_theme_root' ) ) {
			$root = get_theme_root( $theme_slug );
			if ( is_string( $root ) && '' !== $root ) {
				$path = rtrim( $root, '/' ) . '/' . $theme_slug;
				if ( is_dir( $path ) ) {
					return $path;
				}
			}
		}

		return null;
	}

	/**
	 * Delete one directory tree.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			if ( ! $entry instanceof \SplFileInfo ) {
				continue;
			}

			if ( $entry->isDir() && ! $entry->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing environment-owned theme directory.
				rmdir( $entry->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing environment-owned theme file.
				unlink( $entry->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing environment-owned theme directory.
		rmdir( $dir );
	}
}
