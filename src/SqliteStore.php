<?php
/**
 * SQLite-backed store for tests and isolated tooling.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Uses PDO SQLite for repeatable repository tests.
 */
class SqliteStore implements DatabaseStore {

	/**
	 * SQLite connection.
	 *
	 * @var \PDO
	 */
	private \PDO $pdo;

	/**
	 * Base table prefix.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Database path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute SQLite path.
	 * @param string $prefix Table prefix.
	 */
	public function __construct( string $path, string $prefix = 'wp_' ) {
		$this->path   = $path;
		$this->prefix = $prefix;

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating isolated test DB directory.
			mkdir( $dir, 0755, true );
		}

		$this->pdo = new \PDO( 'sqlite:' . $path );
		$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	}

	/**
	 * {@inheritDoc}
	 */
	public function cache_key(): string {
		return 'sqlite:' . $this->path;
	}

	/**
	 * {@inheritDoc}
	 */
	public function driver(): string {
		return 'sqlite';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function table( string $suffix ): string {
		return $this->prefix . 'rudel_' . $suffix;
	}

	/**
	 * {@inheritDoc}
	 */
	public function execute( string $sql, array $params = array() ): int {
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $this->normalize_params( $params ) );
		return $stmt->rowCount();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_row( string $sql, array $params = array() ): ?array {
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $this->normalize_params( $params ) );
		$row = $stmt->fetch( \PDO::FETCH_ASSOC );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_all( string $sql, array $params = array() ): array {
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $this->normalize_params( $params ) );
		$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetch_var( string $sql, array $params = array() ) {
		$stmt = $this->pdo->prepare( $sql );
		$stmt->execute( $this->normalize_params( $params ) );
		return $stmt->fetchColumn();
	}

	/**
	 * {@inheritDoc}
	 */
	public function insert( string $table, array $data ): int {
		$columns      = array_keys( $data );
		$placeholders = implode( ', ', array_fill( 0, count( $columns ), '?' ) );
		$sql          = sprintf(
			'INSERT INTO %s (%s) VALUES (%s)',
			$table,
			implode( ', ', $columns ),
			$placeholders
		);

		$this->execute( $sql, array_values( $data ) );

		return (int) $this->pdo->lastInsertId();
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( string $table, array $data, array $where ): int {
		$sets   = array_map( static fn( string $column ): string => $column . ' = ?', array_keys( $data ) );
		$clauses = array_map( static fn( string $column ): string => $column . ' = ?', array_keys( $where ) );
		$sql     = sprintf(
			'UPDATE %s SET %s WHERE %s',
			$table,
			implode( ', ', $sets ),
			implode( ' AND ', $clauses )
		);

		return $this->execute( $sql, array_merge( array_values( $data ), array_values( $where ) ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $table, array $where ): int {
		$clauses = array_map( static fn( string $column ): string => $column . ' = ?', array_keys( $where ) );
		$sql     = sprintf(
			'DELETE FROM %s WHERE %s',
			$table,
			implode( ' AND ', $clauses )
		);

		return $this->execute( $sql, array_values( $where ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function begin(): void {
		$this->pdo->beginTransaction();
	}

	/**
	 * {@inheritDoc}
	 */
	public function commit(): void {
		if ( $this->pdo->inTransaction() ) {
			$this->pdo->commit();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function rollback(): void {
		if ( $this->pdo->inTransaction() ) {
			$this->pdo->rollBack();
		}
	}

	/**
	 * Normalize PDO-bound params.
	 *
	 * @param array $params Raw params.
	 * @return array<int, mixed>
	 */
	private function normalize_params( array $params ): array {
		return array_map(
			static function ( $value ) {
				if ( is_bool( $value ) ) {
					return $value ? 1 : 0;
				}

				return $value;
			},
			array_values( $params )
		);
	}
}
