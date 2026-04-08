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
                    'slug' => 'demo-app',
                    'name' => 'Demo App',
                    'path' => '/tmp/demo-app',
                    'type' => 'app',
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
                    'branch' => 'rudel/demo-app',
                    'repo_path' => '/tmp/demo-app/wp-content/themes/demo-theme',
                    'created_at' => '2026-04-08T00:00:00+00:00',
                    'updated_at' => '2026-04-08T00:00:00+00:00',
                ],
            ]
        );

        $wpdb->addTable(
            'wp_rudel_app_deployments',
            'CREATE TABLE `wp_rudel_app_deployments` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `deployment_key` varchar(64) NOT NULL,
                `app_id` bigint(20) unsigned NOT NULL,
                `environment_id` bigint(20) unsigned DEFAULT NULL,
                `app_slug` varchar(64) NOT NULL,
                `app_name` varchar(191) NOT NULL,
                `sandbox_slug` varchar(64) NOT NULL,
                `sandbox_name` varchar(191) NOT NULL,
                `source_environment_type` varchar(20) NOT NULL,
                `github_repo` varchar(191) DEFAULT NULL,
                `github_branch` varchar(191) DEFAULT NULL,
                `github_base_branch` varchar(191) DEFAULT NULL,
                `github_dir` varchar(191) DEFAULT NULL,
                `deployed_at` varchar(32) NOT NULL,
                `created_at` varchar(32) NOT NULL,
                `updated_at` varchar(32) NOT NULL,
                PRIMARY KEY (`id`)
            )',
            [
                [
                    'id' => 1,
                    'deployment_key' => 'deploy-20260408_000000-a1b2c3',
                    'app_id' => 1,
                    'environment_id' => 2,
                    'app_slug' => 'demo-app',
                    'app_name' => 'Demo App',
                    'sandbox_slug' => 'feature',
                    'sandbox_name' => 'Feature',
                    'source_environment_type' => 'sandbox',
                    'github_repo' => 'https://example.test/demo.git',
                    'github_branch' => 'rudel/feature',
                    'github_base_branch' => 'main',
                    'github_dir' => 'themes/demo',
                    'deployed_at' => '2026-04-08T00:00:00+00:00',
                    'created_at' => '2026-04-08T00:00:00+00:00',
                    'updated_at' => '2026-04-08T00:00:00+00:00',
                ],
            ]
        );

        RudelSchema::reset();
        RudelSchema::ensure(new WpdbStore($wpdb));

        $environmentRow = $wpdb->getTableRows('wp_rudel_environments')[0];
        $worktreeRow = $wpdb->getTableRows('wp_rudel_worktrees')[0];
        $deploymentRow = $wpdb->getTableRows('wp_rudel_app_deployments')[0];

        $this->assertSame('https://example.test/demo.git', $environmentRow['tracked_git_remote']);
        $this->assertSame('main', $environmentRow['tracked_git_branch']);
        $this->assertSame('themes/demo', $environmentRow['tracked_git_dir']);

        $this->assertSame('demo-theme', $worktreeRow['metadata_name']);

        $this->assertSame('https://example.test/demo.git', $deploymentRow['git_remote']);
        $this->assertSame('rudel/feature', $deploymentRow['git_branch']);
        $this->assertSame('main', $deploymentRow['git_base_branch']);
        $this->assertSame('themes/demo', $deploymentRow['git_dir']);
    }
}
