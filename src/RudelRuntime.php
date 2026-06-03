<?php
/**
 * Runtime API for embedded integrations.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Reads active runtime state without exposing profile constant names to callers.
 */
final class RudelRuntime {

	/**
	 * Runtime profile.
	 *
	 * @var RuntimeProfile
	 */
	private RuntimeProfile $profile;

	/**
	 * Initialize dependencies.
	 *
	 * @param RuntimeProfile|null $profile Runtime profile. Defaults to the active profile.
	 */
	public function __construct( ?RuntimeProfile $profile = null ) {
		$this->profile = $profile ?? RuntimeProfile::current();
	}

	/**
	 * Active environment/worktree ID.
	 *
	 * @return string|null
	 */
	public function active_id(): ?string {
		return $this->string_constant( $this->profile->constant( 'id' ) );
	}

	/**
	 * Whether the requested ID is the active runtime ID.
	 *
	 * @param string $id Runtime ID.
	 * @return bool
	 */
	public function is_active( string $id ): bool {
		return '' !== $id && $id === $this->active_id();
	}

	/**
	 * Cookie spec that activates one runtime ID.
	 *
	 * @param string $id Runtime ID.
	 * @return CookieSpec
	 */
	public function activation_cookie( string $id ): CookieSpec {
		return new CookieSpec(
			name: $this->profile->cookie_name(),
			value: $id,
			secure: $this->is_secure_request()
		);
	}

	/**
	 * Host WordPress table prefix while inside a selected runtime.
	 *
	 * @return string|null
	 */
	public function host_table_prefix(): ?string {
		return $this->string_constant( $this->profile->constant( 'host_table_prefix' ) );
	}

	/**
	 * Environment variables needed to run a command in one runtime.
	 *
	 * @param string $id Runtime ID.
	 * @return array<string, string>
	 */
	public function environment_variable( string $id ): array {
		return array(
			$this->profile->environment_variable_name() => $id,
		);
	}

	/**
	 * Read one optional string constant.
	 *
	 * @param string $constant Constant name.
	 * @return string|null
	 */
	private function string_constant( string $constant ): ?string {
		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = constant( $constant );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Whether the current request is HTTPS.
	 *
	 * @return bool
	 */
	private function is_secure_request(): bool {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Runtime transport detection only compares known scalar HTTPS markers.
		if ( isset( $_SERVER['HTTPS'] ) && is_string( $_SERVER['HTTPS'] ) && '' !== $_SERVER['HTTPS'] && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) {
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput
			return true;
		}

		$is_forwarded_https = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'];
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		return $is_forwarded_https;
	}
}
