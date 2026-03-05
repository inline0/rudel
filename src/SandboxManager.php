<?php
/**
 * Sandbox CRUD orchestrator.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Creates, lists, retrieves, and destroys sandbox environments.
 */
class SandboxManager {

	/**
	 * Absolute path to the sandboxes directory.
	 *
	 * @var string
	 */
	private string $sandboxes_dir;

	/**
	 * Absolute path to the Rudel plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $sandboxes_dir Optional override for the sandboxes directory.
	 */
	public function __construct( ?string $sandboxes_dir = null ) {
		$this->plugin_dir    = defined( 'RUDEL_PLUGIN_DIR' ) ? RUDEL_PLUGIN_DIR : dirname( __DIR__ ) . '/';
		$this->sandboxes_dir = $sandboxes_dir ?? $this->get_default_sandboxes_dir();
	}

	/**
	 * Create a new sandbox.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $options Optional settings (template, etc.).
	 * @return Sandbox The newly created sandbox.
	 *
	 * @throws \RuntimeException If the directory already exists or creation fails.
	 * @throws \InvalidArgumentException If conflicting clone options are provided.
	 * @throws \Throwable If any step after directory creation fails (directory is cleaned up).
	 */
	public function create( string $name, array $options = array() ): Sandbox {
		if ( empty( $options['skip_limits'] ) ) {
			$this->check_limits();
		}

		$id   = Sandbox::generate_id( $name );
		$path = $this->sandboxes_dir . '/' . $id;

		if ( is_dir( $path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException(
				sprintf( 'Sandbox directory already exists: %s', $path )
			);
		}

		$clone_from    = $options['clone_from'] ?? null;
		$clone_db      = ! empty( $options['clone_db'] );
		$clone_themes  = ! empty( $options['clone_themes'] );
		$clone_plugins = ! empty( $options['clone_plugins'] );
		$clone_uploads = ! empty( $options['clone_uploads'] );
		$has_clone     = $clone_db || $clone_themes || $clone_plugins || $clone_uploads;

		if ( $clone_from && $has_clone ) {
			throw new \InvalidArgumentException( 'Cannot combine --clone-from with --clone-db, --clone-themes, --clone-plugins, or --clone-uploads.' );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Direct filesystem operations for sandbox scaffolding.
		if ( ! is_dir( $this->sandboxes_dir ) ) {
			mkdir( $this->sandboxes_dir, 0755, true );
		}

		if ( ! mkdir( $path, 0755 ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create sandbox directory: %s', $path ) );
		}
		// phpcs:enable

		try {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $path . '/wp-content', 0755 );
			mkdir( $path . '/wp-content/themes', 0755 );
			mkdir( $path . '/wp-content/plugins', 0755 );
			mkdir( $path . '/wp-content/uploads', 0755 );
			mkdir( $path . '/wp-content/mu-plugins', 0755 );
			mkdir( $path . '/tmp', 0755 );
			// phpcs:enable

			$this->ensure_sqlite_integration();
			$this->write_db_drop_in( $path );
			$this->write_sandbox_bootstrap( $id, $path );
			$this->write_wp_cli_yml( $path );
			$this->write_claude_md( $id, $name, $path );

			$clone_source     = null;
			$is_multisite     = false;
			$template         = $options['template'] ?? ( $has_clone || $clone_from ? 'clone' : 'blank' );
			$is_from_template = ! in_array( $template, array( 'blank', 'clone' ), true )
				&& ! $clone_from && ! $has_clone
				&& $this->template_exists( $template );

			if ( $is_from_template ) {
				$this->initialize_from_template( $template, $id, $path );
			} elseif ( $clone_from ) {
				$source = $this->get( $clone_from );
				if ( ! $source ) {
					throw new \RuntimeException( sprintf( 'Source sandbox not found: %s', $clone_from ) );
				}
				$clone_source = $this->clone_from_sandbox( $source, $id, $path );
			} elseif ( $clone_db ) {
				$table_prefix = 'wp_' . substr( md5( $id ), 0, 6 ) . '_';
				$site_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
				$sandbox_url  = $site_url . '/__rudel/' . $id;

				$db_cloner    = new DatabaseCloner( $this->plugin_dir );
				$clone_result = $db_cloner->clone_database(
					$path . '/wordpress.db',
					$table_prefix,
					$sandbox_url,
					array( 'chunk_size' => $options['chunk_size'] ?? 500 )
				);

				$is_multisite = ! empty( $clone_result['is_multisite'] );

				$clone_source = array(
					'host_url'       => $site_url,
					'cloned_at'      => gmdate( 'c' ),
					'db_cloned'      => true,
					'themes_cloned'  => $clone_themes,
					'plugins_cloned' => $clone_plugins,
					'uploads_cloned' => $clone_uploads,
					'tables_cloned'  => $clone_result['tables_cloned'],
					'rows_cloned'    => $clone_result['rows_cloned'],
				);

				if ( $is_multisite ) {
					$clone_source['multisite'] = true;
				}
			} else {
				$this->create_blank_database( $id, $path );

				if ( $has_clone ) {
					$site_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
					$clone_source = array(
						'host_url'       => $site_url,
						'cloned_at'      => gmdate( 'c' ),
						'db_cloned'      => false,
						'themes_cloned'  => $clone_themes,
						'plugins_cloned' => $clone_plugins,
						'uploads_cloned' => $clone_uploads,
					);
				}
			}

			if ( $clone_themes || $clone_plugins || $clone_uploads ) {
				$content_cloner = new ContentCloner();
				$content_cloner->clone_content(
					$path . '/wp-content',
					array(
						'themes'  => $clone_themes,
						'plugins' => $clone_plugins,
						'uploads' => $clone_uploads,
					)
				);
			}
		} catch ( \Throwable $e ) {
			$this->delete_directory( $path );
			throw $e;
		}

		if ( $is_multisite ) {
			$this->write_sandbox_bootstrap( $id, $path, true );
		}

		$sandbox = new Sandbox(
			id: $id,
			name: $name,
			path: $path,
			created_at: gmdate( 'c' ),
			template: $template,
			status: 'active',
			clone_source: $clone_source,
			multisite: $is_multisite,
		);
		$sandbox->save_meta();

		return $sandbox;
	}

	/**
	 * List all sandboxes.
	 *
	 * @return Sandbox[] Array of sandbox instances.
	 */
	public function list(): array {
		if ( ! is_dir( $this->sandboxes_dir ) ) {
			return array();
		}

		$sandboxes = array();
		$dirs      = scandir( $this->sandboxes_dir );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$path = $this->sandboxes_dir . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$sandbox = Sandbox::from_path( $path );
			if ( $sandbox ) {
				$sandboxes[] = $sandbox;
			}
		}

		return $sandboxes;
	}

	/**
	 * Get a single sandbox by ID.
	 *
	 * @param string $id Sandbox identifier.
	 * @return Sandbox|null Sandbox instance or null if not found.
	 */
	public function get( string $id ): ?Sandbox {
		if ( ! Sandbox::validate_id( $id ) ) {
			return null;
		}

		$path = $this->sandboxes_dir . '/' . $id;
		return Sandbox::from_path( $path );
	}

	/**
	 * Destroy a sandbox by ID.
	 *
	 * @param string $id Sandbox identifier.
	 * @return bool True on success.
	 */
	public function destroy( string $id ): bool {
		$sandbox = $this->get( $id );
		if ( ! $sandbox ) {
			return false;
		}

		return $this->delete_directory( $sandbox->path );
	}

	/**
	 * Export a sandbox as a zip archive.
	 *
	 * @param string $id          Sandbox identifier.
	 * @param string $output_path Absolute path for the output zip file.
	 * @return void
	 *
	 * @throws \RuntimeException If the sandbox is not found or export fails.
	 */
	public function export( string $id, string $output_path ): void {
		$sandbox = $this->get( $id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $id ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $output_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create zip archive: %s', $output_path ) );
		}

		$base_path = $sandbox->path;
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $base_path ) + 1 );

			// Skip snapshots directory.
			if ( str_starts_with( $relative, 'snapshots/' ) || 'snapshots' === $relative ) {
				continue;
			}

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $relative );
			} else {
				$zip->addFile( $item->getPathname(), $relative );
			}
		}

		$zip->close();
	}

	/**
	 * Import a sandbox from a zip archive.
	 *
	 * @param string $zip_path Absolute path to the zip file.
	 * @param string $name     Human-readable name for the imported sandbox.
	 * @return Sandbox The imported sandbox.
	 *
	 * @throws \RuntimeException If the zip is invalid or import fails.
	 */
	public function import( string $zip_path, string $name ): Sandbox {
		if ( ! file_exists( $zip_path ) ) {
			throw new \RuntimeException( sprintf( 'Zip file not found: %s', $zip_path ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new \RuntimeException( sprintf( 'Failed to open zip archive: %s', $zip_path ) );
		}

		$tmp_dir = sys_get_temp_dir() . '/rudel-import-' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating temp extraction directory.
		mkdir( $tmp_dir, 0755, true );
		$zip->extractTo( $tmp_dir );
		$zip->close();

		$meta_file = $tmp_dir . '/.rudel.json';
		if ( ! file_exists( $meta_file ) ) {
			$this->delete_directory( $tmp_dir );
			throw new \RuntimeException( 'Invalid sandbox archive: missing .rudel.json' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading extracted metadata.
		$old_meta = json_decode( file_get_contents( $meta_file ), true );
		if ( ! is_array( $old_meta ) || empty( $old_meta['id'] ) ) {
			$this->delete_directory( $tmp_dir );
			throw new \RuntimeException( 'Invalid sandbox archive: malformed .rudel.json' );
		}

		$old_id = $old_meta['id'];
		$new_id = Sandbox::generate_id( $name );

		if ( ! is_dir( $this->sandboxes_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating sandboxes directory.
			mkdir( $this->sandboxes_dir, 0755, true );
		}

		$new_path = $this->sandboxes_dir . '/' . $new_id;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving extracted sandbox into place.
		rename( $tmp_dir, $new_path );

		$this->ensure_sqlite_integration();
		$this->write_db_drop_in( $new_path );
		$this->write_sandbox_bootstrap( $new_id, $new_path );
		$this->write_wp_cli_yml( $new_path );
		$this->write_claude_md( $new_id, $name, $new_path );

		// Rewrite URLs and prefix in the database.
		$db_path = $new_path . '/wordpress.db';
		if ( file_exists( $db_path ) ) {
			$old_prefix = 'wp_' . substr( md5( $old_id ), 0, 6 ) . '_';
			$new_prefix = 'wp_' . substr( md5( $new_id ), 0, 6 ) . '_';

			$site_url = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
			$old_url  = $site_url . '/__rudel/' . $old_id;
			$new_url  = $site_url . '/__rudel/' . $new_id;

			// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database requires PDO for import rewriting.
			$pdo = new \PDO( 'sqlite:' . $db_path );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			// phpcs:enable

			$db_cloner = new DatabaseCloner( $this->plugin_dir );
			$db_cloner->rewrite_urls( $pdo, $old_prefix, $old_url, $new_url );

			if ( $old_prefix !== $new_prefix ) {
				// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO operations for table renaming.
				$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$old_prefix}%'" )
					->fetchAll( \PDO::FETCH_COLUMN );
				// phpcs:enable

				foreach ( $tables as $old_table ) {
					$new_table = $new_prefix . substr( $old_table, strlen( $old_prefix ) );
					$pdo->exec( "ALTER TABLE `{$old_table}` RENAME TO `{$new_table}`" );
				}

				$db_cloner->rewrite_table_prefix_in_data( $pdo, $new_prefix, $old_prefix, $new_prefix );
			}

			$pdo = null;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting permissions on imported database file.
			chmod( $db_path, 0664 );
		}

		$sandbox = new Sandbox(
			id: $new_id,
			name: $name,
			path: $new_path,
			created_at: gmdate( 'c' ),
			template: $old_meta['template'] ?? 'imported',
			status: 'active',
		);
		$sandbox->save_meta();

		return $sandbox;
	}

	/**
	 * Get the configured sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_sandboxes_dir(): string {
		return $this->sandboxes_dir;
	}

	/**
	 * Clean up expired sandboxes.
	 *
	 * @param array $options Options: 'dry_run' (bool), 'max_age_days' (int override).
	 * @return array{removed: string[], skipped: string[], errors: string[]} Cleanup results.
	 */
	public function cleanup( array $options = array() ): array {
		$dry_run      = ! empty( $options['dry_run'] );
		$max_age_days = $options['max_age_days'] ?? 0;

		if ( 0 === $max_age_days ) {
			$config       = new RudelConfig();
			$max_age_days = $config->get( 'max_age_days' );
		}

		$result = array(
			'removed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		if ( $max_age_days <= 0 ) {
			return $result;
		}

		$cutoff    = time() - ( $max_age_days * 86400 );
		$sandboxes = $this->list();

		foreach ( $sandboxes as $sandbox ) {
			$created = strtotime( $sandbox->created_at );

			if ( false === $created || $created >= $cutoff ) {
				$result['skipped'][] = $sandbox->id;
				continue;
			}

			if ( $dry_run ) {
				$result['removed'][] = $sandbox->id;
				continue;
			}

			if ( $this->destroy( $sandbox->id ) ) {
				$result['removed'][] = $sandbox->id;
			} else {
				$result['errors'][] = $sandbox->id;
			}
		}

		return $result;
	}

	/**
	 * Check configured limits before creating a new sandbox.
	 *
	 * @param RudelConfig|null $config Optional config instance for testing.
	 * @return void
	 *
	 * @throws \RuntimeException If a limit is exceeded.
	 */
	public function check_limits( ?RudelConfig $config = null ): void {
		$config        = $config ?? new RudelConfig();
		$max_sandboxes = $config->get( 'max_sandboxes' );
		$max_disk_mb   = $config->get( 'max_disk_mb' );

		if ( $max_sandboxes > 0 ) {
			$count = count( $this->list() );
			if ( $count >= $max_sandboxes ) {
				throw new \RuntimeException(
					sprintf( 'Sandbox limit reached: %d of %d', $count, $max_sandboxes )
				);
			}
		}

		if ( $max_disk_mb > 0 ) {
			$total_bytes = 0;
			foreach ( $this->list() as $sandbox ) {
				$total_bytes += $sandbox->get_size();
			}
			$total_mb = $total_bytes / ( 1024 * 1024 );
			if ( $total_mb >= $max_disk_mb ) {
				throw new \RuntimeException(
					sprintf( 'Disk limit reached: %.1f MB of %d MB', $total_mb, $max_disk_mb )
				);
			}
		}
	}

	/**
	 * Determine the default sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	private function get_default_sandboxes_dir(): string {
		if ( defined( 'RUDEL_SANDBOXES_DIR' ) ) {
			return RUDEL_SANDBOXES_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-sandboxes';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-sandboxes';
	}

	/**
	 * Get the WordPress core path.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	private function get_wp_core_path(): string {
		if ( defined( 'ABSPATH' ) ) {
			return rtrim( ABSPATH, '/' );
		}
		return dirname( __DIR__, 3 );
	}

	/**
	 * Ensure the SQLite integration library is available.
	 *
	 * @return void
	 */
	private function ensure_sqlite_integration(): void {
		$lib_path = $this->plugin_dir . 'lib/sqlite-database-integration';

		if ( is_dir( $lib_path ) && file_exists( $lib_path . '/wp-includes/sqlite/db.php' ) ) {
			return;
		}

		$this->download_sqlite_integration( $lib_path );
	}

	/**
	 * Download the SQLite integration library from GitHub.
	 *
	 * @param string $target_path Destination path for the extracted library.
	 * @return void
	 *
	 * @throws \RuntimeException If the download or extraction fails.
	 */
	private function download_sqlite_integration( string $target_path ): void {
		$version     = 'main';
		$url         = "https://github.com/WordPress/sqlite-database-integration/archive/refs/heads/{$version}.zip";
		$tmp_zip     = sys_get_temp_dir() . '/sqlite-database-integration.zip';
		$tmp_extract = sys_get_temp_dir() . '/sqlite-database-integration-extract';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Direct download; curl fallback follows.
		$contents = @file_get_contents( $url );
		if ( false === $contents ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close -- Fallback download when file_get_contents fails; WP HTTP API unavailable.
			$ch = curl_init( $url );
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS      => 5,
					CURLOPT_TIMEOUT        => 60,
				)
			);
			$contents  = curl_exec( $ch );
			$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
			// phpcs:enable

			if ( 200 !== $http_code || false === $contents ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
				throw new \RuntimeException(
					'Failed to download SQLite database integration. '
					. 'Please manually install it to: ' . $target_path
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temp zip file.
		file_put_contents( $tmp_zip, $contents );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp_zip ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning up temp file.
			@unlink( $tmp_zip );
			throw new \RuntimeException( 'Failed to extract SQLite database integration zip.' );
		}

		if ( is_dir( $tmp_extract ) ) {
			$this->delete_directory( $tmp_extract );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating temp extraction directory.
		mkdir( $tmp_extract, 0755, true );
		$zip->extractTo( $tmp_extract );
		$zip->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- Cleaning up temp zip.
		@unlink( $tmp_zip );

		$extracted_dirs = glob( $tmp_extract . '/sqlite-database-integration-*' );
		if ( empty( $extracted_dirs ) ) {
			$this->delete_directory( $tmp_extract );
			throw new \RuntimeException( 'Unexpected archive structure for SQLite integration.' );
		}

		if ( ! is_dir( dirname( $target_path ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating lib directory.
			mkdir( dirname( $target_path ), 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving extracted directory into place.
		rename( $extracted_dirs[0], $target_path );
		$this->delete_directory( $tmp_extract );
	}

	/**
	 * Write the db.php drop-in for a sandbox.
	 *
	 * @param string $sandbox_path Absolute path to the sandbox directory.
	 * @return void
	 */
	private function write_db_drop_in( string $sandbox_path ): void {
		$sqlite_path = $this->plugin_dir . 'lib/sqlite-database-integration';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/db.php.tpl' );
		$content  = str_replace( '{{sqlite_integration_path}}', $sqlite_path, $template );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing sandbox drop-in.
		file_put_contents( $sandbox_path . '/wp-content/db.php', $content );
	}

	/**
	 * Write the per-sandbox bootstrap.php.
	 *
	 * @param string $id        Sandbox identifier.
	 * @param string $path      Absolute path to the sandbox directory.
	 * @param bool   $multisite Whether this sandbox is a multisite clone.
	 * @return void
	 */
	private function write_sandbox_bootstrap( string $id, string $path, bool $multisite = false ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/sandbox-bootstrap.php.tpl' );

		$multisite_block = '';
		if ( $multisite ) {
			$multisite_block = "\n// Multisite\n"
				. "if (! defined('WP_ALLOW_MULTISITE')) { define('WP_ALLOW_MULTISITE', true); }\n"
				. "if (! defined('MULTISITE')) { define('MULTISITE', true); }\n"
				. "if (! defined('SUBDOMAIN_INSTALL')) { define('SUBDOMAIN_INSTALL', false); }\n"
				. "if (! defined('DOMAIN_CURRENT_SITE')) { define('DOMAIN_CURRENT_SITE', \$_SERVER['HTTP_HOST'] ?? 'localhost'); }\n"
				. "if (! defined('PATH_CURRENT_SITE')) { define('PATH_CURRENT_SITE', '/__rudel/' . \$sandbox_id . '/'); }\n"
				. "if (! defined('SITE_ID_CURRENT_SITE')) { define('SITE_ID_CURRENT_SITE', 1); }\n"
				. "if (! defined('BLOG_ID_CURRENT_SITE')) { define('BLOG_ID_CURRENT_SITE', 1); }\n";
		}

		$content = strtr(
			$template,
			array(
				'{{sandbox_id}}'      => $id,
				'{{sandbox_path}}'    => $path,
				'{{multisite_block}}' => $multisite_block,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing sandbox bootstrap.
		file_put_contents( $path . '/bootstrap.php', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/bootstrap.php', 0444 );
	}

	/**
	 * Write the per-sandbox wp-cli.yml.
	 *
	 * @param string $path Absolute path to the sandbox directory.
	 * @return void
	 */
	private function write_wp_cli_yml( string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/wp-cli.yml.tpl' );
		$content  = strtr(
			$template,
			array(
				'{{wp_core_path}}'           => $this->get_wp_core_path(),
				'{{sandbox_bootstrap_path}}' => $path . '/bootstrap.php',
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing sandbox wp-cli.yml.
		file_put_contents( $path . '/wp-cli.yml', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/wp-cli.yml', 0444 );
	}

	/**
	 * Write the per-sandbox CLAUDE.md.
	 *
	 * @param string $id   Sandbox identifier.
	 * @param string $name Human-readable name.
	 * @param string $path Absolute path to the sandbox directory.
	 * @return void
	 */
	private function write_claude_md( string $id, string $name, string $path ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/CLAUDE.md.tpl' );
		$content  = strtr(
			$template,
			array(
				'{{sandbox_id}}'   => $id,
				'{{sandbox_name}}' => $name,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing sandbox CLAUDE.md.
		file_put_contents( $path . '/CLAUDE.md', $content );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting read-only on generated file.
		chmod( $path . '/CLAUDE.md', 0444 );
	}

	/**
	 * Create a blank SQLite database with WordPress schema and default content.
	 *
	 * @param string $id   Sandbox identifier.
	 * @param string $path Absolute path to the sandbox directory.
	 * @return void
	 */
	private function create_blank_database( string $id, string $path ): void {
		$db_path      = $path . '/wordpress.db';
		$table_prefix = 'wp_' . substr( md5( $id ), 0, 6 ) . '_';

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database creation requires PDO; $wpdb is unavailable.
		$pdo = new \PDO( 'sqlite:' . $db_path );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$tables = $this->get_wordpress_table_schema( $table_prefix );
		foreach ( $tables as $sql ) {
			$pdo->exec( $sql );
		}

		$site_url    = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$sandbox_url = $site_url . '/__rudel/' . $id;

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores options as serialized PHP arrays.
		$options = array(
			array( 'siteurl', $sandbox_url ),
			array( 'home', $sandbox_url ),
			array( 'blogname', 'Rudel Sandbox' ),
			array( 'blogdescription', 'A sandboxed WordPress environment' ),
			array( 'admin_email', 'admin@sandbox.local' ),
			array( 'users_can_register', '0' ),
			array( 'start_of_week', '1' ),
			array( 'use_balanceTags', '0' ),
			array( 'use_smilies', '1' ),
			array( 'require_name_email', '1' ),
			array( 'comments_per_page', '50' ),
			array( 'posts_per_page', '10' ),
			array( 'date_format', 'F j, Y' ),
			array( 'time_format', 'g:i a' ),
			array( 'links_updated_date_format', 'F j, Y g:i a' ),
			array( 'comment_moderation', '0' ),
			array( 'moderation_notify', '1' ),
			array( 'permalink_structure', '/%postname%/' ),
			array( 'rewrite_rules', '' ),
			array( 'active_plugins', serialize( array() ) ),
			array( 'template', 'twentytwentyfour' ),
			array( 'stylesheet', 'twentytwentyfour' ),
			array( 'current_theme', 'Twenty Twenty-Four' ),
			array( 'WPLANG', '' ),
			array( 'widget_block', serialize( array() ) ),
			array( 'sidebars_widgets', serialize( array( 'wp_inactive_widgets' => array() ) ) ),
			array(
				'cron',
				serialize(
					array(
						time() + 600 => array(
							'wp_scheduled_delete' => array(
								md5( '' ) => array(
									'schedule' => 'daily',
									'args'     => array(),
								),
							),
						),
					)
				),
			),
			array( 'fresh_site', '0' ),
			array( 'db_version', '57155' ),
			array( 'initial_db_version', '57155' ),
			array( $table_prefix . 'user_roles', serialize( $this->get_default_user_roles() ) ),
		);
		// phpcs:enable

		$stmt = $pdo->prepare( "INSERT INTO {$table_prefix}options (option_name, option_value, autoload) VALUES (?, ?, 'yes')" );
		foreach ( $options as list( $name, $value ) ) {
			$stmt->execute( array( $name, $value ) );
		}

		$password_hash = '$P$BForRudelSandboxDefaultAdmin00000.';
		$pdo->exec( "INSERT INTO {$table_prefix}users (ID, user_login, user_pass, user_nicename, user_email, user_registered, user_status, display_name) VALUES (1, 'admin', '{$password_hash}', 'admin', 'admin@sandbox.local', datetime('now'), 0, 'admin')" );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress capability format.
		$pdo->exec( "INSERT INTO {$table_prefix}usermeta (user_id, meta_key, meta_value) VALUES (1, '{$table_prefix}capabilities', '" . serialize( array( 'administrator' => true ) ) . "')" );
		$pdo->exec( "INSERT INTO {$table_prefix}usermeta (user_id, meta_key, meta_value) VALUES (1, '{$table_prefix}user_level', '10')" );
		$pdo->exec( "INSERT INTO {$table_prefix}usermeta (user_id, meta_key, meta_value) VALUES (1, 'nickname', 'admin')" );

		$pdo->exec( "INSERT INTO {$table_prefix}posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_type, post_modified, post_modified_gmt) VALUES (1, 1, datetime('now'), datetime('now'), 'Welcome to your Rudel sandbox. This is your first post. Edit or delete it, then start writing!', 'Hello world!', '', 'publish', 'open', 'open', 'hello-world', 'post', datetime('now'), datetime('now'))" );

		$pdo->exec( "INSERT INTO {$table_prefix}posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_type, post_modified, post_modified_gmt) VALUES (2, 1, datetime('now'), datetime('now'), 'This is a sample page.', 'Sample Page', '', 'publish', 'closed', 'open', 'sample-page', 'page', datetime('now'), datetime('now'))" );

		$pdo->exec( "INSERT INTO {$table_prefix}comments (comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url, comment_date, comment_date_gmt, comment_content, comment_approved, comment_type) VALUES (1, 1, 'A WordPress Commenter', 'wapuu@wordpress.example', 'https://wordpress.org/', datetime('now'), datetime('now'), 'Hi, this is a comment.', '1', 'comment')" );

		$pdo->exec( "INSERT INTO {$table_prefix}terms (term_id, name, slug, term_group) VALUES (1, 'Uncategorized', 'uncategorized', 0)" );
		$pdo->exec( "INSERT INTO {$table_prefix}term_taxonomy (term_taxonomy_id, term_id, taxonomy, description, parent, count) VALUES (1, 1, 'category', '', 0, 1)" );
		$pdo->exec( "INSERT INTO {$table_prefix}term_relationships (object_id, term_taxonomy_id) VALUES (1, 1)" );

		$pdo = null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting permissions on generated database file.
		chmod( $db_path, 0664 );
	}

	/**
	 * Get WordPress core table CREATE statements for SQLite.
	 *
	 * @param string $prefix Table prefix.
	 * @return string[] Array of CREATE TABLE SQL statements.
	 */
	private function get_wordpress_table_schema( string $prefix ): array {
		return array(
			"CREATE TABLE IF NOT EXISTS {$prefix}terms (
				term_id INTEGER PRIMARY KEY AUTOINCREMENT,
				name TEXT NOT NULL DEFAULT '',
				slug TEXT NOT NULL DEFAULT '',
				term_group INTEGER NOT NULL DEFAULT 0
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}term_taxonomy (
				term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,
				term_id INTEGER NOT NULL DEFAULT 0,
				taxonomy TEXT NOT NULL DEFAULT '',
				description TEXT NOT NULL DEFAULT '',
				parent INTEGER NOT NULL DEFAULT 0,
				count INTEGER NOT NULL DEFAULT 0
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}term_relationships (
				object_id INTEGER NOT NULL DEFAULT 0,
				term_taxonomy_id INTEGER NOT NULL DEFAULT 0,
				term_order INTEGER NOT NULL DEFAULT 0,
				PRIMARY KEY (object_id, term_taxonomy_id)
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}termmeta (
				meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				term_id INTEGER NOT NULL DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}commentmeta (
				meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				comment_id INTEGER NOT NULL DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}comments (
				comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,
				comment_post_ID INTEGER NOT NULL DEFAULT 0,
				comment_author TEXT NOT NULL DEFAULT '',
				comment_author_email TEXT NOT NULL DEFAULT '',
				comment_author_url TEXT NOT NULL DEFAULT '',
				comment_author_IP TEXT NOT NULL DEFAULT '',
				comment_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				comment_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				comment_content TEXT NOT NULL,
				comment_karma INTEGER NOT NULL DEFAULT 0,
				comment_approved TEXT NOT NULL DEFAULT '1',
				comment_agent TEXT NOT NULL DEFAULT '',
				comment_type TEXT NOT NULL DEFAULT 'comment',
				comment_parent INTEGER NOT NULL DEFAULT 0,
				user_id INTEGER NOT NULL DEFAULT 0
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}links (
				link_id INTEGER PRIMARY KEY AUTOINCREMENT,
				link_url TEXT NOT NULL DEFAULT '',
				link_name TEXT NOT NULL DEFAULT '',
				link_image TEXT NOT NULL DEFAULT '',
				link_target TEXT NOT NULL DEFAULT '',
				link_description TEXT NOT NULL DEFAULT '',
				link_visible TEXT NOT NULL DEFAULT 'Y',
				link_owner INTEGER NOT NULL DEFAULT 1,
				link_rating INTEGER NOT NULL DEFAULT 0,
				link_updated TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				link_rel TEXT NOT NULL DEFAULT '',
				link_notes TEXT NOT NULL DEFAULT '',
				link_rss TEXT NOT NULL DEFAULT ''
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}options (
				option_id INTEGER PRIMARY KEY AUTOINCREMENT,
				option_name TEXT NOT NULL DEFAULT '' UNIQUE,
				option_value TEXT NOT NULL DEFAULT '',
				autoload TEXT NOT NULL DEFAULT 'yes'
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}postmeta (
				meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				post_id INTEGER NOT NULL DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}posts (
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				post_author INTEGER NOT NULL DEFAULT 0,
				post_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_content TEXT NOT NULL DEFAULT '',
				post_title TEXT NOT NULL DEFAULT '',
				post_excerpt TEXT NOT NULL DEFAULT '',
				post_status TEXT NOT NULL DEFAULT 'publish',
				comment_status TEXT NOT NULL DEFAULT 'open',
				ping_status TEXT NOT NULL DEFAULT 'open',
				post_password TEXT NOT NULL DEFAULT '',
				post_name TEXT NOT NULL DEFAULT '',
				to_ping TEXT NOT NULL DEFAULT '',
				pinged TEXT NOT NULL DEFAULT '',
				post_modified TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_modified_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				post_content_filtered TEXT NOT NULL DEFAULT '',
				post_parent INTEGER NOT NULL DEFAULT 0,
				guid TEXT NOT NULL DEFAULT '',
				menu_order INTEGER NOT NULL DEFAULT 0,
				post_type TEXT NOT NULL DEFAULT 'post',
				post_mime_type TEXT NOT NULL DEFAULT '',
				comment_count INTEGER NOT NULL DEFAULT 0
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}users (
				ID INTEGER PRIMARY KEY AUTOINCREMENT,
				user_login TEXT NOT NULL DEFAULT '',
				user_pass TEXT NOT NULL DEFAULT '',
				user_nicename TEXT NOT NULL DEFAULT '',
				user_email TEXT NOT NULL DEFAULT '',
				user_url TEXT NOT NULL DEFAULT '',
				user_registered TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
				user_activation_key TEXT NOT NULL DEFAULT '',
				user_status INTEGER NOT NULL DEFAULT 0,
				display_name TEXT NOT NULL DEFAULT ''
			)",
			"CREATE TABLE IF NOT EXISTS {$prefix}usermeta (
				umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
				user_id INTEGER NOT NULL DEFAULT 0,
				meta_key TEXT DEFAULT NULL,
				meta_value TEXT
			)",
		);
	}

	/**
	 * Get the default WordPress user role definitions.
	 *
	 * @return array<string, array<string, mixed>> Role definitions keyed by role slug.
	 */
	private function get_default_user_roles(): array {
		return array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'switch_themes'          => true,
					'edit_themes'            => true,
					'activate_plugins'       => true,
					'edit_plugins'           => true,
					'edit_users'             => true,
					'edit_files'             => true,
					'manage_options'         => true,
					'moderate_comments'      => true,
					'manage_categories'      => true,
					'manage_links'           => true,
					'upload_files'           => true,
					'import'                 => true,
					'unfiltered_html'        => true,
					'edit_posts'             => true,
					'edit_others_posts'      => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'edit_pages'             => true,
					'read'                   => true,
					'level_10'               => true,
					'level_9'                => true,
					'level_8'                => true,
					'level_7'                => true,
					'level_6'                => true,
					'level_5'                => true,
					'level_4'                => true,
					'level_3'                => true,
					'level_2'                => true,
					'level_1'                => true,
					'level_0'                => true,
					'edit_others_pages'      => true,
					'edit_published_pages'   => true,
					'publish_pages'          => true,
					'delete_pages'           => true,
					'delete_others_pages'    => true,
					'delete_published_pages' => true,
					'delete_posts'           => true,
					'delete_others_posts'    => true,
					'delete_published_posts' => true,
					'delete_private_posts'   => true,
					'edit_private_posts'     => true,
					'read_private_posts'     => true,
					'delete_private_pages'   => true,
					'edit_private_pages'     => true,
					'read_private_pages'     => true,
					'delete_users'           => true,
					'create_users'           => true,
					'unfiltered_upload'      => true,
					'edit_dashboard'         => true,
					'update_plugins'         => true,
					'delete_plugins'         => true,
					'install_plugins'        => true,
					'update_themes'          => true,
					'install_themes'         => true,
					'update_core'            => true,
					'list_users'             => true,
					'remove_users'           => true,
					'promote_users'          => true,
					'edit_theme_options'     => true,
					'delete_themes'          => true,
					'export'                 => true,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'moderate_comments'      => true,
					'manage_categories'      => true,
					'manage_links'           => true,
					'upload_files'           => true,
					'unfiltered_html'        => true,
					'edit_posts'             => true,
					'edit_others_posts'      => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'edit_pages'             => true,
					'read'                   => true,
					'level_7'                => true,
					'level_6'                => true,
					'level_5'                => true,
					'level_4'                => true,
					'level_3'                => true,
					'level_2'                => true,
					'level_1'                => true,
					'level_0'                => true,
					'edit_others_pages'      => true,
					'edit_published_pages'   => true,
					'publish_pages'          => true,
					'delete_pages'           => true,
					'delete_others_pages'    => true,
					'delete_published_pages' => true,
					'delete_posts'           => true,
					'delete_others_posts'    => true,
					'delete_published_posts' => true,
					'delete_private_posts'   => true,
					'edit_private_posts'     => true,
					'read_private_posts'     => true,
					'delete_private_pages'   => true,
					'edit_private_pages'     => true,
					'read_private_pages'     => true,
				),
			),
			'author'        => array(
				'name'         => 'Author',
				'capabilities' => array(
					'upload_files'           => true,
					'edit_posts'             => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'read'                   => true,
					'level_2'                => true,
					'level_1'                => true,
					'level_0'                => true,
					'delete_posts'           => true,
					'delete_published_posts' => true,
				),
			),
			'contributor'   => array(
				'name'         => 'Contributor',
				'capabilities' => array(
					'edit_posts'   => true,
					'read'         => true,
					'level_1'      => true,
					'level_0'      => true,
					'delete_posts' => true,
				),
			),
			'subscriber'    => array(
				'name'         => 'Subscriber',
				'capabilities' => array(
					'read'    => true,
					'level_0' => true,
				),
			),
		);
	}

	/**
	 * Check if a template exists by name.
	 *
	 * @param string $name Template name.
	 * @return bool True if the template directory exists.
	 */
	private function template_exists( string $name ): bool {
		$tpl_manager = new TemplateManager();
		return is_dir( $tpl_manager->get_templates_dir() . '/' . $name );
	}

	/**
	 * Initialize a sandbox from a template: copy db and wp-content, rewrite URLs and prefix.
	 *
	 * @param string $template_name Template name.
	 * @param string $target_id     New sandbox ID.
	 * @param string $target_path   New sandbox directory path.
	 * @return void
	 *
	 * @throws \RuntimeException If the template is not found or initialization fails.
	 */
	private function initialize_from_template( string $template_name, string $target_id, string $target_path ): void {
		$tpl_manager   = new TemplateManager();
		$template_path = $tpl_manager->get_template_path( $template_name );

		$meta_file = $template_path . '/template.json';
		if ( ! file_exists( $meta_file ) ) {
			throw new \RuntimeException( sprintf( 'Template metadata not found: %s', $template_name ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template metadata.
		$meta = json_decode( file_get_contents( $meta_file ), true );

		$source_db = $template_path . '/wordpress.db';
		$target_db = $target_path . '/wordpress.db';

		if ( file_exists( $source_db ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying database from template.
			copy( $source_db, $target_db );
		}

		$source_url    = $meta['source_url'] ?? '';
		$site_url      = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$sandbox_url   = $site_url . '/__rudel/' . $target_id;
		$source_id     = $meta['source_sandbox_id'] ?? '';
		$source_prefix = 'wp_' . substr( md5( $source_id ), 0, 6 ) . '_';
		$target_prefix = 'wp_' . substr( md5( $target_id ), 0, 6 ) . '_';

		if ( file_exists( $target_db ) ) {
			// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database requires PDO for template initialization.
			$pdo = new \PDO( 'sqlite:' . $target_db );
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			// phpcs:enable

			$db_cloner = new DatabaseCloner( $this->plugin_dir );

			if ( $source_url && $source_url !== $sandbox_url ) {
				$db_cloner->rewrite_urls( $pdo, $source_prefix, $source_url, $sandbox_url );
			}

			if ( $source_prefix !== $target_prefix ) {
				// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO operations for table renaming.
				$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$source_prefix}%'" )
					->fetchAll( \PDO::FETCH_COLUMN );
				// phpcs:enable

				foreach ( $tables as $old_table ) {
					$new_table = $target_prefix . substr( $old_table, strlen( $source_prefix ) );
					$pdo->exec( "ALTER TABLE `{$old_table}` RENAME TO `{$new_table}`" );
				}

				$db_cloner->rewrite_table_prefix_in_data( $pdo, $target_prefix, $source_prefix, $target_prefix );
			}

			$pdo = null;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting permissions on generated database file.
			chmod( $target_db, 0664 );
		}

		// Replace scaffolded wp-content with template wp-content.
		$template_content = $template_path . '/wp-content';
		if ( is_dir( $template_content ) ) {
			$this->delete_directory( $target_path . '/wp-content' );
			$content_cloner = new ContentCloner();
			$content_cloner->copy_directory( $template_content, $target_path . '/wp-content' );
			$this->write_db_drop_in( $target_path );
		}
	}

	/**
	 * Clone from an existing sandbox: copy its SQLite db and wp-content, then rewrite URLs and prefix.
	 *
	 * @param Sandbox $source      Source sandbox to clone from.
	 * @param string  $target_id   New sandbox ID.
	 * @param string  $target_path New sandbox directory path.
	 * @return array Clone source metadata.
	 *
	 * @throws \RuntimeException If the database copy fails.
	 */
	private function clone_from_sandbox( Sandbox $source, string $target_id, string $target_path ): array {
		$source_db = $source->get_db_path();
		$target_db = $target_path . '/wordpress.db';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying SQLite database file.
		if ( ! copy( $source_db, $target_db ) ) {
			throw new \RuntimeException( sprintf( 'Failed to copy database from source sandbox: %s', $source->id ) );
		}

		$source_prefix = 'wp_' . substr( md5( $source->id ), 0, 6 ) . '_';
		$target_prefix = 'wp_' . substr( md5( $target_id ), 0, 6 ) . '_';

		$site_url    = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$source_url  = $site_url . '/__rudel/' . $source->id;
		$sandbox_url = $site_url . '/__rudel/' . $target_id;

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database requires PDO.
		$pdo = new \PDO( 'sqlite:' . $target_db );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$db_cloner = new DatabaseCloner( $this->plugin_dir );
		$db_cloner->rewrite_urls( $pdo, $source_prefix, $source_url, $sandbox_url );

		// Rename tables from source prefix to target prefix.
		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO operations for table renaming.
		$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$source_prefix}%'" )
			->fetchAll( \PDO::FETCH_COLUMN );
		// phpcs:enable

		foreach ( $tables as $old_table ) {
			$new_table = $target_prefix . substr( $old_table, strlen( $source_prefix ) );
			$pdo->exec( "ALTER TABLE `{$old_table}` RENAME TO `{$new_table}`" );
		}

		$db_cloner->rewrite_table_prefix_in_data( $pdo, $target_prefix, $source_prefix, $target_prefix );

		$pdo = null;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Setting permissions on generated database file.
		chmod( $target_db, 0664 );

		// Replace scaffolded wp-content with source sandbox's wp-content.
		$this->delete_directory( $target_path . '/wp-content' );
		$content_cloner = new ContentCloner();
		$content_cloner->copy_directory( $source->get_wp_content_path(), $target_path . '/wp-content' );

		// Re-write the db.php drop-in (it was copied from source but needs same content).
		$this->write_db_drop_in( $target_path );

		return array(
			'type'           => 'sandbox',
			'source_id'      => $source->id,
			'source_name'    => $source->name,
			'cloned_at'      => gmdate( 'c' ),
			'db_cloned'      => true,
			'content_cloned' => true,
		);
	}

	/**
	 * Recursively delete a directory and all its contents.
	 *
	 * @param string $dir Absolute path to the directory.
	 * @return bool True on success.
	 */
	private function delete_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isWritable() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Handling read-only generated files.
				chmod( $item->getPathname(), 0644 );
			}
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Direct recursive directory removal.
				rmdir( $item->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Direct file deletion during directory cleanup.
				unlink( $item->getPathname() );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing now-empty directory.
		return rmdir( $dir );
	}
}
