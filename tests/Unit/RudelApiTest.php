<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Rudel;
use Rudel\Tests\RudelTestCase;

class RudelApiTest extends RudelTestCase
{
    // Context: not in sandbox

    public function testIsSandboxReturnsFalseOutsideSandbox(): void
    {
        $this->assertFalse(Rudel::is_sandbox());
    }

    public function testIdReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::id());
    }

    public function testPathReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::path());
    }

    public function testEngineReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::engine());
    }

    public function testTablePrefixReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::table_prefix());
    }

    public function testUrlReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::url());
    }

    public function testLogPathReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::log_path());
    }

    public function testIsEmailDisabledReturnsFalseOutsideSandbox(): void
    {
        $this->assertFalse(Rudel::is_email_disabled());
    }

    public function testExitUrlWorksOutsideSandbox(): void
    {
        $this->assertSame('/?adminExit', Rudel::exit_url());
    }

    public function testContextReturnsArrayOutsideSandbox(): void
    {
        $ctx = Rudel::context();
        $this->assertFalse($ctx['is_sandbox']);
        $this->assertNull($ctx['id']);
        $this->assertNull($ctx['engine']);
    }

    // Context: in sandbox

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsSandboxReturnsTrueInSandbox(): void
    {
        define('RUDEL_ID', 'test-sandbox');
        define('RUDEL_PATH', '/tmp/test-sandbox');
        $this->assertTrue(Rudel::is_sandbox());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIdReturnsSandboxId(): void
    {
        define('RUDEL_ID', 'my-box-1234');
        $this->assertSame('my-box-1234', Rudel::id());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPathReturnsSandboxPath(): void
    {
        define('RUDEL_PATH', '/var/sandboxes/my-box');
        $this->assertSame('/var/sandboxes/my-box', Rudel::path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEngineReadsFromMetadata(): void
    {
        $path = $this->tmpDir . '/engine-test';
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode(['engine' => 'sqlite']));

        define('RUDEL_ID', 'engine-test');
        define('RUDEL_PATH', $path);

        $this->assertSame('sqlite', Rudel::engine());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEngineDefaultsToMysql(): void
    {
        $path = $this->tmpDir . '/no-engine';
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode(['id' => 'no-engine']));

        define('RUDEL_ID', 'no-engine');
        define('RUDEL_PATH', $path);

        $this->assertSame('mysql', Rudel::engine());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTablePrefixReturnsConstant(): void
    {
        define('RUDEL_TABLE_PREFIX', 'rudel_abc123_');
        $this->assertSame('rudel_abc123_', Rudel::table_prefix());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUrlBuildsFromConstants(): void
    {
        define('RUDEL_ID', 'url-test');
        if (! defined('RUDEL_PATH_PREFIX')) {
            define('RUDEL_PATH_PREFIX', '__rudel');
        }
        define('WP_HOME', 'https://example.com');

        $this->assertSame('https://example.com/__rudel/url-test/', Rudel::url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExitUrlWithWpHome(): void
    {
        define('WP_HOME', 'https://example.com');
        $this->assertSame('https://example.com/?adminExit', Rudel::exit_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsEmailDisabledWhenConstantSet(): void
    {
        define('RUDEL_ID', 'email-test');
        define('RUDEL_DISABLE_EMAIL', true);
        $this->assertTrue(Rudel::is_email_disabled());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogPathInSandbox(): void
    {
        define('RUDEL_PATH', '/var/sandboxes/log-test');
        define('RUDEL_ID', 'log-test');
        $this->assertSame('/var/sandboxes/log-test/wp-content/debug.log', Rudel::log_path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextReturnsFullStateInSandbox(): void
    {
        define('RUDEL_ID', 'ctx-test');
        define('RUDEL_PATH', '/tmp/ctx-test');
        define('RUDEL_TABLE_PREFIX', 'rudel_abc_');
        define('RUDEL_VERSION', '0.1.0');

        $ctx = Rudel::context();
        $this->assertTrue($ctx['is_sandbox']);
        $this->assertSame('ctx-test', $ctx['id']);
        $this->assertSame('/tmp/ctx-test', $ctx['path']);
        $this->assertSame('rudel_abc_', $ctx['table_prefix']);
        $this->assertSame('0.1.0', $ctx['version']);
    }

    // Static helpers

    public function testVersionReturnsValue(): void
    {
        // RUDEL_VERSION is defined in rudel.php which may not be loaded in tests.
        $version = Rudel::version();
        if (defined('RUDEL_VERSION')) {
            $this->assertSame(RUDEL_VERSION, $version);
        } else {
            $this->assertNull($version);
        }
    }

    public function testCliCommandReturnsDefault(): void
    {
        $this->assertSame('rudel', Rudel::cli_command());
    }

    public function testPathPrefixReturnsDefault(): void
    {
        $this->assertSame('__rudel', Rudel::path_prefix());
    }

    // Management: list/get

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllReturnsEmptyWhenNoSandboxes(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        $this->assertSame([], Rudel::all());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsNullForNonexistent(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        $this->assertNull(Rudel::get('nonexistent'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAndGetSandbox(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $sandbox = Rudel::create('API Test', ['engine' => 'sqlite']);
        $this->assertNotNull($sandbox);
        $this->assertSame('API Test', $sandbox->name);

        $found = Rudel::get($sandbox->id);
        $this->assertNotNull($found);
        $this->assertSame($sandbox->id, $found->id);

        $all = Rudel::all();
        $this->assertCount(1, $all);

        $this->assertTrue(Rudel::destroy($sandbox->id));
        $this->assertNull(Rudel::get($sandbox->id));
    }
}
