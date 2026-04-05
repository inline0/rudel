<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class RuntimeMuPluginTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRuntimeHooksOverrideHostDefinedSiteUrls(): void
    {
        eval(<<<'PHP'
namespace {
    if (! function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_filter_callbacks'][$hook][] = $callback;
        }
    }
    if (! function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {
            $GLOBALS['rudel_test_action_callbacks'][$hook][] = $callback;
        }
    }
}
PHP);

        define('ABSPATH', $this->tmpDir . '/');
        define('RUDEL_ID', 'runtime-box');
        define('RUDEL_ENVIRONMENT_URL', 'http://localhost:8888/__rudel/runtime-box');
        define('RUDEL_IS_PREVIEW', true);
        define('RUDEL_BOOTSTRAP_PLUGIN_DIR', dirname(__DIR__, 2));
        define('WP_HOME', 'http://localhost:8888');
        define('WP_SITEURL', 'http://localhost:8888');

        require dirname(__DIR__, 2) . '/templates/runtime-mu-plugin.php.tpl';

        $this->assertSame('http://localhost:8888/__rudel/runtime-box', apply_filters('pre_option_home', false));
        $this->assertSame('http://localhost:8888/__rudel/runtime-box', apply_filters('pre_option_siteurl', false));
        $this->assertArrayNotHasKey('parse_request', $GLOBALS['rudel_test_action_callbacks'] ?? []);
        $this->assertTrue(function_exists('rudel_runtime_preview_resolve'));
        $this->assertTrue(function_exists('rudel_runtime_preview_prepare_php_request'));
    }
}
