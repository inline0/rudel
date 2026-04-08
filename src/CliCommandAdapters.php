<?php
/**
 * Argument adapters that keep CLI semantics and direct PHP execution aligned.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Turns parsed CLI arguments into stable execution plans for the public API.
 */
class CliCommandAdapters {

	/**
	 * Resolve sandbox create into the right PHP target.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_create( array $args, array $assoc_args ): array {
		$git_remote = self::optional_assoc( $assoc_args, 'git' );
		$name       = self::sandbox_create_name( $assoc_args, $git_remote );
		$options    = self::sandbox_create_options( $assoc_args );

		if ( null !== $git_remote ) {
			$options['name'] = $name;
			$options['type'] = self::optional_assoc( $assoc_args, 'type' ) ?? 'theme';

			return self::php_plan(
				Rudel::class . '::create_from_git',
				array( $git_remote, $options )
			);
		}

		return self::php_plan(
			Rudel::class . '::create',
			array( $name, $options )
		);
	}

	/**
	 * Resolve sandbox list.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_list( array $args, array $assoc_args ): array {
		return self::php_plan( Rudel::class . '::all', array() );
	}

	/**
	 * Resolve sandbox info.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_info( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::get',
			array( self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve sandbox destroy.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the sandbox identifier is missing or the sandbox does not exist.
	 */
	public static function sandbox_destroy( array $args, array $assoc_args ): array {
		$id      = self::required_positional( $args, 0, 'id' );
		$sandbox = Rudel::get( $id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $id ) );
		}

		$force = self::flag( $assoc_args, 'force' );

		return self::php_plan(
			Rudel::class . '::destroy',
			array( $id ),
			array(
				'needs_confirmation'   => ! $force,
				'confirmation_message' => sprintf( "Are you sure you want to destroy sandbox '%s' (%s)?", $sandbox->name, $id ),
			)
		);
	}

	/**
	 * Resolve sandbox update.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If no updateable fields were provided or the sandbox identifier is missing.
	 */
	public static function sandbox_update( array $args, array $assoc_args ): array {
		$changes = self::policy_changes( $assoc_args );
		if ( empty( $changes ) ) {
			throw new \InvalidArgumentException( 'No metadata changes were provided.' );
		}

		return self::php_plan(
			Rudel::class . '::update',
			array(
				self::required_positional( $args, 0, 'id' ),
				$changes,
			)
		);
	}

	/**
	 * Resolve system status.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function system_status( array $args, array $assoc_args ): array {
		return self::php_plan( Rudel::class . '::status', array() );
	}

	/**
	 * Resolve sandbox cleanup.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_cleanup( array $args, array $assoc_args ): array {
		$dry_run = self::flag( $assoc_args, 'dry-run' );

		if ( self::flag( $assoc_args, 'merged' ) ) {
			return self::php_plan(
				Rudel::class . '::cleanup_merged',
				array(
					array(
						'dry_run' => $dry_run,
					),
				)
			);
		}

		return self::php_plan(
			Rudel::class . '::cleanup',
			array(
				array(
					'dry_run'       => $dry_run,
					'max_age_days'  => self::int_assoc( $assoc_args, 'max-age-days', 0 ),
					'max_idle_days' => self::int_assoc( $assoc_args, 'max-idle-days', 0 ),
				),
			)
		);
	}

	/**
	 * Resolve sandbox log commands.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the sandbox identifier is missing or the sandbox does not exist.
	 */
	public static function sandbox_logs( array $args, array $assoc_args ): array {
		$id      = self::required_positional( $args, 0, 'id' );
		$sandbox = Rudel::get( $id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $id ) );
		}

		$log_path = $sandbox->get_wp_content_path() . '/debug.log';

		if ( self::flag( $assoc_args, 'follow' ) ) {
			return self::shell_plan(
				array( 'tail', '-f', $log_path ),
				array(
					'path' => $log_path,
				)
			);
		}

		if ( self::flag( $assoc_args, 'clear' ) ) {
			return self::php_plan(
				Rudel::class . '::clear_log',
				array( $id )
			);
		}

		return self::php_plan(
			Rudel::class . '::read_log',
			array(
				$id,
				self::int_assoc( $assoc_args, 'lines', 50 ),
			)
		);
	}

	/**
	 * Resolve sandbox push.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_push( array $args, array $assoc_args ): array {
		$id = self::required_positional( $args, 0, 'id' );

		return self::php_plan(
			Rudel::class . '::push',
			array(
				$id,
				self::optional_assoc( $assoc_args, 'git' ) ?? '',
				self::optional_assoc( $assoc_args, 'message' ) ?? 'Update from Rudel sandbox',
				self::optional_assoc( $assoc_args, 'dir' ) ?? '',
			)
		);
	}

	/**
	 * Resolve sandbox restore.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_restore( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::restore',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'snapshot' ),
			)
		);
	}

	/**
	 * Resolve sandbox snapshot creation.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function sandbox_snapshot( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::snapshot',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'name' ),
			)
		);
	}

	/**
	 * Resolve template list.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function template_list( array $args, array $assoc_args ): array {
		return self::php_plan( Rudel::class . '::templates', array() );
	}

	/**
	 * Resolve template save.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function template_save( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::save_template',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'name' ),
				self::optional_assoc( $assoc_args, 'description' ) ?? '',
			)
		);
	}

	/**
	 * Resolve template delete.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function template_delete( array $args, array $assoc_args ): array {
		$name  = self::required_positional( $args, 0, 'name' );
		$force = self::flag( $assoc_args, 'force' );

		return self::php_plan(
			Rudel::class . '::delete_template',
			array( $name ),
			array(
				'needs_confirmation'   => ! $force,
				'confirmation_message' => sprintf( "Are you sure you want to delete template '%s'?", $name ),
			)
		);
	}

	/**
	 * Resolve app create.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_create( array $args, array $assoc_args ): array {
		$domain = self::required_assoc( $assoc_args, 'domain' );
		$name   = self::optional_assoc( $assoc_args, 'name' ) ?? str_replace( '.', '-', $domain );

		return self::php_plan(
			Rudel::class . '::create_app',
			array(
				$name,
				array( $domain ),
				self::app_create_options( $assoc_args ),
			)
		);
	}

	/**
	 * Resolve app list.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_list( array $args, array $assoc_args ): array {
		return self::php_plan( Rudel::class . '::apps', array() );
	}

	/**
	 * Resolve app info.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_info( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::app',
			array( self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve app destroy.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the app identifier is missing or the app does not exist.
	 */
	public static function app_destroy( array $args, array $assoc_args ): array {
		$id    = self::required_positional( $args, 0, 'id' );
		$app   = Rudel::app( $id );
		$force = self::flag( $assoc_args, 'force' );

		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		return self::php_plan(
			Rudel::class . '::destroy_app',
			array( $id ),
			array(
				'needs_confirmation'   => ! $force,
				'confirmation_message' => sprintf(
					"This will permanently destroy app '%s' (%s).",
					$app->name,
					implode( ', ', $app->domains ?? array() )
				),
			)
		);
	}

	/**
	 * Resolve app update.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If no updateable fields were provided or the app identifier is missing.
	 */
	public static function app_update( array $args, array $assoc_args ): array {
		$changes = array_merge(
			self::policy_changes( $assoc_args ),
			self::git_tracking_changes( $assoc_args )
		);

		if ( empty( $changes ) ) {
			throw new \InvalidArgumentException( 'No metadata changes were provided.' );
		}

		return self::php_plan(
			Rudel::class . '::update_app',
			array(
				self::required_positional( $args, 0, 'id' ),
				$changes,
			)
		);
	}

	/**
	 * Resolve app-derived sandbox creation.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the app identifier is missing or the source app does not exist.
	 */
	public static function app_create_sandbox( array $args, array $assoc_args ): array {
		$id  = self::required_positional( $args, 0, 'id' );
		$app = Rudel::app( $id );
		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		$options = self::policy_changes( $assoc_args );

		return self::php_plan(
			Rudel::class . '::create_sandbox_from_app',
			array(
				$id,
				self::optional_assoc( $assoc_args, 'name' ) ?? "{$app->name} Sandbox",
				$options,
			)
		);
	}

	/**
	 * Resolve app backup creation.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_backup( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::backup_app',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'name' ),
			)
		);
	}

	/**
	 * Resolve app backup listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_backups( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::app_backups',
			array( self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve app deployment listing.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_deployments( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::app_deployments',
			array( self::required_positional( $args, 0, 'id' ) )
		);
	}

	/**
	 * Resolve app restore.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the app identifier or backup name is missing, or the app does not exist.
	 */
	public static function app_restore( array $args, array $assoc_args ): array {
		$id    = self::required_positional( $args, 0, 'id' );
		$app   = Rudel::app( $id );
		$force = self::flag( $assoc_args, 'force' );

		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		$backup_name = self::required_assoc( $assoc_args, 'backup' );

		return self::php_plan(
			Rudel::class . '::restore_app',
			array( $id, $backup_name ),
			array(
				'needs_confirmation'   => ! $force,
				'confirmation_message' => sprintf( "This will replace app '%s' with backup '%s'.", $app->name, $backup_name ),
			)
		);
	}

	/**
	 * Resolve app deploy.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the app identifier or source sandbox is missing, or the app does not exist.
	 */
	public static function app_deploy( array $args, array $assoc_args ): array {
		$id         = self::required_positional( $args, 0, 'id' );
		$app        = Rudel::app( $id );
		$sandbox_id = self::required_assoc( $assoc_args, 'from' );
		$backup     = self::optional_assoc( $assoc_args, 'backup' );
		$options    = array(
			'label' => self::optional_assoc( $assoc_args, 'label' ),
			'notes' => self::optional_assoc( $assoc_args, 'notes' ),
		);

		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		if ( self::flag( $assoc_args, 'dry-run' ) ) {
			return self::php_plan(
				Rudel::class . '::plan_app_deploy',
				array( $id, $sandbox_id, $backup, $options )
			);
		}

		return self::php_plan(
			Rudel::class . '::deploy_sandbox_to_app',
			array( $id, $sandbox_id, $backup, $options ),
			array(
				'needs_confirmation'   => ! self::flag( $assoc_args, 'force' ),
				'confirmation_message' => sprintf( "This will replace app '%s' with sandbox '%s'.", $app->name, $sandbox_id ),
			)
		);
	}

	/**
	 * Resolve app rollback.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException|\RuntimeException If the app identifier or deployment identifier is missing, or the app does not exist.
	 */
	public static function app_rollback( array $args, array $assoc_args ): array {
		$id            = self::required_positional( $args, 0, 'id' );
		$app           = Rudel::app( $id );
		$deployment_id = self::required_assoc( $assoc_args, 'deployment' );

		if ( ! $app ) {
			throw new \RuntimeException( sprintf( 'App not found: %s', $id ) );
		}

		return self::php_plan(
			Rudel::class . '::rollback_app_deployment',
			array( $id, $deployment_id, array() ),
			array(
				'needs_confirmation'   => ! self::flag( $assoc_args, 'force' ),
				'confirmation_message' => sprintf( "This will restore app '%s' from deployment '%s'.", $app->name, $deployment_id ),
			)
		);
	}

	/**
	 * Resolve app domain add.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_domain_add( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::add_app_domain',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'domain' ),
			)
		);
	}

	/**
	 * Resolve app domain removal.
	 *
	 * @param array<int, string>   $args Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	public static function app_domain_remove( array $args, array $assoc_args ): array {
		return self::php_plan(
			Rudel::class . '::remove_app_domain',
			array(
				self::required_positional( $args, 0, 'id' ),
				self::required_assoc( $assoc_args, 'domain' ),
			)
		);
	}

	/**
	 * Create a PHP execution plan.
	 *
	 * @param string               $callable Public callable string.
	 * @param array<int, mixed>    $arguments Normalized callable arguments.
	 * @param array<string, mixed> $extra Optional metadata.
	 * @return array<string, mixed>
	 */
	private static function php_plan( string $callable, array $arguments, array $extra = array() ): array {
		return array_merge(
			array(
				'transport'            => 'php',
				'callable'             => $callable,
				'arguments'            => $arguments,
				'needs_confirmation'   => false,
				'confirmation_message' => null,
			),
			$extra
		);
	}

	/**
	 * Create a shell execution plan for the narrow cases that cannot be made request-scoped PHP calls.
	 *
	 * @param array<int, string>   $command Shell command segments.
	 * @param array<string, mixed> $extra Optional metadata.
	 * @return array<string, mixed>
	 */
	private static function shell_plan( array $command, array $extra = array() ): array {
		return array_merge(
			array(
				'transport'            => 'shell',
				'command'              => $command,
				'needs_confirmation'   => false,
				'confirmation_message' => null,
			),
			$extra
		);
	}

	/**
	 * Read one required positional argument.
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @param int                $index Argument index.
	 * @param string             $name Human-readable argument name.
	 * @return string
	 *
	 * @throws \InvalidArgumentException If the requested argument is missing or blank.
	 */
	private static function required_positional( array $args, int $index, string $name ): string {
		if ( ! array_key_exists( $index, $args ) || '' === trim( (string) $args[ $index ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Missing required positional argument: %s', $name ) );
		}

		return (string) $args[ $index ];
	}

	/**
	 * Read one required associative argument.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @return string
	 *
	 * @throws \InvalidArgumentException If the requested argument is missing or blank.
	 */
	private static function required_assoc( array $assoc_args, string $name ): string {
		$value = self::optional_assoc( $assoc_args, $name );
		if ( null === $value || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'Missing required associative argument: %s', $name ) );
		}

		return $value;
	}

	/**
	 * Read one optional associative argument as a string.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @return string|null
	 */
	private static function optional_assoc( array $assoc_args, string $name ): ?string {
		if ( ! array_key_exists( $name, $assoc_args ) || null === $assoc_args[ $name ] ) {
			return null;
		}

		return (string) $assoc_args[ $name ];
	}

	/**
	 * Normalize one integer-style associative argument.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Argument name.
	 * @param int                  $default Default value.
	 * @return int
	 */
	private static function int_assoc( array $assoc_args, string $name, int $default ): int {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return $default;
		}

		return (int) $assoc_args[ $name ];
	}

	/**
	 * Normalize boolean flags without depending on WP-CLI internals.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $name Flag name.
	 * @return bool
	 */
	private static function flag( array $assoc_args, string $name ): bool {
		if ( ! array_key_exists( $name, $assoc_args ) ) {
			return false;
		}

		$value = $assoc_args[ $name ];
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return true;
		}

		return ! in_array( strtolower( (string) $value ), array( '', '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Reuse the policy flag normalization outside the concrete CLI classes.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If mutually exclusive expiry or protection flags are combined.
	 */
	private static function policy_changes( array $assoc_args ): array {
		$changes = array();

		if ( array_key_exists( 'owner', $assoc_args ) ) {
			$changes['owner'] = $assoc_args['owner'];
		}

		if ( array_key_exists( 'labels', $assoc_args ) ) {
			$changes['labels'] = $assoc_args['labels'];
		}

		if ( array_key_exists( 'purpose', $assoc_args ) ) {
			$changes['purpose'] = $assoc_args['purpose'];
		}

		$protect   = self::flag( $assoc_args, 'protected' );
		$unprotect = self::flag( $assoc_args, 'unprotected' );

		if ( $protect && $unprotect ) {
			throw new \InvalidArgumentException( 'Cannot combine --protected and --unprotected.' );
		}

		if ( $protect ) {
			$changes['protected'] = true;
		} elseif ( $unprotect ) {
			$changes['protected'] = false;
		}

		if ( array_key_exists( 'ttl-days', $assoc_args ) ) {
			$changes['ttl_days'] = $assoc_args['ttl-days'];
		}

		if ( array_key_exists( 'expires-at', $assoc_args ) ) {
			$changes['expires_at'] = $assoc_args['expires-at'];
		}

		if ( self::flag( $assoc_args, 'clear-expiry' ) ) {
			$changes['clear_expiry'] = true;
		}

		return $changes;
	}

	/**
	 * Normalize tracked Git metadata flags shared by app commands.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If incompatible tracking flags are combined.
	 */
	private static function git_tracking_changes( array $assoc_args ): array {
		$changes = array();
		$clear   = self::flag( $assoc_args, 'clear-git' );

		if ( array_key_exists( 'git', $assoc_args ) ) {
			$changes['git'] = $assoc_args['git'];
		}

		if ( array_key_exists( 'branch', $assoc_args ) ) {
			$changes['branch'] = $assoc_args['branch'];
		}

		if ( array_key_exists( 'dir', $assoc_args ) ) {
			$changes['dir'] = $assoc_args['dir'];
		}

		if ( $clear && ! empty( $changes ) ) {
			throw new \InvalidArgumentException( 'Cannot combine --clear-git with --git, --branch, or --dir.' );
		}

		if ( $clear ) {
			$changes['clear_git'] = true;
		}

		return $changes;
	}

	/**
	 * Keep sandbox create naming identical everywhere the command is interpreted.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string|null          $git_remote Optional Git remote.
	 * @return string
	 */
	private static function sandbox_create_name( array $assoc_args, ?string $git_remote ): string {
		$name = self::optional_assoc( $assoc_args, 'name' );
		if ( null !== $name && '' !== $name ) {
			return $name;
		}

		if ( null !== $git_remote && '' !== $git_remote ) {
			return basename( preg_replace( '/\.git$/', '', $git_remote ) );
		}

		return 'sandbox';
	}

	/**
	 * Normalize sandbox create options into the shape expected by the API layer.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	private static function sandbox_create_options( array $assoc_args ): array {
		$clone_all  = self::flag( $assoc_args, 'clone-all' );
		$clone_from = self::optional_assoc( $assoc_args, 'clone-from' );
		$options    = array_merge(
			array(
				'template'      => self::optional_assoc( $assoc_args, 'template' ) ?? 'blank',
				'clone_db'      => $clone_all || self::flag( $assoc_args, 'clone-db' ),
				'clone_themes'  => $clone_all || self::flag( $assoc_args, 'clone-themes' ),
				'clone_plugins' => $clone_all || self::flag( $assoc_args, 'clone-plugins' ),
				'clone_uploads' => $clone_all || self::flag( $assoc_args, 'clone-uploads' ),
			),
			self::policy_changes( $assoc_args )
		);

		if ( null !== $clone_from ) {
			$options['clone_from'] = $clone_from;
		}

		return $options;
	}

	/**
	 * Normalize app create options into the shape expected by the API layer.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 */
	private static function app_create_options( array $assoc_args ): array {
		$clone_all = self::flag( $assoc_args, 'clone-all' );

		return array_merge(
			array(
				'clone_from'    => self::optional_assoc( $assoc_args, 'clone-from' ),
				'clone_db'      => $clone_all || self::flag( $assoc_args, 'clone-db' ),
				'clone_themes'  => $clone_all || self::flag( $assoc_args, 'clone-themes' ),
				'clone_plugins' => $clone_all || self::flag( $assoc_args, 'clone-plugins' ),
				'clone_uploads' => $clone_all || self::flag( $assoc_args, 'clone-uploads' ),
			),
			self::git_tracking_changes( $assoc_args ),
			self::policy_changes( $assoc_args )
		);
	}
}
