<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;

class SnapshotCommandTest extends RudelTestCase
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
    public function testSnapshotReportsCreatedSnapshot(): void
    {
        $this->bootstrapWpCli();

        $environment = new Environment('snap-box', 'Snapshot Box', '/tmp/snap-box', '2026-01-01T00:00:00+00:00', engine: 'sqlite');
        $snapshotManager = new class extends SnapshotManager {
            public array $createdNames = [];

            public function __construct() {}

            public function create(string $name): array
            {
                $this->createdNames[] = $name;

                return [
                    'name' => $name,
                    'created_at' => '2026-01-01T00:00:00+00:00',
                    'sandbox_id' => 'snap-box',
                ];
            }
        };

        $cmd = new class($this->environmentManager($environment), $snapshotManager) extends \Rudel\CLI\SnapshotCommand {
            public function __construct(\Rudel\EnvironmentManager $manager, private SnapshotManager $snapshotManager)
            {
                parent::__construct($manager);
            }

            protected function snapshot_manager(\Rudel\Environment $sandbox): SnapshotManager
            {
                return $this->snapshotManager;
            }
        };

        $cmd(['snap-box'], ['name' => 'before-update']);

        $this->assertSame(['before-update'], $snapshotManager->createdNames);
        $this->assertSame(['Snapshot created: before-update'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSnapshotErrorsThroughWpCli(): void
    {
        $this->bootstrapWpCli();

        $environment = new Environment('snap-box', 'Snapshot Box', '/tmp/snap-box', '2026-01-01T00:00:00+00:00', engine: 'sqlite');
        $snapshotManager = new class extends SnapshotManager {
            public function __construct() {}

            public function create(string $name): array
            {
                throw new \RuntimeException('snapshot failed');
            }
        };

        $cmd = new class($this->environmentManager($environment), $snapshotManager) extends \Rudel\CLI\SnapshotCommand {
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
        $this->expectExceptionMessage('snapshot failed');
        $cmd(['snap-box'], ['name' => 'before-update']);
    }
}
