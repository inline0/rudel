<?php

namespace Rudel\Tests\Integration;

use Rudel\Tests\RudelTestCase;

/**
 * Tests for bootstrap.php -- the pre-boot sandbox resolver.
 *
 * Each test runs in a separate process because bootstrap.php defines constants
 * (RUDEL_SANDBOX_ID, DB_DIR, etc.) that can only be defined once per process.
 *
 * We test bootstrap.php by including it in a controlled environment with
 * specific superglobal values set, then checking which constants were defined.
 */
class BootstrapTest extends RudelTestCase
{
    private string $bootstrapPath;
    private string $sandboxesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';
        $this->sandboxesDir = $this->tmpDir . '/rudel-sandboxes';
        mkdir($this->sandboxesDir, 0755, true);
    }

    /**
     * Run bootstrap.php in a child process with controlled environment.
     * Returns the output as JSON with the state of all relevant constants.
     */
    private function runBootstrap(array $serverVars = [], array $cookieVars = [], array $argv = [], array $extraDefines = []): array
    {
        $script = '<?php' . "\n";

        // Set superglobals
        $script .= '$_SERVER = ' . var_export(array_merge($_SERVER, $serverVars), true) . ";\n";
        $script .= '$_COOKIE = ' . var_export($cookieVars, true) . ";\n";

        if (! empty($argv)) {
            $script .= '$argv = ' . var_export($argv, true) . ";\n";
            $script .= '$GLOBALS["argv"] = $argv;' . "\n";
        }

        // Define WP_CONTENT_DIR so bootstrap can find sandboxes
        $script .= "define('WP_CONTENT_DIR', " . var_export($this->tmpDir, true) . ");\n";

        foreach ($extraDefines as $name => $value) {
            $script .= "define('{$name}', " . var_export($value, true) . ");\n";
        }

        // Include bootstrap
        $script .= "require " . var_export($this->bootstrapPath, true) . ";\n";

        // Output state
        $script .= 'echo json_encode([' . "\n";
        $script .= '  "sandbox_id" => defined("RUDEL_SANDBOX_ID") ? RUDEL_SANDBOX_ID : null,' . "\n";
        $script .= '  "sandbox_path" => defined("RUDEL_SANDBOX_PATH") ? RUDEL_SANDBOX_PATH : null,' . "\n";
        $script .= '  "db_dir" => defined("DB_DIR") ? DB_DIR : null,' . "\n";
        $script .= '  "db_file" => defined("DB_FILE") ? DB_FILE : null,' . "\n";
        $script .= '  "database_type" => defined("DATABASE_TYPE") ? DATABASE_TYPE : null,' . "\n";
        $script .= '  "wp_content_dir" => defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : null,' . "\n";
        $script .= '  "wp_plugin_dir" => defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : null,' . "\n";
        $script .= '  "wp_temp_dir" => defined("WP_TEMP_DIR") ? WP_TEMP_DIR : null,' . "\n";
        $script .= '  "table_prefix" => $GLOBALS["table_prefix"] ?? null,' . "\n";
        $script .= '  "auth_key" => defined("AUTH_KEY") ? AUTH_KEY : null,' . "\n";
        $script .= '  "nonce_key" => defined("NONCE_KEY") ? NONCE_KEY : null,' . "\n";
        $script .= ']);' . "\n";

        $tmpScript = $this->tmpDir . '/bootstrap-test-' . uniqid() . '.php';
        file_put_contents($tmpScript, $script);

        $output = shell_exec('php ' . escapeshellarg($tmpScript) . ' 2>/dev/null');
        @unlink($tmpScript);

        $result = json_decode($output ?: '{}', true);
        return $result ?: [];
    }

    // No sandbox context -- pass-through

    public function testNoSandboxContextDefinesNothing(): void
    {
        $result = $this->runBootstrap([
            'REQUEST_URI' => '/wp-admin/',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
        $this->assertNull($result['db_dir'] ?? null);
        $this->assertNull($result['table_prefix'] ?? null);
    }

    public function testNoSandboxesDirDefinesNothing(): void
    {
        // Remove the sandboxes dir
        rmdir($this->sandboxesDir);

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'anything',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    // Header resolution

    public function testHeaderResolution(): void
    {
        $this->createFakeSandboxInDir('header-box');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'header-box',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertSame('header-box', $result['sandbox_id']);
        $this->assertStringContainsString('header-box', $result['sandbox_path']);
        $this->assertSame('wordpress.db', $result['db_file']);
        $this->assertSame('sqlite', $result['database_type']);
    }

    // Cookie resolution

    public function testCookieResolution(): void
    {
        $this->createFakeSandboxInDir('cookie-box');

        $result = $this->runBootstrap(
            serverVars: ['HTTP_HOST' => 'example.com'],
            cookieVars: ['rudel_sandbox' => 'cookie-box'],
        );

        $this->assertSame('cookie-box', $result['sandbox_id']);
    }

    // Path prefix resolution

    public function testPathPrefixResolution(): void
    {
        $this->createFakeSandboxInDir('path-box');

        $result = $this->runBootstrap([
            'REQUEST_URI' => '/__rudel/path-box/wp-admin/',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertSame('path-box', $result['sandbox_id']);
    }

    // Subdomain resolution

    public function testSubdomainResolution(): void
    {
        $this->createFakeSandboxInDir('subdomain-box');

        $result = $this->runBootstrap([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'subdomain-box.example.com',
        ]);

        $this->assertSame('subdomain-box', $result['sandbox_id']);
    }

    // Constants set correctly

    public function testSandboxSetsAllRequiredConstants(): void
    {
        $this->createFakeSandboxInDir('full-const');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'full-const',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('full-const', $result['sandbox_id']);
        $this->assertNotNull($result['sandbox_path']);
        $this->assertNotNull($result['db_dir']);
        $this->assertSame('wordpress.db', $result['db_file']);
        $this->assertSame('sqlite', $result['database_type']);
        // WP_CONTENT_DIR is pre-defined by the test harness, so bootstrap skips it.
        // WP_PLUGIN_DIR and WP_TEMP_DIR are set fresh by bootstrap.
        $this->assertStringEndsWith('/wp-content/plugins', $result['wp_plugin_dir'] ?? '');
        $this->assertStringEndsWith('/tmp', $result['wp_temp_dir'] ?? '');
    }

    public function testSandboxSetsTablePrefix(): void
    {
        $this->createFakeSandboxInDir('prefix-test');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'prefix-test',
            'HTTP_HOST' => 'localhost',
        ]);

        $expected_prefix = 'wp_' . substr(md5('prefix-test'), 0, 6) . '_';
        $this->assertSame($expected_prefix, $result['table_prefix']);
    }

    public function testSandboxSetsAuthSalts(): void
    {
        $this->createFakeSandboxInDir('salt-test');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'salt-test',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame(hash('sha256', 'salt-test' . 'AUTH_KEY'), $result['auth_key']);
        $this->assertSame(hash('sha256', 'salt-test' . 'NONCE_KEY'), $result['nonce_key']);
    }

    public function testDifferentSandboxesGetDifferentSalts(): void
    {
        $this->createFakeSandboxInDir('salt-a');
        $this->createFakeSandboxInDir('salt-b');

        $resultA = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'salt-a',
            'HTTP_HOST' => 'localhost',
        ]);

        $resultB = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'salt-b',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNotSame($resultA['auth_key'], $resultB['auth_key']);
        $this->assertNotSame($resultA['nonce_key'], $resultB['nonce_key']);
    }

    // Priority order

    public function testHeaderTakesPriorityOverCookie(): void
    {
        $this->createFakeSandboxInDir('header-wins');
        $this->createFakeSandboxInDir('cookie-loses');

        $result = $this->runBootstrap(
            serverVars: [
                'HTTP_X_RUDEL_SANDBOX' => 'header-wins',
                'HTTP_HOST' => 'example.com',
            ],
            cookieVars: ['rudel_sandbox' => 'cookie-loses'],
        );

        $this->assertSame('header-wins', $result['sandbox_id']);
    }

    public function testCookieTakesPriorityOverPathPrefix(): void
    {
        $this->createFakeSandboxInDir('cookie-wins');
        $this->createFakeSandboxInDir('path-loses');

        $result = $this->runBootstrap(
            serverVars: [
                'REQUEST_URI' => '/__rudel/path-loses/',
                'HTTP_HOST' => 'example.com',
            ],
            cookieVars: ['rudel_sandbox' => 'cookie-wins'],
        );

        $this->assertSame('cookie-wins', $result['sandbox_id']);
    }

    // Security -- invalid IDs

    public function testRejectsPathTraversalId(): void
    {
        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => '../../../etc',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    public function testRejectsIdStartingWithDot(): void
    {
        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => '.hidden',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    public function testRejectsIdWithSlash(): void
    {
        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'abc/def',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    public function testRejectsNonexistentSandbox(): void
    {
        // No sandbox directory created
        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'ghost-sandbox',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    // Already resolved guard

    public function testSkipsWhenAlreadyResolved(): void
    {
        $this->createFakeSandboxInDir('should-skip');

        $result = $this->runBootstrap(
            serverVars: [
                'HTTP_X_RUDEL_SANDBOX' => 'should-skip',
                'HTTP_HOST' => 'localhost',
            ],
            extraDefines: ['RUDEL_SANDBOX_ID' => 'already-set'],
        );

        // WP_CONTENT_DIR is set by our test harness, not by bootstrap
        // The key test: DB_DIR should NOT be set (bootstrap returned early)
        $this->assertNull($result['db_dir'] ?? null);
    }

    // Helpers

    private function createFakeSandboxInDir(string $id): string
    {
        $path = $this->sandboxesDir . '/' . $id;
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode(['id' => $id, 'name' => $id]));
        return $path;
    }
}
