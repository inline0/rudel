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
    sed 's/✔ Ran .*//' | sed '/ℹ Starting /d' | sed '/^[[:space:]]*$/d'
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

# Create rich host content to clone
wp_cli option update blogname "Host Site Name" > /dev/null 2>&1
wp_cli option update blogdescription "A complex WordPress site for clone testing" > /dev/null 2>&1

# Posts with varied content
wp_cli post create --post_title="Host Post Alpha" --post_status=publish --post_content='<p>Alpha content with <a href="http://localhost:8888/about">internal link</a>.</p>' > /dev/null 2>&1
wp_cli post create --post_title="Host Post Beta" --post_status=publish --post_content='<div class="gallery"><img src="http://localhost:8888/wp-content/uploads/photo.jpg" /></div>' > /dev/null 2>&1
wp_cli post create --post_title="Draft Post" --post_status=draft --post_content='Unpublished draft content.' > /dev/null 2>&1
wp_cli post create --post_title="Host Page" --post_type=page --post_status=publish --post_content='<p>Page content with special chars: O'\''Brien &amp; "quotes"</p>' > /dev/null 2>&1

# Custom post meta
ALPHA_ID_NUM=$(wp_cli post list --post_type=post --post_status=publish --format=csv --fields=ID,post_title | grep "Host Post Alpha" | cut -d',' -f1 | tail -1)
if [[ -n "$ALPHA_ID_NUM" ]]; then
    wp_cli post meta update "$ALPHA_ID_NUM" _custom_url "http://localhost:8888/custom/path" > /dev/null 2>&1
    wp_cli post meta update "$ALPHA_ID_NUM" _view_count "42" > /dev/null 2>&1
    wp_cli post meta update "$ALPHA_ID_NUM" _featured "1" > /dev/null 2>&1
fi

# Create a second user
wp_cli user create editor editor@host.local --role=editor --display_name="Editor User" > /dev/null 2>&1 || true

# Serialized option (simulating plugin settings)
wp_cli option update rudel_test_settings '{"api_url":"http://localhost:8888/api","enabled":true,"count":5}' --format=json > /dev/null 2>&1 || true

HOST_POST_COUNT=$(wp_cli post list --post_type=post --post_status=publish --format=count | tail -1)
HOST_PAGE_COUNT=$(wp_cli post list --post_type=page --post_status=publish --format=count | tail -1)
HOST_USER_COUNT=$(wp_cli user list --format=count | tail -1)
pass "Host has $HOST_POST_COUNT published posts, $HOST_PAGE_COUNT pages, $HOST_USER_COUNT users"

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

# Verify draft posts cloned too
CLONE_DRAFTS=$(sandbox_cli "$CLONE_ID" post list --post_type=post --post_status=draft --format=csv --fields=post_title)
if echo "$CLONE_DRAFTS" | grep -q "Draft Post"; then
    pass "Clone has draft post"
else
    fail "Clone missing draft post" "$CLONE_DRAFTS"
fi

# Verify pages cloned
CLONE_PAGES=$(sandbox_cli "$CLONE_ID" post list --post_type=page --post_status=publish --format=csv --fields=post_title)
if echo "$CLONE_PAGES" | grep -q "Host Page"; then
    pass "Clone has custom page"
else
    fail "Clone missing custom page" "$CLONE_PAGES"
fi

# Verify multiple users cloned
CLONE_USERS=$(sandbox_cli "$CLONE_ID" user list --format=count | tail -1)
if [[ "$CLONE_USERS" -ge 2 ]]; then
    pass "Clone has $CLONE_USERS users (multiple)"
else
    fail "Clone user count wrong" "Expected >= 2, got: $CLONE_USERS"
fi

# Verify post meta survived clone
if [[ -n "$ALPHA_ID_NUM" ]]; then
    CLONE_META_VAL=$(sandbox_cli "$CLONE_ID" post meta get "$ALPHA_ID_NUM" _view_count | tail -1)
    if [[ "$CLONE_META_VAL" == "42" ]]; then
        pass "Clone has post meta (_view_count=42)"
    else
        fail "Clone post meta missing" "Got: $CLONE_META_VAL"
    fi

    # Verify URL in post meta was rewritten
    CLONE_META_URL=$(sandbox_cli "$CLONE_ID" post meta get "$ALPHA_ID_NUM" _custom_url | tail -1)
    if echo "$CLONE_META_URL" | grep -q "__rudel/${CLONE_ID}"; then
        pass "Clone post meta URL rewritten to sandbox"
    else
        fail "Clone post meta URL not rewritten" "Got: $CLONE_META_URL"
    fi
fi

# Verify blogdescription survived
CLONE_DESC=$(sandbox_cli "$CLONE_ID" option get blogdescription | tail -1)
if [[ "$CLONE_DESC" == "A complex WordPress site for clone testing" ]]; then
    pass "Clone has host blogdescription"
else
    fail "Clone blogdescription mismatch" "Got: $CLONE_DESC"
fi

# Verify sandbox URL rewriting
CLONE_SITEURL=$(sandbox_cli "$CLONE_ID" option get siteurl | tail -1)
if echo "$CLONE_SITEURL" | grep -q "__rudel/${CLONE_ID}"; then
    pass "Clone siteurl points to sandbox path"
else
    fail "Clone siteurl not rewritten" "Got: $CLONE_SITEURL"
fi

# Verify wp-content was copied (themes should be present)
CLONE_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-environments/${CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -n "$CLONE_THEMES" ]]; then
    pass "Clone has themes in wp-content"
else
    fail "Clone missing themes" ""
fi

# Verify clone metadata in .rudel.json
CLONE_META=$(wpenv_run bash -c "cat /var/www/html/wp-content/rudel-environments/${CLONE_ID}/.rudel.json" | tail -30)
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
DB_CLONE_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-environments/${DB_CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -z "$DB_CLONE_THEMES" || "$DB_CLONE_THEMES" =~ ^[[:space:]]*$ ]]; then
    pass "DB-only clone has empty themes directory"
else
    fail "DB-only clone unexpectedly has themes" "$DB_CLONE_THEMES"
fi

# Selective clone: themes only (no DB)
echo ""
echo -e "${BOLD}Selective clone: themes only${NC}"

THEME_CLONE_OUTPUT=$(wp_cli rudel create --name=themes-only --clone-themes)
THEME_CLONE_ID=$(parse_sandbox_id "$THEME_CLONE_OUTPUT")
if [[ -n "$THEME_CLONE_ID" ]]; then
    SANDBOX_IDS+=("$THEME_CLONE_ID")
    pass "Created themes-only clone: $THEME_CLONE_ID"
else
    fail "Failed to create themes-only clone" "$THEME_CLONE_OUTPUT"
fi

# Themes-only clone should have a blank DB (default blogname)
THEME_CLONE_NAME=$(sandbox_cli "$THEME_CLONE_ID" option get blogname | tail -1)
if [[ "$THEME_CLONE_NAME" == "Rudel Sandbox" ]]; then
    pass "Themes-only clone has blank DB (default blogname)"
else
    fail "Themes-only clone DB not blank" "Got: $THEME_CLONE_NAME"
fi

# But should have themes copied
THEME_CLONE_HAS_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-environments/${THEME_CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -n "$THEME_CLONE_HAS_THEMES" ]]; then
    pass "Themes-only clone has themes copied"
else
    fail "Themes-only clone missing themes" ""
fi

# Verify clone_source metadata
THEME_META=$(wpenv_run bash -c "cat /var/www/html/wp-content/rudel-environments/${THEME_CLONE_ID}/.rudel.json" | tail -30)
if echo "$THEME_META" | grep -q '"db_cloned": false'; then
    pass "Themes-only metadata shows db_cloned: false"
else
    fail "Themes-only metadata wrong db_cloned" "$THEME_META"
fi

if echo "$THEME_META" | grep -q '"themes_cloned": true'; then
    pass "Themes-only metadata shows themes_cloned: true"
else
    fail "Themes-only metadata wrong themes_cloned" "$THEME_META"
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
