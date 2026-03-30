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
	 * Constructor.
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

			$branch      = $environment->get_git_branch();
			$github_repo = $environment->get_github_repo();
			$worktrees   = $environment->clone_source['git_worktrees'] ?? array();
			$has_git     = ! empty( $worktrees );
			$has_github  = ! empty( $github_repo );

			if ( ! $has_git && ! $has_github ) {
				$result['skipped'][] = $environment->id;
				continue;
			}

			$is_merged = false;

			if ( $has_github ) {
				try {
					$is_merged = ( new GitHubIntegration( $github_repo ) )->is_branch_merged( $branch );
				} catch ( \RuntimeException $e ) {
					$is_merged = false;
					unset( $e );
				}
			}

			if ( ! $is_merged && $has_git ) {
				$is_merged_locally = true;
				foreach ( $worktrees as $worktree ) {
					$default_branch = $git->get_default_branch( $worktree['repo'] );
					if ( ! $git->is_branch_merged( $worktree['repo'], $worktree['branch'], $default_branch ) ) {
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
				$worktree_path = $environment->get_wp_content_path() . '/' . $worktree['type'] . '/' . $worktree['name'];
				$git->remove_worktree( $worktree['repo'], $worktree_path );
				$git->delete_branch( $worktree['repo'], $worktree['branch'] );
			}

			if ( $has_github ) {
				try {
					( new GitHubIntegration( $github_repo ) )->delete_branch( $branch );
				} catch ( \RuntimeException $e ) {
					unset( $e );
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
