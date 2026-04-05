<?php

namespace Rudel\Tests\Unit;

use Rudel\PreviewRequest;
use Rudel\PreviewRequestRouter;
use Rudel\Tests\RudelTestCase;

class PreviewRequestTest extends RudelTestCase
{
    public function testBasePathBuildsCanonicalPreviewPrefix(): void
    {
        $this->assertSame('/__rudel/alpha-box/', PreviewRequest::base_path('alpha-box', '__rudel'));
    }

    public function testExtractEnvironmentIdReadsPrefixedPathFromAbsoluteUrl(): void
    {
        $id = PreviewRequest::extract_environment_id(
            'https://example.com/__rudel/my-app/wp-admin/post.php?post=1',
            '__rudel'
        );

        $this->assertSame('my-app', $id);
    }

    public function testStripPrefixPreservesInnerAdminPath(): void
    {
        $stripped = PreviewRequest::strip_prefix('/__rudel/my-app/wp-admin/post.php?post=1', 'my-app', '__rudel');

        $this->assertSame('/wp-admin/post.php?post=1', $stripped);
    }

    public function testRouterResolvesAdminIndexPhp(): void
    {
        $resolved = PreviewRequestRouter::resolve('/wp-admin/', '/var/www/html/', '/var/www/html/wp-content');

        $this->assertSame(
            [
                'type' => 'php',
                'path' => '/var/www/html/wp-admin/index.php',
                'request_path' => '/wp-admin/',
            ],
            $resolved
        );
    }

    public function testRouterResolvesRootLoginEntrypoint(): void
    {
        $resolved = PreviewRequestRouter::resolve('/wp-login.php?redirect_to=%2Fwp-admin%2F', '/var/www/html/', '/var/www/html/wp-content');

        $this->assertSame(
            [
                'type' => 'php',
                'path' => '/var/www/html/wp-login.php',
                'request_path' => '/wp-login.php',
            ],
            $resolved
        );
    }

    public function testRouterResolvesWpContentStaticAssetsInsideEnvironment(): void
    {
        $contentDir = $this->tmpDir . '/wp-content';
        mkdir($contentDir . '/themes/demo', 0755, true);
        file_put_contents($contentDir . '/themes/demo/style.css', 'body{}');

        $resolved = PreviewRequestRouter::resolve('/wp-content/themes/demo/style.css', '/var/www/html/', $contentDir);

        $this->assertSame(
            [
                'type' => 'static',
                'path' => realpath($contentDir . '/themes/demo/style.css'),
                'request_path' => '/wp-content/themes/demo/style.css',
            ],
            $resolved
        );
    }

    public function testRouterRejectsTraversalOutsideWpContent(): void
    {
        $contentDir = $this->tmpDir . '/wp-content';
        mkdir($contentDir, 0755, true);

        $resolved = PreviewRequestRouter::resolve('/wp-content/../wp-config.php', '/var/www/html/', $contentDir);

        $this->assertNull($resolved);
    }
}
