<?php

namespace Rudel\Tests\Unit;

use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class RemoteCleanupTest extends RudelTestCase
{
    public function testRemoteCheckoutCleanupUsesFetchedMainlineAndPreservesLocalEdits(): void
    {
        $server = null;
        try {
            $root = $this->tmpDir . '/http';
            $source = $this->tmpDir . '/source';
            $remote = $root . '/remote.git';
            mkdir($root);
            mkdir($source);
            $this->git($root, 'init --bare --initial-branch=main remote.git');
            $this->git($source, 'init --initial-branch=main');
            $this->git($source, 'config user.name Test');
            $this->git($source, 'config user.email test@example.com');
            file_put_contents($source . '/style.css', "/* Theme Name: Remote */\n");
            $this->git($source, 'add style.css');
            $this->git($source, 'commit -m initial');
            $this->git($source, 'remote add origin ' . escapeshellarg($remote));
            $this->git($source, 'push origin main');
            $this->git($remote, 'update-server-info');

            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $this->assertIsResource($socket);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $this->assertIsString($address);
            $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.err', 'a']], $pipes);
            $this->assertIsResource($server);
            $url = 'http://' . $address . '/remote.git';
            $ready = false;
            for ($i = 0; $i < 50; $i++) {
                if (@file_get_contents($url . '/HEAD') !== false) {
                    $ready = true;
                    break;
                }
                usleep(20_000);
            }
            $this->assertTrue($ready);
            $path = $this->createFakeSandbox('remote-cleanup', 'Remote', ['clone_source' => ['git_remote' => $url, 'git_dir' => 'themes/demo', 'git_base_branch' => 'main']]);
            $checkout = $path . '/themes/demo';
            $this->git($source, 'clone ' . escapeshellarg($remote) . ' ' . escapeshellarg($checkout));
            $this->git($checkout, 'remote set-url origin ' . escapeshellarg($url));
            $this->git($checkout, 'config user.name Test');
            $this->git($checkout, 'config user.email test@example.com');
            $this->git($checkout, 'checkout -b rudel/remote-cleanup');
            file_put_contents($checkout . '/style.css', "/* Theme Name: Sandbox */\n");
            $this->git($checkout, 'add style.css');
            $this->git($checkout, 'commit -m sandbox');
            $this->git($remote, 'fetch ' . escapeshellarg($checkout) . ' rudel/remote-cleanup:refs/heads/rudel/remote-cleanup');
            $this->git($remote, 'update-server-info');
            $manager = new EnvironmentManager($this->tmpDir, $this->runtimeStore());
            $this->assertSame(['remote-cleanup'], $manager->cleanup_merged(['dry_run' => true])['skipped']);

            $this->git($source, 'fetch origin');
            $this->git($source, 'merge --ff-only origin/rudel/remote-cleanup');
            $this->git($source, 'push origin main');
            $this->git($remote, 'update-server-info');
            $this->assertSame(['remote-cleanup'], $manager->cleanup_merged(['dry_run' => true])['removed']);
            file_put_contents($checkout . '/style.css', "uncommitted work\n");
            $this->assertSame(['remote-cleanup'], $manager->cleanup_merged(['dry_run' => true])['skipped']);
            $this->assertSame("uncommitted work\n", file_get_contents($checkout . '/style.css'));
        } finally {
            if (is_resource($server)) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    private function git(string $dir, string $arguments): void
    {
        $process = proc_open('git ' . $arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
        $this->assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
    }
}
