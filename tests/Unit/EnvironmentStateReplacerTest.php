<?php

namespace Rudel\Tests\Unit;

use Rudel\Environment;
use Rudel\EnvironmentStateReplacer;
use Rudel\Tests\RudelTestCase;

class EnvironmentStateReplacerTest extends RudelTestCase
{
    public function testReplaceDropsTargetTablesBeforeCopyingSubsiteState(): void
    {
        global $wpdb;

        $wpdb = new \MockWpdb();
        $wpdb->base_prefix = 'wp_';

        $sourcePath = $this->tmpDir . '/source';
        $targetPath = $this->tmpDir . '/target';

        mkdir($sourcePath . '/wp-content', 0755, true);
        mkdir($targetPath . '/wp-content', 0755, true);

        file_put_contents($sourcePath . '/wp-content/state.txt', 'source state');
        file_put_contents($targetPath . '/wp-content/state.txt', 'target state');

        $wpdb->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Feature Deploy'],
                ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'http://feature.localhost'],
                ['option_id' => 3, 'option_name' => 'home', 'option_value' => 'http://feature.localhost'],
            ]
        );
        $wpdb->addTable(
            'wp_3_options',
            'CREATE TABLE `wp_3_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Demo App'],
                ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'http://demo.localhost'],
                ['option_id' => 3, 'option_name' => 'home', 'option_value' => 'http://demo.localhost'],
            ]
        );

        $source = new Environment(
            id: 'feature',
            name: 'Feature',
            path: $sourcePath,
            created_at: '2026-01-01T00:00:00+00:00',
            template: 'blank',
            status: 'active',
            clone_source: null,
            multisite: true,
            engine: 'subsite',
            blog_id: 2,
            type: 'sandbox'
        );
        $target = new Environment(
            id: 'demo',
            name: 'Demo',
            path: $targetPath,
            created_at: '2026-01-01T00:00:00+00:00',
            template: 'blank',
            status: 'active',
            clone_source: null,
            multisite: true,
            engine: 'subsite',
            blog_id: 3,
            type: 'app',
            domains: ['demo.example.test']
        );

        $result = (new EnvironmentStateReplacer())->replace($source, $target);

        $this->assertSame(1, $result['tables_copied']);
        $this->assertContains('DROP TABLE IF EXISTS `wp_3_options`', $wpdb->queriesExecuted);

        $targetRows = $wpdb->getTableRows('wp_3_options');
        $this->assertCount(3, $targetRows);
        $this->assertSame('Feature Deploy', $targetRows[0]['option_value']);
        $this->assertSame('http://demo.example.test', $targetRows[1]['option_value']);
        $this->assertSame('http://demo.example.test', $targetRows[2]['option_value']);
        $this->assertSame('source state', file_get_contents($targetPath . '/wp-content/state.txt'));
    }
}
