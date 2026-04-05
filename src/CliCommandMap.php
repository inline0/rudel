<?php
/**
 * Serializable catalog for translating WP-CLI commands into PHP operations.
 *
 * @package Rudel
 */

namespace Rudel;

use Rudel\CLI\AppCommand;
use Rudel\CLI\CleanupCommand;
use Rudel\CLI\LogsCommand;
use Rudel\CLI\PrCommand;
use Rudel\CLI\PushCommand;
use Rudel\CLI\RestoreCommand;
use Rudel\CLI\RudelCommand;
use Rudel\CLI\SnapshotCommand;
use Rudel\CLI\TemplateCommand;

/**
 * Exposes the CLI surface as stable metadata for agent and harness integrations.
 */
class CliCommandMap {

	/**
	 * Declarative CLI command catalog for harnesses and tooling.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array_values( self::definition_index() );
	}

	/**
	 * Resolve one command definition by path.
	 *
	 * @param string|array<int, string> $path Command path with or without "wp" and the root command.
	 * @return array<string, mixed>|null
	 */
	public static function definition( $path ): ?array {
		$key = self::path_key( self::normalize_path( $path ) );

		return self::definition_index()[ $key ] ?? null;
	}

	/**
	 * Convert one parsed CLI invocation into an execution plan.
	 *
	 * @param string|array<int, string> $path Command path with or without "wp" and the root command.
	 * @param array<int, string>        $args Positional arguments.
	 * @param array<string, mixed>      $assoc_args Associative arguments.
	 * @return array<string, mixed>
	 *
	 * @throws \InvalidArgumentException If the CLI path is not part of the published command surface.
	 * @throws \RuntimeException If the command adapter does not return a valid execution plan.
	 */
	public static function resolve( $path, array $args = array(), array $assoc_args = array() ): array {
		$definition = self::definition( $path );
		if ( ! $definition ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unknown Rudel CLI command path: %s', self::path_key( self::normalize_path( $path ) ) )
			);
		}

		$adapter = $definition['adapter'];
		$plan    = call_user_func( $adapter, $args, $assoc_args );
		if ( ! is_array( $plan ) ) {
			throw new \RuntimeException( sprintf( 'CLI adapter did not return a plan: %s', $adapter ) );
		}

		return array_merge(
			$definition,
			array(
				'transport'            => 'php',
				'arguments'            => array(),
				'needs_confirmation'   => false,
				'confirmation_message' => null,
			),
			$plan
		);
	}

	/**
	 * Build the indexed command catalog once per request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definition_index(): array {
		static $definitions = null;

		if ( null !== $definitions ) {
			return $definitions;
		}

		$definitions = array();

		foreach ( self::raw_definitions() as $definition ) {
			$definition['wp_cli_command']                             = 'wp ' . Rudel::cli_command() . ' ' . self::path_key( $definition['cli_path'] );
			$definitions[ self::path_key( $definition['cli_path'] ) ] = $definition;
		}

		return $definitions;
	}

	/**
	 * Define the supported command surface in one place so the harness can trust it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function raw_definitions(): array {
		return array(
			self::definition_item(
				'sandbox.create',
				array( 'create' ),
				RudelCommand::class,
				'create',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::create',
					),
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::create_from_github',
					),
				),
				CliCommandAdapters::class . '::sandbox_create',
				'Create a sandbox, optionally seeded from GitHub.'
			),
			self::definition_item(
				'sandbox.list',
				array( 'list' ),
				RudelCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::all',
					),
				),
				CliCommandAdapters::class . '::sandbox_list',
				'List sandboxes.'
			),
			self::definition_item(
				'sandbox.info',
				array( 'info' ),
				RudelCommand::class,
				'info',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::get',
					),
				),
				CliCommandAdapters::class . '::sandbox_info',
				'Read sandbox metadata.'
			),
			self::definition_item(
				'sandbox.destroy',
				array( 'destroy' ),
				RudelCommand::class,
				'destroy',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::destroy',
					),
				),
				CliCommandAdapters::class . '::sandbox_destroy',
				'Destroy a sandbox.'
			),
			self::definition_item(
				'sandbox.update',
				array( 'update' ),
				RudelCommand::class,
				'update',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::update',
					),
				),
				CliCommandAdapters::class . '::sandbox_update',
				'Update sandbox metadata and policy.'
			),
			self::definition_item(
				'system.status',
				array( 'status' ),
				RudelCommand::class,
				'status',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::status',
					),
				),
				CliCommandAdapters::class . '::system_status',
				'Read Rudel runtime status.'
			),
			self::definition_item(
				'sandbox.cleanup',
				array( 'cleanup' ),
				CleanupCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::cleanup',
					),
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::cleanup_merged',
					),
				),
				CliCommandAdapters::class . '::sandbox_cleanup',
				'Clean up sandboxes by policy or merge state.'
			),
			self::definition_item(
				'sandbox.logs',
				array( 'logs' ),
				LogsCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::read_log',
					),
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::clear_log',
					),
					array(
						'transport' => 'shell',
						'callable'  => 'tail -f',
					),
				),
				CliCommandAdapters::class . '::sandbox_logs',
				'Read, clear, or follow a sandbox log.'
			),
			self::definition_item(
				'sandbox.pr',
				array( 'pr' ),
				PrCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::pr',
					),
				),
				CliCommandAdapters::class . '::sandbox_pr',
				'Open a pull request from a sandbox branch.'
			),
			self::definition_item(
				'sandbox.push',
				array( 'push' ),
				PushCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::push',
					),
				),
				CliCommandAdapters::class . '::sandbox_push',
				'Push sandbox files to GitHub.'
			),
			self::definition_item(
				'sandbox.restore',
				array( 'restore' ),
				RestoreCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::restore',
					),
				),
				CliCommandAdapters::class . '::sandbox_restore',
				'Restore a sandbox snapshot.'
			),
			self::definition_item(
				'sandbox.snapshot',
				array( 'snapshot' ),
				SnapshotCommand::class,
				'__invoke',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::snapshot',
					),
				),
				CliCommandAdapters::class . '::sandbox_snapshot',
				'Create a sandbox snapshot.'
			),
			self::definition_item(
				'template.list',
				array( 'template', 'list' ),
				TemplateCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::templates',
					),
				),
				CliCommandAdapters::class . '::template_list',
				'List saved templates.'
			),
			self::definition_item(
				'template.save',
				array( 'template', 'save' ),
				TemplateCommand::class,
				'save',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::save_template',
					),
				),
				CliCommandAdapters::class . '::template_save',
				'Save a sandbox as a template.'
			),
			self::definition_item(
				'template.delete',
				array( 'template', 'delete' ),
				TemplateCommand::class,
				'delete',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::delete_template',
					),
				),
				CliCommandAdapters::class . '::template_delete',
				'Delete a template.'
			),
			self::definition_item(
				'app.create',
				array( 'app', 'create' ),
				AppCommand::class,
				'create',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::create_app',
					),
				),
				CliCommandAdapters::class . '::app_create',
				'Create a permanent app.'
			),
			self::definition_item(
				'app.list',
				array( 'app', 'list' ),
				AppCommand::class,
				'list_',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::apps',
					),
				),
				CliCommandAdapters::class . '::app_list',
				'List apps.'
			),
			self::definition_item(
				'app.info',
				array( 'app', 'info' ),
				AppCommand::class,
				'info',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::app',
					),
				),
				CliCommandAdapters::class . '::app_info',
				'Read app metadata.'
			),
			self::definition_item(
				'app.destroy',
				array( 'app', 'destroy' ),
				AppCommand::class,
				'destroy',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::destroy_app',
					),
				),
				CliCommandAdapters::class . '::app_destroy',
				'Destroy an app.'
			),
			self::definition_item(
				'app.update',
				array( 'app', 'update' ),
				AppCommand::class,
				'update',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::update_app',
					),
				),
				CliCommandAdapters::class . '::app_update',
				'Update app metadata and policy.'
			),
			self::definition_item(
				'app.create-sandbox',
				array( 'app', 'create-sandbox' ),
				AppCommand::class,
				'create_sandbox',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::create_sandbox_from_app',
					),
				),
				CliCommandAdapters::class . '::app_create_sandbox',
				'Create a sandbox from an app.'
			),
			self::definition_item(
				'app.backup',
				array( 'app', 'backup' ),
				AppCommand::class,
				'backup',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::backup_app',
					),
				),
				CliCommandAdapters::class . '::app_backup',
				'Create an app backup.'
			),
			self::definition_item(
				'app.backups',
				array( 'app', 'backups' ),
				AppCommand::class,
				'backups',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::app_backups',
					),
				),
				CliCommandAdapters::class . '::app_backups',
				'List app backups.'
			),
			self::definition_item(
				'app.deployments',
				array( 'app', 'deployments' ),
				AppCommand::class,
				'deployments',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::app_deployments',
					),
				),
				CliCommandAdapters::class . '::app_deployments',
				'List app deployment records.'
			),
			self::definition_item(
				'app.restore',
				array( 'app', 'restore' ),
				AppCommand::class,
				'restore',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::restore_app',
					),
				),
				CliCommandAdapters::class . '::app_restore',
				'Restore an app backup.'
			),
			self::definition_item(
				'app.deploy',
				array( 'app', 'deploy' ),
				AppCommand::class,
				'deploy',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::plan_app_deploy',
					),
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::deploy_sandbox_to_app',
					),
				),
				CliCommandAdapters::class . '::app_deploy',
				'Plan or deploy sandbox state into an app.'
			),
			self::definition_item(
				'app.rollback',
				array( 'app', 'rollback' ),
				AppCommand::class,
				'rollback',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::rollback_app_deployment',
					),
				),
				CliCommandAdapters::class . '::app_rollback',
				'Rollback an app deployment.'
			),
			self::definition_item(
				'app.domain-add',
				array( 'app', 'domain-add' ),
				AppCommand::class,
				'domain_add',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::add_app_domain',
					),
				),
				CliCommandAdapters::class . '::app_domain_add',
				'Attach an additional domain to an app.'
			),
			self::definition_item(
				'app.domain-remove',
				array( 'app', 'domain-remove' ),
				AppCommand::class,
				'domain_remove',
				array(
					array(
						'transport' => 'php',
						'callable'  => Rudel::class . '::remove_app_domain',
					),
				),
				CliCommandAdapters::class . '::app_domain_remove',
				'Remove a domain from an app.'
			),
		);
	}

	/**
	 * Build one definition record.
	 *
	 * @param string                            $operation Stable operation identifier.
	 * @param array<int, string>                $cli_path Relative command path below the root command.
	 * @param string                            $handler_class WP-CLI command class.
	 * @param string                            $handler_method Method or __invoke target.
	 * @param array<int, array<string, string>> $targets Potential execution targets.
	 * @param string                            $adapter Adapter callable.
	 * @param string                            $summary Short intent summary.
	 * @return array<string, mixed>
	 */
	private static function definition_item( string $operation, array $cli_path, string $handler_class, string $handler_method, array $targets, string $adapter, string $summary ): array {
		return array(
			'operation' => $operation,
			'cli_path'  => $cli_path,
			'handler'   => array(
				'class'  => $handler_class,
				'method' => $handler_method,
			),
			'targets'   => $targets,
			'adapter'   => $adapter,
			'summary'   => $summary,
		);
	}

	/**
	 * Normalize a command path so callers can pass raw shell segments or short paths.
	 *
	 * @param string|array<int, string> $path Raw command path.
	 * @return array<int, string>
	 */
	private static function normalize_path( $path ): array {
		if ( is_string( $path ) ) {
			$path = preg_split( '/\s+/', trim( $path ) );
			if ( false === $path ) {
				$path = array();
			}
		}

		$path = array_values(
			array_filter(
				array_map(
					static function ( string $segment ): string {
						return trim( $segment );
					},
					$path
				),
				static function ( string $segment ): bool {
					return '' !== $segment;
				}
			)
		);

		if ( isset( $path[0] ) && 'wp' === $path[0] ) {
			array_shift( $path );
		}

		if ( isset( $path[0] ) && Rudel::cli_command() === $path[0] ) {
			array_shift( $path );
		}

		return $path;
	}

	/**
	 * Collapse a path into the lookup key used throughout the catalog.
	 *
	 * @param array<int, string> $path Command path.
	 * @return string
	 */
	private static function path_key( array $path ): string {
		return implode( ' ', $path );
	}
}
