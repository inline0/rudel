<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class PromoteCommandTest extends RudelTestCase
{
    private function createCommand(): \Rudel\CLI\PromoteCommand
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();

        return new \Rudel\CLI\PromoteCommand(new \Rudel\EnvironmentManager($this->tmpDir));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteNonexistentSandboxCallsError(): void
    {
        $cmd = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found');
        $cmd(['nonexistent'], ['force' => true]);
    }
}
