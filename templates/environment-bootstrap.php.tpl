<?php
/**
 * Per-sandbox bootstrap -- loaded by wp-cli.yml require directive.
 * Sets all WordPress constants to isolate this sandbox.
 */

$sandbox_id = '{{sandbox_id}}';
$sandbox_path = '{{sandbox_path}}';

// Already resolved (global bootstrap ran first)
if (defined('RUDEL_ID')) {
    return;
}

{{sqlite_block}}
// WP content directories
define('WP_CONTENT_DIR', $sandbox_path . '/wp-content');
define('WP_PLUGIN_DIR', $sandbox_path . '/wp-content/plugins');
define('WPMU_PLUGIN_DIR', $sandbox_path . '/wp-content/mu-plugins');
define('WP_TEMP_DIR', $sandbox_path . '/tmp');
define('UPLOADS', 'wp-content/uploads');

// Sandbox site URL (CLI context: build from SERVER_NAME or default to localhost)
$_rudel_host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$_rudel_sandbox_url = 'http://' . $_rudel_host . '/{{path_prefix}}/' . $sandbox_id;
if (! defined('WP_SITEURL')) {
    define('WP_SITEURL', $_rudel_sandbox_url);
}
if (! defined('WP_HOME')) {
    define('WP_HOME', $_rudel_sandbox_url);
}
if (! defined('WP_CONTENT_URL')) {
    define('WP_CONTENT_URL', $_rudel_sandbox_url . '/wp-content');
}
unset($_rudel_host, $_rudel_sandbox_url);
{{multisite_block}}
// Per-sandbox debug logging
if (! defined('WP_DEBUG')) { define('WP_DEBUG', true); }
if (! defined('WP_DEBUG_LOG')) { define('WP_DEBUG_LOG', true); }
if (! defined('WP_DEBUG_DISPLAY')) { define('WP_DEBUG_DISPLAY', false); }

// Per-sandbox cache isolation
if (! defined('WP_CACHE_KEY_SALT')) { define('WP_CACHE_KEY_SALT', 'rudel_' . $sandbox_id . '_'); }

// Disable outbound email by default
if (! defined('RUDEL_DISABLE_EMAIL')) { define('RUDEL_DISABLE_EMAIL', true); }

// Per-sandbox table prefix
$_rudel_prefix = 'rudel_' . substr(md5($sandbox_id), 0, 6) . '_';
$GLOBALS['table_prefix'] = $_rudel_prefix;
if (! defined('RUDEL_TABLE_PREFIX')) {
    define('RUDEL_TABLE_PREFIX', $_rudel_prefix);
}

// Per-sandbox auth salts
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

// Set $table_prefix in caller scope for wp-config.php compatibility.
$table_prefix = $_rudel_prefix;
unset($_rudel_prefix);
