<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\BootstrapRuntimeStore;
use Rudel\Tests\RudelTestCase;

class BootstrapRuntimeStoreTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostUrlUsesHostOptionsIncludingPortAndSubdirectory(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int, option_name varchar(191), option_value longtext)', [
            ['option_id' => 1, 'option_name' => 'home', 'option_value' => 'http://localhost:8000/wordpress'],
            ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'http://localhost:8000/core'],
        ]);
        $this->assertSame('http://localhost:8000/wordpress', (new BootstrapRuntimeStore())->host_url());
        $wpdb->update('wp_options', ['option_value' => ''], ['option_name' => 'home']);
        $this->assertSame('http://localhost:8000/core', (new BootstrapRuntimeStore())->host_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostUrlUsesAnExplicitConfiguredHome(): void
    {
        define('WP_HOME', 'https://example.test:8443/wordpress');
        $this->assertSame('https://example.test:8443/wordpress', (new BootstrapRuntimeStore())->host_url());
    }

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
        define('RUDEL_RUNTIME_TABLE_WORKTREES', 'client_worktrees');

        $store = (new \ReflectionClass(BootstrapRuntimeStore::class))->newInstanceWithoutConstructor();
        $prefix = new \ReflectionProperty(BootstrapRuntimeStore::class, 'prefix');
        $prefix->setAccessible(true);
        $prefix->setValue($store, 'wp_');

        $method = new \ReflectionMethod(BootstrapRuntimeStore::class, 'table');
        $method->setAccessible(true);

        $this->assertSame('wp_client_worktrees', $method->invoke($store, 'worktrees'));
        $this->assertSame('wp_themeworkspace_environments', $method->invoke($store, 'environments'));
    }
}
