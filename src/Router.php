<?php
/**
 * Request-to-sandbox resolution.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Resolves the active sandbox from incoming request context.
 */
class Router {

	/**
	 * Absolute path to the sandboxes directory.
	 *
	 * @var string
	 */
	private string $sandboxes_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $sandboxes_dir Optional override for the sandboxes directory.
	 */
	public function __construct( ?string $sandboxes_dir = null ) {
		$this->sandboxes_dir = $sandboxes_dir ?? $this->get_default_sandboxes_dir();
	}

	/**
	 * Try all resolution methods in priority order.
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve(): ?string {
		return $this->resolve_from_header()
			?? $this->resolve_from_cookie()
			?? $this->resolve_from_cli()
			?? $this->resolve_from_path_prefix()
			?? $this->resolve_from_subdomain();
	}

	/**
	 * Resolve from X-Rudel-Sandbox header.
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve_from_header(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WP context; validated by regex in validate_and_return().
		$id = $_SERVER['HTTP_X_RUDEL_SANDBOX'] ?? null;
		return $this->validate_and_return( $id );
	}

	/**
	 * Resolve from rudel_sandbox cookie.
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve_from_cookie(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WP context; validated by regex.
		$id = $_COOKIE['rudel_sandbox'] ?? null;
		return $this->validate_and_return( $id );
	}

	/**
	 * Resolve from WP-CLI --url= argument.
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve_from_cli(): ?string {
		if ( 'cli' !== php_sapi_name() ) {
			return null;
		}

		global $argv;
		if ( empty( $argv ) ) {
			return null;
		}

		foreach ( $argv as $arg ) {
			if ( str_starts_with( $arg, '--url=' ) ) {
				$url = substr( $arg, 6 );
				// Path prefix pattern.
				if ( preg_match( '#/__rudel/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $url, $matches ) ) {
					return $this->validate_and_return( $matches[1] );
				}
				// Subdomain pattern.
				if ( preg_match( '#^https?://([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})\.#', $url, $matches ) ) {
					return $this->validate_and_return( $matches[1] );
				}
			}
		}

		return null;
	}

	/**
	 * Resolve from URL path prefix (/__rudel/{id}/).
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve_from_path_prefix(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WP context; validated by regex.
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( preg_match( '#^/__rudel/([a-zA-Z0-9][a-zA-Z0-9_-]{0,63})/?#', $uri, $matches ) ) {
			return $this->validate_and_return( $matches[1] );
		}
		return null;
	}

	/**
	 * Resolve from subdomain ({id}.domain.com).
	 *
	 * @return string|null Sandbox ID or null.
	 */
	public function resolve_from_subdomain(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Pre-WP context; validated by regex.
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( ! $host ) {
			return null;
		}

		// First segment before the domain.
		$parts = explode( '.', $host );
		if ( count( $parts ) < 3 ) {
			return null;
		}

		$subdomain = $parts[0];
		return $this->validate_and_return( $subdomain );
	}

	/**
	 * Validate an ID and confirm the sandbox directory exists.
	 *
	 * @param string|null $id Candidate sandbox ID.
	 * @return string|null Validated ID or null.
	 */
	private function validate_and_return( ?string $id ): ?string {
		if ( ! $id || ! Sandbox::validate_id( $id ) ) {
			return null;
		}

		$sandbox_path = $this->sandboxes_dir . '/' . $id;
		if ( ! $this->validate_path( $sandbox_path ) ) {
			return null;
		}

		return $id;
	}

	/**
	 * Validate that a path exists and is contained within the sandboxes directory.
	 *
	 * @param string $path Candidate sandbox path.
	 * @return bool True if the path is valid.
	 */
	private function validate_path( string $path ): bool {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return false;
		}

		$base = realpath( $this->sandboxes_dir );
		if ( false === $base ) {
			return false;
		}

		// Prevent path traversal.
		return str_starts_with( $real, $base . DIRECTORY_SEPARATOR );
	}

	/**
	 * Determine the default sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_sandboxes_dir(): string {
		if ( defined( 'RUDEL_SANDBOXES_DIR' ) ) {
			return RUDEL_SANDBOXES_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-sandboxes';
		}
		return __DIR__ . '/../rudel-sandboxes';
	}

	/**
	 * Get the configured sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_sandboxes_dir(): string {
		return $this->sandboxes_dir;
	}
}
