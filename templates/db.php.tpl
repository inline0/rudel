<?php
/**
 * SQLite database drop-in for Rudel sandbox.
 * Points to the shared SQLite integration bundled with Rudel.
 */

define('SQLITE_DB_DROPIN_VERSION', '1.0.0');

$sqlite_plugin_path = '{{sqlite_integration_path}}';

if (! file_exists($sqlite_plugin_path . '/wp-includes/sqlite/db.php')) {
    error_log('Rudel: SQLite integration not found at: ' . $sqlite_plugin_path);
    return;
}

if (! defined('DATABASE_TYPE')) {
    define('DATABASE_TYPE', 'sqlite');
}

if (! defined('DB_ENGINE')) {
    define('DB_ENGINE', 'sqlite');
}

require_once $sqlite_plugin_path . '/wp-includes/sqlite/db.php';
