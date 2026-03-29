<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\Tests\RudelTestCase;

class ArchiveCommandTest extends RudelTestCase
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
    public function testExportCallsManagerAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public array $exportCall = [];

            public function __construct() {}

            public function export(string $id, string $output_path): void
            {
                $this->exportCall = [$id, $output_path];
            }
        };

        $cmd = new \Rudel\CLI\ExportCommand($manager);
        $cmd(['box-1234'], ['output' => '/tmp/sandbox.zip']);

        $this->assertSame(['box-1234', '/tmp/sandbox.zip'], $manager->exportCall);
        $this->assertSame(['Sandbox exported to /tmp/sandbox.zip'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExportErrorsThroughWpCli(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public function __construct() {}

            public function export(string $id, string $output_path): void
            {
                throw new \RuntimeException('zip failed');
            }
        };

        $cmd = new \Rudel\CLI\ExportCommand($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zip failed');
        $cmd(['box-1234'], ['output' => '/tmp/sandbox.zip']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportCallsManagerAndLogsPath(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public array $importCall = [];

            public function __construct() {}

            public function import(string $zip_path, string $name): Environment
            {
                $this->importCall = [$zip_path, $name];

                return new Environment(
                    id: 'imported-a1b2',
                    name: $name,
                    path: '/tmp/imported-a1b2',
                    created_at: '2026-01-01T00:00:00+00:00',
                    engine: 'sqlite'
                );
            }
        };

        $cmd = new \Rudel\CLI\ImportCommand($manager);
        $cmd(['/tmp/sandbox.zip'], ['name' => 'Imported Sandbox']);

        $this->assertSame(['/tmp/sandbox.zip', 'Imported Sandbox'], $manager->importCall);
        $this->assertSame(['Sandbox imported: imported-a1b2'], \WP_CLI::$successes);
        $this->assertContains('  Path: /tmp/imported-a1b2', \WP_CLI::$log);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportErrorsThroughWpCli(): void
    {
        $this->bootstrapWpCli();

        $manager = new class extends \Rudel\EnvironmentManager {
            public function __construct() {}

            public function import(string $zip_path, string $name): Environment
            {
                throw new \RuntimeException('invalid archive');
            }
        };

        $cmd = new \Rudel\CLI\ImportCommand($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid archive');
        $cmd(['/tmp/sandbox.zip'], ['name' => 'Imported Sandbox']);
    }
}
