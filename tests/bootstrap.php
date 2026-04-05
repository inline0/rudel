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

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		$base = defined( 'WP_HOME' ) ? rtrim( (string) WP_HOME, '/' ) : 'http://example.test';
		$path = ltrim( $path, '/' );
		return '' === $path ? $base : $base . '/' . $path;
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

if ( ! function_exists( 'wpmu_create_blog' ) ) {
	function wpmu_create_blog( string $domain, string $path, string $title, int $admin_user_id = 1 ) {
		unset( $admin_user_id );

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

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

$GLOBALS['wpdb']              = new \MockWpdb();
$GLOBALS['wpdb']->prefix      = 'wp_';
$GLOBALS['wpdb']->base_prefix = 'wp_';
$GLOBALS['rudel_test_multisite']         = true;
$GLOBALS['rudel_test_subdomain_install'] = true;
$GLOBALS['rudel_test_next_blog_id']      = 2;
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
