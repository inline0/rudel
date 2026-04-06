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

	    #[RunInSeparateProcess]
	    #[PreserveGlobalState(false)]
	    public function testRuntimeUrlOverridesStayScopedToTheResolvedBlogDuringSwitchToBlogFlows(): void
	    {
	        $wordpressRoot = $this->tmpDir . '/wordpress';
	        mkdir($wordpressRoot . '/wp-content', 0755, true);

	        define('ABSPATH', $wordpressRoot . '/');
	        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
	        define('WP_HOME', 'http://example.test');
	        define('DOMAIN_CURRENT_SITE', 'example.test');

	        $manager = new EnvironmentManager(
	            $this->tmpDir . '/apps',
	            $this->tmpDir . '/sandboxes',
	            'app',
	            $this->runtimeStore()
	        );

	        $siteA = $manager->create('Site A', ['type' => 'app']);
	        $siteB = $manager->create('Site B', ['type' => 'app']);

	        define('RUDEL_ID', $siteA->id);
	        define('RUDEL_IS_APP', true);
	        define('RUDEL_PATH', $siteA->path);
	        define('RUDEL_ENVIRONMENT_URL', rtrim($siteA->get_url(), '/'));
	        define('RUDEL_HOST_URL', 'http://example.test');
	        define('RUDEL_TABLE_PREFIX', 'wp_' . $siteA->blog_id . '_');

	        $GLOBALS['rudel_test_current_blog_id'] = (int) $siteA->blog_id;
	        $GLOBALS['blog_id'] = (int) $siteA->blog_id;
	        $GLOBALS['current_blog'] = get_blog_details((int) $siteA->blog_id);
	        $GLOBALS['table_prefix'] = 'wp_' . $siteA->blog_id . '_';
	        $GLOBALS['wpdb']->blogid = (int) $siteA->blog_id;

	        require $siteA->path . '/wp-content/mu-plugins/rudel-runtime.php';

	        $expected = [
	            1 => 'http://example.test/wp-admin/',
	            (int) $siteA->blog_id => rtrim($siteA->get_url(), '/') . '/wp-admin/',
	            (int) $siteB->blog_id => rtrim($siteB->get_url(), '/') . '/wp-admin/',
	        ];

	        $resolved = [
	            1 => get_admin_url(1),
	            (int) $siteA->blog_id => get_admin_url((int) $siteA->blog_id),
	            (int) $siteB->blog_id => get_admin_url((int) $siteB->blog_id),
	        ];

	        $this->assertSame($expected, $resolved);
	        $this->assertCount(3, array_unique(array_values($resolved)));
	        $this->assertSame(rtrim($siteA->get_url(), '/') . '/', get_home_url((int) $siteA->blog_id, '/'));
	        $this->assertSame(rtrim($siteB->get_url(), '/') . '/', get_home_url((int) $siteB->blog_id, '/'));
	    }
	}
