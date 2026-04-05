<?php
/**
 * Runtime dispatcher for prefixed Rudel preview requests.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Make prefixed preview URLs behave like a real subpath site.
 */
class PreviewRequestRouter {

	/**
	 * Root-level PHP entrypoints that WordPress expects to execute directly.
	 *
	 * @var string[]
	 */
	private const ROOT_PHP_ENTRYPOINTS = array(
		'/index.php',
		'/wp-activate.php',
		'/wp-blog-header.php',
		'/wp-comments-post.php',
		'/wp-config.php',
		'/wp-cron.php',
		'/wp-links-opml.php',
		'/wp-load.php',
		'/wp-login.php',
		'/wp-mail.php',
		'/wp-settings.php',
		'/wp-signup.php',
		'/wp-trackback.php',
		'/xmlrpc.php',
	);

	/**
	 * MIME types for static files served through preview mode.
	 *
	 * @var array<string, string>
	 */
	private const MIME_TYPES = array(
		'css'   => 'text/css; charset=UTF-8',
		'eot'   => 'application/vnd.ms-fontobject',
		'gif'   => 'image/gif',
		'ico'   => 'image/x-icon',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'js'    => 'application/javascript; charset=UTF-8',
		'json'  => 'application/json; charset=UTF-8',
		'map'   => 'application/json; charset=UTF-8',
		'otf'   => 'font/otf',
		'png'   => 'image/png',
		'svg'   => 'image/svg+xml',
		'ttf'   => 'font/ttf',
		'txt'   => 'text/plain; charset=UTF-8',
		'webp'  => 'image/webp',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'xml'   => 'application/xml; charset=UTF-8',
	);

	/**
	 * Dispatch prefixed preview requests to their real WordPress targets.
	 *
	 * @param mixed $wp Current WP object from parse_request, unused.
	 * @return void
	 */
	public static function maybe_dispatch( mixed $wp = null ): void {
		unset( $wp );

		if ( ! defined( 'RUDEL_IS_PREVIEW' ) || ! RUDEL_IS_PREVIEW ) {
			return;
		}

		if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- parse_request runs inside WordPress; preview routing only inspects the raw request path and uses stripslashes() to stay phpstan-compatible outside full WP stubs.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? stripslashes( (string) $_SERVER['REQUEST_URI'] ) : '/';
		if ( '' === $request_uri ) {
			return;
		}

		$resolved = self::resolve( $request_uri, ABSPATH, WP_CONTENT_DIR );
		if ( ! is_array( $resolved ) ) {
			return;
		}

		if ( 'php' === $resolved['type'] ) {
			self::prepare_php_request( $resolved['request_path'], $resolved['path'] );
			require $resolved['path'];
			exit;
		}

		self::stream_static_file( $resolved['path'] );
		exit;
	}

	/**
	 * Resolve a stripped preview request URI to either a PHP entrypoint or a static file.
	 *
	 * @param string $request_uri Request URI seen by WordPress after bootstrap prefix stripping.
	 * @param string $abspath     WordPress ABSPATH.
	 * @param string $content_dir Environment-specific WP_CONTENT_DIR.
	 * @return array{type: string, path: string, request_path: string}|null
	 */
	public static function resolve( string $request_uri, string $abspath, string $content_dir ): ?array {
		$request_path = self::normalize_request_path( $request_uri );
		if ( '/' === $request_path ) {
			return null;
		}

		$abspath = rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR;

		if ( in_array( $request_path, self::ROOT_PHP_ENTRYPOINTS, true ) ) {
			return self::build_php_target( $request_path, $abspath . ltrim( $request_path, '/' ) );
		}

		if ( '/wp-admin' === $request_path || '/wp-admin/' === $request_path ) {
			return self::build_php_target( '/wp-admin/index.php', $abspath . 'wp-admin/index.php', '/wp-admin/' );
		}

		if ( str_starts_with( $request_path, '/wp-admin/' ) ) {
			return self::resolve_under_base( $request_path, '/wp-admin/', $abspath . 'wp-admin' );
		}

		if ( str_starts_with( $request_path, '/wp-includes/' ) ) {
			return self::resolve_under_base( $request_path, '/wp-includes/', $abspath . 'wp-includes' );
		}

		if ( str_starts_with( $request_path, '/wp-content/' ) ) {
			return self::resolve_under_base( $request_path, '/wp-content/', $content_dir );
		}

		return null;
	}

	/**
	 * Resolve a request path underneath a specific filesystem base path.
	 *
	 * @param string $request_path Request path.
	 * @param string $request_base Request base prefix, with leading and trailing slash.
	 * @param string $filesystem_base Filesystem base directory.
	 * @return array{type: string, path: string, request_path: string}|null
	 */
	private static function resolve_under_base( string $request_path, string $request_base, string $filesystem_base ): ?array {
		$base = realpath( $filesystem_base );
		if ( false === $base || ! is_dir( $base ) ) {
			return null;
		}

		$relative = substr( $request_path, strlen( $request_base ) );
		if ( '' === $relative ) {
			return null;
		}

		$candidate = realpath( $base . DIRECTORY_SEPARATOR . $relative );
		if ( false === $candidate || ! is_file( $candidate ) ) {
			return null;
		}

		$base = rtrim( $base, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $candidate, $base ) ) {
			return null;
		}

		if ( 'php' === strtolower( pathinfo( $candidate, PATHINFO_EXTENSION ) ) ) {
			return self::build_php_target( $request_path, $candidate );
		}

		return array(
			'type'         => 'static',
			'path'         => $candidate,
			'request_path' => $request_path,
		);
	}

	/**
	 * Build a normalized PHP dispatch target.
	 *
	 * @param string      $request_path Requested in-environment path.
	 * @param string      $file_path    PHP file to execute.
	 * @param string|null $script_name  Optional PHP_SELF/SCRIPT_NAME override.
	 * @return array{type: string, path: string, request_path: string}
	 */
	private static function build_php_target( string $request_path, string $file_path, ?string $script_name = null ): array {
		if ( ! is_string( $script_name ) || '' === $script_name ) {
			$script_name = $request_path;
		}

		return array(
			'type'         => 'php',
			'path'         => $file_path,
			'request_path' => $script_name,
		);
	}

	/**
	 * Normalize an incoming request URI down to its path component.
	 *
	 * @param string $request_uri Request URI.
	 * @return string
	 */
	private static function normalize_request_path( string $request_uri ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Routing runs before higher-level URL helpers.
		$path = parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return '/' . ltrim( $path, '/' );
	}

	/**
	 * Normalize the server globals to match the dispatched PHP entrypoint.
	 *
	 * @param string $request_path Requested in-environment path.
	 * @param string $file_path    PHP file to execute.
	 * @return void
	 */
	private static function prepare_php_request( string $request_path, string $file_path ): void {
		$script_name                = '/' . ltrim( $request_path, '/' );
		$_SERVER['SCRIPT_NAME']     = $script_name;
		$_SERVER['PHP_SELF']        = $script_name;
		$_SERVER['SCRIPT_FILENAME'] = $file_path;
		$_SERVER['DOCUMENT_URI']    = $script_name;
	}

	/**
	 * Stream a static preview file through WordPress.
	 *
	 * @param string $file_path Absolute file path.
	 * @return void
	 */
	private static function stream_static_file( string $file_path ): void {
		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime_type = self::MIME_TYPES[ $extension ] ?? 'application/octet-stream';

		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		header( 'Cache-Control: public, max-age=3600' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Request method only selects HEAD body suppression and uses stripslashes() to stay phpstan-compatible outside full WP stubs.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? stripslashes( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'HEAD' === $request_method ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Preview mode must stream real sandbox/core files through PHP.
		readfile( $file_path );
	}
}
