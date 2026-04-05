<?php
/**
 * Environment CRUD orchestrator.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Creates, lists, retrieves, and destroys Rudel environments.
 */
class EnvironmentManager {

	/**
	 * Absolute path to the sandboxes directory.
	 *
	 * @var string
	 */
	private string $environments_dir;

	/**
	 * Absolute path to the related environments directory for cross-type clones.
	 *
	 * @var string
	 */
	private string $alternate_environments_dir;

	/**
	 * Absolute path to the Rudel plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Environment metadata repository.
	 *
	 * @var EnvironmentRepository
	 */
	private EnvironmentRepository $repository;

	/**
	 * Cleanup orchestration for this environment type.
	 *
	 * @var EnvironmentCleanupService
	 */
	private EnvironmentCleanupService $cleanup_service;

	/**
	 * Destructive state replacement helper.
	 *
	 * @var EnvironmentStateReplacer
	 */
	private EnvironmentStateReplacer $state_replacer;

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * Managed environment type.
	 *
	 * @var string
	 */
	private string $managed_type;

	/**
	 * Initialize dependencies.
	 *
	 * @param string|null        $environments_dir Optional override for the environments directory.
	 * @param string|null        $alternate_environments_dir Optional override for the related environments directory.
	 * @param string             $managed_type Managed environment type.
	 * @param DatabaseStore|null $store Optional runtime store override.
	 */
	public function __construct(
		?string $environments_dir = null,
		?string $alternate_environments_dir = null,
		string $managed_type = 'sandbox',
		?DatabaseStore $store = null
	) {
		$this->plugin_dir       = defined( 'RUDEL_PLUGIN_DIR' ) ? RUDEL_PLUGIN_DIR : dirname( __DIR__ ) . '/';
		$this->environments_dir = $environments_dir ?? $this->get_default_environments_dir();
		$this->managed_type     = $managed_type;

		if ( null !== $alternate_environments_dir ) {
			$this->alternate_environments_dir = $alternate_environments_dir;
		} elseif ( $this->get_default_apps_dir() === $this->environments_dir ) {
			$this->alternate_environments_dir = $this->get_default_environments_dir();
		} else {
			$this->alternate_environments_dir = $this->get_default_apps_dir();
		}

		$this->store           = $store ?? RudelDatabase::for_paths( $this->environments_dir, $this->alternate_environments_dir );
		$this->repository      = new EnvironmentRepository( $this->store, $this->environments_dir, $this->managed_type );
		$this->cleanup_service = new EnvironmentCleanupService( $this->repository, array( $this, 'destroy' ) );
		$this->state_replacer  = new EnvironmentStateReplacer();
	}

	/**
	 * Create a new environment.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $options Optional settings (template, etc.).
	 * @return Environment The newly created environment.
	 *
	 * @throws \RuntimeException If the directory already exists or creation fails.
	 * @throws \InvalidArgumentException If conflicting clone options are provided.
	 * @throws \Throwable If any step after directory creation fails (directory is cleaned up).
	 */
	public function create( string $name, array $options = array() ): Environment {
		$options = Hooks::filter( 'rudel_environment_create_options', $options, $name, $this );
		$context = array(
			'name'                       => $name,
			'options'                    => $options,
			'environments_dir'           => $this->environments_dir,
			'alternate_environments_dir' => $this->alternate_environments_dir,
		);
		Hooks::action( 'rudel_before_environment_create', $context );

		$id            = null;
		$path          = null;
		$engine        = 'subsite';
		$blog_id       = null;
		$git_worktrees = array();
		$site_options  = $this->normalize_site_options( $options['site_options'] ?? array() );
		$created_at    = gmdate( 'c' );
		$config        = new RudelConfig();

		try {
			if ( empty( $options['skip_limits'] ) ) {
				$this->check_limits();
			}

			$id   = Environment::generate_id( $name );
			$path = $this->repository->path_for( $id );

			if ( is_dir( $path ) ) {
				throw new \RuntimeException( sprintf( 'Environment directory already exists: %s', $path ) );
			}

			$clone_from     = $options['clone_from'] ?? null;
			$clone_db       = ! empty( $options['clone_db'] );
			$clone_themes   = ! empty( $options['clone_themes'] );
			$clone_plugins  = ! empty( $options['clone_plugins'] );
			$clone_uploads  = ! empty( $options['clone_uploads'] );
			$has_clone      = $clone_db || $clone_themes || $clone_plugins || $clone_uploads;
			$target_type    = $options['type'] ?? 'sandbox';
			$target_domains = $options['domains'] ?? null;

			if ( $clone_from && $has_clone ) {
				throw new \InvalidArgumentException( 'Cannot combine --clone-from with --clone-db, --clone-themes, --clone-plugins, or --clone-uploads.' );
			}

			if ( ! in_array( $target_type, array( 'sandbox', 'app' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid environment type: %s. Must be "sandbox" or "app".', $target_type ) );
			}

			if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
				throw new \RuntimeException( 'Rudel requires a WordPress multisite installation.' );
			}

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for environment scaffolding.
			if ( ! is_dir( $this->environments_dir ) ) {
				mkdir( $this->environments_dir, 0755, true );
			}

			if ( ! mkdir( $path, 0755 ) ) {
				throw new \RuntimeException( sprintf( 'Failed to create environment directory: %s', $path ) );
			}

			mkdir( $path . '/wp-content', 0755 );
			mkdir( $path . '/wp-content/themes', 0755 );
			mkdir( $path . '/wp-content/plugins', 0755 );
			mkdir( $path . '/wp-content/uploads', 0755 );
			mkdir( $path . '/wp-content/mu-plugins', 0755 );
			mkdir( $path . '/tmp', 0755 );
			// phpcs:enable

			$this->write_environment_bootstrap( $id, $path );
			$this->write_wp_cli_yml( $path, $id );
			$this->write_claude_md( $id, $name, $path );

			$clone_source     = null;
			$clone_lineage    = array();
			$is_multisite     = true;
			$template         = $options['template'] ?? ( $has_clone || $clone_from ? 'clone' : 'blank' );
			$is_from_template = ! in_array( $template, array( 'blank', 'clone' ), true )
				&& ! $clone_from && ! $has_clone
				&& $this->template_exists( $template );
			$subsite_cloner   = new SubsiteCloner();
			$blog_id          = $subsite_cloner->create_subsite( $id, $name );
			$target_url       = $this->get_target_environment_url( $id, $blog_id );

			if ( $clone_from ) {
				$source = $this->resolve_clone_source_environment( $clone_from );
				if ( ! $source ) {
					throw new \RuntimeException( sprintf( 'Source environment not found: %s', $clone_from ) );
				}
				if ( ! $source->is_subsite() ) {
					throw new \InvalidArgumentException( 'Subsite environments can only clone from other subsite environments.' );
				}
				if ( ! isset( $options['app_id'] ) && null !== $source->app_record_id && 'sandbox' === $target_type ) {
					$options['app_id'] = $source->app_record_id;
				}

				$clone_source  = $this->clone_from_subsite_environment( $source, $id, $path, $blog_id );
				$clone_lineage = array(
					'source_environment_id'   => $source->id,
					'source_environment_type' => $source->type,
				);
			} elseif ( $clone_db ) {
				$clone_result = $subsite_cloner->clone_host_db_to_subsite( $blog_id );
				$clone_source = $this->build_clone_source(
					$this->get_host_site_url(),
					true,
					$clone_themes,
					$clone_plugins,
					$clone_uploads,
					array(
						'tables_cloned' => $clone_result['tables_cloned'],
						'rows_cloned'   => $clone_result['rows_cloned'],
						'multisite'     => true,
						'target_url'    => $target_url,
					)
				);
			}

			if ( $is_from_template ) {
				$this->initialize_from_template( $template, $id, $path );
			}

			if ( $has_clone && ! $clone_source ) {
				$clone_source = $this->build_clone_source(
					$this->get_host_site_url(),
					false,
					$clone_themes,
					$clone_plugins,
					$clone_uploads,
					array(
						'multisite'  => true,
						'target_url' => $target_url,
					)
				);
			}

			if ( $clone_themes || $clone_plugins || $clone_uploads ) {
				$content_cloner  = new ContentCloner();
				$content_results = $content_cloner->clone_content(
					$path . '/wp-content',
					array(
						'themes'  => $clone_themes,
						'plugins' => $clone_plugins,
						'uploads' => $clone_uploads,
					),
					$id
				);

				$git_worktrees = array();
				foreach ( $content_results as $dir => $result ) {
					if ( is_array( $result ) && ! empty( $result['worktrees'] ) ) {
						foreach ( $result['worktrees'] as $repo_name => $branch ) {
							$git_worktrees[] = array(
								'type'   => $dir,
								'name'   => $repo_name,
								'branch' => $branch,
								'repo'   => $this->get_host_wp_content_dir() . '/' . $dir . '/' . $repo_name,
							);
						}
					}
				}
			}

			$this->apply_site_options( $id, $path, $blog_id, $site_options );

			$this->write_runtime_mu_plugin( $path );

			if ( ! empty( $git_worktrees ) && null !== $clone_source ) {
				$clone_source['git_worktrees'] = $git_worktrees;
			}

			$policy_meta = EnvironmentPolicy::metadata_for_create(
				array_merge( $options, $clone_lineage ),
				$target_type,
				$created_at,
				$config
			);

			$environment = new Environment(
				id: $id,
				name: $name,
				path: $path,
				created_at: $created_at,
				template: $template,
				status: 'active',
				clone_source: $clone_source,
				multisite: $is_multisite,
				engine: $engine,
				blog_id: $blog_id,
				type: $target_type,
				domains: $target_domains,
				owner: $policy_meta['owner'],
				labels: $policy_meta['labels'],
				purpose: $policy_meta['purpose'],
				is_protected: $policy_meta['protected'],
				expires_at: $policy_meta['expires_at'],
				last_used_at: $policy_meta['last_used_at'],
				source_environment_id: $policy_meta['source_environment_id'] ?? null,
				source_environment_type: $policy_meta['source_environment_type'] ?? null,
				last_deployed_from_id: $policy_meta['last_deployed_from_id'] ?? null,
				last_deployed_from_type: $policy_meta['last_deployed_from_type'] ?? null,
				last_deployed_at: $policy_meta['last_deployed_at'] ?? null,
				tracked_github_repo: $policy_meta['tracked_github_repo'] ?? null,
				tracked_github_branch: $policy_meta['tracked_github_branch'] ?? null,
				tracked_github_dir: $policy_meta['tracked_github_dir'] ?? null,
				app_record_id: isset( $options['app_id'] ) ? (int) $options['app_id'] : null,
			);
			$environment = $this->repository->save( $environment );

			Hooks::action( 'rudel_after_environment_create', $environment, $context );

			return $environment;
		} catch ( \Throwable $e ) {
			if ( $blog_id ) {
				$subsite_cloner = new SubsiteCloner();
				$subsite_cloner->delete_subsite( $blog_id );
			}
			if ( is_string( $path ) && is_dir( $path ) ) {
				$this->delete_directory( $path );
			}

			Hooks::action( 'rudel_environment_create_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * List all environments.
	 *
	 * @return Environment[] Array of environment instances.
	 */
	public function list(): array {
		return $this->repository->all();
	}

	/**
	 * Get a single environment by ID.
	 *
	 * @param string $id Environment identifier.
	 * @return Environment|null Environment instance or null if not found.
	 */
	public function get( string $id ): ?Environment {
		return $this->repository->get( $id );
	}

	/**
	 * Update environment metadata and return the refreshed environment.
	 *
	 * @param string $id      Environment identifier.
	 * @param array  $changes Metadata changes.
	 * @return Environment
	 *
	 * @throws \RuntimeException If the environment is not found.
	 * @throws \Throwable If the update fails after lifecycle hooks begin.
	 */
	public function update( string $id, array $changes ): Environment {
		$environment = $this->get( $id );
		if ( ! $environment ) {
			throw new \RuntimeException( sprintf( 'Environment not found: %s', $id ) );
		}

		$site_options = $this->normalize_site_options( $changes['site_options'] ?? array() );
		unset( $changes['site_options'] );

		$changes = EnvironmentPolicy::normalize_changes( $changes, $environment->type );
		$context = array(
			'environment' => $environment,
			'changes'     => $changes,
		);
		Hooks::action( 'rudel_before_environment_update', $context );

		try {
			$this->apply_site_options(
				$environment->id,
				$environment->path,
				$environment->blog_id,
				$site_options
			);
			$updated = $this->repository->update_fields( $id, $changes, $environment->type );
			Hooks::action( 'rudel_after_environment_update', $updated, $context );
			return $updated;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_update_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Destroy an environment by ID.
	 *
	 * @param string $id Environment identifier.
	 * @return bool True on success.
	 *
	 * @throws \Throwable If destruction fails after lifecycle hooks begin.
	 */
	public function destroy( string $id ): bool {
		$environment = $this->get( $id );
		if ( ! $environment ) {
			return false;
		}

		$context = array(
			'environment' => $environment,
		);
		Hooks::action( 'rudel_before_environment_destroy', $context );

		try {
			if ( $environment->is_subsite() && $environment->blog_id ) {
				$subsite_cloner = new SubsiteCloner();
				$subsite_cloner->delete_subsite( $environment->blog_id );
			}

			$result = $this->delete_directory( $environment->path );
			if ( $result ) {
				$this->repository->delete( $environment->id, $environment->type );
				Hooks::action( 'rudel_after_environment_destroy', $context );
			}

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_destroy_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Replace one environment's runtime state with another's.
	 *
	 * @param Environment $source Source environment.
	 * @param Environment $target Target environment.
	 * @return array{source_id: string, target_id: string, tables_copied: int}
	 *
	 * @throws \Throwable If validation or replacement fails after lifecycle hooks begin.
	 */
	public function replace_environment_state( Environment $source, Environment $target ): array {
		$context = array(
			'source' => $source,
			'target' => $target,
		);
		Hooks::action( 'rudel_before_environment_replace_state', $context );

		try {
			$result = $this->state_replacer->replace( $source, $target );
			$this->write_runtime_mu_plugin( $target->path );

			Hooks::action( 'rudel_after_environment_replace_state', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_environment_replace_state_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Configured environments directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_environments_dir(): string {
		return $this->repository->environments_dir();
	}

	/**
	 * Clean up expired environments.
	 *
	 * @param array $options Options: 'dry_run' (bool), 'max_age_days' (int override), 'max_idle_days' (int override).
	 * @return array{removed: string[], skipped: string[], errors: string[], reasons?: array<string, string>} Cleanup results.
	 */
	public function cleanup( array $options = array() ): array {
		return $this->cleanup_service->cleanup( $options );
	}

	/**
	 * Clean up environments whose git branches have been merged.
	 *
	 * @param array $options Options: 'dry_run' (bool).
	 * @return array{removed: string[], skipped: string[], errors: string[], reasons?: array<string, string>} Cleanup results.
	 */
	public function cleanup_merged( array $options = array() ): array {
		return $this->cleanup_service->cleanup_merged( $options );
	}

	/**
	 * Host site URL without a trailing slash.
	 *
	 * @return string Host site URL.
	 */
	private function get_host_site_url(): string {
		$scheme = 'http';
		$host   = 'localhost';
		$port   = null;

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation without requiring full WP helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : $scheme;
				$host   = isset( $parts['host'] ) ? (string) $parts['host'] : $host;
				$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
			}
		}

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$network_host = preg_replace( '/:\d+$/', '', (string) DOMAIN_CURRENT_SITE );
			if ( is_string( $network_host ) && '' !== $network_host ) {
				$host = $network_host;
			}
		}

		$url = $scheme . '://' . $host;
		if ( null !== $port ) {
			$url .= ':' . $port;
		}

		return rtrim( $url, '/' );
	}

	/**
	 * Build clone metadata for a new environment.
	 *
	 * @param string $host_url       Source host URL.
	 * @param bool   $db_cloned      Whether the database was cloned.
	 * @param bool   $themes_cloned  Whether themes were cloned.
	 * @param bool   $plugins_cloned Whether plugins were cloned.
	 * @param bool   $uploads_cloned Whether uploads were cloned.
	 * @param array  $extra          Additional metadata to merge into the clone record.
	 * @return array<string, mixed> Clone metadata payload.
	 */
	private function build_clone_source(
		string $host_url,
		bool $db_cloned,
		bool $themes_cloned,
		bool $plugins_cloned,
		bool $uploads_cloned,
		array $extra = array()
	): array {
		return Hooks::filter(
			'rudel_environment_clone_source',
			array_merge(
				array(
					'host_url'       => $host_url,
					'cloned_at'      => gmdate( 'c' ),
					'db_cloned'      => $db_cloned,
					'themes_cloned'  => $themes_cloned,
					'plugins_cloned' => $plugins_cloned,
					'uploads_cloned' => $uploads_cloned,
				),
				$extra
			),
			$host_url,
			$db_cloned,
			$themes_cloned,
			$plugins_cloned,
			$uploads_cloned,
			$extra
		);
	}

	/**
	 * Resolve an environment by checking both the current and related environment directories.
	 *
	 * @param string $id Environment ID.
	 * @return Environment|null
	 */
	private function resolve_clone_source_environment( string $id ): ?Environment {
		return $this->repository->resolve( $id );
	}

	/**
	 * Build the runtime URL used inside an environment's database.
	 *
	 * @param Environment $environment Environment whose canonical URL is needed.
	 * @return string
	 */
	private function get_environment_site_url( Environment $environment ): string {
		return rtrim( Environment::multisite_url_for( $environment->id, $environment->blog_id ), '/' );
	}

	/**
	 * Build the target runtime URL for a not-yet-saved environment.
	 *
	 * @param string $id Environment ID.
	 * @param int    $blog_id Target multisite blog ID.
	 * @return string
	 */
	private function get_target_environment_url(
		string $id,
		int $blog_id
	): string {
		return rtrim( Environment::multisite_url_for( $id, $blog_id ), '/' );
	}

	/**
	 * Check configured limits before creating a new environment.
	 *
	 * @param RudelConfig|null $config Optional config instance for testing.
	 * @return void
	 *
	 * @throws \RuntimeException If a limit is exceeded.
	 */
	public function check_limits( ?RudelConfig $config = null ): void {
		$config        = $config ?? new RudelConfig();
		$max_sandboxes = $config->get( 'max_sandboxes' );
		$max_disk_mb   = $config->get( 'max_disk_mb' );
		$environments  = $this->repository->all();

		if ( $max_sandboxes > 0 ) {
			$count = count( $environments );
			if ( $count >= $max_sandboxes ) {
				throw new \RuntimeException(
					sprintf( 'Sandbox limit reached: %d of %d', $count, $max_sandboxes )
				);
			}
		}

		if ( $max_disk_mb > 0 ) {
			$total_bytes = 0;
			foreach ( $environments as $environment ) {
				$total_bytes += $environment->get_size();
			}
			$total_mb = $total_bytes / ( 1024 * 1024 );
			if ( $total_mb >= $max_disk_mb ) {
				throw new \RuntimeException(
					sprintf( 'Disk limit reached: %.1f MB of %d MB', $total_mb, $max_disk_mb )
				);
			}
		}
	}

	/**
	 * Determine the default environments directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_environments_dir(): string {
		if ( defined( 'RUDEL_ENVIRONMENTS_DIR' ) ) {
			return RUDEL_ENVIRONMENTS_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-environments';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-environments';
	}

	/**
	 * Determine the default apps directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_apps_dir(): string {
		if ( defined( 'RUDEL_APPS_DIR' ) ) {
			return RUDEL_APPS_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-apps';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-apps';
	}

	/**
	 * WordPress core path Rudel boots against.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	private function get_wp_core_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' );
		}
		return dirname( __DIR__, 3 );
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
		return $this->get_wp_core_path() . '/wp-content';
	}

	/**
	 * Write the per-environment bootstrap.php.
	 *
	 * @param string $id   Environment identifier.
	 * @param string $path Absolute path to the environment directory.
	 * @return void
	 */
	private function write_environment_bootstrap( string $id, string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/environment-bootstrap.php.tpl' );

		$content        = strtr(
			$template,
			array(
				'{{sandbox_id}}'   => $id,
				'{{sandbox_path}}' => $path,
			)
		);
		$bootstrap_path = $path . '/bootstrap.php';
		if ( file_exists( $bootstrap_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Allow refreshing the generated bootstrap before locking it again.
			chmod( $bootstrap_path, 0644 );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing environment bootstrap.
		file_put_contents( $bootstrap_path, $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $bootstrap_path, 0444 );
	}

	/**
	 * Write the per-environment wp-cli.yml.
	 *
	 * @param string $path Absolute path to the environment directory.
	 * @param string $id   Environment identifier.
	 * @return void
	 */
	private function write_wp_cli_yml( string $path, string $id = '' ): void {
		$url     = Environment::multisite_url_for( $id );
		$content = 'path: ' . $this->get_wp_core_path() . "\n"
			. 'url: ' . $url . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing environment wp-cli.yml.
		file_put_contents( $path . '/wp-cli.yml', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/wp-cli.yml', 0444 );
	}

	/**
	 * Write the per-environment CLAUDE.md.
	 *
	 * @param string $id   Environment identifier.
	 * @param string $name Human-readable name.
	 * @param string $path Absolute path to the environment directory.
	 * @return void
	 */
	private function write_claude_md( string $id, string $name, string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/CLAUDE.md.tpl' );
		$content  = strtr(
			$template,
			array(
				'{{sandbox_id}}'   => $id,
				'{{sandbox_name}}' => $name,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing environment CLAUDE.md.
		file_put_contents( $path . '/CLAUDE.md', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/CLAUDE.md', 0444 );
	}

	/**
	 * Write the per-environment MU plugin with runtime hooks that must always load.
	 *
	 * @param string $path Absolute path to the environment directory.
	 * @return void
	 */
	private function write_runtime_mu_plugin( string $path ): void {
		if ( ! is_dir( $path . '/wp-content/mu-plugins' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Ensuring MU plugin directory exists after content copy.
			mkdir( $path . '/wp-content/mu-plugins', 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/runtime-mu-plugin.php.tpl' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing runtime MU plugin.
		file_put_contents( $path . '/wp-content/mu-plugins/rudel-runtime.php', $template );
	}

	/**
	 * Normalize requested site options before they are written into one environment database.
	 *
	 * @param mixed $site_options Raw site option map.
	 * @return array<string, string|null>
	 *
	 * @throws \InvalidArgumentException If the payload is not a flat scalar map.
	 */
	private function normalize_site_options( $site_options ): array {
		if ( null === $site_options || array() === $site_options ) {
			return array();
		}

		if ( ! is_array( $site_options ) ) {
			throw new \InvalidArgumentException( 'site_options must be an associative array.' );
		}

		$normalized = array();
		foreach ( $site_options as $name => $value ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9_:-]+$/', $name ) ) {
				throw new \InvalidArgumentException( 'site_options keys must be valid option names.' );
			}

			if ( null !== $value && ! is_scalar( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'site_options[%s] must be scalar or null.', $name ) );
			}

			$normalized[ $name ] = null === $value ? null : (string) $value;
		}

		return $normalized;
	}

	/**
	 * Apply requested site options into one environment database.
	 *
	 * @param string                     $id Environment identifier.
	 * @param string                     $path Absolute environment path.
	 * @param int|string|null            $blog_id Optional multisite blog identifier.
	 * @param array<string, string|null> $site_options Site option overrides.
	 * @return void
	 */
	private function apply_site_options( string $id, string $path, $blog_id, array $site_options ): void {
		if ( array() === $site_options ) {
			return;
		}

		$this->apply_mysql_site_options( $id, $blog_id, $site_options );
	}

	/**
	 * Apply site options inside a MySQL- or subsite-backed environment.
	 *
	 * @param string                     $id Environment identifier.
	 * @param int|string|null            $blog_id Optional multisite blog identifier.
	 * @param array<string, string|null> $site_options Site option overrides.
	 * @throws \RuntimeException When the host WordPress database connection is unavailable.
	 * @return void
	 */
	private function apply_mysql_site_options( string $id, $blog_id, array $site_options ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			throw new \RuntimeException( 'Applying MySQL-backed site options requires a running WordPress database connection.' );
		}

		$table_prefix = $wpdb->base_prefix . (int) $blog_id . '_';

		$table = $table_prefix . 'options';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Preview environments need direct writes against isolated options tables with runtime-resolved table names.
		foreach ( $site_options as $option_name => $option_value ) {
			$exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_id FROM `{$table}` WHERE option_name = %s LIMIT 1",
					$option_name
				)
			);

			if ( null === $option_value ) {
				if ( $exists ) {
					$wpdb->delete( $table, array( 'option_name' => $option_name ) );
				}
				continue;
			}

			if ( $exists ) {
				$wpdb->update(
					$table,
					array( 'option_value' => $option_value ),
					array( 'option_name' => $option_name )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'option_name'  => $option_name,
						'option_value' => $option_value,
						'autoload'     => 'yes',
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Check if a template exists by name.
	 *
	 * @param string $name Template name.
	 * @return bool True if the template directory exists.
	 */
	private function template_exists( string $name ): bool {
		$tpl_manager = new TemplateManager();
		return is_dir( $tpl_manager->get_templates_dir() . '/' . $name );
	}

	/**
	 * Initialize a sandbox from a template by copying its runtime content shell.
	 *
	 * @param string $template_name Template name.
	 * @param string $target_id     New sandbox ID.
	 * @param string $target_path   New sandbox directory path.
	 * @return void
	 *
	 * @throws \RuntimeException If the template is not found or initialization fails.
	 */
	private function initialize_from_template( string $template_name, string $target_id, string $target_path ): void {
		$tpl_manager   = new TemplateManager();
		$template_path = $tpl_manager->get_template_path( $template_name );

		$meta_file = $template_path . '/template.json';
		if ( ! file_exists( $meta_file ) ) {
			throw new \RuntimeException( sprintf( 'Template metadata not found: %s', $template_name ) );
		}

		// The initial scaffold only exists to boot the environment; once a template is chosen, its content should be authoritative.
		$template_content = $template_path . '/wp-content';
		if ( is_dir( $template_content ) ) {
			$this->delete_directory( $target_path . '/wp-content' );
			$content_cloner = new ContentCloner();
			$content_cloner->copy_directory( $template_content, $target_path . '/wp-content' );
		}
	}

	/**
	 * Clone one subdomain-multisite environment into another subdomain-multisite site.
	 *
	 * @param Environment $source Source environment.
	 * @param string      $target_id Target environment slug.
	 * @param string      $target_path Target environment path.
	 * @param int         $target_blog_id Target multisite blog ID.
	 * @return array<string, mixed>
	 */
	private function clone_from_subsite_environment(
		Environment $source,
		string $target_id,
		string $target_path,
		int $target_blog_id
	): array {
		global $wpdb;

		$source_prefix = $source->get_table_prefix();
		$target_prefix = $wpdb->base_prefix . $target_blog_id . '_';
		$source_url    = $this->get_environment_site_url( $source );
		$target_url    = $this->get_target_environment_url( $target_id, $target_blog_id );

		$mysql_cloner = new MySQLCloner();
		$tables       = $mysql_cloner->copy_tables( $source_prefix, $target_prefix, array( $target_prefix . 'snap_' ) );
		$mysql_cloner->rewrite_urls( $wpdb, $target_prefix, $source_url, $target_url );
		$mysql_cloner->rewrite_table_prefix_in_data( $wpdb, $target_prefix, $source_prefix, $target_prefix );

		$content_clone = $this->clone_environment_content( $source, $target_id, $target_path );

		return Hooks::filter(
			'rudel_environment_clone_source',
			array(
				'host_url'              => $source_url,
				'cloned_at'             => gmdate( 'c' ),
				'db_cloned'             => true,
				'themes_cloned'         => ! empty( $content_clone['themes_cloned'] ),
				'plugins_cloned'        => ! empty( $content_clone['plugins_cloned'] ),
				'uploads_cloned'        => ! empty( $content_clone['uploads_cloned'] ),
				'tables_cloned'         => $tables,
				'multisite'             => true,
				'target_url'            => $target_url,
				'git_worktrees'         => $content_clone['git_worktrees'],
				'source_environment_id' => $source->id,
				'source_type'           => $source->type,
			),
			$source_url,
			true,
			! empty( $content_clone['themes_cloned'] ),
			! empty( $content_clone['plugins_cloned'] ),
			! empty( $content_clone['uploads_cloned'] ),
			array(
				'tables_cloned'         => $tables,
				'multisite'             => true,
				'target_url'            => $target_url,
				'git_worktrees'         => $content_clone['git_worktrees'],
				'source_environment_id' => $source->id,
				'source_type'           => $source->type,
			)
		);
	}

	/**
	 * Clone wp-content from another environment while preserving git worktrees for code directories.
	 *
	 * @param Environment $source Source environment.
	 * @param string      $target_id New environment identifier.
	 * @param string      $target_path New environment path.
	 * @return array{git_worktrees: array<int, array{type:string,name:string,branch:string,repo:string}>}
	 */
	private function clone_environment_content( Environment $source, string $target_id, string $target_path ): array {
		$source_content = $source->get_wp_content_path();
		$target_content = $target_path . '/wp-content';
		$content_cloner = new ContentCloner();
		$git            = new GitIntegration();
		$git_worktrees  = array();

		$this->delete_directory( $target_content );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating wp-content for cloned environments.
		mkdir( $target_content, 0755, true );

		$iterator = new \FilesystemIterator( $source_content, \FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $entry ) {
			$name            = $entry->getFilename();
			$source_path     = $entry->getPathname();
			$target_pathname = $target_content . '/' . $name;

			if ( $entry->isDir() ) {
				if ( in_array( $name, array( 'themes', 'plugins' ), true ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating top-level content directory before git worktree clone.
					mkdir( $target_pathname, 0755, true );

					$results = $git->clone_with_worktrees( $source_path, $target_pathname, $target_id );
					foreach ( $results['worktrees'] as $repo_name => $branch ) {
						$git_worktrees[] = array(
							'type'   => $name,
							'name'   => $repo_name,
							'branch' => $branch,
							'repo'   => $source_path . '/' . $repo_name,
						);
					}

					continue;
				}

				$content_cloner->copy_directory( $source_path, $target_pathname );
				continue;
			}

			$target_dir = dirname( $target_pathname );
			if ( ! is_dir( $target_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating parent directory for copied environment file.
				mkdir( $target_dir, 0755, true );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying environment content file.
			copy( $source_path, $target_pathname );
		}

		return array(
			'git_worktrees' => $git_worktrees,
		);
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return bool True on success.
	 */
	private function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isWritable() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Handling read-only generated files.
				chmod( $item->getPathname(), 0644 );
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct recursive directory removal.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file deletion during directory cleanup.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty directory.
		return rmdir( $dir );
	}
}
