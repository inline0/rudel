<?php
/**
 * Shared serialized string search/replace helper.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Performs string replacement while preserving serialized PHP payloads.
 */
class SerializedSearchReplace {

	/**
	 * Replace strings in a value, preserving serialized PHP payloads when present.
	 *
	 * @param string $value   The value to process.
	 * @param string $search  The string to search for.
	 * @param string $replace The replacement string.
	 * @return string The processed value.
	 */
	public static function apply( string $value, string $search, string $replace ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Serialized data handling required for WordPress data migration.
		$unserialized = @unserialize( $value );

		if ( false !== $unserialized ) {
			$unserialized = self::walk( $unserialized, $search, $replace );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Re-serializing WordPress data after replacement.
			return serialize( $unserialized );
		}

		return str_replace( $search, $replace, $value );
	}

	/**
	 * Recursively walk nested arrays and objects for string replacement.
	 *
	 * @param mixed  $data    The data to process.
	 * @param string $search  The string to search for.
	 * @param string $replace The replacement string.
	 * @return mixed The processed data.
	 */
	private static function walk( $data, string $search, string $replace ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			$result = array();
			foreach ( $data as $key => $value ) {
				$new_key            = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
				$result[ $new_key ] = self::walk( $value, $search, $replace );
			}
			return $result;
		}

		if ( is_object( $data ) ) {
			$props = get_object_vars( $data );
			foreach ( $props as $prop => $value ) {
				$data->$prop = self::walk( $value, $search, $replace );
			}
			return $data;
		}

		return $data;
	}
}
