<?php
/**
 * WP-CLI command to open GitHub pull requests from sandboxes.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\GitHubIntegration;
use WP_CLI;

/**
 * Create a GitHub pull request from a sandbox branch.
 */
class PrCommand extends AbstractEnvironmentCommand {

	/**
	 * Create a GitHub pull request from a sandbox branch.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--github=<repo>]
	 * : GitHub repository (owner/repo). Uses stored repo from previous push if omitted.
	 *
	 * [--title=<title>]
	 * : PR title. Defaults to the sandbox name.
	 *
	 * [--body=<body>]
	 * : PR description.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel pr my-sandbox-a1b2 --github=inline0/my-theme --title="Add header template"
	 *     Success: PR #3 created: https://github.com/inline0/my-theme/pull/3
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$sandbox = $this->require_environment( $args[0] );
		$repo    = $assoc_args['github'] ?? $sandbox->get_github_repo();

		if ( ! $repo ) {
			WP_CLI::error( 'GitHub repo required. Pass --github=owner/repo or push first to store it.' );
		}

		$branch = $sandbox->get_git_branch();
		$title  = $assoc_args['title'] ?? $sandbox->name;
		$body   = $assoc_args['body'] ?? "Created from Rudel sandbox `{$sandbox->id}`";

		try {
			$pr = ( new GitHubIntegration( $repo ) )->create_pr( $branch, $title, $body );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( "PR #{$pr['number']} created: {$pr['html_url']}" );
	}
}
