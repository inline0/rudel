<?php
/**
 * Database store factory.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Resolves the active Rudel database store.
 */
class RudelDatabase {

	/**
	 * Shared stores by cache key.
	 *
	 * @var array<string, DatabaseStore>
	 */
	private static array $stores = array();

	/**
	 * Build or reuse the appropriate store for the current runtime.
	 *
	 * @param string|null $primary_path Primary filesystem root.
	 * @param string|null $secondary_path Secondary filesystem root.
	 * @return DatabaseStore
	 */
	public static function for_paths( ?string $primary_path = null, ?string $secondary_path = null ): DatabaseStore {
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$store = new WpdbStore( $GLOBALS['wpdb'] );
			$key   = $store->cache_key();

			if ( ! isset( self::$stores[ $key ] ) ) {
				self::$stores[ $key ] = $store;
			}

			RudelSchema::ensure( self::$stores[ $key ] );

			return self::$stores[ $key ];
		}

		if ( defined( 'RUDEL_TEST_TMPDIR' ) ) {
			$root  = self::common_root( array_filter( array( $primary_path, $secondary_path ) ) );
			$root  = $root ?: RUDEL_TEST_TMPDIR;
			$path  = rtrim( $root, '/' ) . '/rudel-state.sqlite';
			$store = new SqliteStore( $path );
			$key   = $store->cache_key();

			if ( ! isset( self::$stores[ $key ] ) ) {
				self::$stores[ $key ] = $store;
			}

			RudelSchema::ensure( self::$stores[ $key ] );

			return self::$stores[ $key ];
		}

		throw new \RuntimeException( 'Rudel requires a WordPress database connection.' );
	}

	/**
	 * Reset cached stores for tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$stores = array();
		RudelSchema::reset();
	}

	/**
	 * Find the nearest shared root for a set of paths.
	 *
	 * @param array<int, string> $paths Absolute paths.
	 * @return string|null
	 */
	private static function common_root( array $paths ): ?string {
		if ( empty( $paths ) ) {
			return null;
		}

		$parts = array_map(
			static fn( string $path ): array => array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) ),
			$paths
		);

		$common = array_shift( $parts );
		if ( ! is_array( $common ) ) {
			return null;
		}

		foreach ( $parts as $path_parts ) {
			$max    = min( count( $common ), count( $path_parts ) );
			$shared = array();

			for ( $i = 0; $i < $max; ++$i ) {
				if ( $common[ $i ] !== $path_parts[ $i ] ) {
					break;
				}

				$shared[] = $common[ $i ];
			}

			$common = $shared;
		}

		if ( empty( $common ) ) {
			return null;
		}

		return '/' . implode( '/', $common );
	}
}
