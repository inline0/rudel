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

$rudel_bootstrap_sapi = defined( 'RUDEL_BOOTSTRAP_SAPI' ) ? (string) RUDEL_BOOTSTRAP_SAPI : php_sapi_name();

// This file can live under a web-accessible plugin path, so refuse direct hits.
if ( 'cli' !== $rudel_bootstrap_sapi && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit;
}

// Per-environment bootstraps can preload RUDEL_ID; re-resolving here would clobber that context.
if ( defined( 'RUDEL_ID' ) ) {
	return;
}

	$rudel_bootstrap_is_app        = false;
	$rudel_bootstrap_requested_url = null;
	$rudel_bootstrap_host_url      = null;

require_once __DIR__ . '/src/RuntimeTableConfig.php';
require_once __DIR__ . '/src/BootstrapRuntimeStore.php';

	( function () use ( &$rudel_bootstrap_is_app, &$rudel_bootstrap_requested_url, &$rudel_bootstrap_host_url, $rudel_bootstrap_sapi ) {
		$plugin_dir       = __DIR__;
		$environments_dir = null;

		if ( defined( 'RUDEL_ENVIRONMENTS_DIR' ) ) {
			$environments_dir = RUDEL_ENVIRONMENTS_DIR;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$environments_dir = WP_CONTENT_DIR . '/rudel-environments';
		} else {
			$abspath          = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 2 ) . '/';
			$environments_dir = $abspath . 'wp-content/rudel-environments';
		}

		if ( defined( 'RUDEL_APPS_DIR' ) ) {
			$apps_dir = RUDEL_APPS_DIR;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$apps_dir = WP_CONTENT_DIR . '/rudel-apps';
		} else {
			$abspath  = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 2 ) . '/';
			$apps_dir = $abspath . 'wp-content/rudel-apps';
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

		if ( 'cli' !== $rudel_bootstrap_sapi ) {
			foreach ( array( 'HTTP_HOST', 'SERVER_NAME' ) as $server_key ) {
				if ( ! isset( $_SERVER[ $server_key ] ) || ! is_string( $_SERVER[ $server_key ] ) || '' === $_SERVER[ $server_key ] ) {
					continue;
				}

				$host_parts = $split_host( $_SERVER[ $server_key ] );
				if ( null !== $host_parts['port'] && ! in_array( $host_parts['port'], array( 80, 443 ), true ) && '' !== $host_parts['host'] ) {
					$_SERVER[ $server_key ] = $host_parts['host'];
				}
			}
		}

		$validate_id = function ( ?string $id ): bool {
			return $id && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $id );
		};

		$validate_record = function ( array $record ) use ( $environments_dir, $apps_dir ): ?array {
			$slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
			$path = isset( $record['path'] ) ? (string) $record['path'] : '';
			$type = isset( $record['type'] ) ? (string) $record['type'] : 'sandbox';

			if ( '' === $slug || '' === $path ) {
				return null;
			}

			$real = realpath( $path );
			if ( false === $real || ! is_dir( $real ) ) {
				return null;
			}

			$base_dir = 'app' === $type ? $apps_dir : $environments_dir;
			$base     = realpath( $base_dir );
			if ( false === $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
				return null;
			}

			return array(
				'id'        => $slug,
				'path'      => $real,
				'is_app'    => 'app' === $type,
				'record_id' => isset( $record['id'] ) ? (int) $record['id'] : null,
				'app_id'    => isset( $record['app_id'] ) ? (int) $record['app_id'] : null,
				'engine'    => isset( $record['engine'] ) ? (string) $record['engine'] : 'mysql',
				'multisite' => ! empty( $record['multisite'] ),
				'blog_id'   => isset( $record['blog_id'] ) ? (int) $record['blog_id'] : null,
			);
		};

		$sandbox_id         = null;
		$sandbox_path       = null;
		$environment_engine = 'subsite';
		$environment_blog   = null;
		$environment_multi  = false;
		$environment_row_id = null;
		$app_row_id         = null;

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

		$try_resolve = function ( string $id ) use ( $validate_id, $runtime_store, $validate_record, &$sandbox_id, &$sandbox_path, &$rudel_bootstrap_is_app, &$environment_engine, &$environment_blog, &$environment_multi, &$environment_row_id, &$app_row_id ): bool {
			if ( ! $validate_id( $id ) ) {
				return false;
			}

			$result = $runtime_store->environment_by_slug( $id );
			if ( ! is_array( $result ) ) {
				return false;
			}

			$result = $validate_record( $result );
			if ( $result ) {
				$sandbox_id             = $result['id'];
				$sandbox_path           = $result['path'];
				$rudel_bootstrap_is_app = $result['is_app'];
				$environment_engine     = $result['engine'];
				$environment_blog       = $result['blog_id'];
				$environment_multi      = $result['multisite'];
				$environment_row_id     = $result['record_id'];
				$app_row_id             = $result['app_id'];
				return true;
			}

			return false;
		};

		$try_resolve_domain = function ( string $host ) use ( $runtime_store, $validate_record, $normalize_host, &$sandbox_id, &$sandbox_path, &$rudel_bootstrap_is_app, &$environment_engine, &$environment_blog, &$environment_multi, &$environment_row_id, &$app_row_id ): bool {
			$domain = $normalize_host( $host );
			if ( '' === $domain ) {
				return false;
			}

			$result = $runtime_store->app_by_domain( $domain );
			if ( ! is_array( $result ) ) {
				return false;
			}

			$result = $validate_record( $result );
			if ( ! is_array( $result ) ) {
				return false;
			}

			$sandbox_id             = $result['id'];
			$sandbox_path           = $result['path'];
			$rudel_bootstrap_is_app = true;
			$environment_engine     = $result['engine'];
			$environment_blog       = $result['blog_id'];
			$environment_multi      = $result['multisite'];
			$environment_row_id     = $result['record_id'];
			$app_row_id             = $result['app_id'];

			return true;
		};

		$extract_cli_url            = function (): ?string {
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
		$normalize_cli_url_for_core = function ( string $url ): string {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap URL normalization.
			$parts = parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return $url;
			}

			$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
			if ( null === $port || in_array( $port, array( 80, 443 ), true ) ) {
				return $url;
			}

			$normalized = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : 'http://';
			if ( isset( $parts['user'] ) && '' !== (string) $parts['user'] ) {
				$normalized .= $parts['user'];
				if ( isset( $parts['pass'] ) && '' !== (string) $parts['pass'] ) {
					$normalized .= ':' . $parts['pass'];
				}
				$normalized .= '@';
			}

			$normalized .= $parts['host'];
			$normalized .= isset( $parts['path'] ) ? $parts['path'] : '';
			$normalized .= isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			$normalized .= isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';

			return $normalized;
		};
		$rewrite_cli_url_arguments  = function ( string $normalized_url ): void {
			$rewrite = function ( array &$args ) use ( $normalized_url ): void {
				foreach ( $args as $index => $arg ) {
					if ( ! is_string( $arg ) ) {
						continue;
					}

					if ( 0 === strpos( $arg, '--url=' ) ) {
						$args[ $index ] = '--url=' . $normalized_url;
						return;
					}

					if ( '--url' === $arg && isset( $args[ $index + 1 ] ) && is_string( $args[ $index + 1 ] ) ) {
						$args[ $index + 1 ] = $normalized_url;
						return;
					}
				}
			};

			global $argv;

			if ( isset( $argv ) && is_array( $argv ) ) {
				$rewrite( $argv );
			}
			if ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
				$rewrite( $_SERVER['argv'] );
			}
		};

		// Resolution order matters: explicit operator routing should win before host-based app lookup.
		if ( ! $sandbox_id ) {
			$header_id = $_SERVER['HTTP_X_RUDEL_SANDBOX'] ?? null;
			if ( $header_id ) {
				$try_resolve( $header_id );
			}
		}

		if ( ! $sandbox_id ) {
			$host = $_SERVER['HTTP_HOST'] ?? '';
			if ( is_string( $host ) && '' !== $host ) {
				$try_resolve_domain( $host );
			}
		}

		if ( ! $sandbox_id && 'cli' === $rudel_bootstrap_sapi ) {
			$rudel_bootstrap_requested_url = $extract_cli_url();
			if ( is_string( $rudel_bootstrap_requested_url ) && '' !== $rudel_bootstrap_requested_url ) {
				// WP-CLI multisite lookup matches against stored host-only domains, so keep the raw portful URL for Rudel-generated links but hand core a normalized host-only value.
				$rewrite_cli_url_arguments( $normalize_cli_url_for_core( $rudel_bootstrap_requested_url ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pre-WP bootstrap.
				$cli_host = parse_url( $rudel_bootstrap_requested_url, PHP_URL_HOST );
				if ( is_string( $cli_host ) && '' !== $cli_host ) {
					$try_resolve_domain( $cli_host );
				}
				if ( ! $sandbox_id && is_string( $cli_host ) && preg_match( '/^([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})\./', $normalize_host( $cli_host ), $m ) ) {
					$try_resolve( $m[1] );
				}
			}
		}

		if ( ! $sandbox_id ) {
			$host = $_SERVER['HTTP_HOST'] ?? '';
			if ( $host ) {
				$normalized_host = $normalize_host( (string) $host );
				$network_host    = $current_network_host();

				if ( '' !== $network_host && str_ends_with( $normalized_host, '.' . $network_host ) ) {
					$slug = substr( $normalized_host, 0, -strlen( '.' . $network_host ) );
					if ( '' !== $slug ) {
						$try_resolve( $slug );
					}
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
				if ( ! defined( 'RUDEL_HOST_URL' ) ) {
					define( 'RUDEL_HOST_URL', $host_url );
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

		// Keep sandboxed PHP boxed into its environment plus the minimum shared runtime paths it still needs.
		$wp_core_path = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : dirname( __DIR__, 2 );
		$paths        = array(
			$sandbox_path,
			$wp_core_path,
			$plugin_dir,
			sys_get_temp_dir(),
			'/tmp',
		);

		// WP-CLI may run from outside WordPress, so the jail has to allow the invoking binary too.
		if ( 'cli' === $rudel_bootstrap_sapi && ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$cli_dir = dirname( (string) realpath( $_SERVER['SCRIPT_FILENAME'] ) );
			if ( '' !== $cli_dir && '.' !== $cli_dir ) {
				$paths[] = $cli_dir;
			}
		}

		$allowed_paths = implode( PATH_SEPARATOR, $paths );
		if ( ! defined( 'RUDEL_DISABLE_OPEN_BASEDIR_JAIL' ) || ! RUDEL_DISABLE_OPEN_BASEDIR_JAIL ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- Intentional open_basedir jail for sandbox isolation.
			ini_set( 'open_basedir', $allowed_paths );
		}

		$_rudel_engine = $environment_engine;

		$def( 'WP_CONTENT_DIR', $sandbox_path . '/wp-content' );

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
		$def( 'RUDEL_HOST_URL', $host_url );
		$def( 'RUDEL_ENVIRONMENT_URL', $environment_url );

		$def( 'RUDEL_ENVIRONMENT_CONTENT_URL', $environment_url . '/wp-content' );
		$def( 'WP_CONTENT_URL', $environment_url . '/wp-content' );
		$def( 'WP_PLUGIN_DIR', $sandbox_path . '/wp-content/plugins' );
		$def( 'WPMU_PLUGIN_DIR', $sandbox_path . '/wp-content/mu-plugins' );
		$def( 'WP_TEMP_DIR', $sandbox_path . '/tmp' );
		$def( 'UPLOADS', 'wp-content/uploads' );

		// Keep sandbox notices out of browser output while preserving a per-environment debug trail.
		if ( ! $rudel_bootstrap_is_app ) {
			$def( 'WP_DEBUG', true );
			$def( 'WP_DEBUG_LOG', true );
			$def( 'WP_DEBUG_DISPLAY', false );
		}

		// Shared Redis/Memcached backends need an environment-specific salt or cached state will bleed across sites.
		$def( 'WP_CACHE_KEY_SALT', 'rudel_' . $sandbox_id . '_' );

		// Temporary sandboxes should be safe to preview even when the cloned site would normally send mail.
		$def( 'RUDEL_DISABLE_EMAIL', ! $rudel_bootstrap_is_app );

		// Deterministic per-environment salts keep auth cookies from bleeding across host and sandbox sessions.
		$def( 'AUTH_KEY', hash( 'sha256', $sandbox_id . 'AUTH_KEY' ) );
		$def( 'SECURE_AUTH_KEY', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_KEY' ) );
		$def( 'LOGGED_IN_KEY', hash( 'sha256', $sandbox_id . 'LOGGED_IN_KEY' ) );
		$def( 'NONCE_KEY', hash( 'sha256', $sandbox_id . 'NONCE_KEY' ) );
		$def( 'AUTH_SALT', hash( 'sha256', $sandbox_id . 'AUTH_SALT' ) );
		$def( 'SECURE_AUTH_SALT', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_SALT' ) );
		$def( 'LOGGED_IN_SALT', hash( 'sha256', $sandbox_id . 'LOGGED_IN_SALT' ) );
		$def( 'NONCE_SALT', hash( 'sha256', $sandbox_id . 'NONCE_SALT' ) );

		$def( 'WP_ALLOW_MULTISITE', true );
		$def( 'MULTISITE', true );
		$def( 'SUBDOMAIN_INSTALL', true );
		$def( 'DOMAIN_CURRENT_SITE', $current_network_host() );
		$def( 'PATH_CURRENT_SITE', '/' );
		$def( 'SITE_ID_CURRENT_SITE', 1 );
		$def( 'BLOG_ID_CURRENT_SITE', 1 );

		$def( 'RUDEL_ID', $sandbox_id );
		$def( 'RUDEL_PATH', $sandbox_path );
		$def( 'RUDEL_IS_APP', $rudel_bootstrap_is_app );
		$def( 'RUDEL_BOOTSTRAP_PLUGIN_DIR', $plugin_dir );
		$def( 'RUDEL_ENV_TYPE', $rudel_bootstrap_is_app ? 'app' : 'sandbox' );
		$def( 'RUDEL_ENGINE', $_rudel_engine );
		if ( null !== $environment_blog ) {
			$base_prefix = null;
			if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->base_prefix ) && is_string( $GLOBALS['wpdb']->base_prefix ) && '' !== $GLOBALS['wpdb']->base_prefix ) {
				$base_prefix = $GLOBALS['wpdb']->base_prefix;
			} elseif ( isset( $GLOBALS['table_prefix'] ) && is_string( $GLOBALS['table_prefix'] ) && '' !== $GLOBALS['table_prefix'] ) {
				$base_prefix = $GLOBALS['table_prefix'];
			}

			if ( is_string( $base_prefix ) && '' !== $base_prefix ) {
				$def( 'RUDEL_TABLE_PREFIX', $base_prefix . $environment_blog . '_' );
			}
		}
		$def( 'RUDEL_ENV_RECORD_ID', $environment_row_id );
		if ( null !== $app_row_id ) {
			$def( 'RUDEL_APP_RECORD_ID', $app_row_id );
		}
	} )();
unset( $rudel_bootstrap_is_app );
unset( $rudel_bootstrap_requested_url );
unset( $rudel_bootstrap_sapi );
