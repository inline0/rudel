<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\GitIntegration;
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

            public function update(string $id, array $changes): Environment
            {
                $repository = new \Rudel\EnvironmentRepository(
                    \Rudel\RudelDatabase::for_paths(dirname($this->environment->path)),
                    dirname($this->environment->path),
                    $this->environment->type
                );

                $this->environment = $repository->update_fields($id, $changes, $this->environment->type);

                return $this->environment;
            }
        };
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushRequiresGitRemote(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box');
        $cmd = new \Rudel\CLI\PushCommand($this->environmentManager($environment));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git remote required');
        $cmd(['push-box'], []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushStoresRemoteAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box');
        $fakeGit = new class extends GitIntegration {
            public array $pushCalls = [];

            public function push_checkout(string $repo_path, string $branch, string $message, ?string $remote_url = null): ?string
            {
                $this->pushCalls[] = [
                    'repo_path' => $repo_path,
                    'branch' => $branch,
                    'message' => $message,
                    'remote_url' => $remote_url,
                ];

                return 'abc1234';
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGit) extends \Rudel\CLI\PushCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitIntegration $git)
            {
                parent::__construct($manager);
            }

            protected function git(): GitIntegration
            {
                return $this->git;
            }
        };

        $cmd(['push-box'], ['git' => 'https://example.test/owner/repo.git', 'dir' => 'themes/my-theme', 'message' => 'Ship it']);

        $this->assertSame([[
            'repo_path' => $environment->get_wp_content_path() . '/themes/my-theme',
            'branch' => 'rudel/push-box',
            'message' => 'Ship it',
            'remote_url' => 'https://example.test/owner/repo.git',
        ]], $fakeGit->pushCalls);
        $this->assertSame(['Pushed to rudel/push-box (abc1234)'], \WP_CLI::$successes);

        $updated = \Rudel\Environment::from_path($environment->path);
        $this->assertNotNull($updated);
        $this->assertSame('https://example.test/owner/repo.git', $updated->clone_source['git_remote']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushUsesTrackedGitRemoteAndDirectoryByDefault(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box', [
            'git_remote' => 'https://example.test/owner/repo.git',
            'git_base_branch' => 'release/2026',
            'git_dir' => 'themes/my-theme',
        ]);
        $fakeGit = new class extends GitIntegration {
            public array $pushCalls = [];

            public function push_checkout(string $repo_path, string $branch, string $message, ?string $remote_url = null): ?string
            {
                $this->pushCalls[] = [
                    'repo_path' => $repo_path,
                    'branch' => $branch,
                    'message' => $message,
                    'remote_url' => $remote_url,
                ];

                return 'def5678';
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGit) extends \Rudel\CLI\PushCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitIntegration $git)
            {
                parent::__construct($manager);
            }

            protected function git(): GitIntegration
            {
                return $this->git;
            }
        };

        $cmd(['push-box'], []);

        $this->assertSame([[
            'repo_path' => $environment->get_wp_content_path() . '/themes/my-theme',
            'branch' => 'rudel/push-box',
            'message' => 'Update from Rudel sandbox',
            'remote_url' => 'https://example.test/owner/repo.git',
        ]], $fakeGit->pushCalls);
        $this->assertSame(['Pushed to rudel/push-box (def5678)'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushLogsWhenThereAreNoChanges(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box', ['git_remote' => 'https://example.test/owner/repo.git']);
        $fakeGit = new class extends GitIntegration {
            public function push_checkout(string $repo_path, string $branch, string $message, ?string $remote_url = null): ?string
            {
                return null;
            }
        };

        $cmd = new class($this->environmentManager($environment), $fakeGit) extends \Rudel\CLI\PushCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private GitIntegration $git)
            {
                parent::__construct($manager);
            }

            protected function git(): GitIntegration
            {
                return $this->git;
            }
        };

        $cmd(['push-box'], []);

        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('Pushing rudel/push-box...', $logOutput);
        $this->assertStringContainsString('No changes to push.', $logOutput);
        $this->assertSame([], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPushErrorsWhenSubdirectoryIsMissing(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('push-box', ['git_remote' => 'https://example.test/owner/repo.git']);
        $cmd = new \Rudel\CLI\PushCommand($this->environmentManager($environment));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Directory not found');
        $cmd(['push-box'], ['dir' => 'themes/missing']);
    }
}
