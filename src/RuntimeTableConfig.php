<?php
/**
 * Runtime table naming for profile-driven embedded installs.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Resolves the host-table names Rudel uses for runtime metadata.
 */
class RuntimeTableConfig {

	/**
	 * Shared runtime table-name prefix after the WordPress DB prefix.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return RuntimeProfile::current()->runtime_table_prefix();
	}

	/**
	 * Normalize one runtime table prefix.
	 *
	 * @param string $prefix Table-name prefix.
	 * @return string
	 */
	public static function normalize_prefix( string $prefix ): string {
		$prefix = trim( $prefix );
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
		return RuntimeProfile::current()->runtime_table( $suffix );
	}

	/**
	 * Table naming signature used for schema caching.
	 *
	 * @return string
	 */
	public static function signature(): string {
		$tables = array();

		foreach ( array( 'environments', 'worktrees' ) as $suffix ) {
			$tables[] = self::table( $suffix );
		}

		return implode( '|', $tables );
	}

	/**
	 * Resolve the host WordPress table prefix for Rudel-owned runtime tables.
	 *
	 * @param object|null $wpdb WordPress database object.
	 * @return string
	 */
	public static function wordpress_prefix( ?object $wpdb = null ): string {
		$host_prefix_constant = RuntimeProfile::current()->constant( 'host_table_prefix' );
		if ( defined( $host_prefix_constant ) ) {
			$prefix = constant( $host_prefix_constant );
			if ( is_string( $prefix ) && '' !== $prefix ) {
				return $prefix;
			}
		}

		if ( null === $wpdb && isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) ) {
			$wpdb = $GLOBALS['wpdb'];
		}

		if ( null !== $wpdb && isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix ) && '' !== $wpdb->base_prefix ) {
			return $wpdb->base_prefix;
		}

		return 'wp_';
	}
}
