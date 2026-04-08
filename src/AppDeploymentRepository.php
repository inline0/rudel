<?php
/**
 * App deployment repository.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists deployment history in DB-backed records.
 */
class AppDeploymentRepository {

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * Initialize dependencies.
	 *
	 * @param DatabaseStore $store Runtime store.
	 */
	public function __construct( DatabaseStore $store ) {
		$this->store = $store;
	}

	/**
	 * Record a deployment.
	 *
	 * @param Environment $app App environment.
	 * @param Environment $sandbox Source environment.
	 * @param array       $data Deployment metadata.
	 * @return array<string, mixed>
	 *
	 * @throws \RuntimeException When the insert fails or the deployment cannot be reloaded.
	 */
	public function record( Environment $app, Environment $sandbox, array $data = array() ): array {
		$deployed_at    = is_string( $data['deployed_at'] ?? null ) ? $data['deployed_at'] : gmdate( 'c' );
		$deployment_key = is_string( $data['deployment_key'] ?? null ) && '' !== $data['deployment_key']
			? $data['deployment_key']
			: $this->generate_key( $deployed_at );
		$now            = gmdate( 'c' );
		$record         = array(
			'deployment_key'          => $deployment_key,
			'app_id'                  => $app->app_record_id,
			'environment_id'          => $sandbox->record_id,
			'app_slug'                => $app->id,
			'app_name'                => $app->name,
			'app_domains'             => RuntimeJson::encode( $app->domains ?? array() ),
			'sandbox_slug'            => $sandbox->id,
			'sandbox_name'            => $sandbox->name,
			'source_environment_type' => $sandbox->type,
			'backup_name'             => $data['backup_name'] ?? null,
			'tables_copied'           => isset( $data['tables_copied'] ) ? (int) $data['tables_copied'] : null,
			'label'                   => $this->normalize_optional_string( $data['label'] ?? null ),
			'notes'                   => $this->normalize_optional_string( $data['notes'] ?? null ),
			'github_repo'             => $data['git_remote'] ?? ( $data['github_repo'] ?? ( $sandbox->get_git_remote() ?? $app->get_git_remote() ) ),
			'github_branch'           => $data['git_branch'] ?? ( $data['github_branch'] ?? $sandbox->get_git_branch() ),
			'github_base_branch'      => $data['git_base_branch'] ?? ( $data['github_base_branch'] ?? ( $sandbox->get_git_base_branch() ?? $app->get_git_base_branch() ) ),
			'github_dir'              => $data['git_dir'] ?? ( $data['github_dir'] ?? ( $sandbox->get_git_dir() ?? $app->get_git_dir() ) ),
			'deployed_at'             => $deployed_at,
			'created_at'              => $now,
			'updated_at'              => $now,
		);

		$this->store->insert( $this->table(), $record );

		$stored = $this->find( (int) $app->app_record_id, $deployment_key );
		if ( ! $stored ) {
			throw new \RuntimeException( sprintf( 'Failed to persist deployment %s.', $deployment_key ) );
		}

		return $stored;
	}

	/**
	 * List deployments for one app.
	 *
	 * @param int $app_id App DB ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $app_id ): array {
		$rows = $this->store->fetch_all(
			'SELECT * FROM ' . $this->table() . ' WHERE app_id = ? ORDER BY deployed_at DESC, id DESC',
			array( $app_id )
		);

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Find one deployment by key.
	 *
	 * @param int    $app_id App DB ID.
	 * @param string $deployment_key Deployment key.
	 * @return array<string, mixed>|null
	 */
	public function find( int $app_id, string $deployment_key ): ?array {
		$row = $this->store->fetch_row(
			'SELECT * FROM ' . $this->table() . ' WHERE app_id = ? AND deployment_key = ? LIMIT 1',
			array( $app_id, $deployment_key )
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Delete one deployment by key.
	 *
	 * @param int    $app_id App DB ID.
	 * @param string $deployment_key Deployment key.
	 * @return bool
	 */
	public function delete( int $app_id, string $deployment_key ): bool {
		return $this->store->delete(
			$this->table(),
			array(
				'app_id'         => $app_id,
				'deployment_key' => $deployment_key,
			)
		) > 0;
	}

	/**
	 * Prune old deployments for one app.
	 *
	 * @param int $app_id App DB ID.
	 * @param int $keep Number of newest deployments to retain.
	 * @return string[] Removed deployment keys.
	 */
	public function prune( int $app_id, int $keep ): array {
		if ( $keep <= 0 ) {
			return array();
		}

		$removed = array();
		$stale   = array_reverse( array_slice( $this->list( $app_id ), $keep ) );

		foreach ( $stale as $deployment ) {
			$key = $deployment['id'] ?? null;
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			if ( $this->delete( $app_id, $key ) ) {
				$removed[] = $key;
			}
		}

		return $removed;
	}

	/**
	 * Hydrate a deployment row to the public payload.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$domains = array();
		if ( isset( $row['app_domains'] ) && is_string( $row['app_domains'] ) ) {
			$decoded = json_decode( $row['app_domains'], true );
			if ( is_array( $decoded ) ) {
				$domains = array_values( $decoded );
			}
		}

		$record = array(
			'id'                      => (string) $row['deployment_key'],
			'deployed_at'             => (string) $row['deployed_at'],
			'app_id'                  => (string) $row['app_slug'],
			'app_name'                => (string) $row['app_name'],
			'app_domains'             => $domains,
			'sandbox_id'              => (string) $row['sandbox_slug'],
			'sandbox_name'            => (string) $row['sandbox_name'],
			'source_environment_type' => (string) $row['source_environment_type'],
			'backup_name'             => $row['backup_name'],
			'tables_copied'           => isset( $row['tables_copied'] ) ? (int) $row['tables_copied'] : null,
			'label'                   => $row['label'],
			'notes'                   => $row['notes'],
		);

		if ( ! empty( $row['github_repo'] ) ) {
			$record['git_remote']      = (string) $row['github_repo'];
			$record['git_branch']      = (string) $row['github_branch'];
			$record['git_base_branch'] = (string) $row['github_base_branch'];
			$record['git_dir']         = (string) $row['github_dir'];
		}

		return $record;
	}

	/**
	 * Generate a sortable deployment key.
	 *
	 * @param string $deployed_at ISO timestamp.
	 * @return string
	 */
	private function generate_key( string $deployed_at ): string {
		$timestamp = strtotime( $deployed_at );
		$prefix    = false !== $timestamp ? gmdate( 'Ymd_His', $timestamp ) : gmdate( 'Ymd_His' );
		return 'deploy-' . $prefix . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
	}

	/**
	 * Normalize freeform strings.
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

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->store->table( 'app_deployments' );
	}
}
