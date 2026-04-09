<?php

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function get_error_message(): string {
			return '';
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix      = 'wp_';
		public string $base_prefix = 'wp_';
		public int $insert_id      = 0;

		public function __construct(
			string $dbuser = '',
			string $dbpassword = '',
			string $dbname = '',
			string $dbhost = ''
		) {}

		public function query( string $query ): int|false {
			return 0;
		}

		public function prepare( string $query, mixed ...$args ): string {
			return $query;
		}

		public function esc_like( string $text ): string {
			return $text;
		}

		/**
		 * @return array<int, mixed>
		 */
		public function get_results( string $query, string $output = OBJECT ): array {
			return array();
		}

		/**
		 * @return array<int, mixed>
		 */
		public function get_col( string $query ): array {
			return array();
		}

		public function get_var( ?string $query = null ): mixed {
			return null;
		}

		public function get_row( string $query, string $output = OBJECT ): mixed {
			return null;
		}

		/**
		 * @param array<string, mixed>    $data
		 * @param array<int, string>|null $format
		 */
		public function insert( string $table, array $data, ?array $format = null ): int|false {
			return 1;
		}

		/**
		 * @param array<string, mixed>    $data
		 * @param array<string, mixed>    $where
		 * @param array<int, string>|null $format
		 * @param array<int, string>|null $where_format
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|false {
			return 1;
		}

		/**
		 * @param array<string, mixed>    $where
		 * @param array<int, string>|null $where_format
		 */
		public function delete( string $table, array $where, ?array $where_format = null ): int|false {
			return 1;
		}

		public function suppress_errors( bool $suppress = true ): bool {
			return $suppress;
		}

		public function set_prefix( string $prefix, bool $set_table_names = true ): string {
			$this->prefix      = $prefix;
			$this->base_prefix = $prefix;
			return $prefix;
		}

		public function get_charset_collate(): string {
			return '';
		}
	}
}

/** @var wpdb $wpdb */
$wpdb = new wpdb();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, mixed ...$args ): void {}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return WP_HOME;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool|int $gmt = false ): string|int {
		return 'mysql' === $type ? '2026-01-01 00:00:00' : time();
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @phpstan-assert-if-true WP_Error $thing
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_blog_details' ) ) {
	function get_blog_details( mixed $fields = null, bool $get_all = true ): object|false {
		return (object) array(
			'siteurl' => WP_HOME,
		);
	}
}

if ( ! function_exists( 'wpmu_create_blog' ) ) {
	function wpmu_create_blog( string $domain, string $path, string $title, int $user_id, array $options = array(), int $network_id = 1 ): int|WP_Error {
		return 1;
	}
}

if ( ! function_exists( 'wpmu_delete_blog' ) ) {
	function wpmu_delete_blog( int $blog_id, bool $drop = false ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = array() ): int|false {
		return 0;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default_value = false ): mixed {
		return $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
		return true;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( mixed $data ): string {
		return serialize( $data );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( mixed $data ): mixed {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		$value = @unserialize( $data, array( 'allowed_classes' => false ) );

		return false === $value && 'b:0;' !== $data ? $data : $value;
	}
}
