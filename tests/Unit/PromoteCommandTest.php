<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class PromoteCommandTest extends RudelTestCase
{
    private function bootstrapWpCli(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();
    }

    private function createCommand(EnvironmentManager $manager): \Rudel\CLI\PromoteCommand
    {
        return new \Rudel\CLI\PromoteCommand($manager);
    }

    private function createEnvironment(string $id = 'box-1234', string $engine = 'sqlite'): Environment
    {
        return new Environment(
            id: $id,
            name: 'Promote Me',
            path: $this->tmpDir . '/' . $id,
            created_at: '2026-03-29T00:00:00+00:00',
            engine: $engine
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteNonexistentSandboxCallsError(): void
    {
        $this->bootstrapWpCli();
        $cmd = $this->createCommand(new \Rudel\EnvironmentManager($this->tmpDir));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found');
        $cmd(['nonexistent'], ['force' => true]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteUsesDefaultBackupDirectoryWhenNotProvided(): void
    {
        $this->bootstrapWpCli();
        $environment = $this->createEnvironment();

        $manager = new class($this->tmpDir . '/rudel-environments', $environment) extends EnvironmentManager {
            public array $promoteCall = [];

            public function __construct(
                private string $environmentsDir,
                private Environment $environment
            ) {}

            public function get(string $id): ?Environment
            {
                return $id === $this->environment->id ? $this->environment : null;
            }

            public function get_environments_dir(): string
            {
                return $this->environmentsDir;
            }

            public function promote(string $id, string $backup_dir): array
            {
                $this->promoteCall = [$id, $backup_dir];

                return [
                    'backup_path' => $backup_dir,
                    'backup_prefix' => 'rudel_backup_20260329_000000_',
                    'tables_copied' => 7,
                ];
            }
        };

        $cmd = $this->createCommand($manager);
        $cmd([$environment->id], ['force' => true]);

        $this->assertCount(2, $manager->promoteCall);
        $this->assertSame($environment->id, $manager->promoteCall[0]);
        $this->assertMatchesRegularExpression(
            '#^' . preg_quote($this->tmpDir . '/rudel-environments/_backups/', '#') . '\d{8}_\d{6}$#',
            $manager->promoteCall[1]
        );
        $this->assertSame([], \WP_CLI::$warnings);
        $this->assertSame([], \WP_CLI::$confirmations);
        $this->assertContains('Backing up host...', \WP_CLI::$log);
        $this->assertContains('Sandbox promoted to host.', \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteWarnsAndConfirmsBeforeRunningWithoutForce(): void
    {
        $this->bootstrapWpCli();
        $environment = $this->createEnvironment();

        $manager = new class($environment) extends EnvironmentManager {
            public int $promoteCalls = 0;

            public function __construct(private Environment $environment) {}

            public function get(string $id): ?Environment
            {
                return $id === $this->environment->id ? $this->environment : null;
            }

            public function get_environments_dir(): string
            {
                return '/tmp/rudel-environments';
            }

            public function promote(string $id, string $backup_dir): array
            {
                ++$this->promoteCalls;

                return [
                    'backup_path' => $backup_dir,
                    'backup_prefix' => 'rudel_backup_20260329_000000_',
                    'tables_copied' => 3,
                ];
            }
        };

        $cmd = $this->createCommand($manager);
        $cmd([$environment->id], []);

        $this->assertSame(1, $manager->promoteCalls);
        $this->assertSame(['This will replace the host site with the sandbox\'s state.'], \WP_CLI::$warnings);
        $this->assertSame(['Are you sure?'], \WP_CLI::$confirmations);
        $this->assertContains('A backup of the current host will be created before proceeding.', \WP_CLI::$log);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteRejectsSubsiteSandboxesBeforeManagerPromotion(): void
    {
        $this->bootstrapWpCli();
        $environment = $this->createEnvironment('subsite-box', 'subsite');

        $manager = new class($environment) extends EnvironmentManager {
            public int $promoteCalls = 0;

            public function __construct(private Environment $environment) {}

            public function get(string $id): ?Environment
            {
                return $id === $this->environment->id ? $this->environment : null;
            }

            public function promote(string $id, string $backup_dir): array
            {
                ++$this->promoteCalls;
                return [];
            }
        };

        $cmd = $this->createCommand($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Promote is not supported for subsite-engine sandboxes.');

        try {
            $cmd([$environment->id], ['force' => true]);
        } finally {
            $this->assertSame(0, $manager->promoteCalls);
        }
    }
}
