<?php
/**
 * Runtime activation cookie specification.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Value object describing one runtime activation cookie.
 */
final class CookieSpec {

	/**
	 * Initialize cookie data.
	 *
	 * @param string $name      Cookie name.
	 * @param string $value     Cookie value.
	 * @param int    $expires   Expiry timestamp. Zero means session cookie.
	 * @param string $path      Cookie path.
	 * @param bool   $secure    Whether the cookie is secure-only.
	 * @param bool   $http_only Whether the cookie is HTTP-only.
	 * @param string $same_site SameSite value.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $value,
		public readonly int $expires = 0,
		public readonly string $path = '/',
		public readonly bool $secure = false,
		public readonly bool $http_only = true,
		public readonly string $same_site = 'Lax',
	) {}

	/**
	 * Export as a setcookie options array.
	 *
	 * @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}
	 */
	public function options(): array {
		return array(
			'expires'  => $this->expires,
			'path'     => $this->path,
			'secure'   => $this->secure,
			'httponly' => $this->http_only,
			'samesite' => $this->same_site,
		);
	}
}
