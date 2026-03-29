<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class AppCommandTest extends RudelTestCase
{
    private function createCommand(): \Rudel\CLI\AppCommand
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }

        \WP_CLI::reset();

        $manager = new \Rudel\AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        return new \Rudel\CLI\AppCommand($manager);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOutputsSuccessWithAppId(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'client-a.com', 'engine' => 'sqlite']);

        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString('App created:', \WP_CLI::$successes[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListWithAppsCallsFormatItems(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'list-test.com', 'engine' => 'sqlite']);
        \WP_CLI::reset();

        $cmd->list_([], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $this->assertCount(1, $formatCalls);

        $call = array_values($formatCalls)[0];
        $this->assertSame(['id', 'name', 'domains', 'engine', 'status', 'created'], $call['fields']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListEmptyLogsNoAppsFound(): void
    {
        $cmd = $this->createCommand();

        $cmd->list_([], []);

        $logMessages = array_filter(\WP_CLI::$log, fn($m) => is_string($m));
        $this->assertContains('No apps found.', $logMessages);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInfoNonexistentCallsError(): void
    {
        $cmd = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App not found: ghost-app');
        $cmd->info(['ghost-app'], []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyWithoutForceWarnsAndConfirms(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'destroy-test.com', 'engine' => 'sqlite']);
        $id = preg_replace('/^App created: ([^ ]+) .*/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->destroy([$id], []);

        $this->assertNotEmpty(\WP_CLI::$warnings);
        $this->assertNotEmpty(\WP_CLI::$confirmations);
        $this->assertCount(1, \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDomainAddAndRemoveSucceed(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'primary.com', 'engine' => 'sqlite']);
        $id = preg_replace('/^App created: ([^ ]+) .*/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->domain_add([$id], ['domain' => 'secondary.com']);
        $cmd->domain_remove([$id], ['domain' => 'secondary.com']);

        $this->assertCount(2, \WP_CLI::$successes);
        $this->assertStringContainsString('Domain added', \WP_CLI::$successes[0]);
        $this->assertStringContainsString('Domain removed', \WP_CLI::$successes[1]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxOutputsSuccess(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'client-a.com', 'engine' => 'sqlite']);
        $appId = preg_replace('/^App created: ([^ ]+) .*/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->create_sandbox([$appId], []);

        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString('Sandbox created from app', \WP_CLI::$successes[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBackupAndRestoreReportSuccess(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'backup-app.com', 'engine' => 'sqlite']);
        $appId = preg_replace('/^App created: ([^ ]+) .*/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->backup([$appId], ['name' => 'baseline']);
        $cmd->restore([$appId], ['backup' => 'baseline', 'force' => true]);

        $this->assertCount(2, \WP_CLI::$successes);
        $this->assertStringContainsString('App backup created', \WP_CLI::$successes[0]);
        $this->assertStringContainsString('App restored from backup', \WP_CLI::$successes[1]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeployReportsSuccessAndBackup(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['domain' => 'deploy-app.com', 'engine' => 'sqlite']);
        $appId = preg_replace('/^App created: ([^ ]+) .*/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->create_sandbox([$appId], ['name' => 'Deploy Sandbox']);
        $sandboxId = preg_replace('/^Sandbox created from app: ([^ ]+)$/', '$1', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->deploy([$appId], ['from' => $sandboxId, 'backup' => 'before-deploy', 'force' => true]);

        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString('Sandbox deployed to app', \WP_CLI::$successes[0]);
        $this->assertContains('  Backup:  before-deploy', array_filter(\WP_CLI::$log, fn($m) => is_string($m)));
    }
}
