<?php
/**
 * Environment metadata repository.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps environment lookup and persistence in one place so orchestration code can stay focused on behavior.
 */
class EnvironmentRepository {

	/**
	 * Primary directory containing the environments this repository owns.
	 *
	 * @var string
	 */
	private string $environments_dir;

	/**
	 * Secondary directory used for cross-type resolution when cloning between sandboxes and apps.
	 *
	 * @var string
	 */
	private string $alternate_environments_dir;

	/**
	 * Constructor.
	 *
	 * @param string      $environments_dir Primary environments directory.
	 * @param string|null $alternate_environments_dir Optional related environments directory.
	 */
	public function __construct( string $environments_dir, ?string $alternate_environments_dir = null ) {
		$this->environments_dir           = $environments_dir;
		$this->alternate_environments_dir = $alternate_environments_dir ?? '';
	}

	/**
	 * List all environments stored under the primary directory.
	 *
	 * @return Environment[]
	 */
	public function all(): array {
		if ( ! is_dir( $this->environments_dir ) ) {
			return array();
		}

		$environments = array();
		$dirs         = scandir( $this->environments_dir );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$path = $this->environments_dir . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$environment = Environment::from_path( $path );
			if ( $environment ) {
				$environments[] = $environment;
			}
		}

		return $environments;
	}

	/**
	 * Resolve an environment by ID from the primary directory.
	 *
	 * @param string $id Environment identifier.
	 * @return Environment|null
	 */
	public function get( string $id ): ?Environment {
		if ( ! Environment::validate_id( $id ) ) {
			return null;
		}

		return Environment::from_path( $this->path_for( $id ) );
	}

	/**
	 * Resolve an environment by checking both the primary and alternate directories.
	 *
	 * @param string $id Environment identifier.
	 * @return Environment|null
	 */
	public function resolve( string $id ): ?Environment {
		$environment = $this->get( $id );
		if ( $environment ) {
			return $environment;
		}

		if ( '' === $this->alternate_environments_dir || $this->alternate_environments_dir === $this->environments_dir ) {
			return null;
		}

		if ( ! Environment::validate_id( $id ) ) {
			return null;
		}

		return Environment::from_path( $this->alternate_environments_dir . '/' . $id );
	}

	/**
	 * Persist environment metadata.
	 *
	 * @param Environment $environment Environment instance to save.
	 * @return void
	 */
	public function save( Environment $environment ): void {
		$environment->save_meta();
	}

	/**
	 * Return the absolute path for an environment ID in the primary directory.
	 *
	 * @param string $id Environment identifier.
	 * @return string
	 */
	public function path_for( string $id ): string {
		return $this->environments_dir . '/' . $id;
	}

	/**
	 * Return the primary environments directory.
	 *
	 * @return string
	 */
	public function environments_dir(): string {
		return $this->environments_dir;
	}
}
