<?php
/**
 * Runtime template rendering.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Renders generated runtime files from a runtime profile.
 */
final class RuntimeTemplateRenderer {

	/**
	 * Render template placeholders from the runtime profile.
	 *
	 * @param string         $template Template contents.
	 * @param RuntimeProfile $profile Runtime profile.
	 * @return string
	 */
	public static function render( string $template, RuntimeProfile $profile ): string {
		$function_prefix = $profile->runtime_function_prefix();
		$constant_keys   = array(
			'id',
			'path',
			'wp_config_path',
			'plugin_dir',
			'env_type',
			'engine',
			'table_prefix',
			'host_table_prefix',
			'host_url',
			'environment_url',
			'environment_content_url',
			'theme_slug',
			'template_slug',
			'theme_root',
			'theme_root_uri',
			'record_id',
			'disable_email',
			'user_scope',
			'users_table',
			'usermeta_table',
		);

		$replacements = array(
			'{{runtime_mu_loaded_constant}}' => $profile->runtime_mu_loaded_constant(),
			'{{runtime_function_prefix}}'    => $function_prefix,
			'{{runtime_environment_url_fn}}' => $function_prefix . '_environment_url',
			'{{runtime_blog_id_fn}}'         => $function_prefix . '_blog_id',
			'{{runtime_current_blog_id_fn}}' => $function_prefix . '_current_blog_id',
			'{{runtime_port_suffix_fn}}'     => $function_prefix . '_network_port_suffix',
			'{{runtime_blog_url_for_fn}}'    => $function_prefix . '_blog_url_for',
			'{{runtime_site_option_fn}}'     => $function_prefix . '_site_option_override',
			'{{runtime_host_url_fn}}'        => $function_prefix . '_host_url',
			'{{runtime_admin_styles_fn}}'    => $function_prefix . '_admin_bar_styles',
			'{{admin_bar_node_id}}'          => $profile->admin_bar_node_id(),
			'{{admin_bar_title}}'            => $profile->admin_bar_title(),
			'{{email_log_label}}'            => $profile->email_log_label(),
		);

		foreach ( $constant_keys as $key ) {
			$replacements[ '{{constant_' . $key . '}}' ] = $profile->constant( $key );
		}

		return strtr( $template, $replacements );
	}
}
