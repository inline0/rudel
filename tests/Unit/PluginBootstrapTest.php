<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class PluginBootstrapTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPluginRegistersAppCommand(): void
    {
        require_once dirname(__DIR__) . '/Stubs/wp-cli-stubs.php';

        eval(<<<'PHP'
namespace {
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
        function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
    }
    if (! function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): void {}
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

        $this->assertArrayHasKey('rudel', \WP_CLI::$commands);
        $this->assertArrayHasKey('rudel app', \WP_CLI::$commands);
        $this->assertArrayHasKey('rudel template', \WP_CLI::$commands);
    }
}
