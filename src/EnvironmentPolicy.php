<?php
/**
 * Environment policy normalization and cleanup helpers.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps policy parsing in one place so CLI, API, and automation stay consistent.
 */
class EnvironmentPolicy {

	/**
	 * Build metadata for a newly created environment.
	 *
	 * @param array            $input      Raw metadata input.
	 * @param string           $type       Environment type.
	 * @param string           $created_at Creation timestamp.
	 * @param RudelConfig|null $config     Optional config instance for default policy values.
	 * @return array<string, mixed>
	 */
	public static function metadata_for_create(
		array $input,
		string $type,
		string $created_at,
		?RudelConfig $config = null
	): array {
		$config            = $config ?? new RudelConfig();
		$default_ttl_days  = 'sandbox' === $type ? $config->get( 'default_ttl_days' ) : 0;
		$metadata          = array(
			'owner'        => null,
			'labels'       => array(),
			'purpose'      => null,
			'protected'    => false,
			'expires_at'   => null,
			'last_used_at' => $created_at,
			'shared_plugins' => false,
			'shared_uploads' => false,
		);
		$normalized_create = self::normalize_changes( $input, $type, $created_at );

		$has_expiry = array_key_exists( 'expires_at', $normalized_create );
		if ( ! $has_expiry && $default_ttl_days > 0 ) {
			$metadata['expires_at'] = gmdate( 'c', strtotime( '+' . $default_ttl_days . ' days', strtotime( $created_at ) ) );
		}

		return array_merge( $metadata, $normalized_create );
	}

	/**
	 * Normalize partial metadata changes for an existing or pending environment.
	 *
	 * @param array       $changes Raw change set.
	 * @param string      $type    Environment type.
	 * @param string|null $now     Optional timestamp used for TTL conversion.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException If metadata values are invalid.
	 */
	public static function normalize_changes( array $changes, string $type = 'sandbox', ?string $now = null ): array {
		$now        ??= gmdate( 'c' );
		$normalized   = array();
		$clear_expiry = ! empty( $changes['clear_expiry'] );

		if ( array_key_exists( 'owner', $changes ) ) {
			$normalized['owner'] = self::normalize_optional_string( $changes['owner'] );
		}

		if ( array_key_exists( 'labels', $changes ) ) {
			$normalized['labels'] = self::normalize_labels( $changes['labels'] );
		}

		if ( array_key_exists( 'purpose', $changes ) ) {
			$normalized['purpose'] = self::normalize_optional_string( $changes['purpose'] );
		}

		if ( array_key_exists( 'protected', $changes ) || array_key_exists( 'is_protected', $changes ) ) {
			$normalized['protected'] = self::normalize_boolean( $changes['protected'] ?? $changes['is_protected'] );
		}

		if ( $clear_expiry && ( array_key_exists( 'ttl_days', $changes ) || array_key_exists( 'expires_at', $changes ) ) ) {
			throw new \InvalidArgumentException( 'Cannot combine clear_expiry with ttl_days or expires_at.' );
		}

		if ( $clear_expiry ) {
			$normalized['expires_at'] = null;
		} elseif ( array_key_exists( 'ttl_days', $changes ) ) {
			$ttl_days                 = self::normalize_positive_integer( $changes['ttl_days'], 'ttl_days' );
			$normalized['expires_at'] = gmdate( 'c', strtotime( '+' . $ttl_days . ' days', strtotime( $now ) ) );
		} elseif ( array_key_exists( 'expires_at', $changes ) ) {
			$normalized['expires_at'] = self::normalize_timestamp( $changes['expires_at'], 'expires_at' );
		}

		if ( array_key_exists( 'last_used_at', $changes ) ) {
			$normalized['last_used_at'] = self::normalize_timestamp( $changes['last_used_at'], 'last_used_at' );
		}

		if ( array_key_exists( 'source_environment_id', $changes ) ) {
			$normalized['source_environment_id'] = self::normalize_environment_id( $changes['source_environment_id'], 'source_environment_id' );
		}

		if ( array_key_exists( 'source_environment_type', $changes ) ) {
			$normalized['source_environment_type'] = self::normalize_environment_type( $changes['source_environment_type'], 'source_environment_type' );
		}

		if ( array_key_exists( 'last_deployed_from_id', $changes ) ) {
			$normalized['last_deployed_from_id'] = self::normalize_environment_id( $changes['last_deployed_from_id'], 'last_deployed_from_id' );
		}

		if ( array_key_exists( 'last_deployed_from_type', $changes ) ) {
			$normalized['last_deployed_from_type'] = self::normalize_environment_type( $changes['last_deployed_from_type'], 'last_deployed_from_type' );
		}

		if ( array_key_exists( 'last_deployed_at', $changes ) ) {
			$normalized['last_deployed_at'] = self::normalize_timestamp( $changes['last_deployed_at'], 'last_deployed_at' );
		}

		if ( array_key_exists( 'tracked_git_remote', $changes ) ) {
			$normalized['tracked_git_remote'] = self::normalize_git_remote( $changes['tracked_git_remote'] );
		}

		if ( array_key_exists( 'tracked_git_branch', $changes ) ) {
			$normalized['tracked_git_branch'] = self::normalize_git_branch( $changes['tracked_git_branch'] );
		}

		if ( array_key_exists( 'tracked_git_dir', $changes ) ) {
			$normalized['tracked_git_dir'] = self::normalize_git_dir( $changes['tracked_git_dir'] );
		}

		if ( array_key_exists( 'shared_plugins', $changes ) ) {
			$normalized['shared_plugins'] = self::normalize_boolean( $changes['shared_plugins'] );
		}

		if ( array_key_exists( 'shared_uploads', $changes ) ) {
			$normalized['shared_uploads'] = self::normalize_boolean( $changes['shared_uploads'] );
		}

		if ( array_key_exists( 'clone_source', $changes ) ) {
			$normalized['clone_source'] = self::normalize_clone_source( $changes['clone_source'] );
		}

		return $normalized;
	}

	/**
	 * Determine whether cleanup should remove an environment and why.
	 *
	 * @param Environment $environment  Environment to evaluate.
	 * @param int         $now          Current timestamp.
	 * @param int         $max_age_days Maximum allowed age in days.
	 * @param int         $max_idle_days Maximum allowed idle time in days.
	 * @return string|null Cleanup reason, or null if the environment should stay.
	 */
	public static function cleanup_reason( Environment $environment, int $now, int $max_age_days = 0, int $max_idle_days = 0 ): ?string {
		if ( $environment->is_protected() ) {
			return null;
		}

		$expires_at = is_string( $environment->expires_at ) ? strtotime( $environment->expires_at ) : false;
		if ( false !== $expires_at && $expires_at <= $now ) {
			return 'expired';
		}

		$created_at = strtotime( $environment->created_at );
		if ( $max_age_days > 0 && false !== $created_at ) {
			$age_cutoff = $now - ( $max_age_days * 86400 );
			if ( $created_at < $age_cutoff ) {
				return 'age';
			}
		}

		$last_activity = $environment->last_activity_at();
		$last_activity = is_string( $last_activity ) ? strtotime( $last_activity ) : false;
		if ( $max_idle_days > 0 && false !== $last_activity ) {
			$idle_cutoff = $now - ( $max_idle_days * 86400 );
			if ( $last_activity < $idle_cutoff ) {
				return 'idle';
			}
		}

		return null;
	}

	/**
	 * Human-readable description of a cleanup reason.
	 *
	 * @param string $reason Cleanup reason code.
	 * @return string
	 */
	public static function describe_cleanup_reason( string $reason ): string {
		return match ( $reason ) {
			'expired' => 'expiry reached',
			'age'     => 'age policy matched',
			'idle'    => 'idle policy matched',
			default   => $reason,
		};
	}

	/**
	 * Normalize a nullable string.
	 *
	 * @param mixed $value Raw input.
	 * @return string|null
	 * @throws \InvalidArgumentException If the value cannot be normalized to a string.
	 */
	private static function normalize_optional_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			throw new \InvalidArgumentException( 'Expected a string metadata value.' );
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	/**
	 * Normalize a tracked Git remote identifier.
	 *
	 * @param mixed $value Raw input.
	 * @return string|null
	 * @throws \InvalidArgumentException If the value is not a valid remote URL or slug.
	 */
	private static function normalize_git_remote( $value ): ?string {
		$value = self::normalize_optional_string( $value );
		if ( null === $value ) {
			return null;
		}

		if ( str_contains( $value, ' ' ) ) {
			throw new \InvalidArgumentException( 'Tracked Git remotes cannot contain spaces.' );
		}

		return $value;
	}

	/**
	 * Normalize an optional tracked Git branch.
	 *
	 * @param mixed $value Raw input.
	 * @return string|null
	 */
	private static function normalize_git_branch( $value ): ?string {
		return self::normalize_optional_string( $value );
	}

	/**
	 * Normalize an optional tracked wp-content subdirectory.
	 *
	 * @param mixed $value Raw input.
	 * @return string|null
	 * @throws \InvalidArgumentException If the path is absolute or traverses outside wp-content.
	 */
	private static function normalize_git_dir( $value ): ?string {
		$value = self::normalize_optional_string( $value );
		if ( null === $value ) {
			return null;
		}

		$value = str_replace( '\\', '/', $value );
		$value = trim( $value, '/' );

		if ( '' === $value || '.' === $value ) {
			return null;
		}

		if ( preg_match( '#(^|/)\.\.?(?:/|$)#', $value ) ) {
			throw new \InvalidArgumentException( 'Tracked Git directories must stay within wp-content.' );
		}

		return $value;
	}

	/**
	 * Normalize internal clone metadata.
	 *
	 * @param mixed $value Raw input.
	 * @return array<string, mixed>|null
	 * @throws \InvalidArgumentException If the value is not null or an array payload.
	 */
	private static function normalize_clone_source( $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( 'clone_source must be an array or null.' );
		}

		return $value;
	}

	/**
	 * Normalize labels from string or array input.
	 *
	 * @param mixed $labels Raw labels.
	 * @return array<int, string>
	 * @throws \InvalidArgumentException If labels are not a string or array.
	 */
	private static function normalize_labels( $labels ): array {
		if ( is_string( $labels ) ) {
			$labels = explode( ',', $labels );
		}

		if ( ! is_array( $labels ) ) {
			throw new \InvalidArgumentException( 'Labels must be a comma-separated string or an array.' );
		}

		$normalized = array();
		foreach ( $labels as $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}

			$label = trim( (string) $label );
			if ( '' !== $label ) {
				$normalized[] = $label;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Normalize a boolean-like input.
	 *
	 * @param mixed $value Raw boolean-like input.
	 * @return bool
	 * @throws \InvalidArgumentException If the value cannot be normalized to a boolean.
	 */
	private static function normalize_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}

		throw new \InvalidArgumentException( 'Expected a boolean-like value.' );
	}

	/**
	 * Normalize a positive integer policy value.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $field Field name for errors.
	 * @return int
	 * @throws \InvalidArgumentException If the value is not a positive integer.
	 */
	private static function normalize_positive_integer( $value, string $field ): int {
		if ( is_string( $value ) && '' === trim( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s must be greater than zero.', $field ) );
		}

		if ( ! is_numeric( $value ) ) {
			throw new \InvalidArgumentException( sprintf( '%s must be an integer.', $field ) );
		}

		$value = (int) $value;
		if ( $value <= 0 ) {
			throw new \InvalidArgumentException( sprintf( '%s must be greater than zero.', $field ) );
		}

		return $value;
	}

	/**
	 * Normalize an ISO-like timestamp.
	 *
	 * @param mixed  $value Raw timestamp input.
	 * @param string $field Field name for errors.
	 * @return string|null
	 * @throws \InvalidArgumentException If the timestamp is invalid.
	 */
	private static function normalize_timestamp( $value, string $field ): ?string {
		$value = self::normalize_optional_string( $value );
		if ( null === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid %s timestamp: %s', $field, $value ) );
		}

		return gmdate( 'c', $timestamp );
	}

	/**
	 * Normalize an environment ID reference.
	 *
	 * @param mixed  $value Raw environment ID.
	 * @param string $field Field name for errors.
	 * @return string|null
	 * @throws \InvalidArgumentException If the ID is invalid.
	 */
	private static function normalize_environment_id( $value, string $field ): ?string {
		$value = self::normalize_optional_string( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! Environment::validate_id( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid %s: %s', $field, $value ) );
		}

		return $value;
	}

	/**
	 * Normalize an environment type reference.
	 *
	 * @param mixed  $value Raw environment type.
	 * @param string $field Field name for errors.
	 * @return string|null
	 * @throws \InvalidArgumentException If the environment type is invalid.
	 */
	private static function normalize_environment_type( $value, string $field ): ?string {
		$value = self::normalize_optional_string( $value );
		if ( null === $value ) {
			return null;
		}

		if ( ! in_array( $value, array( 'sandbox', 'app' ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid %s: %s', $field, $value ) );
		}

		return $value;
	}
}
