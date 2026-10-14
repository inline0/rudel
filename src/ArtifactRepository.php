<?php
/**
 * Database metadata for snapshots and reusable templates.
 *
 * @package Rudel
 */

namespace Rudel;

/** Stores artifact records in the host WordPress database. */
class ArtifactRepository {
	/**
	 * Runtime collection store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;
	/**
	 * Runtime collection scope.
	 *
	 * @var string
	 */
	private string $scope;
	/**
	 * Runtime collection kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Bind one filesystem artifact collection to the runtime database.
	 *
	 * @param string             $directory Collection directory.
	 * @param string             $kind Artifact kind.
	 * @param DatabaseStore|null $store Optional runtime store.
	 */
	public function __construct( string $directory, string $kind, ?DatabaseStore $store = null ) {
		$this->store = $store ?? RudelDatabase::for_paths();
		$this->scope = hash( 'sha256', rtrim( $directory, '/' ) );
		$this->kind  = $kind;
		RudelSchema::ensure( $this->store );
	}

	/**
	 * Read one artifact record.
	 *
	 * @param string $name Artifact name.
	 * @return array<string, mixed>|null Metadata.
	 */
	public function get( string $name ): ?array {
		$row = $this->store->fetch_row(
			'SELECT metadata FROM ' . $this->store->table( 'artifacts' ) . ' WHERE scope = ? AND kind = ? AND name = ?',
			array( $this->scope, $this->kind, $name )
		);
		return null === $row ? null : $this->decode( $row['metadata'] ?? null );
	}

	/**
	 * Save or replace artifact metadata.
	 *
	 * @param string               $name Artifact name.
	 * @param array<string, mixed> $metadata Metadata.
	 * @return void
	 * @throws \RuntimeException When metadata cannot be encoded.
	 */
	public function save( string $name, array $metadata ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone database consumers do not load WordPress functions.
		$encoded = json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		if ( false === $encoded ) {
			throw new \RuntimeException( 'Cannot encode artifact metadata.' );
		}
		$where = array(
			'scope' => $this->scope,
			'kind'  => $this->kind,
			'name'  => $name,
		);
		$data  = array( 'metadata' => $encoded );
		if ( null === $this->get( $name ) ) {
			$this->store->insert( $this->store->table( 'artifacts' ), array_merge( $where, $data ) );
		} else {
			$this->store->update( $this->store->table( 'artifacts' ), $data, $where );
		}
	}

	/**
	 * List artifact metadata in this collection.
	 *
	 * @return array<int, array<string, mixed>> Records.
	 */
	public function all(): array {
		$rows    = $this->store->fetch_all(
			'SELECT metadata FROM ' . $this->store->table( 'artifacts' ) . ' WHERE scope = ? AND kind = ?',
			array( $this->scope, $this->kind )
		);
		$records = array();
		foreach ( $rows as $row ) {
			$metadata = $this->decode( $row['metadata'] ?? null );
			if ( null !== $metadata ) {
				$records[] = $metadata;
			}
		}
		return $records;
	}

	/**
	 * Remove one artifact record.
	 *
	 * @param string $name Artifact name.
	 * @return void
	 */
	public function delete( string $name ): void {
		$this->store->delete(
			$this->store->table( 'artifacts' ),
			array(
				'scope' => $this->scope,
				'kind'  => $this->kind,
				'name'  => $name,
			)
		);
	}

	/**
	 * Decode only a string-keyed metadata object.
	 *
	 * @param mixed $value Stored JSON.
	 * @return array<string, mixed>|null Metadata.
	 */
	private function decode( mixed $value ): ?array {
		$data = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}
		$metadata = array();
		foreach ( $data as $key => $item ) {
			if ( is_string( $key ) ) {
				$metadata[ $key ] = $item;
			}
		}
		return $metadata;
	}
}
