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
        $this->assertStringContainsString("define('WP_HOME', \$_rudel_environment_url);", $bootstrap);

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
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdatePreservesCloneSourceAndUsesSharedRuntimeContentPath(): void
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
                'github_repo' => 'inline0/example-theme',
                'github_dir' => 'themes/example-theme',
            ],
        ]);

        $this->assertSame('inline0/example-theme', $updated->clone_source['github_repo']);
        $this->assertSame('themes/example-theme', $updated->clone_source['github_dir']);
        $this->assertSame($wordpressRoot . '/wp-content', $updated->get_runtime_wp_content_path());
        $this->assertSame(
            $wordpressRoot . '/wp-content/themes/example-theme',
            $updated->get_runtime_content_path('themes/example-theme')
        );
    }
}
