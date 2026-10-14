<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Rudel;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;

class SnapshotCleanupTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFacadeAndCliDeleteSnapshotsWithoutTouchingTheSandbox(): void
    {
        define('RUDEL_ENVIRONMENTS_DIR', $this->tmpDir);
        define('WP_CLI', true);
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';
        \WP_CLI::reset();
        $path = $this->createFakeSandbox('snapshot-test');
        $environment = Rudel::get('snapshot-test');
        $this->assertNotNull($environment);
        $prefix = $environment->get_table_prefix();
        $GLOBALS['wpdb']->addTable($prefix . 'options', 'CREATE TABLE t (id int)', []);
        Rudel::snapshot('snapshot-test', 'old');
        $command = new \Rudel\CLI\SnapshotCommand(new \Rudel\EnvironmentManager($this->tmpDir, $this->runtimeStore()));
        $command(['delete', 'snapshot-test'], ['name' => 'old']);
        $this->assertSame(['Snapshot deleted: old'], \WP_CLI::$successes);
        $this->assertDirectoryDoesNotExist($path . '/snapshots/old');
        $this->assertTrue($GLOBALS['wpdb']->hasTable($prefix . 'options'));
        Rudel::snapshot('snapshot-test', 'later');
        $this->assertTrue(Rudel::delete_snapshot('snapshot-test', 'later'));
        $this->assertFalse(Rudel::delete_snapshot('snapshot-test', 'later'));
        $plan = \Rudel\CliCommandMap::resolve('wp rudel snapshot delete', ['snapshot-test'], ['name' => 'later']);
        $this->assertSame(Rudel::class . '::delete_snapshot', $plan['callable']);
        $this->assertSame(['snapshot-test', 'later'], $plan['arguments']);
    }

    public function testPruneKeepsTheNewestSnapshotAndRemovesItsOlderTables(): void
    {
        $this->createFakeSandbox('snapshot-prune');
        $environment = $this->environmentRepository()->get('snapshot-prune');
        $this->assertNotNull($environment);
        $prefix = $environment->get_table_prefix();
        $GLOBALS['wpdb']->addTable($prefix . 'options', 'CREATE TABLE t (id int)', []);
        $manager = new SnapshotManager($environment);
        foreach (['a', 'b', 'c'] as $name) {
            $manager->create($name);
        }
        $latest = $manager->list_snapshots()[0]['name'];
        $this->assertCount(2, $manager->prune(1));
        $this->assertSame([$latest], array_column($manager->list_snapshots(), 'name'));
        $this->assertTrue($GLOBALS['wpdb']->hasTable($prefix . 'options'));
        $snapshotTables = array_filter($GLOBALS['wpdb']->getTableNames(), static fn(string $table): bool => str_starts_with($table, $prefix . 'snap_'));
        $this->assertCount(1, $snapshotTables);
    }

    public function testDeletionRejectsPathsOutsideSnapshotStorage(): void
    {
        $path = $this->createFakeSandbox('safe-snapshot');
        mkdir($path . '/snapshots');
        $environment = $this->environmentRepository()->get('safe-snapshot');
        $this->assertNotNull($environment);
        $this->expectException(\InvalidArgumentException::class);
        (new SnapshotManager($environment))->delete('..');
    }
}
