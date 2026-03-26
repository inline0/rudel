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
if ( defined( 'RUDEL_SANDBOX_ID' ) ) {
	return;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Temporary variable, unset after use.
$_rudel_prefix = null;

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

( function () use ( &$_rudel_prefix ) {
	$plugin_dir    = __DIR__;
	$sandboxes_dir = null;

	// Determine sandboxes directory.
	if ( defined( 'RUDEL_SANDBOXES_DIR' ) ) {
		$sandboxes_dir = RUDEL_SANDBOXES_DIR;
	} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
		$sandboxes_dir = WP_CONTENT_DIR . '/rudel-sandboxes';
	} else {
		$abspath       = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 2 ) . '/';
		$sandboxes_dir = $abspath . 'wp-content/rudel-sandboxes';
	}

	if ( ! is_dir( $sandboxes_dir ) ) {
		return;
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
	$validate_path = function ( string $id ) use ( $sandboxes_dir ): ?string {
		$path = $sandboxes_dir . '/' . $id;
		if ( ! is_dir( $path ) ) {
			return null;
		}
		$real = realpath( $path );
		if ( false === $real ) {
			return null;
		}
		$base = realpath( $sandboxes_dir );
		if ( false === $base ) {
			return null;
		}
		if ( 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}
		return $real;
	};

	$sandbox_id   = null;
	$sandbox_path = null;

	// 1. X-Rudel-Sandbox header.
	if ( ! $sandbox_id ) {
		$header_id = $_SERVER['HTTP_X_RUDEL_SANDBOX'] ?? null;
		if ( $header_id && $validate_id( $header_id ) ) {
			$path = $validate_path( $header_id );
			if ( $path ) {
				$sandbox_id   = $header_id;
				$sandbox_path = $path;
			}
		}
	}

	// 2. rudel_sandbox cookie.
	if ( ! $sandbox_id ) {
		$cookie_id = $_COOKIE['rudel_sandbox'] ?? null;
		if ( $cookie_id && $validate_id( $cookie_id ) ) {
			$path = $validate_path( $cookie_id );
			if ( $path ) {
				$sandbox_id   = $cookie_id;
				$sandbox_path = $path;
			}
		}
	}

	// 3. WP-CLI --url= argument.
	if ( ! $sandbox_id && 'cli' === php_sapi_name() ) {
		global $argv;
		if ( ! empty( $argv ) ) {
			foreach ( $argv as $arg ) {
				if ( 0 === strpos( $arg, '--url=' ) ) {
					$url = substr( $arg, 6 );
					if ( preg_match( '#/' . preg_quote( RUDEL_PATH_PREFIX, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $url, $m ) ) {
						if ( $validate_id( $m[1] ) ) {
							$path = $validate_path( $m[1] );
							if ( $path ) {
								$sandbox_id   = $m[1];
								$sandbox_path = $path;
							}
						}
					}
					if ( ! $sandbox_id && preg_match( '#^https?://([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})\.#', $url, $m ) ) {
						if ( $validate_id( $m[1] ) ) {
							$path = $validate_path( $m[1] );
							if ( $path ) {
								$sandbox_id   = $m[1];
								$sandbox_path = $path;
							}
						}
					}
					break;
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

	// 4. Path prefix: /__rudel/{id}/.
	if ( ! $sandbox_id ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#^/' . preg_quote( RUDEL_PATH_PREFIX, '#' ) . '/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $uri, $m ) ) {
			if ( $validate_id( $m[1] ) ) {
				$path = $validate_path( $m[1] );
				if ( $path ) {
					$sandbox_id   = $m[1];
					$sandbox_path = $path;
				}
			}
		}
	}

	// 5. Subdomain: {id}.domain.com.
	if ( ! $sandbox_id ) {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( $host ) {
			$parts = explode( '.', $host );
			if ( count( $parts ) >= 3 ) {
				$subdomain = $parts[0];
				if ( $validate_id( $subdomain ) ) {
					$path = $validate_path( $subdomain );
					if ( $path ) {
						$sandbox_id   = $subdomain;
						$sandbox_path = $path;
					}
				}
			}
		}
	}

	if ( ! $sandbox_id || ! $sandbox_path ) {
		return;
	}

	// Auto-set the sandbox cookie in web context so wp-admin and other
	// real PHP files (not routed through index.php) maintain sandbox context.
	if ( 'cli' !== php_sapi_name() ) {
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

	// Build content URL.
	$protocol = 'http';
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && in_array( $_SERVER['HTTP_X_FORWARDED_PROTO'], array( 'http', 'https' ), true ) ) {
		$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
	} elseif ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
		$protocol = 'https';
	}
	$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$site_url = $protocol . '://' . $host;

	// Sandbox site URL (preempts wp-config.php constants).
	$sandbox_url = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id;
	$def( 'WP_SITEURL', $sandbox_url );
	$def( 'WP_HOME', $sandbox_url );

	$def( 'WP_CONTENT_URL', $sandbox_url . '/wp-content' );
	$def( 'WP_PLUGIN_DIR', $sandbox_path . '/wp-content/plugins' );
	$def( 'WPMU_PLUGIN_DIR', $sandbox_path . '/wp-content/mu-plugins' );
	$def( 'WP_TEMP_DIR', $sandbox_path . '/tmp' );
	$def( 'UPLOADS', 'wp-content/uploads' );

	// Per-sandbox debug logging (sandboxes are dev environments).
	$def( 'WP_DEBUG', true );
	$def( 'WP_DEBUG_LOG', true );
	$def( 'WP_DEBUG_DISPLAY', false );

	// Per-sandbox table prefix (subsite engine uses multisite's own prefix via blog_id).
	if ( 'subsite' !== $_rudel_engine ) {
		$_rudel_prefix           = 'wp_' . substr( md5( $sandbox_id ), 0, 6 ) . '_';
		$GLOBALS['table_prefix'] = $_rudel_prefix;
		$def( 'RUDEL_TABLE_PREFIX', $_rudel_prefix );
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
			$def( 'DOMAIN_CURRENT_SITE', $host );
			$def( 'PATH_CURRENT_SITE', '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id . '/' );
			$def( 'SITE_ID_CURRENT_SITE', 1 );
			$def( 'BLOG_ID_CURRENT_SITE', 1 );
		} else {
			$def( 'MULTISITE', false );
			$def( 'WP_ALLOW_MULTISITE', false );
		}
	}

	// Rudel sandbox markers.
	$def( 'RUDEL_SANDBOX_ID', $sandbox_id );
	$def( 'RUDEL_SANDBOX_PATH', $sandbox_path );
} )();

// Also set $table_prefix in the caller's scope for WP-CLI eval compatibility.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Must match WP's $table_prefix variable name.
if ( null !== $_rudel_prefix ) {
	$table_prefix = $_rudel_prefix;
}
unset( $_rudel_prefix );
