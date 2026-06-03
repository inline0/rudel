<?php
/**
 * Runtime profile fixture loaded before wp-env auto-activates Rudel.
 *
 * @package Rudel\Tests\E2E
 */

add_filter(
	'rudel_runtime_profile',
	static function (): array {
		return array(
			'selectors'       => array(
				'cookie'        => 'rudel_environment',
				'header'        => 'X-Rudel-Environment',
				'env'           => 'RUDEL_ENVIRONMENT',
				'url_subdomain' => true,
			),
			'constants'       => array(
				'id'                      => 'RUDEL_ID',
				'path'                    => 'RUDEL_PATH',
				'wp_config_path'          => 'RUDEL_WP_CONFIG_PATH',
				'plugin_dir'              => 'RUDEL_BOOTSTRAP_PLUGIN_DIR',
				'env_type'                => 'RUDEL_ENV_TYPE',
				'engine'                  => 'RUDEL_ENGINE',
				'table_prefix'            => 'RUDEL_TABLE_PREFIX',
				'host_table_prefix'       => 'RUDEL_HOST_TABLE_PREFIX',
				'host_url'                => 'RUDEL_HOST_URL',
				'environment_url'         => 'RUDEL_ENVIRONMENT_URL',
				'environment_content_url' => 'RUDEL_ENVIRONMENT_CONTENT_URL',
				'theme_slug'              => 'RUDEL_THEME_SLUG',
				'template_slug'           => 'RUDEL_TEMPLATE_SLUG',
				'theme_root'              => 'RUDEL_ENVIRONMENT_THEME_ROOT',
				'theme_root_uri'          => 'RUDEL_ENVIRONMENT_THEME_ROOT_URI',
				'record_id'               => 'RUDEL_ENV_RECORD_ID',
				'disable_email'           => 'RUDEL_DISABLE_EMAIL',
				'user_scope'              => 'RUDEL_USER_SCOPE',
				'users_table'             => 'RUDEL_USERS_TABLE',
				'usermeta_table'          => 'RUDEL_USERMETA_TABLE',
			),
			'runtime_tables'  => array(
				'prefix'       => 'rudel_',
				'environments' => 'rudel_environments',
				'worktrees'    => 'rudel_worktrees',
			),
			'naming'          => array(
				'environment_table_prefix'      => '{{host_prefix}}{{short_hash}}_',
				'isolated_users_table'          => '{{host_prefix}}rudel_env_{{blog_id}}_users',
				'isolated_usermeta_table'       => '{{host_prefix}}rudel_env_{{blog_id}}_usermeta',
				'cache_key_salt'                => 'rudel_{{id}}_',
				'git_branch_prefix'             => 'rudel/',
				'managed_table_prefix_patterns' => array(
					'/^rudel_/',
					'/^[A-Za-z0-9_]+_[a-f0-9]{7}_$/',
				),
			),
			'paths'           => array(
				'environments_dir_name'        => 'rudel-environments',
				'environments_dir_constant'    => 'RUDEL_ENVIRONMENTS_DIR',
				'environment_content_url_path' => 'rudel-environments',
				'bootstrap_config_path'        => '/var/www/html/wp-content/rudel-runtime-profile.php',
			),
			'wp_config_marker' => '// Rudel environment bootstrap',
			'runtime_mu'      => array(
				'file'              => 'rudel-runtime.php',
				'loaded_constant'   => 'RUDEL_RUNTIME_HOOKS_LOADED',
				'function_prefix'   => 'rudel_runtime',
				'admin_bar_node_id' => 'rudel-environment',
				'admin_bar_title'   => 'Sandbox',
				'email_log_label'   => 'Rudel',
			),
		);
	}
);
