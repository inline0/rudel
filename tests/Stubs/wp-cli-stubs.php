<?php
/**
 * Minimal WP-CLI stubs for unit testing RudelCommand.
 *
 * @package Rudel\Tests
 */

// phpcs:disable WordPress.NamingConventions, Squiz.Classes.ClassFileName, Squiz.Commenting

namespace {

	class WP_CLI_Command {
	}

	class WP_CLI {
		public static array $log           = array();
		public static array $successes     = array();
		public static array $errors        = array();
		public static array $confirmations = array();

		public static function reset(): void {
			self::$log           = array();
			self::$successes     = array();
			self::$errors        = array();
			self::$confirmations = array();
		}

		public static function log( string $message ): void {
			self::$log[] = $message;
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		/**
		 * @throws \RuntimeException Always, mimicking WP_CLI::error() exit behavior.
		 */
		public static function error( string $message ): void {
			self::$errors[] = $message;
			throw new \RuntimeException( $message );
		}

		public static function confirm( string $message ): void {
			self::$confirmations[] = $message;
		}
	}
}

namespace WP_CLI\Utils {

	function format_items( string $format, array $items, array $fields ): void {
		\WP_CLI::$log[] = array(
			'__format_items' => true,
			'format'         => $format,
			'items'          => $items,
			'fields'         => $fields,
		);
	}

	function get_flag_value( array $assoc_args, string $flag, $default = null ) {
		return $assoc_args[ $flag ] ?? $default;
	}
}
