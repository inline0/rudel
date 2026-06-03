<?php
/**
 * Plugin Name: Rudel
 * Description: The WordPress isolation layer for request-selected sandboxes.
 * Version: 0.11.0
 * Author: Inline0
 * Author URI: https://inline0.com
 * License: GPL-2.0-or-later
 * Requires PHP: 8.2
 * Requires at least: 6.4
 *
 * @package Rudel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RUDEL_VERSION', '0.11.0' );
define( 'RUDEL_PLUGIN_FILE', __FILE__ );
define( 'RUDEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RUDEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$rudel_autoload = RUDEL_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $rudel_autoload ) ) {
	// Composer can install Rudel as a library or as a plugin, so the autoloader does not always live under this directory.
	$rudel_autoload = dirname( __DIR__, 2 ) . '/autoload.php';
}
if ( file_exists( $rudel_autoload ) ) {
	require_once $rudel_autoload;
}
unset( $rudel_autoload );

/**
 * Ensure Rudel's runtime tables exist whenever WordPress has a DB connection.
 *
 * @return void
 * @throws RuntimeException If no runtime profile is available.
 */
function rudel_ensure_runtime_schema() {
	if ( ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
		return;
	}

	if ( null === rudel_runtime_profile_or_null() ) {
		throw new RuntimeException( 'A runtime profile is required before Rudel runtime schema can be installed.' );
	}

	Rudel\RudelSchema::ensure( new Rudel\WpdbStore( $GLOBALS['wpdb'] ) );
}

register_activation_hook(
	__FILE__,
	function () {
		rudel_ensure_runtime_schema();
		$writer = new Rudel\ConfigWriter();
		$writer->install();
		Rudel\Automation::ensure_scheduled();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		Rudel\Automation::unschedule();
		$writer = new Rudel\ConfigWriter();
		$writer->uninstall();
	}
);

add_action( 'plugins_loaded', 'rudel_ensure_runtime_schema', 1 );

if ( ! defined( 'RUDEL_CLI_COMMAND' ) ) {
	define( 'RUDEL_CLI_COMMAND', 'rudel' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( RUDEL_CLI_COMMAND, Rudel\CLI\RudelCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' cleanup', Rudel\CLI\CleanupCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' logs', Rudel\CLI\LogsCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' push', Rudel\CLI\PushCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' restore', Rudel\CLI\RestoreCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' snapshot', Rudel\CLI\SnapshotCommand::class );
	WP_CLI::add_command( RUDEL_CLI_COMMAND . ' template', Rudel\CLI\TemplateCommand::class );
}

/**
 * Current runtime profile, if one is installed.
 *
 * @return Rudel\RuntimeProfile|null
 */
function rudel_runtime_profile_or_null(): ?Rudel\RuntimeProfile {
	try {
		return Rudel\RuntimeProfile::current();
	} catch ( Throwable $e ) {
		unset( $e );
	}

	$filtered = Rudel\Hooks::filter( 'rudel_runtime_profile', null );
	if ( $filtered instanceof Rudel\RuntimeProfile ) {
		Rudel\RuntimeProfile::set_current( $filtered );
		return $filtered;
	}

	if ( is_array( $filtered ) ) {
		try {
			Rudel\RuntimeProfile::set_current( $filtered );
			return Rudel\RuntimeProfile::current();
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	return null;
}

/**
 * Runtime constant value for one profile slot.
 *
 * @param string $key Logical constant key.
 * @return mixed|null
 */
function rudel_runtime_constant_value( string $key ) {
	$profile = rudel_runtime_profile_or_null();
	if ( null === $profile ) {
		return null;
	}

	$constant = $profile->constant( $key );
	return defined( $constant ) ? constant( $constant ) : null;
}

/**
 * Whether a runtime constant slot is defined.
 *
 * @param string $key Logical constant key.
 * @return bool
 */
function rudel_runtime_constant_defined( string $key ): bool {
	$profile = rudel_runtime_profile_or_null();
	return null !== $profile && defined( $profile->constant( $key ) );
}

$rudel_runtime_profile = rudel_runtime_profile_or_null();
$rudel_hooks_constant  = null !== $rudel_runtime_profile ? $rudel_runtime_profile->runtime_mu_loaded_constant() : null;

if ( null === $rudel_hooks_constant || ! defined( $rudel_hooks_constant ) ) {
	if ( null !== $rudel_hooks_constant ) {
		define( $rudel_hooks_constant, true );
	}

	if ( Rudel\Rudel::is_sandbox() && null !== $rudel_runtime_profile && ! rudel_runtime_constant_defined( 'table_prefix' ) && isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && isset( $GLOBALS['wpdb']->prefix ) && is_string( $GLOBALS['wpdb']->prefix ) && '' !== $GLOBALS['wpdb']->prefix ) {
		define( $rudel_runtime_profile->constant( 'table_prefix' ), $GLOBALS['wpdb']->prefix );
	}

	// Register this unconditionally so late-defined environment constants can still suppress mail before it leaves PHP.
	add_filter(
		'pre_wp_mail',
		function ( $null, $atts ) {
			if ( ! Rudel\Rudel::is_email_disabled() ) {
				return $null;
			}

			$to = $atts['to'] ?? '';
			if ( is_array( $to ) ) {
				$to = implode( ', ', array_map( 'strval', $to ) );
			}

			$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';

			$id      = rudel_runtime_constant_value( 'id' );
			$profile = rudel_runtime_profile_or_null();
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && is_string( $id ) && '' !== $id ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: logging blocked email in the environment debug.log.
				error_log( sprintf( '%s: email blocked in environment %s (to: %s, subject: %s)', null !== $profile ? $profile->email_log_label() : 'Runtime', $id, $to, $subject ) );
			}

			return true;
		},
		10,
		2
	);

	add_action(
		'init',
		array( Rudel\Automation::class, 'ensure_scheduled' )
	);
	add_action(
		Rudel\Automation::CRON_HOOK,
		array( Rudel\Automation::class, 'run' )
	);

	if ( rudel_runtime_constant_defined( 'theme_slug' ) && rudel_runtime_constant_defined( 'theme_root' ) ) {
		add_filter(
			'pre_option_template',
			'rudel_overlay_template_slug'
		);
		add_filter(
			'pre_option_stylesheet',
			'rudel_overlay_stylesheet_slug'
		);
		add_filter(
			'pre_option_current_theme',
			'rudel_overlay_current_theme'
		);
		add_filter(
			'theme_root',
			'rudel_overlay_theme_root',
			10,
			2
		);
		add_filter(
			'theme_root_uri',
			'rudel_overlay_theme_root_uri',
			10,
			3
		);

		$rudel_theme_root = rudel_runtime_constant_value( 'theme_root' );
		if ( function_exists( 'register_theme_directory' ) && is_string( $rudel_theme_root ) ) {
			register_theme_directory( $rudel_theme_root );
		}
	}

	if ( Rudel\Rudel::is_sandbox() ) {
		Rudel\Rudel::touch_current_environment();

		add_action(
			'admin_bar_menu',
			function ( $wp_admin_bar ) {
				$profile = rudel_runtime_profile_or_null();
				$wp_admin_bar->add_node(
					array(
						'id'    => null !== $profile ? $profile->admin_bar_node_id() : 'runtime-environment',
						'title' => '&#9632; ' . ( null !== $profile ? $profile->admin_bar_title() : 'Environment' ) . ': ' . Rudel\Rudel::id(),
						'href'  => Rudel\Rudel::exit_url(),
						'meta'  => array(
							'title' => 'Click to exit sandbox and return to host',
						),
					)
				);
			},
			1
		);

		add_action(
			'wp_head',
			'rudel_admin_bar_styles'
		);
		add_action(
			'admin_head',
			'rudel_admin_bar_styles'
		);
	}
}

/**
 * Active overlay stylesheet slug.
 *
 * @return string
 */
function rudel_overlay_stylesheet_slug(): string {
	$slug = rudel_runtime_constant_value( 'theme_slug' );
	return is_string( $slug ) ? $slug : '';
}

/**
 * Active overlay template slug.
 *
 * @return string
 */
function rudel_overlay_template_slug(): string {
	$slug = rudel_runtime_constant_value( 'template_slug' );
	if ( is_string( $slug ) && '' !== $slug ) {
		return $slug;
	}

	return rudel_overlay_stylesheet_slug();
}

/**
 * Display name for the current overlay theme.
 *
 * @return string
 */
function rudel_overlay_current_theme(): string {
	$slug = rudel_overlay_stylesheet_slug();
	if ( function_exists( 'wp_get_theme' ) && '' !== $slug ) {
		$theme = wp_get_theme( $slug );
		if ( $theme && method_exists( $theme, 'exists' ) && $theme->exists() ) {
			return (string) $theme->get( 'Name' );
		}
	}

	return $slug;
}

/**
 * Theme root for the selected overlay theme.
 *
 * @param string $theme_root Current root.
 * @param mixed  $stylesheet Theme stylesheet.
 * @return string
 */
function rudel_overlay_theme_root( string $theme_root, $stylesheet = null ): string {
	if ( is_scalar( $stylesheet ) && rudel_overlay_owns_theme_slug( (string) $stylesheet ) ) {
		$runtime_theme_root = rudel_runtime_constant_value( 'theme_root' );
		return is_string( $runtime_theme_root ) ? $runtime_theme_root : $theme_root;
	}

	return $theme_root;
}

/**
 * Theme root URI for the selected overlay theme.
 *
 * @param string $theme_root_uri Current root URI.
 * @param string $siteurl Site URL.
 * @param mixed  $stylesheet Theme stylesheet.
 * @return string
 */
function rudel_overlay_theme_root_uri( string $theme_root_uri, $siteurl = null, $stylesheet = null ): string {
	unset( $siteurl );

	$runtime_theme_root_uri = rudel_runtime_constant_value( 'theme_root_uri' );
	if ( is_string( $runtime_theme_root_uri ) && is_scalar( $stylesheet ) && rudel_overlay_owns_theme_slug( (string) $stylesheet ) ) {
		return $runtime_theme_root_uri;
	}

	return $theme_root_uri;
}

/**
 * Whether one theme slug is owned by the selected overlay root.
 *
 * @param string $slug Theme slug.
 * @return bool
 */
function rudel_overlay_owns_theme_slug( string $slug ): bool {
	$theme_root = rudel_runtime_constant_value( 'theme_root' );
	if ( ! is_string( $theme_root ) || '' === $slug ) {
		return false;
	}

	$owned_slugs = array_unique(
		array_filter(
			array(
				rudel_overlay_stylesheet_slug(),
				rudel_overlay_template_slug(),
			),
			static fn ( string $value ): bool => '' !== $value
		)
	);

	return in_array( $slug, $owned_slugs, true ) && is_dir( rtrim( $theme_root, '/' ) . '/' . $slug );
}

/**
 * Output admin bar styles for the sandbox indicator.
 *
 * @return void
 */
function rudel_admin_bar_styles() {
	$profile = rudel_runtime_profile_or_null();
	$node_id = null !== $profile ? $profile->admin_bar_node_id() : 'runtime-environment';
	echo '<style>#wp-admin-bar-' . esc_attr( $node_id ) . ' > a { background: #d63638 !important; color: #fff !important; font-weight: 600 !important; }</style>';
}
