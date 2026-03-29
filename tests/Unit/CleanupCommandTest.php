<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class CleanupCommandTest extends RudelTestCase
{
    private function bootstrapWpCli(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupDryRunPassesOptionsAndLogsResults(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public array $cleanupOptions = [];

            public function __construct() {}

            public function cleanup(array $options = array()): array
            {
                $this->cleanupOptions = $options;

                return [
                    'removed' => ['old-a', 'old-b'],
                    'skipped' => [],
                    'errors' => ['failed-c'],
                ];
            }
        };

        $cmd = new \Rudel\CLI\CleanupCommand($manager);
        $cmd([], ['dry-run' => true, 'max-age-days' => '7']);

        $this->assertSame(['dry_run' => true, 'max_age_days' => 7, 'max_idle_days' => 0], $manager->cleanupOptions);
        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('Would remove 2 sandbox(es)', $logOutput);
        $this->assertStringContainsString('old-a', $logOutput);
        $this->assertStringContainsString('old-b', $logOutput);
        $this->assertContains('Failed to remove: failed-c', \WP_CLI::$warnings);
        $this->assertSame([], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupMergedUsesMergedCleanupPath(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public bool $cleanupMergedCalled = false;
            public array $cleanupMergedOptions = [];

            public function __construct() {}

            public function cleanup_merged(array $options = array()): array
            {
                $this->cleanupMergedCalled = true;
                $this->cleanupMergedOptions = $options;

                return [
                    'removed' => ['merged-box'],
                    'skipped' => [],
                    'errors' => [],
                ];
            }
        };

        $cmd = new \Rudel\CLI\CleanupCommand($manager);
        $cmd([], ['merged' => true]);

        $this->assertTrue($manager->cleanupMergedCalled);
        $this->assertSame(['dry_run' => false], $manager->cleanupMergedOptions);
        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString('with merged branches', \WP_CLI::$successes[0]);
        $this->assertStringContainsString('merged-box', implode("\n", array_filter(\WP_CLI::$log, 'is_string')));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupPassesIdleOverrideAndLogsReasons(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public array $cleanupOptions = [];

            public function __construct() {}

            public function cleanup(array $options = array()): array
            {
                $this->cleanupOptions = $options;

                return [
                    'removed' => ['idle-box'],
                    'skipped' => [],
                    'errors' => [],
                    'reasons' => ['idle-box' => 'idle'],
                ];
            }
        };

        $cmd = new \Rudel\CLI\CleanupCommand($manager);
        $cmd([], ['dry-run' => true, 'max-idle-days' => '3']);

        $this->assertSame(['dry_run' => true, 'max_age_days' => 0, 'max_idle_days' => 3], $manager->cleanupOptions);
        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('idle policy matched', $logOutput);
    }
}
