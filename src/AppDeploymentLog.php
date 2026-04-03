<?php
/**
 * App deployment log management.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists app deployment records so deploys remain auditable after state changes.
 */
class AppDeploymentLog {

	/**
	 * App environment.
	 *
	 * @var Environment
	 */
	private Environment $app;

	/**
	 * Deployment repository.
	 *
	 * @var AppDeploymentRepository
	 */
	private AppDeploymentRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param Environment $app App environment.
	 */
	public function __construct( Environment $app ) {
		$this->app        = $app;
		$this->repository = new AppDeploymentRepository( RudelDatabase::for_paths( dirname( $app->path ) ) );
	}

	/**
	 * Record a deployment into the app.
	 *
	 * @param Environment $sandbox Source sandbox.
	 * @param array       $data Optional deployment metadata overrides.
	 * @return array<string, mixed>
	 */
	public function record( Environment $sandbox, array $data = array() ): array {
		return $this->repository->record( $this->app, $sandbox, $data );
	}

	/**
	 * List deployment records for the app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(): array {
		if ( null === $this->app->app_record_id ) {
			return array();
		}

		return $this->repository->list( $this->app->app_record_id );
	}

	/**
	 * Resolve a single deployment record by ID.
	 *
	 * @param string $id Deployment identifier.
	 * @return array<string, mixed>|null
	 */
	public function find( string $id ): ?array {
		if ( '' === $id || null === $this->app->app_record_id ) {
			return null;
		}

		return $this->repository->find( $this->app->app_record_id, $id );
	}

	/**
	 * Delete a deployment record.
	 *
	 * @param string $id Deployment identifier.
	 * @return bool
	 */
	public function delete( string $id ): bool {
		if ( null === $this->app->app_record_id ) {
			return false;
		}

		return $this->repository->delete( $this->app->app_record_id, $id );
	}

	/**
	 * Prune older deployment records beyond the requested retention count.
	 *
	 * @param int $keep Number of newest deployments to retain.
	 * @return string[] Removed deployment IDs.
	 */
	public function prune( int $keep ): array {
		if ( $keep <= 0 || null === $this->app->app_record_id ) {
			return array();
		}

		return $this->repository->prune( $this->app->app_record_id, $keep );
	}
}
