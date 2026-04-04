<?php
/**
 * Plugin Name: Rudel Runtime Hooks
 * Description: Runtime hooks that must always load inside a Rudel environment.
 *
 * @package Rudel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'RUDEL_RUNTIME_HOOKS_LOADED' ) ) {
	return;
}

define( 'RUDEL_RUNTIME_HOOKS_LOADED', true );

/**
 * Return the resolved environment URL even when the host defines WP_HOME/WP_SITEURL.
 *
 * @return string|null
 */
function rudel_runtime_environment_url() {
	if ( defined( 'RUDEL_ENVIRONMENT_URL' ) && is_string( RUDEL_ENVIRONMENT_URL ) && '' !== RUDEL_ENVIRONMENT_URL ) {
		return rtrim( RUDEL_ENVIRONMENT_URL, '/' );
	}

	if ( defined( 'WP_HOME' ) && is_string( WP_HOME ) && '' !== WP_HOME ) {
		return rtrim( WP_HOME, '/' );
	}

	return null;
}

if ( null !== rudel_runtime_environment_url() ) {
	// Host-level WP_HOME/WP_SITEURL constants override database reads, so sandboxes/apps need a runtime pre_option override.
	add_filter(
		'pre_option_home',
		function ( $value ) {
			return rudel_runtime_environment_url();
		}
	);

	add_filter(
		'pre_option_siteurl',
		function ( $value ) {
			return rudel_runtime_environment_url();
		}
	);
}

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		if ( ! defined( 'RUDEL_DISABLE_EMAIL' ) || ! RUDEL_DISABLE_EMAIL ) {
			return $null;
		}

		$to = $atts['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}

		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'RUDEL_ID' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in sandbox debug.log.
			error_log( sprintf( 'Rudel: email blocked in sandbox %s (to: %s, subject: %s)', RUDEL_ID, $to, $subject ) );
		}

		return true;
	},
	10,
	2
);

if ( defined( 'RUDEL_ID' ) && '' !== RUDEL_ID ) {
	add_action(
		'admin_bar_menu',
		function ( $wp_admin_bar ) {
			$is_app = defined( 'RUDEL_IS_APP' ) && RUDEL_IS_APP;
			$title  = '&#9632; ' . ( $is_app ? 'App' : 'Sandbox' ) . ': ' . RUDEL_ID;
			$base   = rudel_runtime_environment_url();
			$href   = $is_app
				? ( $base ?? '/' )
				: ( $base ? $base . '/?adminExit' : '/?adminExit' );

			$wp_admin_bar->add_node(
				array(
					'id'    => 'rudel-environment',
					'title' => $title,
					'href'  => $href,
					'meta'  => array(
						'title' => $is_app
							? 'Current Rudel app environment'
							: 'Click to exit sandbox and return to host',
					),
				)
			);
		},
		1
	);

	add_action( 'wp_head', 'rudel_runtime_admin_bar_styles' );
	add_action( 'admin_head', 'rudel_runtime_admin_bar_styles' );
}

/**
 * Output admin bar styles for the environment indicator.
 *
 * @return void
 */
function rudel_runtime_admin_bar_styles() {
	if ( ! defined( 'RUDEL_ID' ) || '' === RUDEL_ID ) {
		return;
	}

	$is_app = defined( 'RUDEL_IS_APP' ) && RUDEL_IS_APP;
	$color  = $is_app ? '#2271b1' : '#d63638';
	echo '<style>#wp-admin-bar-rudel-environment > a { background: ' . esc_attr( $color ) . ' !important; color: #fff !important; font-weight: 600 !important; }</style>';
}
