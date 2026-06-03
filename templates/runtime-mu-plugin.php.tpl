<?php
/**
 * Plugin Name: Runtime Hooks
 * Description: Runtime hooks that must always load inside a selected environment.
 *
 * @package Rudel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( '{{runtime_mu_loaded_constant}}' ) ) {
	return;
}

define( '{{runtime_mu_loaded_constant}}', true );

/**
 * Return the resolved environment URL for the active runtime site.
 *
 * @return string|null
 */
function {{runtime_environment_url_fn}}() {
	if ( defined( '{{constant_environment_url}}' ) && is_string( {{constant_environment_url}} ) && '' !== {{constant_environment_url}} ) {
		return rtrim( {{constant_environment_url}}, '/' );
	}

	return null;
}

/**
 * Return the resolved multisite blog ID for the active runtime site.
 *
 * @return int|null
 */
function {{runtime_blog_id_fn}}() {
	if ( defined( '{{constant_table_prefix}}' ) && is_string( {{constant_table_prefix}} ) ) {
		if ( preg_match( '/(\d+)_$/', {{constant_table_prefix}}, $matches ) ) {
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
function {{runtime_current_blog_id_fn}}() {
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
function {{runtime_port_suffix_fn}}() {
	$host_url = {{runtime_host_url_fn}}();
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
function {{runtime_blog_url_for_fn}}( $blog_id ) {
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
	$host_url = {{runtime_host_url_fn}}();
	if ( null !== $host_url ) {
		$parts = wp_parse_url( $host_url );
		if ( is_array( $parts ) && isset( $parts['scheme'] ) ) {
			$scheme = (string) $parts['scheme'];
		}
	}

	$url = $scheme . '://' . $domain;
	if ( ! preg_match( '/:\d+$/', $domain ) ) {
		$url .= {{runtime_port_suffix_fn}}();
	}

	return rtrim( $url, '/' ) . $path;
}

/**
 * Blog-aware site URL override for the current option read.
 *
 * @param mixed $value Current pre_option value.
 * @return mixed
 */
function {{runtime_site_option_fn}}( $value ) {
	$current_blog_id  = {{runtime_current_blog_id_fn}}();
	$resolved_blog_id = {{runtime_blog_id_fn}}();

	if ( null !== $resolved_blog_id && null !== $current_blog_id && $current_blog_id === $resolved_blog_id ) {
		$environment_url = {{runtime_environment_url_fn}}();
		if ( null !== $environment_url ) {
			return $environment_url;
		}
	}

	if ( null !== $current_blog_id ) {
		$blog_url = {{runtime_blog_url_for_fn}}( $current_blog_id );
		if ( null !== $blog_url ) {
			return rtrim( $blog_url, '/' );
		}
	}

	return $value;
}

/**
 * Return the network host URL when bootstrap resolved one.
 *
 * @return string|null
 */
function {{runtime_host_url_fn}}() {
	if ( defined( '{{constant_host_url}}' ) && is_string( {{constant_host_url}} ) && '' !== {{constant_host_url}} ) {
		return rtrim( {{constant_host_url}}, '/' );
	}

	return null;
}

if ( null !== {{runtime_environment_url_fn}}() ) {
	// Host-level WP_HOME/WP_SITEURL constants override database reads, but the
	// override must stay blog-aware so switch_to_blog() still yields distinct
	// root/current/sibling URLs in multisite admin flows.
	add_filter(
		'pre_option_home',
		function ( $value ) {
			return {{runtime_site_option_fn}}( $value );
		}
	);

	add_filter(
		'pre_option_siteurl',
		function ( $value ) {
			return {{runtime_site_option_fn}}( $value );
		}
	);

}
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		if ( ! defined( '{{constant_disable_email}}' ) || ! {{constant_disable_email}} ) {
			return $null;
		}

		$to = $atts['to'] ?? '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', array_map( 'strval', $to ) );
		}

		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( '{{constant_id}}' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in the environment debug.log.
			error_log( sprintf( '{{email_log_label}}: email blocked in environment %s (to: %s, subject: %s)', {{constant_id}}, $to, $subject ) );
		}

		return true;
	},
	10,
	2
);

if ( defined( '{{constant_id}}' ) && '' !== {{constant_id}} ) {
	add_action(
		'admin_bar_menu',
		function ( $wp_admin_bar ) {
			$title  = '&#9632; {{admin_bar_title}}: ' . {{constant_id}};
			$base   = {{runtime_environment_url_fn}}();
			$host   = {{runtime_host_url_fn}}();
			$href   = $host ?? ( $base ?? '/' );

			$wp_admin_bar->add_node(
				array(
					'id'    => '{{admin_bar_node_id}}',
					'title' => $title,
					'href'  => $href,
					'meta'  => array(
						'title' => 'Return to the host site',
					),
				)
			);
		},
		1
	);

	add_action( 'wp_head', '{{runtime_admin_styles_fn}}' );
	add_action( 'admin_head', '{{runtime_admin_styles_fn}}' );
}

/**
 * Output admin bar styles for the environment indicator.
 *
 * @return void
 */
function {{runtime_admin_styles_fn}}() {
	if ( ! defined( '{{constant_id}}' ) || '' === {{constant_id}} ) {
		return;
	}

	echo '<style>#wp-admin-bar-{{admin_bar_node_id}} > a { background: #d63638 !important; color: #fff !important; font-weight: 600 !important; }</style>';
}
