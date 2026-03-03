#!/usr/bin/env bash
#
# E2E Test: wp-env integration
#
# Tests Rudel sandboxes against a real WordPress instance running in Docker
# via @wordpress/env. Requires Docker to be available.
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
    echo "Docker not available, skipping wp-env tests"
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

# wp-env appends status lines like "✔ Ran `cmd` in 'cli'." to stdout
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

http_body() {
    curl -sL "$1" 2>&1
}

copy_theme() {
    local id="$1"
    local theme="$2"
    npx wp-env run cli -- bash -c \
        "cp -r /var/www/html/wp-content/themes/${theme} /var/www/html/wp-content/rudel-sandboxes/${id}/wp-content/themes/" 2>&1 | strip_wpenv
}

cleanup() {
    if [[ ${#SANDBOX_IDS[@]} -gt 0 ]]; then
        for sid in "${SANDBOX_IDS[@]}"; do
            wp_cli rudel destroy "$sid" --force > /dev/null 2>&1 || true
        done
    fi
}
trap cleanup EXIT

echo -e "${BOLD}Rudel E2E: wp-env Integration${NC}"
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

# Pre-flight
echo -e "${BOLD}Pre-flight checks${NC}"

ACTIVE_PLUGINS=$(wp_cli plugin list --status=active --format=csv --fields=name)
if echo "$ACTIVE_PLUGINS" | grep -q "rudel"; then
    pass "Plugin is active"
else
    echo "  Activating plugin..."
    wp_cli plugin activate rudel 2>&1
    ACTIVE_PLUGINS=$(wp_cli plugin list --status=active --format=csv --fields=name)
    if echo "$ACTIVE_PLUGINS" | grep -q "rudel"; then
        pass "Plugin activated successfully"
    else
        fail "Plugin activation failed" "$ACTIVE_PLUGINS"
    fi
fi

# Ensure .htaccess exists for URL rewriting
wp_cli rewrite structure '/%postname%/' --hard > /dev/null 2>&1

STATUS_OUTPUT=$(wp_cli rudel status)
if echo "$STATUS_OUTPUT" | grep -q "yes"; then
    pass "Bootstrap is installed"
else
    fail "Bootstrap not installed" "$STATUS_OUTPUT"
fi

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8888/)
if [[ "$HTTP_CODE" =~ ^(200|301|302)$ ]]; then
    pass "Host WordPress responds (HTTP $HTTP_CODE)"
else
    fail "Host WordPress not responding" "HTTP $HTTP_CODE"
fi

# Create sandboxes
echo ""
echo -e "${BOLD}Create sandboxes${NC}"

ALPHA_OUTPUT=$(wp_cli rudel create --name=alpha)
ALPHA_ID=$(parse_sandbox_id "$ALPHA_OUTPUT")
if [[ -n "$ALPHA_ID" ]]; then
    SANDBOX_IDS+=("$ALPHA_ID")
    pass "Created sandbox alpha: $ALPHA_ID"
else
    fail "Failed to create sandbox alpha" "$ALPHA_OUTPUT"
fi

BETA_OUTPUT=$(wp_cli rudel create --name=beta)
BETA_ID=$(parse_sandbox_id "$BETA_OUTPUT")
if [[ -n "$BETA_ID" ]]; then
    SANDBOX_IDS+=("$BETA_ID")
    pass "Created sandbox beta: $BETA_ID"
else
    fail "Failed to create sandbox beta" "$BETA_OUTPUT"
fi

LIST_OUTPUT=$(wp_cli rudel list --format=count)
LIST_COUNT=$(echo "$LIST_OUTPUT" | tail -1)
if [[ "$LIST_COUNT" == "2" ]]; then
    pass "wp rudel list shows 2 sandboxes"
else
    fail "Wrong sandbox count" "Expected 2, got: $LIST_COUNT"
fi

if [[ "$ALPHA_ID" != "$BETA_ID" ]]; then
    pass "Sandboxes have different IDs"
else
    fail "Sandboxes have same ID" "$ALPHA_ID"
fi

# Directory structure
echo ""
echo -e "${BOLD}Directory structure${NC}"

ALPHA_DIR_EXISTS=$(wpenv_run bash -c "test -d /var/www/html/wp-content/rudel-sandboxes/${ALPHA_ID} && echo yes || echo no" | tail -1)
if [[ "$ALPHA_DIR_EXISTS" == "yes" ]]; then
    pass "Alpha sandbox directory exists"
else
    fail "Alpha sandbox directory missing" "$ALPHA_DIR_EXISTS"
fi

ALPHA_DB_EXISTS=$(wpenv_run bash -c "test -f /var/www/html/wp-content/rudel-sandboxes/${ALPHA_ID}/wordpress.db && echo yes || echo no" | tail -1)
if [[ "$ALPHA_DB_EXISTS" == "yes" ]]; then
    pass "Alpha wordpress.db exists"
else
    fail "Alpha wordpress.db missing" "$ALPHA_DB_EXISTS"
fi

ALPHA_DROPIN_EXISTS=$(wpenv_run bash -c "test -f /var/www/html/wp-content/rudel-sandboxes/${ALPHA_ID}/wp-content/db.php && echo yes || echo no" | tail -1)
if [[ "$ALPHA_DROPIN_EXISTS" == "yes" ]]; then
    pass "Alpha db.php drop-in exists"
else
    fail "Alpha db.php drop-in missing" "$ALPHA_DROPIN_EXISTS"
fi

echo ""
echo -e "${BOLD}Configure sandboxes${NC}"

sandbox_cli "$ALPHA_ID" option update blogname "Alpha Site" > /dev/null 2>&1
ALPHA_BLOGNAME=$(sandbox_cli "$ALPHA_ID" option get blogname | tail -1)
if [[ "$ALPHA_BLOGNAME" == "Alpha Site" ]]; then
    pass "Alpha blogname set"
else
    fail "Alpha blogname not set" "Got: $ALPHA_BLOGNAME"
fi

sandbox_cli "$BETA_ID" option update blogname "Beta Site" > /dev/null 2>&1
BETA_BLOGNAME=$(sandbox_cli "$BETA_ID" option get blogname | tail -1)
if [[ "$BETA_BLOGNAME" == "Beta Site" ]]; then
    pass "Beta blogname set"
else
    fail "Beta blogname not set" "Got: $BETA_BLOGNAME"
fi

# Copy and activate different themes
copy_theme "$ALPHA_ID" "twentytwentyfour" > /dev/null 2>&1
sandbox_cli "$ALPHA_ID" theme activate twentytwentyfour > /dev/null 2>&1
ALPHA_THEME=$(sandbox_cli "$ALPHA_ID" theme list --status=active --format=csv --fields=name | tail -1)
if [[ "$ALPHA_THEME" == "twentytwentyfour" ]]; then
    pass "Alpha theme: twentytwentyfour"
else
    fail "Alpha theme not set" "Got: $ALPHA_THEME"
fi

copy_theme "$BETA_ID" "twentytwentythree" > /dev/null 2>&1
sandbox_cli "$BETA_ID" theme activate twentytwentythree > /dev/null 2>&1
BETA_THEME=$(sandbox_cli "$BETA_ID" theme list --status=active --format=csv --fields=name | tail -1)
if [[ "$BETA_THEME" == "twentytwentythree" ]]; then
    pass "Beta theme: twentytwentythree"
else
    fail "Beta theme not set" "Got: $BETA_THEME"
fi

# Create content
echo ""
echo -e "${BOLD}Create content${NC}"

sandbox_cli "$ALPHA_ID" post create --post_title="Alpha Post One" --post_status=publish > /dev/null 2>&1
sandbox_cli "$ALPHA_ID" post create --post_title="Alpha Post Two" --post_status=publish > /dev/null 2>&1
ALPHA_POST_COUNT=$(sandbox_cli "$ALPHA_ID" post list --post_type=post --post_status=publish --format=count | tail -1)
if [[ "$ALPHA_POST_COUNT" == "3" ]]; then
    pass "Alpha has 3 published posts (1 default + 2 new)"
else
    fail "Alpha post count wrong" "Expected 3, got: $ALPHA_POST_COUNT"
fi

sandbox_cli "$BETA_ID" post create --post_title="Beta Post Only" --post_status=publish > /dev/null 2>&1
BETA_POST_COUNT=$(sandbox_cli "$BETA_ID" post list --post_type=post --post_status=publish --format=count | tail -1)
if [[ "$BETA_POST_COUNT" == "2" ]]; then
    pass "Beta has 2 published posts (1 default + 1 new)"
else
    fail "Beta post count wrong" "Expected 2, got: $BETA_POST_COUNT"
fi

# WP-CLI isolation
echo ""
echo -e "${BOLD}WP-CLI isolation${NC}"

ALPHA_NAME_CHECK=$(sandbox_cli "$ALPHA_ID" option get blogname | tail -1)
if [[ "$ALPHA_NAME_CHECK" == "Alpha Site" ]]; then
    pass "Alpha CLI returns Alpha Site"
else
    fail "Alpha CLI returned wrong blogname" "Got: $ALPHA_NAME_CHECK"
fi

BETA_NAME_CHECK=$(sandbox_cli "$BETA_ID" option get blogname | tail -1)
if [[ "$BETA_NAME_CHECK" == "Beta Site" ]]; then
    pass "Beta CLI returns Beta Site"
else
    fail "Beta CLI returned wrong blogname" "Got: $BETA_NAME_CHECK"
fi

ALPHA_THEME_CHECK=$(sandbox_cli "$ALPHA_ID" option get stylesheet | tail -1)
if [[ "$ALPHA_THEME_CHECK" == "twentytwentyfour" ]]; then
    pass "Alpha CLI returns twentytwentyfour theme"
else
    fail "Alpha CLI returned wrong theme" "Got: $ALPHA_THEME_CHECK"
fi

BETA_THEME_CHECK=$(sandbox_cli "$BETA_ID" option get stylesheet | tail -1)
if [[ "$BETA_THEME_CHECK" == "twentytwentythree" ]]; then
    pass "Beta CLI returns twentytwentythree theme"
else
    fail "Beta CLI returned wrong theme" "Got: $BETA_THEME_CHECK"
fi

# Verify posts don't leak between sandboxes
ALPHA_HAS_BETA=$(sandbox_cli "$ALPHA_ID" post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if ! echo "$ALPHA_HAS_BETA" | grep -q "Beta Post Only"; then
    pass "Alpha does not have Beta's posts"
else
    fail "Alpha has Beta's posts" "$ALPHA_HAS_BETA"
fi

BETA_HAS_ALPHA=$(sandbox_cli "$BETA_ID" post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if ! echo "$BETA_HAS_ALPHA" | grep -q "Alpha Post"; then
    pass "Beta does not have Alpha's posts"
else
    fail "Beta has Alpha's posts" "$BETA_HAS_ALPHA"
fi

ALPHA_TITLES=$(sandbox_cli "$ALPHA_ID" post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if echo "$ALPHA_TITLES" | grep -q "Alpha Post One"; then
    pass "Alpha has its own post 'Alpha Post One'"
else
    fail "Alpha missing its own post" "$ALPHA_TITLES"
fi

BETA_TITLES=$(sandbox_cli "$BETA_ID" post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if echo "$BETA_TITLES" | grep -q "Beta Post Only"; then
    pass "Beta has its own post 'Beta Post Only'"
else
    fail "Beta missing its own post" "$BETA_TITLES"
fi

# HTTP isolation
echo ""
echo -e "${BOLD}HTTP isolation${NC}"

ALPHA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8888/__rudel/${ALPHA_ID}/")
if [[ "$ALPHA_HTTP_CODE" == "200" ]]; then
    pass "Alpha HTTP 200"
else
    fail "Alpha HTTP failed" "Got: $ALPHA_HTTP_CODE"
fi

BETA_HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8888/__rudel/${BETA_ID}/")
if [[ "$BETA_HTTP_CODE" == "200" ]]; then
    pass "Beta HTTP 200"
else
    fail "Beta HTTP failed" "Got: $BETA_HTTP_CODE"
fi

ALPHA_BODY=$(http_body "http://localhost:8888/__rudel/${ALPHA_ID}/")
if grep -qi "Alpha Site" <<< "$ALPHA_BODY"; then
    pass "Alpha HTML contains 'Alpha Site'"
else
    fail "Alpha HTML missing title" "Body snippet: $(head -20 <<< "$ALPHA_BODY")"
fi

if grep -qi "twentytwentyfour" <<< "$ALPHA_BODY"; then
    pass "Alpha HTML references twentytwentyfour theme"
else
    fail "Alpha HTML missing theme reference" ""
fi

BETA_BODY=$(http_body "http://localhost:8888/__rudel/${BETA_ID}/")
if grep -qi "Beta Site" <<< "$BETA_BODY"; then
    pass "Beta HTML contains 'Beta Site'"
else
    fail "Beta HTML missing title" "Body snippet: $(head -20 <<< "$BETA_BODY")"
fi

if grep -qi "twentytwentythree" <<< "$BETA_BODY"; then
    pass "Beta HTML references twentytwentythree theme"
else
    fail "Beta HTML missing theme reference" ""
fi

# Host unaffected
echo ""
echo -e "${BOLD}Host unaffected${NC}"

HOST_BLOGNAME=$(wp_cli option get blogname | tail -1)
if [[ "$HOST_BLOGNAME" != "Alpha Site" && "$HOST_BLOGNAME" != "Beta Site" ]]; then
    pass "Host blogname is not a sandbox name ($HOST_BLOGNAME)"
else
    fail "Host blogname was overwritten" "Got: $HOST_BLOGNAME"
fi

HOST_POSTS=$(wp_cli post list --post_type=post --post_status=publish --format=csv --fields=post_title)
if ! echo "$HOST_POSTS" | grep -q "Alpha Post\|Beta Post"; then
    pass "Host has no sandbox posts"
else
    fail "Host has sandbox posts" "$HOST_POSTS"
fi

# Destroy and cleanup
echo ""
echo -e "${BOLD}Destroy and cleanup${NC}"

DESTROY_ALPHA=$(wp_cli rudel destroy "$ALPHA_ID" --force)
if echo "$DESTROY_ALPHA" | grep -q "Success"; then
    pass "Alpha destroyed"
else
    fail "Alpha destroy failed" "$DESTROY_ALPHA"
fi

ALPHA_DIR_GONE=$(wpenv_run bash -c "test -d /var/www/html/wp-content/rudel-sandboxes/${ALPHA_ID} && echo exists || echo gone" | tail -1)
if [[ "$ALPHA_DIR_GONE" == "gone" ]]; then
    pass "Alpha directory removed"
else
    fail "Alpha directory still exists" "$ALPHA_DIR_GONE"
fi

# Beta should still work
BETA_STILL_WORKS=$(sandbox_cli "$BETA_ID" option get blogname | tail -1)
if [[ "$BETA_STILL_WORKS" == "Beta Site" ]]; then
    pass "Beta still works after Alpha destroyed"
else
    fail "Beta broken after Alpha destroy" "Got: $BETA_STILL_WORKS"
fi

DESTROY_BETA=$(wp_cli rudel destroy "$BETA_ID" --force)
if echo "$DESTROY_BETA" | grep -q "Success"; then
    pass "Beta destroyed"
else
    fail "Beta destroy failed" "$DESTROY_BETA"
fi

LIST_FINAL=$(wp_cli rudel list)
if echo "$LIST_FINAL" | grep -q "No sandboxes found"; then
    pass "No sandboxes remain"
else
    fail "Sandboxes still listed" "$LIST_FINAL"
fi

# Clear destroyed IDs so cleanup trap doesn't try again
SANDBOX_IDS=()

# Results
echo ""
echo "==========================================="
if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}${BOLD}All $TOTAL tests passed!${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}$FAILED of $TOTAL tests failed${NC}"
    exit 1
fi
