<?php

namespace Rudel\Tests\Unit;

use Rudel\PrivateStorage;
use Rudel\Tests\RudelTestCase;

class PrivateStorageTest extends RudelTestCase
{
    public function testLegacyLogMigratesBehindExecutableGuardWithoutExposingPhpPayload(): void
    {
        $path = $this->tmpDir . '/private-environment';
        mkdir($path);
        $payload = "secret token\n<?php echo 'executed'; ?>\n";
        file_put_contents($path . '/debug.log', $payload);
        $log = PrivateStorage::log_file($path);
        $this->assertSame(PrivateStorage::LOG_HEADER . $payload, file_get_contents($log));
        $this->assertFileDoesNotExist($path . '/debug.log');
        $this->assertSame($log, PrivateStorage::log_file($path));
        $this->assertSame(PrivateStorage::LOG_HEADER . $payload, file_get_contents($log));
        foreach (['logs', 'snapshots', 'backups', 'metadata', 'tmp'] as $directory) {
            $this->assertStringContainsString('Require all denied', file_get_contents($path . '/' . $directory . '/.htaccess'));
            $this->assertFileExists($path . '/' . $directory . '/index.php');
        }
        $process = proc_open([PHP_BINARY, '-r', 'include $argv[1];', $log], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('', $output);
    }
}
