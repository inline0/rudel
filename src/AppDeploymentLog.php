<?php
/**
 * App deployment log management.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Persists app deployment records so deploys remain auditable after state changes.
 */
class AppDeploymentLog {

	/**
	 * App environment.
	 *
	 * @var Environment
	 */
	private Environment $app;

	/**
	 * Directory used to store deployment records.
	 *
	 * @var string
	 */
	private string $deployments_dir;

	/**
	 * Constructor.
	 *
	 * @param Environment $app App environment.
	 */
	public function __construct( Environment $app ) {
		$this->app             = $app;
		$this->deployments_dir = $app->path . '/deployments';
	}

	/**
	 * Record a deployment into the app.
	 *
	 * @param Environment $sandbox Source sandbox.
	 * @param array       $data Optional deployment metadata overrides.
	 * @return array<string, mixed>
	 */
	public function record( Environment $sandbox, array $data = array() ): array {
		$deployed_at = is_string( $data['deployed_at'] ?? null ) ? $data['deployed_at'] : gmdate( 'c' );
		$repo        = $data['github_repo'] ?? $sandbox->get_github_repo() ?? $this->app->get_github_repo();
		$record      = array(
			'id'                      => $this->generate_id( $deployed_at ),
			'deployed_at'             => $deployed_at,
			'app_id'                  => $this->app->id,
			'app_name'                => $this->app->name,
			'app_domains'             => $this->app->domains ?? array(),
			'sandbox_id'              => $sandbox->id,
			'sandbox_name'            => $sandbox->name,
			'source_environment_type' => $sandbox->type,
			'backup_name'             => $data['backup_name'] ?? null,
			'tables_copied'           => isset( $data['tables_copied'] ) ? (int) $data['tables_copied'] : null,
			'label'                   => $this->normalize_optional_string( $data['label'] ?? null ),
			'notes'                   => $this->normalize_optional_string( $data['notes'] ?? null ),
		);

		if ( null !== $repo ) {
			$record['github_repo']        = $repo;
			$record['github_branch']      = $data['github_branch'] ?? $sandbox->get_git_branch();
			$record['github_base_branch'] = $data['github_base_branch'] ?? $sandbox->get_github_base_branch() ?? $this->app->get_github_base_branch();
			$record['github_dir']         = $data['github_dir'] ?? $sandbox->get_github_dir() ?? $this->app->get_github_dir();
		}

		$this->write_record( $record );

		return $record;
	}

	/**
	 * List deployment records for the app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(): array {
		if ( ! is_dir( $this->deployments_dir ) ) {
			return array();
		}

		$records = array();
		$files   = scandir( $this->deployments_dir );

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file || ! str_ends_with( $file, '.json' ) ) {
				continue;
			}

			$record = $this->read_record( $this->deployments_dir . '/' . $file );
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}

		usort(
			$records,
			static function ( array $left, array $right ): int {
				$left_time  = strtotime( $left['deployed_at'] ?? '' ) ?: 0;
				$right_time = strtotime( $right['deployed_at'] ?? '' ) ?: 0;

				if ( $left_time === $right_time ) {
					return strcmp( $right['id'] ?? '', $left['id'] ?? '' );
				}

				return $right_time <=> $left_time;
			}
		);

		return $records;
	}

	/**
	 * Persist a deployment record.
	 *
	 * @param array<string, mixed> $record Deployment record payload.
	 * @return void
	 */
	private function write_record( array $record ): void {
		if ( ! is_dir( $this->deployments_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating deployment metadata directory.
			mkdir( $this->deployments_dir, 0755, true );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing local deployment metadata.
		file_put_contents(
			$this->deployments_dir . '/' . $record['id'] . '.json',
			json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable
	}

	/**
	 * Read a deployment record from disk.
	 *
	 * @param string $path Absolute file path.
	 * @return array<string, mixed>|null
	 */
	private function read_record( string $path ): ?array {
		if ( ! file_exists( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local deployment metadata.
		$data = json_decode( file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Generate a sortable deployment ID.
	 *
	 * @param string $deployed_at ISO 8601 deployment timestamp.
	 * @return string
	 */
	private function generate_id( string $deployed_at ): string {
		$timestamp = strtotime( $deployed_at );
		$prefix    = false !== $timestamp ? gmdate( 'Ymd_His', $timestamp ) : gmdate( 'Ymd_His' );
		return 'deploy-' . $prefix . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
	}

	/**
	 * Normalize an optional freeform string.
	 *
	 * @param mixed $value Raw input.
	 * @return string|null
	 */
	private function normalize_optional_string( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
