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
	 * Isolated-user table workflows.
	 *
	 * @var EnvironmentUserIsolationService
	 */
	private EnvironmentUserIsolationService $user_isolation;

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
		$this->user_isolation  = new EnvironmentUserIsolationService();
	}

	/**
	 * Create a new environment.
	 *
	 * @param string               $name    Human-readable name.
	 * @param array<string, mixed> $options Optional settings (template, etc.).
	 * @return Environment The newly created environment.
	 *
	 * @throws \RuntimeException If the directory already exists or creation fails.
	 * @throws \InvalidArgumentException If conflicting clone options are provided.
	 */
	public function create( string $name, array $options = array() ): Environment {
		$filtered_options = Hooks::filter( 'rudel_environment_create_options', $options, $name, $this );
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
		/** @var array<string, mixed> $options */
		$options = is_array( $filtered_options ) ? $filtered_options : $options;
		$context = array(
			'name'                       => $name,
			'options'                    => $options,
			'environments_dir'           => $this->environments_dir,
			'alternate_environments_dir' => $this->alternate_environments_dir,
		);
		Hooks::action( 'rudel_before_environment_create', $context );

		$id           = null;
		$path         = null;
		$site_options = $this->normalize_site_options( $options['site_options'] ?? array() );
		$created_at   = gmdate( 'c' );
		$config       = new RudelConfig();

		try {
			if ( empty( $options['skip_limits'] ) ) {
				$this->check_limits();
			}

			$id   = Environment::generate_id( $name );
			$path = $this->repository->path_for( $id );

			if ( is_dir( $path ) ) {
				throw new \RuntimeException( sprintf( 'Environment directory already exists: %s', $path ) );
			}

			$raw_clone_from = $options['clone_from'] ?? null;
			$clone_from     = is_string( $raw_clone_from ) ? $raw_clone_from : null;
			$raw_type       = $options['type'] ?? 'sandbox';
			$target_type    = is_string( $raw_type ) ? $raw_type : 'sandbox';
			$raw_domains    = $options['domains'] ?? null;
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
			/** @var array<int, string>|null $target_domains */
			$target_domains = is_array( $raw_domains ) ? $raw_domains : null;

			if ( ! in_array( $target_type, array( 'sandbox', 'app' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Invalid environment type: %s. Must be "sandbox" or "app".', $target_type ) );
			}

			$table_prefix = Environment::table_prefix_for_id( $id );
			$theme_slug   = $this->resolve_overlay_theme_slug( $options );
			$target_url   = $this->get_target_environment_url( $id, null, $target_type, $target_domains );
			$template     = is_string( $options['template'] ?? null ) ? (string) $options['template'] : 'overlay';

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for environment scaffolding.
			if ( ! is_dir( $this->environments_dir ) ) {
				mkdir( $this->environments_dir, 0755, true );
			}

			if ( ! mkdir( $path, 0755 ) ) {
				throw new \RuntimeException( sprintf( 'Failed to create environment directory: %s', $path ) );
			}

			mkdir( $path . '/themes', 0755 );
			mkdir( $path . '/logs', 0755 );
			mkdir( $path . '/snapshots', 0755 );
			mkdir( $path . '/metadata', 0755 );
			mkdir( $path . '/tmp', 0755 );
			// phpcs:enable

			$clone_source       = null;
			$clone_lineage      = array();
			$source_environment = null;

			$this->write_wp_cli_yml( $path, $target_url );

			if ( $clone_from ) {
				$source_environment = $this->resolve_clone_source_environment( $clone_from );
				if ( ! $source_environment ) {
					throw new \RuntimeException( sprintf( 'Source environment not found: %s', $clone_from ) );
				}
				if ( ! isset( $options['app_id'] ) && null !== $source_environment->app_record_id && 'sandbox' === $target_type ) {
					$options['app_id'] = $source_environment->app_record_id;
				}

				$theme_slug   = $theme_slug ?? $source_environment->theme_slug ?? $this->theme_slug_from_tracked_dir( $source_environment->tracked_git_dir );
				$clone_source = $this->clone_from_overlay_environment( $source_environment, $table_prefix, $target_url, $path, $theme_slug );
				$clone_lineage = array(
					'source_environment_id'   => $source_environment->id,
					'source_environment_type' => $source_environment->type,
				);
			} else {
				$clone_result = $this->clone_host_database_to_overlay( $table_prefix, $target_url );
				$clone_source = $this->build_clone_source(
					$this->get_host_site_url(),
					true,
					null !== $theme_slug,
					false,
					false,
					array(
						'tables_cloned' => $clone_result['tables_cloned'],
						'rows_cloned'   => $clone_result['rows_cloned'],
						'engine'        => 'overlay',
						'target_url'    => $target_url,
						'table_prefix'  => $table_prefix,
					)
				);
			}

			if ( null !== $theme_slug && ! $clone_from ) {
				( new ThemeOverlay() )->copy_theme( $theme_slug, ThemeOverlay::theme_root_for( $path ) );
			}

			$theme_template = null !== $theme_slug
				? ( new ThemeOverlay() )->template_slug_for_theme( $theme_slug, ThemeOverlay::theme_root_for( $path ) )
				: null;
			$site_options = array_merge(
				array_filter(
					array(
						'siteurl'    => $target_url,
						'home'       => $target_url,
						'template'   => $theme_template,
						'stylesheet' => $theme_slug,
					),
					static fn ( $value ): bool => null !== $value && '' !== $value
				),
				$site_options
			);
			$this->apply_site_options( $id, $path, $table_prefix, $site_options );

			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
			/** @var array<string, mixed> $merged_options */
			$merged_options = array_merge(
				$options,
				$clone_lineage,
				array(
					'shared_plugins' => true,
					'shared_uploads' => true,
				)
			);
			$policy_meta    = EnvironmentPolicy::metadata_for_create(
				$merged_options,
				$target_type,
				$created_at,
				$config
			);

			$pm_owner = $policy_meta['owner'] ?? null;
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
			/** @var array<int, string> $pm_labels */
			$pm_labels    = $policy_meta['labels'] ?? array();
			$pm_purpose   = $policy_meta['purpose'] ?? null;
			$pm_protected = $policy_meta['protected'] ?? false;
			$pm_expires   = $policy_meta['expires_at'] ?? null;
			$pm_last_used = $policy_meta['last_used_at'] ?? null;
			$pm_src_id    = $policy_meta['source_environment_id'] ?? null;
			$pm_src_type  = $policy_meta['source_environment_type'] ?? null;
			$pm_dep_id    = $policy_meta['last_deployed_from_id'] ?? null;
			$pm_dep_type  = $policy_meta['last_deployed_from_type'] ?? null;
			$pm_dep_at    = $policy_meta['last_deployed_at'] ?? null;
			$pm_git_rem   = $policy_meta['tracked_git_remote'] ?? null;
			$pm_git_br    = $policy_meta['tracked_git_branch'] ?? null;
			$pm_git_dir   = $policy_meta['tracked_git_dir'] ?? null;

			$environment = new Environment(
				id: $id,
				name: $name,
				path: $path,
				created_at: $created_at,
				template: $template,
				status: 'active',
				clone_source: $clone_source,
				multisite: false,
				engine: 'overlay',
				blog_id: null,
				type: $target_type,
				domains: $target_domains,
				owner: is_string( $pm_owner ) ? $pm_owner : null,
				labels: is_array( $pm_labels ) ? $pm_labels : array(),
				purpose: is_string( $pm_purpose ) ? $pm_purpose : null,
				is_protected: (bool) $pm_protected,
				expires_at: is_string( $pm_expires ) ? $pm_expires : null,
				last_used_at: is_string( $pm_last_used ) ? $pm_last_used : null,
				source_environment_id: is_string( $pm_src_id ) ? $pm_src_id : null,
				source_environment_type: is_string( $pm_src_type ) ? $pm_src_type : null,
				last_deployed_from_id: is_string( $pm_dep_id ) ? $pm_dep_id : null,
				last_deployed_from_type: is_string( $pm_dep_type ) ? $pm_dep_type : null,
				last_deployed_at: is_string( $pm_dep_at ) ? $pm_dep_at : null,
				tracked_git_remote: is_string( $pm_git_rem ) ? $pm_git_rem : null,
				tracked_git_branch: is_string( $pm_git_br ) ? $pm_git_br : null,
				tracked_git_dir: is_string( $pm_git_dir ) ? $pm_git_dir : null,
				shared_plugins: true,
				shared_uploads: true,
				table_prefix: $table_prefix,
				theme_slug: $theme_slug,
				app_record_id: isset( $options['app_id'] ) && is_numeric( $options['app_id'] ) ? (int) $options['app_id'] : null,
			);
			$environment = $this->repository->save( $environment );

			Hooks::action( 'rudel_after_environment_create', $environment, $context );

			return $environment;
		} catch ( \Throwable $e ) {
			if ( null !== $id ) {
				try {
					( new MySQLCloner() )->drop_tables( Environment::table_prefix_for_id( (string) $id ) );
				} catch ( \Throwable $drop_error ) {
					unset( $drop_error );
				}
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
	 * @param string               $id      Environment identifier.
	 * @param array<string, mixed> $changes Metadata changes.
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
				$environment->get_table_prefix(),
				$site_options
			);
			$updated = $this->repository->update_fields( $id, $changes, $environment->type );
			if ( array_key_exists( 'shared_plugins', $changes ) || array_key_exists( 'shared_uploads', $changes ) ) {
				EnvironmentContentLayout::materialize_for_environment( $updated );
			}
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
			$this->remove_environment_worktrees( $environment );

			if ( $environment->is_subsite() && $environment->blog_id ) {
				$this->user_isolation->drop( $environment );
				$subsite_cloner = new SubsiteCloner();
				$subsite_cloner->delete_subsite( $environment->blog_id );
			} elseif ( $environment->is_overlay() ) {
				( new MySQLCloner() )->drop_tables( $environment->get_table_prefix() );
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
			if ( $target->is_overlay() && null !== $source->theme_slug && $source->theme_slug !== $target->theme_slug ) {
				$target = $this->repository->update_fields(
					$target->id,
					array( 'theme_slug' => $source->theme_slug ),
					$target->type
				);
				$context['target'] = $target;
			}
			if ( $target->is_subsite() ) {
				$this->write_runtime_mu_plugin( $target->path );
				$this->write_environment_db_dropin(
					$target->path,
					array(
						'environment'    => $target,
						'path'           => $target->path,
						'blog_id'        => $target->blog_id,
						'type'           => $target->type,
						'table_prefix'   => $target->get_table_prefix(),
						'users_table'    => $target->get_users_table(),
						'usermeta_table' => $target->get_usermeta_table(),
					)
				);
			}

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
	 * @param array<string, mixed> $options Options: 'dry_run' (bool), 'max_age_days' (int override), 'max_idle_days' (int override).
	 * @return array{removed: string[], skipped: string[], errors: string[], reasons?: array<string, string>} Cleanup results.
	 */
	public function cleanup( array $options = array() ): array {
		return $this->cleanup_service->cleanup( $options );
	}

	/**
	 * Clean up environments whose git branches have been merged.
	 *
	 * @param array<string, mixed> $options Options: 'dry_run' (bool).
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
			$wp_home_val = constant( 'WP_HOME' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation without requiring full WP helpers.
			$parts = parse_url( is_string( $wp_home_val ) ? $wp_home_val : '' );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] : $scheme;
				$host   = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : $host;
				$port   = isset( $parts['port'] ) && is_numeric( $parts['port'] ) ? (int) $parts['port'] : null;
			}
		} elseif ( function_exists( 'home_url' ) ) {
			$home_url = home_url( '/' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime URL derivation from WordPress' resolved home URL.
			$parts = parse_url( $home_url );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] : $scheme;
				$host   = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : $host;
				$port   = isset( $parts['port'] ) && is_numeric( $parts['port'] ) ? (int) $parts['port'] : null;
			}
		}

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$domain_site  = constant( 'DOMAIN_CURRENT_SITE' );
			$network_host = is_string( $domain_site ) ? preg_replace( '/:\d+$/', '', $domain_site ) : null;
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
	 * Remove linked worktrees recorded for one environment.
	 *
	 * @param Environment $environment Environment being destroyed.
	 * @return void
	 */
	private function remove_environment_worktrees( Environment $environment ): void {
		$worktrees = $environment->clone_source['git_worktrees'] ?? array();
		if ( ! is_array( $worktrees ) || empty( $worktrees ) ) {
			return;
		}

		$git = new GitIntegration();

		foreach ( $worktrees as $worktree ) {
			if ( ! is_array( $worktree ) ) {
				continue;
			}

			$repo   = isset( $worktree['repo'] ) && is_scalar( $worktree['repo'] ) ? trim( (string) $worktree['repo'] ) : '';
			$type   = isset( $worktree['type'] ) && is_scalar( $worktree['type'] ) ? trim( (string) $worktree['type'] ) : '';
			$name   = isset( $worktree['name'] ) && is_scalar( $worktree['name'] ) ? trim( (string) $worktree['name'] ) : '';
			$branch = isset( $worktree['branch'] ) && is_scalar( $worktree['branch'] ) ? trim( (string) $worktree['branch'] ) : '';

			if ( '' === $repo || '' === $type || '' === $name ) {
				continue;
			}

			$repo_control  = $git->common_git_dir( $repo ) ?? $repo;
			$worktree_path = $environment->get_wp_content_path() . '/' . $type . '/' . $name;
			$raw_meta_name = isset( $worktree['metadata_name'] ) && is_scalar( $worktree['metadata_name'] ) ? trim( (string) $worktree['metadata_name'] ) : null;

			$git->remove_worktree( $repo_control, $worktree_path, is_string( $raw_meta_name ) && '' !== $raw_meta_name ? $raw_meta_name : null );

			if ( '' !== $branch ) {
				$git->delete_branch( $repo_control, $branch );
			}
		}
	}

	/**
	 * Build clone metadata for a new environment.
	 *
	 * @param string               $host_url       Source host URL.
	 * @param bool                 $db_cloned      Whether the database was cloned.
	 * @param bool                 $themes_cloned  Whether themes were cloned.
	 * @param bool                 $plugins_cloned Whether plugins were cloned.
	 * @param bool                 $uploads_cloned Whether uploads were cloned.
	 * @param array<string, mixed> $extra          Additional metadata to merge into the clone record.
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
		$default  = array_merge(
			array(
				'host_url'       => $host_url,
				'cloned_at'      => gmdate( 'c' ),
				'db_cloned'      => $db_cloned,
				'themes_cloned'  => $themes_cloned,
				'plugins_cloned' => $plugins_cloned,
				'uploads_cloned' => $uploads_cloned,
			),
			$extra
		);
		$filtered = Hooks::filter(
			'rudel_environment_clone_source',
			$default,
			$host_url,
			$db_cloned,
			$themes_cloned,
			$plugins_cloned,
			$uploads_cloned,
			$extra
		);
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
		/** @var array<string, mixed> */
		return is_array( $filtered ) ? $filtered : $default;
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
		return rtrim( $environment->get_url(), '/' );
	}

	/**
	 * Resolve the active theme for a new overlay environment.
	 *
	 * @param array<string, mixed> $options Create options.
	 * @return string|null Theme slug.
	 */
	private function resolve_overlay_theme_slug( array $options ): ?string {
		$raw_theme = $options['theme'] ?? ( $options['theme_slug'] ?? null );
		$theme     = is_scalar( $raw_theme ) ? (string) $raw_theme : null;

		return ( new ThemeOverlay() )->resolve_theme_slug( $theme );
	}

	/**
	 * Resolve a theme slug from a tracked Git directory such as themes/client-theme.
	 *
	 * @param string|null $tracked_git_dir Tracked wp-content-relative path.
	 * @return string|null Theme slug, or null when the tracked path is not a theme.
	 */
	private function theme_slug_from_tracked_dir( ?string $tracked_git_dir ): ?string {
		if ( null === $tracked_git_dir ) {
			return null;
		}

		$path = trim( str_replace( '\\', '/', $tracked_git_dir ), '/' );
		if ( '' === $path || ! str_starts_with( $path, 'themes/' ) ) {
			return null;
		}

		$parts = explode( '/', $path );
		$slug  = $parts[1] ?? '';
		if ( '' === $slug ) {
			return null;
		}

		return ( new ThemeOverlay() )->resolve_theme_slug( $slug );
	}

	/**
	 * Clone the host database into a new overlay table prefix.
	 *
	 * @param string $target_prefix Environment table prefix.
	 * @param string $target_url Environment URL.
	 * @return array{tables_cloned: int, rows_cloned: int, is_multisite: bool}
	 * @throws \RuntimeException When the host WordPress database connection is unavailable.
	 */
	private function clone_host_database_to_overlay( string $target_prefix, string $target_url ): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'Overlay database cloning requires a running WordPress database connection.' );
		}

		$registry_prefix = RuntimeTableConfig::wordpress_prefix( $wpdb ) . RuntimeTableConfig::prefix();

		return ( new MySQLCloner() )->clone_database(
			$target_prefix,
			$target_url,
			array(
				'exclude_prefixes' => array(
					$registry_prefix,
					$target_prefix,
				),
			)
		);
	}

	/**
	 * Clone one overlay environment into another.
	 *
	 * @param Environment $source Source environment.
	 * @param string      $target_prefix Target table prefix.
	 * @param string      $target_url Target URL.
	 * @param string      $target_path Target environment path.
	 * @param string|null $theme_slug Theme slug to copy.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the host WordPress database connection is unavailable.
	 */
	private function clone_from_overlay_environment(
		Environment $source,
		string $target_prefix,
		string $target_url,
		string $target_path,
		?string $theme_slug
	): array {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'Overlay environment cloning requires a running WordPress database connection.' );
		}

		$source_prefix = $source->get_table_prefix();
		$source_url    = $this->get_environment_site_url( $source );
		$mysql_cloner  = new MySQLCloner();
		$tables        = $mysql_cloner->copy_tables( $source_prefix, $target_prefix, array( $target_prefix . 'snap_' ), true );
		$mysql_cloner->rewrite_urls( $wpdb, $target_prefix, $source_url, $target_url );
		$mysql_cloner->rewrite_table_prefix_in_data( $wpdb, $target_prefix, $source_prefix, $target_prefix );

		$themes_cloned = false;
		$git_worktrees = array();
		if ( null !== $theme_slug && '' !== $theme_slug ) {
			$source_theme = ThemeOverlay::theme_root_for( $source ) . '/' . $theme_slug;
			if ( is_dir( $source_theme ) ) {
				$target_theme_root = ThemeOverlay::theme_root_for( $target_path );
				$git               = new GitIntegration();
				if ( $git->is_git_repo( $source_theme ) ) {
					if ( ! is_dir( $target_theme_root ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating overlay theme root before worktree clone.
						mkdir( $target_theme_root, 0755, true );
					}
					$target_id     = basename( $target_path );
					$branch        = 'rudel/' . $target_id;
					$metadata_name = $git->worktree_metadata_name( $target_id, 'themes', $theme_slug );
					$target_theme  = $target_theme_root . '/' . $theme_slug;
					if ( $git->create_worktree( $source_theme, $target_theme, $branch, $metadata_name ) ) {
						$git_worktrees[] = array(
							'type'          => 'themes',
							'name'          => $theme_slug,
							'branch'        => $branch,
							'repo'          => $target_theme,
							'metadata_name' => $metadata_name,
						);
					} else {
						( new ContentCloner() )->copy_directory( $source_theme, $target_theme );
					}
				} else {
					( new ContentCloner() )->copy_directory( $source_theme, $target_theme_root . '/' . $theme_slug );
				}
				$themes_cloned = true;
			} else {
				( new ThemeOverlay() )->copy_theme( $theme_slug, ThemeOverlay::theme_root_for( $target_path ) );
				$themes_cloned = true;
			}
		}

		return array(
			'host_url'              => $source_url,
			'cloned_at'             => gmdate( 'c' ),
			'db_cloned'             => true,
			'themes_cloned'         => $themes_cloned,
			'plugins_cloned'        => false,
			'uploads_cloned'        => false,
			'tables_cloned'         => $tables,
			'engine'                => 'overlay',
			'target_url'            => $target_url,
			'table_prefix'          => $target_prefix,
			'git_worktrees'         => $git_worktrees,
			'source_environment_id' => $source->id,
			'source_type'           => $source->type,
		);
	}

	/**
	 * Build the target runtime URL for a not-yet-saved environment.
	 *
	 * @param string                  $id Environment ID.
	 * @param int|null                $blog_id Optional multisite blog ID for legacy subsite records.
	 * @param string                  $type Environment type.
	 * @param array<int, string>|null $domains Canonical domains when creating an app.
	 * @return string
	 */
	private function get_target_environment_url(
		string $id,
		?int $blog_id,
		string $type = 'sandbox',
		?array $domains = null
	): string {
		if ( 'app' === $type && is_array( $domains ) && ! empty( $domains ) ) {
			$primary = reset( $domains );
			if ( is_string( $primary ) && '' !== trim( $primary ) ) {
				return rtrim( Environment::domain_url( $primary ), '/' );
			}
		}

		if ( null !== $blog_id ) {
			return rtrim( Environment::multisite_url_for( $id, $blog_id ), '/' );
		}

		return $this->get_host_site_url();
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
	 * Write the per-environment wp-cli.yml.
	 *
	 * @param string $path Absolute path to the environment directory.
	 * @param string $environment_url Canonical environment URL.
	 * @return void
	 */
	private function write_wp_cli_yml( string $path, string $environment_url ): void {
		$url     = trailingslashit( $environment_url );
		$content = 'path: ' . $this->get_wp_core_path() . "\n"
			. 'url: ' . $url . "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing environment wp-cli.yml.
		file_put_contents( $path . '/wp-cli.yml', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/wp-cli.yml', 0444 );
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
	 * Write the per-environment db.php drop-in that maps isolated user tables.
	 *
	 * @param string               $path Absolute path to the environment directory.
	 * @param array<string, mixed> $context Context about the target environment.
	 * @return void
	 */
	private function write_environment_db_dropin( string $path, array $context = array() ): void {
		if ( ! is_dir( $path . '/wp-content' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Ensuring wp-content exists before writing the db.php drop-in.
			mkdir( $path . '/wp-content', 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/db.php.tpl' );
		$template = Hooks::filter( 'rudel_environment_db_dropin_contents', $template, $context );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing environment db.php drop-in.
		file_put_contents( $path . '/wp-content/db.php', $template );
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
	 * @param string                     $table_prefix Environment table prefix.
	 * @param array<string, string|null> $site_options Site option overrides.
	 * @return void
	 */
	private function apply_site_options( string $id, string $path, string $table_prefix, array $site_options ): void {
		if ( array() === $site_options ) {
			return;
		}

		$this->apply_mysql_site_options( $id, $table_prefix, $site_options );
	}

	/**
	 * Apply site options inside a MySQL- or subsite-backed environment.
	 *
	 * @param string                     $id Environment identifier.
	 * @param string                     $table_prefix Environment table prefix.
	 * @param array<string, string|null> $site_options Site option overrides.
	 * @throws \RuntimeException When the host WordPress database connection is unavailable.
	 * @return void
	 */
	private function apply_mysql_site_options( string $id, string $table_prefix, array $site_options ): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'Applying MySQL-backed site options requires a running WordPress database connection.' );
		}

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
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return bool True on success.
	 */
	private function delete_directory( string $dir ): bool {
		if ( is_link( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content root during environment cleanup.
			return unlink( $dir );
		}

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterator as $item ) {
			if ( ! $item instanceof \SplFileInfo ) {
				continue;
			}
			$item_path = $item->getPathname();
			if ( ! file_exists( $item_path ) && ! is_link( $item_path ) ) {
				continue;
			}
			$parent_path = dirname( $item_path );
			if ( is_dir( $parent_path ) && ! is_writable( $parent_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring write access on the parent directory before cleanup.
				chmod( $parent_path, 0755 );
			}
			if ( ! $item->isWritable() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Handling read-only generated files.
				chmod( $item_path, $item->isDir() ? 0755 : 0644 );
			}
			if ( $item->isLink() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing symlinked shared-content entry during environment cleanup.
				unlink( $item_path );
				continue;
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct recursive directory removal.
				if ( is_dir( $item_path ) ) {
					rmdir( $item_path );
				}
			} elseif ( file_exists( $item_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file deletion during directory cleanup.
				unlink( $item_path );
			}
		}

		if ( ! is_dir( $dir ) ) {
			return true;
		}
		if ( ! is_writable( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restoring write access on the root cleanup directory.
			chmod( $dir, 0755 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty directory.
		return rmdir( $dir );
	}
}
