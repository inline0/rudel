#!/usr/bin/env bash
#
# E2E Test: ConfigWriter install/uninstall cycle
#
# Tests the wp-config.php modification logic in isolation
# using a fake wp-config.php file.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_TMPDIR=$(mktemp -d)
PASSED=0
FAILED=0
TOTAL=0

cleanup() {
    # Restore permissions before cleanup
    find "$TEST_TMPDIR" -type f -exec chmod 644 {} \; 2>/dev/null || true
    rm -rf "$TEST_TMPDIR"
}
trap cleanup EXIT

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

pass() {
    PASSED=$((PASSED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "  ${GREEN}✓${NC} $1"
}

fail() {
    FAILED=$((FAILED + 1))
    TOTAL=$((TOTAL + 1))
    echo -e "  ${RED}✗${NC} $1"
    if [[ -n "${2:-}" ]]; then
        echo "    $2"
    fi
}

run_writer() {
    php -r "
        require_once '$RUDEL_DIR/vendor/autoload.php';
        define('ABSPATH', '$TEST_TMPDIR/wordpress/');
        define('RUDEL_PLUGIN_FILE', '$RUDEL_DIR/rudel.php');
        Rudel\RuntimeProfile::set_current(array(
            'selectors' => array(
                'cookie' => 'rudel_environment',
                'header' => 'X-Rudel-Environment',
                'env' => 'RUDEL_ENVIRONMENT',
                'url_subdomain' => true,
            ),
            'constants' => array(
                'id' => 'RUDEL_ID',
                'path' => 'RUDEL_PATH',
                'wp_config_path' => 'RUDEL_WP_CONFIG_PATH',
                'plugin_dir' => 'RUDEL_BOOTSTRAP_PLUGIN_DIR',
                'env_type' => 'RUDEL_ENV_TYPE',
                'engine' => 'RUDEL_ENGINE',
                'table_prefix' => 'RUDEL_TABLE_PREFIX',
                'host_table_prefix' => 'RUDEL_HOST_TABLE_PREFIX',
                'host_url' => 'RUDEL_HOST_URL',
                'environment_url' => 'RUDEL_ENVIRONMENT_URL',
                'environment_content_url' => 'RUDEL_ENVIRONMENT_CONTENT_URL',
                'theme_slug' => 'RUDEL_THEME_SLUG',
                'template_slug' => 'RUDEL_TEMPLATE_SLUG',
                'theme_root' => 'RUDEL_ENVIRONMENT_THEME_ROOT',
                'theme_root_uri' => 'RUDEL_ENVIRONMENT_THEME_ROOT_URI',
                'record_id' => 'RUDEL_ENV_RECORD_ID',
                'disable_email' => 'RUDEL_DISABLE_EMAIL',
                'user_scope' => 'RUDEL_USER_SCOPE',
                'users_table' => 'RUDEL_USERS_TABLE',
                'usermeta_table' => 'RUDEL_USERMETA_TABLE',
            ),
            'runtime_tables' => array(
                'prefix' => 'rudel_',
                'environments' => 'rudel_environments',
                'worktrees' => 'rudel_worktrees',
            ),
            'naming' => array(
                'environment_table_prefix' => '{{host_prefix}}{{short_hash}}_',
                'isolated_users_table' => '{{host_prefix}}rudel_env_{{blog_id}}_users',
                'isolated_usermeta_table' => '{{host_prefix}}rudel_env_{{blog_id}}_usermeta',
                'cache_key_salt' => 'rudel_{{id}}_',
                'git_branch_prefix' => 'rudel/',
                'managed_table_prefix_patterns' => array(
                    '/^rudel_/',
                    '/^[A-Za-z0-9_]+_[a-f0-9]{7}_$/',
                ),
            ),
            'paths' => array(
                'environments_dir_name' => 'rudel-environments',
                'environments_dir_constant' => 'RUDEL_ENVIRONMENTS_DIR',
                'environment_content_url_path' => 'rudel-environments',
                'bootstrap_config_path' => '$TEST_TMPDIR/rudel-runtime-profile.php',
            ),
            'wp_config_marker' => '// Rudel environment bootstrap',
            'runtime_mu' => array(
                'file' => 'rudel-runtime.php',
                'loaded_constant' => 'RUDEL_RUNTIME_HOOKS_LOADED',
                'function_prefix' => 'rudel_runtime',
                'admin_bar_node_id' => 'rudel-environment',
                'admin_bar_title' => 'Sandbox',
                'email_log_label' => 'Rudel',
            ),
        ));
        $1
    " 2>&1
}

echo -e "${BOLD}Rudel E2E: ConfigWriter${NC}"
echo "==========================================="
echo ""

# Create a fake WordPress root with wp-config.php
WP_DIR="$TEST_TMPDIR/wordpress"
mkdir -p "$WP_DIR"
cat > "$WP_DIR/wp-config.php" << 'EOF'
<?php
define('DB_NAME', 'wordpress');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');

$table_prefix = 'wp_';

define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
EOF
ORIGINAL_CONTENT=$(cat "$WP_DIR/wp-config.php")

# --------------------------------------------------------------------------
# Test: isInstalled returns false initially
# --------------------------------------------------------------------------
echo -e "${BOLD}Initial state${NC}"

RESULT=$(run_writer '
    $w = new Rudel\ConfigWriter();
    echo $w->is_installed() ? "true" : "false";
')
if [[ "$RESULT" == "false" ]]; then
    pass "is_installed() returns false initially"
else
    fail "is_installed() should return false" "$RESULT"
fi

# --------------------------------------------------------------------------
# Test: install injects the line
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Install${NC}"

run_writer '
    $w = new Rudel\ConfigWriter();
    $w->install();
' > /dev/null

if grep -q "// Rudel environment bootstrap" "$WP_DIR/wp-config.php"; then
    pass "install() injects marker line"
else
    fail "install() didn't inject marker" "$(head -5 "$WP_DIR/wp-config.php")"
fi

if grep -q "require_once" "$WP_DIR/wp-config.php"; then
    pass "install() injects require_once"
else
    fail "install() didn't inject require_once" ""
fi

# Require line should be injected immediately before wp-settings.php
REQUIRE_LINE=$(grep -n "require_once.*// Rudel environment bootstrap" "$WP_DIR/wp-config.php" | head -1 | cut -d: -f1)
SETTINGS_LINE=$(grep -n "wp-settings.php" "$WP_DIR/wp-config.php" | grep -v "Rudel" | head -1 | cut -d: -f1)
if [[ -n "$REQUIRE_LINE" && -n "$SETTINGS_LINE" && "$REQUIRE_LINE" -eq $((SETTINGS_LINE - 1)) ]]; then
    pass "Bootstrap require is immediately before wp-settings.php (line $REQUIRE_LINE)"
else
    fail "Bootstrap require not before wp-settings.php" "Require: $REQUIRE_LINE, Settings: $SETTINGS_LINE"
fi

# No separate fixup line should remain once bootstrap itself runs at the wp-settings boundary.
FIXUP_LINE=$(grep -n "table_prefix.*// Rudel environment bootstrap" "$WP_DIR/wp-config.php" | head -1 | cut -d: -f1 || true)
if [[ -z "$FIXUP_LINE" ]]; then
    pass "No standalone table prefix fixup line remains"
else
    fail "Unexpected standalone table prefix fixup line" "Fixup: $FIXUP_LINE"
fi

# isInstalled should now return true
RESULT=$(run_writer '
    $w = new Rudel\ConfigWriter();
    echo $w->is_installed() ? "true" : "false";
')
if [[ "$RESULT" == "true" ]]; then
    pass "is_installed() returns true after install"
else
    fail "is_installed() should return true" "$RESULT"
fi

# --------------------------------------------------------------------------
# Test: backup was created
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Backup${NC}"

BACKUP_COUNT=$(ls "$WP_DIR"/wp-config.php.runtime-backup-* 2>/dev/null | wc -l | tr -d ' ')
if [[ "$BACKUP_COUNT" -ge 1 ]]; then
    pass "Backup file created ($BACKUP_COUNT backups)"
else
    fail "No backup file found" ""
fi

# Backup content should match original
BACKUP_FILE=$(ls "$WP_DIR"/wp-config.php.runtime-backup-* | head -1)
BACKUP_CONTENT=$(cat "$BACKUP_FILE")
if [[ "$BACKUP_CONTENT" == "$ORIGINAL_CONTENT" ]]; then
    pass "Backup matches original content"
else
    fail "Backup content differs from original" ""
fi

# --------------------------------------------------------------------------
# Test: idempotent install
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Idempotent install${NC}"

BEFORE=$(cat "$WP_DIR/wp-config.php")
run_writer '
    $w = new Rudel\ConfigWriter();
    $w->install();
' > /dev/null
AFTER=$(cat "$WP_DIR/wp-config.php")

if [[ "$BEFORE" == "$AFTER" ]]; then
    pass "Second install() is a no-op"
else
    fail "Second install() modified the file" ""
fi

MARKER_COUNT=$(grep -c "// Rudel environment bootstrap" "$WP_DIR/wp-config.php")
if [[ "$MARKER_COUNT" -eq 1 ]]; then
    pass "Exactly one marker line after double install"
else
    fail "Wrong marker line count" "Expected 1, got: $MARKER_COUNT"
fi

# --------------------------------------------------------------------------
# Test: uninstall removes the line
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Uninstall${NC}"

run_writer '
    $w = new Rudel\ConfigWriter();
    $w->uninstall();
' > /dev/null

if ! grep -q "// Rudel environment bootstrap" "$WP_DIR/wp-config.php"; then
    pass "uninstall() removes marker line"
else
    fail "uninstall() didn't remove marker" ""
fi

if ! grep -q "require_once.*bootstrap" "$WP_DIR/wp-config.php"; then
    pass "uninstall() removes require_once line"
else
    fail "uninstall() didn't remove require_once" ""
fi

# Original content preserved
if grep -q "define('DB_NAME', 'wordpress')" "$WP_DIR/wp-config.php"; then
    pass "Original DB_NAME preserved"
else
    fail "Original content lost" ""
fi

if grep -q "define('DB_USER', 'root')" "$WP_DIR/wp-config.php"; then
    pass "Original DB_USER preserved"
else
    fail "Original content lost" ""
fi

RESULT=$(run_writer '
    $w = new Rudel\ConfigWriter();
    echo $w->is_installed() ? "true" : "false";
')
if [[ "$RESULT" == "false" ]]; then
    pass "is_installed() returns false after uninstall"
else
    fail "is_installed() should return false" "$RESULT"
fi

# --------------------------------------------------------------------------
# Test: uninstall is no-op when not installed
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Uninstall no-op${NC}"

BEFORE=$(cat "$WP_DIR/wp-config.php")
run_writer '
    $w = new Rudel\ConfigWriter();
    $w->uninstall();
' > /dev/null
AFTER=$(cat "$WP_DIR/wp-config.php")

if [[ "$BEFORE" == "$AFTER" ]]; then
    pass "uninstall() is no-op when not installed"
else
    fail "uninstall() modified file when not installed" ""
fi

# --------------------------------------------------------------------------
# Test: full cycle preserves file integrity
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Full cycle integrity${NC}"

BEFORE=$(cat "$WP_DIR/wp-config.php")
run_writer '
    $w = new Rudel\ConfigWriter();
    $w->install();
    $w->uninstall();
' > /dev/null
AFTER=$(cat "$WP_DIR/wp-config.php")

if [[ "$BEFORE" == "$AFTER" ]]; then
    pass "Install + uninstall cycle preserves file"
else
    fail "File changed after full cycle" ""
fi

# --------------------------------------------------------------------------
# Results
# --------------------------------------------------------------------------
echo ""
echo "==========================================="
if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All $TOTAL tests passed!${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}$FAILED of $TOTAL tests failed${NC}"
    exit 1
fi
