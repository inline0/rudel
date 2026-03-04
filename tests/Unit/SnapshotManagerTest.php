<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Sandbox;
use Rudel\SandboxManager;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;

class SnapshotManagerTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateWritesDirectoryAndMetadata(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Create');

        $manager = new SnapshotManager($sandbox);
        $meta = $manager->create('v1');

        $this->assertDirectoryExists($sandbox->path . '/snapshots/v1');
        $this->assertFileExists($sandbox->path . '/snapshots/v1/snapshot.json');
        $this->assertFileExists($sandbox->path . '/snapshots/v1/wordpress.db');
        $this->assertDirectoryExists($sandbox->path . '/snapshots/v1/wp-content');
        $this->assertSame('v1', $meta['name']);
        $this->assertSame($sandbox->id, $meta['sandbox_id']);
        $this->assertNotEmpty($meta['created_at']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateThrowsOnDuplicateName(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Dup');

        $manager = new SnapshotManager($sandbox);
        $manager->create('v1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Snapshot already exists');
        $manager->create('v1');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateThrowsOnInvalidName(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Invalid');

        $manager = new SnapshotManager($sandbox);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid snapshot name');
        $manager->create('../escape');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsMetadata(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap List');

        $manager = new SnapshotManager($sandbox);
        $manager->create('v1');
        $manager->create('v2');

        $list = $manager->list_snapshots();
        $this->assertCount(2, $list);

        $names = array_column($list, 'name');
        $this->assertContains('v1', $names);
        $this->assertContains('v2', $names);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsEmptyWhenNoSnapshots(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Empty');

        $manager = new SnapshotManager($sandbox);
        $this->assertSame([], $manager->list_snapshots());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestoreReplacesDbAndContent(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Restore');

        // Create a snapshot.
        $manager = new SnapshotManager($sandbox);
        $manager->create('baseline');

        // Modify the sandbox after snapshot: add a file, delete db.
        file_put_contents($sandbox->get_wp_content_path() . '/new-file.txt', 'modified');
        unlink($sandbox->get_db_path());

        // Restore.
        $manager->restore('baseline');

        // Database should be back.
        $this->assertFileExists($sandbox->get_db_path());
        // New file should be gone (wp-content was replaced).
        $this->assertFileDoesNotExist($sandbox->get_wp_content_path() . '/new-file.txt');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestoreThrowsOnMissingSnapshot(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Missing');

        $manager = new SnapshotManager($sandbox);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Snapshot not found');
        $manager->restore('nonexistent');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteRemovesDirectory(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Delete');

        $manager = new SnapshotManager($sandbox);
        $manager->create('disposable');

        $this->assertDirectoryExists($sandbox->path . '/snapshots/disposable');
        $this->assertTrue($manager->delete('disposable'));
        $this->assertDirectoryDoesNotExist($sandbox->path . '/snapshots/disposable');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteReturnsFalseForNonexistent(): void
    {
        $this->defineConstants();
        $sandbox = $this->createRealSandbox('Snap Del Miss');

        $manager = new SnapshotManager($sandbox);
        $this->assertFalse($manager->delete('nope'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValidateNameAcceptsValidNames(): void
    {
        $this->assertTrue(SnapshotManager::validate_name('v1'));
        $this->assertTrue(SnapshotManager::validate_name('before-update'));
        $this->assertTrue(SnapshotManager::validate_name('snapshot_2024.01.01'));
        $this->assertTrue(SnapshotManager::validate_name('A'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testValidateNameRejectsInvalidNames(): void
    {
        $this->assertFalse(SnapshotManager::validate_name(''));
        $this->assertFalse(SnapshotManager::validate_name('.hidden'));
        $this->assertFalse(SnapshotManager::validate_name('-dash'));
        $this->assertFalse(SnapshotManager::validate_name('../escape'));
        $this->assertFalse(SnapshotManager::validate_name(str_repeat('a', 65)));
    }

    // Helpers

    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }

    private function createRealSandbox(string $name): Sandbox
    {
        $manager = new SandboxManager($this->tmpDir);
        return $manager->create($name);
    }
}
