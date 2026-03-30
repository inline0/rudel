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
	 * Constructor.
	 *
	 * @param string|null $apps_dir Optional override for the apps directory.
	 * @param string|null $sandboxes_dir Optional override for the sandboxes directory.
	 */
	public function __construct( ?string $apps_dir = null, ?string $sandboxes_dir = null ) {
		$this->apps_dir        = $apps_dir ?? $this->get_default_apps_dir();
		$this->sandboxes_dir   = $sandboxes_dir ?? $this->get_default_sandboxes_dir();
		$this->manager         = new EnvironmentManager( $this->apps_dir, $this->sandboxes_dir );
		$this->sandbox_manager = new EnvironmentManager( $this->sandboxes_dir, $this->apps_dir );
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
			$app = $this->inherit_git_tracking_from_source( $app, $options );
			$this->rebuild_domain_map();
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
		return $this->manager->list();
	}

	/**
	 * Get a single app by ID.
	 *
	 * @param string $id App identifier.
	 * @return Environment|null App instance or null if not found.
	 */
	public function get( string $id ): ?Environment {
		return $this->manager->get( $id );
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
				$this->rebuild_domain_map();
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
			$meta = $this->backup_manager( $app )->create( $name );
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
		return $this->backup_manager( $app )->list_snapshots();
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
		return $this->deployment_log( $app )->list();
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
			$config = new RudelConfig();
			if ( $config->get( 'auto_backup_before_app_restore' ) > 0 ) {
				$this->backup( $id, 'pre-restore-' . gmdate( 'Ymd_His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 ) );
			}

			$this->backup_manager( $app )->restore( $name );
			$this->manager->update(
				$id,
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);
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
		$sandbox = $this->sandbox_manager->get( $sandbox_id );

		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $sandbox_id ) );
		}

		if ( $sandbox->is_subsite() ) {
			throw new \InvalidArgumentException( 'Apps cannot be deployed from subsite-engine sandboxes.' );
		}

		if ( $sandbox->engine !== $app->engine ) {
			throw new \InvalidArgumentException(
				sprintf( 'Cannot deploy across engines: sandbox is %s, app is %s.', $sandbox->engine, $app->engine )
			);
		}

		$options       = Hooks::filter( 'rudel_app_deploy_options', $options, $app, $sandbox, $this );
		$backup_name ??= 'pre-deploy-' . gmdate( 'Ymd_His' );
		$context       = array(
			'app'         => $app,
			'sandbox'     => $sandbox,
			'backup_name' => $backup_name,
			'options'     => $options,
		);
		Hooks::action( 'rudel_before_app_deploy', $context );

		try {
			$backup      = $this->backup( $app_id, $backup_name );
			$state       = $this->manager->replace_environment_state( $sandbox, $app );
			$deployed_at = gmdate( 'c' );
			$this->manager->update(
				$app_id,
				array(
					'last_deployed_from_id'   => $sandbox->id,
					'last_deployed_from_type' => $sandbox->type,
					'last_deployed_at'        => $deployed_at,
					'last_used_at'            => $deployed_at,
				)
			);
			$deployment = $this->deployment_log( $this->require_app( $app_id ) )->record(
				$sandbox,
				array(
					'deployed_at'   => $deployed_at,
					'backup_name'   => $backup['name'],
					'tables_copied' => $state['tables_copied'],
					'label'         => $options['label'] ?? null,
					'notes'         => $options['notes'] ?? null,
				)
			);

			$result = array(
				'app_id'        => $app->id,
				'sandbox_id'    => $sandbox->id,
				'backup'        => $backup,
				'tables_copied' => $state['tables_copied'],
				'deployment'    => $deployment,
			);
			Hooks::action( 'rudel_after_app_deploy', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_deploy_failed', $context, $e );
			throw $e;
		}
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
			$app->update_meta_batch(
				array(
					'domains'      => array_values( array_unique( $domains ) ),
					'last_used_at' => gmdate( 'c' ),
				)
			);
			$this->rebuild_domain_map();
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
			$app->update_meta_batch(
				array(
					'domains'      => $domains,
					'last_used_at' => gmdate( 'c' ),
				)
			);
			$this->rebuild_domain_map();
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
	 * Rebuild the domains.json mapping file from all app metadata.
	 *
	 * @return void
	 */
	public function rebuild_domain_map(): void {
		$map  = array();
		$apps = $this->list();

		foreach ( $apps as $app ) {
			if ( empty( $app->domains ) ) {
				continue;
			}
			foreach ( $app->domains as $domain ) {
				$map[ $this->normalize_domain( $domain ) ] = $app->id;
			}
		}

		$map_path = $this->apps_dir . '/domains.json';

		if ( ! is_dir( $this->apps_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating apps directory.
			mkdir( $this->apps_dir, 0755, true );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing domain map.
		file_put_contents(
			$map_path,
			json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable
	}

	/**
	 * Read the domain map.
	 *
	 * @return array<string, string> Domain to app ID mapping.
	 */
	public function get_domain_map(): array {
		$map_path = $this->apps_dir . '/domains.json';
		if ( ! file_exists( $map_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading domain map.
		$data = json_decode( file_get_contents( $map_path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$map = array();
		foreach ( $data as $domain => $id ) {
			if ( is_string( $domain ) && is_string( $id ) ) {
				$map[ $this->normalize_domain( $domain ) ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Create a backup manager for an app.
	 *
	 * @param Environment $app App environment.
	 * @return SnapshotManager
	 */
	private function backup_manager( Environment $app ): SnapshotManager {
		return new SnapshotManager(
			$app,
			array(
				'kind'          => 'backup',
				'storage_dir'   => 'backups',
				'metadata_file' => 'backup.json',
				'owner_id_key'  => 'app_id',
			)
		);
	}

	/**
	 * Create a deployment log manager for an app.
	 *
	 * @param Environment $app App environment.
	 * @return AppDeploymentLog
	 */
	private function deployment_log( Environment $app ): AppDeploymentLog {
		return new AppDeploymentLog( $app );
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
		$map = $this->get_domain_map();
		if ( isset( $map[ $domain ] ) && $map[ $domain ] !== $exclude_id ) {
			throw new \InvalidArgumentException(
				sprintf( 'Domain "%s" is already mapped to app "%s".', $domain, $map[ $domain ] )
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
