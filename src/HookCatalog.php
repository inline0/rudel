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
			'rudel_environment_cleanup_options'        => array(
				'type' => 'filter',
				'args' => array( '$options', '$repository' ),
			),
			'rudel_environment_cleanup_merged_options' => array(
				'type' => 'filter',
				'args' => array( '$options', '$repository' ),
			),
			'rudel_environment_db_dropin_contents'     => array(
				'type' => 'filter',
				'args' => array( '$contents', '$context' ),
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
