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
    echo $w->isInstalled() ? "true" : "false";
')
if [[ "$RESULT" == "false" ]]; then
    pass "isInstalled() returns false initially"
else
    fail "isInstalled() should return false" "$RESULT"
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

if grep -q "// Rudel sandbox bootstrap" "$WP_DIR/wp-config.php"; then
    pass "install() injects marker line"
else
    fail "install() didn't inject marker" "$(head -5 "$WP_DIR/wp-config.php")"
fi

if grep -q "require_once" "$WP_DIR/wp-config.php"; then
    pass "install() injects require_once"
else
    fail "install() didn't inject require_once" ""
fi

# Marker should be near the top (line 2)
LINE_NUM=$(grep -n "// Rudel sandbox bootstrap" "$WP_DIR/wp-config.php" | head -1 | cut -d: -f1)
if [[ "$LINE_NUM" -le 3 ]]; then
    pass "Bootstrap line is near the top (line $LINE_NUM)"
else
    fail "Bootstrap line too far down" "Line: $LINE_NUM"
fi

# isInstalled should now return true
RESULT=$(run_writer '
    $w = new Rudel\ConfigWriter();
    echo $w->isInstalled() ? "true" : "false";
')
if [[ "$RESULT" == "true" ]]; then
    pass "isInstalled() returns true after install"
else
    fail "isInstalled() should return true" "$RESULT"
fi

# --------------------------------------------------------------------------
# Test: backup was created
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Backup${NC}"

BACKUP_COUNT=$(ls "$WP_DIR"/wp-config.php.rudel-backup-* 2>/dev/null | wc -l | tr -d ' ')
if [[ "$BACKUP_COUNT" -ge 1 ]]; then
    pass "Backup file created ($BACKUP_COUNT backups)"
else
    fail "No backup file found" ""
fi

# Backup content should match original
BACKUP_FILE=$(ls "$WP_DIR"/wp-config.php.rudel-backup-* | head -1)
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

MARKER_COUNT=$(grep -c "// Rudel sandbox bootstrap" "$WP_DIR/wp-config.php")
if [[ "$MARKER_COUNT" -eq 1 ]]; then
    pass "Only one marker line after double install"
else
    fail "Multiple marker lines" "Count: $MARKER_COUNT"
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

if ! grep -q "// Rudel sandbox bootstrap" "$WP_DIR/wp-config.php"; then
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
    echo $w->isInstalled() ? "true" : "false";
')
if [[ "$RESULT" == "false" ]]; then
    pass "isInstalled() returns false after uninstall"
else
    fail "isInstalled() should return false" "$RESULT"
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
