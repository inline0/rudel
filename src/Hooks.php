<?php
/**
 * Minimal wrapper around WordPress hooks.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Dispatches Rudel lifecycle hooks when WordPress is available.
 */
class Hooks {

	/**
	 * Run a WordPress action when hooks are available.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Hook arguments.
	 * @return void
	 */
	public static function action( string $hook, ...$args ): void {
		if ( function_exists( 'do_action' ) ) {
			do_action( $hook, ...$args );
		}
	}

	/**
	 * Run a WordPress filter when hooks are available.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  $value Filtered value.
	 * @param mixed  ...$args Additional filter arguments.
	 * @return mixed
	 */
	public static function filter( string $hook, $value, ...$args ) {
		if ( function_exists( 'apply_filters' ) ) {
			return apply_filters( $hook, $value, ...$args );
		}

		return $value;
	}
}
