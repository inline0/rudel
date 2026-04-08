<?php
/**
 * Documented hook catalog.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Treats Rudel's documented actions and filters as a stable extension contract that other code can inspect.
 */
class HookCatalog {

	/**
	 * Documented action hooks.
	 *
	 * @return array<string, array{type: string, args: string[]}>
	 */
	public static function actions(): array {
		return array(
			'rudel_before_environment_create'              => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_environment_create'               => array(
				'type' => 'action',
				'args' => array( '$environment', '$context' ),
			),
			'rudel_environment_create_failed'              => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_environment_update'              => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_environment_update'               => array(
				'type' => 'action',
				'args' => array( '$environment', '$context' ),
			),
			'rudel_environment_update_failed'              => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_environment_destroy'             => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_environment_destroy'              => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_environment_destroy_failed'             => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_environment_replace_state'       => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_environment_replace_state'        => array(
				'type' => 'action',
				'args' => array( '$result', '$context' ),
			),
			'rudel_environment_replace_state_failed'       => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_environment_cleanup'             => array(
				'type' => 'action',
				'args' => array( '$options' ),
			),
			'rudel_after_environment_cleanup'              => array(
				'type' => 'action',
				'args' => array( '$result', '$options' ),
			),
			'rudel_before_environment_cleanup_merged'      => array(
				'type' => 'action',
				'args' => array( '$options' ),
			),
			'rudel_after_environment_cleanup_merged'       => array(
				'type' => 'action',
				'args' => array( '$result', '$options' ),
			),
			'rudel_before_app_create'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_create'                       => array(
				'type' => 'action',
				'args' => array( '$app', '$context' ),
			),
			'rudel_app_create_failed'                      => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_update'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_update'                       => array(
				'type' => 'action',
				'args' => array( '$app', '$context' ),
			),
			'rudel_app_update_failed'                      => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_destroy'                     => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_destroy'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_app_destroy_failed'                     => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_create_sandbox'              => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_create_sandbox'               => array(
				'type' => 'action',
				'args' => array( '$sandbox', '$context' ),
			),
			'rudel_app_create_sandbox_failed'              => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_backup'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_backup'                       => array(
				'type' => 'action',
				'args' => array( '$backup', '$context' ),
			),
			'rudel_app_backup_failed'                      => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_restore'                     => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_restore'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_app_restore_failed'                     => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_deploy'                      => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_deploy'                       => array(
				'type' => 'action',
				'args' => array( '$result', '$context' ),
			),
			'rudel_app_deploy_failed'                      => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_rollback'                    => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_rollback'                     => array(
				'type' => 'action',
				'args' => array( '$result', '$context' ),
			),
			'rudel_app_rollback_failed'                    => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_domain_add'                  => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_domain_add'                   => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_app_domain_add_failed'                  => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_app_domain_remove'               => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_app_domain_remove'                => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_app_domain_remove_failed'               => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_recovery_point_create'           => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_recovery_point_create'            => array(
				'type' => 'action',
				'args' => array( '$context', '$meta' ),
			),
			'rudel_before_recovery_point_restore'          => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_recovery_point_restore'           => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_before_recovery_point_delete'           => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_recovery_point_delete'            => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_before_environment_push'                => array(
				'type' => 'action',
				'args' => array( '$context' ),
			),
			'rudel_after_environment_push'                 => array(
				'type' => 'action',
				'args' => array( '$sha', '$context' ),
			),
			'rudel_environment_push_failed'                => array(
				'type' => 'action',
				'args' => array( '$context', '$error' ),
			),
			'rudel_before_automation_cleanup'              => array(
				'type' => 'action',
				'args' => array( '$config' ),
			),
			'rudel_after_automation_cleanup'               => array(
				'type' => 'action',
				'args' => array( '$result', '$config' ),
			),
			'rudel_before_automation_app_backups'          => array(
				'type' => 'action',
				'args' => array( '$config' ),
			),
			'rudel_after_automation_app_backups'           => array(
				'type' => 'action',
				'args' => array( '$result', '$config' ),
			),
			'rudel_before_automation_app_retention'        => array(
				'type' => 'action',
				'args' => array( '$config' ),
			),
			'rudel_after_automation_app_retention'         => array(
				'type' => 'action',
				'args' => array( '$result', '$config' ),
			),
			'rudel_after_automation_expiring_environments' => array(
				'type' => 'action',
				'args' => array( '$result', '$config' ),
			),
		);
	}

	/**
	 * Documented filter hooks.
	 *
	 * @return array<string, array{type: string, args: string[]}>
	 */
	public static function filters(): array {
		return array(
			'rudel_environment_create_options'         => array(
				'type' => 'filter',
				'args' => array( '$options', '$name', '$manager' ),
			),
			'rudel_environment_clone_source'           => array(
				'type' => 'filter',
				'args' => array( '$clone_source', '$host_url', '$db_cloned', '$themes_cloned', '$plugins_cloned', '$uploads_cloned', '$extra' ),
			),
			'rudel_app_domains'                        => array(
				'type' => 'filter',
				'args' => array( '$domains', '$name', '$app_manager' ),
			),
			'rudel_app_create_options'                 => array(
				'type' => 'filter',
				'args' => array( '$options', '$name', '$domains', '$app_manager' ),
			),
			'rudel_app_update_changes'                 => array(
				'type' => 'filter',
				'args' => array( '$changes', '$app', '$app_manager' ),
			),
			'rudel_app_create_sandbox_options'         => array(
				'type' => 'filter',
				'args' => array( '$options', '$app', '$name', '$app_manager' ),
			),
			'rudel_app_deploy_options'                 => array(
				'type' => 'filter',
				'args' => array( '$options', '$app', '$sandbox', '$app_manager' ),
			),
			'rudel_app_deploy_plan'                    => array(
				'type' => 'filter',
				'args' => array( '$plan', '$app', '$sandbox' ),
			),
			'rudel_environment_cleanup_options'        => array(
				'type' => 'filter',
				'args' => array( '$options', '$repository' ),
			),
			'rudel_environment_cleanup_merged_options' => array(
				'type' => 'filter',
				'args' => array( '$options', '$repository' ),
			),
		);
	}

	/**
	 * Hook catalog keyed by hook name.
	 *
	 * @return array<string, array{type: string, args: string[]}>
	 */
	public static function all(): array {
		return array_merge( self::actions(), self::filters() );
	}
}
