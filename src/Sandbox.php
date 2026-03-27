<?php
/**
 * Sandbox model.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Represents a single Rudel sandbox environment.
 */
class Sandbox {

	/**
	 * Constructor.
	 *
	 * @param string     $id           Sandbox identifier.
	 * @param string     $name         Human-readable name.
	 * @param string     $path         Absolute filesystem path.
	 * @param string     $created_at   ISO 8601 creation timestamp.
	 * @param string     $template     Template used to create this sandbox.
	 * @param string     $status       Current status (active, paused).
	 * @param array|null $clone_source Clone source metadata, or null if not cloned.
	 * @param bool       $multisite    Whether this sandbox was cloned from a multisite host.
	 * @param string     $engine       Database engine: 'mysql', 'sqlite', or 'subsite'.
	 * @param int|null   $blog_id      Multisite blog ID (subsite engine only).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $path,
		public readonly string $created_at,
		public readonly string $template = 'blank',
		public readonly string $status = 'active',
		public readonly ?array $clone_source = null,
		public readonly bool $multisite = false,
		public readonly string $engine = 'mysql',
		public readonly ?int $blog_id = null,
	) {}

	/**
	 * Load a sandbox from its directory path.
	 *
	 * @param string $path Absolute path to the sandbox directory.
	 * @return self|null Sandbox instance or null if metadata is missing/invalid.
	 */
	public static function from_path( string $path ): ?self {
		$meta_file = rtrim( $path, '/' ) . '/.rudel.json';
		if ( ! file_exists( $meta_file ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
		$data = json_decode( file_get_contents( $meta_file ), true );
		if ( ! is_array( $data ) || ! isset( $data['id'], $data['name'] ) ) {
			return null;
		}

		return new self(
			id: $data['id'],
			name: $data['name'],
			path: rtrim( $path, '/' ),
			created_at: $data['created_at'] ?? '',
			template: $data['template'] ?? 'blank',
			status: $data['status'] ?? 'active',
			clone_source: $data['clone_source'] ?? null,
			multisite: ! empty( $data['multisite'] ),
			engine: $data['engine'] ?? 'mysql',
			blog_id: isset( $data['blog_id'] ) ? (int) $data['blog_id'] : null,
		);
	}

	/**
	 * Check if this sandbox uses the MySQL engine.
	 *
	 * @return bool True if MySQL.
	 */
	public function is_mysql(): bool {
		return 'mysql' === $this->engine;
	}

	/**
	 * Check if this sandbox uses the SQLite engine.
	 *
	 * @return bool True if SQLite.
	 */
	public function is_sqlite(): bool {
		return 'sqlite' === $this->engine;
	}

	/**
	 * Check if this sandbox uses the subsite engine.
	 *
	 * @return bool True if subsite.
	 */
	public function is_subsite(): bool {
		return 'subsite' === $this->engine;
	}

	/**
	 * Get the path to the sandbox SQLite database file.
	 *
	 * @return string|null Absolute path to the database file, or null for MySQL/subsite sandboxes.
	 */
	public function get_db_path(): ?string {
		if ( ! $this->is_sqlite() ) {
			return null;
		}
		return $this->path . '/wordpress.db';
	}

	/**
	 * Get the sandbox table prefix.
	 *
	 * @return string Table prefix string.
	 */
	public function get_table_prefix(): string {
		if ( $this->is_subsite() && null !== $this->blog_id ) {
			global $wpdb;
			if ( isset( $wpdb ) && $wpdb ) {
				return $wpdb->base_prefix . $this->blog_id . '_';
			}
		}
		return 'rudel_' . substr( md5( $this->id ), 0, 6 ) . '_';
	}

	/**
	 * Get the path to the sandbox wp-content directory.
	 *
	 * @return string Absolute path to wp-content.
	 */
	public function get_wp_content_path(): string {
		return $this->path . '/wp-content';
	}

	/**
	 * Calculate total disk usage of the sandbox directory.
	 *
	 * @return int Size in bytes.
	 */
	public function get_size(): int {
		$size     = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->path, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}
		return $size;
	}

	/**
	 * Get the sandbox URL.
	 *
	 * @return string URL path or full URL if WP_HOME is defined.
	 */
	public function get_url(): string {
		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' ) . '/' . RUDEL_PATH_PREFIX . '/' . $this->id . '/';
		}
		return '/' . RUDEL_PATH_PREFIX . '/' . $this->id . '/';
	}

	/**
	 * Convert sandbox to an associative array.
	 *
	 * @return array<string, mixed> Sandbox data.
	 */
	public function to_array(): array {
		$data = array(
			'id'         => $this->id,
			'name'       => $this->name,
			'path'       => $this->path,
			'created_at' => $this->created_at,
			'template'   => $this->template,
			'status'     => $this->status,
			'engine'     => $this->engine,
		);

		if ( null !== $this->clone_source ) {
			$data['clone_source'] = $this->clone_source;
		}

		if ( $this->multisite ) {
			$data['multisite'] = true;
		}

		if ( null !== $this->blog_id ) {
			$data['blog_id'] = $this->blog_id;
		}

		return $data;
	}

	/**
	 * Write metadata to .rudel.json inside the sandbox directory.
	 *
	 * @return void
	 */
	public function save_meta(): void {
		$meta_file = $this->path . '/.rudel.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WP dependency in model.
		file_put_contents( $meta_file, json_encode( $this->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	/**
	 * Validate a sandbox ID format.
	 *
	 * @param string $id Candidate sandbox ID.
	 * @return bool True if the ID is valid.
	 */
	public static function validate_id( string $id ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $id );
	}

	/**
	 * Generate a unique sandbox ID from a human-readable name.
	 *
	 * @param string $name Human-readable name.
	 * @return string Generated ID in slug-hash format.
	 */
	public static function generate_id( string $name ): string {
		$slug = strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', $name ), '-' ) );
		$slug = substr( $slug, 0, 48 );
		$hash = substr( md5( uniqid( $name, true ) ), 0, 4 );
		if ( '' === $slug ) {
			return 'sandbox-' . $hash;
		}
		return $slug . '-' . $hash;
	}
}
