<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\BootstrapRuntimeStore;
use Rudel\Tests\RudelTestCase;

class BootstrapRuntimeStoreTest extends RudelTestCase
{
    public function testParseDbHostSupportsHostAndPort(): void
    {
        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'parse_db_host');
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        define('RUDEL_RUNTIME_TABLE_PREFIX', 'themeworkspace');

        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $prefix = new \ReflectionProperty(BootstrapRuntimeStore::class, 'prefix');
        $prefix->setAccessible(true);
        $prefix->setValue($store, 'wp_');

        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'table');
        $method->setAccessible(true);

        $this->assertSame('wp_themeworkspace_environments', $method->invoke($store, 'environments'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTablePrefersExplicitPerTableOverrides(): void
    {
        define('RUDEL_RUNTIME_TABLE_PREFIX', 'themeworkspace');
        define('RUDEL_RUNTIME_TABLE_APP_DOMAINS', 'client_app_domains');

        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $prefix = new \ReflectionProperty(BootstrapRuntimeStore::class, 'prefix');
        $prefix->setAccessible(true);
        $prefix->setValue($store, 'wp_');

        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'table');
        $method->setAccessible(true);

        $this->assertSame('wp_client_app_domains', $method->invoke($store, 'app_domains'));
        $this->assertSame('wp_themeworkspace_apps', $method->invoke($store, 'apps'));
    }
}
