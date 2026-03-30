<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\GitHubIntegration;
use Rudel\Tests\RudelTestCase;

class PushCommandTest extends RudelTestCase
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
        $path = $this->createFakeSandbox($id, 'Push Box', array_filter([
            'clone_source' => $cloneSource,
        ], static fn($value) => null !== $value));

        file_put_contents($path . '/wp-content/index.php', '<?php // root');
        mkdir($path . '/wp-content/themes/my-theme', 0755, true);
        file_put_contents($path . '/wp-content/themes/my-theme/style.css', '/* theme */');

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
    public function testPushRequiresGitHubRepo(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box');
        $cmd = new \Rudel\CLI\PushCommand($this->environmentManager($environment));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub repo required');
        $cmd(['push-box'], []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushStoresRepoAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box');
        $fakeGithub = new class extends GitHubIntegration {
            public array $branchCalls = [];
            public array $pushCalls = [];

            public function __construct() {}

            public function create_branch(string $branch, ?string $base_branch = null): void
            {
                $this->branchCalls[] = [
                    'branch' => $branch,
                    'base_branch' => $base_branch,
                ];
            }

            public function push(string $branch, string $local_dir, string $message): ?string
            {
                $this->pushCalls[] = [
                    'branch' => $branch,
                    'local_dir' => $local_dir,
                    'message' => $message,
                ];

                return 'abc1234';
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGithub) extends \Rudel\CLI\PushCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitHubIntegration $github)
            {
                parent::__construct($manager);
            }

            protected function github(string $repo): GitHubIntegration
            {
                return $this->github;
            }
        };

        $cmd(['push-box'], ['github' => 'owner/repo', 'dir' => 'themes/my-theme', 'message' => 'Ship it']);

        $this->assertSame([[
            'branch' => 'rudel/push-box',
            'base_branch' => null,
        ]], $fakeGithub->branchCalls);
        $this->assertSame('rudel/push-box', $fakeGithub->pushCalls[0]['branch']);
        $this->assertSame($environment->get_wp_content_path() . '/themes/my-theme', $fakeGithub->pushCalls[0]['local_dir']);
        $this->assertSame('Ship it', $fakeGithub->pushCalls[0]['message']);
        $this->assertSame(['Pushed to rudel/push-box (abc1234)'], \WP_CLI::$successes);

        $meta = json_decode(file_get_contents($environment->path . '/.rudel.json'), true);
        $this->assertSame('owner/repo', $meta['clone_source']['github_repo']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushLogsWhenThereAreNoChanges(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box', ['github_repo' => 'owner/repo']);
        $fakeGithub = new class extends GitHubIntegration {
            public function __construct() {}

            public function create_branch(string $branch, ?string $base_branch = null): void
            {
                throw new \RuntimeException('Reference already exists');
            }

            public function push(string $branch, string $local_dir, string $message): ?string
            {
                return null;
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGithub) extends \Rudel\CLI\PushCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitHubIntegration $github)
            {
                parent::__construct($manager);
            }

            protected function github(string $repo): GitHubIntegration
            {
                return $this->github;
            }
        };

        $cmd(['push-box'], []);

        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('Branch already exists.', $logOutput);
        $this->assertStringContainsString('No changes to push.', $logOutput);
        $this->assertSame([], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushErrorsWhenSubdirectoryIsMissing(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box', ['github_repo' => 'owner/repo']);
        $cmd = new \Rudel\CLI\PushCommand($this->environmentManager($environment));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory not found');
        $cmd(['push-box'], ['dir' => 'themes/missing']);
    }
}
