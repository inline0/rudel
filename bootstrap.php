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

( function () {
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
					if ( preg_match( '#/__rudel/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $url, $m ) ) {
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

	// 4. Path prefix: /__rudel/{id}/.
	if ( ! $sandbox_id ) {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#^/__rudel/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $uri, $m ) ) {
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

	// Safe define helper.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Dynamic constant names for WP config.
	$def = function ( string $name, mixed $value ): void {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	};

	// open_basedir jail.
	$wp_core_path  = defined( 'ABSPATH' ) ? rtrim( ABSPATH, '/' ) : dirname( __DIR__, 2 );
	$allowed_paths = implode(
		PATH_SEPARATOR,
		array(
			$sandbox_path,
			$wp_core_path,
			$plugin_dir,
			sys_get_temp_dir(),
			'/tmp',
		)
	);
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Intentional open_basedir jail for sandbox isolation.
	ini_set( 'open_basedir', $allowed_paths );

	// SQLite database constants.
	$def( 'DB_DIR', $sandbox_path );
	$def( 'DB_FILE', 'wordpress.db' ); // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled -- Filename.
	$def( 'DATABASE_TYPE', 'sqlite' );
	$def( 'DB_ENGINE', 'sqlite' );

	// WP content directories.
	$def( 'WP_CONTENT_DIR', $sandbox_path . '/wp-content' );

	// Build content URL.
	$protocol = 'http';
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
		$protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
	} elseif ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) {
		$protocol = 'https';
	}
	$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$site_url = $protocol . '://' . $host;

	$def( 'WP_CONTENT_URL', $site_url . '/wp-content' );
	$def( 'WP_PLUGIN_DIR', $sandbox_path . '/wp-content/plugins' );
	$def( 'WPMU_PLUGIN_DIR', $sandbox_path . '/wp-content/mu-plugins' );
	$def( 'WP_TEMP_DIR', $sandbox_path . '/tmp' );
	$def( 'UPLOADS', 'wp-content/uploads' );

	// Per-sandbox table prefix.
	$GLOBALS['table_prefix'] = 'wp_' . substr( md5( $sandbox_id ), 0, 6 ) . '_';

	// Per-sandbox auth salts (deterministic).
	$def( 'AUTH_KEY', hash( 'sha256', $sandbox_id . 'AUTH_KEY' ) );
	$def( 'SECURE_AUTH_KEY', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_KEY' ) );
	$def( 'LOGGED_IN_KEY', hash( 'sha256', $sandbox_id . 'LOGGED_IN_KEY' ) );
	$def( 'NONCE_KEY', hash( 'sha256', $sandbox_id . 'NONCE_KEY' ) );
	$def( 'AUTH_SALT', hash( 'sha256', $sandbox_id . 'AUTH_SALT' ) );
	$def( 'SECURE_AUTH_SALT', hash( 'sha256', $sandbox_id . 'SECURE_AUTH_SALT' ) );
	$def( 'LOGGED_IN_SALT', hash( 'sha256', $sandbox_id . 'LOGGED_IN_SALT' ) );
	$def( 'NONCE_SALT', hash( 'sha256', $sandbox_id . 'NONCE_SALT' ) );

	// Rudel sandbox markers.
	$def( 'RUDEL_SANDBOX_ID', $sandbox_id );
	$def( 'RUDEL_SANDBOX_PATH', $sandbox_path );
} )();
