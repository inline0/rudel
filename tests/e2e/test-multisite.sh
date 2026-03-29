#!/usr/bin/env bash
#
# E2E Test: multisite clone
#
# Tests cloning from a WordPress Multisite host installation.
# Verifies that per-blog tables (wp_2_posts, wp_3_options, etc.) are
# cloned into the sandbox SQLite database and that main-site URLs
# are rewritten to the sandbox path.
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
    echo "Docker not available, skipping multisite tests"
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

site_cli() {
    local site_url="$1"
    shift
    npx wp-env run cli -- wp --url="$site_url" "$@" 2>&1 | strip_wpenv
}

wpenv_run() {
    npx wp-env run cli -- "$@" 2>&1 | strip_wpenv
}

# Query the sandbox SQLite database directly via PHP/PDO.
# Bypasses WP-CLI entirely, avoiding multisite constant interference.
# Usage: sqlite_query <sandbox_id> <sql>
sqlite_query() {
    local sandbox_id="$1"
    local query="$2"
    local db_path="/var/www/html/wp-content/rudel-environments/${sandbox_id}/wordpress.db"
    npx wp-env run cli -- php -r "\$pdo=new PDO('sqlite:'.\$argv[1]);\$s=\$pdo->query(\$argv[2]);while(\$r=\$s->fetch(PDO::FETCH_NUM))echo implode('|',\$r).PHP_EOL;" "$db_path" "$query" 2>&1 | strip_wpenv || true
}

# Compute the sandbox table prefix (mirrors EnvironmentManager logic).
sandbox_prefix() {
    local id="$1"
    npx wp-env run cli -- php -r "echo 'rudel_'.substr(md5(\$argv[1]),0,6).'_';" "$id" 2>&1 | strip_wpenv || true
}

cleanup() {
    echo ""
    echo -e "${BOLD}Multisite teardown${NC}"

    # Destroy any remaining test sandboxes
    if [[ ${#SANDBOX_IDS[@]} -gt 0 ]]; then
        for sid in "${SANDBOX_IDS[@]}"; do
            wp_cli rudel destroy "$sid" --force > /dev/null 2>&1 || true
        done
    fi

    # Remove multisite constants from wp-config.php inside the container
    npx wp-env run cli -- bash -c "sed -i \
        -e \"/define.*'WP_ALLOW_MULTISITE'/d\" \
        -e \"/define.*'MULTISITE'/d\" \
        -e \"/define.*'SUBDOMAIN_INSTALL'/d\" \
        -e \"/define.*'DOMAIN_CURRENT_SITE'/d\" \
        -e \"/define.*'PATH_CURRENT_SITE'/d\" \
        -e \"/define.*'SITE_ID_CURRENT_SITE'/d\" \
        -e \"/define.*'BLOG_ID_CURRENT_SITE'/d\" \
        /var/www/html/wp-config.php" > /dev/null 2>&1 || true

    # Remove any override file from previous runs
    rm -f "$RUDEL_DIR/.wp-env.override.json"

    # Reset databases and reinstall as single-site
    cd "$RUDEL_DIR"
    npx wp-env clean all 2>&1 || true
    npx wp-env start 2>&1 || true
    echo "  Multisite teardown complete (reset to single-site)"
}
trap cleanup EXIT

echo -e "${BOLD}Rudel E2E: Multisite Clone${NC}"
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

# Multisite setup
echo -e "${BOLD}Multisite setup${NC}"

# Step 1: Create network tables without modifying wp-config.php
echo "Installing multisite network tables..."
wp_cli core multisite-install \
    --title="Test Network" \
    --admin_user=admin \
    --admin_password=password \
    --admin_email=admin@localhost.local \
    --skip-email \
    --skip-config > /dev/null 2>&1 || true

# Step 2: Add multisite constants to wp-config.php (MULTISITE last so
# all supporting constants are present when WordPress loads as multisite)
wp_cli config set WP_ALLOW_MULTISITE true --raw --type=constant > /dev/null 2>&1
wp_cli config set SUBDOMAIN_INSTALL false --raw --type=constant > /dev/null 2>&1
wp_cli config set DOMAIN_CURRENT_SITE 'localhost:8888' --type=constant > /dev/null 2>&1
wp_cli config set PATH_CURRENT_SITE '/' --type=constant > /dev/null 2>&1
wp_cli config set SITE_ID_CURRENT_SITE 1 --raw --type=constant > /dev/null 2>&1
wp_cli config set BLOG_ID_CURRENT_SITE 1 --raw --type=constant > /dev/null 2>&1
wp_cli config set MULTISITE true --raw --type=constant > /dev/null 2>&1
echo ""

# Activate plugin network-wide
wp_cli plugin activate rudel --network > /dev/null 2>&1 || true
wp_cli rewrite structure '/%postname%/' --hard > /dev/null 2>&1

# Create subsites
echo "Creating subsites..."
wp_cli site create --slug=news --title="News Site" > /dev/null 2>&1
wp_cli site create --slug=shop --title="Shop Site" > /dev/null 2>&1

# Seed dummy data: main site (blog_id=1)
echo "Seeding content..."
wp_cli option update blogname "Network Main" > /dev/null 2>&1
wp_cli post create --post_title="Main Article One" --post_status=publish > /dev/null 2>&1
wp_cli post create --post_title="Main Article Two" --post_status=publish > /dev/null 2>&1
wp_cli post create --post_title="About the Network" --post_type=page --post_status=publish > /dev/null 2>&1
wp_cli option update rudel_ms_test '{"role":"main","active":true}' --format=json > /dev/null 2>&1 || true

# Seed dummy data: news site (blog_id=2)
site_cli "http://localhost:8888/news/" option update blogname "News Site" > /dev/null 2>&1
site_cli "http://localhost:8888/news/" post create --post_title="News Entry Alpha" --post_status=publish > /dev/null 2>&1
site_cli "http://localhost:8888/news/" post create --post_title="News Entry Beta" --post_status=publish > /dev/null 2>&1
site_cli "http://localhost:8888/news/" post create --post_title="News Entry Gamma" --post_status=publish > /dev/null 2>&1
site_cli "http://localhost:8888/news/" post create --post_title="News Draft" --post_status=draft > /dev/null 2>&1
site_cli "http://localhost:8888/news/" option update rudel_ms_test '{"role":"news","active":true}' --format=json > /dev/null 2>&1 || true

# Seed dummy data: shop site (blog_id=3)
site_cli "http://localhost:8888/shop/" option update blogname "Shop Site" > /dev/null 2>&1
site_cli "http://localhost:8888/shop/" post create --post_title="Product Launch" --post_status=publish > /dev/null 2>&1
site_cli "http://localhost:8888/shop/" post create --post_title="Summer Sale" --post_status=publish > /dev/null 2>&1
site_cli "http://localhost:8888/shop/" option update rudel_ms_test '{"role":"shop","active":true}' --format=json > /dev/null 2>&1 || true

echo ""

# Pre-flight
echo -e "${BOLD}Pre-flight${NC}"

if npx wp-env run cli -- wp core is-installed --network > /dev/null 2>&1; then
    pass "Multisite is active"
else
    fail "Multisite not active"
fi

SITE_COUNT=$(wp_cli site list --format=count | tail -1)
if [[ "$SITE_COUNT" == "3" ]]; then
    pass "3 sites exist"
else
    fail "Expected 3 sites" "Got: $SITE_COUNT"
fi

MAIN_POSTS=$(wp_cli post list --post_type=post --post_status=publish --format=count | tail -1)
if [[ "$MAIN_POSTS" -ge 2 ]]; then
    pass "Main site has $MAIN_POSTS published posts"
else
    fail "Main site post count wrong" "Expected >= 2, got: $MAIN_POSTS"
fi

NEWS_POSTS=$(site_cli "http://localhost:8888/news/" post list --post_type=post --post_status=publish --format=count | tail -1)
if [[ "$NEWS_POSTS" -ge 3 ]]; then
    pass "News site has $NEWS_POSTS published posts"
else
    fail "News site post count wrong" "Expected >= 3, got: $NEWS_POSTS"
fi

SHOP_POSTS=$(site_cli "http://localhost:8888/shop/" post list --post_type=post --post_status=publish --format=count | tail -1)
if [[ "$SHOP_POSTS" -ge 2 ]]; then
    pass "Shop site has $SHOP_POSTS published posts"
else
    fail "Shop site post count wrong" "Expected >= 2, got: $SHOP_POSTS"
fi

# Clone-all from multisite host
# Use SQLite explicitly because this suite validates the cloned database
# directly via PDO instead of going through sandbox WP-CLI.
echo ""
echo -e "${BOLD}Clone-all from multisite host${NC}"

CLONE_OUTPUT=$(wp_cli rudel create --name=ms-full --clone-all --engine=sqlite)
CLONE_ID=$(parse_sandbox_id "$CLONE_OUTPUT")
if [[ -n "$CLONE_ID" ]]; then
    SANDBOX_IDS+=("$CLONE_ID")
    pass "Created full multisite clone: $CLONE_ID"
else
    fail "Failed to create multisite clone" "$CLONE_OUTPUT"
fi

# Compute sandbox table prefix for direct SQLite queries
SB_PREFIX=$(sandbox_prefix "$CLONE_ID")

# All sandbox data verification is done via direct SQLite queries
# because WP-CLI inherits the host's MULTISITE constants, which
# prevents sandbox_cli from loading the sandbox as single-site.

# Verify main site data cloned
CLONE_BLOGNAME=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}options WHERE option_name='blogname'")
if [[ "$CLONE_BLOGNAME" == "Network Main" ]]; then
    pass "Clone has main site blogname"
else
    fail "Clone blogname mismatch" "Expected 'Network Main', got: $CLONE_BLOGNAME"
fi

CLONE_POSTS=$(sqlite_query "$CLONE_ID" "SELECT post_title FROM ${SB_PREFIX}posts WHERE post_status='publish' AND post_type='post'")
if echo "$CLONE_POSTS" | grep -q "Main Article One"; then
    pass "Clone has 'Main Article One'"
else
    fail "Clone missing 'Main Article One'" "$CLONE_POSTS"
fi

if echo "$CLONE_POSTS" | grep -q "Main Article Two"; then
    pass "Clone has 'Main Article Two'"
else
    fail "Clone missing 'Main Article Two'" "$CLONE_POSTS"
fi

CLONE_PAGES=$(sqlite_query "$CLONE_ID" "SELECT post_title FROM ${SB_PREFIX}posts WHERE post_status='publish' AND post_type='page'")
if echo "$CLONE_PAGES" | grep -q "About the Network"; then
    pass "Clone has 'About the Network' page"
else
    fail "Clone missing page" "$CLONE_PAGES"
fi

CLONE_OPT=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}options WHERE option_name='rudel_ms_test'")
if echo "$CLONE_OPT" | grep -q '"main"'; then
    pass "Clone has main site custom option"
else
    fail "Clone missing rudel_ms_test option" "$CLONE_OPT"
fi

# Verify per-blog tables exist in sandbox SQLite
SANDBOX_TABLES=$(sqlite_query "$CLONE_ID" "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")

if echo "$SANDBOX_TABLES" | grep -q "_2_posts"; then
    pass "News site (blog_id=2) posts table exists in sandbox"
else
    fail "News site posts table missing" "$SANDBOX_TABLES"
fi

if echo "$SANDBOX_TABLES" | grep -q "_2_options"; then
    pass "News site (blog_id=2) options table exists in sandbox"
else
    fail "News site options table missing" "$SANDBOX_TABLES"
fi

if echo "$SANDBOX_TABLES" | grep -q "_3_posts"; then
    pass "Shop site (blog_id=3) posts table exists in sandbox"
else
    fail "Shop site posts table missing" "$SANDBOX_TABLES"
fi

if echo "$SANDBOX_TABLES" | grep -q "_3_options"; then
    pass "Shop site (blog_id=3) options table exists in sandbox"
else
    fail "Shop site options table missing" "$SANDBOX_TABLES"
fi

if echo "$SANDBOX_TABLES" | grep -qE "_blogs$"; then
    pass "Network blogs table exists in sandbox"
else
    fail "Network blogs table missing" "$SANDBOX_TABLES"
fi

if echo "$SANDBOX_TABLES" | grep -qE "_sitemeta$"; then
    pass "Network sitemeta table exists in sandbox"
else
    fail "Network sitemeta table missing" "$SANDBOX_TABLES"
fi

# Verify news site posts data in sandbox
NEWS_TABLE="${SB_PREFIX}2_posts"
NEWS_DATA=$(sqlite_query "$CLONE_ID" "SELECT post_title FROM ${NEWS_TABLE} WHERE post_status='publish' AND post_type='post'")
if echo "$NEWS_DATA" | grep -q "News Entry Alpha"; then
    pass "News site 'News Entry Alpha' in sandbox"
else
    fail "News site post missing" "$NEWS_DATA"
fi
if echo "$NEWS_DATA" | grep -q "News Entry Beta"; then
    pass "News site 'News Entry Beta' in sandbox"
else
    fail "News site post missing" "$NEWS_DATA"
fi
if echo "$NEWS_DATA" | grep -q "News Entry Gamma"; then
    pass "News site 'News Entry Gamma' in sandbox"
else
    fail "News site post missing" "$NEWS_DATA"
fi

# Verify news draft was also cloned
NEWS_DRAFTS=$(sqlite_query "$CLONE_ID" "SELECT post_title FROM ${NEWS_TABLE} WHERE post_status='draft' AND post_type='post'")
if echo "$NEWS_DRAFTS" | grep -q "News Draft"; then
    pass "News site draft post cloned"
else
    fail "News site draft missing" "$NEWS_DRAFTS"
fi

# Verify shop site posts data in sandbox
SHOP_TABLE="${SB_PREFIX}3_posts"
SHOP_DATA=$(sqlite_query "$CLONE_ID" "SELECT post_title FROM ${SHOP_TABLE} WHERE post_status='publish' AND post_type='post'")
if echo "$SHOP_DATA" | grep -q "Product Launch"; then
    pass "Shop site 'Product Launch' in sandbox"
else
    fail "Shop site post missing" "$SHOP_DATA"
fi
if echo "$SHOP_DATA" | grep -q "Summer Sale"; then
    pass "Shop site 'Summer Sale' in sandbox"
else
    fail "Shop site post missing" "$SHOP_DATA"
fi

# Verify per-blog options data
NEWS_OPT=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}2_options WHERE option_name='rudel_ms_test'")
if echo "$NEWS_OPT" | grep -q '"news"'; then
    pass "News site custom option preserved in sandbox"
else
    fail "News site custom option missing" "$NEWS_OPT"
fi

SHOP_OPT=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}3_options WHERE option_name='rudel_ms_test'")
if echo "$SHOP_OPT" | grep -q '"shop"'; then
    pass "Shop site custom option preserved in sandbox"
else
    fail "Shop site custom option missing" "$SHOP_OPT"
fi

# Verify URL rewriting for main site
CLONE_SITEURL=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}options WHERE option_name='siteurl'")
if echo "$CLONE_SITEURL" | grep -q "__rudel/${CLONE_ID}"; then
    pass "Main site siteurl rewritten to sandbox"
else
    fail "Main site siteurl not rewritten" "Got: $CLONE_SITEURL"
fi

CLONE_HOME=$(sqlite_query "$CLONE_ID" "SELECT option_value FROM ${SB_PREFIX}options WHERE option_name='home'")
if echo "$CLONE_HOME" | grep -q "__rudel/${CLONE_ID}"; then
    pass "Main site home URL rewritten to sandbox"
else
    fail "Main site home URL not rewritten" "Got: $CLONE_HOME"
fi

# Verify wp_blogs table has all 3 blog entries
BLOGS_TABLE="${SB_PREFIX}blogs"
BLOG_ROWS=$(sqlite_query "$CLONE_ID" "SELECT blog_id, path FROM ${BLOGS_TABLE} ORDER BY blog_id")
if echo "$BLOG_ROWS" | grep -q "^1|"; then
    pass "wp_blogs has main site entry (blog_id=1)"
else
    fail "wp_blogs missing main site" "$BLOG_ROWS"
fi
if echo "$BLOG_ROWS" | grep -q "^2|"; then
    pass "wp_blogs has news site entry (blog_id=2)"
else
    fail "wp_blogs missing news site" "$BLOG_ROWS"
fi
if echo "$BLOG_ROWS" | grep -q "^3|"; then
    pass "wp_blogs has shop site entry (blog_id=3)"
else
    fail "wp_blogs missing shop site" "$BLOG_ROWS"
fi

# Verify wp-content was copied
CLONE_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-environments/${CLONE_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -n "$CLONE_THEMES" ]]; then
    pass "Clone has themes in wp-content"
else
    fail "Clone missing themes" ""
fi

# Verify isolation: modifying sandbox doesn't affect host network
# (Use a direct SQLite INSERT to modify the sandbox without WP-CLI)
sqlite_query "$CLONE_ID" "UPDATE ${SB_PREFIX}options SET option_value='Modified Clone' WHERE option_name='blogname'" > /dev/null 2>&1 || true
HOST_NAME=$(wp_cli option get blogname | tail -1)
if [[ "$HOST_NAME" == "Network Main" ]]; then
    pass "Host unaffected by sandbox modification"
else
    fail "Host was affected by sandbox modification" "Got: $HOST_NAME"
fi

# Clone-db-only from multisite
echo ""
echo -e "${BOLD}Clone-db-only from multisite${NC}"

DB_OUTPUT=$(wp_cli rudel create --name=ms-dbonly --clone-db --engine=sqlite)
DB_ID=$(parse_sandbox_id "$DB_OUTPUT")
if [[ -n "$DB_ID" ]]; then
    SANDBOX_IDS+=("$DB_ID")
    pass "Created db-only multisite clone: $DB_ID"
else
    fail "Failed to create db-only clone" "$DB_OUTPUT"
fi

DB_PREFIX=$(sandbox_prefix "$DB_ID")
DB_BLOGNAME=$(sqlite_query "$DB_ID" "SELECT option_value FROM ${DB_PREFIX}options WHERE option_name='blogname'")
if [[ "$DB_BLOGNAME" == "Network Main" ]]; then
    pass "DB clone has main site blogname"
else
    fail "DB clone blogname mismatch" "Got: $DB_BLOGNAME"
fi

DB_TABLES=$(sqlite_query "$DB_ID" "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
if echo "$DB_TABLES" | grep -q "_2_posts"; then
    pass "DB clone has news site tables"
else
    fail "DB clone missing news site tables" "$DB_TABLES"
fi
if echo "$DB_TABLES" | grep -q "_3_posts"; then
    pass "DB clone has shop site tables"
else
    fail "DB clone missing shop site tables" "$DB_TABLES"
fi

# DB-only clone should have empty themes
DB_THEMES=$(wpenv_run bash -c "ls /var/www/html/wp-content/rudel-environments/${DB_ID}/wp-content/themes/ 2>/dev/null" | tail -5)
if [[ -z "$DB_THEMES" || "$DB_THEMES" =~ ^[[:space:]]*$ ]]; then
    pass "DB-only clone has empty themes directory"
else
    fail "DB-only clone unexpectedly has themes" "$DB_THEMES"
fi

# Blank sandbox on multisite host
echo ""
echo -e "${BOLD}Blank sandbox on multisite host${NC}"

BLANK_OUTPUT=$(wp_cli rudel create --name=ms-blank --engine=sqlite)
BLANK_ID=$(parse_sandbox_id "$BLANK_OUTPUT")
if [[ -n "$BLANK_ID" ]]; then
    SANDBOX_IDS+=("$BLANK_ID")
    pass "Created blank sandbox on multisite host: $BLANK_ID"
else
    fail "Failed to create blank sandbox" "$BLANK_OUTPUT"
fi

BLANK_PREFIX=$(sandbox_prefix "$BLANK_ID")
BLANK_BLOGNAME=$(sqlite_query "$BLANK_ID" "SELECT option_value FROM ${BLANK_PREFIX}options WHERE option_name='blogname'")
if [[ "$BLANK_BLOGNAME" == "Rudel Sandbox" ]]; then
    pass "Blank sandbox has default blogname (single-site)"
else
    fail "Blank sandbox blogname wrong" "Got: $BLANK_BLOGNAME"
fi

# Verify host multisite unaffected
HOST_SITE_COUNT=$(wp_cli site list --format=count | tail -1)
if [[ "$HOST_SITE_COUNT" == "3" ]]; then
    pass "Host still has 3 sites after blank sandbox creation"
else
    fail "Host site count changed" "Got: $HOST_SITE_COUNT"
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
    echo -e "${GREEN}${BOLD}All $TOTAL multisite tests passed!${NC}"
    exit 0
else
    echo -e "${RED}${BOLD}$FAILED of $TOTAL multisite tests failed${NC}"
    exit 1
fi
