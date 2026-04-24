<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\RudelConfig;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;

class SnapshotManagerTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSnapshotsSkipSharedPluginsAndUploadsAndRestoreTheirLinks(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content/plugins/demo-plugin', 0755, true);
        mkdir($wordpressRoot . '/wp-content/uploads/2026/04', 0755, true);

        file_put_contents($wordpressRoot . '/wp-content/plugins/demo-plugin/demo-plugin.php', '<?php');
        file_put_contents($wordpressRoot . '/wp-content/uploads/2026/04/demo.txt', 'shared upload');

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $this->createFakeSandbox('shared-site', 'Shared Site', [
            'blog_id' => 2,
            'multisite' => true,
            'shared_plugins' => true,
            'shared_uploads' => true,
        ]);

        $environment = $this->environmentRepository('sandbox')->get('shared-site');
        $this->assertNotNull($environment);

        rmdir($environment->path . '/wp-content/plugins');
        rmdir($environment->path . '/wp-content/uploads');
        symlink($wordpressRoot . '/wp-content/plugins', $environment->path . '/wp-content/plugins');
        symlink($wordpressRoot . '/wp-content/uploads', $environment->path . '/wp-content/uploads');
        mkdir($environment->path . '/wp-content/themes/demo-theme', 0755, true);
        file_put_contents($environment->path . '/wp-content/themes/demo-theme/style.css', 'theme css');

        $GLOBALS['wpdb']->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://shared-site.example.test'],
                ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://shared-site.example.test'],
            ]
        );

        (new RudelConfig())->set('auto_snapshot_before_restore', 0);

        $manager = new SnapshotManager($environment);
        $manager->create('baseline');

        $snapshotPath = $environment->path . '/snapshots/baseline/wp-content';
        $this->assertDirectoryDoesNotExist($snapshotPath . '/plugins');
        $this->assertDirectoryDoesNotExist($snapshotPath . '/uploads');
        $this->assertFileExists($snapshotPath . '/themes/demo-theme/style.css');

        unlink($environment->path . '/wp-content/plugins');
        unlink($environment->path . '/wp-content/uploads');
        mkdir($environment->path . '/wp-content/plugins', 0755, true);
        mkdir($environment->path . '/wp-content/uploads', 0755, true);
        file_put_contents($environment->path . '/wp-content/plugins/local-only.php', '<?php');
        file_put_contents($environment->path . '/wp-content/uploads/local-only.txt', 'local');

        $manager->restore('baseline');

        $this->assertTrue(is_link($environment->path . '/wp-content/plugins'));
        $this->assertTrue(is_link($environment->path . '/wp-content/uploads'));
        $this->assertSame($wordpressRoot . '/wp-content/plugins', readlink($environment->path . '/wp-content/plugins'));
        $this->assertSame($wordpressRoot . '/wp-content/uploads', readlink($environment->path . '/wp-content/uploads'));
        $this->assertFileExists($environment->path . '/wp-content/plugins/demo-plugin/demo-plugin.php');
        $this->assertFileExists($environment->path . '/wp-content/uploads/2026/04/demo.txt');
        $this->assertFileDoesNotExist($environment->path . '/wp-content/plugins/local-only.php');
        $this->assertFileDoesNotExist($environment->path . '/wp-content/uploads/local-only.txt');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRestoreFiltersEnvironmentDbDropinContents(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $this->createFakeSandbox('restore-site', 'Restore Site', [
            'blog_id' => 2,
            'multisite' => true,
        ]);

        $environment = $this->environmentRepository('sandbox')->get('restore-site');
        $this->assertNotNull($environment);

        file_put_contents($environment->path . '/wp-content/db.php', "<?php\n// original");

        add_filter(
            'rudel_environment_db_dropin_contents',
            static function (string $contents, array $context): string {
                return $contents . "\n// restored blog_id=" . (string) ($context['blog_id'] ?? '');
            },
            10,
            2
        );

        (new SnapshotManager($environment))->create('baseline');

        file_put_contents($environment->path . '/wp-content/db.php', "<?php\n// replaced");

        (new SnapshotManager($environment))->restore('baseline');

        $dbDropin = file_get_contents($environment->path . '/wp-content/db.php');
        $this->assertIsString($dbDropin);
        $this->assertStringContainsString("define( 'CUSTOM_USER_TABLE', RUDEL_USERS_TABLE );", $dbDropin);
        $this->assertStringContainsString('// restored blog_id=2', $dbDropin);
    }
}
