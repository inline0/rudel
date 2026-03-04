<?php
/**
 * WP-CLI commands for Rudel template management.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\SandboxManager;
use Rudel\TemplateManager;
use WP_CLI;

/**
 * Manage Rudel sandbox templates.
 *
 * ## EXAMPLES
 *
 *     # Save a sandbox as a template
 *     $ wp rudel template save my-sandbox-a1b2 --name=starter
 *
 *     # List all templates
 *     $ wp rudel template list
 *
 *     # Delete a template
 *     $ wp rudel template delete starter --force
 */
class TemplateCommand extends \WP_CLI_Command {

	/**
	 * Template manager instance.
	 *
	 * @var TemplateManager
	 */
	private TemplateManager $template_manager;

	/**
	 * Sandbox manager instance.
	 *
	 * @var SandboxManager
	 */
	private SandboxManager $sandbox_manager;

	/**
	 * Constructor.
	 *
	 * @param TemplateManager|null $template_manager Optional template manager for dependency injection.
	 * @param SandboxManager|null  $sandbox_manager  Optional sandbox manager for dependency injection.
	 */
	public function __construct( ?TemplateManager $template_manager = null, ?SandboxManager $sandbox_manager = null ) {
		$this->template_manager = $template_manager ?? new TemplateManager();
		$this->sandbox_manager  = $sandbox_manager ?? new SandboxManager();
	}

	/**
	 * List all templates.
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
	 * ## EXAMPLES
	 *
	 *     $ wp rudel template list
	 *     $ wp rudel template list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @subcommand list
	 * @when after_wp_load
	 */
	public function list_( $args, $assoc_args ): void {
		$templates = $this->template_manager->list_templates();

		if ( empty( $templates ) ) {
			WP_CLI::log( 'No templates found.' );
			return;
		}

		$format = $assoc_args['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $templates, array( 'name', 'description', 'source_sandbox_id', 'created_at' ) );
	}

	/**
	 * Save a sandbox as a template.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID to save as template.
	 *
	 * --name=<name>
	 * : Template name.
	 *
	 * [--description=<description>]
	 * : Optional template description.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel template save my-sandbox-a1b2 --name=starter
	 *     Success: Template saved: starter
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function save( $args, $assoc_args ): void {
		$id      = $args[0];
		$sandbox = $this->sandbox_manager->get( $id );

		if ( ! $sandbox ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		$name        = $assoc_args['name'];
		$description = $assoc_args['description'] ?? '';

		try {
			$meta = $this->template_manager->save( $sandbox, $name, $description );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "Template saved: {$meta['name']}" );
	}

	/**
	 * Delete a template.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Template name to delete.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel template delete starter --force
	 *     Success: Template deleted: starter
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ): void {
		$name  = $args[0];
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! $force ) {
			WP_CLI::confirm( "Are you sure you want to delete template '{$name}'?" );
		}

		if ( $this->template_manager->delete( $name ) ) {
			WP_CLI::success( "Template deleted: {$name}" );
		} else {
			WP_CLI::error( "Template not found: {$name}" );
		}
	}
}
