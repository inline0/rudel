<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Rudel\RuntimeTableConfig;
use Rudel\WpdbStore;

class RuntimeTableConfigOverrideTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRuntimeTablePrefixOverrideChangesDefaultTableNames(): void
    {
        define('RUDEL_RUNTIME_TABLE_PREFIX', 'themeworkspace');

        $this->assertSame('themeworkspace_', RuntimeTableConfig::prefix());

        $store = $this->newStore();

        $this->assertSame('wp_themeworkspace_environments', $store->table('environments'));
        $this->assertSame('wp_themeworkspace_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRuntimeTablePrefixPreservesEmptyOverride(): void
    {
        define('RUDEL_RUNTIME_TABLE_PREFIX', '');

        $this->assertSame('', RuntimeTableConfig::prefix());

        $store = $this->newStore();

        $this->assertSame('wp_environments', $store->table('environments'));
        $this->assertSame('wp_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPerTableOverrideWinsOverSharedRuntimeTablePrefix(): void
    {
        define('RUDEL_RUNTIME_TABLE_PREFIX', 'themeworkspace');
        define('RUDEL_RUNTIME_TABLE_ENVIRONMENTS', 'client_environments');
        define('RUDEL_RUNTIME_TABLE_WORKTREES', 'client_worktrees');

        $store = $this->newStore();

        $this->assertSame('wp_client_environments', $store->table('environments'));
        $this->assertSame('wp_client_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostTablePrefixWinsOverSelectedEnvironmentPrefix(): void
    {
        define('RUDEL_HOST_TABLE_PREFIX', 'wp_');

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_env123_';
        $wpdb->base_prefix = 'wp_env123_';

        $store = new WpdbStore($wpdb);

        $this->assertSame('wp_', RuntimeTableConfig::wordpress_prefix($wpdb));
        $this->assertSame('wp_', $store->prefix());
        $this->assertSame('wp_rudel_environments', $store->table('environments'));
    }

    private function newStore(): WpdbStore
    {
        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->base_prefix = 'wp_';

        return new WpdbStore($wpdb);
    }
}
