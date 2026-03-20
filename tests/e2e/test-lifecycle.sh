#!/usr/bin/env bash
#
# E2E Test: Full sandbox lifecycle
#
# Requires: PHP 8.0+, SQLite3 extension, writable /tmp
# Does NOT require WordPress or wp-cli -- tests the PHP layer directly.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_TMPDIR=$(mktemp -d)
PASSED=0
FAILED=0
TOTAL=0

cleanup() {
    rm -rf "$TEST_TMPDIR"
}
trap cleanup EXIT

# Colors
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
    echo "    $2"
}

assert_contains() {
    if echo "$1" | grep -q "$2"; then
        return 0
    else
        return 1
    fi
}

assert_file_exists() {
    if [[ -f "$1" ]]; then
        return 0
    else
        return 1
    fi
}

assert_dir_exists() {
    if [[ -d "$1" ]]; then
        return 0
    else
        return 1
    fi
}

# Run a PHP snippet that exercises SandboxManager
run_php() {
    php -r "
        require_once '$RUDEL_DIR/vendor/autoload.php';
        define('RUDEL_PLUGIN_DIR', '$RUDEL_DIR/');
        define('RUDEL_SANDBOXES_DIR', '$TEST_TMPDIR/sandboxes');
        define('RUDEL_PATH_PREFIX', '__rudel');
        define('WP_HOME', 'http://localhost:8888');
        $1
    " 2>&1
}

echo -e "${BOLD}Rudel E2E: Sandbox Lifecycle${NC}"
echo "==========================================="
echo "Rudel dir: $RUDEL_DIR"
echo "Temp dir:  $TEST_TMPDIR"
echo ""

# --------------------------------------------------------------------------
# Test 1: Create a sandbox
# --------------------------------------------------------------------------
echo -e "${BOLD}Create sandbox${NC}"

OUTPUT=$(run_php '
    $manager = new Rudel\SandboxManager();
    $sandbox = $manager->create("e2e-test", ["engine" => "sqlite"]);
    echo json_encode($sandbox->to_array());
')

SANDBOX_ID=$(echo "$OUTPUT" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["id"];')
SANDBOX_PATH="$TEST_TMPDIR/sandboxes/$SANDBOX_ID"

if [[ -n "$SANDBOX_ID" ]]; then
    pass "Sandbox created with ID: $SANDBOX_ID"
else
    fail "Sandbox creation failed" "$OUTPUT"
fi

# Verify directory structure
if assert_dir_exists "$SANDBOX_PATH"; then
    pass "Sandbox directory exists"
else
    fail "Sandbox directory missing" "$SANDBOX_PATH"
fi

for dir in wp-content wp-content/themes wp-content/plugins wp-content/uploads wp-content/mu-plugins tmp; do
    if assert_dir_exists "$SANDBOX_PATH/$dir"; then
        pass "Directory exists: $dir"
    else
        fail "Directory missing: $dir" "$SANDBOX_PATH/$dir"
    fi
done

# Verify files
echo ""
echo -e "${BOLD}Verify generated files${NC}"

for file in .rudel.json wordpress.db wp-cli.yml bootstrap.php CLAUDE.md wp-content/db.php; do
    if assert_file_exists "$SANDBOX_PATH/$file"; then
        pass "File exists: $file"
    else
        fail "File missing: $file" "$SANDBOX_PATH/$file"
    fi
done

# Verify file contents
echo ""
echo -e "${BOLD}Verify file contents${NC}"

# .rudel.json
META=$(cat "$SANDBOX_PATH/.rudel.json")
if assert_contains "$META" '"id"'; then
    pass ".rudel.json has id field"
else
    fail ".rudel.json missing id" "$META"
fi
if assert_contains "$META" '"name": "e2e-test"'; then
    pass ".rudel.json has correct name"
else
    fail ".rudel.json wrong name" "$META"
fi

# wp-cli.yml
YML=$(cat "$SANDBOX_PATH/wp-cli.yml")
if assert_contains "$YML" "path:"; then
    pass "wp-cli.yml has path directive"
else
    fail "wp-cli.yml missing path" "$YML"
fi
if assert_contains "$YML" "require:"; then
    pass "wp-cli.yml has require directive"
else
    fail "wp-cli.yml missing require" "$YML"
fi
if assert_contains "$YML" "bootstrap.php"; then
    pass "wp-cli.yml references bootstrap.php"
else
    fail "wp-cli.yml doesn't reference bootstrap.php" "$YML"
fi

# bootstrap.php
BOOTSTRAP=$(cat "$SANDBOX_PATH/bootstrap.php")
if assert_contains "$BOOTSTRAP" "$SANDBOX_ID"; then
    pass "bootstrap.php contains sandbox ID"
else
    fail "bootstrap.php missing sandbox ID" ""
fi
if assert_contains "$BOOTSTRAP" "RUDEL_SANDBOX_ID"; then
    pass "bootstrap.php defines RUDEL_SANDBOX_ID"
else
    fail "bootstrap.php missing RUDEL_SANDBOX_ID" ""
fi

# CLAUDE.md
CLAUDE=$(cat "$SANDBOX_PATH/CLAUDE.md")
if assert_contains "$CLAUDE" "Security rules"; then
    pass "CLAUDE.md contains security rules"
else
    fail "CLAUDE.md missing security rules" ""
fi
if assert_contains "$CLAUDE" "$SANDBOX_ID"; then
    pass "CLAUDE.md contains sandbox ID"
else
    fail "CLAUDE.md missing sandbox ID" ""
fi

# No raw template placeholders
for file in bootstrap.php wp-cli.yml wp-content/db.php CLAUDE.md; do
    if grep -q '{{' "$SANDBOX_PATH/$file" 2>/dev/null; then
        fail "Raw placeholder in $file" "Found {{ in $file"
    else
        pass "No raw placeholders in $file"
    fi
done

# --------------------------------------------------------------------------
# Test 2: File permissions
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Verify file permissions${NC}"

for file in bootstrap.php wp-cli.yml CLAUDE.md; do
    PERMS=$(stat -f "%Lp" "$SANDBOX_PATH/$file" 2>/dev/null || stat -c "%a" "$SANDBOX_PATH/$file" 2>/dev/null)
    if [[ "$PERMS" == "444" ]]; then
        pass "$file is read-only (444)"
    else
        fail "$file has wrong permissions" "Expected 444, got $PERMS"
    fi
done

DB_PERMS=$(stat -f "%Lp" "$SANDBOX_PATH/wordpress.db" 2>/dev/null || stat -c "%a" "$SANDBOX_PATH/wordpress.db" 2>/dev/null)
if [[ "$DB_PERMS" == "664" ]]; then
    pass "wordpress.db is 664"
else
    fail "wordpress.db has wrong permissions" "Expected 664, got $DB_PERMS"
fi

# --------------------------------------------------------------------------
# Test 3: SQLite database integrity
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Verify SQLite database${NC}"

TABLE_COUNT=$(sqlite3 "$SANDBOX_PATH/wordpress.db" "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';" 2>&1)
if [[ "$TABLE_COUNT" -eq 12 ]]; then
    pass "Database has 12 WordPress tables"
else
    fail "Wrong table count" "Expected 12, got $TABLE_COUNT"
fi

# Check for admin user
PREFIX="wp_$(php -r "echo substr(md5('$SANDBOX_ID'), 0, 6);")_"
ADMIN_USER=$(sqlite3 "$SANDBOX_PATH/wordpress.db" "SELECT user_login FROM ${PREFIX}users WHERE ID=1;" 2>&1)
if [[ "$ADMIN_USER" == "admin" ]]; then
    pass "Admin user exists"
else
    fail "Admin user missing" "Got: $ADMIN_USER"
fi

# Check siteurl contains sandbox ID
SITEURL=$(sqlite3 "$SANDBOX_PATH/wordpress.db" "SELECT option_value FROM ${PREFIX}options WHERE option_name='siteurl';" 2>&1)
if assert_contains "$SITEURL" "$SANDBOX_ID"; then
    pass "siteurl contains sandbox ID"
else
    fail "siteurl missing sandbox ID" "$SITEURL"
fi

# Check Hello World post
POST_TITLE=$(sqlite3 "$SANDBOX_PATH/wordpress.db" "SELECT post_title FROM ${PREFIX}posts WHERE ID=1;" 2>&1)
if [[ "$POST_TITLE" == "Hello world!" ]]; then
    pass "Hello World post exists"
else
    fail "Hello World post missing" "Got: $POST_TITLE"
fi

# --------------------------------------------------------------------------
# Test 4: List sandboxes
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}List sandboxes${NC}"

LIST_COUNT=$(run_php '
    $manager = new Rudel\SandboxManager();
    echo count($manager->list());
')
if [[ "$LIST_COUNT" == "1" ]]; then
    pass "List returns 1 sandbox"
else
    fail "Wrong list count" "Expected 1, got $LIST_COUNT"
fi

# --------------------------------------------------------------------------
# Test 5: Get sandbox
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Get sandbox${NC}"

GOT_NAME=$(run_php "
    \$manager = new Rudel\SandboxManager();
    \$s = \$manager->get('$SANDBOX_ID');
    echo \$s ? \$s->name : 'null';
")
if [[ "$GOT_NAME" == "e2e-test" ]]; then
    pass "Get returns correct sandbox"
else
    fail "Get returned wrong sandbox" "Got: $GOT_NAME"
fi

# Get with invalid ID
GOT_INVALID=$(run_php '
    $manager = new Rudel\SandboxManager();
    $s = $manager->get("../../../etc");
    echo $s === null ? "null" : "found";
')
if [[ "$GOT_INVALID" == "null" ]]; then
    pass "Get rejects path traversal"
else
    fail "Get allowed path traversal" "$GOT_INVALID"
fi

# --------------------------------------------------------------------------
# Test 6: Create second sandbox (isolation)
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Sandbox isolation${NC}"

SECOND_OUTPUT=$(run_php '
    $manager = new Rudel\SandboxManager();
    $sandbox = $manager->create("e2e-second", ["engine" => "sqlite"]);
    echo json_encode($sandbox->to_array());
')
SECOND_ID=$(echo "$SECOND_OUTPUT" | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["id"];')

if [[ "$SECOND_ID" != "$SANDBOX_ID" ]]; then
    pass "Second sandbox has different ID"
else
    fail "Second sandbox has same ID as first" "$SECOND_ID"
fi

LIST_COUNT2=$(run_php '
    $manager = new Rudel\SandboxManager();
    echo count($manager->list());
')
if [[ "$LIST_COUNT2" == "2" ]]; then
    pass "List now returns 2 sandboxes"
else
    fail "Wrong list count after second create" "$LIST_COUNT2"
fi

# --------------------------------------------------------------------------
# Test 7: Destroy sandbox
# --------------------------------------------------------------------------
echo ""
echo -e "${BOLD}Destroy sandbox${NC}"

DESTROY_RESULT=$(run_php "
    \$manager = new Rudel\SandboxManager();
    echo \$manager->destroy('$SANDBOX_ID') ? 'true' : 'false';
")
if [[ "$DESTROY_RESULT" == "true" ]]; then
    pass "Destroy returned true"
else
    fail "Destroy returned false" "$DESTROY_RESULT"
fi

if [[ ! -d "$SANDBOX_PATH" ]]; then
    pass "Sandbox directory removed"
else
    fail "Sandbox directory still exists" "$SANDBOX_PATH"
fi

# Verify the second sandbox is unaffected
SECOND_EXISTS=$(run_php "
    \$manager = new Rudel\SandboxManager();
    \$s = \$manager->get('$SECOND_ID');
    echo \$s !== null ? 'exists' : 'gone';
")
if [[ "$SECOND_EXISTS" == "exists" ]]; then
    pass "Second sandbox unaffected by first's destruction"
else
    fail "Second sandbox was affected" "$SECOND_EXISTS"
fi

# Destroy nonexistent
DESTROY_GHOST=$(run_php '
    $manager = new Rudel\SandboxManager();
    echo $manager->destroy("nonexistent") ? "true" : "false";
')
if [[ "$DESTROY_GHOST" == "false" ]]; then
    pass "Destroy nonexistent returns false"
else
    fail "Destroy nonexistent didn't return false" "$DESTROY_GHOST"
fi

# --------------------------------------------------------------------------
# Test 8: Clean up second sandbox
# --------------------------------------------------------------------------
run_php "
    \$manager = new Rudel\SandboxManager();
    \$manager->destroy('$SECOND_ID');
" > /dev/null 2>&1

LIST_FINAL=$(run_php '
    $manager = new Rudel\SandboxManager();
    echo count($manager->list());
')
if [[ "$LIST_FINAL" == "0" ]]; then
    pass "All sandboxes cleaned up"
else
    fail "Sandboxes remain after cleanup" "$LIST_FINAL"
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
