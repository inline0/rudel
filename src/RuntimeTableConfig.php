<?php
/**
 * Runtime table naming for advanced embedded installs.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Resolves the host-table names Rudel uses for runtime metadata.
 */
class RuntimeTableConfig {

	/**
	 * Default shared table-name prefix after `$wpdb->base_prefix`.
	 */
	private const DEFAULT_PREFIX = 'rudel_';

	/**
	 * Explicit per-table constant overrides.
	 *
	 * @var array<string, string>
	 */
	private const TABLE_CONSTANTS = array(
		'environments'    => 'RUDEL_RUNTIME_TABLE_ENVIRONMENTS',
		'apps'            => 'RUDEL_RUNTIME_TABLE_APPS',
		'app_domains'     => 'RUDEL_RUNTIME_TABLE_APP_DOMAINS',
		'worktrees'       => 'RUDEL_RUNTIME_TABLE_WORKTREES',
		'app_deployments' => 'RUDEL_RUNTIME_TABLE_APP_DEPLOYMENTS',
	);

	/**
	 * Shared Rudel table-name prefix after the WordPress DB prefix.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		if ( ! defined( 'RUDEL_RUNTIME_TABLE_PREFIX' ) ) {
			return self::DEFAULT_PREFIX;
		}

		$prefix = trim( (string) constant( 'RUDEL_RUNTIME_TABLE_PREFIX' ) );
		if ( '' === $prefix ) {
			return '';
		}

		return rtrim( $prefix, '_' ) . '_';
	}

	/**
	 * Resolve one runtime table base name.
	 *
	 * @param string $suffix Logical table suffix.
	 * @return string
	 */
	public static function table( string $suffix ): string {
		$constant = self::TABLE_CONSTANTS[ $suffix ] ?? null;
		if ( null !== $constant && defined( $constant ) ) {
			$table = trim( (string) constant( $constant ) );
			if ( '' !== $table ) {
				return $table;
			}
		}

		return self::prefix() . $suffix;
	}

	/**
	 * Table naming signature used for schema caching.
	 *
	 * @return string
	 */
	public static function signature(): string {
		$tables = array();

		foreach ( array_keys( self::TABLE_CONSTANTS ) as $suffix ) {
			$tables[] = self::table( $suffix );
		}

		return implode( '|', $tables );
	}
}
