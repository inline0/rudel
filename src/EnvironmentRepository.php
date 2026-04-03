<?php
/**
 * Environment repository.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists environments in DB-backed runtime tables.
 */
class EnvironmentRepository {

	/**
	 * Runtime database store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * Primary filesystem directory for this repository's managed type.
	 *
	 * @var string
	 */
	private string $environments_dir;

	/**
	 * Type this repository primarily manages.
	 *
	 * @var string|null
	 */
	private ?string $managed_type;

	/**
	 * Worktree metadata repository.
	 *
	 * @var EnvironmentWorktreeRepository
	 */
	private EnvironmentWorktreeRepository $worktrees;

	/**
	 * Constructor.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @param string        $environments_dir Primary directory.
	 * @param string|null   $managed_type Managed type, usually sandbox or app.
	 */
	public function __construct(
		DatabaseStore $store,
		string $environments_dir,
		?string $managed_type = 'sandbox'
	) {
		$this->store            = $store;
		$this->environments_dir = rtrim( $environments_dir, '/' );
		$this->managed_type     = $managed_type;
		$this->worktrees        = new EnvironmentWorktreeRepository( $store );
	}

	/**
	 * List environments for the managed type.
	 *
	 * @return Environment[]
	 */
	public function all(): array {
		$params = array();
		$sql    = 'SELECT * FROM ' . $this->table() . ' ORDER BY created_at DESC, slug DESC';

		if ( null !== $this->managed_type ) {
			$sql     = 'SELECT * FROM ' . $this->table() . ' WHERE type = ? ORDER BY created_at DESC, slug DESC';
			$params[] = $this->managed_type;
		}

		return array_values(
			array_filter(
				array_map( fn( array $row ): ?Environment => $this->hydrate( $row ), $this->store->fetch_all( $sql, $params ) )
			)
		);
	}

	/**
	 * Resolve one environment by slug within the managed type.
	 *
	 * @param string $id Environment slug.
	 * @return Environment|null
	 */
	public function get( string $id ): ?Environment {
		if ( ! Environment::validate_id( $id ) ) {
			return null;
		}

		$row = $this->find_row_by_slug( $id, $this->managed_type );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Resolve an environment across the managed and alternate types.
	 *
	 * @param string $id Environment slug.
	 * @return Environment|null
	 */
	public function resolve( string $id ): ?Environment {
		$environment = $this->get( $id );
		if ( $environment ) {
			return $environment;
		}

		if ( ! Environment::validate_id( $id ) ) {
			return null;
		}

		$row = $this->find_row_by_slug( $id, null );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Resolve one environment by its DB record ID.
	 *
	 * @param int $record_id Record ID.
	 * @return Environment|null
	 */
	public function get_by_record_id( int $record_id ): ?Environment {
		$row = $this->store->fetch_row(
			'SELECT * FROM ' . $this->table() . ' WHERE id = ? LIMIT 1',
			array( $record_id )
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Resolve one environment by filesystem path.
	 *
	 * @param string $path Absolute path.
	 * @return Environment|null
	 */
	public function get_by_path( string $path ): ?Environment {
		$row = $this->store->fetch_row(
			'SELECT * FROM ' . $this->table() . ' WHERE path = ? LIMIT 1',
			array( rtrim( $path, '/' ) )
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * List sandboxes belonging to one app.
	 *
	 * @param int $app_id App record ID.
	 * @return Environment[]
	 */
	public function list_by_app_id( int $app_id ): array {
		$rows = $this->store->fetch_all(
			'SELECT * FROM ' . $this->table() . ' WHERE app_id = ? AND type = ? ORDER BY created_at DESC, slug DESC',
			array( $app_id, 'sandbox' )
		);

		return array_values(
			array_filter(
				array_map( fn( array $row ): ?Environment => $this->hydrate( $row ), $rows )
			)
		);
	}

	/**
	 * Insert or update one environment record.
	 *
	 * @param Environment $environment Environment payload.
	 * @return Environment
	 */
	public function save( Environment $environment ): Environment {
		$payload = $this->payload_for_environment( $environment );
		$existing = $this->find_row_by_slug( $environment->id, null );

		if ( is_array( $existing ) ) {
			$this->store->update(
				$this->table(),
				$payload,
				array( 'id' => (int) $existing['id'] )
			);
			$record_id = (int) $existing['id'];
		} else {
			$record_id = $this->store->insert( $this->table(), $payload );
		}

		$this->replace_worktrees( $record_id, $environment->clone_source['git_worktrees'] ?? array() );

		$saved = $this->get_by_record_id( $record_id );
		if ( ! $saved ) {
			throw new \RuntimeException( sprintf( 'Failed to persist environment: %s', $environment->id ) );
		}

		return $saved;
	}

	/**
	 * Update fields on one environment and return the refreshed record.
	 *
	 * @param string      $slug Environment slug.
	 * @param array       $changes Field changes.
	 * @param string|null $type Optional exact type filter.
	 * @return Environment
	 */
	public function update_fields( string $slug, array $changes, ?string $type = null ): Environment {
		$row = $this->find_row_by_slug( $slug, $type );
		if ( ! is_array( $row ) ) {
			throw new \RuntimeException( sprintf( 'Environment not found: %s', $slug ) );
		}

		$payload = $this->normalize_changes( $changes, $row );
		if ( ! empty( $payload['__worktrees'] ) ) {
			$this->replace_worktrees( (int) $row['id'], $payload['__worktrees'] );
			unset( $payload['__worktrees'] );
		}

		if ( ! empty( $payload ) ) {
			$payload['updated_at'] = gmdate( 'c' );
			$this->store->update( $this->table(), $payload, array( 'id' => (int) $row['id'] ) );
		}

		$updated = $this->get_by_record_id( (int) $row['id'] );
		if ( ! $updated ) {
			throw new \RuntimeException( sprintf( 'Environment not found after update: %s', $slug ) );
		}

		return $updated;
	}

	/**
	 * Remove one environment record.
	 *
	 * @param string      $slug Environment slug.
	 * @param string|null $type Optional exact type filter.
	 * @return bool
	 */
	public function delete( string $slug, ?string $type = null ): bool {
		$row = $this->find_row_by_slug( $slug, $type );
		if ( ! is_array( $row ) ) {
			return false;
		}

		$this->worktrees->replace_for_environment( (int) $row['id'], array() );
		$this->store->delete( $this->table(), array( 'id' => (int) $row['id'] ) );

		return true;
	}

	/**
	 * Return the filesystem path for one slug within the managed directory.
	 *
	 * @param string $id Environment slug.
	 * @return string
	 */
	public function path_for( string $id ): string {
		return $this->environments_dir . '/' . $id;
	}

	/**
	 * Return the primary directory this repository owns.
	 *
	 * @return string
	 */
	public function environments_dir(): string {
		return $this->environments_dir;
	}

	/**
	 * Return the underlying store.
	 *
	 * @return DatabaseStore
	 */
	public function store(): DatabaseStore {
		return $this->store;
	}

	/**
	 * Hydrate one row into an Environment.
	 *
	 * @param array<string, mixed> $row DB row.
	 * @return Environment|null
	 */
	private function hydrate( array $row ): ?Environment {
		$path = isset( $row['path'] ) ? (string) $row['path'] : '';
		if ( '' === $path ) {
			return null;
		}

		$domains   = $this->domains_for_row( $row );
		$worktrees = $this->worktrees->list_for_environment( (int) $row['id'] );

		return Environment::from_record( $row, $domains, $worktrees );
	}

	/**
	 * Read the environment row by slug.
	 *
	 * @param string      $slug Environment slug.
	 * @param string|null $type Optional exact type.
	 * @return array<string, mixed>|null
	 */
	private function find_row_by_slug( string $slug, ?string $type ): ?array {
		if ( null === $type ) {
			$sql    = 'SELECT * FROM ' . $this->table() . ' WHERE slug = ? LIMIT 1';
			$params = array( $slug );
		} else {
			$sql    = 'SELECT * FROM ' . $this->table() . ' WHERE slug = ? AND type = ? LIMIT 1';
			$params = array( $slug, $type );
		}

		$row = $this->store->fetch_row( $sql, $params );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Serialize one environment into DB columns.
	 *
	 * @param Environment $environment Environment payload.
	 * @return array<string, mixed>
	 */
	private function payload_for_environment( Environment $environment ): array {
		$clone_source = $environment->clone_source;
		if ( is_array( $clone_source ) ) {
			unset( $clone_source['git_worktrees'] );
		}

		return array(
			'app_id'                      => $environment->app_record_id,
			'slug'                        => $environment->id,
			'name'                        => $environment->name,
			'path'                        => rtrim( $environment->path, '/' ),
			'type'                        => $environment->type,
			'engine'                      => $environment->engine,
			'template'                    => $environment->template,
			'status'                      => $environment->status,
			'multisite'                   => $environment->multisite ? 1 : 0,
			'blog_id'                     => $environment->blog_id,
			'clone_source'                => null === $clone_source ? null : wp_json_encode( $clone_source ),
			'owner'                       => $environment->owner,
			'labels'                      => wp_json_encode( $environment->labels ),
			'purpose'                     => $environment->purpose,
			'is_protected'                => $environment->is_protected ? 1 : 0,
			'expires_at'                  => $environment->expires_at,
			'last_used_at'                => $environment->last_used_at,
			'source_environment_slug'     => $environment->source_environment_id,
			'source_environment_type'     => $environment->source_environment_type,
			'last_deployed_from_slug'     => $environment->last_deployed_from_id,
			'last_deployed_from_type'     => $environment->last_deployed_from_type,
			'last_deployed_at'            => $environment->last_deployed_at,
			'tracked_github_repo'         => $environment->tracked_github_repo,
			'tracked_github_branch'       => $environment->tracked_github_branch,
			'tracked_github_dir'          => $environment->tracked_github_dir,
			'created_at'                  => $environment->created_at,
			'updated_at'                  => gmdate( 'c' ),
		);
	}

	/**
	 * Normalize update payloads to DB columns.
	 *
	 * @param array<string, mixed> $changes Raw changes.
	 * @param array<string, mixed> $row Existing row.
	 * @return array<string, mixed>
	 */
	private function normalize_changes( array $changes, array $row ): array {
		$payload = array();

		foreach ( $changes as $key => $value ) {
			switch ( $key ) {
				case 'name':
				case 'path':
				case 'template':
				case 'status':
				case 'owner':
				case 'purpose':
				case 'expires_at':
				case 'last_used_at':
				case 'tracked_github_repo':
				case 'tracked_github_branch':
				case 'tracked_github_dir':
					$payload[ $key ] = $value;
					break;
				case 'protected':
					$payload['is_protected'] = $value ? 1 : 0;
					break;
				case 'app_id':
					$payload['app_id'] = $value;
					break;
				case 'clone_source':
					$worktrees = array();
					if ( is_array( $value ) && ! empty( $value['git_worktrees'] ) && is_array( $value['git_worktrees'] ) ) {
						$worktrees = $value['git_worktrees'];
						unset( $value['git_worktrees'] );
					}
					$payload['clone_source'] = null === $value ? null : wp_json_encode( $value );
					$payload['__worktrees']  = $worktrees;
					break;
				case 'labels':
					$payload['labels'] = wp_json_encode( is_array( $value ) ? array_values( $value ) : array() );
					break;
				case 'source_environment_id':
					$payload['source_environment_slug'] = $value;
					break;
				case 'source_environment_type':
					$payload['source_environment_type'] = $value;
					break;
				case 'last_deployed_from_id':
					$payload['last_deployed_from_slug'] = $value;
					break;
				case 'last_deployed_from_type':
					$payload['last_deployed_from_type'] = $value;
					break;
				case 'last_deployed_at':
					$payload['last_deployed_at'] = $value;
					break;
				case 'multisite':
					$payload['multisite'] = $value ? 1 : 0;
					break;
				case 'blog_id':
					$payload['blog_id'] = $value;
					break;
				case 'engine':
					$payload['engine'] = $value;
					break;
			}
		}

		if ( ! array_key_exists( 'clone_source', $payload ) && isset( $row['id'] ) && array_key_exists( '__worktrees', $payload ) ) {
			$payload['clone_source'] = $row['clone_source'] ?? null;
		}

		return $payload;
	}

	/**
	 * List app domains for one row.
	 *
	 * @param array<string, mixed> $row Environment row.
	 * @return array<int, string>|null
	 */
	private function domains_for_row( array $row ): ?array {
		$app_id = isset( $row['app_id'] ) ? (int) $row['app_id'] : 0;
		if ( $app_id <= 0 ) {
			return null;
		}

		$rows = $this->store->fetch_all(
			'SELECT domain FROM ' . $this->store->table( 'app_domains' ) . ' WHERE app_id = ? ORDER BY is_primary DESC, domain ASC',
			array( $app_id )
		);

		if ( empty( $rows ) ) {
			return null;
		}

		return array_values(
			array_filter(
				array_map(
					static fn( array $domain_row ): ?string => isset( $domain_row['domain'] ) ? (string) $domain_row['domain'] : null,
					$rows
				)
			)
		);
	}

	/**
	 * Replace worktree metadata for one environment.
	 *
	 * @param int   $record_id Environment record ID.
	 * @param mixed $worktrees Worktree payload.
	 * @return void
	 */
	private function replace_worktrees( int $record_id, $worktrees ): void {
		$items = is_array( $worktrees ) ? $worktrees : array();
		$this->worktrees->replace_for_environment( $record_id, $items );
	}

	/**
	 * Base environments table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->store->table( 'environments' );
	}
}
