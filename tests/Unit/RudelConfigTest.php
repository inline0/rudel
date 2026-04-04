<?php

namespace Rudel\Tests\Unit;

use Rudel\RudelConfig;
use Rudel\Tests\RudelTestCase;

class RudelConfigTest extends RudelTestCase
{
    public function testDefaultValuesWhenNoOptionExists(): void
    {
        $config = new RudelConfig();

        $this->assertSame(0, $config->get('max_sandboxes'));
        $this->assertSame(0, $config->get('max_age_days'));
        $this->assertSame(0, $config->get('max_disk_mb'));
        $this->assertSame(0, $config->get('default_ttl_days'));
        $this->assertSame(0, $config->get('max_idle_days'));
        $this->assertSame(1, $config->get('auto_cleanup_enabled'));
        $this->assertSame(0, $config->get('auto_cleanup_merged'));
        $this->assertSame(0, $config->get('auto_app_backups_enabled'));
        $this->assertSame(24, $config->get('auto_app_backup_interval_hours'));
        $this->assertSame(0, $config->get('auto_app_backup_retention_count'));
        $this->assertSame(0, $config->get('auto_app_deployment_retention_count'));
        $this->assertSame(0, $config->get('expiring_environment_notice_days'));
        $this->assertSame(1, $config->get('auto_snapshot_before_restore'));
        $this->assertSame(1, $config->get('auto_backup_before_app_restore'));
    }

    public function testGetReturnsZeroForUnknownKey(): void
    {
        $config = new RudelConfig();
        $this->assertSame(0, $config->get('unknown_key'));
    }

    public function testSetAndGet(): void
    {
        $config = new RudelConfig();
        $config->set('max_sandboxes', 10);
        $this->assertSame(10, $config->get('max_sandboxes'));
    }

    public function testSaveAndLoadFromWordPressOptions(): void
    {
        $config = new RudelConfig();
        $config->set('max_sandboxes', 5);
        $config->set('max_age_days', 30);
        $config->set('default_ttl_days', 14);
        $config->set('max_idle_days', 7);
        $config->set('auto_cleanup_merged', 1);
        $config->set('auto_app_backups_enabled', 1);
        $config->set('auto_app_backup_interval_hours', 12);
        $config->set('auto_app_backup_retention_count', 5);
        $config->set('auto_app_deployment_retention_count', 7);
        $config->set('expiring_environment_notice_days', 3);
        $config->save();

        $rows = $this->runtimeStore()->fetch_all(
            'SELECT * FROM `wp_options` WHERE option_name = ?',
            [ $config->option_name() ]
        );
        $this->assertCount(1, $rows);

        $loaded = new RudelConfig();
        $this->assertSame(5, $loaded->get('max_sandboxes'));
        $this->assertSame(30, $loaded->get('max_age_days'));
        $this->assertSame(14, $loaded->get('default_ttl_days'));
        $this->assertSame(7, $loaded->get('max_idle_days'));
        $this->assertSame(1, $loaded->get('auto_cleanup_merged'));
        $this->assertSame(1, $loaded->get('auto_app_backups_enabled'));
        $this->assertSame(12, $loaded->get('auto_app_backup_interval_hours'));
        $this->assertSame(5, $loaded->get('auto_app_backup_retention_count'));
        $this->assertSame(7, $loaded->get('auto_app_deployment_retention_count'));
        $this->assertSame(3, $loaded->get('expiring_environment_notice_days'));
        $this->assertSame(0, $loaded->get('max_disk_mb'));
    }

    public function testAllReturnsDefaults(): void
    {
        $config = new RudelConfig();
        $all = $config->all();

        $this->assertArrayHasKey('max_sandboxes', $all);
        $this->assertArrayHasKey('max_age_days', $all);
        $this->assertArrayHasKey('max_disk_mb', $all);
        $this->assertArrayHasKey('default_ttl_days', $all);
        $this->assertArrayHasKey('max_idle_days', $all);
        $this->assertArrayHasKey('auto_cleanup_enabled', $all);
        $this->assertArrayHasKey('auto_app_backups_enabled', $all);
        $this->assertArrayHasKey('auto_app_backup_retention_count', $all);
        $this->assertSame(0, $all['max_sandboxes']);
    }

    public function testAllIncludesSetValues(): void
    {
        $config = new RudelConfig();
        $config->set('max_sandboxes', 20);
        $all = $config->all();

        $this->assertSame(20, $all['max_sandboxes']);
    }

    public function testLoadHandlesCorruptOptionPayload(): void
    {
        $this->runtimeStore()->insert('wp_options', [
            'option_name' => 'rudel_config',
            'option_value' => 'not serialized',
            'autoload' => 'no',
        ]);

        $config = new RudelConfig();
        $this->assertSame(0, $config->get('max_sandboxes'));
    }

    public function testSaveUpdatesExistingOptionRow(): void
    {
        $this->runtimeStore()->insert('wp_options', [
            'option_name' => 'rudel_config',
            'option_value' => serialize(['max_sandboxes' => 1]),
            'autoload' => 'no',
        ]);

        $config = new RudelConfig();
        $config->set('max_sandboxes', 3);
        $config->save();

        $rows = $this->runtimeStore()->fetch_all(
            'SELECT * FROM `wp_options` WHERE option_name = ?',
            [ $config->option_name() ]
        );
        $this->assertCount(1, $rows);

        $loaded = new RudelConfig();
        $this->assertSame(3, $loaded->get('max_sandboxes'));
    }

    public function testOptionNameIsStable(): void
    {
        $config = new RudelConfig();
        $this->assertSame('rudel_config', $config->option_name());
    }
}
