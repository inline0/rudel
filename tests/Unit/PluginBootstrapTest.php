<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class PluginBootstrapTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginRegistersCliCommands(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        eval(<<<'PHP'
namespace {
    $GLOBALS['rudel_test_filters'] = [];
    $GLOBALS['rudel_test_actions'] = [];

    if (! function_exists('plugin_dir_path')) {
        function plugin_dir_path(string $file): string {
            return dirname($file) . '/';
        }
    }
    if (! function_exists('plugin_dir_url')) {
        function plugin_dir_url(string $file): string {
            return 'https://example.com/plugins/rudel/';
        }
    }
    if (! function_exists('register_activation_hook')) {
        function register_activation_hook(string $file, callable $callback): void {}
    }
    if (! function_exists('register_deactivation_hook')) {
        function register_deactivation_hook(string $file, callable $callback): void {}
    }
    if (! function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_filters'][$hook][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (! function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_actions'][$hook][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (! function_exists('esc_attr')) {
        function esc_attr(string $value): string {
            return $value;
        }
    }
}
PHP);

        define('ABSPATH', $this->tmpDir . '/');
        define('WP_CLI', true);

        \WP_CLI::reset();
        require dirname(__DIR__, 2) . '/rudel.php';

        $expected = [
            'rudel' => \Rudel\CLI\RudelCommand::class,
            'rudel app' => \Rudel\CLI\AppCommand::class,
            'rudel cleanup' => \Rudel\CLI\CleanupCommand::class,
            'rudel export' => \Rudel\CLI\ExportCommand::class,
            'rudel import' => \Rudel\CLI\ImportCommand::class,
            'rudel logs' => \Rudel\CLI\LogsCommand::class,
            'rudel pr' => \Rudel\CLI\PrCommand::class,
            'rudel promote' => \Rudel\CLI\PromoteCommand::class,
            'rudel push' => \Rudel\CLI\PushCommand::class,
            'rudel restore' => \Rudel\CLI\RestoreCommand::class,
            'rudel snapshot' => \Rudel\CLI\SnapshotCommand::class,
            'rudel template' => \Rudel\CLI\TemplateCommand::class,
        ];

        foreach ($expected as $command => $class) {
            $this->assertArrayHasKey($command, \WP_CLI::$commands);
            $this->assertSame($class, \WP_CLI::$commands[$command]);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginRegistersEmailBlockingFilterBeforeSandboxContextExists(): void
    {
        eval(<<<'PHP'
namespace {
    $GLOBALS['rudel_test_filters'] = [];
    $GLOBALS['rudel_test_actions'] = [];

    if (! function_exists('plugin_dir_path')) {
        function plugin_dir_path(string $file): string {
            return dirname($file) . '/';
        }
    }
    if (! function_exists('plugin_dir_url')) {
        function plugin_dir_url(string $file): string {
            return 'https://example.com/plugins/rudel/';
        }
    }
    if (! function_exists('register_activation_hook')) {
        function register_activation_hook(string $file, callable $callback): void {}
    }
    if (! function_exists('register_deactivation_hook')) {
        function register_deactivation_hook(string $file, callable $callback): void {}
    }
    if (! function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_filters'][$hook][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (! function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_actions'][$hook][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $accepted_args,
            ];
        }
    }
    if (! function_exists('esc_attr')) {
        function esc_attr(string $value): string {
            return $value;
        }
    }
}
PHP);

        define('ABSPATH', $this->tmpDir . '/');
        require dirname(__DIR__, 2) . '/rudel.php';

        $this->assertArrayHasKey('pre_wp_mail', $GLOBALS['rudel_test_filters']);
        $callback = $GLOBALS['rudel_test_filters']['pre_wp_mail'][0]['callback'];

        $this->assertNull($callback(null, ['to' => 'test@example.com', 'subject' => 'Before bootstrap']));

        define('RUDEL_ID', 'mail-box');
        define('RUDEL_IS_APP', false);
        define('RUDEL_DISABLE_EMAIL', true);

        $this->assertTrue($callback(null, ['to' => 'test@example.com', 'subject' => 'After bootstrap']));
    }
}
