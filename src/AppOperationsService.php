<?php
/**
 * App operational workflows.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps backup, deploy, rollback, and retention logic together so app orchestration can stay predictable.
 */
class AppOperationsService {

	/**
	 * App environment manager.
	 *
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $app_manager;

	/**
	 * Sandbox environment manager.
	 *
	 * @var EnvironmentManager
	 */
	private EnvironmentManager $sandbox_manager;

	/**
	 * Constructor.
	 *
	 * @param EnvironmentManager $app_manager App environment manager.
	 * @param EnvironmentManager $sandbox_manager Sandbox environment manager.
	 */
	public function __construct( EnvironmentManager $app_manager, EnvironmentManager $sandbox_manager ) {
		$this->app_manager     = $app_manager;
		$this->sandbox_manager = $sandbox_manager;
	}

	/**
	 * Create an app backup under the app state lock.
	 *
	 * @param Environment $app App environment.
	 * @param string      $name Backup name.
	 * @return array<string, mixed>
	 */
	public function backup( Environment $app, string $name ): array {
		$lock = $this->acquire_state_lock( $app );

		try {
			return $this->backup_manager( $app )->create( $name );
		} finally {
			$lock->release();
		}
	}

	/**
	 * List app backups newest first.
	 *
	 * @param Environment $app App environment.
	 * @return array<int, array<string, mixed>>
	 */
	public function backups( Environment $app ): array {
		return $this->backup_manager( $app )->list_snapshots();
	}

	/**
	 * List deployment records newest first.
	 *
	 * @param Environment $app App environment.
	 * @return array<int, array<string, mixed>>
	 */
	public function deployments( Environment $app ): array {
		return $this->deployment_log( $app )->list();
	}

	/**
	 * Build a stable deploy plan without mutating app state.
	 *
	 * @param Environment $app App environment.
	 * @param Environment $sandbox Source sandbox.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata.
	 * @return array<string, mixed>
	 */
	public function preview_deploy( Environment $app, Environment $sandbox, ?string $backup_name = null, array $options = array() ): array {
		$this->validate_deploy_pair( $app, $sandbox );

		$backup_name ??= 'pre-deploy-' . gmdate( 'Ymd_His' );
		$plan          = array(
			'app_id'                => $app->id,
			'app_name'              => $app->name,
			'app_domains'           => $app->domains ?? array(),
			'sandbox_id'            => $sandbox->id,
			'sandbox_name'          => $sandbox->name,
			'engine'                => $app->engine,
			'backup_name'           => $backup_name,
			'lock_path'             => $this->lock_path( $app ),
			'tracked_github_repo'   => $sandbox->get_github_repo() ?? $app->get_github_repo(),
			'tracked_github_branch' => $sandbox->get_github_base_branch() ?? $app->get_github_base_branch(),
			'tracked_github_dir'    => $sandbox->get_github_dir() ?? $app->get_github_dir(),
			'label'                 => $this->normalize_optional_string( $options['label'] ?? null ),
			'notes'                 => $this->normalize_optional_string( $options['notes'] ?? null ),
			'checks'                => array(
				'engines_match'       => $sandbox->engine === $app->engine,
				'subsite_unsupported' => $sandbox->is_subsite(),
			),
			'dry_run'               => ! empty( $options['dry_run'] ),
		);

		return Hooks::filter( 'rudel_app_deploy_plan', $plan, $app, $sandbox );
	}

	/**
	 * Restore an app from a named backup under the app state lock.
	 *
	 * @param Environment $app App environment.
	 * @param string      $name Backup name.
	 * @return void
	 */
	public function restore( Environment $app, string $name ): void {
		$lock = $this->acquire_state_lock( $app );

		try {
			$config = new RudelConfig();
			if ( $config->get( 'auto_backup_before_app_restore' ) > 0 ) {
				$this->backup_manager( $app )->create( $this->auto_name( 'pre-restore' ) );
			}

			$this->backup_manager( $app )->restore( $name );
			$this->app_manager->update(
				$app->id,
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Deploy a sandbox into an app or return a dry-run plan.
	 *
	 * @param Environment $app App environment.
	 * @param Environment $sandbox Source sandbox.
	 * @param string|null $backup_name Optional backup name.
	 * @param array       $options Optional deployment metadata.
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable If deployment fails after hooks begin or validation rejects the pair.
	 */
	public function deploy( Environment $app, Environment $sandbox, ?string $backup_name = null, array $options = array() ): array {
		$plan = $this->preview_deploy( $app, $sandbox, $backup_name, $options );

		if ( ! empty( $options['dry_run'] ) ) {
			return $plan;
		}

		$context = array(
			'app'         => $app,
			'sandbox'     => $sandbox,
			'backup_name' => $plan['backup_name'],
			'options'     => $options,
			'plan'        => $plan,
		);
		Hooks::action( 'rudel_before_app_deploy', $context );

		$lock = $this->acquire_state_lock( $app );

		try {
			$backup      = $this->backup_manager( $app )->create( $plan['backup_name'] );
			$state       = $this->app_manager->replace_environment_state( $sandbox, $app );
			$deployed_at = gmdate( 'c' );
			$this->app_manager->update(
				$app->id,
				array(
					'last_deployed_from_id'   => $sandbox->id,
					'last_deployed_from_type' => $sandbox->type,
					'last_deployed_at'        => $deployed_at,
					'last_used_at'            => $deployed_at,
				)
			);

			$deployment = $this->deployment_log( $this->require_app( $app->id ) )->record(
				$sandbox,
				array(
					'deployed_at'        => $deployed_at,
					'backup_name'        => $backup['name'],
					'tables_copied'      => $state['tables_copied'],
					'label'              => $plan['label'],
					'notes'              => $plan['notes'],
					'github_repo'        => $plan['tracked_github_repo'],
					'github_base_branch' => $plan['tracked_github_branch'],
					'github_dir'         => $plan['tracked_github_dir'],
				)
			);

			$result = array(
				'app_id'        => $app->id,
				'sandbox_id'    => $sandbox->id,
				'backup'        => $backup,
				'tables_copied' => $state['tables_copied'],
				'deployment'    => $deployment,
				'plan'          => $plan,
			);
			Hooks::action( 'rudel_after_app_deploy', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_deploy_failed', $context, $e );
			throw $e;
		} finally {
			$lock->release();
		}
	}

	/**
	 * Roll an app back to the backup referenced by a deployment record.
	 *
	 * @param Environment $app App environment.
	 * @param string      $deployment_id Deployment identifier.
	 * @param array       $options Optional rollback settings.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException If the deployment cannot be resolved to a rollback backup.
	 * @throws \Throwable If rollback fails after lifecycle hooks begin.
	 */
	public function rollback( Environment $app, string $deployment_id, array $options = array() ): array {
		$deployment = $this->deployment_log( $app )->find( $deployment_id );
		if ( ! $deployment ) {
			throw new \RuntimeException( sprintf( 'Deployment not found: %s', $deployment_id ) );
		}

		$backup_name = $deployment['backup_name'] ?? null;
		if ( ! is_string( $backup_name ) || '' === $backup_name ) {
			throw new \RuntimeException( sprintf( 'Deployment %s does not reference a rollback backup.', $deployment_id ) );
		}

		$context = array(
			'app'         => $app,
			'deployment'  => $deployment,
			'backup_name' => $backup_name,
			'options'     => $options,
		);
		Hooks::action( 'rudel_before_app_rollback', $context );

		try {
			$this->restore( $app, $backup_name );

			$result = array(
				'app_id'        => $app->id,
				'deployment_id' => $deployment_id,
				'backup_name'   => $backup_name,
				'deployment'    => $deployment,
			);
			Hooks::action( 'rudel_after_app_rollback', $result, $context );

			return $result;
		} catch ( \Throwable $e ) {
			Hooks::action( 'rudel_app_rollback_failed', $context, $e );
			throw $e;
		}
	}

	/**
	 * Prune backups and deployment history for a single app.
	 *
	 * @param Environment $app App environment.
	 * @param array       $options Retention options.
	 * @return array{app_id: string, backups_removed: string[], deployments_removed: string[]}
	 */
	public function prune( Environment $app, array $options = array() ): array {
		$keep_backups     = isset( $options['keep_backups'] ) ? (int) $options['keep_backups'] : 0;
		$keep_deployments = isset( $options['keep_deployments'] ) ? (int) $options['keep_deployments'] : 0;
		$result           = array(
			'app_id'              => $app->id,
			'backups_removed'     => array(),
			'deployments_removed' => array(),
		);
		$lock             = $this->acquire_state_lock( $app );

		try {
			if ( $keep_backups > 0 ) {
				$result['backups_removed'] = $this->backup_manager( $app )->prune( $keep_backups );
			}

			if ( $keep_deployments > 0 ) {
				$result['deployments_removed'] = $this->deployment_log( $app )->prune( $keep_deployments );
			}
		} finally {
			$lock->release();
		}

		return $result;
	}

	/**
	 * Create scheduled backups for apps whose last backup is older than the configured interval.
	 *
	 * @param Environment[] $apps App environments.
	 * @param int           $interval_hours Minimum hours between scheduled backups.
	 * @return array{created: array<string, string>, skipped: string[], errors: array<string, string>}
	 */
	public function run_scheduled_backups( array $apps, int $interval_hours ): array {
		$result      = array(
			'created' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);
		$now         = time();
		$min_spacing = max( 1, $interval_hours ) * 3600;

		foreach ( $apps as $app ) {
			try {
				$latest_backup = $this->latest_backup( $app );
				$latest_time   = is_array( $latest_backup ) ? strtotime( (string) ( $latest_backup['created_at'] ?? '' ) ) : false;

				if ( false !== $latest_time && ( $now - $latest_time ) < $min_spacing ) {
					$result['skipped'][] = $app->id;
					continue;
				}

				$backup_name                   = $this->auto_name( 'scheduled' );
				$backup                        = $this->backup( $app, $backup_name );
				$result['created'][ $app->id ] = $backup['name'];
			} catch ( \Throwable $e ) {
				$result['errors'][ $app->id ] = $e->getMessage();
			}
		}

		return $result;
	}

	/**
	 * Return the lock file path used for app state changes.
	 *
	 * @param Environment $app App environment.
	 * @return string
	 */
	public function lock_path( Environment $app ): string {
		return $app->path . '/tmp/app-state.lock';
	}

	/**
	 * Resolve an app from the authoritative manager after a mutating operation.
	 *
	 * @param string $id App identifier.
	 * @return Environment
	 *
	 * @throws \RuntimeException If the app no longer exists after a mutating operation.
	 */
	private function require_app( string $id ): Environment {
		$app = $this->app_manager->get( $id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		return $app;
	}

	/**
	 * Resolve a sandbox from the sandbox manager.
	 *
	 * @param string $id Sandbox identifier.
	 * @return Environment
	 *
	 * @throws \RuntimeException If the sandbox cannot be resolved.
	 */
	public function require_sandbox( string $id ): Environment {
		$sandbox = $this->sandbox_manager->get( $id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $id ) );
		}

		return $sandbox;
	}

	/**
	 * Ensure the source sandbox is valid for app deploy.
	 *
	 * @param Environment $app App environment.
	 * @param Environment $sandbox Source sandbox.
	 * @return void
	 *
	 * @throws \InvalidArgumentException If the sandbox cannot safely deploy into the app.
	 */
	private function validate_deploy_pair( Environment $app, Environment $sandbox ): void {
		if ( $sandbox->is_subsite() ) {
			throw new \InvalidArgumentException( 'Apps cannot be deployed from subsite-engine sandboxes.' );
		}

		if ( $sandbox->engine !== $app->engine ) {
			throw new \InvalidArgumentException(
				sprintf( 'Cannot deploy across engines: sandbox is %s, app is %s.', $sandbox->engine, $app->engine )
			);
		}
	}

	/**
	 * Build a backup manager for an app.
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
	 * Build the deployment log manager for an app.
	 *
	 * @param Environment $app App environment.
	 * @return AppDeploymentLog
	 */
	private function deployment_log( Environment $app ): AppDeploymentLog {
		return new AppDeploymentLog( $app );
	}

	/**
	 * Acquire the app state lock.
	 *
	 * @param Environment $app App environment.
	 * @return OperationLock
	 */
	private function acquire_state_lock( Environment $app ): OperationLock {
		$lock = new OperationLock( $app->path . '/tmp', 'app-state' );
		$lock->acquire();
		return $lock;
	}

	/**
	 * Return the newest backup for an app, if any.
	 *
	 * @param Environment $app App environment.
	 * @return array<string, mixed>|null
	 */
	private function latest_backup( Environment $app ): ?array {
		$backups = $this->backups( $app );
		return $backups[0] ?? null;
	}

	/**
	 * Generate an automatic app operation name.
	 *
	 * @param string $prefix Prefix describing why the operation exists.
	 * @return string
	 */
	private function auto_name( string $prefix ): string {
		return $prefix . '-' . gmdate( 'Ymd_His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
	}

	/**
	 * Normalize an optional freeform string used in operator-visible metadata.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null
	 */
	private function normalize_optional_string( $value ): ?string {
		if ( null === $value || ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
