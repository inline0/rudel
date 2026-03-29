<?php
/**
 * Automation helpers for scheduled cleanup.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps scheduled cleanup predictable and recoverable across plugin loads.
 */
class Automation {

	/**
	 * Cron hook used for Rudel automation.
	 */
	public const CRON_HOOK = 'rudel_scheduled_cleanup';

	/**
	 * Ensure the scheduled cleanup event matches the current config.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		$config       = new RudelConfig();
		$auto_cleanup = $config->get( 'auto_cleanup_enabled' ) > 0;
		$auto_merged  = $config->get( 'auto_cleanup_merged' ) > 0;
		$is_scheduled = false !== wp_next_scheduled( self::CRON_HOOK );

		if ( $auto_cleanup || $auto_merged ) {
			if ( ! $is_scheduled ) {
				wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
			}
			return;
		}

		if ( $is_scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Remove any scheduled cleanup event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Run configured automated cleanup tasks.
	 *
	 * @return array{
	 *     cleanup: array{removed: string[], skipped: string[], errors: string[], reasons?: array<string, string>},
	 *     cleanup_merged: array{removed: string[], skipped: string[], errors: string[], reasons?: array<string, string>}
	 * }
	 */
	public static function run(): array {
		$config = new RudelConfig();
		$result = array(
			'cleanup'        => array(
				'removed' => array(),
				'skipped' => array(),
				'errors'  => array(),
			),
			'cleanup_merged' => array(
				'removed' => array(),
				'skipped' => array(),
				'errors'  => array(),
			),
		);

		Hooks::action( 'rudel_before_automation_cleanup', $config->all() );

		$manager = new EnvironmentManager();

		if ( $config->get( 'auto_cleanup_enabled' ) > 0 ) {
			$result['cleanup'] = $manager->cleanup();
		}

		if ( $config->get( 'auto_cleanup_merged' ) > 0 ) {
			$result['cleanup_merged'] = $manager->cleanup_merged();
		}

		Hooks::action( 'rudel_after_automation_cleanup', $result, $config->all() );

		return $result;
	}
}
