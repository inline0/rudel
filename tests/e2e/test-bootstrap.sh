#!/usr/bin/env bash
#
# E2E Test: Bootstrap resolver behavior
#
# Tests that bootstrap.php correctly detects sandbox context from
# various request types and sets the right constants.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BOOTSTRAP="$RUDEL_DIR/bootstrap.php"
TEST_TMPDIR=$(mktemp -d)
SANDBOXES_DIR="$TEST_TMPDIR/rudel-sandboxes"
PASSED=0
FAILED=0
TOTAL=0

cleanup() {
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

# Create sandboxes directory with a test sandbox
mkdir -p "$SANDBOXES_DIR/test-sandbox-001"
echo '{"id":"test-sandbox-001","name":"test","engine":"sqlite"}' > "$SANDBOXES_DIR/test-sandbox-001/.rudel.json"

# Helper: run bootstrap.php in a child process and get constants as JSON
run_bootstrap() {
    local server_vars="$1"
    local cookie_vars="${2:-}"
    local extra_defines="${3:-}"

    cat > "$TEST_TMPDIR/run.php" << 'INNEREOF'
<?php
// Server vars (passed via env)
$server_json = getenv('TEST_SERVER_VARS');
if ($server_json) {
    foreach (json_decode($server_json, true) as $k => $v) {
        $_SERVER[$k] = $v;
    }
}

// Cookie vars
$cookie_json = getenv('TEST_COOKIE_VARS');
if ($cookie_json) {
    $_COOKIE = json_decode($cookie_json, true);
}

// Extra defines
$defines_json = getenv('TEST_EXTRA_DEFINES');
if ($defines_json) {
    foreach (json_decode($defines_json, true) as $k => $v) {
        define($k, $v);
    }
}

define('WP_CONTENT_DIR', getenv('TEST_TMPDIR'));

require getenv('TEST_BOOTSTRAP');

echo json_encode([
    'sandbox_id' => defined('RUDEL_SANDBOX_ID') ? RUDEL_SANDBOX_ID : null,
    'sandbox_path' => defined('RUDEL_SANDBOX_PATH') ? RUDEL_SANDBOX_PATH : null,
    'db_dir' => defined('DB_DIR') ? DB_DIR : null,
    'db_file' => defined('DB_FILE') ? DB_FILE : null,
    'database_type' => defined('DATABASE_TYPE') ? DATABASE_TYPE : null,
    'wp_content_dir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
    'wp_plugin_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
    'wp_temp_dir' => defined('WP_TEMP_DIR') ? WP_TEMP_DIR : null,
    'table_prefix' => $GLOBALS['table_prefix'] ?? null,
    'auth_key' => defined('AUTH_KEY') ? AUTH_KEY : null,
    'open_basedir' => ini_get('open_basedir'),
    'cookie_sandbox' => $_COOKIE['rudel_sandbox'] ?? null,
]);
INNEREOF

    TEST_SERVER_VARS="$server_vars" \
    TEST_COOKIE_VARS="$cookie_vars" \
    TEST_EXTRA_DEFINES="$extra_defines" \
    TEST_TMPDIR="$TEST_TMPDIR" \
    TEST_BOOTSTRAP="$BOOTSTRAP" \
    php "$TEST_TMPDIR/run.php" 2>/dev/null
}

get_json_field() {
    echo "$1" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["'"$2"'"] ?? "NULL";'
}

echo -e "${BOLD}Rudel E2E: Bootstrap Resolver${NC}"
echo "==========================================="
echo ""

# --------------------------------------------------------------------------
# No sandbox context
# --------------------------------------------------------------------------
echo -e "${BOLD}No sandbox context${NC}"

RESULT=$(run_bootstrap '{"REQUEST_URI":"/wp-admin/","HTTP_HOST":"example.com"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "NULL" ]]; then
    pass "No sandbox detected for normal WP request"
else
    fail "Sandbox incorrectly detected" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Header detection
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Header detection${NC}"

RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"test-sandbox-001","HTTP_HOST":"example.com"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "test-sandbox-001" ]]; then
    pass "Detects sandbox from X-Rudel-Sandbox header"
else
    fail "Header detection failed" "Got: $SID"
fi

DB_FILE=$(get_json_field "$RESULT" "db_file")
if [[ "$DB_FILE" == "wordpress.db" ]]; then
    pass "DB_FILE set to wordpress.db"
else
    fail "DB_FILE wrong" "Got: $DB_FILE"
fi

DB_TYPE=$(get_json_field "$RESULT" "database_type")
if [[ "$DB_TYPE" == "sqlite" ]]; then
    pass "DATABASE_TYPE set to sqlite"
else
    fail "DATABASE_TYPE wrong" "Got: $DB_TYPE"
fi

# --------------------------------------------------------------------------
# Cookie detection
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Cookie detection${NC}"

RESULT=$(run_bootstrap '{"HTTP_HOST":"example.com"}' '{"rudel_sandbox":"test-sandbox-001"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "test-sandbox-001" ]]; then
    pass "Detects sandbox from rudel_sandbox cookie"
else
    fail "Cookie detection failed" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Path prefix detection
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Path prefix detection${NC}"

RESULT=$(run_bootstrap '{"REQUEST_URI":"/__rudel/test-sandbox-001/wp-admin/","HTTP_HOST":"example.com"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "test-sandbox-001" ]]; then
    pass "Detects sandbox from /__rudel/ path prefix"
else
    fail "Path prefix detection failed" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Subdomain detection
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Subdomain detection${NC}"

RESULT=$(run_bootstrap '{"REQUEST_URI":"/","HTTP_HOST":"test-sandbox-001.example.com"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "test-sandbox-001" ]]; then
    pass "Detects sandbox from subdomain"
else
    fail "Subdomain detection failed" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Priority: header > cookie > path > subdomain
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Priority order${NC}"

# Header wins over cookie
mkdir -p "$SANDBOXES_DIR/header-wins"
echo '{"id":"header-wins","engine":"sqlite"}' > "$SANDBOXES_DIR/header-wins/.rudel.json"
mkdir -p "$SANDBOXES_DIR/cookie-loses"
echo '{"id":"cookie-loses","engine":"sqlite"}' > "$SANDBOXES_DIR/cookie-loses/.rudel.json"

RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"header-wins","HTTP_HOST":"example.com"}' '{"rudel_sandbox":"cookie-loses"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "header-wins" ]]; then
    pass "Header takes priority over cookie"
else
    fail "Priority wrong: header vs cookie" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Constants set correctly
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Constants verification${NC}"

RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"test-sandbox-001","HTTP_HOST":"localhost"}')

TPREFIX=$(get_json_field "$RESULT" "table_prefix")
EXPECTED_PREFIX="rudel_$(php -r "echo substr(md5('test-sandbox-001'), 0, 6);")_"
if [[ "$TPREFIX" == "$EXPECTED_PREFIX" ]]; then
    pass "Table prefix matches expected hash"
else
    fail "Table prefix mismatch" "Expected: $EXPECTED_PREFIX, Got: $TPREFIX"
fi

AUTH=$(get_json_field "$RESULT" "auth_key")
if [[ -n "$AUTH" && "$AUTH" != "NULL" ]]; then
    pass "AUTH_KEY is set (non-null)"
else
    fail "AUTH_KEY not set" ""
fi

PLUGIN_DIR=$(get_json_field "$RESULT" "wp_plugin_dir")
if echo "$PLUGIN_DIR" | grep -q "test-sandbox-001/wp-content/plugins"; then
    pass "WP_PLUGIN_DIR points to sandbox"
else
    fail "WP_PLUGIN_DIR wrong" "$PLUGIN_DIR"
fi

TEMP_DIR=$(get_json_field "$RESULT" "wp_temp_dir")
if echo "$TEMP_DIR" | grep -q "test-sandbox-001/tmp"; then
    pass "WP_TEMP_DIR points to sandbox"
else
    fail "WP_TEMP_DIR wrong" "$TEMP_DIR"
fi

# --------------------------------------------------------------------------
# open_basedir
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}open_basedir${NC}"

OBD=$(get_json_field "$RESULT" "open_basedir")
if echo "$OBD" | grep -q "test-sandbox-001"; then
    pass "open_basedir includes sandbox path"
else
    fail "open_basedir missing sandbox" "$OBD"
fi

# --------------------------------------------------------------------------
# Security: invalid IDs
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Security: invalid IDs${NC}"

for BAD_ID in "../../../etc" ".hidden" "abc/def" "" "abc def" 'abc;rm' "test\`id\`"; do
    RESULT=$(run_bootstrap "{\"HTTP_X_RUDEL_SANDBOX\":\"$BAD_ID\",\"HTTP_HOST\":\"localhost\"}")
    SID=$(get_json_field "$RESULT" "sandbox_id")
    if [[ "$SID" == "NULL" ]]; then
        pass "Rejects invalid ID: $BAD_ID"
    else
        fail "Accepted invalid ID: $BAD_ID" "Got: $SID"
    fi
done

# Nonexistent sandbox
RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"does-not-exist","HTTP_HOST":"localhost"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "NULL" ]]; then
    pass "Rejects nonexistent sandbox"
else
    fail "Accepted nonexistent sandbox" "Got: $SID"
fi

# --------------------------------------------------------------------------
# Already resolved guard
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Already resolved guard${NC}"

RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"test-sandbox-001","HTTP_HOST":"localhost"}' '' '{"RUDEL_SANDBOX_ID":"already-set"}')
DB_DIR=$(get_json_field "$RESULT" "db_dir")
if [[ "$DB_DIR" == "NULL" ]]; then
    pass "Skips when RUDEL_SANDBOX_ID already defined"
else
    fail "Didn't skip when already resolved" "DB_DIR: $DB_DIR"
fi

# --------------------------------------------------------------------------
# MySQL engine: no SQLite constants
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}MySQL engine${NC}"

mkdir -p "$SANDBOXES_DIR/mysql-sandbox"
echo '{"id":"mysql-sandbox","name":"mysql-test","engine":"mysql"}' > "$SANDBOXES_DIR/mysql-sandbox/.rudel.json"

RESULT=$(run_bootstrap '{"HTTP_X_RUDEL_SANDBOX":"mysql-sandbox","HTTP_HOST":"localhost"}')
SID=$(get_json_field "$RESULT" "sandbox_id")
if [[ "$SID" == "mysql-sandbox" ]]; then
    pass "MySQL sandbox detected"
else
    fail "MySQL sandbox not detected" "Got: $SID"
fi

DB_FILE=$(get_json_field "$RESULT" "db_file")
if [[ "$DB_FILE" == "NULL" ]]; then
    pass "DB_FILE not set for MySQL engine"
else
    fail "DB_FILE should be null for MySQL" "Got: $DB_FILE"
fi

DB_TYPE=$(get_json_field "$RESULT" "database_type")
if [[ "$DB_TYPE" == "NULL" ]]; then
    pass "DATABASE_TYPE not set for MySQL engine"
else
    fail "DATABASE_TYPE should be null for MySQL" "Got: $DB_TYPE"
fi

TABLE_PREFIX=$(get_json_field "$RESULT" "table_prefix")
if [[ "$TABLE_PREFIX" != "NULL" ]]; then
    pass "Table prefix still set for MySQL engine"
else
    fail "Table prefix missing for MySQL" "Got: $TABLE_PREFIX"
fi

WP_CONTENT=$(get_json_field "$RESULT" "wp_content_dir")
if [[ "$WP_CONTENT" != "NULL" ]]; then
    pass "WP_CONTENT_DIR still set for MySQL engine"
else
    fail "WP_CONTENT_DIR missing for MySQL" "Got: $WP_CONTENT"
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
