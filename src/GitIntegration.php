<?php
/**
 * Git integration built on Pitmaster.
 *
 * @package Rudel
 */

namespace Rudel;

use Pitmaster\Object\ObjectId;
use Pitmaster\Pitmaster;
use Pitmaster\Protocol\ReceivePackClient;
use Pitmaster\Protocol\SmartHttpClient;
use Pitmaster\Repository;
use Pitmaster\Status\FileStatus;
use Pitmaster\Status\StatusEntry;

/**
 * Handles Rudel's repository, worktree, and remote Git workflows without shelling out to git.
 */
class GitIntegration {

	/**
	 * Scan a directory for git repos and create worktrees in the target.
	 *
	 * @param string   $source_dir Source directory (e.g. host wp-content/themes).
	 * @param string   $target_dir Target directory (e.g. sandbox wp-content/themes).
	 * @param string   $environment_id Environment ID used for branch naming and worktree metadata naming.
	 * @param string[] $exclude_entries Top-level directory names to skip while cloning.
	 * @return array{
	 *     worktrees: array<int, array{name:string, branch:string, repo:string, metadata_name:string}>,
	 *     copied: string[]
	 * }
	 */
	public function clone_with_worktrees( string $source_dir, string $target_dir, string $environment_id, array $exclude_entries = array() ): array {
		$results = array(
			'worktrees' => array(),
			'copied'    => array(),
		);

		if ( ! is_dir( $source_dir ) ) {
			return $results;
		}

		$entries = scandir( $source_dir );
		if ( false === $entries ) {
			return $results;
		}

		$git_entries   = array();
		$plain_entries = array();

		foreach ( $entries as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}
			if ( in_array( $dir, $exclude_entries, true ) ) {
				continue;
			}

			$source_path = $source_dir . '/' . $dir;

			if ( ! is_dir( $source_path ) ) {
				continue;
			}

			if ( $this->is_git_repo( $source_path ) ) {
				$git_entries[] = $dir;
				continue;
			}

			$plain_entries[] = $dir;
		}

		if ( ! empty( $plain_entries ) ) {
			$cloner = new ContentCloner();
			$cloner->copy_named_directories( $source_dir, $target_dir, $plain_entries );
			$results['copied'] = array_values( array_merge( $results['copied'], $plain_entries ) );
		}

		foreach ( $git_entries as $dir ) {
			$source_path   = $source_dir . '/' . $dir;
			$target_path   = $target_dir . '/' . $dir;
			$branch        = 'rudel/' . $environment_id;
			$metadata_name = $this->worktree_metadata_name( $environment_id, basename( $target_dir ), $dir );

			if ( $this->create_worktree( $source_path, $target_path, $branch, $metadata_name ) ) {
				$results['worktrees'][] = array(
					'name'          => $dir,
					'branch'        => $branch,
					'repo'          => $target_path,
					'metadata_name' => $metadata_name,
				);
				continue;
			}

			$cloner = new ContentCloner();
			$cloner->copy_directory( $source_path, $target_path );
			$results['copied'][] = $dir;
		}

		return $results;
	}

	/**
	 * Check if a directory is a Git repository.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	public function is_git_repo( string $path ): bool {
		return Pitmaster::isRepository( $path );
	}

	/**
	 * Clone a remote repository into a local checkout and switch it to the Rudel branch.
	 *
	 * @param string      $remote_url Remote repository URL.
	 * @param string      $target_path Checkout path.
	 * @param string      $branch Rudel branch name.
	 * @param string|null $base_branch Optional base branch override.
	 * @return array{branch: string, base_branch: string}
	 */
	public function clone_remote_checkout( string $remote_url, string $target_path, string $branch, ?string $base_branch = null ): array {
		$repo = Pitmaster::clone( $remote_url, $target_path );

		$this->ensure_identity( $repo );
		$this->configure_remote( $repo, $remote_url );

		$base_branch ??= $repo->defaultBranch();
		$target        = $this->resolve_branch_target( $repo, $base_branch );

		if ( null === $repo->branch( $branch ) ) {
			$repo->createBranch( $branch, $target );
		}

		$repo->checkout( $branch );

		return array(
			'branch'      => $branch,
			'base_branch' => $base_branch,
		);
	}

	/**
	 * Commit and push one checkout to its configured remote.
	 *
	 * @param string      $repo_path Checkout path.
	 * @param string      $branch Branch to push.
	 * @param string      $message Commit message.
	 * @param string|null $remote_url Optional remote URL override.
	 * @return string|null Commit SHA, or null when nothing changed.
	 *
	 * @throws \RuntimeException If the checkout cannot be opened, committed, or pushed.
	 */
	public function push_checkout( string $repo_path, string $branch, string $message, ?string $remote_url = null ): ?string {
		$repo = Pitmaster::open( $repo_path );

		$this->ensure_identity( $repo );
		if ( is_string( $remote_url ) && '' !== $remote_url ) {
			$this->configure_remote( $repo, $remote_url );
		}

		try {
			$repo->fetch( 'origin' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$current_branch = $repo->branch();
		if ( $branch !== $current_branch ) {
			if ( null === $repo->branch( $branch ) ) {
				$repo->createBranch( $branch, $this->resolve_branch_target( $repo, $repo->defaultBranch() ) );
			}
			$repo->checkout( $branch );
		}

		if ( ! $this->stage_worktree( $repo ) ) {
			return null;
		}

		try {
			$commit = $repo->commit( $message );
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'Nothing to commit' ) ) {
				return null;
			}

			throw $e;
		}

		$repo->push( 'origin', $branch );

		return $commit->hex;
	}

	/**
	 * Create a linked worktree in the target path on a new branch.
	 *
	 * @param string      $repo_path Path to a repo checkout or common git dir.
	 * @param string      $target_path Path for the new worktree.
	 * @param string      $branch Branch name to create or reuse.
	 * @param string|null $metadata_name Optional explicit linked-worktree metadata name.
	 * @return bool
	 */
	public function create_worktree( string $repo_path, string $target_path, string $branch, ?string $metadata_name = null ): bool {
		try {
			Pitmaster::open( $repo_path )->addWorktree( $target_path, $branch, null, $metadata_name );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Remove a linked worktree and its checkout directory.
	 *
	 * @param string      $repo_path Path to a repo checkout or common git dir.
	 * @param string      $target_path Path to the worktree checkout.
	 * @param string|null $metadata_name Optional explicit linked-worktree metadata name.
	 * @return bool
	 */
	public function remove_worktree( string $repo_path, string $target_path, ?string $metadata_name = null ): bool {
		try {
			Pitmaster::open( $repo_path )->removeWorktree( $metadata_name ?? $target_path, true );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		if ( is_dir( $target_path ) ) {
			$this->delete_directory( $target_path );
		}

		return ! file_exists( $target_path );
	}

	/**
	 * Check if a branch has been merged into the target branch.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @param string $branch Branch to check.
	 * @param string $target_branch Branch to check against.
	 * @return bool
	 */
	public function is_branch_merged( string $repo_path, string $branch, string $target_branch = 'main' ): bool {
		try {
			$repo = Pitmaster::open( $repo_path );

			try {
				$repo->fetch( 'origin' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return $repo->isBranchMerged( $branch, $target_branch );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Delete a local branch from a repository.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @param string $branch Branch name.
	 * @return bool
	 */
	public function delete_branch( string $repo_path, string $branch ): bool {
		try {
			Pitmaster::open( $repo_path )->deleteBranch( $branch );
			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Delete a branch from the configured remote.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @param string $branch Branch name.
	 * @param string $remote Remote name.
	 * @return bool
	 */
	public function delete_remote_branch( string $repo_path, string $branch, string $remote = 'origin' ): bool {
		try {
			$repo = Pitmaster::open( $repo_path );
			$url  = $repo->config()->get( "remote.{$remote}.url" );
			if ( null === $url || '' === $url ) {
				return false;
			}

			$http      = new SmartHttpClient();
			$discovery = $http->discoverRefs( $url );
			$ref_name  = "refs/heads/{$branch}";
			$old_id    = $discovery->ref( $ref_name );

			if ( null === $old_id ) {
				return true;
			}

			( new ReceivePackClient( $http ) )->push(
				$url,
				array(
					array(
						'old' => $old_id,
						'new' => ObjectId::fromHex( str_repeat( '0', 40 ) ),
						'ref' => $ref_name,
					),
				),
				''
			);

			return true;
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Repository default branch.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @return string
	 */
	public function get_default_branch( string $repo_path ): string {
		try {
			return Pitmaster::open( $repo_path )->defaultBranch();
		} catch ( \Throwable $e ) {
			unset( $e );
			return 'main';
		}
	}

	/**
	 * Resolve the common git dir for a checkout or worktree path.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @return string|null
	 */
	public function common_git_dir( string $repo_path ): ?string {
		return Pitmaster::commonGitDir( $repo_path );
	}

	/**
	 * Read the configured remote URL from a checkout, if any.
	 *
	 * @param string $repo_path Path to a repo checkout or common git dir.
	 * @param string $remote Remote name.
	 * @return string|null
	 */
	public function remote_url( string $repo_path, string $remote = 'origin' ): ?string {
		try {
			return Pitmaster::open( $repo_path )->config()->get( "remote.{$remote}.url" );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Ensure repo identity exists so commits can be created consistently.
	 *
	 * @param Repository $repo Repository handle.
	 * @return void
	 */
	private function ensure_identity( Repository $repo ): void {
		$config = $repo->config();
		$dirty  = false;

		if ( null === $config->get( 'user.name' ) ) {
			$config->set( 'user.name', defined( 'RUDEL_GIT_AUTHOR_NAME' ) ? (string) RUDEL_GIT_AUTHOR_NAME : 'Rudel' );
			$dirty = true;
		}

		if ( null === $config->get( 'user.email' ) ) {
			$config->set( 'user.email', defined( 'RUDEL_GIT_AUTHOR_EMAIL' ) ? (string) RUDEL_GIT_AUTHOR_EMAIL : 'rudel@localhost' );
			$dirty = true;
		}

		if ( $dirty ) {
			$config->writeToFile( $repo->commonGitDir() . '/config' );
		}
	}

	/**
	 * Ensure the checkout points at the requested remote URL.
	 *
	 * @param Repository $repo Repository handle.
	 * @param string     $remote_url Remote URL.
	 * @param string     $remote Remote name.
	 * @return void
	 */
	private function configure_remote( Repository $repo, string $remote_url, string $remote = 'origin' ): void {
		$config = $repo->config();
		$fetch  = "+refs/heads/*:refs/remotes/{$remote}/*";
		$dirty  = false;

		if ( $config->get( "remote.{$remote}.url" ) !== $remote_url ) {
			$config->set( "remote.{$remote}.url", $remote_url );
			$dirty = true;
		}

		if ( $config->get( "remote.{$remote}.fetch" ) !== $fetch ) {
			$config->set( "remote.{$remote}.fetch", $fetch );
			$dirty = true;
		}

		if ( $dirty ) {
			$config->writeToFile( $repo->commonGitDir() . '/config' );
		}
	}

	/**
	 * Pick the commit a new Rudel branch should start from.
	 *
	 * @param Repository $repo Repository handle.
	 * @param string     $base_branch Base branch name.
	 * @return ObjectId|null
	 */
	private function resolve_branch_target( Repository $repo, string $base_branch ): ?ObjectId {
		$refs = $repo->refDatabase();

		return $refs->resolve( "refs/remotes/origin/{$base_branch}" )
			?? $refs->resolve( "refs/heads/{$base_branch}" )
			?? $refs->resolveHead();
	}

	/**
	 * Stage the current worktree contents.
	 *
	 * @param Repository $repo Repository handle.
	 * @return bool True when there are staged or unstaged changes to commit.
	 */
	private function stage_worktree( Repository $repo ): bool {
		$status       = $repo->status();
		$add_paths    = array();
		$remove_paths = array();
		$has_changes  = false;

		foreach ( $status as $entry ) {
			if ( ! $entry instanceof StatusEntry ) {
				continue;
			}

			if ( FileStatus::Ignored === $entry->index ) {
				continue;
			}

			$has_changes = $has_changes
				|| FileStatus::Unmodified !== $entry->index
				|| FileStatus::Unmodified !== $entry->worktree;

			$full_path = $repo->workDir() . '/' . $entry->path;
			if ( FileStatus::Deleted === $entry->worktree || ! file_exists( $full_path ) ) {
				$remove_paths[] = $entry->path;
				continue;
			}

			if ( FileStatus::Untracked === $entry->index || FileStatus::Unmodified !== $entry->worktree ) {
				$add_paths[] = $entry->path;
			}
		}

		if ( ! empty( $remove_paths ) ) {
			$repo->remove( ...array_values( array_unique( $remove_paths ) ) );
		}

		if ( ! empty( $add_paths ) ) {
			$repo->add( ...array_values( array_unique( $add_paths ) ) );
		}

		return $has_changes;
	}

	/**
	 * Build a deterministic linked-worktree metadata name for one environment checkout.
	 *
	 * @param string $environment_id Environment slug.
	 * @param string $content_type Top-level wp-content directory such as themes or plugins.
	 * @param string $entry_name Repository directory name inside the content type.
	 * @return string
	 */
	private function worktree_metadata_name( string $environment_id, string $content_type, string $entry_name ): string {
		$parts = array_filter(
			array(
				$this->sanitize_worktree_fragment( $environment_id ),
				$this->sanitize_worktree_fragment( $content_type ),
				$this->sanitize_worktree_fragment( $entry_name ),
			),
			static fn( string $value ): bool => '' !== $value
		);

		$base = implode( '-', $parts );
		if ( '' === $base ) {
			$base = 'worktree';
		}

		if ( strlen( $base ) > 72 ) {
			$base = rtrim( substr( $base, 0, 72 ), '-' );
		}

		return 'rudel-' . $base . '-' . substr( md5( $environment_id . '|' . $content_type . '|' . $entry_name ), 0, 8 );
	}

	/**
	 * Normalize one metadata-name fragment into a filesystem-safe slug.
	 *
	 * @param string $value Raw fragment.
	 * @return string
	 */
	private function sanitize_worktree_fragment( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( $value, '-' );
	}

	/**
	 * Remove a directory recursively.
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private function delete_directory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->delete_directory( $path );
				continue;
			}

			// phpcs:ignore -- Git worktree cleanup can run without WordPress filesystem helpers being bootstrapped.
			unlink( $path );
		}

		// phpcs:ignore -- Git worktree cleanup can run without WordPress filesystem helpers being bootstrapped.
		rmdir( $dir );
	}
}
