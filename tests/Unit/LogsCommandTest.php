<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class LogsCommandTest extends RudelTestCase
{
    private function createEnvironmentManager(): \Rudel\EnvironmentManager
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        return new \Rudel\EnvironmentManager($this->tmpDir);
    }

    private function createCommand(): \Rudel\CLI\LogsCommand
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        \WP_CLI::reset();

        return new \Rudel\CLI\LogsCommand($this->createEnvironmentManager());
    }

    private function createSandbox(string $name): string
    {
        $create = new \Rudel\CLI\RudelCommand($this->createEnvironmentManager());
        $create->create([], ['name' => $name, 'template' => 'blank', 'engine' => 'sqlite']);

        return str_replace('Sandbox created: ', '', \WP_CLI::$successes[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogsShowsLogContent(): void
    {
        $cmd = $this->createCommand();
        $id = $this->createSandbox('Log Test');

        $sandbox = $this->createEnvironmentManager()->get($id);
        $logPath = $sandbox->get_wp_content_path() . '/debug.log';
        file_put_contents($logPath, "Line 1\nLine 2\nLine 3\n");
        \WP_CLI::reset();

        $cmd([$id], ['lines' => '2']);

        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('Line 2', $logOutput);
        $this->assertStringContainsString('Line 3', $logOutput);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogsShowsMessageWhenNoLogFile(): void
    {
        $cmd = $this->createCommand();
        $id = $this->createSandbox('No Log Test');
        \WP_CLI::reset();

        $cmd([$id], []);

        $logOutput = implode("\n", array_filter(\WP_CLI::$log, 'is_string'));
        $this->assertStringContainsString('No log file yet', $logOutput);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogsClearRemovesContent(): void
    {
        $cmd = $this->createCommand();
        $id = $this->createSandbox('Clear Log Test');

        $sandbox = $this->createEnvironmentManager()->get($id);
        $logPath = $sandbox->get_wp_content_path() . '/debug.log';
        file_put_contents($logPath, "Some errors\n");
        \WP_CLI::reset();

        $cmd([$id], ['clear' => true]);

        $this->assertSame('', file_get_contents($logPath));
        $this->assertCount(1, \WP_CLI::$successes);
    }
}
