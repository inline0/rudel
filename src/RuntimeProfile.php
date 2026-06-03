<?php
/**
 * Runtime profile contract for embedded Rudel installs.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Defines all public runtime names Rudel must emit for one implementer.
 */
final class RuntimeProfile {

	/**
	 * Required runtime constant slots.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_CONSTANTS = array(
		'id',
		'path',
		'wp_config_path',
		'plugin_dir',
		'env_type',
		'engine',
		'table_prefix',
		'host_table_prefix',
		'host_url',
		'environment_url',
		'environment_content_url',
		'theme_slug',
		'template_slug',
		'theme_root',
		'theme_root_uri',
		'record_id',
		'disable_email',
		'user_scope',
		'users_table',
		'usermeta_table',
	);

	/**
	 * Validated profile data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Build a validated runtime profile.
	 *
	 * @param array<string, mixed> $data Profile data.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Create a profile from an associative array.
	 *
	 * @param array<string, mixed> $data Profile data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		self::require_string( $data, array( 'selectors', 'cookie' ) );
		self::require_string( $data, array( 'selectors', 'header' ) );
		self::require_string( $data, array( 'selectors', 'env' ) );

		foreach ( self::REQUIRED_CONSTANTS as $constant ) {
			self::require_constant_name( $data, $constant );
		}

		self::require_string( $data, array( 'runtime_tables', 'prefix' ) );
		self::require_string( $data, array( 'runtime_tables', 'environments' ) );
		self::require_string( $data, array( 'runtime_tables', 'worktrees' ) );
		self::require_string( $data, array( 'naming', 'environment_table_prefix' ) );
		self::require_string( $data, array( 'naming', 'isolated_users_table' ) );
		self::require_string( $data, array( 'naming', 'isolated_usermeta_table' ) );
		self::require_string( $data, array( 'naming', 'cache_key_salt' ) );
		self::require_string( $data, array( 'naming', 'git_branch_prefix' ) );
		self::require_string( $data, array( 'paths', 'environments_dir_name' ) );
		self::require_string( $data, array( 'paths', 'environments_dir_constant' ) );
		self::require_string( $data, array( 'paths', 'environment_content_url_path' ) );
		self::require_string( $data, array( 'paths', 'bootstrap_config_path' ) );
		self::require_string( $data, array( 'wp_config_marker' ) );
		self::require_string( $data, array( 'runtime_mu', 'file' ) );
		self::require_string( $data, array( 'runtime_mu', 'loaded_constant' ) );
		self::require_string( $data, array( 'runtime_mu', 'function_prefix' ) );
		self::require_string( $data, array( 'runtime_mu', 'admin_bar_node_id' ) );
		self::require_string( $data, array( 'runtime_mu', 'admin_bar_title' ) );
		self::require_string( $data, array( 'runtime_mu', 'email_log_label' ) );

		return new self( $data );
	}

	/**
	 * Resolve the currently installed runtime profile.
	 *
	 * @return self
	 * @throws \RuntimeException If no profile is installed.
	 */
	public static function current(): self {
		if ( isset( $GLOBALS['rudel_runtime_profile'] ) ) {
			$profile = $GLOBALS['rudel_runtime_profile'];
			if ( $profile instanceof self ) {
				return $profile;
			}
			if ( is_array( $profile ) ) {
				// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan type narrowing.
				/** @var array<string, mixed> $profile */
				$GLOBALS['rudel_runtime_profile'] = self::from_array( $profile );
				return $GLOBALS['rudel_runtime_profile'];
			}
		}

		throw new \RuntimeException( 'Rudel runtime profile is required before runtime operations can run.' );
	}

	/**
	 * Replace the current runtime profile.
	 *
	 * @param self|array<string, mixed>|null $profile Runtime profile or raw profile data.
	 * @return void
	 */
	public static function set_current( self|array|null $profile ): void {
		if ( null === $profile ) {
			unset( $GLOBALS['rudel_runtime_profile'] );
			return;
		}

		$GLOBALS['rudel_runtime_profile'] = is_array( $profile ) ? self::from_array( $profile ) : $profile;
	}

	/**
	 * Raw profile data for generated config files.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Cookie name used for request selection.
	 *
	 * @return string
	 */
	public function cookie_name(): string {
		return $this->string_at( array( 'selectors', 'cookie' ) );
	}

	/**
	 * Header name used for request selection.
	 *
	 * @return string
	 */
	public function header_name(): string {
		return $this->string_at( array( 'selectors', 'header' ) );
	}

	/**
	 * Server array key for the configured request header.
	 *
	 * @return string
	 */
	public function server_header_key(): string {
		$key = strtoupper( str_replace( '-', '_', $this->header_name() ) );
		if ( ! str_starts_with( $key, 'HTTP_' ) ) {
			$key = 'HTTP_' . $key;
		}

		return $key;
	}

	/**
	 * CLI environment variable name used for request selection.
	 *
	 * @return string
	 */
	public function environment_variable_name(): string {
		return $this->string_at( array( 'selectors', 'env' ) );
	}

	/**
	 * Whether URL subdomain selection is enabled for this profile.
	 *
	 * @return bool
	 */
	public function url_subdomain_enabled(): bool {
		$selectors = $this->data['selectors'] ?? array();
		return is_array( $selectors ) && ! empty( $selectors['url_subdomain'] );
	}

	/**
	 * Runtime constant name for one logical slot.
	 *
	 * @param string $key Logical constant key.
	 * @return string
	 */
	public function constant( string $key ): string {
		return $this->string_at( array( 'constants', $key ) );
	}

	/**
	 * Runtime metadata table-name prefix after the WordPress base prefix.
	 *
	 * @return string
	 */
	public function runtime_table_prefix(): string {
		return RuntimeTableConfig::normalize_prefix( $this->string_at( array( 'runtime_tables', 'prefix' ) ) );
	}

	/**
	 * Resolve one runtime metadata table base name.
	 *
	 * @param string $suffix Logical table suffix.
	 * @return string
	 */
	public function runtime_table( string $suffix ): string {
		$runtime_tables = $this->data['runtime_tables'] ?? array();
		$table          = is_array( $runtime_tables ) ? ( $runtime_tables[ $suffix ] ?? null ) : null;
		if ( is_string( $table ) && '' !== trim( $table ) ) {
			return trim( $table );
		}

		return $this->runtime_table_prefix() . $suffix;
	}

	/**
	 * Environment-owned WordPress table prefix for one ID.
	 *
	 * @param string $id Environment ID.
	 * @param string $host_prefix Host WordPress table prefix.
	 * @return string
	 */
	public function environment_table_prefix( string $id, string $host_prefix ): string {
		return $this->render(
			$this->string_at( array( 'naming', 'environment_table_prefix' ) ),
			array(
				'id'          => $id,
				'short_hash'  => substr( md5( $id ), 0, 7 ),
				'host_prefix' => $host_prefix,
			)
		);
	}

	/**
	 * Isolated users table name for one blog ID.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $host_prefix Host WordPress table prefix.
	 * @return string
	 */
	public function isolated_users_table( int $blog_id, string $host_prefix ): string {
		return $this->render(
			$this->string_at( array( 'naming', 'isolated_users_table' ) ),
			array(
				'blog_id'     => (string) $blog_id,
				'host_prefix' => $host_prefix,
			)
		);
	}

	/**
	 * Isolated usermeta table name for one blog ID.
	 *
	 * @param int    $blog_id Blog ID.
	 * @param string $host_prefix Host WordPress table prefix.
	 * @return string
	 */
	public function isolated_usermeta_table( int $blog_id, string $host_prefix ): string {
		return $this->render(
			$this->string_at( array( 'naming', 'isolated_usermeta_table' ) ),
			array(
				'blog_id'     => (string) $blog_id,
				'host_prefix' => $host_prefix,
			)
		);
	}

	/**
	 * Cache key salt for one active environment.
	 *
	 * @param string $id Environment ID.
	 * @return string
	 */
	public function cache_key_salt( string $id ): string {
		return $this->render(
			$this->string_at( array( 'naming', 'cache_key_salt' ) ),
			array( 'id' => $id )
		);
	}

	/**
	 * Git branch name for one environment.
	 *
	 * @param string $id Environment ID.
	 * @return string
	 */
	public function git_branch( string $id ): string {
		return $this->string_at( array( 'naming', 'git_branch_prefix' ) ) . $id;
	}

	/**
	 * Directory name under wp-content that stores environments.
	 *
	 * @return string
	 */
	public function environments_dir_name(): string {
		return trim( $this->string_at( array( 'paths', 'environments_dir_name' ) ), '/' );
	}

	/**
	 * Optional constant name that can override the environments directory.
	 *
	 * @return string
	 */
	public function environments_dir_constant(): string {
		return $this->string_at( array( 'paths', 'environments_dir_constant' ) );
	}

	/**
	 * URL path under wp-content for environment-owned runtime content.
	 *
	 * @return string
	 */
	public function environment_content_url_path(): string {
		return trim( $this->string_at( array( 'paths', 'environment_content_url_path' ) ), '/' );
	}

	/**
	 * Profile config path loaded by wp-config.php.
	 *
	 * @return string
	 */
	public function bootstrap_config_path(): string {
		return $this->string_at( array( 'paths', 'bootstrap_config_path' ) );
	}

	/**
	 * Marker comment used in wp-config.php.
	 *
	 * @return string
	 */
	public function wp_config_marker(): string {
		return $this->string_at( array( 'wp_config_marker' ) );
	}

	/**
	 * Runtime MU plugin filename.
	 *
	 * @return string
	 */
	public function runtime_mu_file(): string {
		return $this->string_at( array( 'runtime_mu', 'file' ) );
	}

	/**
	 * Runtime MU plugin loaded constant.
	 *
	 * @return string
	 */
	public function runtime_mu_loaded_constant(): string {
		return $this->string_at( array( 'runtime_mu', 'loaded_constant' ) );
	}

	/**
	 * Runtime MU plugin function prefix.
	 *
	 * @return string
	 */
	public function runtime_function_prefix(): string {
		return $this->string_at( array( 'runtime_mu', 'function_prefix' ) );
	}

	/**
	 * Runtime admin-bar node ID.
	 *
	 * @return string
	 */
	public function admin_bar_node_id(): string {
		return $this->string_at( array( 'runtime_mu', 'admin_bar_node_id' ) );
	}

	/**
	 * Runtime admin-bar title label.
	 *
	 * @return string
	 */
	public function admin_bar_title(): string {
		return $this->string_at( array( 'runtime_mu', 'admin_bar_title' ) );
	}

	/**
	 * Runtime email-block log label.
	 *
	 * @return string
	 */
	public function email_log_label(): string {
		return $this->string_at( array( 'runtime_mu', 'email_log_label' ) );
	}

	/**
	 * Whether a table prefix is managed by this profile.
	 *
	 * @param string $prefix Table prefix.
	 * @return bool
	 */
	public function manages_table_prefix( string $prefix ): bool {
		$naming   = $this->data['naming'] ?? array();
		$patterns = is_array( $naming ) ? ( $naming['managed_table_prefix_patterns'] ?? array() ) : array();
		if ( ! is_array( $patterns ) ) {
			$patterns = array();
		}

		foreach ( $patterns as $pattern ) {
			if ( is_string( $pattern ) && '' !== $pattern && 1 === preg_match( $pattern, $prefix ) ) {
				return true;
			}
		}

		$quoted_prefix = preg_quote( $this->runtime_table_prefix(), '/' );
		if ( '' !== $quoted_prefix && 1 === preg_match( '/^' . $quoted_prefix . '/', $prefix ) ) {
			return true;
		}

		return 1 === preg_match( '/^[A-Za-z0-9_]+_[a-f0-9]{7}_$/', $prefix );
	}

	/**
	 * Read one nested string value.
	 *
	 * @param array<int, string> $path Nested keys.
	 * @return string
	 * @throws \RuntimeException If the key is missing or invalid.
	 */
	private function string_at( array $path ): string {
		$value = $this->data;
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				throw new \RuntimeException( 'Runtime profile is missing required key: ' . implode( '.', $path ) );
			}
			$value = $value[ $segment ];
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \RuntimeException( 'Runtime profile key must be a non-empty string: ' . implode( '.', $path ) );
		}

		return $value;
	}

	/**
	 * Require one nested string value.
	 *
	 * @param array<string, mixed> $data Profile data.
	 * @param array<int, string>   $path Nested keys.
	 * @return void
	 * @throws \InvalidArgumentException If the key is missing or invalid.
	 */
	private static function require_string( array $data, array $path ): void {
		$value = $data;
		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				throw new \InvalidArgumentException( 'Runtime profile is missing required key: ' . implode( '.', $path ) );
			}
			$value = $value[ $segment ];
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			throw new \InvalidArgumentException( 'Runtime profile key must be a non-empty string: ' . implode( '.', $path ) );
		}
	}

	/**
	 * Require one configured PHP constant name.
	 *
	 * @param array<string, mixed> $data Profile data.
	 * @param string               $key Logical constant key.
	 * @return void
	 * @throws \InvalidArgumentException If the constant name is missing or invalid.
	 */
	private static function require_constant_name( array $data, string $key ): void {
		self::require_string( $data, array( 'constants', $key ) );
		$constants = $data['constants'] ?? array();
		$value     = is_array( $constants ) ? ( $constants[ $key ] ?? null ) : null;
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[A-Z][A-Z0-9_]*$/', $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'Runtime profile constant "%s" must be a valid constant name.', $key ) );
		}
	}

	/**
	 * Render simple `{{token}}` placeholders.
	 *
	 * @param string               $template Template string.
	 * @param array<string, mixed> $values Placeholder values.
	 * @return string
	 */
	private function render( string $template, array $values ): string {
		$replacements = array();
		foreach ( $values as $key => $value ) {
			$replacements[ '{{' . $key . '}}' ] = is_scalar( $value ) ? (string) $value : '';
		}

		return strtr( $template, $replacements );
	}
}
