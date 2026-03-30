<?php
/**
 * Filesystem-backed operation locking.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Prevents concurrent destructive operations against the same environment when cron, CLI, or API calls overlap.
 */
class OperationLock {

	/**
	 * Lock file path.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * Open lock handle.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $directory Directory that stores the lock file.
	 * @param string $name      Human-readable lock name.
	 */
	public function __construct( string $directory, string $name ) {
		$this->path = rtrim( $directory, '/' ) . '/' . $name . '.lock';
	}

	/**
	 * Acquire the lock or fail immediately.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If another process already holds the lock.
	 */
	public function acquire(): void {
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating local lock directory.
			mkdir( $dir, 0755, true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Using a local advisory lock file.
		$this->handle = fopen( $this->path, 'c+' );
		if ( ! is_resource( $this->handle ) ) {
			throw new \RuntimeException( sprintf( 'Failed to open lock file: %s', $this->path ) );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- flock returns false when another process already owns the lock.
		if ( ! @flock( $this->handle, LOCK_EX | LOCK_NB ) ) {
			$this->release();
			throw new \RuntimeException( sprintf( 'Another operation is already running (%s).', $this->path ) );
		}

		$payload = array(
			'pid'         => getmypid(),
			'acquired_at' => gmdate( 'c' ),
		);

		ftruncate( $this->handle, 0 );
		rewind( $this->handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing local lock metadata.
		fwrite( $this->handle, json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n" );
		fflush( $this->handle );
	}

	/**
	 * Release the lock.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}

		flock( $this->handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing a local advisory lock handle.
		fclose( $this->handle );
		$this->handle = null;
	}

	/**
	 * Return the lock file path for logging and dry-run output.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Release the lock automatically when the object leaves scope.
	 */
	public function __destruct() {
		$this->release();
	}
}
