<?php
/**
 * Rudel Environment Bootstrap
 *
 * Loaded via wp-config.php BEFORE wp-settings.php.
 * Must be entirely self-contained -- no autoloader, no WP functions.
 *
 * Detects Rudel environment context from the incoming request and sets all
 * relevant WordPress constants to point to the resolved environment.
 * If no Rudel environment is detected, host WordPress boots normally.
 *
 * @package Rudel
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Pre-WP bootstrap; wp_unslash/sanitize unavailable. All values validated by regex.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Pre-WP bootstrap; no WP functions available.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: setting $table_prefix for environment isolation.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

require_once __DIR__ . '/src/RuntimeProfile.php';

$rudel_bootstrap_sapi    = defined( 'RUDEL_BOOTSTRAP_SAPI' ) ? (string) RUDEL_BOOTSTRAP_SAPI : php_sapi_name();
$rudel_bootstrap_profile = \Rudel\RuntimeProfile::current();

// This file can live under a web-accessible plugin path, so refuse direct hits.
if ( 'cli' !== $rudel_bootstrap_sapi && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit;
}

// Per-environment bootstraps can preload the active ID; re-resolving here would clobber that context.
if ( defined( $rudel_bootstrap_profile->constant( 'id' ) ) ) {
	return;
}

	$rudel_bootstrap_requested_url = null;
	$rudel_bootstrap_host_url      = null;

require_once __DIR__ . '/src/BootstrapRuntimeStore.php';
require_once __DIR__ . '/src/RuntimeTableConfig.php';

	( function () use ( &$rudel_bootstrap_requested_url, &$rudel_bootstrap_host_url, $rudel_bootstrap_sapi, $rudel_bootstrap_profile ) {
		$plugin_dir       = __DIR__;
		$environments_dir = null;

		$environments_dir_constant = $rudel_bootstrap_profile->environments_dir_constant();
		if ( defined( $environments_dir_constant ) && is_string( constant( $environments_dir_constant ) ) ) {
			$environments_dir = constant( $environments_dir_constant );
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$environments_dir = WP_CONTENT_DIR . '/' . $rudel_bootstrap_profile->environments_dir_name();
		} else {
			$abspath          = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 2 ) . '/';
			$environments_dir = $abspath . 'wp-content/' . $rudel_bootstrap_profile->environments_dir_name();
		}

		$runtime_store = new \Rudel\BootstrapRuntimeStore();
		$split_host    = function ( string $host ): array {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap host parsing.
			$parts       = parse_url( '//' . ltrim( $host, '/' ) );
			$parsed_host = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : (string) preg_replace( '/:\d+$/', '', $host );
			$parsed_port = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : null;

			return array(
				'host' => $parsed_host,
				'port' => $parsed_port,
			);
		};
		$raw_http_host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		$raw_server    = isset( $_SERVER['SERVER_NAME'] ) && is_string( $_SERVER['SERVER_NAME'] ) ? $_SERVER['SERVER_NAME'] : '';
		$raw_request   = '' !== $raw_http_host ? $raw_http_host : ( '' !== $raw_server ? $raw_server : 'localhost' );
		$raw_parts     = $split_host( $raw_request );
		$raw_host      = isset( $raw_parts['host'] ) ? (string) $raw_parts['host'] : '';
		$raw_port      = isset( $raw_parts['port'] ) ? $raw_parts['port'] : null;

		$validate_id = function ( ?string $id ): bool {
			return $id && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $id );
		};

		$validate_record = function ( array $record ) use ( $environments_dir ): ?array {
			$slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
			$path = isset( $record['path'] ) ? (string) $record['path'] : '';
			$type = isset( $record['type'] ) ? (string) $record['type'] : 'sandbox';

			if ( 'sandbox' !== $type || '' === $slug || '' === $path ) {
				return null;
			}

			$real = realpath( $path );
			if ( false === $real || ! is_dir( $real ) ) {
				return null;
			}

			$base = realpath( $environments_dir );
			if ( false === $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
				return null;
			}

			return array(
				'id'           => $slug,
				'path'         => $real,
				'record_id'    => isset( $record['id'] ) ? (int) $record['id'] : null,
				'engine'       => isset( $record['engine'] ) ? (string) $record['engine'] : 'overlay',
				'multisite'    => ! empty( $record['multisite'] ),
				'blog_id'      => isset( $record['blog_id'] ) ? (int) $record['blog_id'] : null,
				'table_prefix' => isset( $record['table_prefix'] ) && is_string( $record['table_prefix'] ) ? $record['table_prefix'] : null,
				'theme_slug'   => isset( $record['theme_slug'] ) && is_string( $record['theme_slug'] ) ? $record['theme_slug'] : null,
			);
		};

		$sandbox_id         = null;
		$sandbox_path       = null;
		$environment_engine = 'overlay';
		$environment_blog   = null;
		$environment_multi  = false;
		$environment_row_id = null;
		$environment_prefix = null;
		$environment_theme  = null;

		$normalize_host = function ( string $host ): string {
			return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
		};

		$current_network_host = function () use ( $normalize_host ): string {
			if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
				$host = $normalize_host( (string) DOMAIN_CURRENT_SITE );
				if ( '' !== $host ) {
					return $host;
				}
			}

			if ( defined( 'WP_HOME' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap.
				$home_parts = parse_url( (string) WP_HOME );
				if ( is_array( $home_parts ) && ! empty( $home_parts['host'] ) ) {
					return $normalize_host( (string) $home_parts['host'] );
				}
			}

			return $normalize_host( $_SERVER['HTTP_HOST'] ?? 'localhost' );
		};

		$try_resolve = function ( string $id ) use ( $validate_id, $runtime_store, $validate_record, &$sandbox_id, &$sandbox_path, &$environment_engine, &$environment_blog, &$environment_multi, &$environment_row_id, &$environment_prefix, &$environment_theme ): bool {
			if ( ! $validate_id( $id ) ) {
				return false;
			}

			$result = $runtime_store->environment_by_slug( $id );
			if ( ! is_array( $result ) ) {
				return false;
			}

			$result = $validate_record( $result );
			if ( $result ) {
				$sandbox_id         = $result['id'];
				$sandbox_path       = $result['path'];
				$environment_engine = $result['engine'];
				$environment_blog   = $result['blog_id'];
				$environment_multi  = $result['multisite'];
				$environment_row_id = $result['record_id'];
				$environment_prefix = $result['table_prefix'];
				$environment_theme  = $result['theme_slug'];
				return true;
			}

			return false;
		};

		$extract_cli_url = function (): ?string {
			$argv_sources = array();
			global $argv;

			if ( isset( $argv ) && is_array( $argv ) ) {
				$argv_sources[] = $argv;
			}
			if ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
				$argv_sources[] = $_SERVER['argv'];
			}

			foreach ( $argv_sources as $args ) {
				foreach ( $args as $index => $arg ) {
					if ( 0 === strpos( $arg, '--url=' ) ) {
						return substr( $arg, 6 );
					}
					if ( '--url' === $arg && isset( $args[ $index + 1 ] ) && is_string( $args[ $index + 1 ] ) ) {
						return $args[ $index + 1 ];
					}
				}
			}

			return null;
		};

		$theme_template_slug = function ( string $theme_root, string $theme_slug ): string {
			$style_css = rtrim( $theme_root, '/' ) . '/' . $theme_slug . '/style.css';
			if ( ! is_file( $style_css ) ) {
				return $theme_slug;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pre-WP bootstrap reads local theme headers without WordPress helpers.
			$contents = file_get_contents( $style_css );
			if ( ! is_string( $contents ) || '' === $contents ) {
				return $theme_slug;
			}

			if ( 1 === preg_match( '/^[ \t\/*#@]*Template:\s*([A-Za-z0-9_.-]+)/mi', $contents, $matches ) ) {
				return (string) $matches[1];
			}

			return $theme_slug;
		};

		if ( ! $sandbox_id && 'cli' === $rudel_bootstrap_sapi ) {
			$env_id = getenv( $rudel_bootstrap_profile->environment_variable_name() );
			if ( is_string( $env_id ) && '' !== $env_id ) {
				$try_resolve( $env_id );
			}
		}

		if ( ! $sandbox_id ) {
			$header_id = $_SERVER[ $rudel_bootstrap_profile->server_header_key() ] ?? null;
			if ( $header_id ) {
				$try_resolve( $header_id );
			}
		}

		if ( ! $sandbox_id ) {
			$cookie_id = $_COOKIE[ $rudel_bootstrap_profile->cookie_name() ] ?? null;
			if ( is_string( $cookie_id ) && '' !== $cookie_id ) {
				$try_resolve( $cookie_id );
			}
		}

		if ( ! $sandbox_id && 'cli' === $rudel_bootstrap_sapi && $rudel_bootstrap_profile->url_subdomain_enabled() ) {
			$rudel_bootstrap_requested_url = $extract_cli_url();
			if ( is_string( $rudel_bootstrap_requested_url ) && '' !== $rudel_bootstrap_requested_url ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap.
				$cli_host = parse_url( $rudel_bootstrap_requested_url, PHP_URL_HOST );
				if ( ! $sandbox_id && is_string( $cli_host ) && preg_match( '/^([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})\./', $normalize_host( $cli_host ), $m ) ) {
					$try_resolve( $m[1] );
				}
			}
		}

		if ( ! $sandbox_id || ! $sandbox_path ) {
			if ( 'cli' !== $rudel_bootstrap_sapi && '' !== $raw_host && null !== $raw_port && ! in_array( $raw_port, array( 80, 443 ), true ) && $normalize_host( $raw_host ) === $current_network_host() ) {
				$protocol = 'http';
				if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && in_array( $_SERVER['HTTP_X_FORWARDED_PROTO'], array( 'http', 'https' ), true ) ) {
					$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
				} elseif ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
					$protocol = 'https';
				}

				$host_url                 = rtrim( $protocol . '://' . $raw_host . ':' . $raw_port, '/' );
				$rudel_bootstrap_host_url = $host_url;
				$host_url_constant        = $rudel_bootstrap_profile->constant( 'host_url' );
				if ( ! defined( $host_url_constant ) ) {
					define( $host_url_constant, $host_url );
				}
			}

			return;
		}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Dynamic constant names for WP config.
		$def = function ( string $name, mixed $value ): void {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		};

		$_rudel_engine = $environment_engine;

		// Respect an explicit CLI target URL so generated links and rewrites stay on the requested origin.
		$protocol = 'http';
		$host     = $raw_request;
		if ( is_string( $rudel_bootstrap_requested_url ) && '' !== $rudel_bootstrap_requested_url ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap.
			$requested_parts = parse_url( $rudel_bootstrap_requested_url );
			if ( is_array( $requested_parts ) && ! empty( $requested_parts['host'] ) ) {
				$protocol = isset( $requested_parts['scheme'] ) ? $requested_parts['scheme'] : 'http';
				$host     = $requested_parts['host'];
				if ( isset( $requested_parts['port'] ) ) {
					$host .= ':' . $requested_parts['port'];
				}
			}
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && in_array( $_SERVER['HTTP_X_FORWARDED_PROTO'], array( 'http', 'https' ), true ) ) {
			$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
		} elseif ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
			$protocol = 'https';
		}
		if ( ! is_string( $host ) || '' === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
		}
		$site_url  = rtrim( $protocol . '://' . $host, '/' );
		$host_bits = $split_host( (string) $host );
		$host_port = null !== $host_bits['port'] ? ':' . $host_bits['port'] : '';
		$host_url  = rtrim( $protocol . '://' . $current_network_host() . $host_port, '/' );

		$environment_url = $site_url;
		$def( $rudel_bootstrap_profile->constant( 'host_table_prefix' ), $runtime_store->base_prefix() );
		$def( $rudel_bootstrap_profile->constant( 'host_url' ), $host_url );
		$def( $rudel_bootstrap_profile->constant( 'environment_url' ), $environment_url );
		$def( 'WP_TEMP_DIR', $sandbox_path . '/tmp' );

		// Keep sandbox notices out of browser output while preserving a per-environment debug trail.
		$def( 'WP_DEBUG', true );
		$def( 'WP_DEBUG_LOG', $sandbox_path . '/debug.log' );
		$def( 'WP_DEBUG_DISPLAY', false );

		// Shared Redis/Memcached backends need an environment-specific salt or cached state will bleed across sites.
		$def( 'WP_CACHE_KEY_SALT', $rudel_bootstrap_profile->cache_key_salt( $sandbox_id ) );

		// Temporary sandboxes should be safe to preview even when the cloned site would normally send mail.
		$def( $rudel_bootstrap_profile->constant( 'disable_email' ), true );

		// Deterministic per-environment salts keep auth cookies from bleeding across host and sandbox sessions.
		$def( 'AUTH_KEY', hash( 'sha256', $sandbox_id . 'AUTH_KEY' ) );
		$def( 'SECURE_AUTH_KEY', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_KEY' ) );
		$def( 'LOGGED_IN_KEY', hash( 'sha256', $sandbox_id . 'LOGGED_IN_KEY' ) );
		$def( 'NONCE_KEY', hash( 'sha256', $sandbox_id . 'NONCE_KEY' ) );
		$def( 'AUTH_SALT', hash( 'sha256', $sandbox_id . 'AUTH_SALT' ) );
		$def( 'SECURE_AUTH_SALT', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_SALT' ) );
		$def( 'LOGGED_IN_SALT', hash( 'sha256', $sandbox_id . 'LOGGED_IN_SALT' ) );
		$def( 'NONCE_SALT', hash( 'sha256', $sandbox_id . 'NONCE_SALT' ) );

		$def( $rudel_bootstrap_profile->constant( 'id' ), $sandbox_id );
		$def( $rudel_bootstrap_profile->constant( 'path' ), $sandbox_path );
		$def( $rudel_bootstrap_profile->constant( 'plugin_dir' ), $plugin_dir );
		$def( $rudel_bootstrap_profile->constant( 'env_type' ), 'sandbox' );
		$def( $rudel_bootstrap_profile->constant( 'engine' ), $_rudel_engine );
		if ( is_string( $environment_prefix ) && '' !== $environment_prefix ) {
			$GLOBALS['table_prefix'] = $environment_prefix;
			$def( $rudel_bootstrap_profile->constant( 'table_prefix' ), $environment_prefix );
		}
		if ( is_string( $environment_theme ) && '' !== $environment_theme ) {
			$theme_root = $sandbox_path . '/themes';
			$def( $rudel_bootstrap_profile->constant( 'theme_slug' ), $environment_theme );
			$def( $rudel_bootstrap_profile->constant( 'template_slug' ), $theme_template_slug( $theme_root, $environment_theme ) );
			$def( $rudel_bootstrap_profile->constant( 'theme_root' ), $theme_root );
			$def( $rudel_bootstrap_profile->constant( 'theme_root_uri' ), $environment_url . '/wp-content/' . $rudel_bootstrap_profile->environment_content_url_path() . '/' . rawurlencode( $sandbox_id ) . '/themes' );
		}
		$def( $rudel_bootstrap_profile->constant( 'record_id' ), $environment_row_id );
	} )();

$rudel_table_prefix_constant = $rudel_bootstrap_profile->constant( 'table_prefix' );
if ( defined( $rudel_table_prefix_constant ) && is_string( constant( $rudel_table_prefix_constant ) ) && '' !== constant( $rudel_table_prefix_constant ) ) {
	$table_prefix            = constant( $rudel_table_prefix_constant );
	$GLOBALS['table_prefix'] = constant( $rudel_table_prefix_constant );
}
unset( $rudel_bootstrap_requested_url );
unset( $rudel_bootstrap_profile );
unset( $rudel_bootstrap_sapi );
