#!/usr/bin/env bash
#
# E2E Test: subdomain multisite lifecycle
#
# Proves the current Rudel runtime contract against a real WordPress instance
# running inside @wordpress/env. Rudel environments are real subdomain
# multisite sites and all browser/runtime identity flows through those sites.
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

wp_cli() {
	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- wp "$@" 2>&1
	) | strip_wpenv
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

site_cli() {
	local url="$1"
	shift

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- wp --url="$url" "$@" 2>&1
	) | strip_wpenv
}

parse_created_id() {
	local pattern="$1"
	local output="$2"

	echo "$output" | grep -oE "${pattern}: [^ ]+" | sed "s/${pattern}: //"
}

site_url_for_slug() {
	local slug="$1"

	wp_cli site list --fields=url --format=csv \
		| awk -F, -v slug="$slug" 'NR > 1 && $1 ~ ("^http://" slug "\\.") { print $1; exit }'
}

site_blog_id_for_slug() {
	local slug="$1"

	wp_cli site list --fields=blog_id,url --format=csv \
		| awk -F, -v slug="$slug" 'NR > 1 && $2 ~ ("^http://" slug "\\.") { print $1; exit }'
}

resolve_http_target() {
	local url="$1"

	php -r '
		$parts = parse_url($argv[1]);
		if (! is_array($parts) || empty($parts["host"])) {
			fwrite(STDERR, "Could not parse site URL host.\n");
			exit(1);
		}

		$scheme = (string) ($parts["scheme"] ?? "http");
		$port = isset($parts["port"]) ? (int) $parts["port"] : ($scheme === "https" ? 443 : 80);
		echo (string) $parts["host"] . "|" . (string) $port;
	' "$url"
}

http_status() {
	local url="$1"
	local body_file="$2"
	local headers_file="$3"
	local target=""
	local host=""
	local port=""

	target="$(resolve_http_target "$url")"
	host="${target%%|*}"
	port="${target##*|}"

	curl -sS --resolve "${host}:${port}:127.0.0.1" -D "$headers_file" -o "$body_file" -w '%{http_code}' "$url"
}

assert_site_http_contract() {
	local label="$1"
	local site_url="$2"
	local body_file=""
	local headers_file=""
	local status=""
	local location=""

	body_file="$(mktemp)"
	headers_file="$(mktemp)"

	status="$(http_status "${site_url%/}/wp-login.php" "$body_file" "$headers_file")"
	if [[ "$status" == "200" ]]; then
		pass "${label} login resolves over HTTP"
	else
		fail "${label} login did not resolve over HTTP" "$(cat "$body_file")"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi

	status="$(http_status "${site_url%/}/wp-admin/" "$body_file" "$headers_file")"
	if [[ "$status" != "200" && "$status" != "302" ]]; then
		fail "${label} admin did not resolve over HTTP" "$(cat "$body_file")"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi

	if [[ "$status" == "302" ]]; then
		location="$(awk 'BEGIN{IGNORECASE=1} /^Location:/ {sub(/\r$/, "", $0); print substr($0, 11)}' "$headers_file" | tail -n 1)"
		if [[ "$location" == *"/wp-login.php"* ]]; then
			pass "${label} admin redirects into its login screen"
		else
			fail "${label} admin redirected somewhere unexpected" "$location"
			rm -f "$body_file" "$headers_file"
			exit 1
		fi
	else
		pass "${label} admin resolves over HTTP"
	fi

	rm -f "$body_file" "$headers_file"
}

prepare_network() {
	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
		start_wp_env
	)

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- bash -lc "perl -0pi -e \"s/^define\\( 'WP_SITEURL'.*\\n//mg; s/^define\\( 'WP_HOME'.*\\n//mg; s/^define\\( 'WP_ALLOW_MULTISITE'.*\\n//mg; s/^define\\( 'MULTISITE'.*\\n//mg; s/^define\\( 'SUBDOMAIN_INSTALL'.*\\n//mg; s/^\\\$base = '\\/'.*\\n//mg; s/^define\\( 'DOMAIN_CURRENT_SITE'.*\\n//mg; s/^define\\( 'PATH_CURRENT_SITE'.*\\n//mg; s/^define\\( 'SITE_ID_CURRENT_SITE'.*\\n//mg; s/^define\\( 'BLOG_ID_CURRENT_SITE'.*\\n//mg; s/^if \\( ! defined\\( 'RUDEL_WP_CONFIG_PATH'.*\\n//mg\" /var/www/html/wp-config.php" >/dev/null
	)

	wp_cli db reset --yes >/dev/null
	wp_cli core install \
		--url=localhost:8888 \
		--title='Rudel Test Host' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email >/dev/null
	wp_cli plugin activate rudel >/dev/null
	wp_cli core multisite-install \
		--url=localhost:8888 \
		--base=/ \
		--subdomains \
		--title='Rudel Test Network' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email \
		--skip-config >/dev/null
	wp_cli config set WP_ALLOW_MULTISITE true --raw >/dev/null
	wp_cli config set MULTISITE true --raw >/dev/null
	wp_cli config set SUBDOMAIN_INSTALL true --raw >/dev/null
	wp_cli config set DOMAIN_CURRENT_SITE "'localhost:8888'" --raw >/dev/null
	wp_cli config set PATH_CURRENT_SITE "'/'" --raw >/dev/null
	wp_cli config set SITE_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli config set BLOG_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli plugin activate rudel >/dev/null
}

echo -e "${BOLD}Rudel E2E: Subdomain Multisite${NC}"
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

echo -e "${BOLD}Prepare network${NC}"
prepare_network

if wp_cli core is-installed >/dev/null 2>&1; then
	pass "WordPress is installed"
else
	fail "WordPress install failed"
	exit 1
fi

ACTIVE_PLUGINS=$(wp_cli plugin list --status=active --format=csv --fields=name)
if echo "$ACTIVE_PLUGINS" | grep -q '^rudel$'; then
	pass "Rudel plugin is active"
else
	fail "Rudel plugin is not active" "$ACTIVE_PLUGINS"
	exit 1
fi

NETWORK_URL=$(wp_cli option get siteurl | tail -1)
if [[ "$NETWORK_URL" == "http://localhost:8888" ]]; then
	pass "Host site URL is configured"
else
	fail "Unexpected host site URL" "$NETWORK_URL"
fi

STATUS_OUTPUT=$(wp_cli rudel status)
if echo "$STATUS_OUTPUT" | grep -qi "installed" && echo "$STATUS_OUTPUT" | grep -qi "yes"; then
	pass "Runtime bootstrap is installed"
else
	fail "Runtime bootstrap status is unexpected" "$STATUS_OUTPUT"
fi

echo ""
echo -e "${BOLD}Sandbox lifecycle${NC}"

ALPHA_OUTPUT=$(wp_cli rudel create --name=alpha)
ALPHA_ID=$(parse_created_id "Sandbox created" "$ALPHA_OUTPUT")
if [[ -n "$ALPHA_ID" ]]; then
	SANDBOX_IDS+=("$ALPHA_ID")
	pass "Created sandbox ${ALPHA_ID}"
else
	fail "Sandbox creation failed" "$ALPHA_OUTPUT"
	exit 1
fi

ALPHA_URL=$(site_url_for_slug "$ALPHA_ID")
ALPHA_BLOG_ID=$(site_blog_id_for_slug "$ALPHA_ID")
if [[ -n "$ALPHA_URL" && -n "$ALPHA_BLOG_ID" ]]; then
	pass "Sandbox created a multisite site (${ALPHA_URL}, blog ${ALPHA_BLOG_ID})"
else
	fail "Sandbox multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

assert_site_http_contract "Sandbox site" "$ALPHA_URL"

site_cli "$ALPHA_URL" option update blogname "Alpha Site" >/dev/null
ALPHA_BLOGNAME=$(site_cli "$ALPHA_URL" option get blogname | tail -1)
if [[ "$ALPHA_BLOGNAME" == "Alpha Site" ]]; then
	pass "Sandbox site options update through its multisite URL"
else
	fail "Sandbox site options did not update" "$ALPHA_BLOGNAME"
fi

SNAPSHOT_OUTPUT=$(wp_cli rudel snapshot "$ALPHA_ID" --name=baseline)
if echo "$SNAPSHOT_OUTPUT" | grep -q "Snapshot created: baseline"; then
	pass "Snapshot creation works for sandbox sites"
else
	fail "Snapshot creation failed" "$SNAPSHOT_OUTPUT"
fi

site_cli "$ALPHA_URL" option update blogname "Alpha Changed" >/dev/null
wp_cli rudel restore "$ALPHA_ID" --snapshot=baseline --force >/dev/null
ALPHA_RESTORED=$(site_cli "$ALPHA_URL" option get blogname | tail -1)
if [[ "$ALPHA_RESTORED" == "Alpha Site" ]]; then
	pass "Snapshot restore returns the sandbox site to its prior state"
else
	fail "Snapshot restore did not restore sandbox state" "$ALPHA_RESTORED"
fi

TEMPLATE_NAME="starter-${ALPHA_ID}"
TEMPLATE_SAVE=$(wp_cli rudel template save "$ALPHA_ID" --name="$TEMPLATE_NAME")
if echo "$TEMPLATE_SAVE" | grep -q "Template saved: ${TEMPLATE_NAME}"; then
	pass "Template save works"
else
	fail "Template save failed" "$TEMPLATE_SAVE"
fi

TEMPLATE_LIST=$(wp_cli rudel template list --format=json)
if echo "$TEMPLATE_LIST" | grep -q "\"name\":\"${TEMPLATE_NAME}\""; then
	pass "Template list returns saved templates"
else
	fail "Template list missing saved template" "$TEMPLATE_LIST"
fi

echo ""
echo -e "${BOLD}App lifecycle${NC}"

APP_OUTPUT=$(wp_cli rudel app create --name=Demo --domain=demo.example.test --github=inline0/demo-theme --branch=main --dir=themes/demo-theme)
APP_ID=$(parse_created_id "App created" "$APP_OUTPUT")
if [[ -n "$APP_ID" ]]; then
	APP_IDS+=("$APP_ID")
	pass "Created app ${APP_ID}"
else
	fail "App creation failed" "$APP_OUTPUT"
	exit 1
fi

APP_URL=$(site_url_for_slug "$APP_ID")
APP_BLOG_ID=$(site_blog_id_for_slug "$APP_ID")
if [[ -n "$APP_URL" && -n "$APP_BLOG_ID" ]]; then
	pass "App created a multisite site (${APP_URL}, blog ${APP_BLOG_ID})"
else
	fail "App multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

assert_site_http_contract "App site" "$APP_URL"

site_cli "$APP_URL" option update blogname "Demo App" >/dev/null
APP_INFO_JSON=$(wp_cli rudel app info "$APP_ID" --format=json)
if echo "$APP_INFO_JSON" | grep -q "demo.example.test"; then
	pass "App metadata retains its configured domain"
else
	fail "App info missing configured domain" "$APP_INFO_JSON"
fi

if printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_github_repo":"inline0\/demo-theme"' && printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_github_branch":"main"' && printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_github_dir":"themes\/demo-theme"'; then
	pass "App metadata retains its tracked GitHub source"
else
	fail "App info missing tracked GitHub source" "$APP_INFO_JSON"
fi

BACKUP_OUTPUT=$(wp_cli rudel app backup "$APP_ID" --name=baseline)
if echo "$BACKUP_OUTPUT" | grep -q "App backup created: baseline"; then
	pass "App backup creation works"
else
	fail "App backup creation failed" "$BACKUP_OUTPUT"
fi

APP_BACKUPS=$(wp_cli rudel app backups "$APP_ID" --format=json)
if echo "$APP_BACKUPS" | grep -q '"name":"baseline"'; then
	pass "App backups list returns created backups"
else
	fail "App backups list missing baseline" "$APP_BACKUPS"
fi

FEATURE_OUTPUT=$(wp_cli rudel app create-sandbox "$APP_ID" --name="Feature Sandbox")
FEATURE_ID=$(parse_created_id "Sandbox created from app" "$FEATURE_OUTPUT")
if [[ -n "$FEATURE_ID" ]]; then
	SANDBOX_IDS+=("$FEATURE_ID")
	pass "Created app-derived sandbox ${FEATURE_ID}"
else
	fail "App-derived sandbox creation failed" "$FEATURE_OUTPUT"
	exit 1
fi

FEATURE_URL=$(site_url_for_slug "$FEATURE_ID")
if [[ -n "$FEATURE_URL" ]]; then
	pass "App-derived sandbox created a multisite site"
else
	fail "App-derived sandbox multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

assert_site_http_contract "App-derived sandbox site" "$FEATURE_URL"

FEATURE_INFO_JSON=$(wp_cli rudel info "$FEATURE_ID" --format=json)
if printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_github_repo":"inline0\/demo-theme"' && printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_github_branch":"main"' && printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_github_dir":"themes\/demo-theme"'; then
	pass "App-derived sandbox inherits tracked GitHub source"
else
	fail "App-derived sandbox missing tracked GitHub source" "$FEATURE_INFO_JSON"
fi

site_cli "$FEATURE_URL" option update blogname "Feature Deploy" >/dev/null

DEPLOY_PLAN=$(wp_cli rudel app deploy "$APP_ID" --from="$FEATURE_ID" --backup=before-deploy --dry-run)
if echo "$DEPLOY_PLAN" | grep -qi "$FEATURE_ID"; then
	pass "App deploy dry-run produces a plan"
else
	fail "App deploy dry-run did not produce the expected plan" "$DEPLOY_PLAN"
fi

DEPLOY_OUTPUT=$(wp_cli rudel app deploy "$APP_ID" --from="$FEATURE_ID" --backup=before-deploy --label="Feature Deploy" --notes="e2e deploy" --force)
if echo "$DEPLOY_OUTPUT" | grep -qi "deployed"; then
	pass "App deploy works"
else
	fail "App deploy failed" "$DEPLOY_OUTPUT"
fi

APP_DEPLOYED_NAME=$(site_cli "$APP_URL" option get blogname | tail -1)
if [[ "$APP_DEPLOYED_NAME" == "Feature Deploy" ]]; then
	pass "App deploy replaces multisite site state"
else
	fail "App deploy did not replace site state" "$APP_DEPLOYED_NAME"
fi

wp_cli rudel app restore "$APP_ID" --backup=baseline --force >/dev/null
APP_RESTORED_NAME=$(site_cli "$APP_URL" option get blogname | tail -1)
if [[ "$APP_RESTORED_NAME" == "Demo App" ]]; then
	pass "App restore returns the app site to its saved backup"
else
	fail "App restore did not restore site state" "$APP_RESTORED_NAME"
fi

APP_DEPLOYMENTS=$(wp_cli rudel app deployments "$APP_ID" --format=json)
if echo "$APP_DEPLOYMENTS" | grep -q '"deployed_at"'; then
	pass "App deployments are recorded"
else
	fail "App deployments list is empty" "$APP_DEPLOYMENTS"
fi

echo ""
echo "==========================================="
if [[ "$FAILED" -eq 0 ]]; then
	echo -e "${GREEN}${BOLD}All ${TOTAL} tests passed!${NC}"
else
	echo -e "${RED}${BOLD}${FAILED} of ${TOTAL} tests failed${NC}"
	exit 1
fi
