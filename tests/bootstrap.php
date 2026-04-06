<?php
/**
 * Test bootstrap for Rudel.
 *
 * Sets up temp directories, defines stubs for WordPress constants
 * that the source code references, and loads the Composer autoloader.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/Stubs/MockWpdb.php';

// WordPress constants used by production code.
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 1 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 1 );
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'rudel_test_site_base_url_for_blog' ) ) {
	function rudel_test_site_base_url_for_blog( int $blog_id, string $option = 'home' ): string {
		$site = $GLOBALS['rudel_test_sites'][ $blog_id ] ?? null;
		if ( is_array( $site ) && isset( $site[ $option ] ) && is_string( $site[ $option ] ) && '' !== $site[ $option ] ) {
			return rtrim( $site[ $option ], '/' );
		}

		return defined( 'WP_HOME' ) ? rtrim( (string) WP_HOME, '/' ) : 'http://example.test';
	}
}

if ( ! function_exists( 'rudel_test_append_url_path' ) ) {
	function rudel_test_append_url_path( string $base, string $path = '' ): string {
		$base = rtrim( $base, '/' );
		if ( '' === $path ) {
			return $base;
		}

		if ( '/' === $path ) {
			return $base . '/';
		}

		return $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		$base = function_exists( 'get_option' )
			? rtrim( (string) get_option( 'home', rudel_test_site_base_url_for_blog( get_current_blog_id(), 'home' ) ), '/' )
			: rudel_test_site_base_url_for_blog( get_current_blog_id(), 'home' );
		return rudel_test_append_url_path( $base, $path );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( string $path = '', ?string $scheme = null ): string {
		unset( $scheme );
		$base = function_exists( 'get_option' )
			? rtrim( (string) get_option( 'siteurl', rudel_test_site_base_url_for_blog( get_current_blog_id(), 'siteurl' ) ), '/' )
			: rudel_test_site_base_url_for_blog( get_current_blog_id(), 'siteurl' );
		return rudel_test_append_url_path( $base, $path );
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ): bool {
		return is_object( $value ) && method_exists( $value, 'get_error_message' );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['rudel_test_multisite'] ?? true );
	}
}

if ( ! function_exists( 'is_subdomain_install' ) ) {
	function is_subdomain_install(): bool {
		return (bool) ( $GLOBALS['rudel_test_subdomain_install'] ?? true );
	}
}

if ( ! function_exists( 'get_blog_details' ) ) {
	function get_blog_details( int $blog_id ) {
		$site = $GLOBALS['rudel_test_sites'][ $blog_id ] ?? null;
		return is_array( $site ) ? (object) $site : false;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['rudel_test_current_blog_id'] ?? 1 );
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['rudel_test_blog_stack']   ??= array();
		$GLOBALS['rudel_test_blog_stack'][] = get_current_blog_id();
		$GLOBALS['rudel_test_current_blog_id'] = $blog_id;
		$GLOBALS['blog_id']                    = $blog_id;
		$GLOBALS['current_blog']               = get_blog_details( $blog_id );
		$GLOBALS['table_prefix']               = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$GLOBALS['wpdb']->blogid = $blog_id;
		}
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$stack = $GLOBALS['rudel_test_blog_stack'] ?? array();
		if ( ! is_array( $stack ) || array() === $stack ) {
			return false;
		}

		$blog_id = (int) array_pop( $stack );
		$GLOBALS['rudel_test_blog_stack']      = $stack;
		$GLOBALS['rudel_test_current_blog_id'] = $blog_id;
		$GLOBALS['blog_id']                    = $blog_id;
		$GLOBALS['current_blog']               = get_blog_details( $blog_id );
		$GLOBALS['table_prefix']               = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$GLOBALS['wpdb']->blogid = $blog_id;
		}
		return true;
	}
}

if ( ! function_exists( 'wpmu_create_blog' ) ) {
	function wpmu_create_blog( string $domain, string $path, string $title, int $admin_user_id = 1 ) {
		$GLOBALS['rudel_test_last_created_blog_admin_user_id'] = $admin_user_id;

		$next_blog_id                           = (int) ( $GLOBALS['rudel_test_next_blog_id'] ?? 2 );
		$GLOBALS['rudel_test_next_blog_id']     = $next_blog_id + 1;
		$site_url                               = ( defined( 'WP_HOME' ) ? preg_replace( '#://[^/]+#', '://' . $domain, rtrim( (string) WP_HOME, '/' ) ) : 'http://' . $domain ) . rtrim( $path, '/' );
		$site_url                               = '' === $site_url || str_ends_with( $site_url, '/' ) ? $site_url : $site_url . '/';
		$GLOBALS['rudel_test_sites'][ $next_blog_id ] = array(
			'blog_id' => $next_blog_id,
			'domain'  => $domain,
			'path'    => $path,
			'siteurl' => $site_url,
			'home'    => $site_url,
			'title'   => $title,
		);

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$prefix   = $GLOBALS['wpdb']->base_prefix . $next_blog_id . '_';
			$suffixes = array(
				'posts',
				'postmeta',
				'comments',
				'commentmeta',
				'terms',
				'termmeta',
				'term_taxonomy',
				'term_relationships',
				'options',
				'links',
			);

			foreach ( $suffixes as $suffix ) {
				$table = $prefix . $suffix;
				$ddl   = 'CREATE TABLE `' . $table . '` ( `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`) )';
				$rows  = array();

				if ( 'options' === $suffix ) {
					$rows = array(
						array(
							'option_id'    => 1,
							'option_name'  => 'siteurl',
							'option_value' => $site_url,
							'autoload'     => 'yes',
							'id'           => 1,
						),
						array(
							'option_id'    => 2,
							'option_name'  => 'home',
							'option_value' => $site_url,
							'autoload'     => 'yes',
							'id'           => 2,
						),
						array(
							'option_id'    => 3,
							'option_name'  => 'blogname',
							'option_value' => $title,
							'autoload'     => 'yes',
							'id'           => 3,
						),
					);
					$ddl = 'CREATE TABLE `' . $table . '` ( `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (`option_id`) )';
				}

				$GLOBALS['wpdb']->addTable( $table, $ddl, $rows );
			}
		}

		return $next_blog_id;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['rudel_test_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'get_super_admins' ) ) {
	function get_super_admins(): array {
		$super_admins = $GLOBALS['rudel_test_super_admins'] ?? array();
		return is_array( $super_admins ) ? $super_admins : array();
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		$users = $GLOBALS['rudel_test_users'] ?? array();
		if ( ! is_array( $users ) ) {
			return false;
		}

		if ( 'login' === $field && isset( $users[ $value ] ) && is_array( $users[ $value ] ) ) {
			return (object) $users[ $value ];
		}

		if ( 'id' === $field ) {
			foreach ( $users as $user ) {
				if ( is_array( $user ) && isset( $user['ID'] ) && (int) $user['ID'] === (int) $value ) {
					return (object) $user;
				}
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wpmu_delete_blog' ) ) {
	function wpmu_delete_blog( int $blog_id, bool $drop = true ): void {
		unset( $drop );

		unset( $GLOBALS['rudel_test_sites'][ $blog_id ] );

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$prefix = $GLOBALS['wpdb']->base_prefix . $blog_id . '_';
			foreach ( $GLOBALS['wpdb']->getTableNames() as $table ) {
				if ( str_starts_with( $table, $prefix ) ) {
					$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_update_site' ) ) {
	function wp_update_site( int $blog_id, array $data ) {
		if ( ! isset( $GLOBALS['rudel_test_sites'][ $blog_id ] ) || ! is_array( $GLOBALS['rudel_test_sites'][ $blog_id ] ) ) {
			return false;
		}

		if ( isset( $data['domain'] ) && is_string( $data['domain'] ) && '' !== $data['domain'] ) {
			$GLOBALS['rudel_test_sites'][ $blog_id ]['domain'] = $data['domain'];

			$siteurl = $GLOBALS['rudel_test_sites'][ $blog_id ]['siteurl'] ?? null;
			$home    = $GLOBALS['rudel_test_sites'][ $blog_id ]['home'] ?? null;

			if ( is_string( $siteurl ) ) {
				$GLOBALS['rudel_test_sites'][ $blog_id ]['siteurl'] = preg_replace( '#://[^/]+#', '://' . $data['domain'], $siteurl );
			}
			if ( is_string( $home ) ) {
				$GLOBALS['rudel_test_sites'][ $blog_id ]['home'] = preg_replace( '#://[^/]+#', '://' . $data['domain'], $home );
			}
		}

		if ( isset( $data['path'] ) && is_string( $data['path'] ) && '' !== $data['path'] ) {
			$GLOBALS['rudel_test_sites'][ $blog_id ]['path'] = $data['path'];
		}

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		$GLOBALS['rudel_test_action_callbacks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		$GLOBALS['rudel_test_filter_callbacks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, ...$args ): void {
		$GLOBALS['rudel_test_actions'][] = array(
			'hook' => $hook,
			'args' => $args,
		);

		foreach ( $GLOBALS['rudel_test_action_callbacks'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['rudel_test_filter_callbacks'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		$GLOBALS['rudel_test_filters'][] = array(
			'hook'  => $hook,
			'value' => $value,
			'args'  => $args,
		);

		return $value;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		$pre = apply_filters( 'pre_option_' . $option, false, $option, $default );
		if ( false !== $pre ) {
			return $pre;
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$blog_id = get_current_blog_id();
			$table   = 1 === $blog_id
				? $GLOBALS['wpdb']->base_prefix . 'options'
				: $GLOBALS['wpdb']->base_prefix . $blog_id . '_options';

			foreach ( $GLOBALS['wpdb']->getTableRows( $table ) as $row ) {
				if ( ( $row['option_name'] ?? null ) === $option ) {
					$value = $row['option_value'] ?? $default;
					if ( function_exists( 'maybe_unserialize' ) ) {
						return maybe_unserialize( $value );
					}

					return $value;
				}
			}
		}

		$site = $GLOBALS['rudel_test_sites'][ get_current_blog_id() ] ?? null;
		if ( is_array( $site ) && array_key_exists( $option, $site ) ) {
			return $site[ $option ];
		}

		return $default;
	}
}

if ( ! function_exists( 'get_blog_option' ) ) {
	function get_blog_option( int $blog_id, string $option, $default = false ) {
		$previous_blog_id               = get_current_blog_id();
		$previous_table_prefix          = $GLOBALS['table_prefix'] ?? null;
		$previous_wpdb_blog_id          = isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ? $GLOBALS['wpdb']->blogid : null;
		$previous_blog                  = $GLOBALS['current_blog'] ?? null;
		$GLOBALS['rudel_test_current_blog_id'] = $blog_id;
		$GLOBALS['blog_id']                    = $blog_id;
		$GLOBALS['current_blog']               = get_blog_details( $blog_id );
		$GLOBALS['table_prefix']               = 1 === $blog_id ? 'wp_' : 'wp_' . $blog_id . '_';
		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
			$GLOBALS['wpdb']->blogid = $blog_id;
		}

		try {
			return get_option( $option, $default );
		} finally {
			$GLOBALS['rudel_test_current_blog_id'] = $previous_blog_id;
			$GLOBALS['blog_id']                    = $previous_blog_id;
			$GLOBALS['current_blog']               = $previous_blog;
			$GLOBALS['table_prefix']               = $previous_table_prefix;
			if ( null !== $previous_wpdb_blog_id && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \MockWpdb ) {
				$GLOBALS['wpdb']->blogid = $previous_wpdb_blog_id;
			}
		}
	}
}

if ( ! function_exists( 'get_home_url' ) ) {
	function get_home_url( ?int $blog_id = null, string $path = '', ?string $scheme = null ): string {
		unset( $scheme );
		if ( null === $blog_id || get_current_blog_id() === $blog_id ) {
			return home_url( $path );
		}

		$base = rtrim( (string) get_blog_option( $blog_id, 'home', rudel_test_site_base_url_for_blog( $blog_id, 'home' ) ), '/' );
		return rudel_test_append_url_path( $base, $path );
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url( ?int $blog_id = null, string $path = '', ?string $scheme = null ): string {
		unset( $scheme );
		if ( null === $blog_id || get_current_blog_id() === $blog_id ) {
			return site_url( $path );
		}

		$base = rtrim( (string) get_blog_option( $blog_id, 'siteurl', rudel_test_site_base_url_for_blog( $blog_id, 'siteurl' ) ), '/' );
		return rudel_test_append_url_path( $base, $path );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', ?string $scheme = 'admin' ): string {
		unset( $scheme );
		$admin_path = '' === $path ? 'wp-admin/' : 'wp-admin/' . ltrim( $path, '/' );
		return get_site_url( null, $admin_path );
	}
}

if ( ! function_exists( 'get_admin_url' ) ) {
	function get_admin_url( ?int $blog_id = null, string $path = '', ?string $scheme = 'admin' ): string {
		unset( $scheme );
		$admin_path = '' === $path ? 'wp-admin/' : 'wp-admin/' . ltrim( $path, '/' );
		return get_site_url( $blog_id, $admin_path );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	function maybe_serialize( $data ) {
		return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}

		$trimmed = trim( $data );
		if ( '' === $trimmed ) {
			return $data;
		}

		if ( ! preg_match( '/^(?:a|O|s|i|b|d):|^N;/', $trimmed ) ) {
			return $data;
		}

		$value = @unserialize( $data, array( 'allowed_classes' => false ) );
		return false === $value && 'b:0;' !== $trimmed ? $data : $value;
	}
}

$GLOBALS['wpdb']              = new \MockWpdb();
$GLOBALS['wpdb']->prefix      = 'wp_';
$GLOBALS['wpdb']->base_prefix = 'wp_';
$GLOBALS['wpdb']->blogid      = 1;
$GLOBALS['rudel_test_multisite']         = true;
$GLOBALS['rudel_test_subdomain_install'] = true;
$GLOBALS['rudel_test_next_blog_id']      = 2;
$GLOBALS['rudel_test_current_blog_id']   = 1;
$GLOBALS['rudel_test_blog_stack']        = array();
$GLOBALS['rudel_test_sites']             = array(
	1 => array(
		'blog_id' => 1,
		'domain'  => 'example.test',
		'path'    => '/',
		'siteurl' => 'http://example.test/',
		'home'    => 'http://example.test/',
		'title'   => 'Host Site',
	),
);
$GLOBALS['blog_id'] = 1;
$GLOBALS['current_blog'] = (object) $GLOBALS['rudel_test_sites'][1];
$GLOBALS['table_prefix'] = 'wp_';

// Global temp directory for all tests -- each test class manages its own subdirectory.
define( 'RUDEL_TEST_TMPDIR', sys_get_temp_dir() . '/rudel-tests-' . getmypid() );

// Ensure a clean slate
if ( is_dir( RUDEL_TEST_TMPDIR ) ) {
	exec( 'rm -rf ' . escapeshellarg( RUDEL_TEST_TMPDIR ) );
}
mkdir( RUDEL_TEST_TMPDIR, 0755, true );

// Cleanup on shutdown
register_shutdown_function(
	function () {
		if ( is_dir( RUDEL_TEST_TMPDIR ) ) {
			exec( 'rm -rf ' . escapeshellarg( RUDEL_TEST_TMPDIR ) );
		}
	}
);
