<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\TemplateManager;
use Rudel\Tests\RudelTestCase;

class TemplateCommandTest extends RudelTestCase
{
    private function bootstrapWpCli(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();
    }

    private function createEnvironment(string $id): Environment
    {
        $path = $this->createFakeSandbox($id, 'Template Box');
        return \Rudel\Environment::from_path($path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListFormatsTemplates(): void
    {
        $this->bootstrapWpCli();

        $templateManager = new class extends TemplateManager {
            public function __construct() {}

            public function list_templates(): array
            {
                return [[
                    'name' => 'starter',
                    'description' => 'Starter template',
                    'source_sandbox_id' => 'box-a1b2',
                    'created_at' => '2026-01-01T00:00:00+00:00',
                ]];
            }
        };

        $cmd = new \Rudel\CLI\TemplateCommand($templateManager, new \Rudel\EnvironmentManager($this->tmpDir));
        $cmd->list_([], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $this->assertCount(1, $formatCalls);
        $call = array_values($formatCalls)[0];
        $this->assertSame(['name', 'description', 'source_sandbox_id', 'created_at'], $call['fields']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveUsesSandboxAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $environment = $this->createEnvironment('template-box');
        $templateManager = new class extends TemplateManager {
            public array $saveCall = [];

            public function __construct() {}

            public function save(Environment $sandbox, string $name, string $description = ''): array
            {
                $this->saveCall = [$sandbox->id, $name, $description];
                return ['name' => $name];
            }
        };
        $sandboxManager = new class($environment) extends \Rudel\EnvironmentManager {
            public function __construct(private Environment $environment) {}

            public function get(string $id): ?Environment
            {
                return $id === $this->environment->id ? $this->environment : null;
            }
        };

        $cmd = new \Rudel\CLI\TemplateCommand($templateManager, $sandboxManager);
        $cmd->save(['template-box'], ['name' => 'starter', 'description' => 'Starter']);

        $this->assertSame(['template-box', 'starter', 'Starter'], $templateManager->saveCall);
        $this->assertSame(['Template saved: starter'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSaveErrorsWhenSandboxIsMissing(): void
    {
        $this->bootstrapWpCli();

        $sandboxManager = new class extends \Rudel\EnvironmentManager {
            public function __construct() {}

            public function get(string $id): ?Environment
            {
                return null;
            }
        };

        $cmd = new \Rudel\CLI\TemplateCommand(new TemplateManager($this->tmpDir . '/templates'), $sandboxManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found: missing-box');
        $cmd->save(['missing-box'], ['name' => 'starter']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteConfirmsAndReportsSuccess(): void
    {
        $this->bootstrapWpCli();

        $templateManager = new class extends TemplateManager {
            public array $deleted = [];

            public function __construct() {}

            public function delete(string $name): bool
            {
                $this->deleted[] = $name;
                return true;
            }
        };

        $cmd = new \Rudel\CLI\TemplateCommand($templateManager, new \Rudel\EnvironmentManager($this->tmpDir));
        $cmd->delete(['starter'], []);

        $this->assertSame(['starter'], $templateManager->deleted);
        $this->assertNotEmpty(\WP_CLI::$confirmations);
        $this->assertSame(['Template deleted: starter'], \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteErrorsWhenTemplateIsMissing(): void
    {
        $this->bootstrapWpCli();

        $templateManager = new class extends TemplateManager {
            public function __construct() {}

            public function delete(string $name): bool
            {
                return false;
            }
        };

        $cmd = new \Rudel\CLI\TemplateCommand($templateManager, new \Rudel\EnvironmentManager($this->tmpDir));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found: starter');
        $cmd->delete(['starter'], ['force' => true]);
    }
}
