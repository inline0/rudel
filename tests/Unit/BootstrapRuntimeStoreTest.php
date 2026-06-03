<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\BootstrapRuntimeStore;
use Rudel\RuntimeProfile;
use Rudel\Tests\Fixtures\RuntimeProfiles;
use Rudel\Tests\RudelTestCase;

class BootstrapRuntimeStoreTest extends RudelTestCase
{
    public function testParseDbHostSupportsHostAndPort(): void
    {
        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'parse_db_host');

        $parsed = $method->invoke($store, 'mysql.example.com:3307');

        $this->assertSame([
            'host' => 'mysql.example.com',
            'port' => 3307,
            'socket' => null,
        ], $parsed);
    }

    public function testParseDbHostSupportsSocket(): void
    {
        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'parse_db_host');

        $parsed = $method->invoke($store, 'localhost:/tmp/mysql.sock');

        $this->assertSame([
            'host' => 'localhost',
            'port' => 0,
            'socket' => '/tmp/mysql.sock',
        ], $parsed);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTableUsesSharedRuntimeTablePrefixOverride(): void
    {
        $profile = RuntimeProfiles::neutral($this->tmpDir);
        $profile['runtime_tables'] = [
            'prefix' => 'themeworkspace',
            'environments' => 'themeworkspace_environments',
            'worktrees' => 'themeworkspace_worktrees',
        ];
        RuntimeProfile::set_current($profile);

        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $prefix = new \ReflectionProperty(BootstrapRuntimeStore::class, 'prefix');
        $prefix->setValue($store, 'wp_');

        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'table');

        $this->assertSame('wp_themeworkspace_environments', $method->invoke($store, 'environments'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTablePrefersExplicitPerTableOverrides(): void
    {
        $profile = RuntimeProfiles::neutral($this->tmpDir);
        $profile['runtime_tables'] = [
            'prefix' => 'themeworkspace',
            'environments' => 'themeworkspace_environments',
            'worktrees' => 'client_worktrees',
        ];
        RuntimeProfile::set_current($profile);

        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $prefix = new \ReflectionProperty(BootstrapRuntimeStore::class, 'prefix');
        $prefix->setValue($store, 'wp_');

        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'table');

        $this->assertSame('wp_client_worktrees', $method->invoke($store, 'worktrees'));
        $this->assertSame('wp_themeworkspace_environments', $method->invoke($store, 'environments'));
    }
}
