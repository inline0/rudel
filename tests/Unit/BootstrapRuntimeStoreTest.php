<?php

namespace Rudel\Tests\Unit;

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
}
