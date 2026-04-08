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
	 * Serialized prefixes WordPress data commonly starts with.
	 *
	 * @var string[]
	 */
	private const SERIALIZED_PREFIXES = array( 'a', 'O', 's', 'b', 'i', 'd', 'N', 'C' );

	/**
	 * Replace strings in a value, preserving serialized PHP payloads when present.
	 *
	 * @param string $value   The value to process.
	 * @param string $search  The string to search for.
	 * @param string $replace The replacement string.
	 * @return string The processed value.
	 */
	public static function apply( string $value, string $search, string $replace ): string {
		if ( ! str_contains( $value, $search ) ) {
			return $value;
		}

		if ( ! self::looks_serialized( $value ) ) {
			return str_replace( $search, $replace, $value );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Serialized data handling required for WordPress data migration.
		$unserialized = @unserialize( $value );

		if ( false !== $unserialized || 'b:0;' === $value ) {
			$unserialized = self::walk( $unserialized, $search, $replace );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Re-serializing WordPress data after replacement.
			return serialize( $unserialized );
		}

		return str_replace( $search, $replace, $value );
	}

	/**
	 * Fast serialized-payload check so plain strings do not pay an unserialize() cost.
	 *
	 * @param string $value Value under inspection.
	 * @return bool
	 */
	private static function looks_serialized( string $value ): bool {
		$value = trim( $value );

		if ( '' === $value || strlen( $value ) < 2 ) {
			return false;
		}

		if ( 'N;' === $value ) {
			return true;
		}

		$prefix = $value[0];
		if ( ! in_array( $prefix, self::SERIALIZED_PREFIXES, true ) || ':' !== $value[1] ) {
			return false;
		}

		$last = substr( $value, -1 );

		if ( in_array( $prefix, array( 'a', 'O', 'C' ), true ) ) {
			return '}' === $last;
		}

		return ';' === $last;
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
