<?php
/**
 * Subsite cloner: creates and manages WordPress multisite sub-sites for sandbox isolation.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Handles multisite sub-site creation, deletion, and database cloning for subsite-engine sandboxes.
 */
class SubsiteCloner {

	/**
	 * Create a new multisite sub-site.
	 *
	 * @param string $sandbox_id    Sandbox identifier (used for the sub-site slug).
	 * @param string $title         Human-readable title for the sub-site.
	 * @param int    $admin_user_id User ID for the sub-site admin.
	 * @return int The new blog ID.
	 *
	 * @throws \RuntimeException If the host is not multisite or creation fails.
	 */
	public function create_subsite( string $sandbox_id, string $title, int $admin_user_id = 1 ): int {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			throw new \RuntimeException( 'Subsite engine requires a WordPress multisite installation.' );
		}

		if ( ! function_exists( 'wpmu_create_blog' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		$domain = $this->get_current_domain();
		$path   = '/' . RUDEL_PATH_PREFIX . '/' . $sandbox_id . '/';

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.create_blog -- Sandbox sub-site creation.
		$blog_id = wpmu_create_blog( $domain, $path, $title, $admin_user_id );

		if ( is_wp_error( $blog_id ) ) {
			throw new \RuntimeException(
				sprintf( 'Failed to create sub-site: %s', $blog_id->get_error_message() )
			);
		}

		return (int) $blog_id;
	}

	/**
	 * Delete a multisite sub-site and drop its tables.
	 *
	 * @param int $blog_id Blog ID to delete.
	 * @return void
	 */
	public function delete_subsite( int $blog_id ): void {
		if ( ! function_exists( 'wpmu_delete_blog' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.delete_blog -- Sandbox cleanup.
		wpmu_delete_blog( $blog_id, true );
	}

	/**
	 * Get the URL for a sub-site.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string Sub-site URL.
	 */
	public function get_subsite_url( int $blog_id ): string {
		$details = get_blog_details( $blog_id );
		if ( $details && ! empty( $details->siteurl ) ) {
			return rtrim( $details->siteurl, '/' ) . '/';
		}

		$domain = $this->get_current_domain();
		return 'http://' . $domain . '/';
	}

	/**
	 * Clone the host's per-blog database tables into a sub-site.
	 *
	 * @param int $blog_id Target sub-site blog ID.
	 * @return array{tables_cloned: int, rows_cloned: int} Clone statistics.
	 */
	public function clone_host_db_to_subsite( int $blog_id ): array {
		global $wpdb;

		$source_prefix = $wpdb->base_prefix;
		$target_prefix = $wpdb->base_prefix . $blog_id . '_';

		$mysql_cloner = new MySQLCloner();

		$per_blog_suffixes = array(
			'posts',
			'postmeta',
			'comments',
			'commentmeta',
			'terms',
			'termmeta',
			'term_taxonomy',
			'term_relationships',
			'options',
			'links',
		);

		$total_rows  = 0;
		$table_count = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic table names for sub-site cloning.
		foreach ( $per_blog_suffixes as $suffix ) {
			$source_table = $source_prefix . $suffix;
			$target_table = $target_prefix . $suffix;

			$wpdb->query( "TRUNCATE TABLE `{$target_table}`" );
			$wpdb->query( "INSERT INTO `{$target_table}` SELECT * FROM `{$source_table}`" );

			$rows        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$target_table}`" );
			$total_rows += $rows;
			++$table_count;
		}
		// phpcs:enable

		$subsite_url = $this->get_subsite_url( $blog_id );
		$host_url    = rtrim( home_url(), '/' );
		$mysql_cloner->rewrite_urls( $wpdb, $target_prefix, $host_url, rtrim( $subsite_url, '/' ) );

		return array(
			'tables_cloned' => $table_count,
			'rows_cloned'   => $total_rows,
		);
	}

	/**
	 * Get the current site domain.
	 *
	 * @return string Domain name.
	 */
	private function get_current_domain(): string {
		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			return DOMAIN_CURRENT_SITE;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Pre-validated in bootstrap context.
		return $_SERVER['HTTP_HOST'] ?? 'localhost';
	}
}
