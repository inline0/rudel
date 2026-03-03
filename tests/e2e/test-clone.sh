#!/usr/bin/env bash
#
# E2E Test: clone from host
#
# Tests cloning the host MySQL database and wp-content into a sandbox.
# Requires Docker and wp-env to be available.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PASSED=0
FAILED=0
TOTAL=0
SANDBOX_IDS=()

# Skip gracefully if Docker is unavailable
if ! command -v docker &> /dev/null || ! docker info &> /dev/null 2>&1; then
    echo "Docker not available, skipping clone tests"
    exit 0
fi

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
    if [[ -n "${2:-}" ]]; then
        echo "    $2"
    fi
}

parse_sandbox_id() {
    echo "$1" | grep -oE 'Sandbox created: [^ ]+' | sed 's/Sandbox created: //'
}

strip_wpenv() {
    sed 's/✔ Ran .*//' | sed '/^[[:space:]]*$/d'
}

wp_cli() {
    npx wp-env run cli -- wp "$@" 2>&1 | strip_wpenv
}

sandbox_cli() {
    local id="$1"
    shift
    npx wp-env run cli -- wp --url="http://localhost/__rudel/${id}/" "$@" 2>&1 | strip_wpenv
}

wpenv_run() {
    npx wp-env run cli -- "$@" 2>&1 | strip_wpenv
}

cleanup() {
    if [[ ${#SANDBOX_IDS[@]} -gt 0 ]]; then
        for sid in "${SANDBOX_IDS[@]}"; do
            wp_cli rudel destroy "$sid" --force > /dev/null 2>&1 || true
        done
    fi
}
trap cleanup EXIT

echo -e "${BOLD}Rudel E2E: Clone from Host${NC}"
echo "==========================================="

# Startup
cd "$RUDEL_DIR"

if [[ ! -d node_modules/@wordpress/env ]]; then
    echo "Installing @wordpress/env..."
    npm install 2>&1
fi

echo "Starting wp-env..."
npx wp-env start 2>&1
echo ""

# Wait for WordPress to respond
echo "Waiting for WordPress..."
for i in $(seq 1 30); do
    if curl -s -o /dev/null -w "%{http_code}" http://localhost:8888/ | grep -qE '200|301|302'; then
        break
    fi
    sleep 1
done
echo ""

# Pre-flight: ensure plugin active and host has content
echo -e "${BOLD}Pre-flight${NC}"

ACTIVE_PLUGINS=$(wp_cli plugin list --status=active --format=csv --fields=name)
if ! echo "$ACTIVE_PLUGINS" | grep -q "rudel"; then
    wp_cli plugin activate rudel 2>&1
fi
pass "Plugin active"

wp_cli rewrite structure '/%postname%/' --hard > /dev/null 2>&1

# Create host content to clone
wp_cli post create --post_title="Host Post Alpha" --post_status=publish > /dev/null 2>&1
wp_cli post create --post_title="Host Post Beta" --post_status=publish > /dev/null 2>&1
wp_cli option update blogname "Host Site Name" > /dev/null 2>&1

HOST_POST_COUNT=$(wp_cli post list --post_type=post --post_status=publish --format=count | tail -1)
pass "Host has $HOST_POST_COUNT published posts"

# Clone all
echo ""
echo -e "${BOLD}Clone all from host${NC}"

CLONE_OUTPUT=$(wp_cli rudel create --name=full-clone --clone-all)
CLONE_ID=$(parse_sandbox_id "$CLONE_OUTPUT")
if [[ -n "$CLONE_ID" ]]; then
    SANDBOX_IDS+=("$CLONE_ID")
    pass "Created full clone sandbox: $CLONE_ID"
else
    fail "Failed to create clone sandbox" "$CLONE_OUTPUT"
fi

# Verify clone summary in output
if echo "$CLONE_OUTPUT" | grep -q "Database:"; then
    pass "Clone output shows database summary"
else
    fail "Clone output missing database summary" "$CLONE_OUTPUT"
fi

# Verify cloned database content
CLONE_BLOGNAME=$(sandbox_cli "$CLONE_ID" option get blogname | tail -1)
if [[ "$CLONE_BLOGNAME" == "Host Site Name" ]]; then
    pass "Clone has host blogname"
else
    fail "Clone blogname mismatch" "Expected 'Host Site Name', got: $CLONE_BLOGNAME"
fi

CLONE_POSTS=$(sandbox_cli "$CLONE_ID" post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if echo "$CLONE_POSTS" | grep -q "Host Post Alpha"; then
    pass "Clone has 'Host Post Alpha'"
else
    fail "Clone missing host post" "$CLONE_POSTS"
fi

if echo "$CLONE_POSTS" | grep -q "Host Post Beta"; then
    pass "Clone has 'Host Post Beta'"
else
    fail "Clone missing host post" "$CLONE_POSTS"
fi

# Verify sandbox URL rewriting
CLONE_SITEURL=$(sandbox_cli "$CLONE_ID" option get siteurl | tail -1)
if echo "$CLONE_SITEURL" | grep -q "__rudel/${CLONE_ID}"; then
    pass "Clone siteurl points to sandbox path"
else
    fail "Clone siteurl not rewritten" "Got: $CLONE_SITEURL"
fi

# Verify wp-content was copied (themes should be present)
CLONE_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-sandboxes/${CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -n "$CLONE_THEMES" ]]; then
    pass "Clone has themes in wp-content"
else
    fail "Clone missing themes" ""
fi

# Verify clone metadata in .rudel.json
CLONE_META=$(wpenv_run bash -c "cat /var/www/html/wp-content/rudel-sandboxes/${CLONE_ID}/.rudel.json" | tail -30)
if echo "$CLONE_META" | grep -q "clone_source"; then
    pass "Clone metadata has clone_source"
else
    fail "Clone metadata missing clone_source" "$CLONE_META"
fi

if echo "$CLONE_META" | grep -q '"db_cloned": true'; then
    pass "Clone metadata shows db_cloned: true"
else
    fail "Clone metadata db_cloned not true" "$CLONE_META"
fi

# Clone DB only
echo ""
echo -e "${BOLD}Clone database only${NC}"

DB_CLONE_OUTPUT=$(wp_cli rudel create --name=db-only --clone-db)
DB_CLONE_ID=$(parse_sandbox_id "$DB_CLONE_OUTPUT")
if [[ -n "$DB_CLONE_ID" ]]; then
    SANDBOX_IDS+=("$DB_CLONE_ID")
    pass "Created db-only clone: $DB_CLONE_ID"
else
    fail "Failed to create db-only clone" "$DB_CLONE_OUTPUT"
fi

DB_CLONE_BLOGNAME=$(sandbox_cli "$DB_CLONE_ID" option get blogname | tail -1)
if [[ "$DB_CLONE_BLOGNAME" == "Host Site Name" ]]; then
    pass "DB clone has host blogname"
else
    fail "DB clone blogname mismatch" "Got: $DB_CLONE_BLOGNAME"
fi

# DB-only clone should have empty themes
DB_CLONE_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-sandboxes/${DB_CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -z "$DB_CLONE_THEMES" || "$DB_CLONE_THEMES" =~ ^[[:space:]]*$ ]]; then
    pass "DB-only clone has empty themes directory"
else
    fail "DB-only clone unexpectedly has themes" "$DB_CLONE_THEMES"
fi

# Isolation: modifying clone doesn't affect host
echo ""
echo -e "${BOLD}Clone isolation${NC}"

sandbox_cli "$CLONE_ID" option update blogname "Cloned Site Modified" > /dev/null 2>&1
HOST_NAME_CHECK=$(wp_cli option get blogname | tail -1)
if [[ "$HOST_NAME_CHECK" == "Host Site Name" ]]; then
    pass "Host unaffected by clone modification"
else
    fail "Host was affected by clone modification" "Got: $HOST_NAME_CHECK"
fi

sandbox_cli "$CLONE_ID" post create --post_title="Clone-Only Post" --post_status=publish > /dev/null 2>&1
HOST_POSTS_CHECK=$(wp_cli post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if ! echo "$HOST_POSTS_CHECK" | grep -q "Clone-Only Post"; then
    pass "Host does not have clone-only post"
else
    fail "Clone post leaked to host" "$HOST_POSTS_CHECK"
fi

# Cleanup
echo ""
echo -e "${BOLD}Cleanup${NC}"

for sid in "${SANDBOX_IDS[@]}"; do
    DESTROY_OUT=$(wp_cli rudel destroy "$sid" --force)
    if echo "$DESTROY_OUT" | grep -q "Success"; then
        pass "Destroyed $sid"
    else
        fail "Failed to destroy $sid" "$DESTROY_OUT"
    fi
done
SANDBOX_IDS=()

# Verify all gone
LIST_FINAL=$(wp_cli rudel list)
if echo "$LIST_FINAL" | grep -q "No sandboxes found"; then
    pass "No sandboxes remain"
else
    fail "Sandboxes still listed" "$LIST_FINAL"
fi

# Results
echo ""
echo "==========================================="
if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All $TOTAL clone tests passed!${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}$FAILED of $TOTAL clone tests failed${NC}"
    exit 1
fi
