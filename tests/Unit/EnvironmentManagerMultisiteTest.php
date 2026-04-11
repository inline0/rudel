<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class EnvironmentManagerMultisiteTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateWritesMultisiteRuntimeArtifacts(): void
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

        $environment = $manager->create('Alpha Site');

        $this->assertTrue($environment->is_subsite());
        $this->assertNotNull($environment->blog_id);
        $this->assertSame('http://' . $environment->id . '.example.test/', $environment->get_url());
        $this->assertSame($environment->get_url(), $this->siteOptionValue((int) $environment->blog_id, 'siteurl'));
        $this->assertSame($environment->get_url(), $this->siteOptionValue((int) $environment->blog_id, 'home'));

        $this->assertFileExists($environment->path . '/bootstrap.php');
	        $bootstrap = file_get_contents($environment->path . '/bootstrap.php');
	        $this->assertIsString($bootstrap);
	        $this->assertStringContainsString("define('RUDEL_ENGINE', 'subsite')", $bootstrap);
	        $this->assertStringContainsString("define('RUDEL_ENVIRONMENT_URL', \$_rudel_environment_url);", $bootstrap);
	        $this->assertStringNotContainsString("define('WP_HOME', \$_rudel_environment_url);", $bootstrap);
	        $this->assertStringNotContainsString("define('WP_SITEURL', \$_rudel_environment_url);", $bootstrap);
	        $this->assertStringContainsString("define('RUDEL_TABLE_PREFIX', 'wp_" . $environment->blog_id . "_');", $bootstrap);
	        $this->assertStringContainsString("define('RUDEL_USERS_TABLE', 'wp_rudel_env_" . $environment->blog_id . "_users');", $bootstrap);
	        $this->assertStringContainsString("define('RUDEL_USERMETA_TABLE', 'wp_rudel_env_" . $environment->blog_id . "_usermeta');", $bootstrap);

        $this->assertFileExists($environment->path . '/wp-cli.yml');
        $wpCli = file_get_contents($environment->path . '/wp-cli.yml');
        $this->assertIsString($wpCli);
        $this->assertStringContainsString('path: ' . $wordpressRoot, $wpCli);
        $this->assertStringContainsString('url: http://' . $environment->id . '.example.test/', $wpCli);

        $this->assertFileExists($environment->path . '/wp-content/mu-plugins/rudel-runtime.php');
	        $runtimePlugin = file_get_contents($environment->path . '/wp-content/mu-plugins/rudel-runtime.php');
	        $this->assertIsString($runtimePlugin);
	        $this->assertStringContainsString('pre_option_home', $runtimePlugin);
	        $this->assertStringContainsString('rudel_runtime_environment_url', $runtimePlugin);
	        $this->assertStringContainsString('rudel_runtime_site_option_override', $runtimePlugin);

        $this->assertFileExists($environment->path . '/wp-content/db.php');
        $dbDropin = file_get_contents($environment->path . '/wp-content/db.php');
        $this->assertIsString($dbDropin);
        $this->assertStringContainsString("define( 'CUSTOM_USER_TABLE', RUDEL_USERS_TABLE );", $dbDropin);
        $this->assertStringContainsString("define( 'CUSTOM_USER_META_TABLE', RUDEL_USERMETA_TABLE );", $dbDropin);
        $this->assertSame('wp_rudel_env_' . $environment->blog_id . '_users', $environment->get_users_table());
        $this->assertSame('wp_rudel_env_' . $environment->blog_id . '_usermeta', $environment->get_usermeta_table());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateCanSharePluginsAndUploadsWithTheHostContentTree(): void
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

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $environment = $manager->create('Shared Content Site', [
            'shared_plugins' => true,
            'shared_uploads' => true,
        ]);

        $this->assertTrue($environment->shared_plugins);
        $this->assertTrue($environment->shared_uploads);
        $this->assertTrue(is_link($environment->path . '/wp-content/plugins'));
        $this->assertTrue(is_link($environment->path . '/wp-content/uploads'));
        $this->assertDirectoryExists($environment->path . '/wp-content/themes');
        $this->assertFalse(is_link($environment->path . '/wp-content/themes'));
        $this->assertFileExists($environment->path . '/wp-content/plugins/demo-plugin/demo-plugin.php');
        $this->assertFileExists($environment->path . '/wp-content/uploads/2026/04/demo.txt');
        $this->assertSame(
            $wordpressRoot . '/wp-content/plugins',
            readlink($environment->path . '/wp-content/plugins')
        );
        $this->assertSame(
            $wordpressRoot . '/wp-content/uploads',
            readlink($environment->path . '/wp-content/uploads')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppWritesCanonicalDomainIntoRuntimeArtifacts(): void
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

        $app = $manager->create('Demo App', [
            'type' => 'app',
            'domains' => ['demo.example.test'],
        ]);

        $this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'siteurl'));
        $this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'home'));
        $this->assertSame('demo.example.test', $GLOBALS['rudel_test_sites'][(int) $app->blog_id]['domain'] ?? null);

        $bootstrap = file_get_contents($app->path . '/bootstrap.php');
        $this->assertIsString($bootstrap);
        $this->assertStringContainsString("\$_rudel_environment_url = 'http://demo.example.test';", $bootstrap);
        $this->assertStringContainsString("define('RUDEL_TABLE_PREFIX', 'wp_" . $app->blog_id . "_');", $bootstrap);

        $wpCli = file_get_contents($app->path . '/wp-cli.yml');
        $this->assertIsString($wpCli);
        $this->assertStringContainsString('url: http://demo.example.test/', $wpCli);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdatePreservesCloneSourceAndUsesEnvironmentLocalRuntimeContentPath(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $this->createFakeSandbox('alpha-site', 'Alpha Site', [
            'blog_id' => 2,
            'multisite' => true,
        ]);

        $manager = new EnvironmentManager(
            $this->tmpDir,
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $updated = $manager->update('alpha-site', [
            'clone_source' => [
                'git_remote' => 'https://example.test/example-theme.git',
                'git_dir' => 'themes/example-theme',
            ],
        ]);

        $this->assertSame('https://example.test/example-theme.git', $updated->clone_source['git_remote']);
        $this->assertSame('themes/example-theme', $updated->clone_source['git_dir']);
        $this->assertSame($updated->path . '/wp-content', $updated->get_runtime_wp_content_path());
        $this->assertSame(
            $updated->path . '/wp-content/themes/example-theme',
            $updated->get_runtime_content_path('themes/example-theme')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSubsiteReplacesFreshSiteTablesWithSourceState(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $this->createFakeSandbox('alpha-site', 'Alpha Site', [
            'blog_id' => 2,
            'multisite' => true,
        ]);

        $GLOBALS['rudel_test_sites'][2] = [
            'blog_id' => 2,
            'domain' => 'alpha-site.example.test',
            'path' => '/',
            'siteurl' => 'http://alpha-site.example.test/',
            'home' => 'http://alpha-site.example.test/',
            'title' => 'Alpha Site',
        ];
        $GLOBALS['rudel_test_next_blog_id'] = 3;

        $GLOBALS['wpdb']->addTable('wp_2_posts', 'CREATE TABLE `wp_2_posts` ( `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `post_title` text, PRIMARY KEY (`ID`) )', [
            ['ID' => 1, 'post_title' => 'Source Post'],
        ]);
        $GLOBALS['wpdb']->addTable('wp_2_options', 'CREATE TABLE `wp_2_options` ( `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL, `option_value` longtext NOT NULL, `autoload` varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (`option_id`) )', [
            ['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://alpha-site.example.test/', 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://alpha-site.example.test/', 'autoload' => 'yes'],
            ['option_id' => 3, 'option_name' => 'blogname', 'option_value' => 'Alpha Site', 'autoload' => 'yes'],
        ]);

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $cloned = $manager->create('Beta Site', [
            'clone_from' => 'alpha-site',
        ]);

        $this->assertNotNull($cloned->blog_id);
        $this->assertSame('Source Post', $GLOBALS['wpdb']->getTableRows('wp_' . $cloned->blog_id . '_posts')[0]['post_title'] ?? null);
        $this->assertSame(
            rtrim('http://' . $cloned->id . '.example.test/', '/'),
            $this->siteOptionValue((int) $cloned->blog_id, 'siteurl')
        );
        $this->assertSame(
            rtrim('http://' . $cloned->id . '.example.test/', '/'),
            $this->siteOptionValue((int) $cloned->blog_id, 'home')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateUsesCanonicalSubdomainUrlWhenNetworkRunsOnALocalPort(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://localhost:9888');
        define('DOMAIN_CURRENT_SITE', 'localhost:9888');

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $environment = $manager->create('Gamma Site');

        $this->assertSame('http://' . $environment->id . '.localhost:9888/', $environment->get_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateProvisionedIsolatedUserTablesForTheEnvironment(): void
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

        $environment = $manager->create('User Scope Site');

        $this->assertSame('wp_rudel_env_' . $environment->blog_id . '_users', $environment->get_users_table());
        $this->assertSame('wp_rudel_env_' . $environment->blog_id . '_usermeta', $environment->get_usermeta_table());
        $this->assertTrue($GLOBALS['wpdb']->hasTable((string) $environment->get_users_table()));
        $this->assertTrue($GLOBALS['wpdb']->hasTable((string) $environment->get_usermeta_table()));
    }
}
