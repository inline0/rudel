<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Rudel\RuntimeProfile;
use Rudel\RuntimeTableConfig;
use Rudel\Tests\Fixtures\RuntimeProfiles;
use Rudel\WpdbStore;

class RuntimeTableConfigOverrideTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRuntimeProfileChangesDefaultTableNames(): void
    {
        $profile = RuntimeProfiles::neutral(sys_get_temp_dir());
        $profile['runtime_tables'] = [
            'prefix' => 'themeworkspace',
            'environments' => 'themeworkspace_environments',
            'worktrees' => 'themeworkspace_worktrees',
        ];
        RuntimeProfile::set_current($profile);

        $this->assertSame('themeworkspace_', RuntimeTableConfig::prefix());

        $store = $this->newStore();

        $this->assertSame('wp_themeworkspace_environments', $store->table('environments'));
        $this->assertSame('wp_themeworkspace_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRuntimeProfileCanUseUnprefixedTableNames(): void
    {
        $profile = RuntimeProfiles::neutral(sys_get_temp_dir());
        $profile['runtime_tables'] = [
            'prefix' => 'runtime',
            'environments' => 'environments',
            'worktrees' => 'worktrees',
        ];
        RuntimeProfile::set_current($profile);

        $this->assertSame('runtime_', RuntimeTableConfig::prefix());

        $store = $this->newStore();

        $this->assertSame('wp_environments', $store->table('environments'));
        $this->assertSame('wp_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testProfileTableNamesWinOverSharedRuntimeTablePrefix(): void
    {
        $profile = RuntimeProfiles::neutral(sys_get_temp_dir());
        $profile['runtime_tables'] = [
            'prefix' => 'themeworkspace',
            'environments' => 'client_environments',
            'worktrees' => 'client_worktrees',
        ];
        RuntimeProfile::set_current($profile);

        $store = $this->newStore();

        $this->assertSame('wp_client_environments', $store->table('environments'));
        $this->assertSame('wp_client_worktrees', $store->table('worktrees'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostTablePrefixWinsOverSelectedEnvironmentPrefix(): void
    {
        define(RuntimeProfile::current()->constant('host_table_prefix'), 'wp_');

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
