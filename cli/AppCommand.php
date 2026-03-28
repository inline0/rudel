<?php
/**
 * WP-CLI commands for Rudel app management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\AppManager;
use WP_CLI;

/**
 * Manage Rudel apps (permanent domain-routed environments).
 */
class AppCommand extends \WP_CLI_Command {

	/**
	 * App manager instance.
	 *
	 * @var AppManager
	 */
	private AppManager $manager;

	/**
	 * Constructor.
	 *
	 * @param AppManager|null $manager Optional manager for dependency injection.
	 */
	public function __construct( ?AppManager $manager = null ) {
		$this->manager = $manager ?? new AppManager();
	}

	/**
	 * Create a new app.
	 *
	 * ## OPTIONS
	 *
	 * --domain=<domain>
	 * : Primary domain for the app.
	 *
	 * [--name=<name>]
	 * : Human-readable name. Derived from domain if omitted.
	 *
	 * [--engine=<engine>]
	 * : Database engine.
	 * ---
	 * default: mysql
	 * options:
	 *   - mysql
	 *   - sqlite
	 * ---
	 *
	 * [--clone-db]
	 * : Clone the host database.
	 *
	 * [--clone-themes]
	 * : Copy host themes.
	 *
	 * [--clone-plugins]
	 * : Copy host plugins.
	 *
	 * [--clone-uploads]
	 * : Copy host uploads.
	 *
	 * [--clone-all]
	 * : Clone everything.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel app create --domain=client-a.com
	 *     Success: App created: client-a-com-a1b2 (client-a.com)
	 *
	 *     $ wp rudel app create --domain=client-b.com --clone-all
	 *     Success: App created: client-b-com-c3d4 (client-b.com)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @throws \RuntimeException If creation fails.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ): void {
		$domain = $assoc_args['domain'];
		$name   = $assoc_args['name'] ?? str_replace( '.', '-', $domain );

		$clone_all = \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-all', false );
		$options   = array(
			'engine'        => $assoc_args['engine'] ?? 'mysql',
			'clone_db'      => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-db', false ),
			'clone_themes'  => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-themes', false ),
			'clone_plugins' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-plugins', false ),
			'clone_uploads' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-uploads', false ),
		);

		WP_CLI::log( "Creating app '{$name}' for {$domain}..." );

		try {
			$app = $this->manager->create( $name, array( $domain ), $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "App created: {$app->id} ({$domain})" );
		WP_CLI::log( '' );
		WP_CLI::log( "  Path:   {$app->path}" );
		WP_CLI::log( "  Domain: {$domain}" );
		WP_CLI::log( "  Engine: {$app->engine}" );
	}

	/**
	 * List all apps.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ): void {
		$apps = $this->manager->list();

		if ( empty( $apps ) ) {
			WP_CLI::log( 'No apps found.' );
			return;
		}

		$items = array_map(
			function ( $app ) {
				return array(
					'id'      => $app->id,
					'name'    => $app->name,
					'domains' => implode( ', ', $app->domains ?? array() ),
					'engine'  => $app->engine,
					'status'  => $app->status,
					'created' => $app->created_at,
				);
			},
			$apps
		);

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'domains', 'engine', 'status', 'created' ) );
	}

	/**
	 * Show app details.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function info( $args, $assoc_args ): void {
		$id  = $args[0];
		$app = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$data            = $app->to_array();
		$data['domains'] = implode( ', ', $app->domains ?? array() );
		$data['url']     = $app->get_url();

		$format = $assoc_args['format'] ?? 'table';

		if ( 'table' === $format ) {
			$items = array();
			foreach ( $data as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}
				$items[] = array(
					'Field' => $key,
					'Value' => $value,
				);
			}
			WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
		} else {
			WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
		}
	}

	/**
	 * Destroy an app.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID to destroy.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function destroy( $args, $assoc_args ): void {
		$id  = $args[0];
		$app = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			$domains = implode( ', ', $app->domains ?? array() );
			WP_CLI::warning( "This will permanently destroy app '{$app->name}' ({$domains})." );
			WP_CLI::confirm( 'Are you sure?', $assoc_args );
		}

		if ( $this->manager->destroy( $id ) ) {
			WP_CLI::success( "App destroyed: {$id}" );
		} else {
			WP_CLI::error( "Failed to destroy app: {$id}" );
		}
	}

	/**
	 * Add a domain to an app.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --domain=<domain>
	 * : Domain to add.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand domain-add
	 * @when after_wp_load
	 */
	public function domain_add( $args, $assoc_args ): void {
		$id     = $args[0];
		$domain = $assoc_args['domain'];

		try {
			$this->manager->add_domain( $id, $domain );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Domain added: {$domain}" );
	}

	/**
	 * Remove a domain from an app.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --domain=<domain>
	 * : Domain to remove.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand domain-remove
	 * @when after_wp_load
	 */
	public function domain_remove( $args, $assoc_args ): void {
		$id     = $args[0];
		$domain = $assoc_args['domain'];

		try {
			$this->manager->remove_domain( $id, $domain );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Domain removed: {$domain}" );
	}
}
