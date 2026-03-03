<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\ContentCloner;
use Rudel\Tests\RudelTestCase;

class ContentClonerTest extends RudelTestCase
{
    private ContentCloner $cloner;
    private string $hostWpContent;
    private string $sandboxWpContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloner = new ContentCloner();
        $this->hostWpContent = $this->tmpDir . '/host-wp-content';
        $this->sandboxWpContent = $this->tmpDir . '/sandbox-wp-content';

        mkdir($this->hostWpContent, 0755, true);
        mkdir($this->sandboxWpContent . '/themes', 0755, true);
        mkdir($this->sandboxWpContent . '/plugins', 0755, true);
        mkdir($this->sandboxWpContent . '/uploads', 0755, true);
    }

    // copy_directory()

    public function testCopyDirectoryCreatesTargetStructure(): void
    {
        $source = $this->hostWpContent . '/test-source';
        mkdir($source . '/subdir', 0755, true);
        file_put_contents($source . '/file.txt', 'hello');
        file_put_contents($source . '/subdir/nested.txt', 'world');

        $target = $this->tmpDir . '/copy-target';
        $this->cloner->copy_directory($source, $target);

        $this->assertDirectoryExists($target);
        $this->assertDirectoryExists($target . '/subdir');
        $this->assertFileExists($target . '/file.txt');
        $this->assertFileExists($target . '/subdir/nested.txt');
        $this->assertSame('hello', file_get_contents($target . '/file.txt'));
        $this->assertSame('world', file_get_contents($target . '/subdir/nested.txt'));
    }

    public function testCopyDirectoryPreservesDeeplyNestedStructure(): void
    {
        $source = $this->hostWpContent . '/deep-source';
        mkdir($source . '/a/b/c/d', 0755, true);
        file_put_contents($source . '/a/b/c/d/deep.txt', 'deep content');

        $target = $this->tmpDir . '/deep-target';
        $this->cloner->copy_directory($source, $target);

        $this->assertFileExists($target . '/a/b/c/d/deep.txt');
        $this->assertSame('deep content', file_get_contents($target . '/a/b/c/d/deep.txt'));
    }

    public function testCopyDirectoryHandlesEmptyDirectory(): void
    {
        $source = $this->hostWpContent . '/empty-source';
        mkdir($source, 0755);

        $target = $this->tmpDir . '/empty-target';
        $this->cloner->copy_directory($source, $target);

        $this->assertDirectoryExists($target);
        // Should just be . and .. entries.
        $this->assertSame(['.', '..'], scandir($target));
    }

    public function testCopyDirectoryHandlesMultipleFiles(): void
    {
        $source = $this->hostWpContent . '/multi-source';
        mkdir($source, 0755);
        for ($i = 0; $i < 5; $i++) {
            file_put_contents($source . "/file{$i}.txt", "content {$i}");
        }

        $target = $this->tmpDir . '/multi-target';
        $this->cloner->copy_directory($source, $target);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFileExists($target . "/file{$i}.txt");
            $this->assertSame("content {$i}", file_get_contents($target . "/file{$i}.txt"));
        }
    }

    // clone_content() -- selective cloning

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentCopiesThemesOnly(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/themes/twentytwentyfour', 0755, true);
        file_put_contents($this->hostWpContent . '/themes/twentytwentyfour/style.css', 'theme css');

        mkdir($this->hostWpContent . '/plugins/akismet', 0755, true);
        file_put_contents($this->hostWpContent . '/plugins/akismet/akismet.php', 'plugin');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => true,
            'plugins' => false,
            'uploads' => false,
        ]);

        $this->assertSame('copied', $result['themes']);
        $this->assertSame('skipped', $result['plugins']);
        $this->assertSame('skipped', $result['uploads']);
        $this->assertFileExists($this->sandboxWpContent . '/themes/twentytwentyfour/style.css');
        $this->assertFileDoesNotExist($this->sandboxWpContent . '/plugins/akismet/akismet.php');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentCopiesPluginsOnly(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/plugins/akismet', 0755, true);
        file_put_contents($this->hostWpContent . '/plugins/akismet/akismet.php', 'plugin code');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => false,
            'plugins' => true,
            'uploads' => false,
        ]);

        $this->assertSame('skipped', $result['themes']);
        $this->assertSame('copied', $result['plugins']);
        $this->assertSame('skipped', $result['uploads']);
        $this->assertFileExists($this->sandboxWpContent . '/plugins/akismet/akismet.php');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentCopiesUploadsOnly(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/uploads/2026/03', 0755, true);
        file_put_contents($this->hostWpContent . '/uploads/2026/03/photo.jpg', 'image data');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => false,
            'plugins' => false,
            'uploads' => true,
        ]);

        $this->assertSame('skipped', $result['themes']);
        $this->assertSame('skipped', $result['plugins']);
        $this->assertSame('copied', $result['uploads']);
        $this->assertFileExists($this->sandboxWpContent . '/uploads/2026/03/photo.jpg');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentCopiesAllThree(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/themes/theme1', 0755, true);
        file_put_contents($this->hostWpContent . '/themes/theme1/style.css', 'css');
        mkdir($this->hostWpContent . '/plugins/plugin1', 0755, true);
        file_put_contents($this->hostWpContent . '/plugins/plugin1/plugin.php', 'php');
        mkdir($this->hostWpContent . '/uploads/2026', 0755, true);
        file_put_contents($this->hostWpContent . '/uploads/2026/file.jpg', 'jpg');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => true,
            'plugins' => true,
            'uploads' => true,
        ]);

        $this->assertSame('copied', $result['themes']);
        $this->assertSame('copied', $result['plugins']);
        $this->assertSame('copied', $result['uploads']);
        $this->assertFileExists($this->sandboxWpContent . '/themes/theme1/style.css');
        $this->assertFileExists($this->sandboxWpContent . '/plugins/plugin1/plugin.php');
        $this->assertFileExists($this->sandboxWpContent . '/uploads/2026/file.jpg');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentSkipsAllWhenNothingRequested(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/themes/theme1', 0755, true);
        file_put_contents($this->hostWpContent . '/themes/theme1/style.css', 'css');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => false,
            'plugins' => false,
            'uploads' => false,
        ]);

        $this->assertSame('skipped', $result['themes']);
        $this->assertSame('skipped', $result['plugins']);
        $this->assertSame('skipped', $result['uploads']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentReportsMissingSourceDirectory(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);
        // No uploads directory exists on host.

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => false,
            'plugins' => false,
            'uploads' => true,
        ]);

        $this->assertSame('missing', $result['uploads']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentReplacesExistingEmptyDirectory(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        mkdir($this->hostWpContent . '/themes/new-theme', 0755, true);
        file_put_contents($this->hostWpContent . '/themes/new-theme/style.css', 'new css');

        // The sandbox already has an empty themes directory from scaffolding.
        $this->assertDirectoryExists($this->sandboxWpContent . '/themes');

        $result = $this->cloner->clone_content($this->sandboxWpContent, [
            'themes' => true,
            'plugins' => false,
            'uploads' => false,
        ]);

        $this->assertSame('copied', $result['themes']);
        $this->assertFileExists($this->sandboxWpContent . '/themes/new-theme/style.css');
        $this->assertSame('new css', file_get_contents($this->sandboxWpContent . '/themes/new-theme/style.css'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneContentDefaultsToSkippedForMissingOptions(): void
    {
        define('WP_CONTENT_DIR', $this->hostWpContent);

        $result = $this->cloner->clone_content($this->sandboxWpContent, []);

        $this->assertSame('skipped', $result['themes']);
        $this->assertSame('skipped', $result['plugins']);
        $this->assertSame('skipped', $result['uploads']);
    }
}
