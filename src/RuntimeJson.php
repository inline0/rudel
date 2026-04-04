<?php
/**
 * JSON encoding helper for WordPress and pure-PHP runtime paths.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Uses WordPress JSON handling when available without making non-WP runtimes depend on it.
 */
final class RuntimeJson {

	/**
	 * Encode one value to JSON.
	 *
	 * @param mixed $value JSON-serializable value.
	 * @param int   $flags json_encode flags.
	 * @param int   $depth Maximum encoding depth.
	 * @return string
	 *
	 * @throws \RuntimeException When encoding fails.
	 */
	public static function encode( $value, int $flags = 0, int $depth = 512 ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$json = wp_json_encode( $value, $flags, $depth );
		} else {
			// Lifecycle scripts exercise managers without booting WordPress, so runtime repositories cannot assume wp_json_encode() exists.
			$json = json_encode( $value, $flags, $depth );
		}

		if ( false === $json ) {
			throw new \RuntimeException( 'Failed to encode JSON payload.' );
		}

		return $json;
	}
}
