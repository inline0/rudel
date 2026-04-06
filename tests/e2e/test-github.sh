#!/usr/bin/env bash
#
# E2E Test: GitHub-backed Rudel workflow
#
# Proves the live GitHub workflow against temporary repositories and a real
# WordPress multisite network running in @wordpress/env.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEST_TMPDIR="$(mktemp -d)"
RUN_ID="$(date +%s)-$$"
OWNER="${RUDEL_GITHUB_E2E_OWNER:-inline0}"
THEME_REPO_NAME="rudel-test-1-${RUN_ID}"
PLUGIN_REPO_NAME="rudel-test-2-${RUN_ID}"
THEME_REPO="${OWNER}/${THEME_REPO_NAME}"
PLUGIN_REPO="${OWNER}/${PLUGIN_REPO_NAME}"
THEME_RELEASE_BRANCH="release-${RUN_ID}"
PASSED=0
FAILED=0
TOTAL=0
SANDBOX_IDS=()
APP_IDS=()
REPOS=()
NETWORK_WP_CONTENT_DIR=""
NETWORK_URL=""
RUDEL_VERSION_VALUE="$(
	php -r '
		$contents = (string) file_get_contents($argv[1]);
		if (preg_match("/define\\(\\s*\\x27RUDEL_VERSION\\x27,\\s*\\x27([^\\x27]+)\\x27\\s*\\)/", $contents, $matches)) {
			echo $matches[1];
		}
	' "${RUDEL_DIR}/rudel.php"
)"

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

if [[ -f "${RUDEL_DIR}/.env.local" ]]; then
	set -a
	# shellcheck disable=SC1091
	. "${RUDEL_DIR}/.env.local"
	set +a
fi

GITHUB_TOKEN="${RUDEL_GITHUB_TOKEN:-${GH_TOKEN:-${DIVINE_GITHUB_PAT:-}}}"
if [[ -z "$GITHUB_TOKEN" ]] && command -v gh >/dev/null 2>&1; then
	GITHUB_TOKEN="$(gh auth token 2>/dev/null || echo "")"
fi

if [[ -z "$GITHUB_TOKEN" ]]; then
	echo "No GitHub token available, skipping GitHub E2E tests"
	exit 0
fi

export RUDEL_GITHUB_TOKEN="$GITHUB_TOKEN"
export GH_TOKEN="$GITHUB_TOKEN"

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

	if (( ${#REPOS[@]} > 0 )); then
		for repo in "${REPOS[@]}"; do
			gh api -X DELETE "repos/${repo}" >/dev/null 2>&1 || true
		done
	fi

	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
	)

	rm -rf "$TEST_TMPDIR"
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

run_php() {
	local code="$1"

	php -r "
		require '${RUDEL_DIR}/vendor/autoload.php';
		define( 'RUDEL_GITHUB_TOKEN', '${GITHUB_TOKEN}' );
		define( 'RUDEL_VERSION', '${RUDEL_VERSION_VALUE}' );
		${code}
	" 2>&1
}

parse_created_id() {
	local pattern="$1"
	local output="$2"

	echo "$output" | grep -oE "${pattern}: [^ ]+" | sed "s/${pattern}: //"
}

parse_pr_number() {
	local output="$1"

	echo "$output" | grep -oE 'PR #[0-9]+' | head -n 1 | sed 's/PR #//'
}

json_get() {
	local path="$1"

	php -r '
		$data = json_decode( stream_get_contents( STDIN ), true );
		if ( is_array( $data ) && array_is_list( $data ) && 1 === count( $data ) ) {
			$data = $data[0];
		}
		$value = $data;
		foreach ( explode( ".", $argv[1] ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				$value = null;
				break;
			}
			$value = $value[ $segment ];
		}
		if ( is_array( $value ) ) {
			echo json_encode( $value );
		} elseif ( null !== $value ) {
			echo (string) $value;
		}
	' "$path"
}

environment_json() {
	local id="$1"

	wp_cli rudel info "$id" --format=json
}

environment_path() {
	local id="$1"

	environment_json "$id" | json_get path
}

site_url_for_slug() {
	local slug="$1"
	local site_row=""

	site_row="$(wp_cli site list --fields=domain,path --format=csv \
		| awk -F, -v slug="$slug" 'NR > 1 && $1 ~ ("^" slug "\\.") { print $1 "," $2; exit }')"

	if [[ -z "$site_row" || -z "$NETWORK_URL" ]]; then
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

	if [[ -z "$NETWORK_URL" ]]; then
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

ensure_pr_merged() {
	local repo="$1"
	local pr_number="$2"

	gh pr merge "$pr_number" --repo "$repo" --merge --delete-branch >/dev/null
	sleep 2
}

repo_branch_exists() {
	local repo="$1"
	local branch="$2"

	gh api "repos/${repo}/git/ref/heads/${branch}" >/dev/null 2>&1
}

prepare_network() {
	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
		start_wp_env
	)

	(
		cd "$RUDEL_DIR"
		npx wp-env run cli -- bash -lc "perl -0pi -e \"s/^define\\( 'WP_SITEURL'.*\\n//mg; s/^define\\( 'WP_HOME'.*\\n//mg; s/^define\\( 'WP_ALLOW_MULTISITE'.*\\n//mg; s/^define\\( 'MULTISITE'.*\\n//mg; s/^define\\( 'SUBDOMAIN_INSTALL'.*\\n//mg; s/^\\\$base = '\\/'.*\\n//mg; s/^define\\( 'DOMAIN_CURRENT_SITE'.*\\n//mg; s/^define\\( 'PATH_CURRENT_SITE'.*\\n//mg; s/^define\\( 'SITE_ID_CURRENT_SITE'.*\\n//mg; s/^define\\( 'BLOG_ID_CURRENT_SITE'.*\\n//mg; s/^define\\( 'RUDEL_GITHUB_TOKEN'.*\\n//mg; s/^if \\( ! defined\\( 'RUDEL_WP_CONFIG_PATH'.*\\n//mg\" /var/www/html/wp-config.php" >/dev/null
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
	wp_cli config set DOMAIN_CURRENT_SITE "'localhost'" --raw >/dev/null
	wp_cli config set PATH_CURRENT_SITE "'/'" --raw >/dev/null
	wp_cli config set SITE_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli config set BLOG_ID_CURRENT_SITE 1 --raw >/dev/null
	wp_cli db query "UPDATE wp_site SET domain = 'localhost'; UPDATE wp_blogs SET domain = REPLACE(domain, ':8888', '');" >/dev/null
	wp_cli config set RUDEL_GITHUB_TOKEN "$GITHUB_TOKEN" >/dev/null
	wp_cli plugin activate rudel >/dev/null
	NETWORK_WP_CONTENT_DIR="$(wp_cli eval 'echo WP_CONTENT_DIR;')"
	NETWORK_URL="$(wp_cli option get siteurl | tail -1)"
}

prepare_git_repo() {
	local dir="$1"

	git init "$dir" >/dev/null 2>&1
	git -C "$dir" config user.email "dev@inline0.test"
	git -C "$dir" config user.name "Rudel E2E"
	git -C "$dir" add -A
	git -C "$dir" commit -m "Initial commit" >/dev/null 2>&1
	git -C "$dir" branch -M main >/dev/null 2>&1
}

create_remote_repo() {
	local full_repo="$1"
	local dir="$2"
	local owner="${full_repo%%/*}"
	local name="${full_repo#*/}"
	local attempt=0

	if gh api "orgs/${owner}" >/dev/null 2>&1; then
		gh api -X POST "orgs/${owner}/repos" -f name="${name}" -F private=true >/dev/null
	else
		gh api -X POST "user/repos" -f name="${name}" -F private=true >/dev/null
	fi

	for attempt in 1 2 3 4 5 6 7 8 9 10; do
		if gh api "repos/${full_repo}" >/dev/null 2>&1; then
			break
		fi
		sleep 1
	done

	gh api "repos/${full_repo}" >/dev/null 2>&1

	git -C "$dir" remote add origin "https://github.com/${full_repo}.git"

	for attempt in 1 2 3 4 5; do
		if git -C "$dir" push -u origin main; then
			REPOS+=("$full_repo")
			return
		fi
		sleep 2
	done

	return 1
}

create_theme_repo() {
	local dir="$1"

	mkdir -p "$dir"
	cat >"$dir/style.css" <<EOF
/*
Theme Name: Rudel E2E Theme ${RUN_ID}
Version: 1.0.0
*/
EOF
	cat >"$dir/index.php" <<'EOF'
<?php
echo '<main>Rudel E2E Theme</main>';
EOF
	cat >"$dir/functions.php" <<'EOF'
<?php
add_action( 'init', static function (): void {
	// Boot marker for Rudel E2E theme.
} );
EOF
	prepare_git_repo "$dir"
	create_remote_repo "$THEME_REPO" "$dir"

	git -C "$dir" checkout -b "$THEME_RELEASE_BRANCH" >/dev/null 2>&1
	echo "/* Release branch marker */" >> "$dir/style.css"
	git -C "$dir" add -A
	git -C "$dir" commit -m "Create release branch" >/dev/null 2>&1
	git -C "$dir" push -u origin "$THEME_RELEASE_BRANCH" >/dev/null 2>&1
	git -C "$dir" checkout main >/dev/null 2>&1
}

create_plugin_repo() {
	local dir="$1"

	mkdir -p "$dir"
	cat >"$dir/${PLUGIN_REPO_NAME}.php" <<EOF
<?php
/**
 * Plugin Name: Rudel E2E Plugin ${RUN_ID}
 */
EOF
	cat >"$dir/readme.txt" <<'EOF'
Rudel E2E plugin fixture.
EOF
	prepare_git_repo "$dir"
	create_remote_repo "$PLUGIN_REPO" "$dir"
}

echo -e "${BOLD}Rudel E2E: GitHub Workflow${NC}"
echo "==========================================="
echo ""

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
	echo "Docker not available, skipping GitHub workflow tests"
	exit 0
fi

if ! command -v gh >/dev/null 2>&1; then
	echo "gh CLI not available, skipping GitHub workflow tests"
	exit 0
fi

gh auth setup-git >/dev/null 2>&1 || true

if [[ ! -d "$RUDEL_DIR/node_modules/@wordpress/env" ]]; then
	(
		cd "$RUDEL_DIR"
		npm install >/dev/null
	)
fi

THEME_DIR="$TEST_TMPDIR/theme"
PLUGIN_DIR="$TEST_TMPDIR/plugin"

echo -e "${BOLD}Provision temporary repositories${NC}"
echo "  creating ${THEME_REPO}"
create_theme_repo "$THEME_DIR"
echo "  creating ${PLUGIN_REPO}"
create_plugin_repo "$PLUGIN_DIR"

if gh api "repos/${THEME_REPO}" >/dev/null 2>&1; then
	pass "Created theme repository ${THEME_REPO}"
else
	fail "Theme repository was not created"
	exit 1
fi

if gh api "repos/${PLUGIN_REPO}" >/dev/null 2>&1; then
	pass "Created plugin repository ${PLUGIN_REPO}"
else
	fail "Plugin repository was not created"
	exit 1
fi

DEFAULT_BRANCH=$(run_php "
	\$gh = new Rudel\\GitHubIntegration('${THEME_REPO}');
	echo \$gh->get_default_branch();
")
if [[ "$DEFAULT_BRANCH" == "main" ]]; then
	pass "GitHub client resolves default branch"
else
	fail "Unexpected default branch" "$DEFAULT_BRANCH"
fi

echo ""
echo -e "${BOLD}Prepare multisite network${NC}"
prepare_network

STATUS_OUTPUT="$(wp_cli rudel status)"
if echo "$STATUS_OUTPUT" | grep -qi "installed" && echo "$STATUS_OUTPUT" | grep -qi "yes"; then
	pass "Runtime bootstrap is installed"
else
	fail "Runtime bootstrap status is unexpected" "$STATUS_OUTPUT"
	exit 1
fi

echo ""
echo -e "${BOLD}GitHub sandbox creation${NC}"

THEME_SANDBOX_OUTPUT="$(wp_cli rudel create --github="${THEME_REPO}")"
THEME_SANDBOX_ID="$(parse_created_id "Sandbox created" "$THEME_SANDBOX_OUTPUT")"
if [[ -n "$THEME_SANDBOX_ID" ]]; then
	SANDBOX_IDS+=("$THEME_SANDBOX_ID")
	pass "Created theme sandbox ${THEME_SANDBOX_ID}"
else
	fail "Theme sandbox creation failed" "$THEME_SANDBOX_OUTPUT"
	exit 1
fi

THEME_SANDBOX_DIR="${NETWORK_WP_CONTENT_DIR}/themes/${THEME_REPO_NAME}"
if wp_shell "test -f '${THEME_SANDBOX_DIR}/style.css' && test -f '${THEME_SANDBOX_DIR}/index.php'" >/dev/null; then
	pass "Theme repository downloaded into shared network themes"
else
	fail "Theme repository files are missing" "${THEME_SANDBOX_DIR}
${THEME_SANDBOX_OUTPUT}"
fi

THEME_INFO_JSON="$(environment_json "$THEME_SANDBOX_ID")"
if [[ "$(printf '%s' "$THEME_INFO_JSON" | json_get clone_source.github_repo)" == "$THEME_REPO" && "$(printf '%s' "$THEME_INFO_JSON" | json_get clone_source.github_dir)" == "themes/${THEME_REPO_NAME}" ]]; then
	pass "Theme sandbox stores GitHub repository metadata"
else
	fail "Theme sandbox GitHub repository metadata missing" "${THEME_INFO_JSON}
${THEME_SANDBOX_OUTPUT}"
fi

THEME_SANDBOX_URL="$(site_url_for_slug "$THEME_SANDBOX_ID")"
if [[ -n "$THEME_SANDBOX_URL" ]]; then
	pass "Theme sandbox created a multisite site"
	assert_site_http_contract "Theme sandbox site" "$THEME_SANDBOX_URL"
else
	fail "Theme sandbox multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

PLUGIN_SANDBOX_OUTPUT="$(wp_cli rudel create --github="${PLUGIN_REPO}" --type=plugin)"
PLUGIN_SANDBOX_ID="$(parse_created_id "Sandbox created" "$PLUGIN_SANDBOX_OUTPUT")"
if [[ -n "$PLUGIN_SANDBOX_ID" ]]; then
	SANDBOX_IDS+=("$PLUGIN_SANDBOX_ID")
	pass "Created plugin sandbox ${PLUGIN_SANDBOX_ID}"
else
	fail "Plugin sandbox creation failed" "$PLUGIN_SANDBOX_OUTPUT"
	exit 1
fi

PLUGIN_SANDBOX_DIR="${NETWORK_WP_CONTENT_DIR}/plugins/${PLUGIN_REPO_NAME}"
if wp_shell "test -f '${PLUGIN_SANDBOX_DIR}/${PLUGIN_REPO_NAME}.php'" >/dev/null; then
	pass "Plugin repository downloaded into shared network plugins"
else
	fail "Plugin repository files are missing" "${PLUGIN_SANDBOX_DIR}
${PLUGIN_SANDBOX_OUTPUT}"
fi

PLUGIN_SANDBOX_URL="$(site_url_for_slug "$PLUGIN_SANDBOX_ID")"
if [[ -n "$PLUGIN_SANDBOX_URL" ]]; then
	pass "Plugin sandbox created a multisite site"
	assert_site_http_contract "Plugin sandbox site" "$PLUGIN_SANDBOX_URL"
else
	fail "Plugin sandbox multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

echo ""
echo -e "${BOLD}App tracking setup${NC}"

APP_DOMAIN="client-${RUN_ID}.example.test"
APP_OUTPUT="$(wp_cli rudel app create --name=client-demo --domain="${APP_DOMAIN}" --clone-from="$THEME_SANDBOX_ID" --github="${THEME_REPO}" --branch="${THEME_RELEASE_BRANCH}" --dir="themes/${THEME_REPO_NAME}")"
APP_ID="$(parse_created_id "App created" "$APP_OUTPUT")"
if [[ -n "$APP_ID" ]]; then
	APP_IDS+=("$APP_ID")
	pass "Created app ${APP_ID} with tracked GitHub metadata"
else
	fail "App creation failed" "$APP_OUTPUT"
	exit 1
fi

APP_INFO_JSON="$(wp_cli rudel app info "$APP_ID" --format=json)"
if [[ "$(printf '%s' "$APP_INFO_JSON" | json_get tracked_github_repo)" == "$THEME_REPO" && "$(printf '%s' "$APP_INFO_JSON" | json_get tracked_github_branch)" == "$THEME_RELEASE_BRANCH" ]]; then
	pass "App stores tracked GitHub repository and branch"
else
	fail "App GitHub tracking metadata is incorrect" "$APP_INFO_JSON"
fi

APP_URL="$(site_url_for_domain "$APP_DOMAIN")"
if [[ -n "$APP_URL" ]]; then
	pass "GitHub-backed app created a multisite site"
	assert_site_http_contract "GitHub-backed app site" "$APP_URL"
else
	fail "GitHub-backed app multisite site was not created" "$(wp_cli site list --fields=blog_id,url --format=table)"
	exit 1
fi

echo ""
echo -e "${BOLD}Sandbox push and PR workflow${NC}"

wp_shell "printf '%s\n' '/* Sandbox change */' >> '${THEME_SANDBOX_DIR}/style.css' && printf '%s\n' 'sandbox-push' > '${THEME_SANDBOX_DIR}/sandbox-push.txt'" >/dev/null

PUSH_OUTPUT="$(wp_cli rudel push "$THEME_SANDBOX_ID" --message="Sandbox GitHub E2E push")"
if echo "$PUSH_OUTPUT" | grep -q "Pushed to rudel/${THEME_SANDBOX_ID}"; then
	pass "Sandbox push uploads changes without explicit repo flags"
else
	fail "Sandbox push failed" "$PUSH_OUTPUT"
	exit 1
fi

VERIFY_DIR_ONE="${TEST_TMPDIR}/verify-sandbox"
mkdir -p "$VERIFY_DIR_ONE"
run_php "
	\$gh = new Rudel\\GitHubIntegration('${THEME_REPO}');
	\$gh->download('rudel/${THEME_SANDBOX_ID}', '${VERIFY_DIR_ONE}');
" >/dev/null

if [[ -f "${VERIFY_DIR_ONE}/sandbox-push.txt" ]]; then
	pass "Sandbox push is visible in the GitHub branch"
else
	fail "Sandbox push changes are missing from the GitHub branch"
fi

PR_OUTPUT="$(wp_cli rudel pr "$THEME_SANDBOX_ID" --title="Rudel E2E sandbox PR" --body="Sandbox workflow E2E")"
THEME_PR_NUMBER="$(parse_pr_number "$PR_OUTPUT")"
if [[ -n "$THEME_PR_NUMBER" ]]; then
	pass "Sandbox PR created"
else
	fail "Sandbox PR creation failed" "$PR_OUTPUT"
	exit 1
fi

THEME_PR_BASE="$(gh api "repos/${THEME_REPO}/pulls/${THEME_PR_NUMBER}" --jq '.base.ref')"
if [[ "$THEME_PR_BASE" == "main" ]]; then
	pass "Sandbox PR targets the repository default branch"
else
	fail "Sandbox PR targeted the wrong base branch" "$THEME_PR_BASE"
fi

ensure_pr_merged "$THEME_REPO" "$THEME_PR_NUMBER"
MERGED_CLEANUP_OUTPUT="$(wp_cli rudel cleanup --merged)"
LIST_OUTPUT="$(wp_cli rudel list --format=csv)"
if ! echo "$LIST_OUTPUT" | grep -q "$THEME_SANDBOX_ID"; then
	pass "Merged cleanup removes the merged GitHub sandbox"
else
	fail "Merged cleanup did not remove the merged GitHub sandbox" "$MERGED_CLEANUP_OUTPUT"
fi

echo ""
echo -e "${BOLD}App-derived GitHub workflow${NC}"

DERIVED_OUTPUT="$(wp_cli rudel app create-sandbox "$APP_ID" --name="Client Demo Feature")"
DERIVED_ID="$(parse_created_id "Sandbox created from app" "$DERIVED_OUTPUT")"
if [[ -n "$DERIVED_ID" ]]; then
	SANDBOX_IDS+=("$DERIVED_ID")
	pass "Created app-derived sandbox ${DERIVED_ID}"
else
	fail "App-derived sandbox creation failed" "$DERIVED_OUTPUT"
	exit 1
fi

DERIVED_INFO_JSON="$(environment_json "$DERIVED_ID")"
if [[ "$(printf '%s' "$DERIVED_INFO_JSON" | json_get tracked_github_repo)" == "$THEME_REPO" && "$(printf '%s' "$DERIVED_INFO_JSON" | json_get tracked_github_branch)" == "$THEME_RELEASE_BRANCH" && "$(printf '%s' "$DERIVED_INFO_JSON" | json_get tracked_github_dir)" == "themes/${THEME_REPO_NAME}" ]]; then
	pass "App-derived sandbox inherits tracked GitHub metadata"
else
	fail "App-derived sandbox did not inherit tracked GitHub metadata" "$DERIVED_INFO_JSON"
fi

DERIVED_THEME_DIR="${NETWORK_WP_CONTENT_DIR}/themes/${THEME_REPO_NAME}"
if wp_shell "test -d '${DERIVED_THEME_DIR}'" >/dev/null; then
	pass "App-derived sandbox uses the tracked shared theme directory"
else
	fail "Tracked theme directory is missing from app-derived sandbox" "$DERIVED_THEME_DIR"
	exit 1
fi

wp_shell "printf '%s\n' 'derived-push' > '${DERIVED_THEME_DIR}/derived-push.txt'" >/dev/null

DERIVED_PUSH_OUTPUT="$(wp_cli rudel push "$DERIVED_ID" --message="Derived sandbox GitHub E2E push")"
if echo "$DERIVED_PUSH_OUTPUT" | grep -q "Pushed to rudel/${DERIVED_ID}"; then
	pass "App-derived sandbox push uses tracked GitHub defaults"
else
	fail "App-derived sandbox push failed" "$DERIVED_PUSH_OUTPUT"
	exit 1
fi

DERIVED_PR_OUTPUT="$(wp_cli rudel pr "$DERIVED_ID" --title="Rudel E2E derived PR" --body="App-derived sandbox workflow E2E")"
DERIVED_PR_NUMBER="$(parse_pr_number "$DERIVED_PR_OUTPUT")"
if [[ -n "$DERIVED_PR_NUMBER" ]]; then
	pass "App-derived sandbox PR created"
else
	fail "App-derived sandbox PR creation failed" "$DERIVED_PR_OUTPUT"
	exit 1
fi

DERIVED_PR_BASE="$(gh api "repos/${THEME_REPO}/pulls/${DERIVED_PR_NUMBER}" --jq '.base.ref')"
if [[ "$DERIVED_PR_BASE" == "$THEME_RELEASE_BRANCH" ]]; then
	pass "App-derived sandbox PR targets the tracked base branch"
else
	fail "App-derived sandbox PR targeted the wrong base branch" "$DERIVED_PR_BASE"
fi

ensure_pr_merged "$THEME_REPO" "$DERIVED_PR_NUMBER"
SECOND_CLEANUP_OUTPUT="$(wp_cli rudel cleanup --merged)"
LIST_OUTPUT_AFTER="$(wp_cli rudel list --format=csv)"
if ! echo "$LIST_OUTPUT_AFTER" | grep -q "$DERIVED_ID"; then
	pass "Merged cleanup removes the merged app-derived sandbox"
else
	fail "Merged cleanup did not remove the merged app-derived sandbox" "$SECOND_CLEANUP_OUTPUT"
fi

echo ""
echo "==========================================="
if [[ $FAILED -eq 0 ]]; then
	echo -e "${GREEN}${BOLD}All $TOTAL tests passed!${NC}"
	exit 0
else
	echo -e "${RED}${BOLD}$FAILED of $TOTAL tests failed${NC}"
	exit 1
fi
