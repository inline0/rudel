<?php
/**
 * Snapshot manager: create and restore point-in-time environment recovery points.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Manages snapshots for sandboxes and backups for apps.
 */
class SnapshotManager {

	/**
	 * Environment instance.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Human-facing recovery point kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Directory name used to store recovery points.
	 *
	 * @var string
	 */
	private string $storage_dir;

	/**
	 * Metadata filename stored inside each recovery point.
	 *
	 * @var string
	 */
	private string $metadata_file;

	/**
	 * Metadata key used to reference the owning environment ID.
	 *
	 * @var string
	 */
	private string $owner_id_key;

	/**
	 * Initialize dependencies.
	 *
	 * @param Environment $environment Environment to manage recovery points for.
	 * @param array       $options Optional settings for naming and storage.
	 */
	public function __construct( Environment $environment, array $options = array() ) {
		$this->environment   = $environment;
		$this->kind          = $options['kind'] ?? 'snapshot';
		$this->storage_dir   = $options['storage_dir'] ?? ( 'backup' === $this->kind ? 'backups' : 'snapshots' );
		$this->metadata_file = $options['metadata_file'] ?? ( 'backup' === $this->kind ? 'backup.json' : 'snapshot.json' );
		$this->owner_id_key  = $options['owner_id_key'] ?? ( $environment->is_app() ? 'app_id' : 'sandbox_id' );
	}

	/**
	 * Create a named recovery point of the environment's current state.
	 *
	 * @param string $name Recovery point name.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the name is invalid or already exists.
	 * @throws \RuntimeException If recovery point creation fails.
	 * @throws \Throwable If recovery point creation fails after lifecycle hooks begin.
	 */
	public function create( string $name ): array {
		if ( ! self::validate_name( $name ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid %s name: %s', $this->kind, $name ) );
		}

		$point_path = $this->get_snapshot_path( $name );

		if ( is_dir( $point_path ) ) {
			throw new \InvalidArgumentException( sprintf( '%s already exists: %s', ucfirst( $this->kind ), $name ) );
		}

		$context = array(
			'environment' => $this->environment,
			'kind'        => $this->kind,
			'name'        => $name,
			'path'        => $point_path,
		);
		$this->emit_action( 'before', 'create', $context );

		try {
			$storage_dir = $this->get_snapshots_dir();
			if ( ! is_dir( $storage_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for recovery point management.
				mkdir( $storage_dir, 0755, true );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating recovery point directory.
			if ( ! mkdir( $point_path, 0755 ) ) {
				throw new \RuntimeException( sprintf( 'Failed to create %s directory: %s', $this->kind, $point_path ) );
			}

			$mysql_cloner  = new MySQLCloner();
			$source_prefix = $this->environment->get_table_prefix();
			$snap_prefix   = $source_prefix . 'snap_' . substr( md5( $name ), 0, 6 ) . '_';
			$mysql_cloner->copy_tables( $source_prefix, $snap_prefix, array( $source_prefix . 'snap_' ) );
			$user_snapshot = ( new EnvironmentUserIsolationService() )->snapshot( $this->environment, $snap_prefix );

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing recovery point DB metadata.
			file_put_contents(
				$point_path . '/db_snapshot.json',
				json_encode(
					array(
						'engine'       => $this->environment->engine,
						'table_prefix' => $snap_prefix,
						'user_tables'  => $user_snapshot,
					),
					JSON_PRETTY_PRINT
				) . "\n"
			);
			// phpcs:enable

			$content_cloner = new ContentCloner();
			$content_cloner->copy_directory( $this->environment->get_wp_content_path(), $point_path . '/wp-content' );

			$meta = array(
				'name'              => $name,
				'created_at'        => gmdate( 'c' ),
				$this->owner_id_key => $this->environment->id,
			);

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing recovery point metadata.
			file_put_contents(
				$point_path . '/' . $this->metadata_file,
				json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
			);
			// phpcs:enable

			$this->environment->update_meta_batch(
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);

			$this->emit_action( 'after', 'create', $context, $meta );

			return $meta;
		} catch ( \Throwable $e ) {
			$this->emit_action( 'failed', 'create', $context, $e );
			throw $e;
		}
	}

	/**
	 * List all recovery points for this environment.
	 *
	 * @return array<int, array<string, mixed>>
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

			$meta_file = $snapshots_dir . '/' . $dir . '/' . $this->metadata_file;
			if ( ! file_exists( $meta_file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local recovery point metadata.
			$data = json_decode( file_get_contents( $meta_file ), true );
			if ( is_array( $data ) ) {
				$snapshots[] = $data;
			}
		}

		usort(
			$snapshots,
			static function ( array $left, array $right ): int {
				$left_time  = strtotime( (string) ( $left['created_at'] ?? '' ) );
				$right_time = strtotime( (string) ( $right['created_at'] ?? '' ) );
				$left_time  = false !== $left_time ? $left_time : 0;
				$right_time = false !== $right_time ? $right_time : 0;

				if ( $left_time === $right_time ) {
					return strcmp( (string) ( $right['name'] ?? '' ), (string) ( $left['name'] ?? '' ) );
				}

				return $right_time <=> $left_time;
			}
		);

		return $snapshots;
	}

	/**
	 * Restore a recovery point, replacing the environment's current database and wp-content.
	 *
	 * @param string $name Recovery point name.
	 * @return void
	 *
	 * @throws \RuntimeException If the recovery point does not exist.
	 * @throws \Throwable If restore fails after lifecycle hooks begin.
	 */
	public function restore( string $name ): void {
		$point_path = $this->get_snapshot_path( $name );

		if ( ! is_dir( $point_path ) ) {
			throw new \RuntimeException( sprintf( '%s not found: %s', ucfirst( $this->kind ), $name ) );
		}

		$context = array(
			'environment' => $this->environment,
			'kind'        => $this->kind,
			'name'        => $name,
			'path'        => $point_path,
		);
		$this->emit_action( 'before', 'restore', $context );

		try {
			$config = new RudelConfig();
			if ( 'snapshot' === $this->kind && $config->get( 'auto_snapshot_before_restore' ) > 0 ) {
				$this->create( $this->auto_recovery_point_name( 'pre-restore' ) );
			}

			$db_meta_file = $point_path . '/db_snapshot.json';
			if ( file_exists( $db_meta_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading recovery point metadata.
				$db_meta = json_decode( file_get_contents( $db_meta_file ), true );
				if ( is_array( $db_meta ) && ! empty( $db_meta['table_prefix'] ) ) {
					$mysql_cloner  = new MySQLCloner();
					$target_prefix = $this->environment->get_table_prefix();
					$mysql_cloner->drop_tables( $target_prefix, array( $target_prefix . 'snap_' ) );
					$mysql_cloner->copy_tables( $db_meta['table_prefix'], $target_prefix );
					( new EnvironmentUserIsolationService() )->restore_snapshot(
						$this->environment,
						is_array( $db_meta['user_tables'] ?? null ) ? $db_meta['user_tables'] : array()
					);
				}
			}

			$environment_content = $this->environment->get_wp_content_path();
			$snapshot_content    = $point_path . '/wp-content';

			if ( is_dir( $snapshot_content ) ) {
				$this->delete_directory( $environment_content );
				$content_cloner = new ContentCloner();
				$content_cloner->copy_directory( $snapshot_content, $environment_content );
			}

			$this->write_runtime_files( $environment_content );

			$this->environment->update_meta_batch(
				array(
					'last_used_at' => gmdate( 'c' ),
				)
			);
			$this->emit_action( 'after', 'restore', $context );
		} catch ( \Throwable $e ) {
			$this->emit_action( 'failed', 'restore', $context, $e );
			throw $e;
		}
	}

	/**
	 * Delete a recovery point.
	 *
	 * @param string $name Recovery point name.
	 * @return bool True if deleted, false if not found.
	 *
	 * @throws \Throwable If deletion fails after lifecycle hooks begin.
	 */
	public function delete( string $name ): bool {
		$point_path = $this->get_snapshot_path( $name );

		if ( ! is_dir( $point_path ) ) {
			return false;
		}

		$context = array(
			'environment' => $this->environment,
			'kind'        => $this->kind,
			'name'        => $name,
			'path'        => $point_path,
		);
		$this->emit_action( 'before', 'delete', $context );

		try {
			$db_meta_file = $point_path . '/db_snapshot.json';
			if ( file_exists( $db_meta_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading recovery point metadata.
				$db_meta = json_decode( file_get_contents( $db_meta_file ), true );
				if ( is_array( $db_meta ) && ! empty( $db_meta['table_prefix'] ) ) {
					$mysql_cloner = new MySQLCloner();
					$mysql_cloner->drop_tables( $db_meta['table_prefix'] );
					( new EnvironmentUserIsolationService() )->drop_snapshot(
						is_array( $db_meta['user_tables'] ?? null ) ? $db_meta['user_tables'] : array()
					);
				}
			}

			$result = $this->delete_directory( $point_path );
			if ( $result ) {
				$this->emit_action( 'after', 'delete', $context );
			}

			return $result;
		} catch ( \Throwable $e ) {
			$this->emit_action( 'failed', 'delete', $context, $e );
			throw $e;
		}
	}

	/**
	 * Prune older recovery points beyond the requested retention count.
	 *
	 * @param int $keep Number of newest recovery points to retain.
	 * @return string[] Names of removed recovery points.
	 */
	public function prune( int $keep ): array {
		if ( $keep <= 0 ) {
			return array();
		}

		$removed   = array();
		$snapshots = $this->list_snapshots();
		$stale     = array_slice( $snapshots, $keep );

		foreach ( $stale as $snapshot ) {
			$name = $snapshot['name'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			if ( $this->delete( $name ) ) {
				$removed[] = $name;
			}
		}

		return $removed;
	}

	/**
	 * Validate a recovery point name.
	 *
	 * @param string $name Candidate recovery point name.
	 * @return bool True if valid.
	 */
	public static function validate_name( string $name ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.\-]{0,63}$/', $name );
	}

	/**
	 * Recovery point storage directory for this environment.
	 *
	 * @return string Absolute path.
	 */
	private function get_snapshots_dir(): string {
		return $this->environment->path . '/' . $this->storage_dir;
	}

	/**
	 * Filesystem path for a specific recovery point.
	 *
	 * @param string $name Recovery point name.
	 * @return string Absolute path.
	 */
	private function get_snapshot_path( string $name ): string {
		return $this->get_snapshots_dir() . '/' . $name;
	}

	/**
	 * Rewrite generated runtime files after content restore.
	 *
	 * @param string $environment_content Absolute env-local wp-content path.
	 * @return void
	 */
	private function write_runtime_files( string $environment_content ): void {
		$plugin_dir = defined( 'RUDEL_PLUGIN_DIR' ) ? RUDEL_PLUGIN_DIR : dirname( __DIR__ ) . '/';
		$mu_dir     = $environment_content . '/mu-plugins';

		if ( ! is_dir( $mu_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Recreating generated MU plugin directory after restore.
			mkdir( $mu_dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$runtime_template = file_get_contents( $plugin_dir . 'templates/runtime-mu-plugin.php.tpl' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewriting the generated MU plugin after restore.
		file_put_contents( $mu_dir . '/rudel-runtime.php', $runtime_template );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$db_template = file_get_contents( $plugin_dir . 'templates/db.php.tpl' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewriting the env-local db.php drop-in after restore.
		file_put_contents( $environment_content . '/db.php', $db_template );
	}

	/**
	 * Build a collision-resistant automatic recovery point name.
	 *
	 * @param string $prefix Prefix used to explain why the recovery point exists.
	 * @return string
	 */
	private function auto_recovery_point_name( string $prefix ): string {
		return $prefix . '-' . gmdate( 'Ymd_His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
	}

	/**
	 * Emit both generic and kind-specific lifecycle hooks.
	 *
	 * @param string $phase Lifecycle phase: before, after, failed.
	 * @param string $operation Lifecycle operation: create, restore, delete.
	 * @param array  $context Hook context.
	 * @param mixed  ...$args Additional hook arguments.
	 * @return void
	 */
	private function emit_action( string $phase, string $operation, array $context, ...$args ): void {
		Hooks::action( "rudel_{$phase}_recovery_point_{$operation}", $context, ...$args );
		Hooks::action( "rudel_{$phase}_{$this->kind}_{$operation}", $context, ...$args );
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
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing empty directory during recovery point cleanup.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing file during recovery point cleanup.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty directory.
		rmdir( $dir );

		return true;
	}
}
