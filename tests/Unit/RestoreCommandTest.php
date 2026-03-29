<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;

class RestoreCommandTest extends RudelTestCase
{
    private function bootstrapWpCli(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();
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
    public function testRestoreConfirmsAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $environment = new Environment('restore-box', 'Restore Box', '/tmp/restore-box', '2026-01-01T00:00:00+00:00', engine: 'sqlite');
        $snapshotManager = new class extends SnapshotManager {
            public array $restoredNames = [];

            public function __construct() {}

            public function restore(string $name): void
            {
                $this->restoredNames[] = $name;
            }
        };

        $cmd = new class($this->environmentManager($environment), $snapshotManager) extends \Rudel\CLI\RestoreCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private SnapshotManager $snapshotManager)
            {
                parent::__construct($manager);
            }

            protected function snapshot_manager(\Rudel\Environment $sandbox): SnapshotManager
            {
                return $this->snapshotManager;
            }
        };

        $cmd(['restore-box'], ['snapshot' => 'before-update']);

        $this->assertSame(['before-update'], $snapshotManager->restoredNames);
        $this->assertNotEmpty(\WP_CLI::$confirmations);
        $this->assertSame(['Sandbox restored from snapshot: before-update'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestoreWithForceSkipsConfirmation(): void
    {
        $this->bootstrapWpCli();

        $environment = new Environment('restore-box', 'Restore Box', '/tmp/restore-box', '2026-01-01T00:00:00+00:00', engine: 'sqlite');
        $snapshotManager = new class extends SnapshotManager {
            public function __construct() {}

            public function restore(string $name): void {}
        };

        $cmd = new class($this->environmentManager($environment), $snapshotManager) extends \Rudel\CLI\RestoreCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private SnapshotManager $snapshotManager)
            {
                parent::__construct($manager);
            }

            protected function snapshot_manager(\Rudel\Environment $sandbox): SnapshotManager
            {
                return $this->snapshotManager;
            }
        };

        $cmd(['restore-box'], ['snapshot' => 'before-update', 'force' => true]);

        $this->assertSame([], \WP_CLI::$confirmations);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestoreErrorsThroughWpCli(): void
    {
        $this->bootstrapWpCli();

        $environment = new Environment('restore-box', 'Restore Box', '/tmp/restore-box', '2026-01-01T00:00:00+00:00', engine: 'sqlite');
        $snapshotManager = new class extends SnapshotManager {
            public function __construct() {}

            public function restore(string $name): void
            {
                throw new \RuntimeException('restore failed');
            }
        };

        $cmd = new class($this->environmentManager($environment), $snapshotManager) extends \Rudel\CLI\RestoreCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private SnapshotManager $snapshotManager)
            {
                parent::__construct($manager);
            }

            protected function snapshot_manager(\Rudel\Environment $sandbox): SnapshotManager
            {
                return $this->snapshotManager;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('restore failed');
        $cmd(['restore-box'], ['snapshot' => 'before-update', 'force' => true]);
    }
}
