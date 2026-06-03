#!/usr/bin/env bash
#
# E2E Test: request-selected overlay lifecycle.
#
# Proves Rudel against a real single-site WordPress instance running inside
# @wordpress/env. Environments are selected by header, cookie, or CLI env var;
# they isolate DB tables and active theme files while sharing host plugins,
# uploads, users, WordPress core, and the PHP runtime.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PASSED=0
FAILED=0
TOTAL=0
SANDBOX_IDS=()
APP_IDS=()

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

cleanup() {
	if (( ${#APP_IDS[@]} > 0 )); then
		for app_id in "${APP_IDS[@]}"; do
			wp_cli rudel app destroy "$app_id" --force >/dev/null 2>&1 || true
		done
	fi

	if (( ${#SANDBOX_IDS[@]} > 0 )); then
		for sandbox_id in "${SANDBOX_IDS[@]}"; do
			wp_cli rudel destroy "$sandbox_id" --force >/dev/null 2>&1 || true
		done
	fi

	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
	)
}
trap cleanup EXIT

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

strip_wpenv() {
	sed '/^ℹ Starting /d' | sed 's/✔ Ran .*//' | sed '/^[[:space:]]*$/d'
}

shell_quote_args() {
	local quoted=()

	for arg in "$@"; do
		quoted+=("$(printf '%q' "$arg")")
	done

	printf '%s ' "${quoted[@]}"
}

wp_cli() {
	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- wp "$@" 2>&1
	) | strip_wpenv
}

wp_env_cli() {
	local environment_id="$1"
	shift

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- bash -lc "RUDEL_ENVIRONMENT=$(printf '%q' "$environment_id") wp $(shell_quote_args "$@")" 2>&1
	) | strip_wpenv
}

wp_shell() {
	local command="$1"

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- bash -lc "$command" 2>&1
	) | strip_wpenv
}

json_field() {
	local key="$1"

	php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		if (is_array($data) && array_is_list($data) && 1 === count($data) && is_array($data[0])) {
			$data = $data[0];
		}
		$key = $argv[1];
		$value = is_array($data) && array_key_exists($key, $data) ? $data[$key] : null;

		if (is_array($value)) {
			echo json_encode($value, JSON_UNESCAPED_SLASHES);
			return;
		}

		if (is_bool($value)) {
			echo $value ? "true" : "false";
			return;
		}

		if (null !== $value) {
			echo (string) $value;
		}
	' "$key"
}

wp_env_workdir() {
	php -r '
		$config = realpath($argv[1]);
		if (false === $config) {
			$config = $argv[1];
		}

		$home = getenv("WP_ENV_HOME");
		if (! is_string($home) || "" === $home) {
			$home = rtrim((string) getenv("HOME"), "/") . "/.wp-env";
		}

		echo rtrim($home, "/") . "/" . md5($config);
	' "$RUDEL_DIR/.wp-env.json"
}

reset_wp_env_project_state() {
	local workdir=""

	workdir="$(wp_env_workdir)"
	rm -rf "$workdir"
}

start_wp_env() {
	local attempts=0
	local max_attempts=3

	while (( attempts < max_attempts )); do
		attempts=$((attempts + 1))

		if npx wp-env start >/dev/null; then
			return 0
		fi

		if (( attempts < max_attempts )); then
			echo "wp-env start failed on attempt ${attempts}; retrying..." >&2
			npx wp-env destroy --force >/dev/null 2>&1 || true
			reset_wp_env_project_state
			sleep 2
		fi
	done

	echo "wp-env start failed after ${max_attempts} attempts." >&2
	return 1
}

parse_created_id() {
	local pattern="$1"
	local output="$2"

	echo "$output" | grep -oE "${pattern}: [^ ]+" | sed "s/${pattern}: //"
}

environment_json() {
	wp_cli rudel info "$1" --format=json
}

app_json() {
	wp_cli rudel app info "$1" --format=json
}

table_exists() {
	local table="$1"

	wp_cli db query "SHOW TABLES LIKE '${table}'" --skip-column-names | grep -q "^${table}$"
}

overlay_http_body() {
	local environment_id="$1"
	local body_file="$2"

	curl -sS -H "X-Rudel-Environment: ${environment_id}" "http://localhost:8000/" -o "$body_file"
}

overlay_http_status() {
	local environment_id="$1"
	local body_file="$2"

	curl -sS -H "X-Rudel-Environment: ${environment_id}" "http://localhost:8000/" -o "$body_file" -w '%{http_code}'
}

cookie_http_status() {
	local environment_id="$1"
	local body_file="$2"

	curl -sS --cookie "rudel_environment=${environment_id}" "http://localhost:8000/" -o "$body_file" -w '%{http_code}'
}

app_http_status() {
	local domain="$1"
	local body_file="$2"

	curl -sS --resolve "${domain}:8000:127.0.0.1" "http://${domain}:8000/" -o "$body_file" -w '%{http_code}'
}

prepare_single_site() {
	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
		start_wp_env
	)

	wp_shell "perl -0pi -e \"s/^define\\( 'WP_SITEURL'.*\\n//mg; s/^define\\( 'WP_HOME'.*\\n//mg; s/^define\\( 'WP_ALLOW_MULTISITE'.*\\n//mg; s/^define\\( 'MULTISITE'.*\\n//mg; s/^define\\( 'SUBDOMAIN_INSTALL'.*\\n//mg; s/^\\\$base = '\\/'.*\\n//mg; s/^define\\( 'DOMAIN_CURRENT_SITE'.*\\n//mg; s/^define\\( 'PATH_CURRENT_SITE'.*\\n//mg; s/^define\\( 'SITE_ID_CURRENT_SITE'.*\\n//mg; s/^define\\( 'BLOG_ID_CURRENT_SITE'.*\\n//mg; s/^if \\( ! defined\\( 'RUDEL_WP_CONFIG_PATH'.*\\n//mg\" /var/www/html/wp-config.php" >/dev/null
	wp_cli db reset --yes >/dev/null
	wp_cli core install \
		--url=http://localhost:8000 \
		--title='Rudel Host' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email >/dev/null
	wp_cli plugin activate rudel >/dev/null
}

install_host_assets() {
	wp_shell "mkdir -p /var/www/html/wp-content/themes/rudel-host-theme /var/www/html/wp-content/plugins/shared-demo /var/www/html/wp-content/uploads/2026/06" >/dev/null
	wp_shell "cat > /var/www/html/wp-content/themes/rudel-host-theme/style.css <<'CSS'
/*
Theme Name: Rudel Host Theme
*/
CSS
cat > /var/www/html/wp-content/themes/rudel-host-theme/index.php <<'PHP'
<!doctype html>
<html>
<body>
<h1 id=\"site-name\"><?php bloginfo( 'name' ); ?></h1>
<div id=\"theme-marker\">rudel-host-theme</div>
</body>
</html>
PHP
cat > /var/www/html/wp-content/plugins/shared-demo/shared-demo.php <<'PHP'
<?php
/*
Plugin Name: Shared Demo
*/
PHP
printf 'shared upload' > /var/www/html/wp-content/uploads/2026/06/shared.txt" >/dev/null
	wp_cli theme activate rudel-host-theme >/dev/null
}

echo -e "${BOLD}Rudel E2E: Request Overlay Runtime${NC}"
echo "==========================================="
echo ""

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
	echo "Docker not available, skipping wp-env tests"
	exit 0
fi

cd "$RUDEL_DIR"

if [[ ! -d node_modules/@wordpress/env ]]; then
	npm install >/dev/null
fi

echo -e "${BOLD}Prepare single-site WordPress${NC}"
prepare_single_site
install_host_assets

if wp_cli core is-installed >/dev/null 2>&1; then
	pass "WordPress is installed"
else
	fail "WordPress install failed"
	exit 1
fi

if wp_cli plugin is-active rudel >/dev/null 2>&1; then
	pass "Rudel plugin is active"
else
	fail "Rudel plugin is not active" "$(wp_cli plugin list --format=table)"
	exit 1
fi

STATUS_OUTPUT=$(wp_cli rudel status)
if echo "$STATUS_OUTPUT" | grep -q "Bootstrap installed" && echo "$STATUS_OUTPUT" | grep -q "yes"; then
	pass "Runtime bootstrap is installed"
else
	fail "Runtime bootstrap status is unexpected" "$STATUS_OUTPUT"
	exit 1
fi

HOST_BODY="$(mktemp)"
HOST_STATUS=$(curl -sS "http://localhost:8000/" -o "$HOST_BODY" -w '%{http_code}')
if [[ "$HOST_STATUS" == "200" ]] && grep -q "Rudel Host" "$HOST_BODY" && grep -q "rudel-host-theme" "$HOST_BODY"; then
	pass "Host site responds without environment selection"
else
	fail "Host site did not render expected content" "status=${HOST_STATUS} body=$(cat "$HOST_BODY")"
	exit 1
fi
rm -f "$HOST_BODY"

echo ""
echo -e "${BOLD}Sandbox overlays${NC}"

ALPHA_OUTPUT=$(wp_cli rudel create --name=alpha --theme=rudel-host-theme)
ALPHA_ID=$(parse_created_id "Sandbox created" "$ALPHA_OUTPUT")
if [[ -n "$ALPHA_ID" ]]; then
	SANDBOX_IDS+=("$ALPHA_ID")
	pass "Created sandbox ${ALPHA_ID}"
else
	fail "Sandbox creation failed" "$ALPHA_OUTPUT"
	exit 1
fi

BETA_OUTPUT=$(wp_cli rudel create --name=beta --theme=rudel-host-theme)
BETA_ID=$(parse_created_id "Sandbox created" "$BETA_OUTPUT")
if [[ -n "$BETA_ID" && "$BETA_ID" != "$ALPHA_ID" ]]; then
	SANDBOX_IDS+=("$BETA_ID")
	pass "Created sibling sandbox ${BETA_ID}"
else
	fail "Sibling sandbox creation failed" "$BETA_OUTPUT"
	exit 1
fi

ALPHA_JSON=$(environment_json "$ALPHA_ID")
ALPHA_PATH=$(printf '%s' "$ALPHA_JSON" | json_field path)
ALPHA_PREFIX=$(printf '%s' "$ALPHA_JSON" | json_field table_prefix)
ALPHA_THEME=$(printf '%s' "$ALPHA_JSON" | json_field theme_slug)

if wp_shell "test -d '${ALPHA_PATH}/themes/rudel-host-theme' && test -f '${ALPHA_PATH}/themes/rudel-host-theme/style.css'" >/dev/null; then
	pass "Sandbox has its copied active theme"
else
	fail "Sandbox active theme was not copied" "$ALPHA_JSON"
	exit 1
fi

if [[ "$ALPHA_THEME" == "rudel-host-theme" && "$ALPHA_PREFIX" == wp_*_ ]]; then
	pass "Sandbox info reports overlay theme and generated table prefix"
else
	fail "Sandbox info missing overlay metadata" "$ALPHA_JSON"
	exit 1
fi

if table_exists "${ALPHA_PREFIX}options" && ! wp_cli db query "SHOW TABLES LIKE '${ALPHA_PREFIX}rudel_%'" --skip-column-names | grep -q rudel; then
	pass "Sandbox has cloned WordPress tables without cloned Rudel registry tables"
else
	fail "Sandbox DB table layout is wrong" "$(wp_cli db query "SHOW TABLES LIKE '${ALPHA_PREFIX}%'" --skip-column-names)"
	exit 1
fi

if wp_shell "test ! -d '${ALPHA_PATH}/plugins' && test ! -d '${ALPHA_PATH}/uploads'" >/dev/null; then
	pass "Sandbox does not create isolated plugin or upload directories"
else
	fail "Sandbox unexpectedly created plugin/upload directories" "$ALPHA_PATH"
	exit 1
fi

wp_env_cli "$ALPHA_ID" option update blogname "Alpha Site" >/dev/null
wp_env_cli "$BETA_ID" option update blogname "Beta Site" >/dev/null

ALPHA_BLOGNAME=$(wp_env_cli "$ALPHA_ID" option get blogname | tail -1)
BETA_BLOGNAME=$(wp_env_cli "$BETA_ID" option get blogname | tail -1)
HOST_BLOGNAME=$(wp_cli option get blogname | tail -1)
if [[ "$ALPHA_BLOGNAME" == "Alpha Site" && "$BETA_BLOGNAME" == "Beta Site" && "$HOST_BLOGNAME" == "Rudel Host" ]]; then
	pass "CLI environment selection isolates option state"
else
	fail "CLI environment selection leaked option state" "alpha=${ALPHA_BLOGNAME} beta=${BETA_BLOGNAME} host=${HOST_BLOGNAME}"
	exit 1
fi

ALPHA_BODY="$(mktemp)"
ALPHA_STATUS=$(overlay_http_status "$ALPHA_ID" "$ALPHA_BODY")
if [[ "$ALPHA_STATUS" == "200" ]] && grep -q "Alpha Site" "$ALPHA_BODY" && grep -q "rudel-host-theme" "$ALPHA_BODY"; then
	pass "Header-selected sandbox renders its DB state and copied theme"
else
	fail "Header-selected sandbox did not render expected overlay" "status=${ALPHA_STATUS} body=$(cat "$ALPHA_BODY")"
	exit 1
fi
rm -f "$ALPHA_BODY"

BETA_BODY="$(mktemp)"
BETA_STATUS=$(cookie_http_status "$BETA_ID" "$BETA_BODY")
if [[ "$BETA_STATUS" == "200" ]] && grep -q "Beta Site" "$BETA_BODY"; then
	pass "Cookie-selected sandbox renders its own DB state"
else
	fail "Cookie-selected sandbox did not render expected overlay" "status=${BETA_STATUS} body=$(cat "$BETA_BODY")"
	exit 1
fi
rm -f "$BETA_BODY"

wp_env_cli "$ALPHA_ID" plugin activate shared-demo >/dev/null
if wp_env_cli "$ALPHA_ID" plugin is-active shared-demo >/dev/null 2>&1 && ! wp_cli plugin is-active shared-demo >/dev/null 2>&1 && ! wp_env_cli "$BETA_ID" plugin is-active shared-demo >/dev/null 2>&1; then
	pass "Plugin activation state is DB-isolated while plugin files are shared"
else
	fail "Plugin activation state leaked between host or sandboxes" "$(wp_cli plugin list --format=table)"
	exit 1
fi

if wp_shell "test -f /var/www/html/wp-content/plugins/shared-demo/shared-demo.php && test -f /var/www/html/wp-content/uploads/2026/06/shared.txt" >/dev/null; then
	pass "Host plugin files remain the shared code source"
else
	fail "Host plugin/upload files were not available"
	exit 1
fi

SNAPSHOT_OUTPUT=$(wp_cli rudel snapshot "$ALPHA_ID" --name=baseline)
if echo "$SNAPSHOT_OUTPUT" | grep -q "Snapshot created: baseline"; then
	pass "Snapshot creation works for overlay sandboxes"
else
	fail "Snapshot creation failed" "$SNAPSHOT_OUTPUT"
	exit 1
fi

wp_env_cli "$ALPHA_ID" option update blogname "Alpha Changed" >/dev/null
wp_cli rudel restore "$ALPHA_ID" --snapshot=baseline --force >/dev/null
ALPHA_RESTORED=$(wp_env_cli "$ALPHA_ID" option get blogname | tail -1)
if [[ "$ALPHA_RESTORED" == "Alpha Site" ]]; then
	pass "Snapshot restore returns the sandbox DB state to its prior value"
else
	fail "Snapshot restore did not restore sandbox DB state" "$ALPHA_RESTORED"
	exit 1
fi

echo ""
echo -e "${BOLD}App overlays and deploys${NC}"

APP_DOMAIN="demo.example.test"
APP_OUTPUT=$(wp_cli rudel app create --name=Demo --domain="$APP_DOMAIN" --theme=rudel-host-theme)
APP_ID=$(parse_created_id "App created" "$APP_OUTPUT")
if [[ -n "$APP_ID" ]]; then
	APP_IDS+=("$APP_ID")
	pass "Created app ${APP_ID}"
else
	fail "App creation failed" "$APP_OUTPUT"
	exit 1
fi

APP_JSON=$(app_json "$APP_ID")
APP_PATH=$(printf '%s' "$APP_JSON" | json_field path)
APP_PREFIX=$(printf '%s' "$APP_JSON" | json_field table_prefix)
if wp_shell "test -d '${APP_PATH}/themes/rudel-host-theme'" >/dev/null && table_exists "${APP_PREFIX}options"; then
	pass "App has overlay tables and copied active theme"
else
	fail "App overlay assets are missing" "$APP_JSON"
	exit 1
fi

wp_env_cli "$APP_ID" option update blogname "Demo App" >/dev/null

APP_BODY="$(mktemp)"
APP_STATUS=$(app_http_status "$APP_DOMAIN" "$APP_BODY")
if [[ "$APP_STATUS" == "200" ]] && grep -q "Demo App" "$APP_BODY"; then
	pass "Mapped app domain selects the app overlay"
else
	fail "Mapped app domain did not select the app overlay" "status=${APP_STATUS} body=$(cat "$APP_BODY")"
	exit 1
fi
rm -f "$APP_BODY"

FEATURE_OUTPUT=$(wp_cli rudel app create-sandbox "$APP_ID" --name="Feature Sandbox")
FEATURE_ID=$(parse_created_id "Sandbox created from app" "$FEATURE_OUTPUT")
if [[ -n "$FEATURE_ID" ]]; then
	SANDBOX_IDS+=("$FEATURE_ID")
	pass "Created app-derived sandbox ${FEATURE_ID}"
else
	fail "App-derived sandbox creation failed" "$FEATURE_OUTPUT"
	exit 1
fi

FEATURE_BLOGNAME=$(wp_env_cli "$FEATURE_ID" option get blogname | tail -1)
if [[ "$FEATURE_BLOGNAME" == "Demo App" ]]; then
	pass "App-derived sandbox clones app DB state"
else
	fail "App-derived sandbox did not clone app DB state" "$FEATURE_BLOGNAME"
	exit 1
fi

FEATURE_JSON=$(environment_json "$FEATURE_ID")
FEATURE_PATH=$(printf '%s' "$FEATURE_JSON" | json_field path)
FEATURE_PREFIX=$(printf '%s' "$FEATURE_JSON" | json_field table_prefix)
if wp_shell "test -d '${FEATURE_PATH}/themes/rudel-host-theme'" >/dev/null && table_exists "${FEATURE_PREFIX}options"; then
	pass "App-derived sandbox has its own overlay tables and copied active theme"
else
	fail "App-derived sandbox overlay assets are missing" "$FEATURE_JSON"
	exit 1
fi

wp_env_cli "$FEATURE_ID" option update blogname "Feature Deploy" >/dev/null
DEPLOY_PLAN=$(wp_cli rudel app deploy "$APP_ID" --from="$FEATURE_ID" --backup=before-deploy --dry-run)
if echo "$DEPLOY_PLAN" | grep -q "$FEATURE_ID"; then
	pass "App deploy dry-run produces a plan"
else
	fail "App deploy dry-run did not include the source sandbox" "$DEPLOY_PLAN"
	exit 1
fi

DEPLOY_OUTPUT=$(wp_cli rudel app deploy "$APP_ID" --from="$FEATURE_ID" --backup=before-deploy --label="Feature Deploy" --notes="e2e deploy" --force)
if echo "$DEPLOY_OUTPUT" | grep -q "Sandbox deployed to app"; then
	pass "App deploy works"
else
	fail "App deploy failed" "$DEPLOY_OUTPUT"
	exit 1
fi

APP_DEPLOYED_NAME=$(wp_env_cli "$APP_ID" option get blogname | tail -1)
if [[ "$APP_DEPLOYED_NAME" == "Feature Deploy" ]]; then
	pass "App deploy replaces app overlay DB state"
else
	fail "App deploy did not replace app overlay DB state" "$APP_DEPLOYED_NAME"
	exit 1
fi

APP_DEPLOYMENTS=$(wp_cli rudel app deployments "$APP_ID" --format=json)
if echo "$APP_DEPLOYMENTS" | grep -q '"deployed_at"'; then
	pass "App deployment history is recorded"
else
	fail "App deployments list is empty" "$APP_DEPLOYMENTS"
	exit 1
fi

wp_cli rudel app restore "$APP_ID" --backup=before-deploy --force >/dev/null
APP_RESTORED_NAME=$(wp_env_cli "$APP_ID" option get blogname | tail -1)
if [[ "$APP_RESTORED_NAME" == "Demo App" ]]; then
	pass "App restore returns DB state to the saved backup"
else
	fail "App restore did not restore app DB state" "$APP_RESTORED_NAME"
	exit 1
fi

echo ""
echo -e "${BOLD}Destroy cleanup${NC}"

wp_cli rudel destroy "$ALPHA_ID" --force >/dev/null
SANDBOX_IDS=("${BETA_ID}" "${FEATURE_ID}")
if wp_shell "test ! -d '${ALPHA_PATH}'" >/dev/null && ! table_exists "${ALPHA_PREFIX}options"; then
	pass "Destroy removes sandbox directory and generated DB tables"
else
	fail "Destroy did not remove sandbox state" "path=${ALPHA_PATH} prefix=${ALPHA_PREFIX}"
	exit 1
fi

if [[ "$(wp_env_cli "$BETA_ID" option get blogname | tail -1)" == "Beta Site" ]]; then
	pass "Sibling sandbox still works after destroying another sandbox"
else
	fail "Sibling sandbox broke after destroy"
	exit 1
fi

echo ""
echo "==========================================="
if [[ "$FAILED" -eq 0 ]]; then
	echo -e "${GREEN}${BOLD}All ${TOTAL} tests passed!${NC}"
else
	echo -e "${RED}${BOLD}${FAILED} of ${TOTAL} tests failed${NC}"
	exit 1
fi
