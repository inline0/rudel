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
 * Return the resolved environment URL for the active Rudel site.
 *
 * @return string|null
 */
function rudel_runtime_environment_url() {
	if ( defined( 'RUDEL_ENVIRONMENT_URL' ) && is_string( RUDEL_ENVIRONMENT_URL ) && '' !== RUDEL_ENVIRONMENT_URL ) {
		return rtrim( RUDEL_ENVIRONMENT_URL, '/' );
	}

	return null;
}

/**
 * Return the resolved multisite blog ID for the active Rudel site.
 *
 * @return int|null
 */
function rudel_runtime_blog_id() {
	if ( defined( 'RUDEL_TABLE_PREFIX' ) && is_string( RUDEL_TABLE_PREFIX ) ) {
		if ( preg_match( '/(\d+)_$/', RUDEL_TABLE_PREFIX, $matches ) ) {
			return (int) $matches[1];
		}
	}

	return null;
}

/**
 * Current multisite blog ID in this runtime context.
 *
 * @return int|null
 */
function rudel_runtime_current_blog_id() {
	global $wpdb, $table_prefix, $blog_id, $current_blog;

	if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->blogid ) ) {
		$current_blog_id = (int) $wpdb->blogid;
		if ( $current_blog_id > 0 ) {
			return $current_blog_id;
		}
	}

	if ( isset( $table_prefix ) && is_string( $table_prefix ) && preg_match( '/(\d+)_$/', $table_prefix, $matches ) ) {
		return (int) $matches[1];
	}

	if ( isset( $current_blog ) && is_object( $current_blog ) && isset( $current_blog->blog_id ) ) {
		return (int) $current_blog->blog_id;
	}

	if ( isset( $blog_id ) ) {
		return (int) $blog_id;
	}

	if ( function_exists( 'get_current_blog_id' ) ) {
		$current_blog_id = (int) get_current_blog_id();
		if ( $current_blog_id > 0 ) {
			return $current_blog_id;
		}
	}

	return null;
}

/**
 * Network port suffix including the leading colon when present.
 *
 * @return string
 */
function rudel_runtime_network_port_suffix() {
	$host_url = rudel_runtime_host_url();
	if ( null === $host_url ) {
		return '';
	}

	$parts = wp_parse_url( $host_url );
	if ( ! is_array( $parts ) || ! isset( $parts['port'] ) ) {
		return '';
	}

	return ':' . (int) $parts['port'];
}

/**
 * Canonical URL for one multisite blog in the current network.
 *
 * @param int $blog_id Blog ID.
 * @return string|null
 */
function rudel_runtime_blog_url_for( $blog_id ) {
	$blog_id = (int) $blog_id;
	$domain  = '';
	$path    = '/';

	global $wpdb;

	if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_row' ) ) {
		$blogs_table = null;
		if ( isset( $wpdb->blogs ) && is_string( $wpdb->blogs ) && '' !== $wpdb->blogs ) {
			$blogs_table = $wpdb->blogs;
		} elseif ( isset( $wpdb->base_prefix ) && is_string( $wpdb->base_prefix ) && '' !== $wpdb->base_prefix ) {
			$blogs_table = $wpdb->base_prefix . 'blogs';
		}

		if ( is_string( $blogs_table ) && '' !== $blogs_table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime multisite site lookup without recursing through option APIs.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT domain, path FROM `' . $blogs_table . '` WHERE blog_id = %d LIMIT 1',
					$blog_id
				)
			);

			if ( is_object( $row ) ) {
				$domain = isset( $row->domain ) ? (string) $row->domain : '';
				$path   = isset( $row->path ) ? (string) $row->path : '/';
			}
		}
	}

	if ( '' === $domain && function_exists( 'get_blog_details' ) ) {
		$details = get_blog_details( $blog_id );
		if ( $details && ! empty( $details->domain ) ) {
			$domain = (string) $details->domain;
			$path   = isset( $details->path ) ? (string) $details->path : '/';
		}
	}

	if ( '' === $domain ) {
		return null;
	}

	if ( '' === $path ) {
		$path = '/';
	}
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}

	$scheme = 'http';
	$host_url = rudel_runtime_host_url();
	if ( null !== $host_url ) {
		$parts = wp_parse_url( $host_url );
		if ( is_array( $parts ) && isset( $parts['scheme'] ) ) {
			$scheme = (string) $parts['scheme'];
		}
	}

	$url = $scheme . '://' . $domain;
	if ( ! preg_match( '/:\d+$/', $domain ) ) {
		$url .= rudel_runtime_network_port_suffix();
	}

	return rtrim( $url, '/' ) . $path;
}

/**
 * Blog-aware site URL override for the current option read.
 *
 * @param mixed $value Current pre_option value.
 * @return mixed
 */
function rudel_runtime_site_option_override( $value ) {
	$current_blog_id  = rudel_runtime_current_blog_id();
	$resolved_blog_id = rudel_runtime_blog_id();

	if ( null !== $resolved_blog_id && null !== $current_blog_id && $current_blog_id === $resolved_blog_id ) {
		$environment_url = rudel_runtime_environment_url();
		if ( null !== $environment_url ) {
			return $environment_url;
		}
	}

	if ( null !== $current_blog_id ) {
		$blog_url = rudel_runtime_blog_url_for( $current_blog_id );
		if ( null !== $blog_url ) {
			return rtrim( $blog_url, '/' );
		}
	}

	return $value;
}

/**
 * Return the network host URL when Rudel resolved one in bootstrap.
 *
 * @return string|null
 */
function rudel_runtime_host_url() {
	if ( defined( 'RUDEL_HOST_URL' ) && is_string( RUDEL_HOST_URL ) && '' !== RUDEL_HOST_URL ) {
		return rtrim( RUDEL_HOST_URL, '/' );
	}

	return null;
}

if ( null !== rudel_runtime_environment_url() ) {
	// Host-level WP_HOME/WP_SITEURL constants override database reads, but the
	// override must stay blog-aware so switch_to_blog() still yields distinct
	// root/current/sibling URLs in multisite admin flows.
	add_filter(
		'pre_option_home',
		function ( $value ) {
			return rudel_runtime_site_option_override( $value );
		}
	);

	add_filter(
		'pre_option_siteurl',
		function ( $value ) {
			return rudel_runtime_site_option_override( $value );
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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in the environment debug.log.
			error_log( sprintf( 'Rudel: email blocked in environment %s (to: %s, subject: %s)', RUDEL_ID, $to, $subject ) );
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
			$host   = rudel_runtime_host_url();
			$href   = $is_app ? ( $base ?? '/' ) : ( $host ?? '/' );

			$wp_admin_bar->add_node(
				array(
					'id'    => 'rudel-environment',
					'title' => $title,
					'href'  => $href,
					'meta'  => array(
						'title' => $is_app
							? 'Current Rudel app environment'
							: 'Return to the Rudel network host',
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
