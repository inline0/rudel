<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/phpstan/wordpress/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

if ( ! defined( 'WP_HOME' ) ) {
	define( 'WP_HOME', 'https://example.test' );
}

if ( ! defined( 'RUDEL_PLUGIN_FILE' ) ) {
	define( 'RUDEL_PLUGIN_FILE', __DIR__ . '/rudel.php' );
}

if ( ! defined( 'RUDEL_PLUGIN_DIR' ) ) {
	define( 'RUDEL_PLUGIN_DIR', __DIR__ . '/' );
}

if ( ! defined( 'RUDEL_VERSION' ) ) {
	define( 'RUDEL_VERSION', '0.0.0' );
}

if ( ! defined( 'RUDEL_CLI_COMMAND' ) ) {
	define( 'RUDEL_CLI_COMMAND', 'rudel' );
}

if ( ! defined( 'RUDEL_PATH_PREFIX' ) ) {
	define( 'RUDEL_PATH_PREFIX', '__rudel' );
}

if ( ! defined( 'RUDEL_ENVIRONMENTS_DIR' ) ) {
	define( 'RUDEL_ENVIRONMENTS_DIR', WP_CONTENT_DIR . '/rudel-environments' );
}

if ( ! defined( 'RUDEL_APPS_DIR' ) ) {
	define( 'RUDEL_APPS_DIR', WP_CONTENT_DIR . '/rudel-apps' );
}

if ( ! defined( 'RUDEL_GITHUB_TOKEN' ) ) {
	define( 'RUDEL_GITHUB_TOKEN', '' );
}

if ( ! defined( 'RUDEL_ID' ) ) {
	define( 'RUDEL_ID', 'phpstan-environment' );
}

if ( ! defined( 'RUDEL_IS_APP' ) ) {
	define( 'RUDEL_IS_APP', false );
}

if ( ! defined( 'RUDEL_PATH' ) ) {
	define( 'RUDEL_PATH', RUDEL_ENVIRONMENTS_DIR . '/phpstan-environment' );
}

if ( ! defined( 'RUDEL_TABLE_PREFIX' ) ) {
	define( 'RUDEL_TABLE_PREFIX', 'wpphpstan_' );
}

if ( ! defined( 'RUDEL_DISABLE_EMAIL' ) ) {
	define( 'RUDEL_DISABLE_EMAIL', true );
}
