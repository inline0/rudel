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
	 * Whether the current runtime is a WordPress multisite network.
	 *
	 * @return bool
	 */
	protected function is_multisite_network(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether the current multisite network uses subdomain sites.
	 *
	 * @return bool
	 */
	protected function is_subdomain_network(): bool {
		return function_exists( 'is_subdomain_install' ) && is_subdomain_install();
	}

	/**
	 * Build the native multisite site target for one Rudel environment.
	 *
	 * Rudel treats real multisite sites as the canonical browser runtime, so
	 * subsite creation follows the network's own site model directly.
	 *
	 * @param string $environment_id Environment slug.
	 * @return array{domain: string, path: string}
	 *
	 * @throws \RuntimeException When multisite is unavailable or the network is not subdomain-based.
	 */
	public function get_subsite_target( string $environment_id ): array {
		if ( ! $this->is_multisite_network() ) {
			throw new \RuntimeException( 'Subsite engine requires a WordPress multisite installation.' );
		}

		if ( ! $this->is_subdomain_network() ) {
			throw new \RuntimeException( 'Rudel requires a subdomain multisite network for native site isolation.' );
		}

		return array(
			'domain' => $environment_id . '.' . $this->get_current_network_domain(),
			'path'   => '/',
		);
	}

	/**
	 * Create a new multisite sub-site.
	 *
	 * @param string   $sandbox_id    Sandbox identifier (used for the sub-site slug).
	 * @param string   $title         Human-readable title for the sub-site.
	 * @param int|null $admin_user_id Optional user ID for the sub-site admin.
	 * @return int The new blog ID.
	 *
	 * @throws \RuntimeException If the host is not multisite or creation fails.
	 */
	public function create_subsite( string $sandbox_id, string $title, ?int $admin_user_id = null ): int {
		$target        = $this->get_subsite_target( $sandbox_id );
		$admin_user_id = $this->resolve_admin_user_id( $admin_user_id );

		if ( ! function_exists( 'wpmu_create_blog' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.create_blog -- Sandbox sub-site creation.
		$blog_id = wpmu_create_blog( $target['domain'], $target['path'], $title, $admin_user_id );

		if ( is_wp_error( $blog_id ) ) {
			throw new \RuntimeException(
				sprintf( 'Failed to create sub-site: %s', $blog_id->get_error_message() )
			);
		}

		return (int) $blog_id;
	}

	/**
	 * Choose a valid admin user for newly created multisite sites.
	 *
	 * @param int|null $admin_user_id Optional explicit override.
	 * @return int
	 */
	public function resolve_admin_user_id( ?int $admin_user_id = null ): int {
		if ( is_int( $admin_user_id ) && $admin_user_id > 0 ) {
			return $admin_user_id;
		}

		if ( function_exists( 'get_current_user_id' ) ) {
			$current_user_id_result = get_current_user_id();
			$current_user_id        = is_numeric( $current_user_id_result ) ? (int) $current_user_id_result : 0;
			if ( $current_user_id > 0 ) {
				return $current_user_id;
			}
		}

		if ( function_exists( 'get_super_admins' ) && function_exists( 'get_user_by' ) ) {
			$super_admins = get_super_admins();
			if ( is_array( $super_admins ) ) {
				foreach ( $super_admins as $login ) {
					if ( ! is_string( $login ) || '' === $login ) {
						continue;
					}

					$user = get_user_by( 'login', $login );
					if ( is_object( $user ) && isset( $user->ID ) ) {
						$user_id = is_numeric( $user->ID ) ? (int) $user->ID : 0;
						if ( $user_id > 0 ) {
							return $user_id;
						}
					}
				}
			}
		}

		return 1;
	}

	/**
	 * Update the canonical host stored for one multisite site.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $domain Host-only domain to persist.
	 * @param string $path Site path.
	 * @return void
	 *
	 * @throws \RuntimeException When the site domain cannot be updated.
	 */
	public function update_subsite_domain( int $blog_id, string $domain, string $path = '/' ): void {
		$domain = strtolower( trim( (string) preg_replace( '/:\d+$/', '', $domain ) ) );
		$path   = '' === $path ? '/' : $path;
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		if ( function_exists( 'wp_update_site' ) ) {
			$result = wp_update_site(
				$blog_id,
				array(
					'domain' => $domain,
					'path'   => $path,
				)
			);

			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException(
					sprintf( 'Failed to update multisite site domain: %s', $result->get_error_message() )
				);
			}

			return;
		}

		throw new \RuntimeException( 'Updating multisite site domains requires wp_update_site().' );
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
	 * URL for a multisite sub-site.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string Sub-site URL.
	 */
	public function get_subsite_url( int $blog_id ): string {
		$details = get_blog_details( $blog_id );
		if ( $details ) {
			$site_domain = isset( $details->domain ) && is_string( $details->domain ) ? $details->domain : '';
			$site_path   = isset( $details->path ) && is_string( $details->path ) ? $details->path : '/';

			if ( '' !== $site_domain ) {
				if ( '' === $site_path ) {
					$site_path = '/';
				}

				if ( ! str_starts_with( $site_path, '/' ) ) {
					$site_path = '/' . $site_path;
				}

				$site_url = $this->network_scheme() . '://' . $site_domain;
				if ( ! $this->domain_includes_port( $site_domain ) ) {
					$site_url .= $this->network_port_suffix();
				}

				return $site_url . rtrim( $site_path, '/' ) . '/';
			}

			if ( ! empty( $details->siteurl ) && is_string( $details->siteurl ) ) {
				return rtrim( $details->siteurl, '/' ) . '/';
			}
		}

		return $this->network_scheme() . '://' . $this->get_current_domain() . $this->network_port_suffix() . '/';
	}

	/**
	 * Clone the host's per-blog database tables into a sub-site.
	 *
	 * @param int $blog_id Target sub-site blog ID.
	 * @return array{tables_cloned: int, rows_cloned: int} Clone statistics.
	 *
	 * @throws \RuntimeException If the global $wpdb is not available.
	 */
	public function clone_host_db_to_subsite( int $blog_id ): array {
		global $wpdb;

		if ( ! is_object( $wpdb ) ) {
			throw new \RuntimeException( 'Global $wpdb is not available.' );
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing for global $wpdb.
		/** @var \wpdb $wpdb */
		$source_prefix = (string) $wpdb->base_prefix;
		$target_prefix = $source_prefix . $blog_id . '_';

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

			$count       = $wpdb->get_var( "SELECT COUNT(*) FROM `{$target_table}`" );
			$rows        = is_numeric( $count ) ? (int) $count : 0;
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
	 * Current multisite network domain.
	 *
	 * @return string Domain name.
	 */
	private function get_current_domain(): string {
		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime host derivation without relying on later WP URL helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$host = preg_replace( '/:\d+$/', '', (string) $parts['host'] );
				if ( is_string( $host ) && '' !== $host ) {
					return $host;
				}

				return 'localhost';
			}
		}

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$domain_str = is_string( DOMAIN_CURRENT_SITE ) ? DOMAIN_CURRENT_SITE : '';
			$host       = preg_replace( '/:\d+$/', '', $domain_str );
			if ( is_string( $host ) && '' !== $host ) {
				return $host;
			}

			return 'localhost';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Pre-validated in bootstrap context.
		$http_host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$host      = preg_replace( '/:\d+$/', '', $http_host );
		if ( is_string( $host ) && '' !== $host ) {
			return $host;
		}

		return 'localhost';
	}

	/**
	 * Current multisite network domain as stored in multisite site records.
	 *
	 * Multisite site rows store host-only domains. The network port belongs in
	 * rendered URLs, not in wp_site.domain or wp_blogs.domain.
	 *
	 * @return string Domain string suitable for wp_blogs.domain.
	 */
	private function get_current_network_domain(): string {
		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$domain_str = is_string( DOMAIN_CURRENT_SITE ) ? DOMAIN_CURRENT_SITE : '';
			$domain     = preg_replace( '/:\d+$/', '', trim( $domain_str ) );
			if ( is_string( $domain ) && '' !== $domain ) {
				return $domain;
			}
		}

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime host derivation without relying on later WP URL helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				return (string) $parts['host'];
			}
		}

		return $this->get_current_domain();
	}

	/**
	 * Network request scheme.
	 *
	 * @return string
	 */
	private function network_scheme(): string {
		$scheme = 'http';

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime host derivation without relying on later WP URL helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) ) {
				$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : $scheme;
			}
		}

		return $scheme;
	}

	/**
	 * Network port suffix including the leading colon when present.
	 *
	 * @return string
	 */
	private function network_port_suffix(): string {
		$port = null;

		if ( defined( 'WP_HOME' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runtime host derivation without relying on later WP URL helpers.
			$parts = parse_url( (string) WP_HOME );
			if ( is_array( $parts ) && isset( $parts['port'] ) && is_numeric( $parts['port'] ) ) {
				$port = (int) $parts['port'];
			}
		}

		return null === $port ? '' : ':' . $port;
	}

	/**
	 * Whether one multisite site domain already carries an explicit port.
	 *
	 * @param string $domain Domain value from a multisite site record.
	 * @return bool
	 */
	private function domain_includes_port( string $domain ): bool {
		return 1 === preg_match( '/:\d+$/', $domain );
	}
}
