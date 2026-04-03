<?php
/**
 * Environment worktree repository.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists worktree metadata separately from clone-source payloads.
 */
class EnvironmentWorktreeRepository {

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * Constructor.
	 *
	 * @param DatabaseStore $store Runtime store.
	 */
	public function __construct( DatabaseStore $store ) {
		$this->store = $store;
	}

	/**
	 * Replace all worktrees for one environment.
	 *
	 * @param int   $environment_id Environment record ID.
	 * @param array $worktrees Worktree rows.
	 * @return void
	 */
	public function replace_for_environment( int $environment_id, array $worktrees ): void {
		$this->store->execute(
			'DELETE FROM ' . $this->table() . ' WHERE environment_id = ?',
			array( $environment_id )
		);

		$now = gmdate( 'c' );
		foreach ( $worktrees as $worktree ) {
			if ( ! is_array( $worktree ) ) {
				continue;
			}

			$name         = isset( $worktree['name'] ) ? trim( (string) $worktree['name'] ) : '';
			$content_type = isset( $worktree['type'] ) ? trim( (string) $worktree['type'] ) : '';
			$branch       = isset( $worktree['branch'] ) ? trim( (string) $worktree['branch'] ) : '';
			$repo_path    = isset( $worktree['repo'] ) ? trim( (string) $worktree['repo'] ) : '';

			if ( '' === $name || '' === $content_type || '' === $branch || '' === $repo_path ) {
				continue;
			}

			$this->store->insert(
				$this->table(),
				array(
					'environment_id' => $environment_id,
					'content_type'   => $content_type,
					'name'           => $name,
					'branch'         => $branch,
					'repo_path'      => $repo_path,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
		}
	}

	/**
	 * List worktrees for one environment.
	 *
	 * @param int $environment_id Environment record ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_environment( int $environment_id ): array {
		$rows = $this->store->fetch_all(
			'SELECT content_type, name, branch, repo_path FROM ' . $this->table() . ' WHERE environment_id = ? ORDER BY content_type ASC, name ASC',
			array( $environment_id )
		);

		return array_map(
			static fn( array $row ): array => array(
				'type'   => (string) $row['content_type'],
				'name'   => (string) $row['name'],
				'branch' => (string) $row['branch'],
				'repo'   => (string) $row['repo_path'],
			),
			$rows
		);
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->store->table( 'worktrees' );
	}
}
