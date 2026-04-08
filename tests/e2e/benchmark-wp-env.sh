#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUDEL_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_ID=""
SANDBOX_ID=""

cleanup() {
	if [[ -n "$SANDBOX_ID" ]]; then
		wp_cli rudel destroy "$SANDBOX_ID" --force >/dev/null 2>&1 || true
	fi

	if [[ -n "$APP_ID" ]]; then
		wp_cli rudel app destroy "$APP_ID" --force >/dev/null 2>&1 || true
	fi

	(
		cd "$RUDEL_DIR"
		npx wp-env destroy --force >/dev/null 2>&1 || true
	)
}
trap cleanup EXIT

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

		if (
			cd "$RUDEL_DIR"
			npx wp-env start >/dev/null
		); then
			return 0
		fi

		if (( attempts < max_attempts )); then
			npx wp-env destroy --force >/dev/null 2>&1 || true
			reset_wp_env_project_state
			sleep 2
		fi
	done

	echo "wp-env start failed after ${max_attempts} attempts." >&2
	return 1
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
		--title='Rudel Benchmark Host' \
		--admin_user=admin \
		--admin_password=password \
		--admin_email=admin@example.test \
		--skip-email >/dev/null
	wp_cli plugin activate rudel >/dev/null
	wp_cli core multisite-install \
		--url=localhost:8888 \
		--base=/ \
		--subdomains \
		--title='Rudel Benchmark Network' \
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
	wp_cli plugin activate rudel >/dev/null
}

parse_created_id() {
	local pattern="$1"
	local output="$2"

	echo "$output" | grep -oE "${pattern}: [^ ]+" | sed "s/${pattern}: //"
}

app_path() {
	local app_id="$1"

	wp_cli rudel app info "$app_id" --format=json | php -r '
		$data = json_decode(stream_get_contents(STDIN), true);
		if (is_array($data) && array_is_list($data) && 1 === count($data) && is_array($data[0])) {
			$data = $data[0];
		}

		echo is_array($data) && isset($data["path"]) ? (string) $data["path"] : "";
	'
}

now_ms() {
	php -r 'echo (string) floor(microtime(true) * 1000);'
}

measure_ms() {
	local __outputvar="$1"
	local __msvar="$2"
	shift 2
	local started finished output

	started="$(now_ms)"
	output="$("$@")"
	finished="$(now_ms)"

	printf -v "$__outputvar" '%s' "$output"
	printf -v "$__msvar" '%s' "$((finished - started))"
}

echo "Rudel wp-env clone benchmark"
echo "============================"

if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
	echo "Docker not available, skipping benchmark"
	exit 0
fi

prepare_network

APP_OUTPUT=""
APP_MS=""
measure_ms APP_OUTPUT APP_MS wp_cli rudel app create --name='Benchmark App' --domain='benchmark.example.test'
APP_ID="$(parse_created_id 'App created' "$APP_OUTPUT")"

if [[ -z "$APP_ID" ]]; then
	echo "App create failed"
	echo "$APP_OUTPUT"
	exit 1
fi

SANDBOX_OUTPUT=""
SANDBOX_MS=""
measure_ms SANDBOX_OUTPUT SANDBOX_MS wp_cli rudel app create-sandbox "$APP_ID" --name='Benchmark Sandbox'
SANDBOX_ID="$(parse_created_id 'Sandbox created from app' "$SANDBOX_OUTPUT")"

if [[ -z "$SANDBOX_ID" ]]; then
	echo "Sandbox create failed"
	echo "$SANDBOX_OUTPUT"
	exit 1
fi

APP_PATH="$(app_path "$APP_ID")"
if [[ -z "$APP_PATH" ]]; then
	echo "Could not resolve benchmark app path"
	exit 1
fi

LOCAL_THEME_SCRIPT=$(cat <<PHP
\$path = '${APP_PATH}/wp-content/themes/local-theme';
if (! is_dir(\$path) && ! mkdir(\$path, 0755, true) && ! is_dir(\$path)) {
	fwrite(STDERR, 'mkdir failed');
	exit(1);
}
file_put_contents(\$path . '/style.css', "body { color: red; }\n");
\$repo = \Pitmaster\Pitmaster::init(\$path);
\$repo->config()->set('user.email', 'bench@example.test');
\$repo->config()->set('user.name', 'Benchmark User');
\$repo->add('style.css');
\$repo->commit('init');
echo 'ready';
PHP
)

if ! wp_cli eval "$LOCAL_THEME_SCRIPT" | grep -q 'ready'; then
	echo "Could not prepare local git-backed theme inside benchmark app"
	exit 1
fi

LOCAL_SANDBOX_OUTPUT=""
LOCAL_SANDBOX_MS=""
measure_ms LOCAL_SANDBOX_OUTPUT LOCAL_SANDBOX_MS wp_cli rudel app create-sandbox "$APP_ID" --name='Benchmark Local Sandbox'
LOCAL_SANDBOX_ID="$(parse_created_id 'Sandbox created from app' "$LOCAL_SANDBOX_OUTPUT")"

if [[ -z "$LOCAL_SANDBOX_ID" ]]; then
	echo "Local git-backed sandbox create failed"
	echo "$LOCAL_SANDBOX_OUTPUT"
	exit 1
fi

php -r '
	echo json_encode(
		array(
			"app_create_ms" => (int) $argv[1],
			"sandbox_create_ms" => (int) $argv[2],
			"local_git_sandbox_create_ms" => (int) $argv[3],
			"app_id" => $argv[4],
			"sandbox_id" => $argv[5],
			"local_git_sandbox_id" => $argv[6],
		),
		JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
	) . PHP_EOL;
	' "$APP_MS" "$SANDBOX_MS" "$LOCAL_SANDBOX_MS" "$APP_ID" "$SANDBOX_ID" "$LOCAL_SANDBOX_ID"
