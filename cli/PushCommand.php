<?php
/**
 * WP-CLI command to push sandbox changes to a Git remote.
 *
 * @package Rudel
 */

namespace Rudel\CLI;

use Rudel\GitIntegration;
use WP_CLI;

/**
 * Push sandbox file changes to a tracked remote.
 */
class PushCommand extends AbstractEnvironmentCommand {

	/**
	 * Push sandbox file changes to a Git remote.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Sandbox ID.
	 *
	 * [--git=<remote>]
	 * : Git remote URL. Remembered after first successful push.
	 *
	 * [--message=<message>]
	 * : Commit message.
	 * ---
	 * default: Update from Rudel sandbox
	 * ---
	 *
	 * [--dir=<dir>]
	 * : Subdirectory within wp-content to push (e.g. themes/my-theme). Defaults to the tracked directory or all of wp-content.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp rudel push my-sandbox-a1b2 --git=https://example.test/theme.git --dir=themes/my-theme --message="Add header template"
	 *     Success: Pushed to rudel/my-sandbox-a1b2 (abc1234)
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ): void {
		$sandbox = $this->require_environment( $args[0] );
		$remote  = $assoc_args['git'] ?? $sandbox->get_git_remote();

		if ( ! $remote ) {
			WP_CLI::error( 'Git remote required. Pass --git=<remote> (only needed on first push).' );
		}

		$message   = $assoc_args['message'] ?? 'Update from Rudel sandbox';
		$subdir    = $assoc_args['dir'] ?? $sandbox->get_git_dir() ?? '';
		$branch    = $sandbox->get_git_branch();
		$local_dir = $sandbox->get_runtime_content_path( $subdir );

		if ( ! is_dir( $local_dir ) ) {
			WP_CLI::error( "Directory not found: {$local_dir}" );
		}

		try {
			WP_CLI::log( "Pushing {$branch}..." );
			$sha = $this->git()->push_checkout( $local_dir, $branch, $message, $remote );
			if ( $sha ) {
				if ( ! $sandbox->get_git_remote() ) {
					$clone_source               = $sandbox->clone_source ?? array();
					$clone_source['git_remote'] = $remote;
					$this->manager->update(
						$sandbox->id,
						array(
							'clone_source' => $clone_source,
						)
					);
				}
				WP_CLI::success( "Pushed to {$branch} ({$sha})" );
				return;
			}

			WP_CLI::log( 'No changes to push.' );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Create the Git integration service.
	 *
	 * @return GitIntegration
	 */
	protected function git(): GitIntegration {
		return new GitIntegration();
	}
}
