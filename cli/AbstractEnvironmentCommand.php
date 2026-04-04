<?php
/**
 * Shared helpers for Rudel WP-CLI commands.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\Environment;
use Rudel\EnvironmentManager;
use WP_CLI;

/**
 * Base class for commands that operate on Rudel environments.
 */
abstract class AbstractEnvironmentCommand extends \WP_CLI_Command {

	/**
	 * Environment manager instance.
	 *
	 * @var EnvironmentManager
	 */
	protected EnvironmentManager $manager;

	/**
	 * Initialize dependencies.
	 *
	 * @param EnvironmentManager|null $manager Optional manager instance for dependency injection.
	 */
	public function __construct( ?EnvironmentManager $manager = null ) {
		$this->manager = $manager ?? new EnvironmentManager();
	}

	/**
	 * Resolve an environment or abort the command.
	 *
	 * @param string $id Environment ID.
	 * @return Environment
	 */
	protected function require_environment( string $id ): Environment {
		$environment = $this->manager->get( $id );
		if ( ! $environment ) {
			WP_CLI::error( "Sandbox not found: {$id}" );
		}

		return $environment;
	}

	/**
	 * Format a byte count into a human-readable string.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string Formatted size string.
	 */
	protected function format_size( int $bytes ): string {
		$units      = array( 'B', 'KB', 'MB', 'GB' );
		$i          = 0;
		$size       = (float) $bytes;
		$unit_count = count( $units );
		while ( $size >= 1024 && $i < $unit_count - 1 ) {
			$size /= 1024;
			++$i;
		}
		return round( $size, 1 ) . ' ' . $units[ $i ];
	}
}
