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
	 * Initialize dependencies.
	 *
	 * @param string      $id           Sandbox identifier.
	 * @param string      $name         Human-readable name.
	 * @param string      $path         Absolute filesystem path.
	 * @param string      $created_at   ISO 8601 creation timestamp.
	 * @param string      $template     Template used to create this sandbox.
	 * @param string      $status       Current status (active, paused).
	 * @param array|null  $clone_source Clone source metadata, or null if not cloned.
	 * @param bool        $multisite    Whether this sandbox was cloned from a multisite host.
	 * @param string      $engine       Database engine. Rudel uses 'subsite'.
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
	 * @param string|null $tracked_git_remote       Git remote this environment tracks as its deployed code source.
	 * @param string|null $tracked_git_branch       Branch this environment treats as its stable mainline.
	 * @param string|null $tracked_git_dir          Optional wp-content subdirectory associated with the tracked repository.
	 * @param bool        $shared_plugins           Whether plugins are shared live from the host wp-content directory.
	 * @param bool        $shared_uploads           Whether uploads are shared live from the host wp-content directory.
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
		public readonly string $engine = 'subsite',
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
		public readonly ?string $tracked_git_remote = null,
		public readonly ?string $tracked_git_branch = null,
		public readonly ?string $tracked_git_dir = null,
		public readonly bool $shared_plugins = false,
		public readonly bool $shared_uploads = false,
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
			engine: (string) ( $record['engine'] ?? 'subsite' ),
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
			tracked_git_remote: self::string_or_null( $record['tracked_git_remote'] ?? null ),
			tracked_git_branch: self::string_or_null( $record['tracked_git_branch'] ?? null ),
			tracked_git_dir: self::string_or_null( $record['tracked_git_dir'] ?? null ),
			shared_plugins: ! empty( $record['shared_plugins'] ),
			shared_uploads: ! empty( $record['shared_uploads'] ),
			record_id: isset( $record['id'] ) ? (int) $record['id'] : null,
			app_record_id: isset( $record['app_id'] ) ? (int) $record['app_id'] : null,
		);
	}

	/**
	 * Whether this environment uses the subsite engine.
	 *
	 * @return bool True if subsite.
	 */
	public function is_subsite(): bool {
		return 'subsite' === $this->engine;
	}

	/**
	 * Whether this environment is an app.
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
	 * WordPress table prefix for this environment.
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
	 * Whether this environment uses isolated user tables.
	 *
	 * @return bool
	 */
	public function uses_isolated_users(): bool {
		return $this->is_subsite() && null !== $this->blog_id;
	}

	/**
	 * Isolated users table for this environment.
	 *
	 * @return string|null
	 */
	public function get_users_table(): ?string {
		if ( ! $this->uses_isolated_users() ) {
			return null;
		}

		return self::users_table_for_blog( (int) $this->blog_id );
	}

	/**
	 * Isolated usermeta table for this environment.
	 *
	 * @return string|null
	 */
	public function get_usermeta_table(): ?string {
		if ( ! $this->uses_isolated_users() ) {
			return null;
		}

		return self::usermeta_table_for_blog( (int) $this->blog_id );
	}

	/**
	 * WP content path for this environment.
	 *
	 * @return string Absolute path to wp-content.
	 */
	public function get_wp_content_path(): string {
		return $this->path . '/wp-content';
	}

	/**
	 * Runtime wp-content path for this environment.
	 *
	 * In the 0.6 isolation model an environment-local wp-content tree is the
	 * canonical code and file source for both apps and sandboxes. Host-side
	 * operator flows should act on that same tree instead of jumping back to a
	 * host-level fallback path.
	 *
	 * @return string Absolute path to the runtime wp-content directory.
	 */
	public function get_runtime_wp_content_path(): string {
		return $this->get_wp_content_path();
	}

	/**
	 * Runtime content path, optionally scoped to one wp-content subdirectory.
	 *
	 * @param string $relative Relative path within wp-content.
	 * @return string Absolute runtime path.
	 */
	public function get_runtime_content_path( string $relative = '' ): string {
		$base = $this->get_runtime_wp_content_path();
		if ( '' === $relative ) {
			return $base;
		}

		return $base . '/' . ltrim( $relative, '/' );
	}

	/**
	 * Shared top-level wp-content directories for this environment.
	 *
	 * @return array<int, string>
	 */
	public function shared_content_directories(): array {
		return EnvironmentContentLayout::shared_directories( $this );
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
	 * Public URL for this environment.
	 *
	 * @return string Canonical environment URL.
	 */
	public function get_url(): string {
		if ( $this->is_app() && ! empty( $this->domains ) ) {
			return self::domain_url( $this->domains[0] );
		}

		if ( null !== $this->blog_id || $this->is_subsite() ) {
			return self::multisite_url_for( $this->id, $this->blog_id );
		}

		return self::multisite_url_for( $this->id, null );
	}

	/**
	 * Canonical app URL for one mapped domain.
	 *
	 * @param string $domain App domain.
	 * @return string
	 */
	public static function domain_url( string $domain ): string {
		$domain = trim( $domain );
		$url    = self::network_scheme() . '://' . $domain;

		if ( ! self::domain_includes_port( $domain ) ) {
			$url .= self::network_port_suffix();
		}

		return trailingslashit( $url );
	}

	/**
	 * Build the canonical subdomain-multisite URL for one environment.
	 *
	 * @param string   $id Environment slug.
	 * @param int|null $blog_id Optional blog ID when WordPress can resolve a persisted site URL.
	 * @return string
	 */
	public static function multisite_url_for( string $id, ?int $blog_id = null ): string {
		if ( null !== $blog_id && function_exists( 'get_blog_details' ) ) {
			$details = get_blog_details( $blog_id );
			if ( $details ) {
				$site_domain = isset( $details->domain ) ? (string) $details->domain : '';
				$site_path   = isset( $details->path ) ? (string) $details->path : '/';

				if ( '' !== $site_domain ) {
					if ( '' === $site_path ) {
						$site_path = '/';
					}

					if ( ! str_starts_with( $site_path, '/' ) ) {
						$site_path = '/' . $site_path;
					}

					$site_url = self::network_scheme() . '://' . $site_domain;
					if ( ! self::domain_includes_port( $site_domain ) ) {
						$site_url .= self::network_port_suffix();
					}

					return trailingslashit( $site_url . $site_path );
				}

				if ( ! empty( $details->siteurl ) ) {
					return trailingslashit( (string) $details->siteurl );
				}
			}
		}

		$root = preg_replace( '/:\d+$/', '', self::network_host() );
		if ( ! is_string( $root ) || '' === $root ) {
			$root = 'localhost';
		}

		return trailingslashit( self::network_scheme() . '://' . $id . '.' . $root . self::network_port_suffix() );
	}

	/**
	 * Network request scheme.
	 *
	 * @return string
	 */
	private static function network_scheme(): string {
		$scheme = 'http';

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation before full WP helpers are guaranteed.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : $scheme;
			}
		} elseif ( function_exists( 'home_url' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation from the active site URL.
			$parts = parse_url( home_url( '/' ) );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : $scheme;
			}
		}

		return $scheme;
	}

	/**
	 * Network port suffix including the leading colon when present.
	 *
	 * @return string
	 */
	private static function network_port_suffix(): string {
		$port = null;

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation before full WP helpers are guaranteed.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) && isset( $parts['port'] ) ) {
				$port = (int) $parts['port'];
			}
		} elseif ( function_exists( 'home_url' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation from the active site URL.
			$parts = parse_url( home_url( '/' ) );
			if ( is_array( $parts ) && isset( $parts['port'] ) ) {
				$port = (int) $parts['port'];
			}
		}

		return null === $port ? '' : ':' . $port;
	}

	/**
	 * Whether one persisted multisite domain already includes its network port.
	 *
	 * @param string $domain Multisite site domain.
	 * @return bool
	 */
	private static function domain_includes_port( string $domain ): bool {
		return 1 === preg_match( '/:\d+$/', $domain );
	}

	/**
	 * Host name of the current multisite network without any port.
	 *
	 * @return string
	 */
	private static function network_host(): string {
		$host = 'localhost';

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation before full WP helpers are guaranteed.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) && isset( $parts['host'] ) ) {
				$host = (string) $parts['host'];
			}
		} elseif ( function_exists( 'home_url' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation from the active site URL.
			$parts = parse_url( home_url( '/' ) );
			if ( is_array( $parts ) && isset( $parts['host'] ) ) {
				$host = (string) $parts['host'];
			}
		}

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$network_host = preg_replace( '/:\d+$/', '', (string) DOMAIN_CURRENT_SITE );
			if ( is_string( $network_host ) && '' !== $network_host ) {
				$host = $network_host;
			}
		}

		return $host;
	}

	/**
	 * Branch name Rudel uses for this environment's Git workflow.
	 *
	 * @return string Branch name in rudel/{id} format.
	 */
	public function get_git_branch(): string {
		return 'rudel/' . $this->id;
	}

	/**
	 * Git remote associated with this environment, if any.
	 *
	 * @return string|null Remote URL, or null.
	 */
	public function get_git_remote(): ?string {
		return $this->clone_source['git_remote'] ?? $this->tracked_git_remote;
	}

	/**
	 * WP content subdirectory associated with this environment's tracked Git workflow, if any.
	 *
	 * @return string|null Relative directory path, or null for all of wp-content.
	 */
	public function get_git_dir(): ?string {
		return $this->clone_source['git_dir'] ?? $this->tracked_git_dir;
	}

	/**
	 * Base branch this environment treats as its deployed mainline, if any.
	 *
	 * @return string|null Branch name, or null when the repository default branch should be used.
	 */
	public function get_git_base_branch(): ?string {
		return $this->clone_source['git_base_branch'] ?? $this->tracked_git_branch;
	}

	/**
	 * Timestamp cleanup policies should treat as last activity.
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

		if ( $this->uses_isolated_users() ) {
			$data['user_scope']     = 'isolated';
			$data['users_table']    = $this->get_users_table();
			$data['usermeta_table'] = $this->get_usermeta_table();
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

		if ( null !== $this->tracked_git_remote ) {
			$data['tracked_git_remote'] = $this->tracked_git_remote;
		}

		if ( null !== $this->tracked_git_branch ) {
			$data['tracked_git_branch'] = $this->tracked_git_branch;
		}

		if ( null !== $this->tracked_git_dir ) {
			$data['tracked_git_dir'] = $this->tracked_git_dir;
		}

		$data['shared_plugins'] = $this->shared_plugins;
		$data['shared_uploads'] = $this->shared_uploads;

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
	 * Isolated users table name for one multisite blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	public static function users_table_for_blog( int $blog_id ): string {
		return self::network_base_prefix() . 'rudel_env_' . $blog_id . '_users';
	}

	/**
	 * Isolated usermeta table name for one multisite blog.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string
	 */
	public static function usermeta_table_for_blog( int $blog_id ): string {
		return self::network_base_prefix() . 'rudel_env_' . $blog_id . '_usermeta';
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

	/**
	 * Network base table prefix without any blog-specific suffix.
	 *
	 * @return string
	 */
	private static function network_base_prefix(): string {
		global $wpdb, $table_prefix;

		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix ) && '' !== $wpdb->base_prefix ) {
			return $wpdb->base_prefix;
		}

		if ( defined( 'RUDEL_TABLE_PREFIX' ) && is_string( RUDEL_TABLE_PREFIX ) && '' !== RUDEL_TABLE_PREFIX ) {
			return self::base_prefix_from_blog_prefix( RUDEL_TABLE_PREFIX );
		}

		if ( isset( $table_prefix ) && is_string( $table_prefix ) && '' !== $table_prefix ) {
			return self::base_prefix_from_blog_prefix( $table_prefix );
		}

		return 'wp_';
	}

	/**
	 * Strip a multisite blog suffix from one table prefix when present.
	 *
	 * @param string $prefix Raw table prefix.
	 * @return string
	 */
	private static function base_prefix_from_blog_prefix( string $prefix ): string {
		if ( 1 === preg_match( '/^(.*?)(\d+)_$/', $prefix, $matches ) ) {
			return (string) $matches[1];
		}

		return $prefix;
	}
}
