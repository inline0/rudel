<?php
/**
 * Per-environment bootstrap loaded by wp-cli when working inside one runtime site.
 * This bootstrap mirrors the canonical environment URL generated for this site.
 */

$sandbox_id = '{{sandbox_id}}';
$sandbox_path = '{{sandbox_path}}';

// Already resolved (global bootstrap ran first)
if (defined('{{constant_id}}')) {
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
$_rudel_environment_url = '{{environment_url}}';

if (! defined('{{constant_host_url}}')) {
    define('{{constant_host_url}}', $_rudel_network_url);
}
if (! defined('{{constant_environment_url}}')) {
    define('{{constant_environment_url}}', $_rudel_environment_url);
}
if (! defined('{{constant_environment_content_url}}')) {
    define('{{constant_environment_content_url}}', $_rudel_environment_url . '/wp-content');
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
if (! defined('WP_CACHE_KEY_SALT')) { define('WP_CACHE_KEY_SALT', '{{cache_key_salt}}'); }

// Disable outbound email by default
if (! defined('{{constant_disable_email}}')) { define('{{constant_disable_email}}', true); }

// Per-environment auth salts
define('AUTH_KEY', hash('sha256', $sandbox_id . 'AUTH_KEY'));
define('SECURE_AUTH_KEY', hash('sha256', $sandbox_id . 'SECURE_AUTH_KEY'));
define('LOGGED_IN_KEY', hash('sha256', $sandbox_id . 'LOGGED_IN_KEY'));
define('NONCE_KEY', hash('sha256', $sandbox_id . 'NONCE_KEY'));
define('AUTH_SALT', hash('sha256', $sandbox_id . 'AUTH_SALT'));
define('SECURE_AUTH_SALT', hash('sha256', $sandbox_id . 'SECURE_AUTH_SALT'));
define('LOGGED_IN_SALT', hash('sha256', $sandbox_id . 'LOGGED_IN_SALT'));
define('NONCE_SALT', hash('sha256', $sandbox_id . 'NONCE_SALT'));

// Runtime markers
define('{{constant_id}}', $sandbox_id);
define('{{constant_path}}', $sandbox_path);
define('{{constant_engine}}', 'subsite');
define('{{constant_table_prefix}}', '{{table_prefix}}');
define('{{constant_user_scope}}', 'isolated');
define('{{constant_users_table}}', '{{users_table}}');
define('{{constant_usermeta_table}}', '{{usermeta_table}}');
