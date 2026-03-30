<?php
/**
 * GitHub integration: manages branches, files, and PRs via the GitHub API.
 *
 * Works on any host with PHP and outbound HTTP. No git binary required.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Interacts with GitHub repositories via the REST API for sandbox workflows.
 */
class GitHubIntegration {

	/**
	 * GitHub API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.github.com';

	/**
	 * GitHub repository in owner/repo format.
	 *
	 * @var string
	 */
	private string $repo;

	/**
	 * GitHub personal access token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Constructor.
	 *
	 * @param string      $repo  GitHub repository (owner/repo).
	 * @param string|null $token GitHub token. Falls back to RUDEL_GITHUB_TOKEN constant.
	 *
	 * @throws \RuntimeException If no token is available.
	 */
	public function __construct( string $repo, ?string $token = null ) {
		$this->repo  = $repo;
		$this->token = $token ?? ( defined( 'RUDEL_GITHUB_TOKEN' ) ? RUDEL_GITHUB_TOKEN : '' );

		if ( '' === $this->token ) {
			throw new \RuntimeException( 'GitHub token required. Define RUDEL_GITHUB_TOKEN in wp-config.php.' );
		}
	}

	/**
	 * Get the default branch of the repository.
	 *
	 * @return string Default branch name.
	 *
	 * @throws \RuntimeException If the API call fails.
	 */
	public function get_default_branch(): string {
		$response = $this->api( 'GET', "/repos/{$this->repo}" );
		return $response['default_branch'] ?? 'main';
	}

	/**
	 * Create a new branch from the default branch.
	 *
	 * @param string      $branch Branch name to create.
	 * @param string|null $base_branch Optional base branch. Falls back to the repository default branch.
	 * @return void
	 *
	 * @throws \RuntimeException If the API call fails.
	 */
	public function create_branch( string $branch, ?string $base_branch = null ): void {
		$base_branch ??= $this->get_default_branch();
		$ref           = $this->api( 'GET', "/repos/{$this->repo}/git/ref/heads/{$base_branch}" );
		$sha           = $ref['object']['sha'];

		$this->api(
			'POST',
			"/repos/{$this->repo}/git/refs",
			array(
				'ref' => 'refs/heads/' . $branch,
				'sha' => $sha,
			)
		);
	}

	/**
	 * Delete a branch.
	 *
	 * @param string $branch Branch name to delete.
	 * @return bool True on success.
	 */
	public function delete_branch( string $branch ): bool {
		try {
			$this->api( 'DELETE', "/repos/{$this->repo}/git/refs/heads/{$branch}" );
			return true;
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}

	/**
	 * Download all files from a branch into a local directory.
	 *
	 * @param string $branch    Branch to download from.
	 * @param string $local_dir Local directory to write files to.
	 * @return int Number of files downloaded.
	 *
	 * @throws \RuntimeException If the API call fails.
	 */
	public function download( string $branch, string $local_dir ): int {
		$tree  = $this->api( 'GET', "/repos/{$this->repo}/git/trees/{$branch}?recursive=1" );
		$count = 0;

		foreach ( $tree['tree'] ?? array() as $item ) {
			if ( 'blob' !== $item['type'] ) {
				continue;
			}

			$blob = $this->api( 'GET', "/repos/{$this->repo}/git/blobs/{$item['sha']}" );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding GitHub API blob content.
			$content  = base64_decode( $blob['content'] );
			$filepath = $local_dir . '/' . $item['path'];
			$dir      = dirname( $filepath );

			if ( ! is_dir( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating directory for downloaded file.
				mkdir( $dir, 0755, true );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing downloaded file.
			file_put_contents( $filepath, $content );
			++$count;
		}

		return $count;
	}

	/**
	 * Push local file changes to a branch as a single commit.
	 *
	 * Compares local files against the branch and creates a commit with all changes.
	 *
	 * @param string $branch    Branch to push to.
	 * @param string $local_dir Local directory with the files.
	 * @param string $message   Commit message.
	 * @return string|null Commit SHA on success, null if no changes.
	 *
	 * @throws \RuntimeException If the API call fails.
	 */
	public function push( string $branch, string $local_dir, string $message ): ?string {
		$ref       = $this->api( 'GET', "/repos/{$this->repo}/git/ref/heads/{$branch}" );
		$head_sha  = $ref['object']['sha'];
		$commit    = $this->api( 'GET', "/repos/{$this->repo}/git/commits/{$head_sha}" );
		$base_tree = $commit['tree']['sha'];

		$tree_items = array();
		$iterator   = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $local_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $local_dir ) + 1 );

			// Avoid pushing editor caches and local dotfiles by default.
			if ( str_starts_with( $relative, '.' ) || str_contains( $relative, '/.' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file for GitHub upload.
			$content = file_get_contents( $file->getPathname() );

			$blob = $this->api(
				'POST',
				"/repos/{$this->repo}/git/blobs",
				array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding file content for GitHub API.
					'content'  => base64_encode( $content ),
					'encoding' => 'base64',
				)
			);

			$tree_items[] = array(
				'path' => $relative,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $blob['sha'],
			);
		}

		if ( empty( $tree_items ) ) {
			return null;
		}

		$tree = $this->api(
			'POST',
			"/repos/{$this->repo}/git/trees",
			array(
				'base_tree' => $base_tree,
				'tree'      => $tree_items,
			)
		);

		$new_commit = $this->api(
			'POST',
			"/repos/{$this->repo}/git/commits",
			array(
				'message' => $message,
				'tree'    => $tree['sha'],
				'parents' => array( $head_sha ),
			)
		);

		$this->api(
			'PATCH',
			"/repos/{$this->repo}/git/refs/heads/{$branch}",
			array( 'sha' => $new_commit['sha'] )
		);

		return $new_commit['sha'];
	}

	/**
	 * Create a pull request.
	 *
	 * @param string      $branch Branch to create PR from.
	 * @param string      $title  PR title.
	 * @param string      $body   PR body/description.
	 * @param string|null $base_branch Optional base branch. Falls back to the repository default branch.
	 * @return array{number: int, url: string, html_url: string} PR data.
	 *
	 * @throws \RuntimeException If the API call fails.
	 */
	public function create_pr( string $branch, string $title, string $body = '', ?string $base_branch = null ): array {
		$base_branch ??= $this->get_default_branch();
		$response      = $this->api(
			'POST',
			"/repos/{$this->repo}/pulls",
			array(
				'title' => $title,
				'head'  => $branch,
				'base'  => $base_branch,
				'body'  => $body,
			)
		);

		return array(
			'number'   => $response['number'],
			'url'      => $response['url'],
			'html_url' => $response['html_url'],
		);
	}

	/**
	 * Check if a branch has been merged (via a closed+merged PR).
	 *
	 * @param string $branch Branch to check.
	 * @return bool True if merged.
	 */
	public function is_branch_merged( string $branch ): bool {
		try {
			$prs = $this->api( 'GET', "/repos/{$this->repo}/pulls?state=closed&head={$this->get_owner()}:{$branch}&per_page=1" );
			foreach ( $prs as $pr ) {
				if ( ! empty( $pr['merged_at'] ) ) {
					return true;
				}
			}
		} catch ( \RuntimeException $e ) {
			return false;
		}

		return false;
	}

	/**
	 * Get the repository owner from the repo string.
	 *
	 * @return string Owner name.
	 */
	private function get_owner(): string {
		return explode( '/', $this->repo )[0];
	}

	/**
	 * Make a GitHub API request.
	 *
	 * @param string     $method   HTTP method (GET, POST, PATCH, DELETE).
	 * @param string     $endpoint API endpoint path.
	 * @param array|null $body     Request body (JSON-encoded).
	 * @return array Response data.
	 *
	 * @throws \RuntimeException If the request fails.
	 */
	private function api( string $method, string $endpoint, ?array $body = null ): array {
		$url     = self::API_BASE . $endpoint;
		$headers = array(
			'Authorization: token ' . $this->token,
			'Accept: application/vnd.github.v3+json',
			'User-Agent: Rudel/' . ( defined( 'RUDEL_VERSION' ) ? RUDEL_VERSION : '0.0.0' ),
		);

		if ( null !== $body ) {
			$headers[] = 'Content-Type: application/json';
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_getinfo, WordPress.WP.AlternativeFunctions.curl_curl_close -- Direct cURL for GitHub API; WP HTTP API may not be available in bootstrap context.
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_CUSTOMREQUEST  => $method,
			)
		);

		if ( null !== $body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON encoding for GitHub API request body.
		}

		$response  = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		// phpcs:enable

		if ( false === $response ) {
			throw new \RuntimeException( 'GitHub API request failed: ' . $endpoint );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Decoding GitHub API response.
		$data = json_decode( $response, true ) ?? array();

		if ( $http_code >= 400 ) {
			$message = $data['message'] ?? 'Unknown error';
			throw new \RuntimeException( sprintf( 'GitHub API error (%d): %s [%s]', $http_code, $message, $endpoint ) );
		}

		return $data;
	}
}
