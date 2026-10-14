<?php
/**
 * Point-in-time sandbox templates with host database metadata.
 *
 * @package Rudel
 */

namespace Rudel;

/** Saves reusable site tables and environment-owned content. */
class TemplateManager {
	/**
	 * Template collection directory.
	 *
	 * @var string
	 */
	private string $templates_dir;

	/**
	 * Optional injected runtime store.
	 *
	 * @var DatabaseStore|null
	 */
	private ?DatabaseStore $store;

	/**
	 * Bind the template collection.
	 *
	 * @param string|null        $templates_dir Collection directory.
	 * @param DatabaseStore|null $store Host metadata store.
	 */
	public function __construct( ?string $templates_dir = null, ?DatabaseStore $store = null ) {
		$this->templates_dir = rtrim( $templates_dir ?? EnvironmentContentLayout::host_wp_content_dir() . '/rudel-templates', '/' );
		$this->store         = $store;
	}

	/**
	 * Save current tables and owned theme/plugin files independently of the source.
	 *
	 * @param Environment $sandbox Source sandbox.
	 * @param string      $name Template name.
	 * @param string      $description Description.
	 * @return array<string, mixed> Template metadata.
	 * @throws \InvalidArgumentException When the name is invalid or already exists.
	 * @throws \RuntimeException When database or filesystem capture fails.
	 * @throws \Throwable When capture fails after allocating saved tables.
	 */
	public function save( Environment $sandbox, string $name, string $description = '' ): array {
		$this->validate( $name );
		if ( in_array( $name, array( 'overlay', 'blank' ), true ) ) {
			throw new \InvalidArgumentException( 'Reserved template name: ' . $name );
		}
		$path = $this->templates_dir . '/' . $name;
		if ( is_dir( $path ) || null !== $this->repository()->get( $name ) ) {
			throw new \InvalidArgumentException( 'Template already exists: ' . $name );
		}
		PrivateStorage::protect( $this->templates_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Template capture uses native filesystem operations.
		if ( ! mkdir( $path, 0755 ) ) {
			throw new \RuntimeException( 'Cannot create template directory: ' . $path );
		}
		$prefix = $this->table_prefix( $name );
		try {
			$cloner        = new MySQLCloner();
			$source_prefix = $sandbox->get_table_prefix();
			$count         = $cloner->copy_tables( $source_prefix, $prefix, array( $source_prefix . 'snap_' ) );
			if ( 0 === $count ) {
				throw new \RuntimeException( 'Template capture requires sandbox database tables.' );
			}
			global $wpdb;
			if ( ! $wpdb instanceof \wpdb ) {
				throw new \RuntimeException( 'Template capture requires WordPress database access.' );
			}
			$cloner->rewrite_table_prefix_in_data( $wpdb, $prefix, $source_prefix, $prefix );
			$content = new ContentCloner();
			if ( $sandbox->is_overlay() ) {
				$content->copy_directory( ThemeOverlay::theme_root_for( $sandbox ), $path . '/wp-content/themes' );
				if ( is_dir( $sandbox->path . '/plugins' ) && ! is_link( $sandbox->path . '/plugins' ) ) {
					$content->copy_directory( $sandbox->path . '/plugins', $path . '/wp-content/plugins' );
				}
			} else {
				EnvironmentContentLayout::copy_owned_wp_content( $sandbox, $path . '/wp-content' );
			}
			$clone_source = $sandbox->clone_source ?? array();
			unset( $clone_source['git'], $clone_source['git_worktrees'] );
			$meta = array(
				'name'              => $name,
				'created_at'        => gmdate( 'c' ),
				'source_sandbox_id' => $sandbox->id,
				'source_url'        => $sandbox->get_url(),
				'description'       => $description,
				'table_prefix'      => $prefix,
				'theme_slug'        => $sandbox->theme_slug,
				'clone_source'      => $clone_source,
				'tables_cloned'     => $count,
			);
			$this->repository()->save( $name, $meta );
			return $meta;
		} catch ( \Throwable $error ) {
			( new MySQLCloner() )->drop_tables( $prefix );
			$this->delete_directory( $path );
			throw $error;
		}
	}

	/**
	 * Copy a saved template into a new sandbox without consulting the source site.
	 *
	 * @param string $name Template name.
	 * @param string $target_prefix New sandbox table prefix.
	 * @param string $target_path New sandbox directory.
	 * @param string $target_url New sandbox URL.
	 * @return array<string, mixed> Saved metadata.
	 * @throws \RuntimeException When the template has no saved database or cannot be restored.
	 */
	public function restore_into( string $name, string $target_prefix, string $target_path, string $target_url ): array {
		$meta   = $this->get( $name );
		$prefix = $meta['table_prefix'] ?? null;
		if ( ! is_string( $prefix ) || $prefix !== $this->table_prefix( $name ) ) {
			throw new \RuntimeException( 'This template has no saved database. Save it again from a sandbox: ' . $name );
		}
		$cloner = new MySQLCloner();
		if ( 0 === $cloner->copy_tables( $prefix, $target_prefix ) ) {
			throw new \RuntimeException( 'Saved template tables are missing: ' . $name );
		}
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'Template restore requires WordPress database access.' );
		}
		$source_url = is_string( $meta['source_url'] ?? null ) ? $meta['source_url'] : $target_url;
		$cloner->rewrite_urls( $wpdb, $target_prefix, $source_url, $target_url );
		$cloner->rewrite_table_prefix_in_data( $wpdb, $target_prefix, $prefix, $target_prefix );
		$content = $this->get_template_path( $name ) . '/wp-content';
		$copier  = new ContentCloner();
		foreach ( array( 'themes', 'plugins' ) as $directory ) {
			if ( is_dir( $content . '/' . $directory ) ) {
				$copier->copy_directory( $content . '/' . $directory, $target_path . '/' . $directory );
			}
		}
		$clone_source = $meta['clone_source'] ?? null;
		if ( is_array( $clone_source ) && is_string( $clone_source['git_plugin_file'] ?? null ) ) {
			( new ConfigWriter() )->install_git_loader();
		}
		return $meta;
	}

	/**
	 * Read metadata and migrate older file-only templates into the host database.
	 *
	 * @param string $name Template name.
	 * @return array<string, mixed> Metadata.
	 * @throws \RuntimeException When the template is missing or its metadata is invalid.
	 */
	public function get( string $name ): array {
		$path = $this->get_template_path( $name );
		PrivateStorage::protect( $this->templates_dir );
		$record = $this->repository()->get( $name );
		$legacy = $path . '/template.json';
		if ( null === $record && is_file( $legacy ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- One-time migration of legacy file metadata.
			$raw  = file_get_contents( $legacy );
			$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $data ) ) {
				$record = array();
				foreach ( $data as $key => $value ) {
					if ( is_string( $key ) ) {
						$record[ $key ] = $value;
					}
				}
				$this->repository()->save( $name, $record );
			}
		}
		if ( null === $record ) {
			throw new \RuntimeException( 'Template metadata is missing: ' . $name );
		}
		if ( is_file( $legacy ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove public JSON only after a database record exists.
			unlink( $legacy );
		}
		return $record;
	}

	/**
	 * List reusable templates, including migrated file-only templates.
	 *
	 * @return array<int, array<string, mixed>> Metadata records.
	 */
	public function list_templates(): array {
		if ( ! is_dir( $this->templates_dir ) ) {
			return array();
		}
		$entries = scandir( $this->templates_dir );
		if ( false === $entries ) {
			return array();
		}
		foreach ( $entries as $entry ) {
			if ( self::validate_name( $entry ) && is_dir( $this->templates_dir . '/' . $entry ) ) {
				$this->get( $entry );
			}
		}
		return $this->repository()->all();
	}

	/**
	 * Remove a template and its saved tables, retaining its original source site.
	 *
	 * @param string $name Template name.
	 * @return bool Whether a template was removed.
	 * @throws \RuntimeException When table ownership cannot be verified.
	 */
	public function delete( string $name ): bool {
		$this->validate( $name );
		$path = $this->templates_dir . '/' . $name;
		if ( ! is_dir( $path ) ) {
			return false;
		}
		$meta = $this->get( $name );
		if ( isset( $meta['table_prefix'] ) ) {
			if ( $meta['table_prefix'] !== $this->table_prefix( $name ) ) {
				throw new \RuntimeException( 'Template table ownership cannot be verified.' );
			}
			( new MySQLCloner() )->drop_tables( $this->table_prefix( $name ) );
		}
		$result = $this->delete_directory( $path );
		if ( $result ) {
			$this->repository()->delete( $name );
		}
		return $result;
	}

	/**
	 * Locate saved content.
	 *
	 * @param string $name Template name.
	 * @return string Absolute directory.
	 * @throws \RuntimeException When the template is missing.
	 */
	public function get_template_path( string $name ): string {
		$this->validate( $name );
		$path = $this->templates_dir . '/' . $name;
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( 'Template not found: ' . $name );
		}
		return $path;
	}

	/**
	 * Collection directory.
	 *
	 * @return string
	 */
	public function get_templates_dir(): string {
		return $this->templates_dir;
	}

	/**
	 * Validate one path component.
	 *
	 * @param string $name Template name.
	 * @return bool Whether the name is safe.
	 */
	public static function validate_name( string $name ): bool {
		return SnapshotManager::validate_name( $name );
	}

	/**
	 * Reject invalid names before any filesystem or table operations.
	 *
	 * @param string $name Template name.
	 * @return void
	 * @throws \InvalidArgumentException When a name could escape the collection.
	 */
	private function validate( string $name ): void {
		if ( ! self::validate_name( $name ) ) {
			throw new \InvalidArgumentException( 'Invalid template name: ' . $name );
		}
	}

	/**
	 * Saved table ownership is independent of the source sandbox lifetime.
	 *
	 * @param string $name Template name.
	 * @return string
	 */
	private function table_prefix( string $name ): string {
		return 'rudel_tpl_' . substr( hash( 'sha256', $this->templates_dir . '/' . $name ), 0, 12 ) . '_';
	}

	/**
	 * Template metadata belongs to the host runtime store.
	 *
	 * @return ArtifactRepository
	 */
	private function repository(): ArtifactRepository {
		return new ArtifactRepository( $this->templates_dir, 'template', $this->store );
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
			if ( ! ( $item instanceof \SplFileInfo ) ) {
				continue;
			}
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
