<?php
/**
 * Shared CLI parsing for environment metadata and policy flags.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

/**
 * Normalizes metadata flags so create/update commands stay in sync.
 */
trait HandlesEnvironmentPolicy {

	/**
	 * Build metadata changes from CLI arguments.
	 *
	 * @param array $assoc_args CLI associative arguments.
	 * @return array<string, mixed>
	 */
	private function build_policy_changes( array $assoc_args ): array {
		$changes = array();

		if ( array_key_exists( 'owner', $assoc_args ) ) {
			$changes['owner'] = $assoc_args['owner'];
		}

		if ( array_key_exists( 'labels', $assoc_args ) ) {
			$changes['labels'] = $assoc_args['labels'];
		}

		if ( array_key_exists( 'purpose', $assoc_args ) ) {
			$changes['purpose'] = $assoc_args['purpose'];
		}

		$protect   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'protected', false );
		$unprotect = \WP_CLI\Utils\get_flag_value( $assoc_args, 'unprotected', false );

		if ( $protect && $unprotect ) {
			\WP_CLI::error( 'Cannot combine --protected and --unprotected.' );
		}

		if ( $protect ) {
			$changes['protected'] = true;
		} elseif ( $unprotect ) {
			$changes['protected'] = false;
		}

		if ( array_key_exists( 'ttl-days', $assoc_args ) ) {
			$changes['ttl_days'] = $assoc_args['ttl-days'];
		}

		if ( array_key_exists( 'expires-at', $assoc_args ) ) {
			$changes['expires_at'] = $assoc_args['expires-at'];
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'clear-expiry', false ) ) {
			$changes['clear_expiry'] = true;
		}

		return $changes;
	}

	/**
	 * Format whether an environment is protected for CLI tables.
	 *
	 * @param bool $is_protected Protection state.
	 * @return string
	 */
	private function format_protection( bool $is_protected ): string {
		return $is_protected ? 'yes' : 'no';
	}
}
