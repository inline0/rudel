<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxInheritsTrackedGithubMetadataFromApp(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $manager = new AppManager(
            $this->tmpDir . '/apps',
            $this->tmpDir . '/sandboxes'
        );

        $app = $manager->create('Client Demo', ['client.example.test'], [
            'tracked_github_repo' => 'inline0/client-theme',
            'tracked_github_branch' => 'release',
            'tracked_github_dir' => 'themes/client-theme',
        ]);

        $sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');

        $this->assertSame('inline0/client-theme', $sandbox->tracked_github_repo);
        $this->assertSame('release', $sandbox->tracked_github_branch);
        $this->assertSame('themes/client-theme', $sandbox->tracked_github_dir);
        $this->assertSame('inline0/client-theme', $sandbox->get_github_repo());
        $this->assertSame('release', $sandbox->get_github_base_branch());
        $this->assertSame('themes/client-theme', $sandbox->get_github_dir());
    }
}
