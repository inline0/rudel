<?php
/**
 * Environment model.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Represents a single Rudel environment.
 */
class Environment {

	/**
	 * Constructor.
	 *
	 * @param string      $id           Sandbox identifier.
	 * @param string      $name         Human-readable name.
	 * @param string      $path         Absolute filesystem path.
	 * @param string      $created_at   ISO 8601 creation timestamp.
	 * @param string      $template     Template used to create this sandbox.
	 * @param string      $status       Current status (active, paused).
	 * @param array|null  $clone_source Clone source metadata, or null if not cloned.
	 * @param bool        $multisite    Whether this sandbox was cloned from a multisite host.
	 * @param string      $engine       Database engine: 'mysql', 'sqlite', or 'subsite'.
	 * @param int|null    $blog_id      Multisite blog ID (subsite engine only).
	 * @param string      $type                     Environment type: 'sandbox' or 'app'.
	 * @param array|null  $domains                  Domain names mapped to this environment (app mode).
	 * @param string|null $owner                    Optional owner for stewardship and cleanup policy.
	 * @param array       $labels                   Arbitrary labels for grouping and policy.
	 * @param string|null $purpose                  Optional description of why the environment exists.
	 * @param bool        $is_protected             Whether automated cleanup must skip this environment.
	 * @param string|null $expires_at               ISO 8601 expiry timestamp, or null if none.
	 * @param string|null $last_used_at             ISO 8601 last activity timestamp.
	 * @param string|null $source_environment_id    Source environment ID when cloned from another environment.
	 * @param string|null $source_environment_type  Source environment type when cloned from another environment.
	 * @param string|null $last_deployed_from_id    Last sandbox/app deployed into this environment.
	 * @param string|null $last_deployed_from_type  Type of the environment last deployed into this environment.
	 * @param string|null $last_deployed_at         ISO 8601 timestamp of the last deploy into this environment.
	 * @param string|null $tracked_github_repo      GitHub repository this environment tracks as its deployed code source.
	 * @param string|null $tracked_github_branch    Branch this environment treats as its stable mainline.
	 * @param string|null $tracked_github_dir       Optional wp-content subdirectory associated with the tracked repository.
	 * @param int|null    $record_id                DB record ID for the environment row.
	 * @param int|null    $app_record_id            DB record ID for the related app row, when present.
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
		public readonly string $type = 'sandbox',
		public readonly ?array $domains = null,
		public readonly ?string $owner = null,
		public readonly array $labels = array(),
		public readonly ?string $purpose = null,
		public readonly bool $is_protected = false,
		public readonly ?string $expires_at = null,
		public readonly ?string $last_used_at = null,
		public readonly ?string $source_environment_id = null,
		public readonly ?string $source_environment_type = null,
		public readonly ?string $last_deployed_from_id = null,
		public readonly ?string $last_deployed_from_type = null,
		public readonly ?string $last_deployed_at = null,
		public readonly ?string $tracked_github_repo = null,
		public readonly ?string $tracked_github_branch = null,
		public readonly ?string $tracked_github_dir = null,
		public readonly ?int $record_id = null,
		public readonly ?int $app_record_id = null,
	) {}

	/**
	 * Load an environment from its directory path.
	 *
	 * @param string $path Absolute path to the environment directory.
	 * @return self|null Environment instance or null if no DB record matches the path.
	 */
	public static function from_path( string $path ): ?self {
		$path = rtrim( $path, '/' );
		if ( '' === $path ) {
			return null;
		}

		try {
			$store      = RudelDatabase::for_paths( dirname( $path ) );
			$repository = new EnvironmentRepository( $store, dirname( $path ) );
			return $repository->get_by_path( $path );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Hydrate one environment from a DB record.
	 *
	 * @param array<string, mixed>             $record DB record.
	 * @param array<int, string>|null          $domains Normalized app domains.
	 * @param array<int, array<string, mixed>> $worktrees Git worktree metadata.
	 * @return self
	 */
	public static function from_record( array $record, ?array $domains = null, array $worktrees = array() ): self {
		$clone_source = self::json_array_or_null( $record['clone_source'] ?? null );
		if ( ! empty( $worktrees ) ) {
			$clone_source                  = is_array( $clone_source ) ? $clone_source : array();
			$clone_source['git_worktrees'] = $worktrees;
		}

		return new self(
			id: (string) ( $record['slug'] ?? '' ),
			name: (string) ( $record['name'] ?? '' ),
			path: (string) ( $record['path'] ?? '' ),
			created_at: (string) ( $record['created_at'] ?? '' ),
			template: (string) ( $record['template'] ?? 'blank' ),
			status: (string) ( $record['status'] ?? 'active' ),
			clone_source: $clone_source,
			multisite: ! empty( $record['multisite'] ),
			engine: (string) ( $record['engine'] ?? 'mysql' ),
			blog_id: isset( $record['blog_id'] ) ? (int) $record['blog_id'] : null,
			type: (string) ( $record['type'] ?? 'sandbox' ),
			domains: ! empty( $domains ) ? array_values( $domains ) : null,
			owner: self::string_or_null( $record['owner'] ?? null ),
			labels: self::normalize_labels( self::json_array_or_null( $record['labels'] ?? null ) ?? array() ),
			purpose: self::string_or_null( $record['purpose'] ?? null ),
			is_protected: ! empty( $record['is_protected'] ),
			expires_at: self::string_or_null( $record['expires_at'] ?? null ),
			last_used_at: self::string_or_null( $record['last_used_at'] ?? ( $record['created_at'] ?? null ) ),
			source_environment_id: self::string_or_null( $record['source_environment_slug'] ?? null ),
			source_environment_type: self::string_or_null( $record['source_environment_type'] ?? null ),
			last_deployed_from_id: self::string_or_null( $record['last_deployed_from_slug'] ?? null ),
			last_deployed_from_type: self::string_or_null( $record['last_deployed_from_type'] ?? null ),
			last_deployed_at: self::string_or_null( $record['last_deployed_at'] ?? null ),
			tracked_github_repo: self::string_or_null( $record['tracked_github_repo'] ?? null ),
			tracked_github_branch: self::string_or_null( $record['tracked_github_branch'] ?? null ),
			tracked_github_dir: self::string_or_null( $record['tracked_github_dir'] ?? null ),
			record_id: isset( $record['id'] ) ? (int) $record['id'] : null,
			app_record_id: isset( $record['app_id'] ) ? (int) $record['app_id'] : null,
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
	 * Check if this is an app (permanent environment).
	 *
	 * @return bool True if app.
	 */
	public function is_app(): bool {
		return 'app' === $this->type;
	}

	/**
	 * Whether automated cleanup must skip this environment.
	 *
	 * @return bool
	 */
	public function is_protected(): bool {
		return $this->is_protected;
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
		return self::table_prefix_for_id( $this->id );
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
		if ( $this->is_app() && ! empty( $this->domains ) ) {
			return 'https://' . $this->domains[0] . '/';
		}
		if ( defined( 'WP_HOME' ) ) {
			return rtrim( WP_HOME, '/' ) . '/' . RUDEL_PATH_PREFIX . '/' . $this->id . '/';
		}
		return '/' . RUDEL_PATH_PREFIX . '/' . $this->id . '/';
	}

	/**
	 * Get the git branch name for this environment.
	 *
	 * @return string Branch name in rudel/{id} format.
	 */
	public function get_git_branch(): string {
		return 'rudel/' . $this->id;
	}

	/**
	 * Get the GitHub repository associated with this environment, if any.
	 *
	 * @return string|null GitHub repo in owner/repo format, or null.
	 */
	public function get_github_repo(): ?string {
		return $this->clone_source['github_repo'] ?? $this->tracked_github_repo;
	}

	/**
	 * Get the wp-content subdirectory associated with this environment's GitHub workflow, if any.
	 *
	 * @return string|null Relative directory path, or null for all of wp-content.
	 */
	public function get_github_dir(): ?string {
		return $this->clone_source['github_dir'] ?? $this->tracked_github_dir;
	}

	/**
	 * Get the base branch this environment treats as its deployed mainline, if any.
	 *
	 * @return string|null Branch name, or null when the repository default branch should be used.
	 */
	public function get_github_base_branch(): ?string {
		return $this->clone_source['github_base_branch'] ?? $this->tracked_github_branch;
	}

	/**
	 * Return the timestamp cleanup policies should treat as last activity.
	 *
	 * @return string|null
	 */
	public function last_activity_at(): ?string {
		return $this->last_used_at ?? $this->created_at;
	}

	/**
	 * Update one environment field and persist it through the runtime store.
	 *
	 * @param string $key   Field name.
	 * @param mixed  $value Value to set.
	 * @return void
	 */
	public function update_meta( string $key, $value ): void {
		$this->update_meta_batch(
			array(
				$key => $value,
			)
		);
	}

	/**
	 * Update multiple environment fields in one write.
	 *
	 * @param array<string, mixed> $changes Field changes.
	 * @return void
	 */
	public function update_meta_batch( array $changes ): void {
		if ( empty( $changes ) ) {
			return;
		}

		Hooks::action(
			'rudel_before_environment_update_meta',
			array(
				'environment' => $this,
				'changes'     => $changes,
			)
		);

		$repository = new EnvironmentRepository( RudelDatabase::for_paths( dirname( $this->path ) ), dirname( $this->path ) );
		$repository->update_fields( $this->id, $changes, $this->type );

		Hooks::action(
			'rudel_after_environment_update_meta',
			array(
				'environment' => $this,
				'changes'     => $changes,
			)
		);
	}

	/**
	 * Record environment activity without rewriting metadata on every request.
	 *
	 * @param int $minimum_interval Minimum seconds between metadata writes.
	 * @return void
	 */
	public function touch_last_used( int $minimum_interval = 3600 ): void {
		$last_used = $this->last_activity_at();
		$last_seen = is_string( $last_used ) ? strtotime( $last_used ) : false;

		if ( false !== $last_seen && ( time() - $last_seen ) < $minimum_interval ) {
			return;
		}

		$this->update_meta_batch(
			array(
				'last_used_at' => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Convert sandbox to an associative array.
	 *
	 * @return array<string, mixed> Sandbox data.
	 */
	public function to_array(): array {
		$data = array(
			'record_id'     => $this->record_id,
			'app_record_id' => $this->app_record_id,
			'id'            => $this->id,
			'name'          => $this->name,
			'path'          => $this->path,
			'created_at'    => $this->created_at,
			'template'      => $this->template,
			'status'        => $this->status,
			'engine'        => $this->engine,
			'type'          => $this->type,
			'protected'     => $this->is_protected,
			'labels'        => $this->labels,
			'last_used_at'  => $this->last_activity_at(),
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

		if ( null !== $this->domains && ! empty( $this->domains ) ) {
			$data['domains'] = $this->domains;
		}

		if ( null !== $this->owner ) {
			$data['owner'] = $this->owner;
		}

		if ( null !== $this->purpose ) {
			$data['purpose'] = $this->purpose;
		}

		if ( null !== $this->expires_at ) {
			$data['expires_at'] = $this->expires_at;
		}

		if ( null !== $this->source_environment_id ) {
			$data['source_environment_id'] = $this->source_environment_id;
		}

		if ( null !== $this->source_environment_type ) {
			$data['source_environment_type'] = $this->source_environment_type;
		}

		if ( null !== $this->last_deployed_from_id ) {
			$data['last_deployed_from_id'] = $this->last_deployed_from_id;
		}

		if ( null !== $this->last_deployed_from_type ) {
			$data['last_deployed_from_type'] = $this->last_deployed_from_type;
		}

		if ( null !== $this->last_deployed_at ) {
			$data['last_deployed_at'] = $this->last_deployed_at;
		}

		if ( null !== $this->tracked_github_repo ) {
			$data['tracked_github_repo'] = $this->tracked_github_repo;
		}

		if ( null !== $this->tracked_github_branch ) {
			$data['tracked_github_branch'] = $this->tracked_github_branch;
		}

		if ( null !== $this->tracked_github_dir ) {
			$data['tracked_github_dir'] = $this->tracked_github_dir;
		}

		return $data;
	}

	/**
	 * Persist the current environment record.
	 *
	 * @return void
	 */
	public function save_meta(): void {
		$repository = new EnvironmentRepository( RudelDatabase::for_paths( dirname( $this->path ) ), dirname( $this->path ) );
		$repository->save( $this );
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
	 * Build the deterministic table prefix for an environment ID.
	 *
	 * @param string $id Environment identifier.
	 * @return string Table prefix string.
	 */
	public static function table_prefix_for_id( string $id ): string {
		return 'rudel_' . substr( md5( $id ), 0, 6 ) . '_';
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

	/**
	 * Normalize labels read from metadata.
	 *
	 * @param mixed $labels Raw labels value.
	 * @return array<int, string>
	 */
	private static function normalize_labels( $labels ): array {
		if ( ! is_array( $labels ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $labels as $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}

			$label = trim( (string) $label );
			if ( '' !== $label ) {
				$normalized[] = $label;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize nullable string metadata.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return string|null
	 */
	private static function string_or_null( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Decode an optional JSON array string.
	 *
	 * @param mixed $value Raw DB value.
	 * @return array<string, mixed>|array<int, mixed>|null
	 */
	private static function json_array_or_null( $value ): ?array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
