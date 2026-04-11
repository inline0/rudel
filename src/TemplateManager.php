<?php
/**
 * Template manager: save and list reusable sandbox templates.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages templates stored under wp-content/rudel-templates/.
 */
class TemplateManager {

	/**
	 * Absolute path to the templates directory.
	 *
	 * @var string
	 */
	private string $templates_dir;

	/**
	 * Initialize dependencies.
	 *
	 * @param string|null $templates_dir Optional override for the templates directory.
	 */
	public function __construct( ?string $templates_dir = null ) {
		$this->templates_dir = $templates_dir ?? $this->get_default_templates_dir();
	}

	/**
	 * Save a sandbox as a template.
	 *
	 * @param Environment $sandbox     The sandbox to save.
	 * @param string      $name        Template name.
	 * @param string      $description Optional description.
	 * @return array Template metadata.
	 *
	 * @throws \InvalidArgumentException If the name is invalid or already exists.
	 * @throws \RuntimeException If template creation fails.
	 */
	public function save( Environment $sandbox, string $name, string $description = '' ): array {
		if ( ! self::validate_name( $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid template name: %s', $name ) );
		}

		$template_path = $this->templates_dir . '/' . $name;

		if ( is_dir( $template_path ) ) {
			throw new \InvalidArgumentException( sprintf( 'Template already exists: %s', $name ) );
		}

		if ( ! is_dir( $this->templates_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for template management.
			mkdir( $this->templates_dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating template directory.
		if ( ! mkdir( $template_path, 0755 ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create template directory: %s', $template_path ) );
		}

		EnvironmentContentLayout::copy_owned_wp_content( $sandbox, $template_path . '/wp-content' );

		$meta = array(
			'name'              => $name,
			'created_at'        => gmdate( 'c' ),
			'source_sandbox_id' => $sandbox->id,
			'source_url'        => $sandbox->get_url(),
			'description'       => $description,
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing template metadata.
		file_put_contents(
			$template_path . '/template.json',
			json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable

		return $meta;
	}

	/**
	 * List all templates.
	 *
	 * @return array[] Array of template metadata arrays.
	 */
	public function list_templates(): array {
		if ( ! is_dir( $this->templates_dir ) ) {
			return array();
		}

		$templates = array();
		$dirs      = scandir( $this->templates_dir );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$meta_file = $this->templates_dir . '/' . $dir . '/template.json';
			if ( ! file_exists( $meta_file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template metadata.
			$data = json_decode( file_get_contents( $meta_file ), true );
			if ( is_array( $data ) ) {
				$templates[] = $data;
			}
		}

		return $templates;
	}

	/**
	 * Delete a template.
	 *
	 * @param string $name Template name.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete( string $name ): bool {
		$template_path = $this->templates_dir . '/' . $name;

		if ( ! is_dir( $template_path ) ) {
			return false;
		}

		return $this->delete_directory( $template_path );
	}

	/**
	 * Filesystem path for a specific template.
	 *
	 * @param string $name Template name.
	 * @return string Absolute path.
	 *
	 * @throws \RuntimeException If the template does not exist.
	 */
	public function get_template_path( string $name ): string {
		$path = $this->templates_dir . '/' . $name;

		if ( ! is_dir( $path ) ) {
			throw new \RuntimeException( sprintf( 'Template not found: %s', $name ) );
		}

		return $path;
	}

	/**
	 * Directory that stores reusable templates.
	 *
	 * @return string Absolute path.
	 */
	public function get_templates_dir(): string {
		return $this->templates_dir;
	}

	/**
	 * Validate a template name.
	 *
	 * @param string $name Candidate template name.
	 * @return bool True if valid.
	 */
	public static function validate_name( string $name ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.\-]{0,63}$/', $name );
	}

	/**
	 * Determine the default templates directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_templates_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( WP_CONTENT_DIR, '/' ) . '/rudel-templates';
		}
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' ) . '/wp-content/rudel-templates';
		}
		return dirname( __DIR__, 3 ) . '/wp-content/rudel-templates';
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return bool True on success.
	 */
	private function delete_directory( string $dir ): bool {
		if ( is_link( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content entry during template cleanup.
			return unlink( $dir );
		}

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = $item->getPathname();
			if ( ! $item->isWritable() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Handling read-only files during template cleanup.
				chmod( $item_path, 0644 );
			}
			if ( $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content entry during template cleanup.
				unlink( $item_path );
				continue;
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive directory removal during template delete.
				rmdir( $item_path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- File deletion during template cleanup.
				unlink( $item_path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty template directory.
		return rmdir( $dir );
	}
}
