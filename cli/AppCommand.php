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
 * Manage Rudel apps.
 */
class AppCommand extends \WP_CLI_Command {

	use HandlesEnvironmentPolicy;

	/**
	 * App manager instance.
	 *
	 * @var AppManager
	 */
	private AppManager $manager;

	/**
	 * Initialize dependencies.
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
	 * : Primary domain metadata for the app.
	 *
	 * [--name=<name>]
	 * : Human-readable name. Derived from domain if omitted.
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
	 * [--clone-from=<id>]
	 * : Clone from an existing sandbox or app. Mutually exclusive with --clone-db/--clone-all.
	 *
	 * [--github=<repo>]
	 * : Track a GitHub repository in owner/repo format for sandbox push and PR workflows.
	 *
	 * [--branch=<branch>]
	 * : Stable mainline branch for deployments and app-derived sandbox branches.
	 *
	 * [--dir=<dir>]
	 * : Optional wp-content subdirectory tied to the tracked repository.
	 *
	 * [--owner=<owner>]
	 * : Optional owner for stewardship and policy.
	 *
	 * [--labels=<labels>]
	 * : Comma-separated labels for grouping.
	 *
	 * [--purpose=<purpose>]
	 * : Optional description of why the app exists.
	 *
	 * [--protected]
	 * : Mark the app as protected metadata.
	 *
	 * [--ttl-days=<days>]
	 * : Set an expiry relative to creation time.
	 *
	 * [--expires-at=<timestamp>]
	 * : Set an explicit expiry timestamp.
	 *
	 * @param array $args Positional arguments.
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
		$options   = array_merge(
			array(
				'clone_from'    => $assoc_args['clone-from'] ?? null,
				'clone_db'      => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-db', false ),
				'clone_themes'  => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-themes', false ),
				'clone_plugins' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-plugins', false ),
				'clone_uploads' => $clone_all || \WP_CLI\Utils\get_flag_value( $assoc_args, 'clone-uploads', false ),
			),
			$this->build_git_tracking_changes( $assoc_args ),
			$this->build_policy_changes( $assoc_args )
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
		if ( $app->tracked_github_repo ) {
			WP_CLI::log( '  GitHub: ' . $app->tracked_github_repo );
		}
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
	 * @param array $args Positional arguments.
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
					'id'        => $app->id,
					'name'      => $app->name,
					'owner'     => $app->owner ?? '',
					'protected' => $this->format_protection( $app->is_protected() ),
					'domains'   => implode( ', ', $app->domains ?? array() ),
					'status'    => $app->status,
					'created'   => $app->created_at,
				);
			},
			$apps
		);

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array( 'id', 'name', 'owner', 'protected', 'domains', 'status', 'created' ) );
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
	 * @param array $args Positional arguments.
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

		$data                = $app->to_array();
		$data['domains']     = implode( ', ', $app->domains ?? array() );
		$data['url']         = $app->get_url();
		$data['backups']     = count( $this->manager->backups( $id ) );
		$data['deployments'] = count( $this->manager->deployments( $id ) );

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
	 * @param array $args Positional arguments.
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
	 * Update app metadata and policy.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * [--owner=<owner>]
	 * : Set or clear the owner.
	 *
	 * [--labels=<labels>]
	 * : Comma-separated labels.
	 *
	 * [--purpose=<purpose>]
	 * : Set or clear the purpose.
	 *
	 * [--protected]
	 * : Mark the app as protected metadata.
	 *
	 * [--unprotected]
	 * : Remove protection metadata.
	 *
	 * [--ttl-days=<days>]
	 * : Set an expiry relative to now.
	 *
	 * [--expires-at=<timestamp>]
	 * : Set an explicit expiry timestamp.
	 *
	 * [--clear-expiry]
	 * : Remove any explicit expiry.
	 *
	 * [--github=<repo>]
	 * : Track a GitHub repository in owner/repo format.
	 *
	 * [--branch=<branch>]
	 * : Stable mainline branch for deployments and app-derived sandbox branches.
	 *
	 * [--dir=<dir>]
	 * : Optional wp-content subdirectory tied to the tracked repository.
	 *
	 * [--clear-github]
	 * : Remove tracked GitHub metadata.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ): void {
		$id      = $args[0];
		$changes = array_merge(
			$this->build_policy_changes( $assoc_args ),
			$this->build_git_tracking_changes( $assoc_args )
		);

		if ( empty( $changes ) ) {
			WP_CLI::error( 'No metadata changes were provided.' );
		}

		try {
			$app = $this->manager->update( $id, $changes );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "App updated: {$app->id}" );
		WP_CLI::log( '' );
		WP_CLI::log( '  Owner:     ' . ( $app->owner ?? '-' ) );
		WP_CLI::log( '  Protected: ' . $this->format_protection( $app->is_protected() ) );
		WP_CLI::log( '  Expires:   ' . ( $app->expires_at ?? '-' ) );
		WP_CLI::log( '  GitHub:    ' . ( $app->tracked_github_repo ?? '-' ) );
	}

	/**
	 * Create a sandbox from an app.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * [--name=<name>]
	 * : Sandbox name. Defaults to "<app name> Sandbox".
	 *
	 * [--owner=<owner>]
	 * : Optional owner for the sandbox.
	 *
	 * [--labels=<labels>]
	 * : Comma-separated labels for the sandbox.
	 *
	 * [--purpose=<purpose>]
	 * : Optional description of why the sandbox exists.
	 *
	 * [--protected]
	 * : Exclude the sandbox from automated cleanup.
	 *
	 * [--ttl-days=<days>]
	 * : Set an expiry relative to creation time.
	 *
	 * [--expires-at=<timestamp>]
	 * : Set an explicit expiry timestamp.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand create-sandbox
	 * @when after_wp_load
	 */
	public function create_sandbox( $args, $assoc_args ): void {
		$id  = $args[0];
		$app = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$name    = $assoc_args['name'] ?? "{$app->name} Sandbox";
		$options = $this->build_policy_changes( $assoc_args );

		try {
			$sandbox = $this->manager->create_sandbox( $id, $name, $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox created from app: {$sandbox->id}" );
		WP_CLI::log( '' );
		WP_CLI::log( "  App:     {$app->id}" );
		WP_CLI::log( "  Path:    {$sandbox->path}" );
		WP_CLI::log( "  URL:     {$sandbox->get_url()}" );
	}

	/**
	 * Create an app backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --name=<name>
	 * : Backup name.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function backup( $args, $assoc_args ): void {
		$id   = $args[0];
		$name = $assoc_args['name'];

		try {
			$backup = $this->manager->backup( $id, $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "App backup created: {$backup['name']}" );
	}

	/**
	 * List backups for an app.
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
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand backups
	 * @when after_wp_load
	 */
	public function backups( $args, $assoc_args ): void {
		$id      = $args[0];
		$backups = $this->manager->backups( $id );

		if ( empty( $backups ) ) {
			WP_CLI::log( 'No backups found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $backups, array_keys( $backups[0] ) );
	}

	/**
	 * List deployment records for an app.
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
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand deployments
	 * @when after_wp_load
	 */
	public function deployments( $args, $assoc_args ): void {
		$id          = $args[0];
		$deployments = $this->manager->deployments( $id );

		if ( empty( $deployments ) ) {
			WP_CLI::log( 'No deployments found.' );
			return;
		}

		$items = array_map(
			static function ( array $deployment ): array {
				$deployment['app_domains']        = implode( ', ', $deployment['app_domains'] ?? array() );
				$deployment['github_repo']        = $deployment['github_repo'] ?? '';
				$deployment['github_branch']      = $deployment['github_branch'] ?? '';
				$deployment['github_base_branch'] = $deployment['github_base_branch'] ?? '';
				$deployment['github_dir']         = $deployment['github_dir'] ?? '';
				$deployment['label']              = $deployment['label'] ?? '';
				$deployment['notes']              = $deployment['notes'] ?? '';
				$deployment['backup_name']        = $deployment['backup_name'] ?? '';
				return $deployment;
			},
			$deployments
		);

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $items, array_keys( $items[0] ) );
	}

	/**
	 * Restore an app from a backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --backup=<name>
	 * : Backup name to restore from.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc_args ): void {
		$id          = $args[0];
		$backup_name = $assoc_args['backup'];
		$app         = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( ! $force ) {
			WP_CLI::warning( "This will replace app '{$app->name}' with backup '{$backup_name}'." );
			WP_CLI::confirm( 'Are you sure?', $assoc_args );
		}

		try {
			$this->manager->restore( $id, $backup_name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "App restored from backup: {$backup_name}" );
	}

	/**
	 * Deploy a sandbox into an app.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --from=<sandbox-id>
	 * : Sandbox to deploy into the app.
	 *
	 * [--backup=<name>]
	 * : Backup name to create before deploying. Defaults to pre-deploy-{timestamp}.
	 *
	 * [--label=<label>]
	 * : Optional release label stored with the deployment record.
	 *
	 * [--notes=<notes>]
	 * : Optional operator notes stored with the deployment record.
	 *
	 * [--dry-run]
	 * : Show the deploy plan without changing the app.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function deploy( $args, $assoc_args ): void {
		$id         = $args[0];
		$sandbox_id = $assoc_args['from'];
		$app        = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$backup_name = $assoc_args['backup'] ?? null;
		$options     = array(
			'label' => $assoc_args['label'] ?? null,
			'notes' => $assoc_args['notes'] ?? null,
		);

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			try {
				$plan = $this->manager->plan_deploy( $id, $sandbox_id, $backup_name, $options );
			} catch ( \Throwable $e ) {
				WP_CLI::error( $e->getMessage() );
			}

			$items = array();
			foreach ( $plan as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				}

				$items[] = array(
					'Field' => $key,
					'Value' => (string) $value,
				);
			}

			WP_CLI::success( 'Deploy plan generated.' );
			WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
			return;
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( ! $force ) {
			WP_CLI::warning( "This will replace app '{$app->name}' with sandbox '{$sandbox_id}'." );
			WP_CLI::log( 'A backup of the current app will be created before deploying.' );
			WP_CLI::confirm( 'Are you sure?', $assoc_args );
		}

		try {
			$result = $this->manager->deploy(
				$id,
				$sandbox_id,
				$backup_name,
				$options
			);
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Sandbox deployed to app: {$id}" );
		WP_CLI::log( '' );
		WP_CLI::log( "  Sandbox: {$result['sandbox_id']}" );
		WP_CLI::log( "  Backup:  {$result['backup']['name']}" );
		WP_CLI::log( "  Tables:  {$result['tables_copied']}" );
		WP_CLI::log( "  Deploy:  {$result['deployment']['id']}" );
	}

	/**
	 * Roll an app back to the backup captured by a deployment record.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : App ID.
	 *
	 * --deployment=<deployment-id>
	 * : Deployment record to roll back.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function rollback( $args, $assoc_args ): void {
		$id            = $args[0];
		$deployment_id = $assoc_args['deployment'];
		$app           = $this->manager->get( $id );

		if ( ! $app ) {
			WP_CLI::error( "App not found: {$id}" );
		}

		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( ! $force ) {
			WP_CLI::warning( "This will restore app '{$app->name}' from deployment '{$deployment_id}'." );
			WP_CLI::confirm( 'Are you sure?', $assoc_args );
		}

		try {
			$result = $this->manager->rollback( $id, $deployment_id );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "App rolled back: {$id}" );
		WP_CLI::log( '' );
		WP_CLI::log( "  Deployment: {$result['deployment_id']}" );
		WP_CLI::log( "  Backup:     {$result['backup_name']}" );
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
	 * @param array $args Positional arguments.
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
	 * @param array $args Positional arguments.
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

	/**
	 * Build tracked GitHub metadata changes from CLI arguments.
	 *
	 * @param array $assoc_args CLI associative arguments.
	 * @return array<string, mixed>
	 */
	private function build_git_tracking_changes( array $assoc_args ): array {
		$changes = array();
		$clear   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'clear-github', false );

		if ( array_key_exists( 'github', $assoc_args ) ) {
			$changes['github'] = $assoc_args['github'];
		}

		if ( array_key_exists( 'branch', $assoc_args ) ) {
			$changes['branch'] = $assoc_args['branch'];
		}

		if ( array_key_exists( 'dir', $assoc_args ) ) {
			$changes['dir'] = $assoc_args['dir'];
		}

		if ( $clear && ! empty( $changes ) ) {
			WP_CLI::error( 'Cannot combine --clear-github with --github, --branch, or --dir.' );
		}

		if ( $clear ) {
			$changes['clear_github'] = true;
		}

		return $changes;
	}
}
