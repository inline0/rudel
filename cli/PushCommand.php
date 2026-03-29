<?php
/**
 * WP-CLI command to push sandbox changes to GitHub.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\GitHubIntegration;
use WP_CLI;

/**
 * Push sandbox file changes to GitHub.
 */
class PushCommand extends AbstractEnvironmentCommand {

	/**
	 * Push sandbox file changes to GitHub.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--github=<repo>]
	 * : GitHub repository (owner/repo). Remembered after first use.
	 *
	 * [--message=<message>]
	 * : Commit message.
	 * ---
	 * default: Update from Rudel sandbox
	 * ---
	 *
	 * [--dir=<dir>]
	 * : Subdirectory within wp-content to push (e.g. themes/my-theme). Defaults to all of wp-content.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel push my-sandbox-a1b2 --github=inline0/my-theme --dir=themes/my-theme --message="Add header template"
	 *     Success: Pushed to rudel/my-sandbox-a1b2 (abc1234)
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
			WP_CLI::error( 'GitHub repo required. Pass --github=owner/repo (only needed on first push).' );
		}

		$message   = $assoc_args['message'] ?? 'Update from Rudel sandbox';
		$subdir    = $assoc_args['dir'] ?? $sandbox->clone_source['github_dir'] ?? '';
		$branch    = $sandbox->get_git_branch();
		$local_dir = $sandbox->get_wp_content_path();
		if ( '' !== $subdir ) {
			$local_dir .= '/' . ltrim( $subdir, '/' );
		}

		if ( ! is_dir( $local_dir ) ) {
			WP_CLI::error( "Directory not found: {$local_dir}" );
		}

		try {
			$github = new GitHubIntegration( $repo );

			WP_CLI::log( "Ensuring branch {$branch} exists..." );
			try {
				$github->create_branch( $branch );
				WP_CLI::log( '  Branch created.' );
			} catch ( \RuntimeException $e ) {
				if ( str_contains( $e->getMessage(), 'Reference already exists' ) ) {
					WP_CLI::log( '  Branch already exists.' );
				} else {
					throw $e;
				}
			}

			WP_CLI::log( 'Pushing changes...' );
			$sha = $github->push( $branch, $local_dir, $message );
			if ( $sha ) {
				if ( ! $sandbox->get_github_repo() ) {
					$clone_source                = $sandbox->clone_source ?? array();
					$clone_source['github_repo'] = $repo;
					$sandbox->update_meta( 'clone_source', $clone_source );
				}
				WP_CLI::success( "Pushed to {$branch} ({$sha})" );
			} else {
				WP_CLI::log( 'No changes to push.' );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}
