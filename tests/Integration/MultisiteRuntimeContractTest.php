<?php

namespace Rudel\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\BootstrapRuntimeStore;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class MultisiteRuntimeContractTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapRuntimeStoreResolvesCreatedEnvironmentBySlug(): void
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

        $environment = $manager->create('Bravo Site');
        $store = new BootstrapRuntimeStore();
        $record = $store->environment_by_slug($environment->id);

        $this->assertIsArray($record);
        $this->assertSame($environment->id, $record['slug']);
        $this->assertSame('subsite', $record['engine']);
        $this->assertSame((int) $environment->blog_id, (int) $record['blog_id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatedEnvironmentUsesItsOwnLocalWpContentTree(): void
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

        $environment = $manager->create('Charlie Site');

        $this->assertSame($environment->path . '/wp-content', $environment->get_runtime_wp_content_path());
        $this->assertDirectoryExists($environment->get_runtime_wp_content_path());
        $this->assertDirectoryExists($environment->get_runtime_content_path('themes'));
        $this->assertDirectoryExists($environment->get_runtime_content_path('plugins'));
        $this->assertDirectoryExists($environment->get_runtime_content_path('uploads'));
    }
}
