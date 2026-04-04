<?php
/**
 * App repository.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists app identity and domain mappings.
 */
class AppRepository {

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * App environments repository.
	 *
	 * @var EnvironmentRepository
	 */
	private EnvironmentRepository $environments;

	/**
	 * Initialize dependencies.
	 *
	 * @param DatabaseStore         $store Runtime store.
	 * @param EnvironmentRepository $environments App environment repository.
	 */
	public function __construct( DatabaseStore $store, EnvironmentRepository $environments ) {
		$this->store        = $store;
		$this->environments = $environments;
	}

	/**
	 * Register one app environment and its domains.
	 *
	 * @param Environment $environment App environment.
	 * @param array       $domains Normalized domains.
	 * @return Environment
	 *
	 * @throws \RuntimeException When the insert fails or the app cannot be reloaded.
	 */
	public function create( Environment $environment, array $domains ): Environment {
		$now        = gmdate( 'c' );
		$app_id     = $this->store->insert(
			$this->table(),
			array(
				'environment_id' => $environment->record_id,
				'slug'           => $environment->id,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$normalized = $this->normalize_domains( $domains );
		$this->replace_domains( $app_id, $normalized );
		$this->environments->update_fields( $environment->id, array( 'app_id' => $app_id ), 'app' );

		$app = $this->get( $environment->id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'Failed to register app: %s', $environment->id ) );
		}

		return $app;
	}

	/**
	 * List all apps.
	 *
	 * @return Environment[]
	 */
	public function all(): array {
		$rows = $this->store->fetch_all(
			'SELECT environment_id FROM ' . $this->table() . ' ORDER BY id ASC'
		);

		$apps = array();
		foreach ( $rows as $row ) {
			$environment_id = isset( $row['environment_id'] ) ? (int) $row['environment_id'] : 0;
			if ( $environment_id <= 0 ) {
				continue;
			}

			$app = $this->environments->get_by_record_id( $environment_id );
			if ( $app ) {
				$apps[] = $app;
			}
		}

		return $apps;
	}

	/**
	 * Resolve one app by DB ID or slug.
	 *
	 * @param int|string $id App DB ID or slug.
	 * @return Environment|null
	 */
	public function get( $id ): ?Environment {
		$row = $this->find_row( $id );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->environments->get_by_record_id( (int) $row['environment_id'] );
	}

	/**
	 * Resolve one app by mapped domain.
	 *
	 * @param string $domain Domain name.
	 * @return Environment|null
	 */
	public function get_by_domain( string $domain ): ?Environment {
		$row = $this->store->fetch_row(
			'SELECT app_id FROM ' . $this->domains_table() . ' WHERE domain = ? LIMIT 1',
			array( strtolower( trim( $domain ) ) )
		);

		if ( ! is_array( $row ) || empty( $row['app_id'] ) ) {
			return null;
		}

		return $this->get( (int) $row['app_id'] );
	}

	/**
	 * Replace all mapped domains for one app.
	 *
	 * @param int   $app_id App DB ID.
	 * @param array $domains Normalized domains.
	 * @return void
	 */
	public function replace_domains( int $app_id, array $domains ): void {
		$this->store->execute(
			'DELETE FROM ' . $this->domains_table() . ' WHERE app_id = ?',
			array( $app_id )
		);

		$now        = gmdate( 'c' );
		$normalized = $this->normalize_domains( $domains );

		foreach ( $normalized as $index => $domain ) {
			$this->store->insert(
				$this->domains_table(),
				array(
					'app_id'     => $app_id,
					'domain'     => $domain,
					'is_primary' => 0 === $index ? 1 : 0,
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}
	}

	/**
	 * Delete one app registration and detach child environments.
	 *
	 * @param int|string $id App DB ID or slug.
	 * @return bool
	 */
	public function delete( $id ): bool {
		$row = $this->find_row( $id );
		if ( ! is_array( $row ) ) {
			return false;
		}

		$app_id = (int) $row['id'];
		$this->store->execute(
			'UPDATE ' . $this->store->table( 'environments' ) . ' SET app_id = NULL, updated_at = ? WHERE app_id = ? AND type = ?',
			array( gmdate( 'c' ), $app_id, 'sandbox' )
		);
		$this->store->execute(
			'DELETE FROM ' . $this->domains_table() . ' WHERE app_id = ?',
			array( $app_id )
		);
		$this->store->delete( $this->table(), array( 'id' => $app_id ) );

		return true;
	}

	/**
	 * List normalized domains for one app.
	 *
	 * @param int $app_id App DB ID.
	 * @return array<int, string>
	 */
	public function domains( int $app_id ): array {
		$rows = $this->store->fetch_all(
			'SELECT domain FROM ' . $this->domains_table() . ' WHERE app_id = ? ORDER BY is_primary DESC, domain ASC',
			array( $app_id )
		);

		return array_values(
			array_filter(
				array_map(
					static fn( array $row ): ?string => isset( $row['domain'] ) ? (string) $row['domain'] : null,
					$rows
				)
			)
		);
	}

	/**
	 * Normalize a set of domains.
	 *
	 * @param array $domains Raw domains.
	 * @return array<int, string>
	 */
	private function normalize_domains( array $domains ): array {
		$normalized = array();

		foreach ( $domains as $domain ) {
			if ( ! is_scalar( $domain ) ) {
				continue;
			}

			$domain = strtolower( trim( (string) $domain ) );
			if ( '' !== $domain ) {
				$normalized[] = $domain;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Resolve the raw app row.
	 *
	 * @param int|string $id App DB ID or slug.
	 * @return array<string, mixed>|null
	 */
	private function find_row( $id ): ?array {
		if ( is_int( $id ) || ctype_digit( (string) $id ) ) {
			$row = $this->store->fetch_row(
				'SELECT * FROM ' . $this->table() . ' WHERE id = ? LIMIT 1',
				array( (int) $id )
			);
		} else {
			$row = $this->store->fetch_row(
				'SELECT * FROM ' . $this->table() . ' WHERE slug = ? LIMIT 1',
				array( (string) $id )
			);
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Apps table.
	 *
	 * @return string
	 */
	private function table(): string {
		return $this->store->table( 'apps' );
	}

	/**
	 * App domains table.
	 *
	 * @return string
	 */
	private function domains_table(): string {
		return $this->store->table( 'app_domains' );
	}
}
