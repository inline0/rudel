<?php
/**
 * WordPress database-backed store.
 *
 * @package Rudel
 */

namespace Rudel;

/** Wraps `$wpdb` for Rudel repositories. */
class WpdbStore implements DatabaseStore {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private object $wpdb;

	/**
	 * WordPress base table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Initialize dependencies.
	 *
	 * @param \wpdb|null $wpdb Optional database object override.
	 */
	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			$wpdb = $this->resolve_wpdb();
		}

		$this->wpdb   = $wpdb;
		$this->prefix = $this->wpdb->base_prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function cache_key(): string {
		return 'wpdb:' . $this->prefix . ':' . RuntimeTableConfig::signature();
	}

	/**
	 * {@inheritDoc}
	 */
	public function driver(): string {
		return 'mysql';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $suffix Logical table suffix.
	 */
	public function table( string $suffix ): string {
		return $this->prefix . RuntimeTableConfig::table( $suffix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function execute( string $sql, array $params = array() ): int {
		$query = $this->prepare_query( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by WpdbStore.
		$result = $this->wpdb->query( $query );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_row( string $sql, array $params = array() ): ?array {
		$query  = $this->prepare_query( $sql, $params );
		$output = defined( 'ARRAY_A' ) ? \ARRAY_A : 'ARRAY_A';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by WpdbStore.
		$row = $this->wpdb->get_row( $query, $output );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_all( string $sql, array $params = array() ): array {
		$query  = $this->prepare_query( $sql, $params );
		$output = defined( 'ARRAY_A' ) ? \ARRAY_A : 'ARRAY_A';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by WpdbStore.
		$rows = $this->wpdb->get_results( $query, $output );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_var( string $sql, array $params = array() ) {
		$query = $this->prepare_query( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared by WpdbStore.
		return $this->wpdb->get_var( $query );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column values.
	 * @throws \RuntimeException When wpdb reports a write failure.
	 */
	public function insert( string $table, array $data ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Routed through wpdb::insert.
		$result = $this->wpdb->insert( $table, $data );
		if ( false === $result ) {
			throw new \RuntimeException( $this->last_error_message( sprintf( 'Failed to insert Rudel runtime row into %s.', $table ) ) );
		}
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column values.
	 * @param array<string, mixed> $where Row selector.
	 * @throws \RuntimeException When wpdb reports a write failure.
	 */
	public function update( string $table, array $data, array $where ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Routed through wpdb::update.
		$result = $this->wpdb->update( $table, $data, $where );
		if ( false === $result ) {
			throw new \RuntimeException( $this->last_error_message( sprintf( 'Failed to update Rudel runtime rows in %s.', $table ) ) );
		}
		return (int) $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Row selector.
	 * @throws \RuntimeException When wpdb reports a write failure.
	 */
	public function delete( string $table, array $where ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Routed through wpdb::delete.
		$result = $this->wpdb->delete( $table, $where );
		if ( false === $result ) {
			throw new \RuntimeException( $this->last_error_message( sprintf( 'Failed to delete Rudel runtime rows from %s.', $table ) ) );
		}
		return (int) $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin(): void {
		$this->execute( 'START TRANSACTION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function commit(): void {
		$this->execute( 'COMMIT' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		$this->execute( 'ROLLBACK' );
	}

	/**
	 * Resolve the global WordPress DB object.
	 *
	 * @return \wpdb
	 *
	 * @throws \RuntimeException When WordPress has not created $wpdb yet.
	 */
	private function resolve_wpdb(): object {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			throw new \RuntimeException( 'WordPress database object is not available.' );
		}

		return $wpdb;
	}

	/**
	 * Convert ? placeholders to wpdb placeholders.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return string
	 *
	 * @throws \RuntimeException When wpdb cannot prepare the query.
	 */
	private function prepare_query( string $sql, array $params ): string {
		if ( empty( $params ) ) {
			return $sql;
		}

		$segments = explode( '?', $sql );
		$query    = array_shift( $segments );
		$values   = array();

		foreach ( $params as $index => $value ) {
			$query   .= $this->placeholder_for( $value ) . ( $segments[ $index ] ?? '' );
			$values[] = $this->normalize_param( $value );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder conversion handled above.
		$prepared = $this->wpdb->prepare( $query, $values );

		if ( null === $prepared ) {
			throw new \RuntimeException( 'Failed to prepare Rudel runtime query.' );
		}

		return $prepared;
	}

	/**
	 * Build the best available wpdb failure message.
	 *
	 * @param string $fallback Fallback error message.
	 * @return string
	 */
	private function last_error_message( string $fallback ): string {
		if ( isset( $this->wpdb->last_error ) && is_string( $this->wpdb->last_error ) && '' !== trim( $this->wpdb->last_error ) ) {
			return trim( $this->wpdb->last_error );
		}

		return $fallback;
	}

	/**
	 * Determine the wpdb placeholder for one value.
	 *
	 * @param mixed $value Parameter value.
	 * @return string
	 */
	private function placeholder_for( $value ): string {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return '%d';
		}

		if ( is_float( $value ) ) {
			return '%f';
		}

		return '%s';
	}

	/**
	 * Normalize one bound parameter.
	 *
	 * @param mixed $value Raw parameter.
	 * @return mixed
	 */
	private function normalize_param( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		if ( null === $value ) {
			return '';
		}

		return $value;
	}
}
