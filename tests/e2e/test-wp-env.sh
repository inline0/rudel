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
GIT_FIXTURE_DIR=""

GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

cleanup() {
	if [[ -n "$GIT_FIXTURE_DIR" ]]; then
		rm -rf "$GIT_FIXTURE_DIR"
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

wp_cli eval '(new \Rudel\ConfigWriter())->protect_backups();' >/dev/null
CONFIG_BACKUP=$(wp_cli eval 'echo basename(glob(ABSPATH . "wp-config.php.rudel-backup-*.php")[0]);' | tail -1)
CONFIG_STATUS=$(curl -sS "http://localhost:8000/${CONFIG_BACKUP}" -o /dev/null -w '%{http_code}')
if [[ "$CONFIG_STATUS" == "403" || "$CONFIG_STATUS" == "404" ]]; then
	pass "WordPress configuration backups reject public HTTP access"
else
	fail "WordPress configuration backup is publicly downloadable" "$CONFIG_STATUS"
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

if ! ALPHA_UPDATE_OUTPUT=$(wp_env_cli "$ALPHA_ID" option update blogname "Alpha Site"); then
	ALPHA_DEBUG_OUTPUT=$(wp_env_cli "$ALPHA_ID" --debug=bootstrap option get blogname 2>&1 || true)
	fail "Alpha CLI environment option update failed" "${ALPHA_UPDATE_OUTPUT}
${ALPHA_DEBUG_OUTPUT}"
	exit 1
fi

if ! BETA_UPDATE_OUTPUT=$(wp_env_cli "$BETA_ID" option update blogname "Beta Site"); then
	BETA_DEBUG_OUTPUT=$(wp_env_cli "$BETA_ID" --debug=bootstrap option get blogname 2>&1 || true)
	fail "Beta CLI environment option update failed" "${BETA_UPDATE_OUTPUT}
${BETA_DEBUG_OUTPUT}"
	exit 1
fi

ALPHA_BLOGNAME=$(wp_env_cli "$ALPHA_ID" option get blogname | tail -1)
BETA_BLOGNAME=$(wp_env_cli "$BETA_ID" option get blogname | tail -1)
HOST_BLOGNAME=$(wp_cli option get blogname | tail -1)
if [[ "$ALPHA_BLOGNAME" == "Alpha Site" && "$BETA_BLOGNAME" == "Beta Site" && "$HOST_BLOGNAME" == "Rudel Host" ]]; then
	pass "CLI environment selection isolates option state"
else
	fail "CLI environment selection leaked option state" "alpha=${ALPHA_BLOGNAME} beta=${BETA_BLOGNAME} host=${HOST_BLOGNAME}"
	exit 1
fi

DIRECTORY_BLOGNAME=$(wp_shell "cd $(printf '%q' "$ALPHA_PATH") && wp option get blogname" | tail -1)
EXPLICIT_BLOGNAME=$(wp_shell "cd $(printf '%q' "$ALPHA_PATH") && RUDEL_ENVIRONMENT=$(printf '%q' "$BETA_ID") wp option get blogname" | tail -1)
if [[ "$DIRECTORY_BLOGNAME" == "Alpha Site" && "$EXPLICIT_BLOGNAME" == "Beta Site" ]]; then
	pass "Generated sandbox WP-CLI config selects its DB and preserves an explicit selector"
else
	fail "Generated sandbox WP-CLI selector failed" "directory=${DIRECTORY_BLOGNAME} explicit=${EXPLICIT_BLOGNAME}"
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

GIT_FIXTURE_DIR=$(mktemp -d "$RUDEL_DIR/tests/e2e/git-fixtures.XXXXXX")
for repo_name in git-theme shared-demo; do
	SOURCE_DIR="$GIT_FIXTURE_DIR/${repo_name}-source"
	mkdir "$SOURCE_DIR"
	git -C "$SOURCE_DIR" init -q --initial-branch=main
	git -C "$SOURCE_DIR" config user.name Test
	git -C "$SOURCE_DIR" config user.email test@example.test
	if [[ "$repo_name" == "git-theme" ]]; then
		printf '/*\nTheme Name: Git Theme\n*/\n' > "$SOURCE_DIR/style.css"
		printf '<?php echo "GitThemeMarker";\n' > "$SOURCE_DIR/index.php"
	else
		cat > "$SOURCE_DIR/shared-demo.php" <<'PHP'
<?php
/* Plugin Name: Shared Demo */
$rudel_git_plugin_global = 'GitPluginMarker';
add_filter( 'option_blogname', function ( $name ) {
	return $GLOBALS['rudel_git_plugin_global'];
} );
PHP
		printf 'GitPluginAssetMarker' > "$SOURCE_DIR/asset.txt"
	fi
	git -C "$SOURCE_DIR" add .
	git -C "$SOURCE_DIR" commit -qm initial
	git clone -q --bare "$SOURCE_DIR" "$GIT_FIXTURE_DIR/${repo_name}.git"
	git --git-dir="$GIT_FIXTURE_DIR/${repo_name}.git" update-server-info
done
GIT_BASE_URL="http://wordpress/wp-content/plugins/rudel/tests/e2e/$(basename "$GIT_FIXTURE_DIR")"
GIT_THEME_OUTPUT=$(wp_cli rudel create --name=git-theme --git="${GIT_BASE_URL}/git-theme.git" --theme=git-theme)
GIT_THEME_ID=$(parse_created_id "Sandbox created" "$GIT_THEME_OUTPUT")
if [[ -z "$GIT_THEME_ID" ]]; then
	fail "Git theme sandbox creation failed" "$GIT_THEME_OUTPUT"
	exit 1
fi
SANDBOX_IDS+=("$GIT_THEME_ID")
GIT_BODY="$(mktemp)"
GIT_STATUS=$(overlay_http_status "$GIT_THEME_ID" "$GIT_BODY")
if [[ "$GIT_STATUS" == "200" ]] && grep -q GitThemeMarker "$GIT_BODY" && [[ "$(environment_json "$GIT_THEME_ID" | json_field theme_slug)" == "git-theme" ]]; then
	pass "Git-created sandbox serves its previously uninstalled theme"
else
	fail "Git-created sandbox did not serve its checkout" "status=${GIT_STATUS} slug=$(environment_json "$GIT_THEME_ID" | json_field theme_slug) body=$(head -c 600 "$GIT_BODY") ${GIT_THEME_OUTPUT} debug=$(wp_env_cli "$GIT_THEME_ID" eval 'echo json_encode(array("stylesheet"=>get_stylesheet(),"template"=>get_template(),"directory"=>get_template_directory(),"index"=>file_get_contents(get_template_directory()."/index.php")));')"
	exit 1
fi
wp_cli rudel destroy "$GIT_THEME_ID" --force >/dev/null
GIT_PLUGIN_OUTPUT=$(wp_cli rudel create --name=git-plugin --git="${GIT_BASE_URL}/shared-demo.git" --type=plugin)
GIT_PLUGIN_ID=$(parse_created_id "Sandbox created" "$GIT_PLUGIN_OUTPUT")
if [[ -z "$GIT_PLUGIN_ID" ]]; then
	fail "Git plugin sandbox creation failed" "$GIT_PLUGIN_OUTPUT"
	exit 1
fi
SANDBOX_IDS+=("$GIT_PLUGIN_ID")
GIT_STATUS=$(overlay_http_status "$GIT_PLUGIN_ID" "$GIT_BODY")
if [[ "$GIT_STATUS" == "200" ]] && grep -q GitPluginMarker "$GIT_BODY" && wp_env_cli "$GIT_PLUGIN_ID" plugin is-active shared-demo >/dev/null 2>&1 && ! wp_cli plugin is-active shared-demo >/dev/null 2>&1; then
	pass "Git plugin checkout loads in global scope only in its selected sandbox"
else
	fail "Git plugin checkout was not loaded" "$GIT_PLUGIN_OUTPUT"
	exit 1
fi
PLUGIN_ASSET_URL=$(wp_env_cli "$GIT_PLUGIN_ID" eval 'echo plugins_url("asset.txt", RUDEL_GIT_PLUGIN_FILE), PHP_EOL;' | tail -1)
if [[ -z "$PLUGIN_ASSET_URL" ]]; then
	fail "Git plugin asset URL was empty"
	exit 1
fi
PLUGIN_ASSET=$(curl -sS "$PLUGIN_ASSET_URL")
if [[ "$PLUGIN_ASSET" == "GitPluginAssetMarker" ]]; then
	pass "Git plugin asset URLs serve checkout files instead of host plugin files"
else
	fail "Git plugin asset URL did not resolve to its checkout" "$PLUGIN_ASSET_URL"
	exit 1
fi
HOST_STATUS=$(curl -sS http://localhost:8000/ -o "$GIT_BODY" -w '%{http_code}')
if [[ "$HOST_STATUS" == "200" ]] && ! grep -q GitPluginMarker "$GIT_BODY"; then
	pass "Git plugin preview leaves host plugin execution unchanged"
else
	fail "Git plugin code leaked into the host"
	exit 1
fi
wp_cli rudel destroy "$GIT_PLUGIN_ID" --force >/dev/null
rm -f "$GIT_BODY"

wp_env_cli "$ALPHA_ID" option update blogname "TemplateSavedTitle" >/dev/null
wp_shell "cp '${ALPHA_PATH}/themes/rudel-host-theme/index.php' '${ALPHA_PATH}/tmp/template-original.php' && printf '\n<?php echo \"TemplateSavedMarker\"; error_log(\"TemplateHttpLogMarker\"); ?>\n' >> '${ALPHA_PATH}/themes/rudel-host-theme/index.php'" >/dev/null
wp_cli rudel template save "$ALPHA_ID" --name=point-in-time >/dev/null
TEMPLATE_PREFIX=$(wp_cli eval 'echo (new \Rudel\TemplateManager())->get("point-in-time")["table_prefix"];' | tail -1)
wp_env_cli "$ALPHA_ID" option update blogname "TemplateChangedTitle" >/dev/null
wp_shell "cp '${ALPHA_PATH}/tmp/template-original.php' '${ALPHA_PATH}/themes/rudel-host-theme/index.php'" >/dev/null
TEMPLATE_OUTPUT=$(wp_cli rudel create --name=template-copy --template=point-in-time)
TEMPLATE_ID=$(parse_created_id "Sandbox created" "$TEMPLATE_OUTPUT")
SANDBOX_IDS+=("$TEMPLATE_ID")
TEMPLATE_BODY=$(mktemp)
TEMPLATE_STATUS=$(overlay_http_status "$TEMPLATE_ID" "$TEMPLATE_BODY")
if [[ "$TEMPLATE_STATUS" == "200" ]] && grep -q TemplateSavedMarker "$TEMPLATE_BODY" && [[ "$(wp_env_cli "$TEMPLATE_ID" option get blogname | tail -1)" == "TemplateSavedTitle" ]] && [[ "$(wp_env_cli "$TEMPLATE_ID" eval 'echo current_user_can("manage_options") ? "admin" : "missing";' --user=admin | tail -1)" == "admin" ]]; then
	pass "Saved template restores point-in-time tables, theme files and administrator permissions"
else
	fail "Saved template did not restore its captured state" "$TEMPLATE_OUTPUT"
	exit 1
fi
MISSING_OUTPUT=$(wp_cli rudel create --name=missing-template --template=does-not-exist 2>&1 || true)
if echo "$MISSING_OUTPUT" | grep -q "Template not found"; then
	pass "Create rejects an unknown saved template"
else
	fail "Create accepted an unknown template" "$MISSING_OUTPUT"
	exit 1
fi
wp_cli rudel template delete point-in-time --force >/dev/null
TEMPLATE_TABLES=$(wp_cli db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${TEMPLATE_PREFIX}'))='${TEMPLATE_PREFIX}'" --skip-column-names | tail -1)
if [[ "$TEMPLATE_TABLES" == "0" && "$(wp_env_cli "$TEMPLATE_ID" option get blogname | tail -1)" == "TemplateSavedTitle" ]]; then
	pass "Template deletion removes saved tables and preserves created sandboxes"
else
	fail "Template deletion damaged a created sandbox or left saved tables"
	exit 1
fi
TEMPLATE_LOG=$(wp_cli rudel logs "$TEMPLATE_ID")
if echo "$TEMPLATE_LOG" | grep -q TemplateHttpLogMarker && ! echo "$TEMPLATE_LOG" | grep -q __halt_compiler; then
	pass "HTTP sandbox errors are logged privately and CLI output hides the PHP guard"
else
	fail "HTTP sandbox logging failed or exposed its guard" "$TEMPLATE_LOG"
	exit 1
fi
wp_cli rudel destroy "$TEMPLATE_ID" --force >/dev/null
wp_env_cli "$ALPHA_ID" option update blogname "Alpha Site" >/dev/null
rm -f "$TEMPLATE_BODY"

SNAPSHOT_OUTPUT=$(wp_cli rudel snapshot "$ALPHA_ID" --name=baseline)
if echo "$SNAPSHOT_OUTPUT" | grep -q "Snapshot created: baseline"; then
	pass "Snapshot creation works for overlay sandboxes"
else
	fail "Snapshot creation failed" "$SNAPSHOT_OUTPUT"
	exit 1
fi

PRIVATE_BODY=$(mktemp)
for private_path in "logs/debug.php" "snapshots/baseline/db_snapshot.json" "snapshots/baseline/themes/rudel-host-theme/style.css" "metadata/" "tmp/"; do
	PRIVATE_STATUS=$(curl -sS "http://localhost:8000/wp-content/rudel-environments/${ALPHA_ID}/${private_path}" -o "$PRIVATE_BODY" -w '%{http_code}')
	if [[ "$PRIVATE_STATUS" != "403" && "$PRIVATE_STATUS" != "404" ]]; then
		fail "Private sandbox files must not be downloadable" "${private_path}: ${PRIVATE_STATUS}"
		exit 1
	fi
done
if wp_cli eval "if (file_exists('${ALPHA_PATH}/snapshots/baseline/snapshot.json') || file_exists('${ALPHA_PATH}/snapshots/baseline/db_snapshot.json')) { exit(1); }" >/dev/null; then
	pass "Private logs and recovery files reject HTTP access; snapshot metadata stays in MySQL"
else
	fail "Snapshot metadata was written to a public JSON file"
	exit 1
fi
rm -f "$PRIVATE_BODY"

BASE_TABLE_COUNT=$(wp_cli db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${ALPHA_PREFIX}'))='${ALPHA_PREFIX}' AND table_name NOT LIKE '%snap_%'" --skip-column-names | tail -1)
for clone_option in "" "--clone-from=${ALPHA_ID}"; do
	CLONE_OUTPUT=$(wp_cli rudel create --name=regression-clone ${clone_option:+"$clone_option"})
	CLONE_ID=$(parse_created_id "Sandbox created" "$CLONE_OUTPUT")
	if [[ -z "$CLONE_ID" ]]; then
		fail "Regression clone creation failed" "$CLONE_OUTPUT"
		exit 1
	fi
	SANDBOX_IDS+=("$CLONE_ID")
	CLONE_PREFIX=$(environment_json "$CLONE_ID" | json_field table_prefix)
	CLONE_TABLE_COUNT=$(wp_cli db query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND LEFT(table_name,LENGTH('${CLONE_PREFIX}'))='${CLONE_PREFIX}'" --skip-column-names | tail -1)
	if [[ "$CLONE_TABLE_COUNT" == "$BASE_TABLE_COUNT" ]]; then
		pass "Clone ${clone_option:-from-host} excludes sibling and snapshot tables"
	else
		fail "Clone copied extra tables" "expected=${BASE_TABLE_COUNT} actual=${CLONE_TABLE_COUNT}"
		exit 1
	fi
	wp_cli rudel destroy "$CLONE_ID" --force >/dev/null
done

wp_env_cli "$ALPHA_ID" option update blogname "Alpha Changed" >/dev/null
wp_cli rudel restore "$ALPHA_ID" --snapshot=baseline --force >/dev/null
ALPHA_RESTORED=$(wp_env_cli "$ALPHA_ID" option get blogname | tail -1)
if [[ "$ALPHA_RESTORED" == "Alpha Site" ]]; then
	pass "Snapshot restore returns the sandbox DB state to its prior value"
else
	fail "Snapshot restore did not restore sandbox DB state" "$ALPHA_RESTORED"
	exit 1
fi

SNAPSHOT_DELETE_OUTPUT=$(wp_cli rudel snapshot delete "$ALPHA_ID" --name=baseline)
if echo "$SNAPSHOT_DELETE_OUTPUT" | grep -q "Snapshot deleted: baseline" && wp_shell "test ! -d '${ALPHA_PATH}/snapshots/baseline'" >/dev/null; then
	pass "Snapshot delete command removes its directory and tables"
else
	fail "Snapshot delete failed" "$SNAPSHOT_DELETE_OUTPUT"
	exit 1
fi

wp_cli config set WP_DEBUG false --raw >/dev/null
wp_cli config set WP_DEBUG_LOG false --raw >/dev/null
DEBUG_SETTINGS=$(wp_env_cli "$ALPHA_ID" eval 'error_log("RudelDebugProbe"); echo ini_get("display_errors") . ":" . ini_get("log_errors");' | tail -1)
if [[ "$DEBUG_SETTINGS" == "0:1" ]] && wp_shell "grep -q RudelDebugProbe '${ALPHA_PATH}/logs/debug.php'" >/dev/null; then
	pass "Sandbox PHP logging works with host debug constants disabled and display off"
else
	fail "Sandbox debug routing failed"
	exit 1
fi
HOST_SALT=$(wp_cli eval 'echo wp_salt("auth");' | tail -1)
ALPHA_SALT=$(wp_env_cli "$ALPHA_ID" eval 'echo wp_salt("auth");' | tail -1)
BETA_SALT=$(wp_env_cli "$BETA_ID" eval 'echo wp_salt("auth");' | tail -1)
if [[ "$HOST_SALT" != "$ALPHA_SALT" && "$ALPHA_SALT" != "$BETA_SALT" && "$HOST_SALT" != "$BETA_SALT" ]]; then
	pass "Effective auth salts differ between the host and each sandbox"
else
	fail "Environment auth salts were not isolated"
	exit 1
fi
HOST_COOKIE=$(wp_cli eval 'echo wp_generate_auth_cookie(1,time()+3600,"auth");' | tail -1)
HOST_COOKIE_VALID=$(wp_cli eval "var_export(wp_validate_auth_cookie('${HOST_COOKIE}', 'auth'));" | tail -1)
FOREIGN_COOKIE_VALID=$(wp_env_cli "$ALPHA_ID" eval "var_export(wp_validate_auth_cookie('${HOST_COOKIE}', 'auth'));" | tail -1)
if [[ "$HOST_COOKIE_VALID" == "1" && "$FOREIGN_COOKIE_VALID" == "false" ]]; then
	pass "A valid host auth cookie is rejected in a sandbox"
else
	fail "Host auth cookie isolation failed"
	exit 1
fi
wp_env_cli "$ALPHA_ID" plugin deactivate rudel >/dev/null
if ! wp_env_cli "$ALPHA_ID" plugin is-active rudel >/dev/null 2>&1 && [[ "$(wp_env_cli "$ALPHA_ID" option get blogname | tail -1)" == "Alpha Site" ]] && [[ "$(wp_env_cli "$BETA_ID" option get blogname | tail -1)" == "Beta Site" ]] && wp_cli plugin is-active rudel >/dev/null 2>&1; then
	pass "Sandbox deactivation preserves shared bootstrap and sibling selection"
else
	fail "Sandbox deactivation changed shared runtime state"
	exit 1
fi
BLOCKED_MAIL=$(wp_env_cli "$ALPHA_ID" eval 'var_export(wp_mail("nobody@example.test","DeactivatedSandboxProbe","blocked"));' | tail -1)
ALPHA_BODY="$(mktemp)"
ALPHA_STATUS=$(overlay_http_status "$ALPHA_ID" "$ALPHA_BODY")
if [[ "$BLOCKED_MAIL" == "true" && "$ALPHA_STATUS" == "200" ]] && grep -q rudel-host-theme "$ALPHA_BODY" && wp_shell "grep -q DeactivatedSandboxProbe '${ALPHA_PATH}/logs/debug.php'" >/dev/null; then
	pass "Inactive sandbox plugin still loads mail blocking and theme overlay protections"
else
	fail "Inactive sandbox plugin lost runtime protections"
	exit 1
fi
rm -f "$ALPHA_BODY"

COOKIE_JAR="$(mktemp)"
EXIT_BODY="$(mktemp)"
printf '# Netscape HTTP Cookie File\nlocalhost\tFALSE\t/\tFALSE\t0\trudel_environment\t%s\nlocalhost\tFALSE\t/\tFALSE\t0\trudel_sandbox\t%s\n' "$ALPHA_ID" "$ALPHA_ID" > "$COOKIE_JAR"
COOKIE_STATUS=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" http://localhost:8000/ -o "$EXIT_BODY" -w '%{http_code}')
if [[ "$COOKIE_STATUS" == "200" ]] && grep -q 'Alpha Site' "$EXIT_BODY"; then
	pass "Browser cookie jar selects the sandbox before exit"
else
	fail "Exit regression cookie setup failed"
	exit 1
fi
INVALID_EXIT_STATUS=$(curl -sS -b "$COOKIE_JAR" -c "$COOKIE_JAR" 'http://localhost:8000/?rudel_exit=1&_wpnonce=invalid' -o "$EXIT_BODY" -w '%{http_code}')
if [[ "$INVALID_EXIT_STATUS" == "403" ]] && grep -q rudel_environment "$COOKIE_JAR"; then
	pass "An invalid exit nonce preserves the sandbox selector"
else
	fail "Invalid exit request changed the cookie selector"
	exit 1
fi
EXIT_URL=$(wp_env_cli "$ALPHA_ID" eval 'echo \Rudel\Rudel::exit_url();' | tail -1)
EXIT_STATUS=$(curl -sS -L -b "$COOKIE_JAR" -c "$COOKIE_JAR" "$EXIT_URL" -o "$EXIT_BODY" -w '%{http_code}')
if [[ "$EXIT_STATUS" == "200" ]] && grep -q 'Rudel Host' "$EXIT_BODY" && ! grep -q rudel_environment "$COOKIE_JAR" && ! grep -q rudel_sandbox "$COOKIE_JAR"; then
	pass "Exit link expires current and legacy selectors and returns to the host"
else
	fail "Exit link did not return the browser to the host"
	exit 1
fi
rm -f "$COOKIE_JAR" "$EXIT_BODY"

echo ""
echo -e "${BOLD}Destroy cleanup${NC}"

wp_cli rudel destroy "$ALPHA_ID" --force >/dev/null
SANDBOX_IDS=("${BETA_ID}")
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
