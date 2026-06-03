<?php

namespace Rudel\Tests\Unit;

use Rudel\RudelSchema;
use Rudel\Tests\RudelTestCase;
use Rudel\WpdbStore;

class RudelSchemaTest extends RudelTestCase
{
    public function testEnsureAddsMissingGenericGitColumnsAndBackfillsLegacyValues(): void
    {
        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';
        $wpdb->base_prefix = 'wp_';

        $wpdb->addTable(
            'wp_rudel_environments',
            'CREATE TABLE `wp_rudel_environments` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `slug` varchar(64) NOT NULL,
                `name` varchar(191) NOT NULL,
                `path` varchar(255) NOT NULL,
                `type` varchar(20) NOT NULL,
                `engine` varchar(20) NOT NULL,
                `template` varchar(64) NOT NULL,
                `status` varchar(32) NOT NULL,
                `multisite` tinyint(1) NOT NULL DEFAULT 0,
                `tracked_github_repo` varchar(191) DEFAULT NULL,
                `tracked_github_branch` varchar(191) DEFAULT NULL,
                `tracked_github_dir` varchar(191) DEFAULT NULL,
                `created_at` varchar(32) NOT NULL,
                `updated_at` varchar(32) NOT NULL,
                PRIMARY KEY (`id`)
            )',
            [
                [
                    'id' => 1,
                    'slug' => 'demo-env',
                    'name' => 'Demo Env',
                    'path' => '/tmp/demo-env',
                    'type' => 'sandbox',
                    'engine' => 'subsite',
                    'template' => 'blank',
                    'status' => 'active',
                    'multisite' => 1,
                    'tracked_github_repo' => 'https://example.test/demo.git',
                    'tracked_github_branch' => 'main',
                    'tracked_github_dir' => 'themes/demo',
                    'created_at' => '2026-04-08T00:00:00+00:00',
                    'updated_at' => '2026-04-08T00:00:00+00:00',
                ],
            ]
        );

        $wpdb->addTable(
            'wp_rudel_worktrees',
            'CREATE TABLE `wp_rudel_worktrees` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `environment_id` bigint(20) unsigned NOT NULL,
                `content_type` varchar(32) NOT NULL,
                `name` varchar(191) NOT NULL,
                `branch` varchar(191) NOT NULL,
                `repo_path` varchar(255) NOT NULL,
                `created_at` varchar(32) NOT NULL,
                `updated_at` varchar(32) NOT NULL,
                PRIMARY KEY (`id`)
            )',
            [
                [
                    'id' => 1,
                    'environment_id' => 1,
                    'content_type' => 'themes',
                    'name' => 'demo-theme',
                    'branch' => 'rudel/demo-env',
                    'repo_path' => '/tmp/demo-env/wp-content/themes/demo-theme',
                    'created_at' => '2026-04-08T00:00:00+00:00',
                    'updated_at' => '2026-04-08T00:00:00+00:00',
                ],
            ]
        );

        RudelSchema::reset();
        RudelSchema::ensure(new WpdbStore($wpdb));

        $environmentRow = $wpdb->getTableRows('wp_rudel_environments')[0];
        $worktreeRow = $wpdb->getTableRows('wp_rudel_worktrees')[0];

        $this->assertSame('https://example.test/demo.git', $environmentRow['tracked_git_remote']);
        $this->assertSame('main', $environmentRow['tracked_git_branch']);
        $this->assertSame('themes/demo', $environmentRow['tracked_git_dir']);
        $this->assertArrayHasKey('shared_plugins', $environmentRow);
        $this->assertArrayHasKey('shared_uploads', $environmentRow);

        $this->assertSame('demo-theme', $worktreeRow['metadata_name']);
    }
}
