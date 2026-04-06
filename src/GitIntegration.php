<?php
/**
 * Git integration: creates worktrees for git-tracked themes and plugins.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Detects git repos in wp-content and creates worktrees for sandbox isolation.
 * This is an optional add-on. Sandboxes work without git; this just adds
 * branch-based workflows when git repos are present.
 */
class GitIntegration {

	/**
	 * Scan a directory for git repos and create worktrees in the target.
	 *
	 * For each subdirectory that is a git repo, creates a git worktree
	 * on a new branch instead of copying files.
	 *
	 * @param string $source_dir  Source directory (e.g., host wp-content/themes).
	 * @param string $target_dir  Target directory (e.g., sandbox wp-content/themes).
	 * @param string $sandbox_id  Sandbox ID (used for branch naming).
	 * @return array{worktrees: array<string, string>, copied: string[]} Results: worktree branch names and plain-copied dirs.
	 */
	public function clone_with_worktrees( string $source_dir, string $target_dir, string $sandbox_id ): array {
		$results = array(
			'worktrees' => array(),
			'copied'    => array(),
		);

		if ( ! is_dir( $source_dir ) ) {
			return $results;
		}

		$dirs = scandir( $source_dir );
		foreach ( $dirs as $dir ) {
			if ( '.' === $dir || '..' === $dir ) {
				continue;
			}

			$source_path = $source_dir . '/' . $dir;
			$target_path = $target_dir . '/' . $dir;

			if ( ! is_dir( $source_path ) ) {
				continue;
			}

			if ( $this->is_git_repo( $source_path ) ) {
				$branch = 'rudel/' . $sandbox_id;
				if ( $this->create_worktree( $source_path, $target_path, $branch ) ) {
					$results['worktrees'][ $dir ] = $branch;
					continue;
				}
			}

			// Sandboxes still need to build on non-git installs or when worktrees are unavailable.
			$cloner = new ContentCloner();
			$cloner->copy_directory( $source_path, $target_path );
			$results['copied'][] = $dir;
		}

		return $results;
	}

	/**
	 * Check if a directory is a git repository.
	 *
	 * @param string $path Directory path.
	 * @return bool True if the directory has a .git directory or file.
	 */
	public function is_git_repo( string $path ): bool {
		return file_exists( $path . '/.git' );
	}

	/**
	 * Create a git worktree in the target path on a new branch.
	 *
	 * @param string $repo_path   Path to a git checkout or common git dir.
	 * @param string $target_path Path for the new worktree.
	 * @param string $branch      Branch name to create.
	 * @return bool True on success.
	 */
	public function create_worktree( string $repo_path, string $target_path, string $branch ): bool {
		$escaped_target = escapeshellarg( $target_path );
		$escaped_branch = escapeshellarg( $branch );
		$command        = $this->git_command_prefix( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: git worktree creation for sandbox isolation.
		exec(
			"{$command} worktree add -b {$escaped_branch} {$escaped_target} HEAD 2>&1",
			$output,
			$exit_code
		);

		return 0 === $exit_code;
	}

	/**
	 * Remove a git worktree.
	 *
	 * @param string $repo_path   Path to a git checkout or common git dir.
	 * @param string $target_path Path to the worktree to remove.
	 * @return bool True on success.
	 */
	public function remove_worktree( string $repo_path, string $target_path ): bool {
		$escaped_target = escapeshellarg( $target_path );
		$command        = $this->git_command_prefix( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: git worktree removal during sandbox cleanup.
		exec( "{$command} worktree remove {$escaped_target} --force 2>&1", $output, $exit_code );

		return 0 === $exit_code;
	}

	/**
	 * Check if a branch has been merged into a target branch.
	 *
	 * @param string $repo_path     Path to a git checkout or common git dir.
	 * @param string $branch        Branch to check.
	 * @param string $target_branch Branch to check against (default: main).
	 * @return bool True if merged.
	 */
	public function is_branch_merged( string $repo_path, string $branch, string $target_branch = 'main' ): bool {
		$escaped_target = escapeshellarg( $target_branch );
		$command        = $this->git_command_prefix( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: checking git branch merge status.
		exec( "{$command} branch --merged {$escaped_target} 2>&1", $output, $exit_code );

		if ( 0 !== $exit_code ) {
			return false;
		}

		foreach ( $output as $line ) {
			if ( trim( $line, " *\t" ) === $branch ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete a branch from a repository.
	 *
	 * @param string $repo_path Path to a git checkout or common git dir.
	 * @param string $branch    Branch name to delete.
	 * @return bool True on success.
	 */
	public function delete_branch( string $repo_path, string $branch ): bool {
		$escaped_branch = escapeshellarg( $branch );
		$command        = $this->git_command_prefix( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: git branch cleanup during sandbox destruction.
		exec( "{$command} branch -d {$escaped_branch} 2>&1", $output, $exit_code );

		return 0 === $exit_code;
	}

	/**
	 * Repository default branch.
	 *
	 * @param string $repo_path Path to a git checkout or common git dir.
	 * @return string Default branch name (falls back to 'main').
	 */
	public function get_default_branch( string $repo_path ): string {
		$command = $this->git_command_prefix( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: detecting default branch.
		exec( "{$command} symbolic-ref refs/remotes/origin/HEAD 2>&1", $output, $exit_code );

		if ( 0 === $exit_code && ! empty( $output[0] ) ) {
			return str_replace( 'refs/remotes/origin/', '', trim( $output[0] ) );
		}

		return 'main';
	}

	/**
	 * Resolve the common git dir for a checkout or worktree path.
	 *
	 * @param string $repo_path Path to a git checkout or common git dir.
	 * @return string|null
	 */
	public function common_git_dir( string $repo_path ): ?string {
		$repo_path = trim( $repo_path );
		if ( '' === $repo_path || ! is_dir( $repo_path ) ) {
			return null;
		}

		if ( file_exists( $repo_path . '/HEAD' ) && file_exists( $repo_path . '/config' ) ) {
			return $repo_path;
		}

		$escaped_repo = escapeshellarg( $repo_path );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Intentional: resolving the common git dir for worktree-aware cleanup.
		exec( "git -C {$escaped_repo} rev-parse --git-common-dir 2>&1", $output, $exit_code );

		if ( 0 !== $exit_code || empty( $output[0] ) ) {
			return null;
		}

		$common_dir = trim( $output[0] );
		if ( '' === $common_dir ) {
			return null;
		}

		if ( str_starts_with( $common_dir, '/' ) ) {
			return $common_dir;
		}

		$resolved = realpath( $repo_path . '/' . $common_dir );
		return false !== $resolved ? $resolved : $repo_path . '/' . $common_dir;
	}

	/**
	 * Git command prefix for a checkout or common git dir.
	 *
	 * @param string $repo_path Path to a git checkout or common git dir.
	 * @return string
	 */
	private function git_command_prefix( string $repo_path ): string {
		$common_dir = $this->common_git_dir( $repo_path );
		if ( is_string( $common_dir ) && '' !== $common_dir ) {
			return 'git --git-dir=' . escapeshellarg( $common_dir );
		}

		return 'git -C ' . escapeshellarg( $repo_path );
	}
}
