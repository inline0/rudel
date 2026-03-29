<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Tests\RudelTestCase;

class RudelCommandTest extends RudelTestCase
{
    private function createCommand(): \Rudel\CLI\RudelCommand
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        \WP_CLI::reset();

        $manager = new \Rudel\EnvironmentManager($this->tmpDir);
        return new \Rudel\CLI\RudelCommand($manager);
    }

    // create()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateOutputsSuccessWithSandboxId(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Test Create', 'template' => 'blank', 'engine' => 'sqlite']);

        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString('Sandbox created:', \WP_CLI::$successes[0]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateLogsPathAndUrl(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Path Test', 'template' => 'blank', 'engine' => 'sqlite']);

        $logMessages = array_filter(\WP_CLI::$log, fn($m) => is_string($m));
        $combined = implode("\n", $logMessages);
        $this->assertStringContainsString('Path:', $combined);
        $this->assertStringContainsString('URL:', $combined);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatePassesTemplateOption(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Template Test', 'template' => 'custom', 'engine' => 'sqlite']);

        $this->assertCount(1, \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDefaultsTemplateToBlank(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Default Template', 'engine' => 'sqlite']);

        $this->assertCount(1, \WP_CLI::$successes);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateCallsErrorOnException(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        $manager = new class extends \Rudel\EnvironmentManager {
            public function __construct() {
                // Skip parent constructor
            }
            public function create( string $name, array $options = array() ): \Rudel\Environment {
                throw new \RuntimeException( 'Boom' );
            }
        };

        \WP_CLI::reset();
        $cmd = new \Rudel\CLI\RudelCommand($manager);

        $this->expectException(\RuntimeException::class);
        $cmd->create([], ['name' => 'Will Fail', 'engine' => 'sqlite']);
    }

    // list_()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListWithSandboxesCallsFormatItems(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'List Item', 'engine' => 'sqlite']);
        \WP_CLI::reset();

        $cmd->list_([], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $this->assertCount(1, $formatCalls);

        $call = array_values($formatCalls)[0];
        $this->assertSame(['id', 'name', 'engine', 'status', 'template', 'created', 'size'], $call['fields']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListEmptyLogsNoSandboxesFound(): void
    {
        $cmd = $this->createCommand();
        mkdir($this->tmpDir . '/empty', 0755);

        $cmd->list_([], []);

        $logMessages = array_filter(\WP_CLI::$log, fn($m) => is_string($m));
        $this->assertContains('No sandboxes found.', $logMessages);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListRespectsFormatArgument(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Format Test', 'engine' => 'sqlite']);
        \WP_CLI::reset();

        $cmd->list_([], ['format' => 'json']);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $call = array_values($formatCalls)[0];
        $this->assertSame('json', $call['format']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListMapsSizeField(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Size Test', 'engine' => 'sqlite']);
        \WP_CLI::reset();

        $cmd->list_([], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $call = array_values($formatCalls)[0];
        $item = $call['items'][0];
        $this->assertArrayHasKey('size', $item);
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)? (B|KB|MB|GB)$/', $item['size']);
    }

    // info()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInfoDisplaysSandboxDataAsTable(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Info Test', 'engine' => 'sqlite']);
        $id = str_replace('Sandbox created: ', '', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->info([$id], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $this->assertCount(1, $formatCalls);

        $call = array_values($formatCalls)[0];
        $this->assertSame(['Field', 'Value'], $call['fields']);

        $fieldNames = array_column($call['items'], 'Field');
        $this->assertContains('id', $fieldNames);
        $this->assertContains('url', $fieldNames);
        $this->assertContains('size', $fieldNames);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInfoWithJsonFormat(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Json Info', 'engine' => 'sqlite']);
        $id = str_replace('Sandbox created: ', '', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->info([$id], ['format' => 'json']);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $call = array_values($formatCalls)[0];
        $this->assertSame('json', $call['format']);
        $this->assertContains('id', $call['fields']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInfoNonexistentCallsError(): void
    {
        $cmd = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found: ghost-id');
        $cmd->info(['ghost-id'], []);
    }

    // destroy()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyWithForceSucceeds(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Destroy Force', 'engine' => 'sqlite']);
        $id = str_replace('Sandbox created: ', '', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->destroy([$id], ['force' => true]);

        $this->assertCount(1, \WP_CLI::$successes);
        $this->assertStringContainsString($id, \WP_CLI::$successes[0]);
        $this->assertEmpty(\WP_CLI::$confirmations);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyWithoutForceCallsConfirm(): void
    {
        $cmd = $this->createCommand();
        $cmd->create([], ['name' => 'Destroy Confirm', 'engine' => 'sqlite']);
        $id = str_replace('Sandbox created: ', '', \WP_CLI::$successes[0]);
        \WP_CLI::reset();

        $cmd->destroy([$id], []);

        $this->assertNotEmpty(\WP_CLI::$confirmations);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyNonexistentCallsError(): void
    {
        $cmd = $this->createCommand();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found: nonexistent');
        $cmd->destroy(['nonexistent'], ['force' => true]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyFailureCallsError(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        $manager = new class extends \Rudel\EnvironmentManager {
            public function __construct() {
                // Skip parent constructor
            }
            public function get( string $id ): ?\Rudel\Environment {
                return new \Rudel\Environment( 'fail-box', 'Fail', '/tmp/fail', '2026-01-01' );
            }
            public function destroy( string $id ): bool {
                return false;
            }
        };

        \WP_CLI::reset();
        $cmd = new \Rudel\CLI\RudelCommand($manager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to destroy sandbox: fail-box');
        $cmd->destroy(['fail-box'], ['force' => true]);
    }

    // status()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStatusDisplaysConfigurationInfo(): void
    {
        $cmd = $this->createCommand();

        // status() needs ABSPATH for ConfigWriter
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->tmpDir . '/');
        }
        $this->createWpConfig($this->tmpDir);

        $cmd->status([], []);

        $formatCalls = array_filter(\WP_CLI::$log, fn($m) => is_array($m) && ($m['__format_items'] ?? false));
        $this->assertCount(1, $formatCalls);

        $call = array_values($formatCalls)[0];
        $fieldNames = array_column($call['items'], 'Field');
        $this->assertContains('Bootstrap installed', $fieldNames);
        $this->assertContains('Sandboxes directory', $fieldNames);
        $this->assertContains('Active sandboxes', $fieldNames);
        $this->assertContains('SQLite integration', $fieldNames);
        $this->assertContains('PHP version', $fieldNames);
        $this->assertContains('SQLite3 extension', $fieldNames);
        $this->assertContains('PDO SQLite', $fieldNames);
    }

    // format_size() via Reflection

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeZeroBytes(): void
    {
        $this->assertSame('0 B', $this->callFormatSize(0));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeBytes(): void
    {
        $this->assertSame('500 B', $this->callFormatSize(500));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeKilobytes(): void
    {
        $this->assertSame('1 KB', $this->callFormatSize(1024));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeMegabytes(): void
    {
        $this->assertSame('1 MB', $this->callFormatSize(1048576));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeGigabytes(): void
    {
        $this->assertSame('1 GB', $this->callFormatSize(1073741824));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeFractionalKilobytes(): void
    {
        $this->assertSame('1.5 KB', $this->callFormatSize(1536));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormatSizeLargeValueStaysInGB(): void
    {
        $this->assertSame('1024 GB', $this->callFormatSize(1099511627776));
    }

    private function callFormatSize(int $bytes): string
    {
        $cmd = $this->createCommand();
        $ref = new \ReflectionMethod($cmd, 'format_size');
        $ref->setAccessible(true);
        return $ref->invoke($cmd, $bytes);
    }
}
