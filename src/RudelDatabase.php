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
	 * Explicit standalone store configured outside WordPress.
	 *
	 * @var DatabaseStore|null
	 */
	private static ?DatabaseStore $standalone_store = null;

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

		if ( null !== self::$standalone_store ) {
			RudelSchema::ensure( self::$standalone_store );
			return self::$standalone_store;
		}

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
	 * Configure a standalone runtime store.
	 *
	 * @param DatabaseStore $store Runtime store.
	 * @return void
	 */
	public static function set_store( DatabaseStore $store ): void {
		self::$standalone_store = $store;
	}

	/**
	 * Build and configure a standalone store from a neutral connection.
	 *
	 * @param Connection $connection Standalone DB connection.
	 * @return DatabaseStore
	 */
	public static function for_connection( Connection $connection ): DatabaseStore {
		$store = new PdoStore( $connection );
		self::set_store( $store );
		return $store;
	}

	/**
	 * Active standalone store, if one has been configured.
	 *
	 * @return DatabaseStore|null
	 */
	public static function current_store(): ?DatabaseStore {
		return self::$standalone_store;
	}

	/**
	 * Reset cached stores for tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$stores           = array();
		self::$standalone_store = null;
		RudelSchema::reset();
	}
}
