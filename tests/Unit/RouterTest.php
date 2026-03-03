<?php

namespace Rudel\Tests\Unit;

use Rudel\Router;
use Rudel\Tests\RudelTestCase;

class RouterTest extends RudelTestCase
{
    private array $originalServer;
    private array $originalCookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalCookie = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_COOKIE = $this->originalCookie;
        parent::tearDown();
    }

    private function makeRouter(): Router
    {
        return new Router($this->tmpDir);
    }

    // Constructor

    public function testConstructorWithExplicitDir(): void
    {
        $router = new Router('/custom/path');
        $this->assertSame('/custom/path', $router->get_sandboxes_dir());
    }

    // resolveFromHeader()

    public function testResolveFromHeaderFindsValidSandbox(): void
    {
        $this->createFakeSandbox('header-test');
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'header-test';

        $router = $this->makeRouter();
        $this->assertSame('header-test', $router->resolve_from_header());
    }

    public function testResolveFromHeaderReturnsNullWhenMissing(): void
    {
        unset($_SERVER['HTTP_X_RUDEL_SANDBOX']);
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_header());
    }

    public function testResolveFromHeaderReturnsNullForInvalidId(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '../etc/passwd';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_header());
    }

    public function testResolveFromHeaderReturnsNullWhenDirNotExists(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'nonexistent-sandbox';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_header());
    }

    // resolveFromCookie()

    public function testResolveFromCookieFindsValidSandbox(): void
    {
        $this->createFakeSandbox('cookie-test');
        $_COOKIE['rudel_sandbox'] = 'cookie-test';

        $router = $this->makeRouter();
        $this->assertSame('cookie-test', $router->resolve_from_cookie());
    }

    public function testResolveFromCookieReturnsNullWhenMissing(): void
    {
        unset($_COOKIE['rudel_sandbox']);
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_cookie());
    }

    public function testResolveFromCookieReturnsNullForInvalidId(): void
    {
        $_COOKIE['rudel_sandbox'] = '.hidden';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_cookie());
    }

    // resolveFromPathPrefix()

    public function testResolveFromPathPrefixFindsValidSandbox(): void
    {
        $this->createFakeSandbox('pathtest-abc');
        $_SERVER['REQUEST_URI'] = '/__rudel/pathtest-abc/wp-admin/';

        $router = $this->makeRouter();
        $this->assertSame('pathtest-abc', $router->resolve_from_path_prefix());
    }

    public function testResolveFromPathPrefixWorksWithoutTrailingSlash(): void
    {
        $this->createFakeSandbox('noslash-test');
        $_SERVER['REQUEST_URI'] = '/__rudel/noslash-test';

        $router = $this->makeRouter();
        $this->assertSame('noslash-test', $router->resolve_from_path_prefix());
    }

    public function testResolveFromPathPrefixReturnsNullForNonRudelPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_path_prefix());
    }

    public function testResolveFromPathPrefixReturnsNullForEmptyUri(): void
    {
        $_SERVER['REQUEST_URI'] = '';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_path_prefix());
    }

    public function testResolveFromPathPrefixRejectsInvalidId(): void
    {
        $_SERVER['REQUEST_URI'] = '/__rudel/../etc/passwd/';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_path_prefix());
    }

    // resolveFromSubdomain()

    public function testResolveFromSubdomainFindsValidSandbox(): void
    {
        $this->createFakeSandbox('mysandbox');
        $_SERVER['HTTP_HOST'] = 'mysandbox.example.com';

        $router = $this->makeRouter();
        $this->assertSame('mysandbox', $router->resolve_from_subdomain());
    }

    public function testResolveFromSubdomainReturnsNullForTwoPartHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_subdomain());
    }

    public function testResolveFromSubdomainReturnsNullForEmptyHost(): void
    {
        $_SERVER['HTTP_HOST'] = '';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_subdomain());
    }

    public function testResolveFromSubdomainReturnsNullForMissingHost(): void
    {
        unset($_SERVER['HTTP_HOST']);
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_subdomain());
    }

    public function testResolveFromSubdomainReturnsNullWhenSandboxNotOnDisk(): void
    {
        $_SERVER['HTTP_HOST'] = 'ghostsandbox.example.com';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_subdomain());
    }

    // resolveFromCli()

    public function testResolveFromCliParsesPathPrefixUrl(): void
    {
        $this->createFakeSandbox('cli-sandbox');
        $GLOBALS['argv'] = ['wp', '--url=http://localhost/__rudel/cli-sandbox/'];

        $router = $this->makeRouter();
        $this->assertSame('cli-sandbox', $router->resolve_from_cli());
    }

    public function testResolveFromCliParsesSubdomainUrl(): void
    {
        $this->createFakeSandbox('subsandbox');
        $GLOBALS['argv'] = ['wp', '--url=https://subsandbox.example.com'];

        $router = $this->makeRouter();
        $this->assertSame('subsandbox', $router->resolve_from_cli());
    }

    public function testResolveFromCliReturnsNullWithNoUrlArg(): void
    {
        $GLOBALS['argv'] = ['wp', 'post', 'list'];

        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_cli());
    }

    public function testResolveFromCliReturnsNullWithEmptyArgv(): void
    {
        $GLOBALS['argv'] = [];

        $router = $this->makeRouter();
        $this->assertNull($router->resolve_from_cli());
    }

    // resolve() -- priority order

    public function testResolveHeaderWinsOverCookie(): void
    {
        $this->createFakeSandbox('from-header');
        $this->createFakeSandbox('from-cookie');

        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'from-header';
        $_COOKIE['rudel_sandbox'] = 'from-cookie';

        $router = $this->makeRouter();
        $this->assertSame('from-header', $router->resolve());
    }

    public function testResolveCookieWinsOverPathPrefix(): void
    {
        $this->createFakeSandbox('from-cookie');
        $this->createFakeSandbox('from-path');

        unset($_SERVER['HTTP_X_RUDEL_SANDBOX']);
        $_COOKIE['rudel_sandbox'] = 'from-cookie';
        $_SERVER['REQUEST_URI'] = '/__rudel/from-path/';

        $router = $this->makeRouter();
        $this->assertSame('from-cookie', $router->resolve());
    }

    public function testResolveFallsThroughToPathPrefix(): void
    {
        $this->createFakeSandbox('from-path');

        unset($_SERVER['HTTP_X_RUDEL_SANDBOX']);
        unset($_COOKIE['rudel_sandbox']);
        $_SERVER['REQUEST_URI'] = '/__rudel/from-path/';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $router = $this->makeRouter();
        $this->assertSame('from-path', $router->resolve());
    }

    public function testResolveReturnsNullWhenNothingMatches(): void
    {
        unset($_SERVER['HTTP_X_RUDEL_SANDBOX']);
        unset($_COOKIE['rudel_sandbox']);
        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $_SERVER['HTTP_HOST'] = 'example.com';

        $router = $this->makeRouter();
        $this->assertNull($router->resolve());
    }

    // Path traversal prevention

    public function testResolveRejectsPathTraversalDotDot(): void
    {
        // Even if we create a sandbox with a normal id, try to access via traversal
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '..';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve());
    }

    public function testResolveRejectsPathTraversalInHeader(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '../../../etc';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve());
    }

    public function testResolveRejectsIdWithSlash(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'abc/def';
        $router = $this->makeRouter();
        $this->assertNull($router->resolve());
    }

    public function testSymlinkEscapeIsBlocked(): void
    {
        // Create a sandbox dir that is actually a symlink to /tmp
        $target = sys_get_temp_dir();
        $link = $this->tmpDir . '/symlink-escape';
        symlink($target, $link);

        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'symlink-escape';
        $router = $this->makeRouter();

        // The symlink resolves outside the sandboxes dir, so should be rejected
        $this->assertNull($router->resolve());
    }
}
