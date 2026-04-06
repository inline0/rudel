<?php
/**
 * PDO-backed runtime store.
 *
 * @package Rudel
 */

namespace Rudel;

use PDO;
use PDOStatement;

/**
 * Wraps a standalone PDO connection for Rudel repositories.
 */
class PdoStore implements DatabaseStore {

	/**
	 * Standalone DB connection.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Nested transaction depth for one request-scoped store.
	 *
	 * @var int
	 */
	private int $transaction_depth = 0;

	/**
	 * Initialize dependencies.
	 *
	 * @param Connection $connection Standalone DB connection.
	 */
	public function __construct( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * {@inheritDoc}
	 */
	public function cache_key(): string {
		return 'pdo:' . $this->prefix() . ':' . RuntimeTableConfig::signature();
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
		return $this->connection->prefix();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $suffix Logical table suffix.
	 */
	public function table( string $suffix ): string {
		return $this->prefix() . RuntimeTableConfig::table( $suffix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function execute( string $sql, array $params = array() ): int {
		$statement = $this->prepare_and_execute( $sql, $params );
		return $statement->rowCount();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_row( string $sql, array $params = array() ): ?array {
		$row = $this->prepare_and_execute( $sql, $params )->fetch( PDO::FETCH_ASSOC );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_all( string $sql, array $params = array() ): array {
		$rows = $this->prepare_and_execute( $sql, $params )->fetchAll( PDO::FETCH_ASSOC );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 */
	public function fetch_var( string $sql, array $params = array() ) {
		return $this->prepare_and_execute( $sql, $params )->fetchColumn();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column/value pairs.
	 */
	public function insert( string $table, array $data ): int {
		$columns      = array_keys( $data );
		$placeholders = array_fill( 0, count( $columns ), '?' );
		$sql          = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$this->quote_identifier( $table ),
			implode( ', ', array_map( array( $this, 'quote_identifier' ), $columns ) ),
			implode( ', ', $placeholders )
		);

		$this->execute( $sql, array_values( $this->normalize_params( $data ) ) );
		return (int) $this->pdo()->lastInsertId();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column/value pairs.
	 * @param array<string, mixed> $where Column/value predicates.
	 */
	public function update( string $table, array $data, array $where ): int {
		$set_parts   = array();
		$where_parts = array();
		$params      = array();

		foreach ( $this->normalize_params( $data ) as $column => $value ) {
			$set_parts[] = $this->quote_identifier( (string) $column ) . ' = ?';
			$params[]    = $value;
		}

		foreach ( $this->normalize_params( $where ) as $column => $value ) {
			if ( null === $value ) {
				$where_parts[] = $this->quote_identifier( (string) $column ) . ' IS NULL';
				continue;
			}

			$where_parts[] = $this->quote_identifier( (string) $column ) . ' = ?';
			$params[]      = $value;
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$this->quote_identifier( $table ),
			implode( ', ', $set_parts ),
			implode( ' AND ', $where_parts )
		);

		return $this->execute( $sql, $params );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column/value predicates.
	 */
	public function delete( string $table, array $where ): int {
		$where_parts = array();
		$params      = array();

		foreach ( $this->normalize_params( $where ) as $column => $value ) {
			if ( null === $value ) {
				$where_parts[] = $this->quote_identifier( (string) $column ) . ' IS NULL';
				continue;
			}

			$where_parts[] = $this->quote_identifier( (string) $column ) . ' = ?';
			$params[]      = $value;
		}

		$sql = sprintf(
			'DELETE FROM %s WHERE %s',
			$this->quote_identifier( $table ),
			implode( ' AND ', $where_parts )
		);

		return $this->execute( $sql, $params );
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin(): void {
		if ( 0 === $this->transaction_depth ) {
			$this->pdo()->beginTransaction();
		}

		++$this->transaction_depth;
	}

	/**
	 * {@inheritDoc}
	 */
	public function commit(): void {
		if ( $this->transaction_depth <= 0 ) {
			return;
		}

		--$this->transaction_depth;

		if ( 0 === $this->transaction_depth && $this->pdo()->inTransaction() ) {
			$this->pdo()->commit();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		if ( $this->transaction_depth <= 0 ) {
			return;
		}

		$this->transaction_depth = 0;

		if ( $this->pdo()->inTransaction() ) {
			$this->pdo()->rollBack();
		}
	}

	/**
	 * Prepare and execute one SQL statement.
	 *
	 * @param string $sql SQL query with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return PDOStatement
	 */
	private function prepare_and_execute( string $sql, array $params ): PDOStatement {
		$statement = $this->pdo()->prepare( $sql );
		$statement->execute( array_values( $this->normalize_params( $params ) ) );
		return $statement;
	}

	/**
	 * Normalize bound parameters.
	 *
	 * @param array<string|int, mixed> $params Raw parameters.
	 * @return array<string|int, mixed>
	 */
	private function normalize_params( array $params ): array {
		foreach ( $params as $key => $value ) {
			if ( is_bool( $value ) ) {
				$params[ $key ] = $value ? 1 : 0;
			}
		}

		return $params;
	}

	/**
	 * Quote one SQL identifier.
	 *
	 * @param string $identifier Table or column name.
	 * @return string
	 */
	private function quote_identifier( string $identifier ): string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/**
	 * Active PDO connection.
	 *
	 * @return PDO
	 */
	private function pdo(): PDO {
		return $this->connection->pdo();
	}
}
