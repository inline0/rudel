<?php
/**
 * Snapshot manager: create and restore point-in-time snapshots of a sandbox.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages snapshots for a single sandbox, stored under its snapshots/ subdirectory.
 */
class SnapshotManager {

	/**
	 * Sandbox instance.
	 *
	 * @var Sandbox
	 */
	private Sandbox $sandbox;

	/**
	 * Constructor.
	 *
	 * @param Sandbox $sandbox The sandbox to manage snapshots for.
	 */
	public function __construct( Sandbox $sandbox ) {
		$this->sandbox = $sandbox;
	}

	/**
	 * Create a named snapshot of the sandbox's current state.
	 *
	 * @param string $name Snapshot name.
	 * @return array{name: string, created_at: string, sandbox_id: string} Snapshot metadata.
	 *
	 * @throws \InvalidArgumentException If the name is invalid or already exists.
	 * @throws \RuntimeException If snapshot creation fails.
	 */
	public function create( string $name ): array {
		if ( ! self::validate_name( $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid snapshot name: %s', $name ) );
		}

		$snapshot_path = $this->get_snapshot_path( $name );

		if ( is_dir( $snapshot_path ) ) {
			throw new \InvalidArgumentException( sprintf( 'Snapshot already exists: %s', $name ) );
		}

		$snapshots_dir = $this->get_snapshots_dir();
		if ( ! is_dir( $snapshots_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for snapshot management.
			mkdir( $snapshots_dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating snapshot directory.
		if ( ! mkdir( $snapshot_path, 0755 ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create snapshot directory: %s', $snapshot_path ) );
		}

		if ( $this->sandbox->is_mysql() || $this->sandbox->is_subsite() ) {
			$mysql_cloner  = new MySQLCloner();
			$source_prefix = $this->sandbox->get_table_prefix();
			$snap_prefix   = $source_prefix . 'snap_' . substr( md5( $name ), 0, 6 ) . '_';
			$mysql_cloner->copy_tables( $source_prefix, $snap_prefix );

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing snapshot DB metadata.
			file_put_contents(
				$snapshot_path . '/db_snapshot.json',
				json_encode(
					array(
						'engine'       => $this->sandbox->engine,
						'table_prefix' => $snap_prefix,
					),
					JSON_PRETTY_PRINT
				) . "\n"
			);
			// phpcs:enable
		} else {
			$source_db = $this->sandbox->get_db_path();
			if ( file_exists( $source_db ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying SQLite database file for snapshot.
				copy( $source_db, $snapshot_path . '/wordpress.db' );
			}
		}

		$content_cloner = new ContentCloner();
		$content_cloner->copy_directory( $this->sandbox->get_wp_content_path(), $snapshot_path . '/wp-content' );

		$meta = array(
			'name'       => $name,
			'created_at' => gmdate( 'c' ),
			'sandbox_id' => $this->sandbox->id,
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing snapshot metadata.
		file_put_contents(
			$snapshot_path . '/snapshot.json',
			json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable

		return $meta;
	}

	/**
	 * List all snapshots for this sandbox.
	 *
	 * @return array[] Array of snapshot metadata arrays.
	 */
	public function list_snapshots(): array {
		$snapshots_dir = $this->get_snapshots_dir();
		if ( ! is_dir( $snapshots_dir ) ) {
			return array();
		}

		$snapshots = array();
		$dirs      = scandir( $snapshots_dir );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$meta_file = $snapshots_dir . '/' . $dir . '/snapshot.json';
			if ( ! file_exists( $meta_file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local snapshot metadata.
			$data = json_decode( file_get_contents( $meta_file ), true );
			if ( is_array( $data ) ) {
				$snapshots[] = $data;
			}
		}

		return $snapshots;
	}

	/**
	 * Restore a snapshot, replacing the sandbox's current database and wp-content.
	 *
	 * @param string $name Snapshot name.
	 * @return void
	 *
	 * @throws \RuntimeException If the snapshot does not exist.
	 */
	public function restore( string $name ): void {
		$snapshot_path = $this->get_snapshot_path( $name );

		if ( ! is_dir( $snapshot_path ) ) {
			throw new \RuntimeException( sprintf( 'Snapshot not found: %s', $name ) );
		}

		if ( $this->sandbox->is_mysql() || $this->sandbox->is_subsite() ) {
			$db_meta_file = $snapshot_path . '/db_snapshot.json';
			if ( file_exists( $db_meta_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading snapshot metadata.
				$db_meta = json_decode( file_get_contents( $db_meta_file ), true );
				if ( is_array( $db_meta ) && ! empty( $db_meta['table_prefix'] ) ) {
					$mysql_cloner   = new MySQLCloner();
					$sandbox_prefix = $this->sandbox->get_table_prefix();
					$mysql_cloner->drop_tables( $sandbox_prefix );
					$mysql_cloner->copy_tables( $db_meta['table_prefix'], $sandbox_prefix );
				}
			}
		} else {
			$snapshot_db = $snapshot_path . '/wordpress.db';
			$sandbox_db  = $this->sandbox->get_db_path();

			if ( file_exists( $snapshot_db ) ) {
				if ( file_exists( $sandbox_db ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing current database before restore.
					unlink( $sandbox_db );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Restoring database from snapshot.
				copy( $snapshot_db, $sandbox_db );
			}
		}

		$sandbox_content  = $this->sandbox->get_wp_content_path();
		$snapshot_content = $snapshot_path . '/wp-content';

		if ( is_dir( $snapshot_content ) ) {
			$this->delete_directory( $sandbox_content );
			$content_cloner = new ContentCloner();
			$content_cloner->copy_directory( $snapshot_content, $sandbox_content );
		}
	}

	/**
	 * Delete a snapshot.
	 *
	 * @param string $name Snapshot name.
	 * @return bool True if deleted, false if not found.
	 */
	public function delete( string $name ): bool {
		$snapshot_path = $this->get_snapshot_path( $name );

		if ( ! is_dir( $snapshot_path ) ) {
			return false;
		}

		// Clean up MySQL snapshot tables if they exist.
		$db_meta_file = $snapshot_path . '/db_snapshot.json';
		if ( file_exists( $db_meta_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading snapshot metadata.
			$db_meta = json_decode( file_get_contents( $db_meta_file ), true );
			if ( is_array( $db_meta ) && ! empty( $db_meta['table_prefix'] ) ) {
				$mysql_cloner = new MySQLCloner();
				$mysql_cloner->drop_tables( $db_meta['table_prefix'] );
			}
		}

		return $this->delete_directory( $snapshot_path );
	}

	/**
	 * Validate a snapshot name.
	 *
	 * @param string $name Candidate snapshot name.
	 * @return bool True if valid.
	 */
	public static function validate_name( string $name ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.\-]{0,63}$/', $name );
	}

	/**
	 * Get the snapshots directory for this sandbox.
	 *
	 * @return string Absolute path.
	 */
	private function get_snapshots_dir(): string {
		return $this->sandbox->path . '/snapshots';
	}

	/**
	 * Get the path for a specific snapshot.
	 *
	 * @param string $name Snapshot name.
	 * @return string Absolute path.
	 */
	private function get_snapshot_path( string $name ): string {
		return $this->get_snapshots_dir() . '/' . $name;
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return bool True on success.
	 */
	private function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isWritable() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Handling read-only files during snapshot cleanup.
				chmod( $item->getPathname(), 0644 );
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive directory removal during snapshot delete.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- File deletion during snapshot cleanup.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty snapshot directory.
		return rmdir( $dir );
	}
}
