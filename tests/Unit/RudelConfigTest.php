<?php

namespace Rudel\Tests\Unit;

use Rudel\RudelConfig;
use Rudel\Tests\RudelTestCase;

class RudelConfigTest extends RudelTestCase
{
    public function testDefaultValuesWhenNoFile(): void
    {
        $config = new RudelConfig($this->tmpDir . '/nonexistent.json');

        $this->assertSame(0, $config->get('max_sandboxes'));
        $this->assertSame(0, $config->get('max_age_days'));
        $this->assertSame(0, $config->get('max_disk_mb'));
    }

    public function testGetReturnsZeroForUnknownKey(): void
    {
        $config = new RudelConfig($this->tmpDir . '/config.json');
        $this->assertSame(0, $config->get('unknown_key'));
    }

    public function testSetAndGet(): void
    {
        $config = new RudelConfig($this->tmpDir . '/config.json');
        $config->set('max_sandboxes', 10);
        $this->assertSame(10, $config->get('max_sandboxes'));
    }

    public function testSaveAndLoad(): void
    {
        $path = $this->tmpDir . '/config.json';
        $config = new RudelConfig($path);
        $config->set('max_sandboxes', 5);
        $config->set('max_age_days', 30);
        $config->save();

        $this->assertFileExists($path);

        $loaded = new RudelConfig($path);
        $this->assertSame(5, $loaded->get('max_sandboxes'));
        $this->assertSame(30, $loaded->get('max_age_days'));
        $this->assertSame(0, $loaded->get('max_disk_mb'));
    }

    public function testAllReturnsDefaults(): void
    {
        $config = new RudelConfig($this->tmpDir . '/config.json');
        $all = $config->all();

        $this->assertArrayHasKey('max_sandboxes', $all);
        $this->assertArrayHasKey('max_age_days', $all);
        $this->assertArrayHasKey('max_disk_mb', $all);
        $this->assertSame(0, $all['max_sandboxes']);
    }

    public function testAllIncludesSetValues(): void
    {
        $config = new RudelConfig($this->tmpDir . '/config.json');
        $config->set('max_sandboxes', 20);
        $all = $config->all();

        $this->assertSame(20, $all['max_sandboxes']);
    }

    public function testLoadHandlesCorruptFile(): void
    {
        $path = $this->tmpDir . '/bad-config.json';
        file_put_contents($path, 'not json');

        $config = new RudelConfig($path);
        $this->assertSame(0, $config->get('max_sandboxes'));
    }

    public function testSaveCreatesDirectory(): void
    {
        $path = $this->tmpDir . '/nested/dir/config.json';
        $config = new RudelConfig($path);
        $config->set('max_sandboxes', 3);
        $config->save();

        $this->assertFileExists($path);
    }
}
