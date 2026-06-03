<?php

namespace Rudel\Tests\Fixtures;

final class RuntimeProfiles
{
	public static function rudelLike(string $root = '/tmp'): array
	{
		return self::profile(
			[
				'cookie' => 'rudel_environment',
				'header' => 'X-Rudel-Environment',
				'env' => 'RUDEL_ENVIRONMENT',
			],
			[
				'id' => 'RUDEL_ID',
				'path' => 'RUDEL_PATH',
				'wp_config_path' => 'RUDEL_WP_CONFIG_PATH',
				'plugin_dir' => 'RUDEL_BOOTSTRAP_PLUGIN_DIR',
				'env_type' => 'RUDEL_ENV_TYPE',
				'engine' => 'RUDEL_ENGINE',
				'table_prefix' => 'RUDEL_TABLE_PREFIX',
				'host_table_prefix' => 'RUDEL_HOST_TABLE_PREFIX',
				'host_url' => 'RUDEL_HOST_URL',
				'environment_url' => 'RUDEL_ENVIRONMENT_URL',
				'environment_content_url' => 'RUDEL_ENVIRONMENT_CONTENT_URL',
				'theme_slug' => 'RUDEL_THEME_SLUG',
				'template_slug' => 'RUDEL_TEMPLATE_SLUG',
				'theme_root' => 'RUDEL_ENVIRONMENT_THEME_ROOT',
				'theme_root_uri' => 'RUDEL_ENVIRONMENT_THEME_ROOT_URI',
				'record_id' => 'RUDEL_ENV_RECORD_ID',
				'disable_email' => 'RUDEL_DISABLE_EMAIL',
				'user_scope' => 'RUDEL_USER_SCOPE',
				'users_table' => 'RUDEL_USERS_TABLE',
				'usermeta_table' => 'RUDEL_USERMETA_TABLE',
			],
			[
				'prefix' => 'rudel_',
				'environments' => 'rudel_environments',
				'worktrees' => 'rudel_worktrees',
			],
			[
				'environment_table_prefix' => '{{host_prefix}}{{short_hash}}_',
				'isolated_users_table' => '{{host_prefix}}rudel_env_{{blog_id}}_users',
				'isolated_usermeta_table' => '{{host_prefix}}rudel_env_{{blog_id}}_usermeta',
				'cache_key_salt' => 'rudel_{{id}}_',
				'git_branch_prefix' => 'rudel/',
				'managed_table_prefix_patterns' => [
					'/^rudel_/',
					'/^[A-Za-z0-9_]+_[a-f0-9]{7}_$/',
				],
			],
			[
				'environments_dir_name' => 'rudel-environments',
				'environments_dir_constant' => 'RUDEL_ENVIRONMENTS_DIR',
				'environment_content_url_path' => 'rudel-environments',
				'bootstrap_config_path' => rtrim($root, '/') . '/rudel-runtime-profile.php',
			],
			[
				'file' => 'rudel-runtime.php',
				'loaded_constant' => 'RUDEL_RUNTIME_HOOKS_LOADED',
				'function_prefix' => 'rudel_runtime',
				'admin_bar_node_id' => 'rudel-environment',
				'admin_bar_title' => 'Sandbox',
				'email_log_label' => 'Rudel',
			],
			'// Rudel environment bootstrap'
		);
	}

	public static function neutral(string $root = '/tmp'): array
	{
		return self::profile(
			[
				'cookie' => 'fixture_environment',
				'header' => 'X-Fixture-Environment',
				'env' => 'FIXTURE_ENVIRONMENT',
			],
			[
				'id' => 'FIXTURE_ID',
				'path' => 'FIXTURE_PATH',
				'wp_config_path' => 'FIXTURE_WP_CONFIG_PATH',
				'plugin_dir' => 'FIXTURE_BOOTSTRAP_PLUGIN_DIR',
				'env_type' => 'FIXTURE_ENV_TYPE',
				'engine' => 'FIXTURE_ENGINE',
				'table_prefix' => 'FIXTURE_TABLE_PREFIX',
				'host_table_prefix' => 'FIXTURE_HOST_TABLE_PREFIX',
				'host_url' => 'FIXTURE_HOST_URL',
				'environment_url' => 'FIXTURE_ENVIRONMENT_URL',
				'environment_content_url' => 'FIXTURE_ENVIRONMENT_CONTENT_URL',
				'theme_slug' => 'FIXTURE_THEME_SLUG',
				'template_slug' => 'FIXTURE_TEMPLATE_SLUG',
				'theme_root' => 'FIXTURE_ENVIRONMENT_THEME_ROOT',
				'theme_root_uri' => 'FIXTURE_ENVIRONMENT_THEME_ROOT_URI',
				'record_id' => 'FIXTURE_ENV_RECORD_ID',
				'disable_email' => 'FIXTURE_DISABLE_EMAIL',
				'user_scope' => 'FIXTURE_USER_SCOPE',
				'users_table' => 'FIXTURE_USERS_TABLE',
				'usermeta_table' => 'FIXTURE_USERMETA_TABLE',
			],
			[
				'prefix' => 'fixture_',
				'environments' => 'fixture_environments',
				'worktrees' => 'fixture_worktrees',
			],
			[
				'environment_table_prefix' => '{{host_prefix}}fixture_{{short_hash}}_',
				'isolated_users_table' => '{{host_prefix}}fixture_env_{{blog_id}}_users',
				'isolated_usermeta_table' => '{{host_prefix}}fixture_env_{{blog_id}}_usermeta',
				'cache_key_salt' => 'fixture_{{id}}_',
				'git_branch_prefix' => 'fixture/',
				'managed_table_prefix_patterns' => [
					'/^fixture_/',
					'/^[A-Za-z0-9_]+fixture_[a-f0-9]{7}_$/',
				],
			],
			[
				'environments_dir_name' => 'fixture-environments',
				'environments_dir_constant' => 'FIXTURE_ENVIRONMENTS_DIR',
				'environment_content_url_path' => 'fixture-environments',
				'bootstrap_config_path' => rtrim($root, '/') . '/fixture-runtime-profile.php',
			],
			[
				'file' => 'fixture-runtime.php',
				'loaded_constant' => 'FIXTURE_RUNTIME_HOOKS_LOADED',
				'function_prefix' => 'fixture_runtime',
				'admin_bar_node_id' => 'fixture-environment',
				'admin_bar_title' => 'Fixture',
				'email_log_label' => 'Fixture',
			],
			'// Fixture environment bootstrap'
		);
	}

	private static function profile(
		array $selectors,
		array $constants,
		array $runtimeTables,
		array $naming,
		array $paths,
		array $runtimeMu,
		string $wpConfigMarker
	): array {
		return [
			'selectors' => [
				'cookie' => $selectors['cookie'],
				'header' => $selectors['header'],
				'env' => $selectors['env'],
				'url_subdomain' => true,
			],
			'constants' => $constants,
			'runtime_tables' => $runtimeTables,
			'naming' => $naming,
			'paths' => $paths,
			'wp_config_marker' => $wpConfigMarker,
			'runtime_mu' => $runtimeMu,
		];
	}
}
