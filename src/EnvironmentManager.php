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

		$blog_id       = null;
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
					$clone_source = $this->build_clone_source(
						$this->get_host_site_url(),
						true,
						$clone_themes,
						$clone_plugins,
						$clone_uploads,
						array(
							'tables_cloned' => $clone_result['tables_cloned'],
							'rows_cloned'   => $clone_result['rows_cloned'],
						)
					);
				}

				if ( $has_clone && ! $clone_source ) {
					$clone_source = $this->build_clone_source(
						$this->get_host_site_url(),
						false,
						$clone_themes,
						$clone_plugins,
						$clone_uploads
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
				$table_prefix = Environment::table_prefix_for_id( $id );
				$site_url     = $this->get_host_site_url();
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

				$clone_source = $this->build_clone_source(
					$site_url,
					true,
					$clone_themes,
					$clone_plugins,
					$clone_uploads,
					array(
						'tables_cloned' => $clone_result['tables_cloned'],
						'rows_cloned'   => $clone_result['rows_cloned'],
					)
				);

				if ( $is_multisite ) {
					$clone_source['multisite'] = true;
				}
			} else {
				if ( 'sqlite' === $engine ) {
					$this->blank_wordpress()->create_sqlite_database( $id, $path );
				} elseif ( 'mysql' === $engine ) {
					$this->blank_wordpress()->create_mysql_database( $id );
				}

				if ( $has_clone ) {
					$clone_source = $this->build_clone_source(
						$this->get_host_site_url(),
						false,
						$clone_themes,
						$clone_plugins,
						$clone_uploads
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
					$table_prefix = Environment::table_prefix_for_id( $id );
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

		$this->write_runtime_mu_plugin( $path );

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
				if ( is_dir( $sandbox_sub ) && $this->directory_has_entries( $sandbox_sub ) ) {
					if ( is_dir( $host_sub ) ) {
						$this->delete_directory( $host_sub );
					}
					$content_cloner->copy_directory( $sandbox_sub, $host_sub );
				}
			}
		}

		$this->preserve_rudel_activation_on_host( $host_prefix );

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
			$old_prefix = Environment::table_prefix_for_id( $old_id );
			$new_prefix = Environment::table_prefix_for_id( $new_id );

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
	 * Get the host site URL without a trailing slash.
	 *
	 * @return string Host site URL.
	 */
	private function get_host_site_url(): string {
		return defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
	}

	/**
	 * Build clone metadata for a new environment.
	 *
	 * @param string $host_url       Source host URL.
	 * @param bool   $db_cloned      Whether the database was cloned.
	 * @param bool   $themes_cloned  Whether themes were cloned.
	 * @param bool   $plugins_cloned Whether plugins were cloned.
	 * @param bool   $uploads_cloned Whether uploads were cloned.
	 * @param array  $extra          Additional metadata to merge into the clone record.
	 * @return array<string, mixed> Clone metadata payload.
	 */
	private function build_clone_source(
		string $host_url,
		bool $db_cloned,
		bool $themes_cloned,
		bool $plugins_cloned,
		bool $uploads_cloned,
		array $extra = array()
	): array {
		return array_merge(
			array(
				'host_url'       => $host_url,
				'cloned_at'      => gmdate( 'c' ),
				'db_cloned'      => $db_cloned,
				'themes_cloned'  => $themes_cloned,
				'plugins_cloned' => $plugins_cloned,
				'uploads_cloned' => $uploads_cloned,
			),
			$extra
		);
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
	 * Check whether a directory contains at least one non-dot entry.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool True when the directory has real contents.
	 */
	private function directory_has_entries( string $path ): bool {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$iterator = new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $item ) {
			return true;
		}

		return false;
	}

	/**
	 * Keep Rudel active on the host after replacing the host database.
	 *
	 * Promote intentionally copies the sandbox's WordPress state to the host, but
	 * the CLI and bootstrap tooling still depend on Rudel remaining active there.
	 *
	 * @param string $host_prefix Host database table prefix.
	 * @return void
	 */
	private function preserve_rudel_activation_on_host( string $host_prefix ): void {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return;
		}

		$plugin_basename = $this->get_rudel_plugin_basename();
		$options_table   = $host_prefix . 'options';
		$option_row      = $this->find_table_row_by_value( $options_table, 'option_name', 'active_plugins' );
		$plugins         = $this->unserialize_array( $option_row['option_value'] ?? null );

		if ( in_array( $plugin_basename, $plugins, true ) ) {
			return;
		}

		$plugins[] = $plugin_basename;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores active plugins as a serialized PHP array.
		$serialized_plugins = serialize( array_values( array_unique( $plugins ) ) );

		if ( null === $option_row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting the active_plugins option when the promoted database does not have it yet.
			$wpdb->insert(
				$options_table,
				array(
					'option_name'  => 'active_plugins',
					'option_value' => $serialized_plugins,
					'autoload'     => 'yes',
				)
			);
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Updating host activation state after promote.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$options_table}` SET `option_value` = %s WHERE `option_name` = %s",
				$serialized_plugins,
				'active_plugins'
			)
		);
		// phpcs:enable
	}

	/**
	 * Find a single row in a small metadata table by one exact column match.
	 *
	 * @param string $table        Table name.
	 * @param string $match_column Column to match.
	 * @param string $match_value  Value to match.
	 * @return array<string, mixed>|null Matching row or null when absent.
	 */
	private function find_table_row_by_value( string $table, string $match_column, string $match_value ): ?array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! $wpdb ) {
			return null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading small options-like tables after promote.
		$rows = $wpdb->get_results( "SELECT * FROM `{$table}` LIMIT 1000 OFFSET 0" );
		// phpcs:enable

		foreach ( $rows as $row ) {
			$candidate = (array) $row;
			if ( ( $candidate[ $match_column ] ?? null ) === $match_value ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Decode a serialized PHP array stored by WordPress, or return an empty array.
	 *
	 * @param string|null $value Serialized WordPress option value.
	 * @return array<mixed> Decoded array, or an empty array on invalid input.
	 */
	private function unserialize_array( ?string $value ): array {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^a:\d+:/', $value ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores options as serialized PHP arrays.
		$decoded = unserialize( $value );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get the plugin basename used in WordPress's active_plugins option.
	 *
	 * @return string Plugin basename.
	 */
	private function get_rudel_plugin_basename(): string {
		if ( defined( 'RUDEL_PLUGIN_DIR' ) ) {
			return basename( rtrim( RUDEL_PLUGIN_DIR, '/' ) ) . '/rudel.php';
		}

		return 'rudel/rudel.php';
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
	 * Write the per-environment MU plugin with runtime hooks that must always load.
	 *
	 * @param string $path Absolute path to the environment directory.
	 * @return void
	 */
	private function write_runtime_mu_plugin( string $path ): void {
		if ( ! is_dir( $path . '/wp-content/mu-plugins' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Ensuring MU plugin directory exists after content copy.
			mkdir( $path . '/wp-content/mu-plugins', 0755, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local template.
		$template = file_get_contents( $this->plugin_dir . 'templates/runtime-mu-plugin.php.tpl' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing runtime MU plugin.
		file_put_contents( $path . '/wp-content/mu-plugins/rudel-runtime.php', $template );
	}

	/**
	 * Get the blank WordPress provisioner.
	 *
	 * @return BlankWordPressProvisioner
	 */
	private function blank_wordpress(): BlankWordPressProvisioner {
		return new BlankWordPressProvisioner();
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
		$source_prefix = Environment::table_prefix_for_id( $source_id );
		$target_prefix = Environment::table_prefix_for_id( $target_id );

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
		$target_prefix = Environment::table_prefix_for_id( $target_id );

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
