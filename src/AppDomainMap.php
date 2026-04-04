<?php
/**
 * App domain map persistence.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Reads the current app-domain mapping from DB-backed app records.
 */
class AppDomainMap {

	/**
	 * Absolute path to the apps directory.
	 *
	 * @var string
	 */
	private string $apps_dir;

	/**
	 * Runtime store.
	 *
	 * @var DatabaseStore
	 */
	private DatabaseStore $store;

	/**
	 * App repository.
	 *
	 * @var AppRepository
	 */
	private AppRepository $apps;

	/**
	 * Initialize dependencies.
	 *
	 * @param string $apps_dir Apps directory.
	 */
	public function __construct( string $apps_dir ) {
		$this->apps_dir = $apps_dir;
		$this->store    = RudelDatabase::for_paths( $apps_dir );
		$repository     = new EnvironmentRepository( $this->store, $apps_dir, 'app' );
		$this->apps     = new AppRepository( $this->store, $repository );
	}

	/**
	 * Rebuild the domain map from a set of apps.
	 *
	 * @param Environment[] $apps App environments.
	 * @return void
	 */
	public function rebuild( array $apps ): void {
		unset( $apps );
	}

	/**
	 * Read the current domain map.
	 *
	 * @return array<string, string>
	 */
	public function read(): array {
		$map = array();

		foreach ( $this->apps->all() as $app ) {
			foreach ( $app->domains ?? array() as $domain ) {
				if ( is_string( $domain ) && '' !== $domain ) {
					$map[ $this->normalize_domain( $domain ) ] = $app->id;
				}
			}
		}

		return $map;
	}

	/**
	 * Legacy compiled-map path for tooling that still references it.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->apps_dir . '/domains.json';
	}

	/**
	 * Normalize domains to the runtime representation the bootstrap expects.
	 *
	 * @param string $domain Domain value.
	 * @return string
	 */
	private function normalize_domain( string $domain ): string {
		return strtolower( trim( $domain ) );
	}
}
