<?php
/**
 * App manager: creates and manages permanent domain-routed WordPress environments.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages apps and the lifecycle bridge between apps and sandboxes.
 */
class AppManager {

	/**
	 * App environment manager.
	 *
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $manager;

	/**
	 * Sandbox environment manager.
	 *
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $sandbox_manager;

	/**
	 * Absolute path to the apps directory.
	 *
	 * @var string
	 */
	private string $apps_dir;

	/**
	 * Absolute path to the sandboxes directory.
	 *
	 * @var string
	 */
	private string $sandboxes_dir;

	/**
	 * App operations service.
	 *
	 * @var AppOperationsService
	 */
	private AppOperationsService $operations;

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * App repository.
	 *
	 * @var AppRepository
	 */
	private AppRepository $apps;

	/**
	 * Constructor.
	 *
	 * @param string|null $apps_dir Optional override for the apps directory.
	 * @param string|null $sandboxes_dir Optional override for the sandboxes directory.
	 */
	public function __construct( ?string $apps_dir = null, ?string $sandboxes_dir = null ) {
		$this->apps_dir        = $apps_dir ?? $this->get_default_apps_dir();
		$this->sandboxes_dir   = $sandboxes_dir ?? $this->get_default_sandboxes_dir();
		$this->store           = RudelDatabase::for_paths( $this->apps_dir, $this->sandboxes_dir );
		$this->manager         = new EnvironmentManager( $this->apps_dir, $this->sandboxes_dir, 'app', $this->store );
		$this->sandbox_manager = new EnvironmentManager( $this->sandboxes_dir, $this->apps_dir, 'sandbox', $this->store );
		$this->apps            = new AppRepository( $this->store, $this->manager );
		$this->operations      = new AppOperationsService( $this->manager, $this->sandbox_manager );
	}

	/**
	 * Create a new app.
	 *
	 * @param string $name Human-readable name.
	 * @param array  $domains Array of domain names for this app.
	 * @param array  $options Optional settings (engine, clone flags, clone source).
	 * @return Environment The newly created app environment.
	 *
	 * @throws \InvalidArgumentException If domains are invalid, conflicting, or app options are invalid.
	 * @throws \Throwable If app creation fails after lifecycle hooks begin.
	 */
	public function create( string $name, array $domains, array $options = array() ): Environment {
		$domains = Hooks::filter( 'rudel_app_domains', $domains, $name, $this );
		$options = Hooks::filter( 'rudel_app_create_options', $options, $name, $domains, $this );
		$options = $this->normalize_git_tracking_changes( $options );

		if ( empty( $domains ) ) {
			throw new \InvalidArgumentException( 'At least one domain is required for an app.' );
		}

		$domains = array_values(
			array_unique(
				array_map( array( $this, 'normalize_domain' ), $domains )
			)
		);

		foreach ( $domains as $domain ) {
			$this->validate_domain( $domain );
			$this->check_domain_conflict( $domain );
		}

		if ( 'subsite' === ( $options['engine'] ?? 'mysql' ) ) {
			throw new \InvalidArgumentException( 'Apps cannot use the subsite engine.' );
		}

		$options['type']        = 'app';
		$options['domains']     = $domains;
		$options['skip_limits'] = true;

		$context = array(
			'name'    => $name,
			'domains' => $domains,
			'options' => $options,
		);
		Hooks::action( 'rudel_before_app_create', $context );

		try {
			$app = $this->manager->create( $name, $options );
			$app = $this->apps->create( $app, $domains );
			$app = $this->inherit_git_tracking_from_source( $app, $options );
			Hooks::action( 'rudel_after_app_create', $app, $context );

			return $app;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_create_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * List all apps.
	 *
	 * @return Environment[] Array of app instances.
	 */
	public function list(): array {
		return $this->apps->all();
	}

	/**
	 * Get a single app by ID.
	 *
	 * @param string $id App identifier.
	 * @return Environment|null App instance or null if not found.
	 */
	public function get( string $id ): ?Environment {
		return $this->apps->get( $id );
	}

	/**
	 * Update app metadata and return the refreshed app.
	 *
	 * @param string $id      App identifier.
	 * @param array  $changes Metadata changes.
	 * @return Environment
	 *
	 * @throws \Throwable If the update fails after lifecycle hooks begin.
	 */
	public function update( string $id, array $changes ): Environment {
		$app     = $this->require_app( $id );
		$changes = Hooks::filter( 'rudel_app_update_changes', $changes, $app, $this );
		$changes = $this->normalize_git_tracking_changes( $changes, $app );
		$context = array(
			'app'     => $app,
			'changes' => $changes,
		);
		Hooks::action( 'rudel_before_app_update', $context );

		try {
			$updated = $this->manager->update( $id, $changes );
			Hooks::action( 'rudel_after_app_update', $updated, $context );
			return $updated;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_update_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Destroy an app by ID.
	 *
	 * @param string $id App identifier.
	 * @return bool True on success.
	 *
	 * @throws \Throwable If destruction fails after lifecycle hooks begin.
	 */
	public function destroy( string $id ): bool {
		$app = $this->get( $id );
		if ( ! $app ) {
			return false;
		}

		$context = array(
			'app' => $app,
		);
		Hooks::action( 'rudel_before_app_destroy', $context );

		try {
			$result = $this->manager->destroy( $id );
			if ( $result ) {
				$this->apps->delete( $id );
				Hooks::action( 'rudel_after_app_destroy', $context );
			}

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_destroy_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Create a sandbox from an app.
	 *
	 * @param string $app_id App identifier.
	 * @param string $name Sandbox name.
	 * @param array  $options Optional sandbox settings.
	 * @return Environment
	 *
	 * @throws \Throwable If sandbox creation fails after lifecycle hooks begin.
	 */
	public function create_sandbox( string $app_id, string $name, array $options = array() ): Environment {
		$app = $this->require_app( $app_id );

		$options               = Hooks::filter( 'rudel_app_create_sandbox_options', $options, $app, $name, $this );
		$options['clone_from'] = $app->id;
		$options['engine']     = $options['engine'] ?? $app->engine;
		$options['app_id']     = $app->app_record_id;
		unset( $options['type'], $options['domains'], $options['skip_limits'] );

		$context = array(
			'app'     => $app,
			'name'    => $name,
			'options' => $options,
		);
		Hooks::action( 'rudel_before_app_create_sandbox', $context );

		try {
			$sandbox = $this->sandbox_manager->create( $name, $options );
			Hooks::action( 'rudel_after_app_create_sandbox', $sandbox, $context );

			return $sandbox;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_create_sandbox_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Create a backup of an app.
	 *
	 * @param string $id App identifier.
	 * @param string $name Backup name.
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable If backup creation fails after lifecycle hooks begin.
	 */
	public function backup( string $id, string $name ): array {
		$app = $this->require_app( $id );

		$context = array(
			'app'  => $app,
			'name' => $name,
		);
		Hooks::action( 'rudel_before_app_backup', $context );

		try {
			$meta = $this->operations->backup( $app, $name );
			Hooks::action( 'rudel_after_app_backup', $meta, $context );

			return $meta;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_backup_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * List backups for an app.
	 *
	 * @param string $id App identifier.
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \RuntimeException If the app is not found.
	 */
	public function backups( string $id ): array {
		$app = $this->require_app( $id );
		return $this->operations->backups( $app );
	}

	/**
	 * List deployment records for an app.
	 *
	 * @param string $id App identifier.
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \RuntimeException If the app is not found.
	 */
	public function deployments( string $id ): array {
		$app = $this->require_app( $id );
		return $this->operations->deployments( $app );
	}

	/**
	 * Build a deploy plan for an app without mutating state.
	 *
	 * @param string      $app_id App identifier.
	 * @param string      $sandbox_id Sandbox identifier.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata.
	 * @return array<string, mixed>
	 */
	public function preview_deploy( string $app_id, string $sandbox_id, ?string $backup_name = null, array $options = array() ): array {
		$app     = $this->require_app( $app_id );
		$sandbox = $this->operations->require_sandbox( $sandbox_id );
		$options = Hooks::filter( 'rudel_app_deploy_options', $options, $app, $sandbox, $this );

		return $this->operations->preview_deploy( $app, $sandbox, $backup_name, $options );
	}

	/**
	 * Restore an app from a backup.
	 *
	 * @param string $id App identifier.
	 * @param string $name Backup name.
	 * @return void
	 *
	 * @throws \Throwable If restore fails after lifecycle hooks begin.
	 */
	public function restore( string $id, string $name ): void {
		$app = $this->require_app( $id );

		$context = array(
			'app'  => $app,
			'name' => $name,
		);
		Hooks::action( 'rudel_before_app_restore', $context );

		try {
			$this->operations->restore( $app, $name );
			Hooks::action( 'rudel_after_app_restore', $context );
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_restore_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Deploy a sandbox into an app after creating an app backup.
	 *
	 * @param string      $app_id App identifier.
	 * @param string      $sandbox_id Sandbox identifier.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata such as label or notes.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If deploy requirements are invalid.
	 * @throws \RuntimeException If the app or sandbox is not found.
	 * @throws \Throwable If deploy fails after lifecycle hooks begin.
	 */
	public function deploy( string $app_id, string $sandbox_id, ?string $backup_name = null, array $options = array() ): array {
		$app     = $this->require_app( $app_id );
		$sandbox = $this->operations->require_sandbox( $sandbox_id );

		$options = Hooks::filter( 'rudel_app_deploy_options', $options, $app, $sandbox, $this );

		return $this->operations->deploy( $app, $sandbox, $backup_name, $options );
	}

	/**
	 * Roll an app back to the backup captured by a deployment record.
	 *
	 * @param string $app_id App identifier.
	 * @param string $deployment_id Deployment identifier.
	 * @param array  $options Optional rollback settings.
	 * @return array<string, mixed>
	 */
	public function rollback( string $app_id, string $deployment_id, array $options = array() ): array {
		$app = $this->require_app( $app_id );

		return $this->operations->rollback( $app, $deployment_id, $options );
	}

	/**
	 * Prune backups and deployment history for one app.
	 *
	 * @param string $app_id App identifier.
	 * @param array  $options Retention options.
	 * @return array{app_id: string, backups_removed: string[], deployments_removed: string[]}
	 */
	public function prune_history( string $app_id, array $options = array() ): array {
		$app = $this->require_app( $app_id );

		return $this->operations->prune( $app, $options );
	}

	/**
	 * Prune backups and deployment history across all apps.
	 *
	 * @param array $options Retention options.
	 * @return array<int, array{app_id: string, backups_removed: string[], deployments_removed: string[]}>
	 */
	public function prune_all_history( array $options = array() ): array {
		$results = array();

		foreach ( $this->list() as $app ) {
			$results[] = $this->operations->prune( $app, $options );
		}

		return $results;
	}

	/**
	 * Run scheduled backups for all apps.
	 *
	 * @param int $interval_hours Minimum hours between backups for the same app.
	 * @return array{created: array<string, string>, skipped: string[], errors: array<string, string>}
	 */
	public function run_scheduled_backups( int $interval_hours ): array {
		return $this->operations->run_scheduled_backups( $this->list(), $interval_hours );
	}

	/**
	 * Add a domain to an existing app.
	 *
	 * @param string $id App identifier.
	 * @param string $domain Domain name to add.
	 * @return void
	 *
	 * @throws \Throwable If the domain update fails after lifecycle hooks begin.
	 */
	public function add_domain( string $id, string $domain ): void {
		$app = $this->require_app( $id );

		$domain = $this->normalize_domain( $domain );
		$this->validate_domain( $domain );
		$this->check_domain_conflict( $domain, $id );

		$context = array(
			'app'    => $app,
			'domain' => $domain,
		);
		Hooks::action( 'rudel_before_app_domain_add', $context );

		try {
			$domains   = array_map( array( $this, 'normalize_domain' ), $app->domains ?? array() );
			$domains[] = $domain;
			$this->apps->replace_domains( (int) $app->app_record_id, array_values( array_unique( $domains ) ) );
			$this->manager->update(
				$app->id,
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);
			Hooks::action( 'rudel_after_app_domain_add', $context );
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_domain_add_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Remove a domain from an app.
	 *
	 * @param string $id App identifier.
	 * @param string $domain Domain name to remove.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If removing the domain would leave the app unmapped.
	 * @throws \Throwable If the domain update fails after lifecycle hooks begin.
	 */
	public function remove_domain( string $id, string $domain ): void {
		$app = $this->require_app( $id );

		$domain  = $this->normalize_domain( $domain );
		$domains = array_map( array( $this, 'normalize_domain' ), $app->domains ?? array() );
		$domains = array_values( array_filter( $domains, fn( $item ) => $item !== $domain ) );

		if ( empty( $domains ) ) {
			throw new \InvalidArgumentException( 'Cannot remove the last domain from an app.' );
		}

		$context = array(
			'app'    => $app,
			'domain' => $domain,
		);
		Hooks::action( 'rudel_before_app_domain_remove', $context );

		try {
			$this->apps->replace_domains( (int) $app->app_record_id, $domains );
			$this->manager->update(
				$app->id,
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);
			Hooks::action( 'rudel_after_app_domain_remove', $context );
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_domain_remove_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Get the apps directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_apps_dir(): string {
		return $this->apps_dir;
	}

	/**
	 * Retained as a no-op for callers that expect an explicit rebuild step.
	 *
	 * @return void
	 */
	public function rebuild_domain_map(): void {
		// DB-backed runtime lookup does not require a compiled file map.
	}

	/**
	 * Read the domain map.
	 *
	 * @return array<string, string> Domain to app ID mapping.
	 */
	public function get_domain_map(): array {
		$map = array();

		foreach ( $this->list() as $app ) {
			foreach ( $app->domains ?? array() as $domain ) {
				if ( is_string( $domain ) && '' !== $domain ) {
					$map[ $this->normalize_domain( $domain ) ] = $app->id;
				}
			}
		}

		return $map;
	}

	/**
	 * Resolve an app or fail.
	 *
	 * @param string $id App identifier.
	 * @return Environment
	 *
	 * @throws \RuntimeException If the app is not found.
	 */
	private function require_app( string $id ): Environment {
		$app = $this->get( $id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		return $app;
	}

	/**
	 * Normalize tracked GitHub metadata for app create/update flows.
	 *
	 * @param array            $changes Raw change set.
	 * @param Environment|null $app Existing app for update validation.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException If tracked GitHub metadata is inconsistent.
	 */
	private function normalize_git_tracking_changes( array $changes, ?Environment $app = null ): array {
		$normalized = $changes;
		$clear_git  = ! empty( $normalized['clear_github'] );

		if ( array_key_exists( 'github', $normalized ) ) {
			$normalized['tracked_github_repo'] = $normalized['github'];
			unset( $normalized['github'] );
		}

		if ( array_key_exists( 'branch', $normalized ) ) {
			$normalized['tracked_github_branch'] = $normalized['branch'];
			unset( $normalized['branch'] );
		}

		if ( array_key_exists( 'dir', $normalized ) ) {
			$normalized['tracked_github_dir'] = $normalized['dir'];
			unset( $normalized['dir'] );
		}

		if ( $clear_git ) {
			unset( $normalized['clear_github'] );

			if (
				array_key_exists( 'tracked_github_repo', $normalized ) ||
				array_key_exists( 'tracked_github_branch', $normalized ) ||
				array_key_exists( 'tracked_github_dir', $normalized )
			) {
				throw new \InvalidArgumentException( 'Cannot clear the tracked GitHub repository while also setting branch or directory.' );
			}

			$normalized['tracked_github_repo']   = null;
			$normalized['tracked_github_branch'] = null;
			$normalized['tracked_github_dir']    = null;
			return $normalized;
		}

		$repo_key_present   = array_key_exists( 'tracked_github_repo', $normalized );
		$branch_key_present = array_key_exists( 'tracked_github_branch', $normalized );
		$dir_key_present    = array_key_exists( 'tracked_github_dir', $normalized );

		if ( $repo_key_present && is_scalar( $normalized['tracked_github_repo'] ) ) {
			$repo                              = trim( (string) $normalized['tracked_github_repo'] );
			$normalized['tracked_github_repo'] = '' === $repo ? null : $repo;
		}

		if ( ! $repo_key_present && ! $branch_key_present && ! $dir_key_present ) {
			return $normalized;
		}

		$current_repo = $app?->tracked_github_repo;
		$final_repo   = $repo_key_present ? $normalized['tracked_github_repo'] : $current_repo;

		if ( null === $final_repo && $repo_key_present ) {
			if ( $branch_key_present || $dir_key_present ) {
				throw new \InvalidArgumentException( 'Cannot clear the tracked GitHub repository while also setting branch or directory.' );
			}

			$normalized['tracked_github_branch'] = null;
			$normalized['tracked_github_dir']    = null;
			return $normalized;
		}

		if ( null === $final_repo && ( $branch_key_present || $dir_key_present ) ) {
			throw new \InvalidArgumentException( 'Tracked GitHub branch and directory require a GitHub repository.' );
		}

		return $normalized;
	}

	/**
	 * Carry tracked GitHub metadata forward when an app is cloned from another environment.
	 *
	 * @param Environment $app Newly created app.
	 * @param array       $options App creation options.
	 * @return Environment
	 */
	private function inherit_git_tracking_from_source( Environment $app, array $options ): Environment {
		if (
			array_key_exists( 'tracked_github_repo', $options ) ||
			array_key_exists( 'tracked_github_branch', $options ) ||
			array_key_exists( 'tracked_github_dir', $options )
		) {
			return $app;
		}

		$clone_from = $options['clone_from'] ?? null;
		if ( ! is_string( $clone_from ) || '' === $clone_from ) {
			return $app;
		}

		$source = $this->manager->get( $clone_from ) ?? $this->sandbox_manager->get( $clone_from );
		if ( ! $source ) {
			return $app;
		}

		$changes = array_filter(
			array(
				'tracked_github_repo'   => $source->get_github_repo(),
				'tracked_github_branch' => $source->get_github_base_branch(),
				'tracked_github_dir'    => $source->get_github_dir(),
			),
			static fn( $value ) => null !== $value
		);

		if ( empty( $changes ) ) {
			return $app;
		}

		return $this->manager->update( $app->id, $changes );
	}

	/**
	 * Validate a domain name.
	 *
	 * @param string $domain Domain to validate.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the domain is invalid.
	 */
	private function validate_domain( string $domain ): void {
		if ( ! preg_match( '/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid domain: %s', $domain ) );
		}
	}

	/**
	 * Normalize a domain name for metadata and lookup storage.
	 *
	 * @param string $domain Domain name from user input or metadata.
	 * @return string
	 */
	private function normalize_domain( string $domain ): string {
		return strtolower( trim( $domain ) );
	}

	/**
	 * Check for domain conflicts with existing apps.
	 *
	 * @param string      $domain Domain to check.
	 * @param string|null $exclude_id App ID to exclude from the check.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the domain is already mapped to another app.
	 */
	private function check_domain_conflict( string $domain, ?string $exclude_id = null ): void {
		$app = $this->apps->get_by_domain( $domain );
		if ( $app && $app->id !== $exclude_id ) {
			throw new \InvalidArgumentException(
				sprintf( 'Domain "%s" is already mapped to app "%s".', $domain, $app->id )
			);
		}
	}

	/**
	 * Get the default apps directory.
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
	 * Get the default sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_sandboxes_dir(): string {
		if ( defined( 'RUDEL_ENVIRONMENTS_DIR' ) ) {
			return RUDEL_ENVIRONMENTS_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-environments';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-environments';
	}
}
