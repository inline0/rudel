<?php
/**
 * Rudel Sandbox Bootstrap
 *
 * Loaded via wp-config.php BEFORE wp-settings.php.
 * Must be entirely self-contained -- no autoloader, no WP functions.
 *
 * Detects sandbox context from the incoming request and sets all
 * relevant WordPress constants to point to the sandbox's isolated environment.
 * If no sandbox context is detected, does nothing -- host WP boots normally.
 *
 * @package Rudel
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Pre-WP bootstrap; wp_unslash/sanitize unavailable. All values validated by regex.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Pre-WP bootstrap; no WP functions available.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional: setting $table_prefix for sandbox isolation.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound

// This file can live under a web-accessible plugin path, so refuse direct hits.
if ( 'cli' !== php_sapi_name() && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit;
}

// Per-environment bootstraps can preload RUDEL_ID; re-resolving here would clobber that context.
if ( defined( 'RUDEL_ID' ) ) {
	return;
}

$rudel_bootstrap_prefix        = null;
$rudel_bootstrap_is_app        = false;
$rudel_bootstrap_requested_url = null;

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

require_once __DIR__ . '/src/BootstrapRuntimeStore.php';

( function () use ( &$rudel_bootstrap_prefix, &$rudel_bootstrap_is_app, &$rudel_bootstrap_requested_url ) {
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

	if ( ! is_dir( $environments_dir ) && ! is_dir( $apps_dir ) ) {
		return;
	}

	$runtime_store = new \Rudel\BootstrapRuntimeStore();

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
	$environment_engine = 'mysql';
	$environment_blog   = null;
	$environment_multi  = false;
	$environment_row_id = null;
	$app_row_id         = null;

	$normalize_host = function ( string $host ): string {
		return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
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

	// Resolution order matters: explicit routing and operator-controlled signals should win before URL heuristics.
	if ( ! $sandbox_id ) {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( is_string( $host ) && '' !== $host ) {
			$try_resolve_domain( $host );
		}
	}

	if ( ! $sandbox_id ) {
		$header_id = $_SERVER['HTTP_X_RUDEL_SANDBOX'] ?? null;
		if ( $header_id ) {
			$try_resolve( $header_id );
		}
	}

	if ( ! $sandbox_id ) {
		$cookie_id = $_COOKIE['rudel_sandbox'] ?? null;
		if ( $cookie_id ) {
			$try_resolve( $cookie_id );
		}
	}

	if ( ! $sandbox_id && 'cli' === php_sapi_name() ) {
		$rudel_bootstrap_requested_url = $extract_cli_url();
		if ( is_string( $rudel_bootstrap_requested_url ) && '' !== $rudel_bootstrap_requested_url ) {
			if ( preg_match( '#/' . preg_quote( RUDEL_PATH_PREFIX, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $rudel_bootstrap_requested_url, $m ) ) {
				$try_resolve( $m[1] );
			}

			if ( ! $sandbox_id ) {
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
	}

	// Admin traffic can bypass the routed front controller, so exiting has to clear the cookie here too.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Stateless exit action, no side effects beyond clearing a cookie.
	if ( 'cli' !== php_sapi_name() && isset( $_GET['adminExit'] ) ) {
		setcookie( 'rudel_sandbox', '', time() - 3600, '/' );
		unset( $_COOKIE['rudel_sandbox'] );
		$protocol = 'http';
		if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
			$protocol = 'https';
		}
		$redirect = $protocol . '://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . '/';
		header( 'Location: ' . $redirect, true, 302 );
		exit;
	}

	if ( ! $sandbox_id ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#^/' . preg_quote( RUDEL_PATH_PREFIX, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $uri, $m ) ) {
			$try_resolve( $m[1] );
		}
	}

	if ( ! $sandbox_id ) {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( $host ) {
			$parts = explode( '.', $host );
			if ( count( $parts ) >= 3 ) {
				$try_resolve( $parts[0] );
			}
		}
	}

	if ( ! $sandbox_id || ! $sandbox_path ) {
		return;
	}

	// Admin and direct PHP requests may bypass index.php, so persist context in a cookie for the rest of WordPress.
	if ( 'cli' !== php_sapi_name() && ! $rudel_bootstrap_is_app ) {
		$cookie_id = $_COOKIE['rudel_sandbox'] ?? null;
		if ( $cookie_id !== $sandbox_id ) {
			setcookie( 'rudel_sandbox', $sandbox_id, 0, '/' );
			$_COOKIE['rudel_sandbox'] = $sandbox_id;
		}
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
	if ( 'cli' === php_sapi_name() && ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
		$cli_dir = dirname( (string) realpath( $_SERVER['SCRIPT_FILENAME'] ) );
		if ( '' !== $cli_dir && '.' !== $cli_dir ) {
			$paths[] = $cli_dir;
		}
	}

	$allowed_paths = implode( PATH_SEPARATOR, $paths );
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Intentional open_basedir jail for sandbox isolation.
	ini_set( 'open_basedir', $allowed_paths );

	// WordPress reads DB constants during bootstrap, so the engine choice has to be known first.
	$_rudel_engine = $environment_engine;

	if ( 'sqlite' === $_rudel_engine ) {
		$def( 'DB_DIR', $sandbox_path );
		$def( 'DB_FILE', 'wordpress.db' ); // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- Filename.
		$def( 'DATABASE_TYPE', 'sqlite' );
		$def( 'DB_ENGINE', 'sqlite' );
	}

	$def( 'WP_CONTENT_DIR', $sandbox_path . '/wp-content' );

	// Respect an explicit CLI target URL so generated links and rewrites stay on the requested origin.
	$protocol = 'http';
	$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
	$site_url = rtrim( $protocol . '://' . $host, '/' );

	if ( $rudel_bootstrap_is_app ) {
		$environment_url = $site_url;
	} else {
		$environment_url = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id;
	}
	$def( 'WP_SITEURL', $environment_url );
	$def( 'WP_HOME', $environment_url );

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

	// Multisite subsites already rely on WordPress's blog-scoped prefixes; overriding them would break network tables.
	if ( 'subsite' !== $_rudel_engine ) {
		$rudel_bootstrap_prefix  = 'rudel_' . substr( md5( $sandbox_id ), 0, 6 ) . '_';
		$GLOBALS['table_prefix'] = $rudel_bootstrap_prefix;
		$def( 'RUDEL_TABLE_PREFIX', $rudel_bootstrap_prefix );
	}

	// Deterministic per-environment salts keep auth cookies from bleeding across host and sandbox sessions.
	$def( 'AUTH_KEY', hash( 'sha256', $sandbox_id . 'AUTH_KEY' ) );
	$def( 'SECURE_AUTH_KEY', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_KEY' ) );
	$def( 'LOGGED_IN_KEY', hash( 'sha256', $sandbox_id . 'LOGGED_IN_KEY' ) );
	$def( 'NONCE_KEY', hash( 'sha256', $sandbox_id . 'NONCE_KEY' ) );
	$def( 'AUTH_SALT', hash( 'sha256', $sandbox_id . 'AUTH_SALT' ) );
	$def( 'SECURE_AUTH_SALT', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_SALT' ) );
	$def( 'LOGGED_IN_SALT', hash( 'sha256', $sandbox_id . 'LOGGED_IN_SALT' ) );
	$def( 'NONCE_SALT', hash( 'sha256', $sandbox_id . 'NONCE_SALT' ) );

	// Mirror multisite shape from metadata so WordPress keeps generating network-aware URLs inside the environment.
	if ( $environment_multi ) {
		$def( 'WP_ALLOW_MULTISITE', true );
		$def( 'MULTISITE', true );
		$def( 'SUBDOMAIN_INSTALL', false );
		$def( 'DOMAIN_CURRENT_SITE', $normalize_host( $host ) );
		$def( 'PATH_CURRENT_SITE', $rudel_bootstrap_is_app ? '/' : '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id . '/' );
		$def( 'SITE_ID_CURRENT_SITE', 1 );
		$def( 'BLOG_ID_CURRENT_SITE', null !== $environment_blog ? $environment_blog : 1 );
	} else {
		$def( 'MULTISITE', false );
		$def( 'WP_ALLOW_MULTISITE', false );
	}

	$def( 'RUDEL_ID', $sandbox_id );
	$def( 'RUDEL_PATH', $sandbox_path );
	$def( 'RUDEL_IS_APP', $rudel_bootstrap_is_app );
	$def( 'RUDEL_ENV_TYPE', $rudel_bootstrap_is_app ? 'app' : 'sandbox' );
	$def( 'RUDEL_ENGINE', $_rudel_engine );
	$def( 'RUDEL_ENV_RECORD_ID', $environment_row_id );
	if ( null !== $app_row_id ) {
		$def( 'RUDEL_APP_RECORD_ID', $app_row_id );
	}
} )();

// WP-CLI eval runs outside normal global setup, so expose the resolved prefix in the caller's scope as well.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Must match WP's $table_prefix variable name.
if ( null !== $rudel_bootstrap_prefix ) {
	$table_prefix = $rudel_bootstrap_prefix;
}
unset( $rudel_bootstrap_prefix );
unset( $rudel_bootstrap_is_app );
unset( $rudel_bootstrap_requested_url );
