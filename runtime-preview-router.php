<?php
/**
 * Runtime helper functions for prefixed preview routing.
 *
 * Loaded directly by the generated runtime MU plugin to avoid class-loading
 * collisions between the host Rudel package and copied app/theme packages.
 *
 * @package Rudel
 */

if ( ! function_exists( 'rudel_runtime_preview_resolve' ) ) {
	/**
	 * Resolve a stripped preview request URI to either a PHP entrypoint or a static file.
	 *
	 * @param string $request_uri Request URI seen by WordPress after bootstrap prefix stripping.
	 * @param string $abspath WordPress ABSPATH.
	 * @param string $content_dir Environment-specific WP_CONTENT_DIR.
	 * @return array{type:string,path:string,request_path:string}|null
	 */
	function rudel_runtime_preview_resolve( string $request_uri, string $abspath, string $content_dir ): ?array {
		$request_path = rudel_runtime_preview_normalize_request_path( $request_uri );
		if ( '/' === $request_path ) {
			return null;
		}

		$abspath = rtrim( $abspath, '/\\' ) . DIRECTORY_SEPARATOR;

		$root_php_entrypoints = array(
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

		if ( in_array( $request_path, $root_php_entrypoints, true ) ) {
			return rudel_runtime_preview_build_php_target( $request_path, $abspath . ltrim( $request_path, '/' ) );
		}

		if ( '/wp-admin' === $request_path || '/wp-admin/' === $request_path ) {
			return rudel_runtime_preview_build_php_target( '/wp-admin/index.php', $abspath . 'wp-admin/index.php', '/wp-admin/' );
		}

		if ( str_starts_with( $request_path, '/wp-admin/' ) ) {
			return rudel_runtime_preview_resolve_under_base( $request_path, '/wp-admin/', $abspath . 'wp-admin' );
		}

		if ( str_starts_with( $request_path, '/wp-includes/' ) ) {
			return rudel_runtime_preview_resolve_under_base( $request_path, '/wp-includes/', $abspath . 'wp-includes' );
		}

		if ( str_starts_with( $request_path, '/wp-content/' ) ) {
			return rudel_runtime_preview_resolve_under_base( $request_path, '/wp-content/', $content_dir );
		}

		return null;
	}
}

if ( ! function_exists( 'rudel_runtime_preview_resolve_under_base' ) ) {
	/**
	 * Resolve a request path underneath a specific filesystem base path.
	 *
	 * @param string $request_path Request path.
	 * @param string $request_base Request base prefix, with leading and trailing slash.
	 * @param string $filesystem_base Filesystem base directory.
	 * @return array{type:string,path:string,request_path:string}|null
	 */
	function rudel_runtime_preview_resolve_under_base( string $request_path, string $request_base, string $filesystem_base ): ?array {
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
			return rudel_runtime_preview_build_php_target( $request_path, $candidate );
		}

		return array(
			'type'         => 'static',
			'path'         => $candidate,
			'request_path' => $request_path,
		);
	}
}

if ( ! function_exists( 'rudel_runtime_preview_build_php_target' ) ) {
	/**
	 * Build a normalized PHP dispatch target.
	 *
	 * @param string $request_path Requested in-environment path.
	 * @param string $file_path PHP file to execute.
	 * @param string|null $script_name Optional PHP_SELF/SCRIPT_NAME override.
	 * @return array{type:string,path:string,request_path:string}
	 */
	function rudel_runtime_preview_build_php_target( string $request_path, string $file_path, ?string $script_name = null ): array {
		if ( ! is_string( $script_name ) || '' === $script_name ) {
			$script_name = $request_path;
		}

		return array(
			'type'         => 'php',
			'path'         => $file_path,
			'request_path' => $script_name,
		);
	}
}

if ( ! function_exists( 'rudel_runtime_preview_normalize_request_path' ) ) {
	/**
	 * Normalize an incoming request URI down to its path component.
	 *
	 * @param string $request_uri Request URI.
	 * @return string
	 */
	function rudel_runtime_preview_normalize_request_path( string $request_uri ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime preview routing runs before higher-level URL helpers.
		$path = parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		return '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'rudel_runtime_preview_prepare_php_request' ) ) {
	/**
	 * Normalize the server globals to match the dispatched PHP entrypoint.
	 *
	 * @param string $request_path Requested in-environment path.
	 * @param string $file_path PHP file to execute.
	 * @return void
	 */
	function rudel_runtime_preview_prepare_php_request( string $request_path, string $file_path ): void {
		$script_name                = '/' . ltrim( $request_path, '/' );
		$_SERVER['SCRIPT_NAME']     = $script_name;
		$_SERVER['PHP_SELF']        = $script_name;
		$_SERVER['SCRIPT_FILENAME'] = $file_path;
		$_SERVER['DOCUMENT_URI']    = $script_name;
	}
}

if ( ! function_exists( 'rudel_runtime_preview_stream_static_file' ) ) {
	/**
	 * Stream a static preview file through WordPress.
	 *
	 * @param string $file_path Absolute file path.
	 * @return void
	 */
	function rudel_runtime_preview_stream_static_file( string $file_path ): void {
		$mime_types = array(
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

		$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$mime_type = $mime_types[ $extension ] ?? 'application/octet-stream';

		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		header( 'Cache-Control: public, max-age=3600' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Request method only selects HEAD body suppression.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? stripslashes( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'HEAD' === $request_method ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Preview mode must stream real environment files through PHP.
		readfile( $file_path );
	}
}
