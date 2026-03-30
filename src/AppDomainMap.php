<?php
/**
 * App domain map persistence.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps request-time domain lookup cheap while leaving domain validation and ownership to the app layer.
 */
class AppDomainMap {

	/**
	 * Absolute path to the apps directory.
	 *
	 * @var string
	 */
	private string $apps_dir;

	/**
	 * Constructor.
	 *
	 * @param string $apps_dir Apps directory.
	 */
	public function __construct( string $apps_dir ) {
		$this->apps_dir = $apps_dir;
	}

	/**
	 * Rebuild the domain map from a set of apps.
	 *
	 * @param Environment[] $apps App environments.
	 * @return void
	 */
	public function rebuild( array $apps ): void {
		$map = array();

		foreach ( $apps as $app ) {
			if ( empty( $app->domains ) ) {
				continue;
			}

			foreach ( $app->domains as $domain ) {
				if ( is_string( $domain ) && '' !== $domain ) {
					$map[ $this->normalize_domain( $domain ) ] = $app->id;
				}
			}
		}

		if ( ! is_dir( $this->apps_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating app metadata directory for runtime routing.
			mkdir( $this->apps_dir, 0755, true );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Persisting local runtime routing metadata.
		file_put_contents(
			$this->path(),
			json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable
	}

	/**
	 * Read the current domain map.
	 *
	 * @return array<string, string>
	 */
	public function read(): array {
		if ( ! file_exists( $this->path() ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local runtime routing metadata.
		$data = json_decode( file_get_contents( $this->path() ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$map = array();
		foreach ( $data as $domain => $id ) {
			if ( is_string( $domain ) && is_string( $id ) ) {
				$map[ $this->normalize_domain( $domain ) ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Return the absolute path to the domain map file.
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
