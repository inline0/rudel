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
	 * Rudel runtime state always lives in the host WordPress database. Path
	 * arguments remain accepted so older call sites do not need a parallel
	 * signature change during the DB-backed cutover.
	 *
	 * @param string|null $primary_path Primary filesystem root.
	 * @param string|null $secondary_path Secondary filesystem root.
	 * @return DatabaseStore
	 *
	 * @throws \RuntimeException When WordPress has not initialized a DB connection.
	 */
	public static function for_paths( ?string $primary_path = null, ?string $secondary_path = null ): DatabaseStore {
		unset( $primary_path, $secondary_path );

		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$store = new WpdbStore( $GLOBALS['wpdb'] );
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
}
