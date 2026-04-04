<?php
/**
 * Provision blank WordPress databases for Rudel environments.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Creates baseline WordPress databases for SQLite and MySQL environments.
 */
class BlankWordPressProvisioner {

	/**
	 * Create a blank SQLite database with WordPress schema and default content.
	 *
	 * @param string $id Environment identifier.
	 * @param string $path Absolute path to the environment directory.
	 * @param array  $options Optional site settings.
	 * @return void
	 */
	public function create_sqlite_database( string $id, string $path, array $options = array() ): void {
		$db_path      = $path . '/wordpress.db';
		$table_prefix = Environment::table_prefix_for_id( $id );

		// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite database creation requires PDO; $wpdb is unavailable.
		$pdo = new \PDO( 'sqlite:' . $db_path );
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		// phpcs:enable

		$tables = $this->get_wordpress_table_schema( $table_prefix );
		foreach ( $tables as $sql ) {
			$pdo->exec( $sql );
		}

		$site_url         = $this->get_host_site_url();
		$environment_url  = $options['site_url'] ?? $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id;
		$site_name        = $options['site_name'] ?? 'Rudel Sandbox';
		$site_description = $options['site_description'] ?? 'A sandboxed WordPress environment';
		$admin_email      = $options['admin_email'] ?? 'admin@sandbox.local';

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores options as serialized PHP arrays.
		$options = array(
			array( 'siteurl', $environment_url ),
			array( 'home', $environment_url ),
			array( 'blogname', $site_name ),
			array( 'blogdescription', $site_description ),
			array( 'admin_email', $admin_email ),
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
	 * @param string $id Environment identifier.
	 * @param array  $options Optional site settings.
	 * @return void
	 */
	public function create_mysql_database( string $id, array $options = array() ): void {
		global $wpdb;

		$table_prefix     = Environment::table_prefix_for_id( $id );
		$site_url         = $this->get_host_site_url();
		$environment_url  = $options['site_url'] ?? $site_url . '/' . RUDEL_PATH_PREFIX . '/' . $id;
		$site_name        = $options['site_name'] ?? 'Rudel Sandbox';
		$site_description = $options['site_description'] ?? 'A sandboxed WordPress environment';
		$admin_email      = $options['admin_email'] ?? 'admin@sandbox.local';

		// Reuse WordPress's canonical schema so blank MySQL environments match core table definitions.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic table prefix for environment isolation; all table names are internally generated.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$tables = $this->get_wordpress_mysql_table_schema( $table_prefix, $charset_collate );
		foreach ( $tables as $sql ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL from internal schema, not user input.
		}

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- WordPress stores options as serialized PHP arrays; meta_key inserts required for WP bootstrap.
		$options = array(
			array( 'siteurl', $environment_url ),
			array( 'home', $environment_url ),
			array( 'blogname', $site_name ),
			array( 'blogdescription', $site_description ),
			array( 'admin_email', $admin_email ),
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
	 * Host site URL without a trailing slash.
	 *
	 * @return string Host site URL.
	 */
	private function get_host_site_url(): string {
		return defined( 'WP_HOME' ) ? rtrim( WP_HOME, '/' ) : 'http://localhost';
	}

	/**
	 * Get WordPress core table CREATE statements for MySQL.
	 *
	 * @param string $prefix          Table prefix.
	 * @param string $charset_collate Charset/collation string.
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
	 * Default WordPress user role definitions for blank installs.
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
}
