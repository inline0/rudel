<?php
/**
 * Environment CRUD orchestrator.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Creates, lists, retrieves, and destroys sandbox environments.
 */
class EnvironmentManager {

	/**
	 * Absolute path to the sandboxes directory.
	 *
	 * @var string
	 */
	private string $environments_dir;

	/**
	 * Absolute path to the Rudel plugin directory.
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $environments_dir Optional override for the sandboxes directory.
	 */
	public function __construct( ?string $environments_dir = null ) {
		$this->plugin_dir       = defined( 'RUDEL_PLUGIN_DIR' ) ? RUDEL_PLUGIN_DIR : dirname( __DIR__ ) . '/';
		$this->environments_dir = $environments_dir ?? $this->get_default_environments_dir();
	}

	/**
	 * Create a new sandbox.
	 *
	 * @param string $name    Human-readable name.
	 * @param array  $options Optional settings (template, etc.).
	 * @return Environment The newly created sandbox.
	 *
	 * @throws \RuntimeException If the directory already exists or creation fails.
	 * @throws \InvalidArgumentException If conflicting clone options are provided.
	 * @throws \Throwable If any step after directory creation fails (directory is cleaned up).
	 */
	public function create( string $name, array $options = array() ): Environment {
		if ( empty( $options['skip_limits'] ) ) {
			$this->check_limits();
		}

		$id   = Environment::generate_id( $name );
		$path = $this->environments_dir . '/' . $id;

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
		if ( ! is_dir( $this->environments_dir ) ) {
			mkdir( $this->environments_dir, 0755, true );
		}

		if ( ! mkdir( $path, 0755 ) ) {
			throw new \RuntimeException( sprintf( 'Failed to create sandbox directory: %s', $path ) );
		}
		// phpcs:enable

		$engine = $options['engine'] ?? 'mysql';
		if ( ! in_array( $engine, array( 'mysql', 'sqlite', 'subsite' ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Invalid engine: %s. Must be "mysql", "sqlite", or "subsite".', $engine ) );
		}

		if ( 'subsite' === $engine && ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) ) {
			throw new \RuntimeException( 'Subsite engine requires a WordPress multisite installation.' );
		}

			$blog_id   = null;
		$git_worktrees = array();

		try {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $path . '/wp-content', 0755 );
			mkdir( $path . '/wp-content/themes', 0755 );
			mkdir( $path . '/wp-content/plugins', 0755 );
			mkdir( $path . '/wp-content/uploads', 0755 );
			mkdir( $path . '/wp-content/mu-plugins', 0755 );
			mkdir( $path . '/tmp', 0755 );
			// phpcs:enable

			if ( 'sqlite' === $engine ) {
				$this->ensure_sqlite_integration();
				$this->write_db_drop_in( $path );
			}
			$this->write_sandbox_bootstrap( $id, $path, false, $engine );
			$this->write_wp_cli_yml( $path, $engine, $id );
			$this->write_claude_md( $id, $name, $path );

			$clone_source     = null;
			$is_multisite     = false;
			$template         = $options['template'] ?? ( $has_clone || $clone_from ? 'clone' : 'blank' );
			$is_from_template = ! in_array( $template, array( 'blank', 'clone' ), true )
				&& ! $clone_from && ! $has_clone
				&& $this->template_exists( $template );

			if ( 'subsite' === $engine ) {
				$subsite_cloner = new SubsiteCloner();
				$blog_id        = $subsite_cloner->create_subsite( $id, $name );

				if ( $clone_db ) {
					$clone_result = $subsite_cloner->clone_host_db_to_subsite( $blog_id );
					$site_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
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
				}

				if ( $has_clone && ! $clone_source ) {
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
			} elseif ( $is_from_template ) {
				$this->initialize_from_template( $template, $id, $path, $engine );
			} elseif ( $clone_from ) {
				$source = $this->get( $clone_from );
				if ( ! $source ) {
					throw new \RuntimeException( sprintf( 'Source sandbox not found: %s', $clone_from ) );
				}
				if ( $source->engine !== $engine ) {
					throw new \InvalidArgumentException(
						sprintf( 'Cannot clone across engines: source is %s, target is %s.', $source->engine, $engine )
					);
				}
				$clone_source = $this->clone_from_sandbox( $source, $id, $path, $engine );
			} elseif ( $clone_db ) {
				$table_prefix = 'rudel_' . substr( md5( $id ), 0, 6 ) . '_';
				$site_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
				$sandbox_url  = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id;

				if ( 'mysql' === $engine ) {
					$db_cloner    = new MySQLCloner();
					$clone_result = $db_cloner->clone_database(
						$table_prefix,
						$sandbox_url,
						array( 'chunk_size' => $options['chunk_size'] ?? 500 )
					);
				} else {
					$db_cloner    = new DatabaseCloner( $this->plugin_dir );
					$clone_result = $db_cloner->clone_database(
						$path . '/wordpress.db',
						$table_prefix,
						$sandbox_url,
						array( 'chunk_size' => $options['chunk_size'] ?? 500 )
					);
				}

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
				if ( 'sqlite' === $engine ) {
					$this->create_blank_database( $id, $path );
				} elseif ( 'mysql' === $engine ) {
					$this->create_blank_mysql_database( $id );
				}

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
				$content_cloner  = new ContentCloner();
				$content_results = $content_cloner->clone_content(
					$path . '/wp-content',
					array(
						'themes'  => $clone_themes,
						'plugins' => $clone_plugins,
						'uploads' => $clone_uploads,
					),
					$id
				);

				// Collect git worktree info for metadata.
				$git_worktrees = array();
				foreach ( $content_results as $dir => $result ) {
					if ( is_array( $result ) && ! empty( $result['worktrees'] ) ) {
						foreach ( $result['worktrees'] as $repo_name => $branch ) {
							$git_worktrees[] = array(
								'type'   => $dir,
								'name'   => $repo_name,
								'branch' => $branch,
								'repo'   => $this->get_host_wp_content_dir() . '/' . $dir . '/' . $repo_name,
							);
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( 'mysql' === $engine ) {
				global $wpdb;
				if ( isset( $wpdb ) && $wpdb ) {
					$table_prefix = 'rudel_' . substr( md5( $id ), 0, 6 ) . '_';
					$mysql_cloner = new MySQLCloner();
					$mysql_cloner->drop_tables( $table_prefix );
				}
			}
			if ( 'subsite' === $engine && $blog_id ) {
				$subsite_cloner = new SubsiteCloner();
				$subsite_cloner->delete_subsite( $blog_id );
			}
			$this->delete_directory( $path );
			throw $e;
		}

		if ( $is_multisite && 'subsite' !== $engine ) {
			$this->write_sandbox_bootstrap( $id, $path, true, $engine );
		}

		// Store git worktree metadata if any were created.
		if ( ! empty( $git_worktrees ) && null !== $clone_source ) {
			$clone_source['git_worktrees'] = $git_worktrees;
		}

		$sandbox = new Environment(
			id: $id,
			name: $name,
			path: $path,
			created_at: gmdate( 'c' ),
			template: $template,
			status: 'active',
			clone_source: $clone_source,
			multisite: $is_multisite,
			engine: $engine,
			blog_id: $blog_id,
			type: $options['type'] ?? 'sandbox',
			domains: $options['domains'] ?? null,
		);
		$sandbox->save_meta();

		return $sandbox;
	}

	/**
	 * List all sandboxes.
	 *
	 * @return Environment[] Array of sandbox instances.
	 */
	public function list(): array {
		if ( ! is_dir( $this->environments_dir ) ) {
			return array();
		}

		$sandboxes = array();
		$dirs      = scandir( $this->environments_dir );

		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$path = $this->environments_dir . '/' . $dir;
			if ( ! is_dir( $path ) ) {
				continue;
			}

			$sandbox = Environment::from_path( $path );
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
	 * @return Environment|null Sandbox instance or null if not found.
	 */
	public function get( string $id ): ?Environment {
		if ( ! Environment::validate_id( $id ) ) {
			return null;
		}

		$path = $this->environments_dir . '/' . $id;
		return Environment::from_path( $path );
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

		if ( $sandbox->is_mysql() ) {
			$mysql_cloner = new MySQLCloner();
			$mysql_cloner->drop_tables( $sandbox->get_table_prefix() );
		}

		if ( $sandbox->is_subsite() && $sandbox->blog_id ) {
			$subsite_cloner = new SubsiteCloner();
			$subsite_cloner->delete_subsite( $sandbox->blog_id );
		}

		return $this->delete_directory( $sandbox->path );
	}

	/**
	 * Promote a sandbox to replace the host site.
	 *
	 * Copies the sandbox's database tables and wp-content to the host,
	 * rewriting URLs and table prefixes. Creates a backup first.
	 *
	 * @param string $id         Sandbox identifier.
	 * @param string $backup_dir Directory to store the host backup.
	 * @return array{backup_path: string, tables_copied: int} Promotion results.
	 *
	 * @throws \RuntimeException If the sandbox is not found or promotion fails.
	 */
	public function promote( string $id, string $backup_dir ): array {
		global $wpdb;

		$sandbox = $this->get( $id );
		if ( ! $sandbox ) {
			throw new \RuntimeException( sprintf( 'Sandbox not found: %s', $id ) );
		}

		if ( $sandbox->is_subsite() ) {
			throw new \RuntimeException( 'Promote is not supported for subsite-engine sandboxes.' );
		}

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			throw new \RuntimeException( 'Promote requires a running WordPress environment.' );
		}

		$host_prefix  = $wpdb->prefix;
		$host_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$sandbox_url  = $host_url . '/' . RUDEL_PATH_PREFIX . '/' . $sandbox->id;
		$host_content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

		// Step 1: Backup host database tables.
		if ( ! is_dir( $backup_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating backup directory.
			mkdir( $backup_dir, 0755, true );
		}

		$backup_prefix = 'rudel_backup_' . gmdate( 'Ymd_His' ) . '_';
		$mysql_cloner  = new MySQLCloner();
		$host_tables   = $mysql_cloner->discover_tables( $wpdb, $host_prefix );
		$backup_count  = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names for host backup.
		foreach ( $host_tables as $table ) {
			$backup_table = $backup_prefix . substr( $table, strlen( $host_prefix ) );
			$wpdb->query( "CREATE TABLE `{$backup_table}` LIKE `{$table}`" );
			$wpdb->query( "INSERT INTO `{$backup_table}` SELECT * FROM `{$table}`" );
			++$backup_count;
		}
		// phpcs:enable

		// Backup wp-content.
		$backup_content = $backup_dir . '/wp-content';
		$content_cloner = new ContentCloner();
		$content_cloner->copy_directory( $host_content, $backup_content );

		// Store backup metadata.
		$backup_meta = array(
			'created_at'    => gmdate( 'c' ),
			'sandbox_id'    => $sandbox->id,
			'host_prefix'   => $host_prefix,
			'backup_prefix' => $backup_prefix,
			'tables_backed' => $backup_count,
		);
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Writing backup metadata.
		file_put_contents(
			$backup_dir . '/backup.json',
			json_encode( $backup_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
		);
		// phpcs:enable

		// Step 2: Replace host tables with sandbox tables.
		if ( $sandbox->is_sqlite() ) {
			$this->promote_sqlite_to_host( $sandbox, $host_prefix, $sandbox_url, $host_url );
		} else {
			$this->promote_mysql_to_host( $sandbox, $host_prefix, $sandbox_url, $host_url );
		}

		// Step 3: Sync wp-content.
		$sandbox_content = $sandbox->get_wp_content_path();
		if ( is_dir( $sandbox_content ) ) {
			// Remove host themes/plugins/uploads and replace with sandbox's.
			foreach ( array( 'themes', 'plugins', 'uploads' ) as $subdir ) {
				$host_sub    = $host_content . '/' . $subdir;
				$sandbox_sub = $sandbox_content . '/' . $subdir;
				if ( is_dir( $sandbox_sub ) ) {
					if ( is_dir( $host_sub ) ) {
						$this->delete_directory( $host_sub );
					}
					$content_cloner->copy_directory( $sandbox_sub, $host_sub );
				}
			}
		}

		return array(
			'backup_path'   => $backup_dir,
			'backup_prefix' => $backup_prefix,
			'tables_copied' => $backup_count,
		);
	}

	/**
	 * Promote a MySQL sandbox's tables to the host prefix.
	 *
	 * @param Environment $sandbox     The sandbox to promote.
	 * @param string      $host_prefix Host table prefix.
	 * @param string      $sandbox_url Sandbox URL.
	 * @param string      $host_url    Host URL.
	 * @return void
	 */
	private function promote_mysql_to_host( Environment $sandbox, string $host_prefix, string $sandbox_url, string $host_url ): void {
		global $wpdb;

		$sandbox_prefix = $sandbox->get_table_prefix();
		$mysql_cloner   = new MySQLCloner();
		$sandbox_tables = $mysql_cloner->discover_tables( $wpdb, $sandbox_prefix );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names for promotion.
		// Drop existing host tables and replace with sandbox tables.
		foreach ( $sandbox_tables as $table ) {
			$suffix     = substr( $table, strlen( $sandbox_prefix ) );
			$host_table = $host_prefix . $suffix;
			$wpdb->query( "DROP TABLE IF EXISTS `{$host_table}`" );
			$wpdb->query( "CREATE TABLE `{$host_table}` LIKE `{$table}`" );
			$wpdb->query( "INSERT INTO `{$host_table}` SELECT * FROM `{$table}`" );
		}
		// phpcs:enable

		// Rewrite URLs and prefix references.
		$mysql_cloner->rewrite_urls( $wpdb, $host_prefix, $sandbox_url, $host_url );
		$mysql_cloner->rewrite_table_prefix_in_data( $wpdb, $host_prefix, $sandbox_prefix, $host_prefix );
	}

	/**
	 * Promote a SQLite sandbox's database to the host MySQL.
	 *
	 * @param Environment $sandbox     The sandbox to promote.
	 * @param string      $host_prefix Host table prefix.
	 * @param string      $sandbox_url Sandbox URL.
	 * @param string      $host_url    Host URL.
	 * @return void
	 */
	private function promote_sqlite_to_host( Environment $sandbox, string $host_prefix, string $sandbox_url, string $host_url ): void {
		global $wpdb;

		$sandbox_prefix = $sandbox->get_table_prefix();

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Reading SQLite database for promotion.
		$pdo = new \PDO( 'sqlite:' . $sandbox->get_db_path() );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '{$sandbox_prefix}%'" )
			->fetchAll( \PDO::FETCH_COLUMN );
		// phpcs:enable

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names for SQLite to MySQL promotion.
		foreach ( $tables as $sqlite_table ) {
			$suffix     = substr( $sqlite_table, strlen( $sandbox_prefix ) );
			$host_table = $host_prefix . $suffix;

			// Get column info from the host table (if it exists) or create from scratch.
			$wpdb->query( "TRUNCATE TABLE `{$host_table}`" );

			// Read all rows from SQLite and insert into MySQL.
			$stmt = $pdo->query( "SELECT * FROM `{$sqlite_table}`" );
			// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite PDO fetch.
			$rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );

			foreach ( $rows as $row ) {
				$wpdb->insert( $host_table, $row );
			}
		}
		// phpcs:enable

		$pdo = null;

		// Rewrite URLs and prefix references in the host MySQL tables.
		$mysql_cloner = new MySQLCloner();
		$mysql_cloner->rewrite_urls( $wpdb, $host_prefix, $sandbox_url, $host_url );
		$mysql_cloner->rewrite_table_prefix_in_data( $wpdb, $host_prefix, $sandbox_prefix, $host_prefix );
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
	 * @return Environment The imported sandbox.
	 *
	 * @throws \RuntimeException If the zip is invalid or import fails.
	 */
	public function import( string $zip_path, string $name ): Environment {
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
		$new_id = Environment::generate_id( $name );

		if ( ! is_dir( $this->environments_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating sandboxes directory.
			mkdir( $this->environments_dir, 0755, true );
		}

		$engine = $old_meta['engine'] ?? 'mysql';

		$new_path = $this->environments_dir . '/' . $new_id;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving extracted sandbox into place.
		rename( $tmp_dir, $new_path );

		if ( 'sqlite' === $engine ) {
			$this->ensure_sqlite_integration();
			$this->write_db_drop_in( $new_path );
		}
		$this->write_sandbox_bootstrap( $new_id, $new_path, false, $engine );
		$this->write_wp_cli_yml( $new_path );
		$this->write_claude_md( $new_id, $name, $new_path );

		// Rewrite URLs and prefix in the database.
		$db_path = $new_path . '/wordpress.db';
		if ( file_exists( $db_path ) ) {
			$old_prefix = 'rudel_' . substr( md5( $old_id ), 0, 6 ) . '_';
			$new_prefix = 'rudel_' . substr( md5( $new_id ), 0, 6 ) . '_';

			$site_url = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
			$old_url  = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $old_id;
			$new_url  = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $new_id;

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

		$sandbox = new Environment(
			id: $new_id,
			name: $name,
			path: $new_path,
			created_at: gmdate( 'c' ),
			template: $old_meta['template'] ?? 'imported',
			status: 'active',
			engine: $engine,
		);
		$sandbox->save_meta();

		return $sandbox;
	}

	/**
	 * Get the configured sandboxes directory.
	 *
	 * @return string Absolute path.
	 */
	public function get_environments_dir(): string {
		return $this->environments_dir;
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
	 * Clean up sandboxes whose git branches have been merged.
	 *
	 * @param array $options Options: 'dry_run' (bool).
	 * @return array{removed: string[], skipped: string[], errors: string[]} Cleanup results.
	 */
	public function cleanup_merged( array $options = array() ): array {
		$dry_run = ! empty( $options['dry_run'] );
		$git     = new GitIntegration();
		$result  = array(
			'removed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$sandboxes = $this->list();

		foreach ( $sandboxes as $sandbox ) {
			$branch      = $sandbox->get_git_branch();
			$github_repo = $sandbox->get_github_repo();
			$worktrees   = $sandbox->clone_source['git_worktrees'] ?? array();
			$has_git     = ! empty( $worktrees );
			$has_github  = ! empty( $github_repo );

			if ( ! $has_git && ! $has_github ) {
				$result['skipped'][] = $sandbox->id;
				continue;
			}

			$is_merged = false;

			// Check GitHub API first (works on shared hosts without git).
			if ( $has_github ) {
				try {
					$github    = new GitHubIntegration( $github_repo );
					$is_merged = $github->is_branch_merged( $branch );
				} catch ( \RuntimeException $e ) {
					// No token or API error: fall through to local git check.
					$is_merged = false;
				}
			}

			// Fall back to local git check for worktrees.
			if ( ! $is_merged && $has_git ) {
				$all_local_merged = true;
				foreach ( $worktrees as $wt ) {
					$default_branch = $git->get_default_branch( $wt['repo'] );
					if ( ! $git->is_branch_merged( $wt['repo'], $wt['branch'], $default_branch ) ) {
						$all_local_merged = false;
						break;
					}
				}
				$is_merged = $all_local_merged;
			}

			if ( ! $is_merged ) {
				$result['skipped'][] = $sandbox->id;
				continue;
			}

			if ( $dry_run ) {
				$result['removed'][] = $sandbox->id;
				continue;
			}

			// Clean up local worktrees and branches before destroying.
			foreach ( $worktrees as $wt ) {
				$worktree_path = $sandbox->get_wp_content_path() . '/' . $wt['type'] . '/' . $wt['name'];
				$git->remove_worktree( $wt['repo'], $worktree_path );
				$git->delete_branch( $wt['repo'], $wt['branch'] );
			}

			// Clean up GitHub branch.
			if ( $has_github ) {
				try {
					$github = new GitHubIntegration( $github_repo );
					$github->delete_branch( $branch );
				} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort cleanup; failure is acceptable.
					unset( $e );
				}
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
	private function get_default_environments_dir(): string {
		if ( defined( 'RUDEL_ENVIRONMENTS_DIR' ) ) {
			return RUDEL_ENVIRONMENTS_DIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR . '/rudel-environments';
		}
		$abspath = defined( 'ABSPATH' ) ? ABSPATH : dirname( __DIR__, 3 ) . '/';
		return $abspath . 'wp-content/rudel-environments';
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
	 * Get the host WordPress wp-content directory path.
	 *
	 * @return string Absolute path without trailing slash.
	 */
	private function get_host_wp_content_dir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return rtrim( WP_CONTENT_DIR, '/' );
		}
		return $this->get_wp_core_path() . '/wp-content';
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
	 * @param string $engine    Database engine: 'mysql' or 'sqlite'.
	 * @return void
	 */
	private function write_sandbox_bootstrap( string $id, string $path, bool $multisite = false, string $engine = 'mysql' ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/environment-bootstrap.php.tpl' );

		$multisite_block = '';
		if ( $multisite ) {
			$multisite_block = "\n// Multisite\n"
				. "if (! defined('WP_ALLOW_MULTISITE')) { define('WP_ALLOW_MULTISITE', true); }\n"
				. "if (! defined('MULTISITE')) { define('MULTISITE', true); }\n"
				. "if (! defined('SUBDOMAIN_INSTALL')) { define('SUBDOMAIN_INSTALL', false); }\n"
				. "if (! defined('DOMAIN_CURRENT_SITE')) { define('DOMAIN_CURRENT_SITE', \$_SERVER['HTTP_HOST'] ?? 'localhost'); }\n"
				. "if (! defined('PATH_CURRENT_SITE')) { define('PATH_CURRENT_SITE', '/" . RUDEL_PATH_PREFIX . "/' . \$sandbox_id . '/'); }\n"
				. "if (! defined('SITE_ID_CURRENT_SITE')) { define('SITE_ID_CURRENT_SITE', 1); }\n"
				. "if (! defined('BLOG_ID_CURRENT_SITE')) { define('BLOG_ID_CURRENT_SITE', 1); }\n";
		}

		$sqlite_block = '';
		if ( 'sqlite' === $engine ) {
			$sqlite_block = "// SQLite database\n"
				. "define('DB_DIR', \$sandbox_path);\n"
				. "define('DB_FILE', 'wordpress.db');\n"
				. "define('DATABASE_TYPE', 'sqlite');\n"
				. "define('DB_ENGINE', 'sqlite');\n";
		}

		$content = strtr(
			$template,
			array(
				'{{sandbox_id}}'      => $id,
				'{{sandbox_path}}'    => $path,
				'{{path_prefix}}'     => RUDEL_PATH_PREFIX,
				'{{multisite_block}}' => $multisite_block,
				'{{sqlite_block}}'    => $sqlite_block,
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
	 * @param string $path   Absolute path to the sandbox directory.
	 * @param string $engine Database engine.
	 * @param string $id     Sandbox identifier.
	 * @return void
	 */
	private function write_wp_cli_yml( string $path, string $engine = 'mysql', string $id = '' ): void {
		if ( 'subsite' === $engine ) {
			$site_url = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
			$url      = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id . '/';
			$content  = 'path: ' . $this->get_wp_core_path() . "\n"
				. 'url: ' . $url . "\n";
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
			$template = file_get_contents( $this->plugin_dir . 'templates/wp-cli.yml.tpl' );
			$content  = strtr(
				$template,
				array(
					'{{wp_core_path}}'           => $this->get_wp_core_path(),
					'{{sandbox_bootstrap_path}}' => $path . '/bootstrap.php',
				)
			);
		}
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
		$table_prefix = 'rudel_' . substr( md5( $id ), 0, 6 ) . '_';

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database creation requires PDO; $wpdb is unavailable.
		$pdo = new \PDO( 'sqlite:' . $db_path );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$tables = $this->get_wordpress_table_schema( $table_prefix );
		foreach ( $tables as $sql ) {
			$pdo->exec( $sql );
		}

		$site_url    = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$sandbox_url = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id;

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
	 * Create a blank MySQL database with WordPress schema and default content.
	 *
	 * @param string $id Sandbox identifier.
	 * @return void
	 */
	private function create_blank_mysql_database( string $id ): void {
		global $wpdb;

		$table_prefix = 'rudel_' . substr( md5( $id ), 0, 6 ) . '_';
		$site_url     = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$sandbox_url  = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id;

		// Create tables using WordPress's dbDelta-compatible schema.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic table prefix for sandbox isolation; all table names are internally generated.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$tables = $this->get_wordpress_mysql_table_schema( $table_prefix, $charset_collate );
		foreach ( $tables as $sql ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL from internal schema, not user input.
		}

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WordPress stores options as serialized PHP arrays; meta_key inserts required for WP bootstrap.
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
			array( 'fresh_site', '0' ),
			array( 'db_version', '57155' ),
			array( 'initial_db_version', '57155' ),
			array( $table_prefix . 'user_roles', serialize( $this->get_default_user_roles() ) ),
		);

		foreach ( $options as list( $opt_name, $opt_value ) ) {
			$wpdb->insert(
				"{$table_prefix}options",
				array(
					'option_name'  => $opt_name,
					'option_value' => $opt_value,
					'autoload'     => 'yes',
				)
			);
		}

		$password_hash = '$P$BForRudelSandboxDefaultAdmin00000.';
		$wpdb->insert(
			"{$table_prefix}users",
			array(
				'ID'              => 1,
				'user_login'      => 'admin',
				'user_pass'       => $password_hash,
				'user_nicename'   => 'admin',
				'user_email'      => 'admin@sandbox.local',
				'user_registered' => $now,
				'user_status'     => 0,
				'display_name'    => 'admin',
			)
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress capability format.
		$wpdb->insert(
			"{$table_prefix}usermeta",
			array(
				'user_id'    => 1,
				'meta_key'   => "{$table_prefix}capabilities",
				'meta_value' => serialize( array( 'administrator' => true ) ),
			)
		);
		$wpdb->insert(
			"{$table_prefix}usermeta",
			array(
				'user_id'    => 1,
				'meta_key'   => "{$table_prefix}user_level",
				'meta_value' => '10',
			)
		);
		$wpdb->insert(
			"{$table_prefix}usermeta",
			array(
				'user_id'    => 1,
				'meta_key'   => 'nickname',
				'meta_value' => 'admin',
			)
		);

		$wpdb->insert(
			"{$table_prefix}posts",
			array(
				'ID'                => 1,
				'post_author'       => 1,
				'post_date'         => $now,
				'post_date_gmt'     => $now,
				'post_content'      => 'Welcome to your Rudel sandbox. This is your first post. Edit or delete it, then start writing!',
				'post_title'        => 'Hello world!',
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'open',
				'ping_status'       => 'open',
				'post_name'         => 'hello-world',
				'post_type'         => 'post',
				'post_modified'     => $now,
				'post_modified_gmt' => $now,
			)
		);

		$wpdb->insert(
			"{$table_prefix}posts",
			array(
				'ID'                => 2,
				'post_author'       => 1,
				'post_date'         => $now,
				'post_date_gmt'     => $now,
				'post_content'      => 'This is a sample page.',
				'post_title'        => 'Sample Page',
				'post_excerpt'      => '',
				'post_status'       => 'publish',
				'comment_status'    => 'closed',
				'ping_status'       => 'open',
				'post_name'         => 'sample-page',
				'post_type'         => 'page',
				'post_modified'     => $now,
				'post_modified_gmt' => $now,
			)
		);

		$wpdb->insert(
			"{$table_prefix}comments",
			array(
				'comment_ID'           => 1,
				'comment_post_ID'      => 1,
				'comment_author'       => 'A WordPress Commenter',
				'comment_author_email' => 'wapuu@wordpress.example',
				'comment_author_url'   => 'https://wordpress.org/',
				'comment_date'         => $now,
				'comment_date_gmt'     => $now,
				'comment_content'      => 'Hi, this is a comment.',
				'comment_approved'     => '1',
				'comment_type'         => 'comment',
			)
		);

		$wpdb->insert(
			"{$table_prefix}terms",
			array(
				'term_id'    => 1,
				'name'       => 'Uncategorized',
				'slug'       => 'uncategorized',
				'term_group' => 0,
			)
		);
		$wpdb->insert(
			"{$table_prefix}term_taxonomy",
			array(
				'term_taxonomy_id' => 1,
				'term_id'          => 1,
				'taxonomy'         => 'category',
				'description'      => '',
				'parent'           => 0,
				'count'            => 1,
			)
		);
		$wpdb->insert(
			"{$table_prefix}term_relationships",
			array(
				'object_id'        => 1,
				'term_taxonomy_id' => 1,
			)
		);
		// phpcs:enable
	}

	/**
	 * Get WordPress core table CREATE statements for MySQL.
	 *
	 * @param string $prefix           Table prefix.
	 * @param string $charset_collate  Charset/collation string.
	 * @return string[] Array of CREATE TABLE SQL statements.
	 */
	private function get_wordpress_mysql_table_schema( string $prefix, string $charset_collate ): array {
		return array(
			"CREATE TABLE IF NOT EXISTS `{$prefix}terms` (
				term_id bigint(20) unsigned NOT NULL auto_increment,
				name varchar(200) NOT NULL default '',
				slug varchar(200) NOT NULL default '',
				term_group bigint(10) NOT NULL default 0,
				PRIMARY KEY (term_id),
				KEY slug (slug(191)),
				KEY name (name(191))
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}term_taxonomy` (
				term_taxonomy_id bigint(20) unsigned NOT NULL auto_increment,
				term_id bigint(20) unsigned NOT NULL default 0,
				taxonomy varchar(32) NOT NULL default '',
				description longtext NOT NULL,
				parent bigint(20) unsigned NOT NULL default 0,
				count bigint(20) NOT NULL default 0,
				PRIMARY KEY (term_taxonomy_id),
				UNIQUE KEY term_id_taxonomy (term_id, taxonomy),
				KEY taxonomy (taxonomy)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}term_relationships` (
				object_id bigint(20) unsigned NOT NULL default 0,
				term_taxonomy_id bigint(20) unsigned NOT NULL default 0,
				term_order int(11) NOT NULL default 0,
				PRIMARY KEY (object_id, term_taxonomy_id),
				KEY term_taxonomy_id (term_taxonomy_id)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}termmeta` (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				term_id bigint(20) unsigned NOT NULL default 0,
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY term_id (term_id),
				KEY meta_key (meta_key(191))
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}commentmeta` (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				comment_id bigint(20) unsigned NOT NULL default 0,
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY comment_id (comment_id),
				KEY meta_key (meta_key(191))
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}comments` (
				comment_ID bigint(20) unsigned NOT NULL auto_increment,
				comment_post_ID bigint(20) unsigned NOT NULL default 0,
				comment_author tinytext NOT NULL,
				comment_author_email varchar(100) NOT NULL default '',
				comment_author_url varchar(200) NOT NULL default '',
				comment_author_IP varchar(100) NOT NULL default '',
				comment_date datetime NOT NULL default '0000-00-00 00:00:00',
				comment_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				comment_content text NOT NULL,
				comment_karma int(11) NOT NULL default 0,
				comment_approved varchar(20) NOT NULL default '1',
				comment_agent varchar(255) NOT NULL default '',
				comment_type varchar(20) NOT NULL default 'comment',
				comment_parent bigint(20) unsigned NOT NULL default 0,
				user_id bigint(20) unsigned NOT NULL default 0,
				PRIMARY KEY (comment_ID),
				KEY comment_post_ID (comment_post_ID),
				KEY comment_approved_date_gmt (comment_approved, comment_date_gmt),
				KEY comment_date_gmt (comment_date_gmt),
				KEY comment_parent (comment_parent),
				KEY comment_author_email (comment_author_email(10))
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}links` (
				link_id bigint(20) unsigned NOT NULL auto_increment,
				link_url varchar(255) NOT NULL default '',
				link_name varchar(255) NOT NULL default '',
				link_image varchar(255) NOT NULL default '',
				link_target varchar(25) NOT NULL default '',
				link_description varchar(255) NOT NULL default '',
				link_visible varchar(20) NOT NULL default 'Y',
				link_owner bigint(20) unsigned NOT NULL default 1,
				link_rating int(11) NOT NULL default 0,
				link_updated datetime NOT NULL default '0000-00-00 00:00:00',
				link_rel varchar(255) NOT NULL default '',
				link_notes mediumtext NOT NULL,
				link_rss varchar(255) NOT NULL default '',
				PRIMARY KEY (link_id),
				KEY link_visible (link_visible)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}options` (
				option_id bigint(20) unsigned NOT NULL auto_increment,
				option_name varchar(191) NOT NULL default '',
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL default 'yes',
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name),
				KEY autoload (autoload)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}postmeta` (
				meta_id bigint(20) unsigned NOT NULL auto_increment,
				post_id bigint(20) unsigned NOT NULL default 0,
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (meta_id),
				KEY post_id (post_id),
				KEY meta_key (meta_key(191))
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}posts` (
				ID bigint(20) unsigned NOT NULL auto_increment,
				post_author bigint(20) unsigned NOT NULL default 0,
				post_date datetime NOT NULL default '0000-00-00 00:00:00',
				post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				post_content longtext NOT NULL,
				post_title text NOT NULL,
				post_excerpt text NOT NULL,
				post_status varchar(20) NOT NULL default 'publish',
				comment_status varchar(20) NOT NULL default 'open',
				ping_status varchar(20) NOT NULL default 'open',
				post_password varchar(255) NOT NULL default '',
				post_name varchar(200) NOT NULL default '',
				to_ping text NOT NULL,
				pinged text NOT NULL,
				post_modified datetime NOT NULL default '0000-00-00 00:00:00',
				post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				post_content_filtered longtext NOT NULL,
				post_parent bigint(20) unsigned NOT NULL default 0,
				guid varchar(255) NOT NULL default '',
				menu_order int(11) NOT NULL default 0,
				post_type varchar(20) NOT NULL default 'post',
				post_mime_type varchar(100) NOT NULL default '',
				comment_count bigint(20) NOT NULL default 0,
				PRIMARY KEY (ID),
				KEY post_name (post_name(191)),
				KEY type_status_date (post_type, post_status, post_date, ID),
				KEY post_parent (post_parent),
				KEY post_author (post_author)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}users` (
				ID bigint(20) unsigned NOT NULL auto_increment,
				user_login varchar(60) NOT NULL default '',
				user_pass varchar(255) NOT NULL default '',
				user_nicename varchar(50) NOT NULL default '',
				user_email varchar(100) NOT NULL default '',
				user_url varchar(100) NOT NULL default '',
				user_registered datetime NOT NULL default '0000-00-00 00:00:00',
				user_activation_key varchar(255) NOT NULL default '',
				user_status int(11) NOT NULL default 0,
				display_name varchar(250) NOT NULL default '',
				PRIMARY KEY (ID),
				KEY user_login_key (user_login),
				KEY user_nicename (user_nicename),
				KEY user_email (user_email)
			) {$charset_collate}",
			"CREATE TABLE IF NOT EXISTS `{$prefix}usermeta` (
				umeta_id bigint(20) unsigned NOT NULL auto_increment,
				user_id bigint(20) unsigned NOT NULL default 0,
				meta_key varchar(255) default NULL,
				meta_value longtext,
				PRIMARY KEY (umeta_id),
				KEY user_id (user_id),
				KEY meta_key (meta_key(191))
			) {$charset_collate}",
		);
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
	 * @param string $engine        Database engine: 'mysql' or 'sqlite'.
	 * @return void
	 *
	 * @throws \RuntimeException If the template is not found or initialization fails.
	 */
	private function initialize_from_template( string $template_name, string $target_id, string $target_path, string $engine = 'mysql' ): void {
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
		$sandbox_url   = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $target_id;
		$source_id     = $meta['source_sandbox_id'] ?? '';
		$source_prefix = 'rudel_' . substr( md5( $source_id ), 0, 6 ) . '_';
		$target_prefix = 'rudel_' . substr( md5( $target_id ), 0, 6 ) . '_';

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
			if ( 'sqlite' === $engine ) {
				$this->write_db_drop_in( $target_path );
			}
		}
	}

	/**
	 * Clone from an existing sandbox: copy its db and wp-content, then rewrite URLs and prefix.
	 *
	 * @param Environment $source      Source sandbox to clone from.
	 * @param string      $target_id   New sandbox ID.
	 * @param string      $target_path New sandbox directory path.
	 * @param string      $engine      Database engine: 'mysql' or 'sqlite'.
	 * @return array Clone source metadata.
	 *
	 * @throws \RuntimeException If the database copy fails.
	 */
	private function clone_from_sandbox( Environment $source, string $target_id, string $target_path, string $engine = 'mysql' ): array {
		$source_prefix = $source->get_table_prefix();
		$target_prefix = 'rudel_' . substr( md5( $target_id ), 0, 6 ) . '_';

		$site_url    = defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
		$source_url  = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $source->id;
		$sandbox_url = $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $target_id;

		if ( 'mysql' === $engine ) {
			$mysql_cloner = new MySQLCloner();
			$mysql_cloner->copy_tables( $source_prefix, $target_prefix );

			global $wpdb;
			$mysql_cloner->rewrite_urls( $wpdb, $target_prefix, $source_url, $sandbox_url );
			$mysql_cloner->rewrite_table_prefix_in_data( $wpdb, $target_prefix, $source_prefix, $target_prefix );
		} else {
			$source_db = $source->get_db_path();
			$target_db = $target_path . '/wordpress.db';

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying SQLite database file.
			if ( ! copy( $source_db, $target_db ) ) {
				throw new \RuntimeException( sprintf( 'Failed to copy database from source sandbox: %s', $source->id ) );
			}

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
		}

		// Replace scaffolded wp-content with source sandbox's wp-content.
		$this->delete_directory( $target_path . '/wp-content' );
		$content_cloner = new ContentCloner();
		$content_cloner->copy_directory( $source->get_wp_content_path(), $target_path . '/wp-content' );

		if ( 'sqlite' === $engine ) {
			$this->write_db_drop_in( $target_path );
		}

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
