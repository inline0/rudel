<?php

namespace Rudel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for Rudel tests.
 *
 * Provides temp directory management and helper utilities.
 */
abstract class RudelTestCase extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rudel_test_actions'] = [];
        $GLOBALS['rudel_test_filters'] = [];
        $this->tmpDir = RUDEL_TEST_TMPDIR . '/' . uniqid('test-');
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    /**
     * Create a fake sandbox directory with a .rudel.json file.
     */
    protected function createFakeSandbox(string $id, string $name = 'test', array $extraMeta = []): string
    {
        $path = $this->tmpDir . '/' . $id;
        mkdir($path, 0755, true);
        mkdir($path . '/wp-content', 0755, true);
        mkdir($path . '/wp-content/plugins', 0755);
        mkdir($path . '/wp-content/themes', 0755);
        mkdir($path . '/wp-content/uploads', 0755);
        mkdir($path . '/wp-content/mu-plugins', 0755);
        mkdir($path . '/tmp', 0755);

        $meta = array_merge([
            'id' => $id,
            'name' => $name,
            'path' => $path,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'template' => 'blank',
            'status' => 'active',
        ], $extraMeta);

        file_put_contents($path . '/.rudel.json', json_encode($meta, JSON_PRETTY_PRINT));
        return $path;
    }

    /**
     * Create a minimal wp-config.php file.
     */
    protected function createWpConfig(string $dir, ?string $content = null): string
    {
        $path = $dir . '/wp-config.php';
        $content ??= "<?php\n// Test wp-config.php\ndefine('DB_NAME', 'test');\nrequire_once __DIR__ . '/wp-settings.php';\n";
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Write a file with known content for size testing.
     */
    protected function createFileWithSize(string $path, int $bytes): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, str_repeat('x', $bytes));
    }
}
