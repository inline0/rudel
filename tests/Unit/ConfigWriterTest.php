<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\ConfigWriter;
use Rudel\Tests\RudelTestCase;

class ConfigWriterTest extends RudelTestCase
{
    /**
     * ConfigWriter relies on ABSPATH and RUDEL_PLUGIN_FILE constants.
     * Each test runs in a separate process so constants can be defined fresh.
     */

    // install()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallInjectsBootstrapLine(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();

        $contents = file_get_contents($configPath);
        $this->assertStringContainsString('// Rudel sandbox bootstrap', $contents);
        $this->assertStringContainsString("require_once", $contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallIsIdempotent(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();
        $after_first = file_get_contents($configPath);

        $writer->install();
        $after_second = file_get_contents($configPath);

        $this->assertSame($after_first, $after_second);

        // Only one occurrence of the marker
        $this->assertSame(
            1,
            substr_count($after_second, '// Rudel sandbox bootstrap')
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallCreatesBackup(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();

        $backups = glob($this->tmpDir . '/wp-config.php.rudel-backup-*');
        $this->assertNotEmpty($backups);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallThrowsForNonWritableFile(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);
        chmod($configPath, 0444);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not writable');

        try {
            $writer->install();
        } finally {
            chmod($configPath, 0644);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallInjectsAfterPhpTag(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir, "<?php\n\$x = 1;\n");

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();

        $lines = file($configPath);
        // First line should be <?php, second should be the require_once
        $this->assertStringStartsWith('<?php', $lines[0]);
        $this->assertStringContainsString('// Rudel sandbox bootstrap', $lines[1]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallHandlesWhitespaceAfterPhpTag(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir, "<?php   \n\$x = 1;\n");

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();

        $contents = file_get_contents($configPath);
        $this->assertStringContainsString('// Rudel sandbox bootstrap', $contents);
    }

    // uninstall()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallRemovesBootstrapLine(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();
        $this->assertTrue($writer->is_installed());

        $writer->uninstall();
        $this->assertFalse($writer->is_installed());

        $contents = file_get_contents($configPath);
        $this->assertStringNotContainsString('// Rudel sandbox bootstrap', $contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallIsNoOpWhenNotInstalled(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);
        $original = file_get_contents($configPath);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->uninstall();

        // File should be unchanged (no backup created either since not installed)
        $this->assertSame($original, file_get_contents($configPath));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallPreservesOtherContent(): void
    {
        $configPath = $this->createWpConfig(
            $this->tmpDir,
            "<?php\ndefine('DB_NAME', 'test');\ndefine('DB_USER', 'root');\n"
        );

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();
        $writer->uninstall();

        $contents = file_get_contents($configPath);
        $this->assertStringContainsString("define('DB_NAME', 'test');", $contents);
        $this->assertStringContainsString("define('DB_USER', 'root');", $contents);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallCreatesBackup(): void
    {
        $configPath = $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();

        // Delete the backup from install
        foreach (glob($this->tmpDir . '/wp-config.php.rudel-backup-*') as $f) {
            unlink($f);
        }

        $writer->uninstall();

        $backups = glob($this->tmpDir . '/wp-config.php.rudel-backup-*');
        $this->assertNotEmpty($backups, 'Uninstall should create a backup');
    }

    // isInstalled()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsInstalledReturnsFalseInitially(): void
    {
        $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $this->assertFalse($writer->is_installed());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsInstalledReturnsTrueAfterInstall(): void
    {
        $this->createWpConfig($this->tmpDir);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        $writer->install();
        $this->assertTrue($writer->is_installed());
    }

    // getConfigPath() edge cases

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetConfigPathFindsFileOneDirectoryUp(): void
    {
        // Simulate WP where wp-config.php is one level above ABSPATH
        $subDir = $this->tmpDir . '/public';
        mkdir($subDir);
        $this->createWpConfig($this->tmpDir); // Place in parent

        define('ABSPATH', $subDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();
        // Should not throw -- finds it one level up
        $writer->install();
        $this->assertTrue($writer->is_installed());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThrowsWhenNoConfigFound(): void
    {
        // Empty dir, no wp-config.php anywhere
        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not locate wp-config.php');
        $writer->is_installed();
    }

    // Full cycle

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFullInstallUninstallCycle(): void
    {
        $configPath = $this->createWpConfig(
            $this->tmpDir,
            "<?php\ndefine('DB_NAME', 'wordpress');\nrequire_once ABSPATH . 'wp-settings.php';\n"
        );
        $originalContent = file_get_contents($configPath);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_PLUGIN_FILE', $this->tmpDir . '/rudel.php');

        $writer = new ConfigWriter();

        // Initial state
        $this->assertFalse($writer->is_installed());

        // Install
        $writer->install();
        $this->assertTrue($writer->is_installed());

        // Verify the require line is present
        $installedContent = file_get_contents($configPath);
        $this->assertStringContainsString('require_once', $installedContent);
        $this->assertStringContainsString('bootstrap.php', $installedContent);

        // Uninstall
        $writer->uninstall();
        $this->assertFalse($writer->is_installed());

        // Original content should be preserved (minus any whitespace changes from the regex)
        $finalContent = file_get_contents($configPath);
        $this->assertStringContainsString("define('DB_NAME', 'wordpress');", $finalContent);
        $this->assertStringNotContainsString('// Rudel sandbox bootstrap', $finalContent);
    }
}
