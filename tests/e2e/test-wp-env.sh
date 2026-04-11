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

git_worktree_count() {
	php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		if (is_array($data) && array_is_list($data) && 1 === count($data) && is_array($data[0])) {
			$data = $data[0];
		}
		$worktrees = $data["clone_source"]["git_worktrees"] ?? null;
		echo is_array($worktrees) ? (string) count($worktrees) : "0";
	'
}

first_git_worktree_metadata_name() {
	php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		if (is_array($data) && array_is_list($data) && 1 === count($data) && is_array($data[0])) {
			$data = $data[0];
		}
		$metadata = $data["clone_source"]["git_worktrees"][0]["metadata_name"] ?? "";
		if (is_scalar($metadata)) {
			echo (string) $metadata;
		}
	'
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
	local cli_url=""
	shift

	cli_url="$(
		php -r '
			$parts = parse_url($argv[1]);
			if (! is_array($parts) || empty($parts["host"])) {
				fwrite(STDERR, "Could not parse WP-CLI site URL.\n");
				exit(1);
			}

			$scheme = (string) ($parts["scheme"] ?? "http");
			$host = (string) $parts["host"];
			$path = (string) ($parts["path"] ?? "/");
			$port = isset($parts["port"]) ? (int) $parts["port"] : null;

			$url = $scheme . "://" . $host;
			if (null !== $port && in_array($port, [80, 443], true)) {
				$url .= ":" . $port;
			}

			if ("" === $path) {
				$path = "/";
			}
			if (! str_starts_with($path, "/")) {
				$path = "/" . $path;
			}

			echo rtrim($url, "/") . rtrim($path, "/") . "/";
		' "$url"
	)"

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- wp --url="$cli_url" "$@" 2>&1
	) | strip_wpenv
}

parse_created_id() {
	local pattern="$1"
	local output="$2"

	echo "$output" | grep -oE "${pattern}: [^ ]+" | sed "s/${pattern}: //"
}

site_url_for_slug() {
	local slug="$1"
	local site_row=""

	site_row="$(wp_cli site list --fields=domain,path --format=csv \
		| awk -F, -v slug="$slug" 'NR > 1 && $1 ~ ("^" slug "\\.") { print $1 "," $2; exit }')"

	if [[ -z "$site_row" || -z "${NETWORK_URL:-}" ]]; then
		return 0
	fi

	php -r '
		[$domain, $path] = array_pad(explode(",", $argv[1], 2), 2, "/");
		$network = parse_url($argv[2]);
		if (! is_array($network) || empty($network["scheme"]) || empty($network["host"])) {
			exit(1);
		}

		$path = "" === trim($path) ? "/" : trim($path);
		if (! str_starts_with($path, "/")) {
			$path = "/" . $path;
		}

		$url = (string) $network["scheme"] . "://" . (string) $domain;
		if (isset($network["port"])) {
			$url .= ":" . (int) $network["port"];
		}

		echo rtrim($url, "/") . rtrim($path, "/") . "/";
	' "$site_row" "$NETWORK_URL"
}

site_url_for_domain() {
	local domain="$1"

	if [[ -z "${NETWORK_URL:-}" ]]; then
		return 0
	fi

	php -r '
		$network = parse_url($argv[2]);
		if (! is_array($network) || empty($network["scheme"]) || empty($network["host"])) {
			exit(1);
		}

		$url = (string) $network["scheme"] . "://" . (string) $argv[1];
		if (isset($network["port"])) {
			$url .= ":" . (int) $network["port"];
		}

		echo rtrim($url, "/") . "/";
	' "$domain" "$NETWORK_URL"
}

cli_admin_url_for_site() {
	local site_url="$1"

	php -r '
		$parts = parse_url($argv[1]);
		if (! is_array($parts) || empty($parts["scheme"]) || empty($parts["host"])) {
			exit(1);
		}

		$path = (string) ($parts["path"] ?? "/");
		if ("" === $path) {
			$path = "/";
		}
		if (! str_starts_with($path, "/")) {
			$path = "/" . $path;
		}

		echo (string) $parts["scheme"] . "://" . (string) $parts["host"] . rtrim($path, "/") . "/wp-admin/";
	' "$site_url"
}

environment_json() {
	wp_cli rudel info "$1" --format=json
}

environment_path() {
	environment_json "$1" | json_field path
}

app_json() {
	wp_cli rudel app info "$1" --format=json
}

app_path() {
	app_json "$1" | json_field path
}

site_blog_id_for_slug() {
	local slug="$1"

	wp_cli site list --fields=blog_id,url --format=csv \
		| awk -F, -v slug="$slug" 'NR > 1 && $2 ~ ("^http://" slug "\\.") { print $1; exit }'
}

site_blog_id_for_domain() {
	local domain="$1"

	wp_cli site list --fields=blog_id,url --format=csv \
		| awk -F, -v domain="$domain" 'NR > 1 && $2 ~ ("^http://" domain "(:[0-9]+)?/?$") { print $1; exit }'
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

redirect_location() {
	local headers_file="$1"

	awk 'BEGIN{IGNORECASE=1} /^Location:/ {sub(/\r$/, "", $0); print substr($0, 11)}' "$headers_file" | tail -n 1
}

assert_site_http_contract() {
	local label="$1"
	local site_url="$2"
	local body_file=""
	local headers_file=""
	local status=""
	local location=""
	local attempts=0
	local max_attempts=10

	body_file="$(mktemp)"
	headers_file="$(mktemp)"

	while (( attempts < max_attempts )); do
		attempts=$((attempts + 1))
		status="$(http_status "${site_url%/}/" "$body_file" "$headers_file")"
		location="$(redirect_location "$headers_file")"

		if [[ "$location" == *"/wp-signup.php"* ]]; then
			fail "${label} root redirected into signup unexpectedly" "$location"
			rm -f "$body_file" "$headers_file"
			exit 1
		fi

		if [[ "$status" == "200" ]]; then
			pass "${label} root resolves over HTTP"
			break
		fi

		if [[ ( "$status" == "301" || "$status" == "302" ) && "$location" == "${site_url%/}/" ]]; then
			pass "${label} root redirects to its canonical URL"
			break
		fi

		if (( attempts >= max_attempts )); then
			fail "${label} root did not resolve over HTTP" "Status: ${status}\nLocation: ${location}\n$(cat "$body_file")"
			rm -f "$body_file" "$headers_file"
			exit 1
		fi

		sleep 1
	done

	status="$(http_status "${site_url%/}/wp-login.php" "$body_file" "$headers_file")"
	location="$(redirect_location "$headers_file")"
	if [[ "$status" == "200" ]]; then
		pass "${label} login resolves over HTTP"
	else
		fail "${label} login did not resolve over HTTP" "$(cat "$body_file")"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi
	if [[ "$location" == *"/wp-signup.php"* ]]; then
		fail "${label} login redirected into signup unexpectedly" "$location"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi

	status="$(http_status "${site_url%/}/wp-admin/" "$body_file" "$headers_file")"
	location="$(redirect_location "$headers_file")"
	if [[ "$status" != "200" && "$status" != "302" ]]; then
		fail "${label} admin did not resolve over HTTP" "$(cat "$body_file")"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi

	if [[ "$status" == "302" ]]; then
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

	status="$(http_status "${site_url%/}/wp-includes/css/buttons.min.css" "$body_file" "$headers_file")"
	location="$(redirect_location "$headers_file")"
	if [[ "$status" == "200" ]]; then
		pass "${label} core asset resolves over HTTP"
	else
		fail "${label} core asset did not resolve over HTTP" "$(cat "$body_file")"
		rm -f "$body_file" "$headers_file"
		exit 1
	fi
	if [[ "$location" == *"/wp-signup.php"* ]]; then
		fail "${label} core asset redirected into signup unexpectedly" "$location"
		rm -f "$body_file" "$headers_file"
		exit 1
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
		--url=localhost:8000 \
		--title='Rudel Test Host' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email >/dev/null
	wp_cli plugin activate rudel >/dev/null
	wp_cli core multisite-install \
		--url=localhost:8000 \
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
	wp_cli config set DOMAIN_CURRENT_SITE "'localhost'" --raw >/dev/null
	wp_cli config set PATH_CURRENT_SITE "'/'" --raw >/dev/null
	wp_cli config set SITE_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli config set BLOG_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli db query "UPDATE wp_site SET domain = 'localhost'; UPDATE wp_blogs SET domain = REPLACE(domain, ':8000', '');" >/dev/null
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
if [[ "$NETWORK_URL" == "http://localhost:8000" ]]; then
	pass "Host site URL is configured"
else
	fail "Unexpected host site URL" "$NETWORK_URL"
fi

assert_site_http_contract "Host site" "http://localhost:8000"

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

ALPHA_INFO_JSON=$(environment_json "$ALPHA_ID")
ALPHA_PATH=$(printf '%s' "$ALPHA_INFO_JSON" | json_field path)
ALPHA_WP_CONTENT=$(printf '%s' "$ALPHA_INFO_JSON" | json_field wp_content)
if [[ "$ALPHA_WP_CONTENT" == "${ALPHA_PATH}/wp-content" ]]; then
	pass "Sandbox info reports its environment-local wp-content path"
else
	fail "Sandbox info reported the wrong wp-content path" "$ALPHA_INFO_JSON"
fi

site_cli "$ALPHA_URL" option update blogname "Alpha Site" >/dev/null
ALPHA_BLOGNAME=$(site_cli "$ALPHA_URL" option get blogname | tail -1)
if [[ "$ALPHA_BLOGNAME" == "Alpha Site" ]]; then
	pass "Sandbox site options update through its multisite URL"
else
	fail "Sandbox site options did not update" "$ALPHA_BLOGNAME"
fi

site_cli "$ALPHA_URL" user create alphaauthor alpha-author@example.test --role=author --user_pass=secret >/dev/null
if site_cli "$ALPHA_URL" user get alphaauthor --field=ID >/dev/null 2>&1; then
	pass "Sandbox can create isolated users inside its own runtime"
else
	fail "Sandbox user creation failed" "$ALPHA_URL"
fi

if wp_cli user get alphaauthor --field=ID >/dev/null 2>&1; then
	fail "Sandbox user leaked into the host user table" "alphaauthor"
else
	pass "Sandbox users do not leak back into the host site"
fi

SNAPSHOT_OUTPUT=$(wp_cli rudel snapshot "$ALPHA_ID" --name=baseline)
if echo "$SNAPSHOT_OUTPUT" | grep -q "Snapshot created: baseline"; then
	pass "Snapshot creation works for sandbox sites"
else
	fail "Snapshot creation failed" "$SNAPSHOT_OUTPUT"
fi

site_cli "$ALPHA_URL" option update blogname "Alpha Changed" >/dev/null
site_cli "$ALPHA_URL" user create alphatemp alpha-temp@example.test --role=subscriber --user_pass=secret >/dev/null
wp_cli rudel restore "$ALPHA_ID" --snapshot=baseline --force >/dev/null
ALPHA_RESTORED=$(site_cli "$ALPHA_URL" option get blogname | tail -1)
if [[ "$ALPHA_RESTORED" == "Alpha Site" ]]; then
	pass "Snapshot restore returns the sandbox site to its prior state"
else
	fail "Snapshot restore did not restore sandbox state" "$ALPHA_RESTORED"
fi

if site_cli "$ALPHA_URL" user get alphaauthor --field=ID >/dev/null 2>&1 && ! site_cli "$ALPHA_URL" user get alphatemp --field=ID >/dev/null 2>&1; then
	pass "Snapshot restore also restores isolated sandbox users"
else
	fail "Snapshot restore did not restore isolated sandbox users" "$(site_cli "$ALPHA_URL" user list --fields=user_login --format=csv | tail -n +2)"
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
echo -e "${BOLD}Shared plugins and uploads${NC}"

wp_shell "mkdir -p /var/www/html/wp-content/plugins/shared-demo /var/www/html/wp-content/uploads/2026/04 && printf '<?php\n' > /var/www/html/wp-content/plugins/shared-demo/shared-demo.php && printf 'shared upload' > /var/www/html/wp-content/uploads/2026/04/shared.txt" >/dev/null

SHARED_OUTPUT=$(wp_cli rudel create --name=shared-content --shared-plugins --shared-uploads)
SHARED_ID=$(parse_created_id "Sandbox created" "$SHARED_OUTPUT")
if [[ -n "$SHARED_ID" ]]; then
	SANDBOX_IDS+=("$SHARED_ID")
	pass "Created sandbox ${SHARED_ID} with shared plugins/uploads"
else
	fail "Shared sandbox creation failed" "$SHARED_OUTPUT"
	exit 1
fi

SHARED_INFO_JSON=$(environment_json "$SHARED_ID")
SHARED_PATH=$(printf '%s' "$SHARED_INFO_JSON" | json_field path)
if printf '%s' "$SHARED_INFO_JSON" | grep -Fq '"shared_plugins":true' && printf '%s' "$SHARED_INFO_JSON" | grep -Fq '"shared_uploads":true'; then
	pass "Sandbox info reports shared plugins/uploads metadata"
else
	fail "Sandbox info did not report shared plugins/uploads metadata" "$SHARED_INFO_JSON"
fi

if wp_shell "[[ -L '${SHARED_PATH}/wp-content/plugins' && -L '${SHARED_PATH}/wp-content/uploads' ]]" >/dev/null 2>&1; then
	pass "Shared sandbox links plugins and uploads into its local wp-content"
else
	fail "Shared sandbox did not create plugins/uploads links" "$SHARED_PATH"
fi

if wp_shell "[[ -f '${SHARED_PATH}/wp-content/plugins/shared-demo/shared-demo.php' && -f '${SHARED_PATH}/wp-content/uploads/2026/04/shared.txt' ]]" >/dev/null 2>&1; then
	pass "Shared sandbox sees host plugin and upload content through those links"
else
	fail "Shared sandbox could not see host plugin/upload content" "$SHARED_PATH"
fi

echo ""
echo -e "${BOLD}App lifecycle${NC}"

APP_DOMAIN="demo.example.test"
APP_OUTPUT=$(wp_cli rudel app create --name=Demo --domain="$APP_DOMAIN" --git=https://example.test/demo-theme.git --branch=main --dir=themes/demo-theme)
APP_ID=$(parse_created_id "App created" "$APP_OUTPUT")
if [[ -n "$APP_ID" ]]; then
	APP_IDS+=("$APP_ID")
	pass "Created app ${APP_ID}"
else
	fail "App creation failed" "$APP_OUTPUT"
	exit 1
fi

APP_URL=$(site_url_for_domain "$APP_DOMAIN")
APP_BLOG_ID=$(site_blog_id_for_domain "$APP_DOMAIN")
if [[ -n "$APP_URL" && -n "$APP_BLOG_ID" ]]; then
	pass "App created a multisite site (${APP_URL}, blog ${APP_BLOG_ID})"
else
	fail "App multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

assert_site_http_contract "App site" "$APP_URL"

site_cli "$APP_URL" option update blogname "Demo App" >/dev/null
site_cli "$APP_URL" user create appauthor app-author@example.test --role=author --user_pass=secret >/dev/null
if site_cli "$APP_URL" user get appauthor --field=ID >/dev/null 2>&1; then
	pass "App can create isolated users inside its own runtime"
else
	fail "App user creation failed" "$APP_URL"
fi

if wp_cli user get appauthor --field=ID >/dev/null 2>&1; then
	fail "App user leaked into the host user table" "appauthor"
else
	pass "App users do not leak back into the host site"
fi

APP_INFO_JSON=$(wp_cli rudel app info "$APP_ID" --format=json)
if echo "$APP_INFO_JSON" | grep -q "demo.example.test"; then
	pass "App metadata retains its configured domain"
else
	fail "App info missing configured domain" "$APP_INFO_JSON"
fi

APP_CANONICAL_URL=$(site_url_for_domain "$APP_DOMAIN")
if [[ "$(printf '%s' "$APP_INFO_JSON" | json_field url)" == "$APP_CANONICAL_URL" ]]; then
	pass "App info reports the canonical app domain"
else
	fail "App info did not report the canonical app domain" "$APP_INFO_JSON"
fi

if printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_git_remote":"https:\/\/example.test\/demo-theme.git"' && printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_git_branch":"main"' && printf '%s' "$APP_INFO_JSON" | grep -Fq '"tracked_git_dir":"themes\/demo-theme"'; then
	pass "App metadata retains its tracked Git source"
else
	fail "App info missing tracked Git source" "$APP_INFO_JSON"
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

if site_cli "$FEATURE_URL" user get appauthor --field=ID >/dev/null 2>&1; then
	pass "App-derived sandboxes clone the app isolated users"
else
	fail "App-derived sandbox did not clone the app isolated users" "$(site_cli "$FEATURE_URL" user list --fields=user_login --format=csv | tail -n +2)"
fi

FEATURE_INFO_JSON=$(wp_cli rudel info "$FEATURE_ID" --format=json)
if printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_git_remote":"https:\/\/example.test\/demo-theme.git"' && printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_git_branch":"main"' && printf '%s' "$FEATURE_INFO_JSON" | grep -Fq '"tracked_git_dir":"themes\/demo-theme"'; then
	pass "App-derived sandbox inherits tracked Git source"
else
	fail "App-derived sandbox missing tracked Git source" "$FEATURE_INFO_JSON"
fi

FEATURE_PATH=$(printf '%s' "$FEATURE_INFO_JSON" | json_field path)
FEATURE_WP_CONTENT=$(printf '%s' "$FEATURE_INFO_JSON" | json_field wp_content)
if [[ "$FEATURE_WP_CONTENT" == "${FEATURE_PATH}/wp-content" ]]; then
	pass "App-derived sandbox info reports its environment-local wp-content path"
else
	fail "App-derived sandbox info reported the wrong wp-content path" "$FEATURE_INFO_JSON"
fi

site_cli "$FEATURE_URL" option update blogname "Feature Deploy" >/dev/null
site_cli "$FEATURE_URL" user create featureonly feature-only@example.test --role=editor --user_pass=secret >/dev/null

if site_cli "$APP_URL" user get featureonly --field=ID >/dev/null 2>&1; then
	fail "Sandbox-only user appeared in the app before deploy" "featureonly"
else
	pass "Sandbox-only users stay isolated before deploy"
fi

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

if site_cli "$APP_URL" user get featureonly --field=ID >/dev/null 2>&1; then
	pass "App deploy replaces isolated app users with the sandbox users"
else
	fail "App deploy did not replace isolated app users" "$(site_cli "$APP_URL" user list --fields=user_login --format=csv | tail -n +2)"
fi

wp_cli rudel app restore "$APP_ID" --backup=baseline --force >/dev/null
APP_RESTORED_NAME=$(site_cli "$APP_URL" option get blogname | tail -1)
if [[ "$APP_RESTORED_NAME" == "Demo App" ]]; then
	pass "App restore returns the app site to its saved backup"
else
	fail "App restore did not restore site state" "$APP_RESTORED_NAME"
fi

if site_cli "$APP_URL" user get appauthor --field=ID >/dev/null 2>&1 && ! site_cli "$APP_URL" user get featureonly --field=ID >/dev/null 2>&1; then
	pass "App restore also restores the saved isolated user set"
else
	fail "App restore did not restore the isolated app users" "$(site_cli "$APP_URL" user list --fields=user_login --format=csv | tail -n +2)"
fi

APP_DEPLOYMENTS=$(wp_cli rudel app deployments "$APP_ID" --format=json)
if echo "$APP_DEPLOYMENTS" | grep -q '"deployed_at"'; then
	pass "App deployments are recorded"
else
	fail "App deployments list is empty" "$APP_DEPLOYMENTS"
fi

SHARED_APP_DOMAIN="shared.example.test"
SHARED_APP_OUTPUT=$(wp_cli rudel app create --name="Shared App" --domain="$SHARED_APP_DOMAIN" --shared-plugins --shared-uploads)
SHARED_APP_ID=$(parse_created_id "App created" "$SHARED_APP_OUTPUT")
if [[ -n "$SHARED_APP_ID" ]]; then
	APP_IDS+=("$SHARED_APP_ID")
	pass "Created app ${SHARED_APP_ID} with shared plugins/uploads"
else
	fail "Shared app creation failed" "$SHARED_APP_OUTPUT"
	exit 1
fi

SHARED_APP_INFO_JSON=$(wp_cli rudel app info "$SHARED_APP_ID" --format=json)
if printf '%s' "$SHARED_APP_INFO_JSON" | grep -Fq '"shared_plugins":true' && printf '%s' "$SHARED_APP_INFO_JSON" | grep -Fq '"shared_uploads":true'; then
	pass "App info reports shared plugins/uploads metadata"
else
	fail "App info did not report shared plugins/uploads metadata" "$SHARED_APP_INFO_JSON"
fi

SHARED_FEATURE_OUTPUT=$(wp_cli rudel app create-sandbox "$SHARED_APP_ID" --name="Shared App Feature")
SHARED_FEATURE_ID=$(parse_created_id "Sandbox created from app" "$SHARED_FEATURE_OUTPUT")
if [[ -n "$SHARED_FEATURE_ID" ]]; then
	SANDBOX_IDS+=("$SHARED_FEATURE_ID")
	pass "Created shared app-derived sandbox ${SHARED_FEATURE_ID}"
else
	fail "Shared app-derived sandbox creation failed" "$SHARED_FEATURE_OUTPUT"
	exit 1
fi

SHARED_FEATURE_INFO_JSON=$(environment_json "$SHARED_FEATURE_ID")
SHARED_FEATURE_PATH=$(printf '%s' "$SHARED_FEATURE_INFO_JSON" | json_field path)
if printf '%s' "$SHARED_FEATURE_INFO_JSON" | grep -Fq '"shared_plugins":true' && printf '%s' "$SHARED_FEATURE_INFO_JSON" | grep -Fq '"shared_uploads":true'; then
	pass "App-derived sandbox inherits shared plugins/uploads metadata"
else
	fail "App-derived sandbox did not inherit shared plugins/uploads metadata" "$SHARED_FEATURE_INFO_JSON"
fi

if wp_shell "[[ -L '${SHARED_FEATURE_PATH}/wp-content/plugins' && -L '${SHARED_FEATURE_PATH}/wp-content/uploads' ]]" >/dev/null 2>&1; then
	pass "App-derived shared sandbox links plugins and uploads into its local wp-content"
else
	fail "App-derived shared sandbox did not create plugins/uploads links" "$SHARED_FEATURE_PATH"
fi

if wp_shell "[[ -f '${SHARED_FEATURE_PATH}/wp-content/plugins/shared-demo/shared-demo.php' && -f '${SHARED_FEATURE_PATH}/wp-content/uploads/2026/04/shared.txt' ]]" >/dev/null 2>&1; then
	pass "App-derived shared sandbox sees host plugin and upload content"
else
	fail "App-derived shared sandbox could not see host plugin/upload content" "$SHARED_FEATURE_PATH"
fi

echo ""
echo -e "${BOLD}Multisite admin URLs${NC}"

SIBLING_DOMAIN="sibling.example.test"
SIBLING_OUTPUT=$(wp_cli rudel app create --name="Sibling App" --domain="$SIBLING_DOMAIN")
SIBLING_ID=$(parse_created_id "App created" "$SIBLING_OUTPUT")
if [[ -n "$SIBLING_ID" ]]; then
	APP_IDS+=("$SIBLING_ID")
	pass "Created sibling app ${SIBLING_ID}"
else
	fail "Sibling app creation failed" "$SIBLING_OUTPUT"
	exit 1
fi

SIBLING_URL=$(site_url_for_domain "$SIBLING_DOMAIN")
SIBLING_BLOG_ID=$(site_blog_id_for_domain "$SIBLING_DOMAIN")
if [[ -n "$SIBLING_URL" && -n "$SIBLING_BLOG_ID" ]]; then
	pass "Sibling app created a multisite site"
else
	fail "Sibling app multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

URL_RESOLUTION_SCRIPT=$(cat <<PHP
echo wp_json_encode(
	array(
		1 => get_admin_url( 1 ),
		${APP_BLOG_ID} => get_admin_url( ${APP_BLOG_ID} ),
		${SIBLING_BLOG_ID} => get_admin_url( ${SIBLING_BLOG_ID} ),
	)
);
PHP
)
URL_RESOLUTION_JSON=$(site_cli "$APP_URL" eval "$URL_RESOLUTION_SCRIPT" | grep -E '^\{.*\}$' | tail -n 1)

ROOT_ADMIN_URL=$(cli_admin_url_for_site "$NETWORK_URL")
APP_ADMIN_URL=$(cli_admin_url_for_site "$APP_URL")
SIBLING_ADMIN_URL=$(cli_admin_url_for_site "$SIBLING_URL")

ROOT_MENU_URL=$(printf '%s' "$URL_RESOLUTION_JSON" | json_field 1)
APP_MENU_URL=$(printf '%s' "$URL_RESOLUTION_JSON" | json_field "$APP_BLOG_ID")
SIBLING_MENU_URL=$(printf '%s' "$URL_RESOLUTION_JSON" | json_field "$SIBLING_BLOG_ID")

if [[ "$ROOT_MENU_URL" == "$ROOT_ADMIN_URL" && "$APP_MENU_URL" == "$APP_ADMIN_URL" && "$SIBLING_MENU_URL" == "$SIBLING_ADMIN_URL" && "$ROOT_MENU_URL" != "$APP_MENU_URL" && "$ROOT_MENU_URL" != "$SIBLING_MENU_URL" && "$APP_MENU_URL" != "$SIBLING_MENU_URL" ]]; then
	pass "Per-blog admin URLs stay distinct for root, current app, and sibling app"
else
	fail "Per-blog admin URLs collapsed to the current app" "expected root=${ROOT_ADMIN_URL} app=${APP_ADMIN_URL} sibling=${SIBLING_ADMIN_URL}; got root=${ROOT_MENU_URL:-<missing>} app=${APP_MENU_URL:-<missing>} sibling=${SIBLING_MENU_URL:-<missing>}; full=${URL_RESOLUTION_JSON}"
	exit 1
fi

echo ""
echo -e "${BOLD}Local git-backed deploy deletion${NC}"

LOCAL_APP_DOMAIN="git-demo.example.test"
LOCAL_APP_OUTPUT=$(wp_cli rudel app create --name="Git Demo" --domain="$LOCAL_APP_DOMAIN")
LOCAL_APP_ID=$(parse_created_id "App created" "$LOCAL_APP_OUTPUT")
if [[ -n "$LOCAL_APP_ID" ]]; then
	APP_IDS+=("$LOCAL_APP_ID")
	pass "Created git-backed app ${LOCAL_APP_ID}"
else
	fail "Git-backed app creation failed" "$LOCAL_APP_OUTPUT"
	exit 1
fi

LOCAL_APP_PATH=$(app_path "$LOCAL_APP_ID")
if [[ -z "$LOCAL_APP_PATH" ]]; then
	fail "Could not resolve the git-backed app path" "$(app_json "$LOCAL_APP_ID")"
	exit 1
fi

LOCAL_APP_THEME_DIR="${LOCAL_APP_PATH}/wp-content/themes/local-theme"
LOCAL_GIT_SCRIPT=$(cat <<PHP
\$path = '${LOCAL_APP_THEME_DIR}';
if (! is_dir(\$path) && ! mkdir(\$path, 0755, true) && ! is_dir(\$path)) {
	fwrite(STDERR, 'mkdir failed');
	exit(1);
}
file_put_contents(\$path . '/style.css', "body { color: red; }\n");
\$repo = \Pitmaster\Pitmaster::init(\$path);
\$repo->config()->set('user.email', 'test@example.test');
\$repo->config()->set('user.name', 'Test User');
\$repo->add('style.css');
\$repo->commit('init');
echo 'ready';
PHP
)
if wp_cli eval "$LOCAL_GIT_SCRIPT" | grep -q "ready"; then
	pass "Prepared a local git-backed theme inside the app"
else
	fail "Could not prepare a local git-backed theme inside the app" "$LOCAL_APP_THEME_DIR"
	exit 1
fi

LOCAL_FEATURE_OUTPUT=$(wp_cli rudel app create-sandbox "$LOCAL_APP_ID" --name="Git Demo Feature")
LOCAL_FEATURE_ID=$(parse_created_id "Sandbox created from app" "$LOCAL_FEATURE_OUTPUT")
if [[ -n "$LOCAL_FEATURE_ID" ]]; then
	SANDBOX_IDS+=("$LOCAL_FEATURE_ID")
	pass "Created git-backed app-derived sandbox ${LOCAL_FEATURE_ID}"
else
	fail "Git-backed app-derived sandbox creation failed" "$LOCAL_FEATURE_OUTPUT"
	exit 1
fi

LOCAL_FEATURE_PATH=$(environment_path "$LOCAL_FEATURE_ID")
if [[ -z "$LOCAL_FEATURE_PATH" ]]; then
	fail "Could not resolve the git-backed sandbox path" "$(environment_json "$LOCAL_FEATURE_ID")"
	exit 1
fi

LOCAL_FEATURE_THEME_DIR="${LOCAL_FEATURE_PATH}/wp-content/themes/local-theme"
if wp_shell "test -e '${LOCAL_FEATURE_THEME_DIR}/.git'" >/dev/null; then
	pass "Git-backed app-derived sandbox keeps its local theme worktree"
else
	fail "Git-backed app-derived sandbox did not create a local theme worktree" "$LOCAL_FEATURE_THEME_DIR"
	exit 1
fi

LOCAL_FEATURE_INFO_JSON=$(environment_json "$LOCAL_FEATURE_ID")
LOCAL_FEATURE_WORKTREE_COUNT=$(printf '%s' "$LOCAL_FEATURE_INFO_JSON" | git_worktree_count)
LOCAL_FEATURE_METADATA_NAME=$(printf '%s' "$LOCAL_FEATURE_INFO_JSON" | first_git_worktree_metadata_name)
if [[ "$LOCAL_FEATURE_WORKTREE_COUNT" -ge 1 && -n "$LOCAL_FEATURE_METADATA_NAME" ]]; then
	pass "Git-backed app-derived sandbox records explicit worktree metadata"
else
	fail "Git-backed app-derived sandbox did not record explicit worktree metadata" "$LOCAL_FEATURE_INFO_JSON"
	exit 1
fi

LOCAL_SECOND_OUTPUT=$(wp_cli rudel app create-sandbox "$LOCAL_APP_ID" --name="Git Demo Feature Two")
LOCAL_SECOND_ID=$(parse_created_id "Sandbox created from app" "$LOCAL_SECOND_OUTPUT")
if [[ -n "$LOCAL_SECOND_ID" ]]; then
	SANDBOX_IDS+=("$LOCAL_SECOND_ID")
	pass "Created second git-backed app-derived sandbox ${LOCAL_SECOND_ID}"
else
	fail "Second git-backed app-derived sandbox creation failed" "$LOCAL_SECOND_OUTPUT"
	exit 1
fi

LOCAL_SECOND_INFO_JSON=$(environment_json "$LOCAL_SECOND_ID")
LOCAL_SECOND_METADATA_NAME=$(printf '%s' "$LOCAL_SECOND_INFO_JSON" | first_git_worktree_metadata_name)
if [[ -n "$LOCAL_SECOND_METADATA_NAME" && "$LOCAL_SECOND_METADATA_NAME" != "$LOCAL_FEATURE_METADATA_NAME" ]]; then
	pass "Concurrent sandboxes use distinct linked-worktree metadata names"
else
	fail "Concurrent sandboxes collided on linked-worktree metadata" "$LOCAL_SECOND_INFO_JSON"
	exit 1
fi

wp_cli rudel destroy "$LOCAL_FEATURE_ID" --force >/dev/null

LOCAL_THIRD_OUTPUT=$(wp_cli rudel app create-sandbox "$LOCAL_APP_ID" --name="Git Demo Feature Three")
LOCAL_THIRD_ID=$(parse_created_id "Sandbox created from app" "$LOCAL_THIRD_OUTPUT")
if [[ -n "$LOCAL_THIRD_ID" ]]; then
	SANDBOX_IDS+=("$LOCAL_THIRD_ID")
	pass "Created replacement git-backed app-derived sandbox ${LOCAL_THIRD_ID}"
else
	fail "Replacement git-backed app-derived sandbox creation failed" "$LOCAL_THIRD_OUTPUT"
	exit 1
fi

LOCAL_THIRD_INFO_JSON=$(environment_json "$LOCAL_THIRD_ID")
LOCAL_THIRD_METADATA_NAME=$(printf '%s' "$LOCAL_THIRD_INFO_JSON" | first_git_worktree_metadata_name)
if [[ -n "$LOCAL_THIRD_METADATA_NAME" && "$LOCAL_THIRD_METADATA_NAME" != "$LOCAL_SECOND_METADATA_NAME" ]]; then
	pass "Repeated create/destroy/create cycles do not collide on worktree metadata"
else
	fail "Replacement sandbox reused a colliding worktree metadata name" "$LOCAL_THIRD_INFO_JSON"
	exit 1
fi

LOCAL_THIRD_PATH=$(environment_path "$LOCAL_THIRD_ID")
LOCAL_THIRD_THEME_DIR="${LOCAL_THIRD_PATH}/wp-content/themes/local-theme"
wp_shell "rm -rf '${LOCAL_THIRD_THEME_DIR}'" >/dev/null
LOCAL_DEPLOY_OUTPUT=$(wp_cli rudel app deploy "$LOCAL_APP_ID" --from="$LOCAL_THIRD_ID" --backup=git-delete --force)
if echo "$LOCAL_DEPLOY_OUTPUT" | grep -qi "deployed"; then
	pass "Deploy after tracked theme removal works"
else
	fail "Deploy after tracked theme removal failed" "$LOCAL_DEPLOY_OUTPUT"
	exit 1
fi

if wp_shell "test ! -e '${LOCAL_APP_THEME_DIR}'" >/dev/null; then
	pass "Deploy removes tracked theme directories that no longer exist in the app source sandbox"
else
	fail "Deploy left a removed tracked theme behind in the app" "$LOCAL_APP_THEME_DIR"
fi

echo ""
echo "==========================================="
if [[ "$FAILED" -eq 0 ]]; then
	echo -e "${GREEN}${BOLD}All ${TOTAL} tests passed!${NC}"
else
	echo -e "${RED}${BOLD}${FAILED} of ${TOTAL} tests failed${NC}"
	exit 1
fi
