<?php
/**
 * Helpers for prefix-based Rudel preview requests.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Normalize and inspect prefixed preview request paths.
 */
class PreviewRequest {

	/**
	 * Build the canonical preview base path for an environment.
	 *
	 * @param string      $environment_id Preview environment slug.
	 * @param string|null $path_prefix    Optional path prefix override.
	 * @return string
	 */
	public static function base_path( string $environment_id, ?string $path_prefix = null ): string {
		$prefix = self::normalize_prefix( $path_prefix );
		return '/' . $prefix . '/' . $environment_id . '/';
	}

	/**
	 * Extract a preview environment slug from a request URI.
	 *
	 * @param string      $request_uri Request URI or absolute URL.
	 * @param string|null $path_prefix Optional path prefix override.
	 * @return string|null
	 */
	public static function extract_environment_id( string $request_uri, ?string $path_prefix = null ): ?string {
		$path = self::request_path( $request_uri );
		if ( ! is_string( $path ) ) {
			return null;
		}

		$prefix = self::normalize_prefix( $path_prefix );
		if ( preg_match( '#^/' . preg_quote( $prefix, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})(?:/|$)#', $path, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Strip the outer preview prefix so WordPress sees the in-environment request path.
	 *
	 * @param string      $request_uri     Request URI or absolute URL.
	 * @param string      $environment_id  Preview environment slug.
	 * @param string|null $path_prefix     Optional path prefix override.
	 * @return string
	 */
	public static function strip_prefix( string $request_uri, string $environment_id, ?string $path_prefix = null ): string {
		$base = rtrim( self::base_path( $environment_id, $path_prefix ), '/' );
		if ( 0 !== strpos( $request_uri, $base ) ) {
			return $request_uri;
		}

		$stripped = substr( $request_uri, strlen( $base ) );
		if ( '' === $stripped ) {
			return '/';
		}

		if ( '/' === $stripped[0] || '?' === $stripped[0] ) {
			return $stripped;
		}

		return '/' . ltrim( $stripped, '/' );
	}

	/**
	 * Normalize the configured preview prefix.
	 *
	 * @param string|null $path_prefix Optional path prefix override.
	 * @return string
	 */
	private static function normalize_prefix( ?string $path_prefix = null ): string {
		$prefix = $path_prefix;
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$prefix = defined( 'RUDEL_PATH_PREFIX' ) ? (string) RUDEL_PATH_PREFIX : '__rudel';
		}

		return trim( $prefix, '/' );
	}

	/**
	 * Parse the path component from a request URI or absolute URL.
	 *
	 * @param string $request_uri Request URI or absolute URL.
	 * @return string|null
	 */
	private static function request_path( string $request_uri ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Request routing happens before WordPress helpers are available.
		$path = parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		return '/' . ltrim( $path, '/' );
	}
}
