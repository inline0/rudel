<?php
/**
 * Environment cleanup orchestration.
 *
 * @package Rudel
 */

namespace Rudel;

/**
 * Keeps cleanup policies separate from the broader environment lifecycle so retention rules stay easier to evolve.
 */
class EnvironmentCleanupService {

	/**
	 * Environment repository.
	 *
	 * @var EnvironmentRepository
	 */
	private EnvironmentRepository $repository;

	/**
	 * Destroy callback used after cleanup policy selects an environment.
	 *
	 * @var \Closure
	 */
	private \Closure $destroyer;

	/**
	 * Initialize dependencies.
	 *
	 * @param EnvironmentRepository $repository Environment repository.
	 * @param callable              $destroyer  Destroy callback accepting an environment ID.
	 */
	public function __construct( EnvironmentRepository $repository, callable $destroyer ) {
		$this->repository = $repository;
		$this->destroyer  = \Closure::fromCallable( $destroyer );
	}

	/**
	 * Clean up expired or stale environments.
	 *
	 * @param array $options Options: dry_run, max_age_days, max_idle_days.
	 * @return array{removed: string[], skipped: string[], errors: string[], reasons: array<string, string>}
	 */
	public function cleanup( array $options = array() ): array {
		$options = Hooks::filter( 'rudel_environment_cleanup_options', $options, $this->repository );
		Hooks::action( 'rudel_before_environment_cleanup', $options );

		$dry_run       = ! empty( $options['dry_run'] );
		$max_age_days  = $options['max_age_days'] ?? 0;
		$max_idle_days = $options['max_idle_days'] ?? 0;
		$config        = new RudelConfig();

		if ( 0 === $max_age_days ) {
			$max_age_days = $config->get( 'max_age_days' );
		}

		if ( 0 === $max_idle_days ) {
			$max_idle_days = $config->get( 'max_idle_days' );
		}

		$result = array(
			'removed' => array(),
			'skipped' => array(),
			'errors'  => array(),
			'reasons' => array(),
		);

		$environments = $this->repository->all();

		if ( $max_age_days <= 0 && $max_idle_days <= 0 ) {
			$has_explicit_expiry = false;
			foreach ( $environments as $environment ) {
				if ( null !== $environment->expires_at ) {
					$has_explicit_expiry = true;
					break;
				}
			}

			if ( ! $has_explicit_expiry ) {
				Hooks::action( 'rudel_after_environment_cleanup', $result, $options );
				return $result;
			}
		}

		$now = time();

		foreach ( $environments as $environment ) {
			if ( $environment->is_protected() ) {
				$result['skipped'][]                   = $environment->id;
				$result['reasons'][ $environment->id ] = 'protected';
				continue;
			}

			$reason = EnvironmentPolicy::cleanup_reason( $environment, $now, $max_age_days, $max_idle_days );
			if ( null === $reason ) {
				$result['skipped'][] = $environment->id;
				continue;
			}

			$result['reasons'][ $environment->id ] = $reason;

			if ( $dry_run ) {
				$result['removed'][] = $environment->id;
				continue;
			}

			if ( ( $this->destroyer )( $environment->id ) ) {
				$result['removed'][] = $environment->id;
			} else {
				$result['errors'][] = $environment->id;
			}
		}

		Hooks::action( 'rudel_after_environment_cleanup', $result, $options );

		return $result;
	}

	/**
	 * Clean up environments whose Git branches have already landed.
	 *
	 * @param array $options Options: dry_run.
	 * @return array{removed: string[], skipped: string[], errors: string[], reasons: array<string, string>}
	 */
	public function cleanup_merged( array $options = array() ): array {
		$options = Hooks::filter( 'rudel_environment_cleanup_merged_options', $options, $this->repository );
		Hooks::action( 'rudel_before_environment_cleanup_merged', $options );

		$dry_run = ! empty( $options['dry_run'] );
		$git     = new GitIntegration();
		$result  = array(
			'removed' => array(),
			'skipped' => array(),
			'errors'  => array(),
			'reasons' => array(),
		);

		foreach ( $this->repository->all() as $environment ) {
			if ( $environment->is_protected() ) {
				$result['skipped'][]                   = $environment->id;
				$result['reasons'][ $environment->id ] = 'protected';
				continue;
			}

			$branch     = $environment->get_git_branch();
			$git_remote = $environment->get_git_remote();
			$worktrees  = $environment->clone_source['git_worktrees'] ?? array();
			$has_git    = ! empty( $worktrees );
			$has_remote = ! empty( $git_remote );

			if ( ! $has_git && ! $has_remote ) {
				$result['skipped'][] = $environment->id;
				continue;
			}

			$is_merged = false;

			if ( $has_git ) {
				$is_merged_locally = true;
				foreach ( $worktrees as $worktree ) {
					$repo_control   = $git->common_git_dir( $worktree['repo'] ) ?? $worktree['repo'];
					$default_branch = $git->get_default_branch( $repo_control );
					if ( ! $git->is_branch_merged( $repo_control, $worktree['branch'], $default_branch ) ) {
						$is_merged_locally = false;
						break;
					}
				}
				$is_merged = $is_merged_locally;
			}

			if ( ! $is_merged ) {
				$result['skipped'][] = $environment->id;
				continue;
			}

			$result['reasons'][ $environment->id ] = 'merged';

			if ( $dry_run ) {
				$result['removed'][] = $environment->id;
				continue;
			}

			foreach ( $worktrees as $worktree ) {
				$repo_control  = $git->common_git_dir( $worktree['repo'] ) ?? $worktree['repo'];
				$worktree_path = $environment->get_wp_content_path() . '/' . $worktree['type'] . '/' . $worktree['name'];
				$metadata_name = isset( $worktree['metadata_name'] ) ? trim( (string) $worktree['metadata_name'] ) : null;
				$git->remove_worktree( $repo_control, $worktree_path, '' !== (string) $metadata_name ? $metadata_name : null );
				$git->delete_branch( $repo_control, $worktree['branch'] );
			}

			if ( $has_remote && $has_git ) {
				$first_repo = $worktrees[ array_key_first( $worktrees ) ]['repo'] ?? null;
				if ( is_string( $first_repo ) && '' !== $first_repo ) {
					$repo_control = $git->common_git_dir( $first_repo ) ?? $first_repo;
					$git->delete_remote_branch( $repo_control, $branch );
				}
			}

			if ( ( $this->destroyer )( $environment->id ) ) {
				$result['removed'][] = $environment->id;
			} else {
				$result['errors'][] = $environment->id;
			}
		}

		Hooks::action( 'rudel_after_environment_cleanup_merged', $result, $options );

		return $result;
	}
}
