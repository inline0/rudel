<?php
/**
 * Automation helpers for scheduled environment maintenance.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps scheduled maintenance predictable and recoverable across plugin loads.
 */
class Automation {

	/**
	 * Cron hook used for Rudel automation.
	 */
	public const CRON_HOOK = 'rudel_scheduled_cleanup';

	/**
	 * Ensure the scheduled automation event matches the current config.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		$config         = new RudelConfig();
		$needs_schedule = self::should_schedule( $config );
		$is_scheduled   = false !== wp_next_scheduled( self::CRON_HOOK );

		if ( $needs_schedule ) {
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
	 * Remove any scheduled automation event.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Run configured automated maintenance tasks.
	 *
	 * @return array<string, mixed>
	 */
	public static function run(): array {
		$config = new RudelConfig();
		$result = array(
			'cleanup'               => array(
				'removed' => array(),
				'skipped' => array(),
				'errors'  => array(),
			),
			'cleanup_merged'        => array(
				'removed' => array(),
				'skipped' => array(),
				'errors'  => array(),
			),
			'app_backups'           => array(
				'created' => array(),
				'skipped' => array(),
				'errors'  => array(),
			),
			'app_retention'         => array(),
			'expiring_environments' => array(
				'days'     => 0,
				'expiring' => array(),
				'expired'  => array(),
			),
		);

		Hooks::action( 'rudel_before_automation_cleanup', $config->all() );

		$manager     = new EnvironmentManager();
		$app_manager = new AppManager();

		if ( $config->get( 'auto_cleanup_enabled' ) > 0 ) {
			$result['cleanup'] = $manager->cleanup();
		}

		if ( $config->get( 'auto_cleanup_merged' ) > 0 ) {
			$result['cleanup_merged'] = $manager->cleanup_merged();
		}

		Hooks::action( 'rudel_after_automation_cleanup', $result, $config->all() );

		if ( $config->get( 'auto_app_backups_enabled' ) > 0 ) {
			Hooks::action( 'rudel_before_automation_app_backups', $config->all() );
			$result['app_backups'] = $app_manager->run_scheduled_backups( $config->get( 'auto_app_backup_interval_hours' ) );
			Hooks::action( 'rudel_after_automation_app_backups', $result['app_backups'], $config->all() );
		}

		$keep_backups     = $config->get( 'auto_app_backup_retention_count' );
		$keep_deployments = $config->get( 'auto_app_deployment_retention_count' );

		if ( $keep_backups > 0 || $keep_deployments > 0 ) {
			Hooks::action( 'rudel_before_automation_app_retention', $config->all() );
			$result['app_retention'] = $app_manager->prune_all_history(
				array(
					'keep_backups'     => $keep_backups,
					'keep_deployments' => $keep_deployments,
				)
			);
			Hooks::action( 'rudel_after_automation_app_retention', $result['app_retention'], $config->all() );
		}

		$notice_days = $config->get( 'expiring_environment_notice_days' );
		if ( $notice_days > 0 ) {
			$result['expiring_environments'] = self::collect_expiring_environments( $notice_days, $manager, $app_manager );
			Hooks::action( 'rudel_after_automation_expiring_environments', $result['expiring_environments'], $config->all() );
		}

		return $result;
	}

	/**
	 * Determine whether any automation feature needs WP-Cron.
	 *
	 * @param RudelConfig $config Active config.
	 * @return bool
	 */
	private static function should_schedule( RudelConfig $config ): bool {
		return $config->get( 'auto_cleanup_enabled' ) > 0
			|| $config->get( 'auto_cleanup_merged' ) > 0
			|| $config->get( 'auto_app_backups_enabled' ) > 0
			|| $config->get( 'auto_app_backup_retention_count' ) > 0
			|| $config->get( 'auto_app_deployment_retention_count' ) > 0
			|| $config->get( 'expiring_environment_notice_days' ) > 0;
	}

	/**
	 * Collect environments that are close to expiry so integrators can notify owners.
	 *
	 * @param int                $notice_days Days ahead to include.
	 * @param EnvironmentManager $environment_manager Sandbox manager.
	 * @param AppManager         $app_manager App manager.
	 * @return array{days: int, expiring: array<int, array<string, mixed>>, expired: array<int, array<string, mixed>>}
	 */
	private static function collect_expiring_environments( int $notice_days, EnvironmentManager $environment_manager, AppManager $app_manager ): array {
		$cutoff = time() + ( max( 1, $notice_days ) * 86400 );
		$result = array(
			'days'     => $notice_days,
			'expiring' => array(),
			'expired'  => array(),
		);

		foreach ( array_merge( $environment_manager->list(), $app_manager->list() ) as $environment ) {
			if ( null === $environment->expires_at ) {
				continue;
			}

			$expires_at = strtotime( $environment->expires_at );
			if ( false === $expires_at || $expires_at > $cutoff ) {
				continue;
			}

			$item = array(
				'id'         => $environment->id,
				'type'       => $environment->type,
				'name'       => $environment->name,
				'owner'      => $environment->owner,
				'expires_at' => $environment->expires_at,
				'protected'  => $environment->is_protected(),
			);

			if ( $expires_at <= time() ) {
				$result['expired'][] = $item;
			} else {
				$result['expiring'][] = $item;
			}
		}

		usort(
			$result['expiring'],
			static fn( array $left, array $right ): int => strcmp( (string) $left['expires_at'], (string) $right['expires_at'] )
		);
		usort(
			$result['expired'],
			static fn( array $left, array $right ): int => strcmp( (string) $left['expires_at'], (string) $right['expires_at'] )
		);

		return $result;
	}
}
