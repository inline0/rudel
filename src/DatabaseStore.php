<?php
/**
 * Database store contract.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Minimal SQL abstraction for Rudel runtime state.
 */
interface DatabaseStore {

	/**
	 * Stable identifier for schema cache keys.
	 *
	 * @return string
	 */
	public function cache_key(): string;

	/**
	 * SQL driver name.
	 *
	 * @return string
	 */
	public function driver(): string;

	/**
	 * Base table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string;

	/**
	 * Fully-qualified Rudel table name.
	 *
	 * @param string $suffix Logical suffix without prefix.
	 * @return string
	 */
	public function table( string $suffix ): string;

	/**
	 * Run a mutating statement.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return int Affected row count.
	 */
	public function execute( string $sql, array $params = array() ): int;

	/**
	 * Fetch one row.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return array<string, mixed>|null
	 */
	public function fetch_row( string $sql, array $params = array() ): ?array;

	/**
	 * Fetch all rows.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_all( string $sql, array $params = array() ): array;

	/**
	 * Fetch a single scalar value.
	 *
	 * @param string $sql SQL with ? placeholders.
	 * @param array  $params Bound parameters.
	 * @return mixed
	 */
	public function fetch_var( string $sql, array $params = array() );

	/**
	 * Insert one row and return the generated ID.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column/value pairs.
	 * @return int
	 */
	public function insert( string $table, array $data ): int;

	/**
	 * Update rows and return the affected row count.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data Column/value pairs.
	 * @param array<string, mixed> $where Column/value predicates.
	 * @return int
	 */
	public function update( string $table, array $data, array $where ): int;

	/**
	 * Delete rows and return the affected row count.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $where Column/value predicates.
	 * @return int
	 */
	public function delete( string $table, array $where ): int;

	/**
	 * Start a transaction when supported.
	 *
	 * @return void
	 */
	public function begin(): void;

	/**
	 * Commit the current transaction when supported.
	 *
	 * @return void
	 */
	public function commit(): void;

	/**
	 * Roll back the current transaction when supported.
	 *
	 * @return void
	 */
	public function rollback(): void;
}
