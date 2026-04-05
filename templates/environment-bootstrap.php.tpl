<?php
/**
 * Per-environment bootstrap -- loaded by wp-cli when working inside one Rudel site.
 * Rudel uses subdomain multisite sites as the canonical runtime surface.
 * This bootstrap mirrors the real site URL directly.
 */

$sandbox_id = '{{sandbox_id}}';
$sandbox_path = '{{sandbox_path}}';

// Already resolved (global bootstrap ran first)
if (defined('RUDEL_ID')) {
    return;
}

// WP content directories
define('WP_CONTENT_DIR', $sandbox_path . '/wp-content');
define('WP_PLUGIN_DIR', $sandbox_path . '/wp-content/plugins');
define('WPMU_PLUGIN_DIR', $sandbox_path . '/wp-content/mu-plugins');
define('WP_TEMP_DIR', $sandbox_path . '/tmp');
define('UPLOADS', 'wp-content/uploads');

if (! defined('WP_ALLOW_MULTISITE')) { define('WP_ALLOW_MULTISITE', true); }
if (! defined('MULTISITE')) { define('MULTISITE', true); }
if (! defined('SUBDOMAIN_INSTALL')) { define('SUBDOMAIN_INSTALL', true); }

$_rudel_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$_rudel_host_without_port = preg_replace('/:\d+$/', '', $_rudel_host);
$_rudel_port = str_contains($_rudel_host, ':') ? substr($_rudel_host, strrpos($_rudel_host, ':')) : '';
$_rudel_scheme = (! empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) ? 'https' : 'http';
$_rudel_root_host = defined('DOMAIN_CURRENT_SITE') ? preg_replace('/:\d+$/', '', (string) DOMAIN_CURRENT_SITE) : $_rudel_host_without_port;
if (! defined('DOMAIN_CURRENT_SITE')) { define('DOMAIN_CURRENT_SITE', $_rudel_root_host); }
if (! defined('PATH_CURRENT_SITE')) { define('PATH_CURRENT_SITE', '/'); }
if (! defined('SITE_ID_CURRENT_SITE')) { define('SITE_ID_CURRENT_SITE', 1); }
if (! defined('BLOG_ID_CURRENT_SITE')) { define('BLOG_ID_CURRENT_SITE', 1); }

$_rudel_network_url = $_rudel_scheme . '://' . $_rudel_root_host . $_rudel_port;
$_rudel_environment_url = $_rudel_scheme . '://' . $sandbox_id . '.' . $_rudel_root_host . $_rudel_port;

if (! defined('RUDEL_HOST_URL')) {
    define('RUDEL_HOST_URL', $_rudel_network_url);
}
if (! defined('RUDEL_ENVIRONMENT_URL')) {
    define('RUDEL_ENVIRONMENT_URL', $_rudel_environment_url);
}
if (! defined('WP_SITEURL')) {
    define('WP_SITEURL', $_rudel_environment_url);
}
if (! defined('WP_HOME')) {
    define('WP_HOME', $_rudel_environment_url);
}
if (! defined('RUDEL_ENVIRONMENT_CONTENT_URL')) {
    define('RUDEL_ENVIRONMENT_CONTENT_URL', $_rudel_environment_url . '/wp-content');
}
if (! defined('WP_CONTENT_URL')) {
    define('WP_CONTENT_URL', $_rudel_environment_url . '/wp-content');
}
unset($_rudel_host, $_rudel_host_without_port, $_rudel_port, $_rudel_scheme, $_rudel_network_url, $_rudel_root_host, $_rudel_environment_url);
// Per-environment debug logging
if (! defined('WP_DEBUG')) { define('WP_DEBUG', true); }
if (! defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', true); }
if (! defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }

// Per-environment cache isolation
if (! defined('WP_CACHE_KEY_SALT')) { define('WP_CACHE_KEY_SALT', 'rudel_' . $sandbox_id . '_'); }

// Disable outbound email by default
if (! defined('RUDEL_DISABLE_EMAIL')) { define('RUDEL_DISABLE_EMAIL', true); }

// Per-environment auth salts
define('AUTH_KEY', hash('sha256', $sandbox_id . 'AUTH_KEY'));
define('SECURE_AUTH_KEY', hash('sha256', $sandbox_id . 'SECURE_AUTH_KEY'));
define('LOGGED_IN_KEY', hash('sha256', $sandbox_id . 'LOGGED_IN_KEY'));
define('NONCE_KEY', hash('sha256', $sandbox_id . 'NONCE_KEY'));
define('AUTH_SALT', hash('sha256', $sandbox_id . 'AUTH_SALT'));
define('SECURE_AUTH_SALT', hash('sha256', $sandbox_id . 'SECURE_AUTH_SALT'));
define('LOGGED_IN_SALT', hash('sha256', $sandbox_id . 'LOGGED_IN_SALT'));
define('NONCE_SALT', hash('sha256', $sandbox_id . 'NONCE_SALT'));

// Rudel markers
define('RUDEL_ID', $sandbox_id);
define('RUDEL_PATH', $sandbox_path);
define('RUDEL_ENGINE', 'subsite');
