<?php

namespace Rudel\Tests\Security;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class MultisiteIsolationTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachEnvironmentGetsItsOwnSubsiteIdentityAndTables(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $alpha = $manager->create('Alpha Site');
        $beta = $manager->create('Beta Site');

        $this->assertNotSame($alpha->blog_id, $beta->blog_id);
        $this->assertNotSame($alpha->get_table_prefix(), $beta->get_table_prefix());
        $this->assertNotSame($alpha->get_url(), $beta->get_url());
        $this->assertNotSame($alpha->get_users_table(), $beta->get_users_table());
        $this->assertNotSame($alpha->get_usermeta_table(), $beta->get_usermeta_table());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachEnvironmentKeepsItsOwnLocalWpContentTree(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $alpha = $manager->create('Alpha Site');
        $beta = $manager->create('Beta Site');

        mkdir($alpha->get_runtime_content_path('themes/alpha-theme'), 0755, true);
        mkdir($beta->get_runtime_content_path('themes/beta-theme'), 0755, true);
        file_put_contents($alpha->get_runtime_content_path('themes/alpha-theme/style.css'), 'alpha');
        file_put_contents($beta->get_runtime_content_path('themes/beta-theme/style.css'), 'beta');

        $this->assertSame($alpha->path . '/wp-content', $alpha->get_runtime_wp_content_path());
        $this->assertSame($beta->path . '/wp-content', $beta->get_runtime_wp_content_path());
        $this->assertFileExists($alpha->get_runtime_content_path('themes/alpha-theme/style.css'));
        $this->assertFileExists($beta->get_runtime_content_path('themes/beta-theme/style.css'));
        $this->assertFileDoesNotExist($alpha->get_runtime_content_path('themes/beta-theme/style.css'));
        $this->assertFileDoesNotExist($beta->get_runtime_content_path('themes/alpha-theme/style.css'));
    }
}
