<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\GitHubIntegration;
use Rudel\Tests\RudelTestCase;

class PrCommandTest extends RudelTestCase
{
    private function bootstrapWpCli(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();
    }

    private function createEnvironment(string $id, ?array $cloneSource = null): Environment
    {
        $path = $this->createFakeSandbox($id, 'PR Box', array_filter([
            'clone_source' => $cloneSource,
        ], static fn($value) => null !== $value));

        return \Rudel\Environment::from_path($path);
    }

    private function environmentManager(Environment $environment): \Rudel\EnvironmentManager
    {
        return new class($environment) extends \Rudel\EnvironmentManager {
            public function __construct(private Environment $environment) {}

            public function get(string $id): ?Environment
            {
                return $id === $this->environment->id ? $this->environment : null;
            }
        };
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrRequiresGitHubRepo(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('pr-box');
        $cmd = new \Rudel\CLI\PrCommand($this->environmentManager($environment));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub repo required');
        $cmd(['pr-box'], []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrUsesSandboxDefaultsAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('pr-box', ['github_repo' => 'owner/repo']);
        $fakeGithub = new class extends GitHubIntegration {
            public array $prCalls = [];

            public function __construct() {}

            public function create_pr(string $branch, string $title, string $body = '', ?string $base_branch = null): array
            {
                $this->prCalls[] = compact('branch', 'title', 'body', 'base_branch');

                return [
                    'number' => 7,
                    'url' => 'https://api.github.test/repos/owner/repo/pulls/7',
                    'html_url' => 'https://github.test/owner/repo/pull/7',
                ];
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGithub) extends \Rudel\CLI\PrCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitHubIntegration $github)
            {
                parent::__construct($manager);
            }

            protected function github(string $repo): GitHubIntegration
            {
                return $this->github;
            }
        };

        $cmd(['pr-box'], []);

        $this->assertSame('rudel/pr-box', $fakeGithub->prCalls[0]['branch']);
        $this->assertSame('PR Box', $fakeGithub->prCalls[0]['title']);
        $this->assertSame('Created from Rudel sandbox `pr-box`', $fakeGithub->prCalls[0]['body']);
        $this->assertNull($fakeGithub->prCalls[0]['base_branch']);
        $this->assertSame(['PR #7 created: https://github.test/owner/repo/pull/7'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrUsesTrackedBaseBranchFromAppDerivedSandbox(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('pr-box', [
            'github_repo' => 'owner/repo',
            'github_base_branch' => 'release/2026',
        ]);
        $fakeGithub = new class extends GitHubIntegration {
            public array $prCalls = [];

            public function __construct() {}

            public function create_pr(string $branch, string $title, string $body = '', ?string $base_branch = null): array
            {
                $this->prCalls[] = compact('branch', 'title', 'body', 'base_branch');

                return [
                    'number' => 9,
                    'url' => 'https://api.github.test/repos/owner/repo/pulls/9',
                    'html_url' => 'https://github.test/owner/repo/pull/9',
                ];
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGithub) extends \Rudel\CLI\PrCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitHubIntegration $github)
            {
                parent::__construct($manager);
            }

            protected function github(string $repo): GitHubIntegration
            {
                return $this->github;
            }
        };

        $cmd(['pr-box'], []);

        $this->assertSame('release/2026', $fakeGithub->prCalls[0]['base_branch']);
        $this->assertSame(['PR #9 created: https://github.test/owner/repo/pull/9'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPrAcceptsCustomTitleAndBody(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('pr-box', ['github_repo' => 'owner/repo']);
        $fakeGithub = new class extends GitHubIntegration {
            public array $prCalls = [];

            public function __construct() {}

            public function create_pr(string $branch, string $title, string $body = '', ?string $base_branch = null): array
            {
                $this->prCalls[] = compact('branch', 'title', 'body', 'base_branch');

                return [
                    'number' => 8,
                    'url' => 'https://api.github.test/repos/owner/repo/pulls/8',
                    'html_url' => 'https://github.test/owner/repo/pull/8',
                ];
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGithub) extends \Rudel\CLI\PrCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitHubIntegration $github)
            {
                parent::__construct($manager);
            }

            protected function github(string $repo): GitHubIntegration
            {
                return $this->github;
            }
        };

        $cmd(['pr-box'], ['title' => 'Custom PR', 'body' => 'Body text']);

        $this->assertSame('Custom PR', $fakeGithub->prCalls[0]['title']);
        $this->assertSame('Body text', $fakeGithub->prCalls[0]['body']);
    }
}
