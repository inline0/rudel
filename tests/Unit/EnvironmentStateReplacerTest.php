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

    public function testReplaceKeepsTargetWorktreeDirectoryLocalAndPreservesGitMetadata(): void
    {
        global $wpdb;

        $wpdb = new \MockWpdb();
        $wpdb->base_prefix = 'wp_';

        $sourcePath = $this->tmpDir . '/source-worktree';
        $targetPath = $this->tmpDir . '/target-worktree';

        mkdir($sourcePath . '/wp-content/themes/demo-theme', 0755, true);
        mkdir($targetPath . '/wp-content/themes/demo-theme/.git', 0755, true);

        file_put_contents($sourcePath . '/wp-content/themes/demo-theme/style.css', 'source css');
        file_put_contents($sourcePath . '/wp-content/themes/demo-theme/new.php', '<?php echo "new";');
        file_put_contents($targetPath . '/wp-content/themes/demo-theme/style.css', 'target css');
        file_put_contents($targetPath . '/wp-content/themes/demo-theme/.git/HEAD', 'ref: refs/heads/main');

        $wpdb->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://feature.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://feature.example.test'],
            ]
        );
        $wpdb->addTable(
            'wp_3_options',
            'CREATE TABLE `wp_3_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://app.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://app.example.test'],
            ]
        );

        $source = new Environment(
            id: 'feature',
            name: 'Feature',
            path: $sourcePath,
            created_at: '2026-01-01T00:00:00+00:00',
            clone_source: [
                'git_worktrees' => [
                    [
                        'type' => 'themes',
                        'name' => 'demo-theme',
                        'branch' => 'rudel/feature',
                        'repo' => $sourcePath . '/wp-content/themes/demo-theme',
                    ],
                ],
            ],
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
            clone_source: [
                'git_worktrees' => [
                    [
                        'type' => 'themes',
                        'name' => 'demo-theme',
                        'branch' => 'rudel/demo',
                        'repo' => $targetPath . '/wp-content/themes/demo-theme',
                    ],
                ],
            ],
            multisite: true,
            engine: 'subsite',
            blog_id: 3,
            type: 'app',
            domains: ['demo.example.test']
        );

        (new EnvironmentStateReplacer())->replace($source, $target);

        $this->assertSame('source css', file_get_contents($targetPath . '/wp-content/themes/demo-theme/style.css'));
        $this->assertFileExists($targetPath . '/wp-content/themes/demo-theme/new.php');
        $this->assertFileExists($targetPath . '/wp-content/themes/demo-theme/.git/HEAD');
        $this->assertSame('ref: refs/heads/main', file_get_contents($targetPath . '/wp-content/themes/demo-theme/.git/HEAD'));
    }

    public function testReplaceRemovesTrackedTargetWorktreesThatNoLongerExistInTheSource(): void
    {
        global $wpdb;

        $wpdb = new \MockWpdb();
        $wpdb->base_prefix = 'wp_';

        $sourcePath = $this->tmpDir . '/source-remove-worktree';
        $targetPath = $this->tmpDir . '/target-remove-worktree';

        mkdir($sourcePath . '/wp-content/themes', 0755, true);
        mkdir($targetPath . '/wp-content/themes/demo-theme/.git', 0755, true);

        file_put_contents($targetPath . '/wp-content/themes/demo-theme/style.css', 'target css');
        file_put_contents($targetPath . '/wp-content/themes/demo-theme/.git/HEAD', 'ref: refs/heads/main');

        $wpdb->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://feature.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://feature.example.test'],
            ]
        );
        $wpdb->addTable(
            'wp_3_options',
            'CREATE TABLE `wp_3_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://app.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://app.example.test'],
            ]
        );

        $source = new Environment(
            id: 'feature',
            name: 'Feature',
            path: $sourcePath,
            created_at: '2026-01-01T00:00:00+00:00',
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
            clone_source: [
                'git_worktrees' => [
                    [
                        'type' => 'themes',
                        'name' => 'demo-theme',
                        'branch' => 'rudel/demo',
                        'repo' => $targetPath . '/wp-content/themes/demo-theme',
                    ],
                ],
            ],
            multisite: true,
            engine: 'subsite',
            blog_id: 3,
            type: 'app',
            domains: ['demo.example.test']
        );

        (new EnvironmentStateReplacer())->replace($source, $target);

        $this->assertDirectoryDoesNotExist($targetPath . '/wp-content/themes/demo-theme');
    }

    public function testReplaceCopiesIsolatedUsersIntoTheTargetEnvironment(): void
    {
        global $wpdb;

        $wpdb = new \MockWpdb();
        $wpdb->base_prefix = 'wp_';

        $sourcePath = $this->tmpDir . '/source-users';
        $targetPath = $this->tmpDir . '/target-users';

        mkdir($sourcePath . '/wp-content', 0755, true);
        mkdir($targetPath . '/wp-content', 0755, true);

        $wpdb->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://feature.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://feature.example.test'],
            ]
        );
        $wpdb->addTable(
            'wp_3_options',
            'CREATE TABLE `wp_3_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://app.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://app.example.test'],
            ]
        );
        $wpdb->addTable(
            'wp_rudel_env_2_users',
            'CREATE TABLE `wp_rudel_env_2_users` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_login` varchar(60), PRIMARY KEY (`ID`))',
            [
                ['ID' => 1, 'user_login' => 'feature-admin'],
            ]
        );
        $wpdb->addTable(
            'wp_rudel_env_2_usermeta',
            'CREATE TABLE `wp_rudel_env_2_usermeta` (`umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`umeta_id`))',
            [
                ['umeta_id' => 1, 'user_id' => 1, 'meta_key' => 'wp_2_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}'],
            ]
        );
        $wpdb->addTable(
            'wp_rudel_env_3_users',
            'CREATE TABLE `wp_rudel_env_3_users` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_login` varchar(60), PRIMARY KEY (`ID`))',
            [
                ['ID' => 1, 'user_login' => 'app-admin'],
            ]
        );
        $wpdb->addTable(
            'wp_rudel_env_3_usermeta',
            'CREATE TABLE `wp_rudel_env_3_usermeta` (`umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`umeta_id`))',
            [
                ['umeta_id' => 1, 'user_id' => 1, 'meta_key' => 'wp_3_capabilities', 'meta_value' => 'a:1:{s:13:"editor";b:1;}'],
            ]
        );

        $source = new Environment(
            id: 'feature',
            name: 'Feature',
            path: $sourcePath,
            created_at: '2026-01-01T00:00:00+00:00',
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
            multisite: true,
            engine: 'subsite',
            blog_id: 3,
            type: 'app',
            domains: ['demo.example.test']
        );

        (new EnvironmentStateReplacer())->replace($source, $target);

        $targetUsers = $wpdb->getTableRows('wp_rudel_env_3_users');
        $targetUsermeta = $wpdb->getTableRows('wp_rudel_env_3_usermeta');

        $this->assertSame('feature-admin', $targetUsers[0]['user_login'] ?? null);
        $this->assertSame('wp_3_capabilities', $targetUsermeta[0]['meta_key'] ?? null);
    }
}
