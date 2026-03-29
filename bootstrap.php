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

// Prevent direct browser access.
if ( 'cli' !== php_sapi_name() && isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ ) ) {
	exit;
}

// Already resolved (e.g. per-sandbox bootstrap loaded via wp-cli.yml).
if ( defined( 'RUDEL_ID' ) ) {
	return;
}

$rudel_bootstrap_prefix        = null;
$rudel_bootstrap_is_app        = false;
$rudel_bootstrap_requested_url = null;

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

( function () use ( &$rudel_bootstrap_prefix, &$rudel_bootstrap_is_app, &$rudel_bootstrap_requested_url ) {
	$plugin_dir       = __DIR__;
	$environments_dir = null;

	// Determine sandboxes directory.
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

	$domains_map = array();
	if ( is_dir( $apps_dir ) ) {
		$domain_map_path = $apps_dir . '/domains.json';
		if ( file_exists( $domain_map_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pre-WP bootstrap.
			$domains_raw     = file_get_contents( $domain_map_path );
			$domains_decoded = json_decode( $domains_raw, true );
			if ( is_array( $domains_decoded ) ) {
				foreach ( $domains_decoded as $domain => $id ) {
					if ( is_string( $domain ) && is_string( $id ) ) {
						$domains_map[ strtolower( (string) preg_replace( '/:\d+$/', '', $domain ) ) ] = $id;
					}
				}
			}
		}
	}

	/**
	 * Validate a sandbox ID format.
	 */
	$validate_id = function ( ?string $id ): bool {
		return $id && preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $id );
	};

	/**
	 * Validate sandbox path (prevent traversal).
	 */
	$validate_path = function ( string $id ) use ( $environments_dir, $apps_dir ): ?array {
		// Check environments (sandboxes) directory first.
		$path = $environments_dir . '/' . $id;
		if ( is_dir( $path ) ) {
			$real = realpath( $path );
			$base = realpath( $environments_dir );
			if ( false !== $real && false !== $base && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
				return array(
					'path'   => $real,
					'is_app' => false,
				);
			}
		}

		// Then check apps directory.
		if ( is_dir( $apps_dir ) ) {
			$path = $apps_dir . '/' . $id;
			if ( is_dir( $path ) ) {
				$real = realpath( $path );
				$base = realpath( $apps_dir );
				if ( false !== $real && false !== $base && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
					return array(
						'path'   => $real,
						'is_app' => true,
					);
				}
			}
		}

		return null;
	};

	$sandbox_id   = null;
	$sandbox_path = null;

	/**
	 * Normalize a host by stripping any port and lowercasing it.
	 */
	$normalize_host = function ( string $host ): string {
		return strtolower( (string) preg_replace( '/:\d+$/', '', $host ) );
	};

	/**
	 * Try to resolve an ID and set sandbox_id/sandbox_path/is_app.
	 */
	$try_resolve = function ( string $id ) use ( $validate_id, $validate_path, &$sandbox_id, &$sandbox_path, &$rudel_bootstrap_is_app ): bool {
		if ( ! $validate_id( $id ) ) {
			return false;
		}
		$result = $validate_path( $id );
		if ( $result ) {
			$sandbox_id             = $id;
			$sandbox_path           = $result['path'];
			$rudel_bootstrap_is_app = $result['is_app'];
			return true;
		}
		return false;
	};

	/**
	 * Try to resolve an app from the domain map.
	 */
	$try_resolve_domain = function ( string $host ) use ( $domains_map, $normalize_host, $try_resolve ): bool {
		$domain = $normalize_host( $host );
		if ( '' === $domain || ! isset( $domains_map[ $domain ] ) ) {
			return false;
		}
		return $try_resolve( $domains_map[ $domain ] );
	};

	/**
	 * Extract a --url value from CLI argv, supporting both --url=value and --url value.
	 */
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

	// 0. App domain map: a concrete request host wins before sandbox detection.
	if ( ! $sandbox_id ) {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( is_string( $host ) && '' !== $host ) {
			$try_resolve_domain( $host );
		}
	}

	// 1. X-Rudel-Sandbox header.
	if ( ! $sandbox_id ) {
		$header_id = $_SERVER['HTTP_X_RUDEL_SANDBOX'] ?? null;
		if ( $header_id ) {
			$try_resolve( $header_id );
		}
	}

	// 2. rudel_sandbox cookie.
	if ( ! $sandbox_id ) {
		$cookie_id = $_COOKIE['rudel_sandbox'] ?? null;
		if ( $cookie_id ) {
			$try_resolve( $cookie_id );
		}
	}

	// 3. WP-CLI --url= argument.
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

	// Exit sandbox: ?adminExit clears the cookie and redirects to host.
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

	// 4. Path prefix: /__rudel/{id}/ (resolves both sandboxes and apps).
	if ( ! $sandbox_id ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#^/' . preg_quote( RUDEL_PATH_PREFIX, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $uri, $m ) ) {
			$try_resolve( $m[1] );
		}
	}

	// 5. Subdomain: {id}.domain.com.
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

	// Auto-set the sandbox cookie in web context so wp-admin and other
	// real PHP files (not routed through index.php) maintain sandbox context.
	if ( 'cli' !== php_sapi_name() && ! $rudel_bootstrap_is_app ) {
		$cookie_id = $_COOKIE['rudel_sandbox'] ?? null;
		if ( $cookie_id !== $sandbox_id ) {
			setcookie( 'rudel_sandbox', $sandbox_id, 0, '/' );
			$_COOKIE['rudel_sandbox'] = $sandbox_id;
		}
	}

	// Safe define helper.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Dynamic constant names for WP config.
	$def = function ( string $name, mixed $value ): void {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	};

	// open_basedir jail.
	$wp_core_path = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : dirname( __DIR__, 2 );
	$paths        = array(
		$sandbox_path,
		$wp_core_path,
		$plugin_dir,
		sys_get_temp_dir(),
		'/tmp',
	);

	// In CLI mode, allow the CLI tool (e.g. WP-CLI phar) to read its own files.
	if ( 'cli' === php_sapi_name() && ! empty( $_SERVER['SCRIPT_FILENAME'] ) ) {
		$cli_dir = dirname( (string) realpath( $_SERVER['SCRIPT_FILENAME'] ) );
		if ( '' !== $cli_dir && '.' !== $cli_dir ) {
			$paths[] = $cli_dir;
		}
	}

	$allowed_paths = implode( PATH_SEPARATOR, $paths );
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Intentional open_basedir jail for sandbox isolation.
	ini_set( 'open_basedir', $allowed_paths );

	// Read engine from sandbox metadata (must happen before SQLite constants).
	$_rudel_engine    = 'mysql';
	$_rudel_meta_file = $sandbox_path . '/.rudel.json';
	$_rudel_meta      = null;
	if ( file_exists( $_rudel_meta_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pre-WP bootstrap.
		$_rudel_meta_raw = file_get_contents( $_rudel_meta_file );
		$_rudel_meta     = json_decode( $_rudel_meta_raw, true );
		if ( is_array( $_rudel_meta ) && isset( $_rudel_meta['engine'] ) ) {
			$_rudel_engine = $_rudel_meta['engine'];
		}
	}

	// SQLite database constants (only for sqlite engine).
	if ( 'sqlite' === $_rudel_engine ) {
		$def( 'DB_DIR', $sandbox_path );
		$def( 'DB_FILE', 'wordpress.db' ); // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- Filename.
		$def( 'DATABASE_TYPE', 'sqlite' );
		$def( 'DB_ENGINE', 'sqlite' );
	}

	// WP content directories.
	$def( 'WP_CONTENT_DIR', $sandbox_path . '/wp-content' );

	// Build environment URL, preferring the explicit CLI --url when available.
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

	// Environment URL (apps live at the domain root; sandboxes use a path prefix).
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

	// Per-sandbox debug logging (sandboxes are dev environments).
	if ( ! $rudel_bootstrap_is_app ) {
		$def( 'WP_DEBUG', true );
		$def( 'WP_DEBUG_LOG', true );
		$def( 'WP_DEBUG_DISPLAY', false );
	}

	// Per-sandbox object cache isolation (prevents Redis/Memcached data leaking between sandboxes).
	$def( 'WP_CACHE_KEY_SALT', 'rudel_' . $sandbox_id . '_' );

	// Disable outbound email by default for temporary sandboxes only.
	$def( 'RUDEL_DISABLE_EMAIL', ! $rudel_bootstrap_is_app );

	// Per-sandbox table prefix (subsite engine uses multisite's own prefix via blog_id).
	if ( 'subsite' !== $_rudel_engine ) {
		$rudel_bootstrap_prefix  = 'rudel_' . substr( md5( $sandbox_id ), 0, 6 ) . '_';
		$GLOBALS['table_prefix'] = $rudel_bootstrap_prefix;
		$def( 'RUDEL_TABLE_PREFIX', $rudel_bootstrap_prefix );
	}

	// Per-sandbox auth salts (deterministic).
	$def( 'AUTH_KEY', hash( 'sha256', $sandbox_id . 'AUTH_KEY' ) );
	$def( 'SECURE_AUTH_KEY', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_KEY' ) );
	$def( 'LOGGED_IN_KEY', hash( 'sha256', $sandbox_id . 'LOGGED_IN_KEY' ) );
	$def( 'NONCE_KEY', hash( 'sha256', $sandbox_id . 'NONCE_KEY' ) );
	$def( 'AUTH_SALT', hash( 'sha256', $sandbox_id . 'AUTH_SALT' ) );
	$def( 'SECURE_AUTH_SALT', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_SALT' ) );
	$def( 'LOGGED_IN_SALT', hash( 'sha256', $sandbox_id . 'LOGGED_IN_SALT' ) );
	$def( 'NONCE_SALT', hash( 'sha256', $sandbox_id . 'NONCE_SALT' ) );

	// Multisite constants: reuse metadata already parsed above.
	if ( is_array( $_rudel_meta ) ) {
		if ( ! empty( $_rudel_meta['multisite'] ) ) {
			$def( 'WP_ALLOW_MULTISITE', true );
			$def( 'MULTISITE', true );
			$def( 'SUBDOMAIN_INSTALL', false );
			$def( 'DOMAIN_CURRENT_SITE', $normalize_host( $host ) );
			$def( 'PATH_CURRENT_SITE', $rudel_bootstrap_is_app ? '/' : '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id . '/' );
			$def( 'SITE_ID_CURRENT_SITE', 1 );
			$def( 'BLOG_ID_CURRENT_SITE', 1 );
		} else {
			$def( 'MULTISITE', false );
			$def( 'WP_ALLOW_MULTISITE', false );
		}
	}

	// Rudel sandbox markers.
	$def( 'RUDEL_ID', $sandbox_id );
	$def( 'RUDEL_PATH', $sandbox_path );
	$def( 'RUDEL_IS_APP', $rudel_bootstrap_is_app );
	$def( 'RUDEL_ENV_TYPE', $rudel_bootstrap_is_app ? 'app' : 'sandbox' );
} )();

// Also set $table_prefix in the caller's scope for WP-CLI eval compatibility.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Must match WP's $table_prefix variable name.
if ( null !== $rudel_bootstrap_prefix ) {
	$table_prefix = $rudel_bootstrap_prefix;
}
unset( $rudel_bootstrap_prefix );
unset( $rudel_bootstrap_is_app );
unset( $rudel_bootstrap_requested_url );
