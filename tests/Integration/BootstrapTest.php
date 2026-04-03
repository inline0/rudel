<?php

namespace Rudel\Tests\Integration;

use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

/**
 * Tests for bootstrap.php -- the pre-boot sandbox resolver.
 *
 * Each test runs in a separate process because bootstrap.php defines constants
 * (RUDEL_ID, DB_DIR, etc.) that can only be defined once per process.
 *
 * We test bootstrap.php by including it in a controlled environment with
 * specific superglobal values set, then checking which constants were defined.
 */
class BootstrapTest extends RudelTestCase
{
    private string $bootstrapPath;
    private string $sandboxesDir;
    private string $appsDir;
    private string $runtimeConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';
        $this->sandboxesDir = $this->tmpDir . '/rudel-environments';
        $this->appsDir = $this->tmpDir . '/rudel-apps';
        $this->runtimeConfigPath = $this->tmpDir . '/wp-config-runtime.php';
        mkdir($this->sandboxesDir, 0755, true);
        file_put_contents(
            $this->runtimeConfigPath,
            "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_DIR', '" . addslashes($this->tmpDir) . "');\ndefine('DB_FILE', 'rudel-state.sqlite');\n\$table_prefix = 'wp_';\n"
        );
    }

    /**
     * Run bootstrap.php in a child process with controlled environment.
     * Returns the output as JSON with the state of all relevant constants.
     */
    private function runBootstrap(array $serverVars = [], array $cookieVars = [], array $argv = [], array $extraDefines = [], bool $skipWpContentDir = false): array
    {
        $runtimeTables = $this->exportRuntimeTables();
        $script = '<?php' . "\n";
        $script .= "require " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n";
        $script .= "require_once " . var_export(dirname(__DIR__) . '/Stubs/MockWpdb.php', true) . ";\n";
        $script .= "defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');\n";
        $script .= "defined('ARRAY_N') || define('ARRAY_N', 'ARRAY_N');\n";
        $script .= '$GLOBALS["wpdb"] = new \MockWpdb();' . "\n";
        $script .= '$GLOBALS["wpdb"]->prefix = "wp_";' . "\n";
        $script .= '$GLOBALS["wpdb"]->base_prefix = "wp_";' . "\n";
        $script .= '\Rudel\RudelSchema::ensure(new \Rudel\WpdbStore($GLOBALS["wpdb"]));' . "\n";
        $script .= '$rudel_tables = ' . var_export($runtimeTables, true) . ";\n";
        $script .= 'foreach ($rudel_tables as $table => $rows) { foreach ($rows as $row) { $GLOBALS["wpdb"]->insert($table, $row); } }' . "\n";

        // Set superglobals
        $script .= '$_SERVER = ' . var_export(array_merge($_SERVER, $serverVars), true) . ";\n";
        $script .= '$_COOKIE = ' . var_export($cookieVars, true) . ";\n";

        if (! empty($argv)) {
            $script .= '$argv = ' . var_export($argv, true) . ";\n";
            $script .= '$GLOBALS["argv"] = $argv;' . "\n";
        }

        if (! $skipWpContentDir) {
            // Define WP_CONTENT_DIR so bootstrap can find sandboxes
            $script .= "define('WP_CONTENT_DIR', " . var_export($this->tmpDir, true) . ");\n";
        }

        if (! array_key_exists('RUDEL_WP_CONFIG_PATH', $extraDefines)) {
            $script .= "define('RUDEL_WP_CONFIG_PATH', " . var_export($this->runtimeConfigPath, true) . ");\n";
        }

        foreach ($extraDefines as $name => $value) {
            $script .= "define('{$name}', " . var_export($value, true) . ");\n";
        }

        // Include bootstrap
        $script .= "require " . var_export($this->bootstrapPath, true) . ";\n";

        // Output state
        $script .= 'echo json_encode([' . "\n";
        $script .= '  "sandbox_id" => defined("RUDEL_ID") ? RUDEL_ID : null,' . "\n";
        $script .= '  "is_app" => defined("RUDEL_IS_APP") ? RUDEL_IS_APP : null,' . "\n";
        $script .= '  "sandbox_path" => defined("RUDEL_PATH") ? RUDEL_PATH : null,' . "\n";
        $script .= '  "db_dir" => defined("DB_DIR") ? DB_DIR : null,' . "\n";
        $script .= '  "db_file" => defined("DB_FILE") ? DB_FILE : null,' . "\n";
        $script .= '  "database_type" => defined("DATABASE_TYPE") ? DATABASE_TYPE : null,' . "\n";
        $script .= '  "wp_content_dir" => defined("WP_CONTENT_DIR") ? WP_CONTENT_DIR : null,' . "\n";
        $script .= '  "wp_plugin_dir" => defined("WP_PLUGIN_DIR") ? WP_PLUGIN_DIR : null,' . "\n";
        $script .= '  "wp_temp_dir" => defined("WP_TEMP_DIR") ? WP_TEMP_DIR : null,' . "\n";
        $script .= '  "table_prefix" => $GLOBALS["table_prefix"] ?? null,' . "\n";
        $script .= '  "wp_content_url" => defined("WP_CONTENT_URL") ? WP_CONTENT_URL : null,' . "\n";
        $script .= '  "auth_key" => defined("AUTH_KEY") ? AUTH_KEY : null,' . "\n";
        $script .= '  "nonce_key" => defined("NONCE_KEY") ? NONCE_KEY : null,' . "\n";
        $script .= '  "rudel_table_prefix" => defined("RUDEL_TABLE_PREFIX") ? RUDEL_TABLE_PREFIX : null,' . "\n";
        $script .= '  "wp_siteurl" => defined("WP_SITEURL") ? WP_SITEURL : null,' . "\n";
        $script .= '  "wp_home" => defined("WP_HOME") ? WP_HOME : null,' . "\n";
        $script .= '  "uploads" => defined("UPLOADS") ? UPLOADS : null,' . "\n";
        $script .= '  "table_prefix_caller_scope" => isset($table_prefix) ? $table_prefix : null,' . "\n";
        $script .= '  "cookie_sandbox" => $_COOKIE["rudel_sandbox"] ?? null,' . "\n";
        $script .= '  "wp_debug" => defined("WP_DEBUG") ? WP_DEBUG : null,' . "\n";
        $script .= '  "wp_debug_log" => defined("WP_DEBUG_LOG") ? WP_DEBUG_LOG : null,' . "\n";
        $script .= '  "wp_debug_display" => defined("WP_DEBUG_DISPLAY") ? WP_DEBUG_DISPLAY : null,' . "\n";
        $script .= '  "cache_key_salt" => defined("WP_CACHE_KEY_SALT") ? WP_CACHE_KEY_SALT : null,' . "\n";
        $script .= '  "disable_email" => defined("RUDEL_DISABLE_EMAIL") ? RUDEL_DISABLE_EMAIL : null,' . "\n";
        $script .= ']);' . "\n";

        $tmpScript = $this->tmpDir . '/bootstrap-test-' . uniqid() . '.php';
        file_put_contents($tmpScript, $script);

        $output = shell_exec('php ' . escapeshellarg($tmpScript) . ' 2>/dev/null');
        @unlink($tmpScript);

        $result = json_decode($output ?: '{}', true);
        return $result ?: [];
    }

    private function exportRuntimeTables(): array
    {
        /** @var \MockWpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $tables = [];
        foreach (['wp_rudel_environments', 'wp_rudel_apps', 'wp_rudel_app_domains', 'wp_rudel_worktrees'] as $table) {
            $tables[$table] = $wpdb->getTableRows($table);
        }

        return $tables;
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
            'REQUEST_URI' => '/' . RUDEL_PATH_PREFIX . '/path-box/wp-admin/',
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

        $expected_prefix = 'rudel_' . substr(md5('prefix-test'), 0, 6) . '_';
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
                'REQUEST_URI' => '/' . RUDEL_PATH_PREFIX . '/path-loses/',
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
            extraDefines: ['RUDEL_ID' => 'already-set'],
        );

        // WP_CONTENT_DIR is set by our test harness, not by bootstrap
        // The key test: DB_DIR should NOT be set (bootstrap returned early)
        $this->assertNull($result['db_dir'] ?? null);
    }

    // Protocol detection

    public function testProtocolFromForwardedProto(): void
    {
        $this->createFakeSandboxInDir('proto-fwd');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'proto-fwd',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertStringStartsWith('https://', $result['wp_content_url'] ?? '');
    }

    public function testProtocolFromHttpsServerVar(): void
    {
        $this->createFakeSandboxInDir('proto-https');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'proto-https',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ]);

        $this->assertStringStartsWith('https://', $result['wp_content_url'] ?? '');
    }

    public function testProtocolIgnoresHttpsOff(): void
    {
        $this->createFakeSandboxInDir('proto-off');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'proto-off',
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'off',
        ]);

        $this->assertStringStartsWith('http://', $result['wp_content_url'] ?? '');
    }

    public function testProtocolDefaultsToHttp(): void
    {
        $this->createFakeSandboxInDir('proto-default');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'proto-default',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertStringStartsWith('http://', $result['wp_content_url'] ?? '');
    }

    public function testSubdomainWithPort(): void
    {
        $this->createFakeSandboxInDir('porttest');

        $result = $this->runBootstrap([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'porttest.example.com:8080',
        ]);

        $this->assertSame('porttest', $result['sandbox_id']);
    }

    // Sandboxes directory resolution

    public function testSandboxesDirFromRudelConstant(): void
    {
        $customDir = $this->tmpDir . '/custom-sandboxes';
        mkdir($customDir, 0755, true);
        $this->createRuntimeSandbox('const-test', $customDir);

        $result = $this->runBootstrap(
            serverVars: [
                'HTTP_X_RUDEL_SANDBOX' => 'const-test',
                'HTTP_HOST' => 'localhost',
            ],
            extraDefines: ['RUDEL_ENVIRONMENTS_DIR' => $customDir],
            skipWpContentDir: true,
        );

        $this->assertSame('const-test', $result['sandbox_id']);
    }

    public function testSandboxesDirFromAbspathFallback(): void
    {
        $absDir = $this->tmpDir . '/wproot';
        $this->createRuntimeSandbox('abs-test', $absDir . '/wp-content/rudel-environments');

        $result = $this->runBootstrap(
            serverVars: [
                'HTTP_X_RUDEL_SANDBOX' => 'abs-test',
                'HTTP_HOST' => 'localhost',
            ],
            extraDefines: ['ABSPATH' => $absDir . '/'],
            skipWpContentDir: true,
        );

        $this->assertSame('abs-test', $result['sandbox_id']);
    }

    // CLI --url= resolution

    public function testCliUrlPathPrefixResolution(): void
    {
        $this->createFakeSandboxInDir('cli-path-box');

        $result = $this->runBootstrap(
            serverVars: ['HTTP_HOST' => 'example.com'],
            argv: ['wp', '--url=http://example.com/' . RUDEL_PATH_PREFIX . '/cli-path-box/', 'post', 'list'],
        );

        $this->assertSame('cli-path-box', $result['sandbox_id']);
    }

    public function testCliUrlSubdomainResolution(): void
    {
        $this->createFakeSandboxInDir('cli-sub-box');

        $result = $this->runBootstrap(
            serverVars: ['HTTP_HOST' => 'example.com'],
            argv: ['wp', '--url=https://cli-sub-box.example.com', 'option', 'get', 'siteurl'],
        );

        $this->assertSame('cli-sub-box', $result['sandbox_id']);
    }

    public function testCliUrlPathPrefixResolutionWithSeparateArg(): void
    {
        $this->createFakeSandboxInDir('cli-split-box');

        $result = $this->runBootstrap(
            serverVars: ['HTTP_HOST' => 'example.com'],
            argv: ['wp', '--url', 'http://example.com/' . RUDEL_PATH_PREFIX . '/cli-split-box/', 'post', 'list'],
        );

        $this->assertSame('cli-split-box', $result['sandbox_id']);
    }

    public function testCliUrlNonMatchingReturnsNull(): void
    {
        $result = $this->runBootstrap(
            serverVars: ['HTTP_HOST' => 'example.com'],
            argv: ['wp', '--url=http://example.com/something', 'post', 'list'],
        );

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    // Additional constants

    public function testDefinesTablePrefixConstant(): void
    {
        $this->createFakeSandboxInDir('tprefix-box');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'tprefix-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $expected = 'rudel_' . substr(md5('tprefix-box'), 0, 6) . '_';
        $this->assertSame($expected, $result['rudel_table_prefix']);
    }

    public function testDefinesWpSiteurlAndWpHome(): void
    {
        $this->createFakeSandboxInDir('urlconst-box');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'urlconst-box',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertSame('http://example.com/' . RUDEL_PATH_PREFIX . '/urlconst-box', $result['wp_siteurl']);
        $this->assertSame('http://example.com/' . RUDEL_PATH_PREFIX . '/urlconst-box', $result['wp_home']);
    }

    public function testTablePrefixSetInCallerScope(): void
    {
        $this->createFakeSandboxInDir('scope-box');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'scope-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $expected = 'rudel_' . substr(md5('scope-box'), 0, 6) . '_';
        $this->assertSame($expected, $result['table_prefix_caller_scope']);
    }

    public function testForwardedProtoTakesPriorityOverHttps(): void
    {
        $this->createFakeSandboxInDir('proto-prio');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'proto-prio',
            'HTTP_HOST' => 'example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTPS' => 'off',
        ]);

        $this->assertStringStartsWith('https://', $result['wp_content_url'] ?? '');
        $this->assertStringStartsWith('https://', $result['wp_siteurl'] ?? '');
    }

    public function testDefinesUploadsConstant(): void
    {
        $this->createFakeSandboxInDir('uploads-box');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'uploads-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('wp-content/uploads', $result['uploads']);
    }

    // MySQL engine

    public function testMysqlEngineSkipsSqliteConstants(): void
    {
        $this->createFakeSandboxInDir('mysql-box', 'mysql');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'mysql-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('mysql-box', $result['sandbox_id']);
        $this->assertNull($result['db_dir']);
        $this->assertNull($result['db_file']);
        $this->assertNull($result['database_type']);
        $this->assertNotNull($result['table_prefix']);
        $this->assertNotNull($result['wp_content_dir']);
        $this->assertNotNull($result['auth_key']);
    }

    public function testSqliteEngineSetsSqliteConstants(): void
    {
        $this->createFakeSandboxInDir('sqlite-box', 'sqlite');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'sqlite-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('sqlite-box', $result['sandbox_id']);
        $this->assertNotNull($result['db_dir']);
        $this->assertSame('wordpress.db', $result['db_file']);
        $this->assertSame('sqlite', $result['database_type']);
    }

    public function testMysqlEngineWithoutSqliteDoesNotSetSqliteConstants(): void
    {
        $this->createFakeSandboxInDir('no-engine-box', 'mysql');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'no-engine-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('no-engine-box', $result['sandbox_id']);
        $this->assertNull($result['db_dir']);
        $this->assertNull($result['db_file']);
    }

    // Subsite engine

    public function testSubsiteEngineSetsContentButSkipsTablePrefix(): void
    {
        $this->createFakeSandboxInDir('subsite-box', 'subsite');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'subsite-box',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('subsite-box', $result['sandbox_id']);
        // No SQLite constants.
        $this->assertNull($result['db_dir']);
        $this->assertNull($result['db_file']);
        $this->assertNull($result['database_type']);
        // WP_CONTENT_DIR is set (subsite sandboxes have their own wp-content).
        $this->assertNotNull($result['wp_content_dir']);
        // Auth salts are set.
        $this->assertNotNull($result['auth_key']);
        // Table prefix is NOT overridden (multisite handles it via blog_id).
        $this->assertNull($result['table_prefix']);
    }

    // Auto-cookie (CLI mode: cookie is not set via setcookie but $_COOKIE is updated)

    public function testCookieDetectionStillWorks(): void
    {
        $this->createFakeSandboxInDir('cookie-auto');

        $result = $this->runBootstrap(
            ['HTTP_HOST' => 'localhost'],
            ['rudel_sandbox' => 'cookie-auto'],
        );

        $this->assertSame('cookie-auto', $result['sandbox_id']);
        $this->assertSame('cookie-auto', $result['cookie_sandbox']);
    }

    public function testPathPrefixDetectionSetsContext(): void
    {
        $this->createFakeSandboxInDir('path-auto');

        $result = $this->runBootstrap([
            'REQUEST_URI' => '/__rudel/path-auto/wp-admin/',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('path-auto', $result['sandbox_id']);
    }

    public function testAdminExitParamDoesNotActivateSandbox(): void
    {
        $this->createFakeSandboxInDir('some-sandbox');

        // ?adminExit is handled before sandbox detection in web mode.
        // In CLI mode it's skipped, so we just verify it doesn't interfere.
        $result = $this->runBootstrap([
            'REQUEST_URI' => '/?adminExit',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
    }

    public function testAdminExitDoesNotAffectCookieDetectionInCli(): void
    {
        $this->createFakeSandboxInDir('cookie-exit-test');

        // In CLI mode, ?adminExit is ignored, cookie detection still works.
        $result = $this->runBootstrap(
            ['HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/?adminExit'],
            ['rudel_sandbox' => 'cookie-exit-test'],
        );

        // CLI mode: adminExit handler doesn't fire, cookie takes effect.
        $this->assertSame('cookie-exit-test', $result['sandbox_id']);
    }

    // Debug logging

    public function testSandboxEnablesDebugLogging(): void
    {
        $this->createFakeSandboxInDir('debug-test');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'debug-test',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('debug-test', $result['sandbox_id']);
        $this->assertTrue($result['wp_debug']);
        $this->assertTrue($result['wp_debug_log']);
        $this->assertFalse($result['wp_debug_display']);
    }

    public function testNoSandboxDoesNotSetDebugConstants(): void
    {
        $result = $this->runBootstrap([
            'REQUEST_URI' => '/wp-admin/',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertNull($result['sandbox_id'] ?? null);
        $this->assertNull($result['wp_debug']);
    }

    // Cache isolation

    public function testSandboxSetsCacheKeySalt(): void
    {
        $this->createFakeSandboxInDir('cache-test');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'cache-test',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('cache-test', $result['sandbox_id']);
        $this->assertSame('rudel_cache-test_', $result['cache_key_salt']);
    }

    public function testCacheKeySaltDiffersBetweenSandboxes(): void
    {
        $this->createFakeSandboxInDir('cache-a');
        $this->createFakeSandboxInDir('cache-b');

        $resultA = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'cache-a',
            'HTTP_HOST' => 'localhost',
        ]);
        $resultB = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'cache-b',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertNotSame($resultA['cache_key_salt'], $resultB['cache_key_salt']);
    }

    public function testNoSandboxDoesNotSetCacheKeySalt(): void
    {
        $result = $this->runBootstrap([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertNull($result['cache_key_salt']);
    }

    // Email isolation

    public function testSandboxDisablesEmailByDefault(): void
    {
        $this->createFakeSandboxInDir('email-test');

        $result = $this->runBootstrap([
            'HTTP_X_RUDEL_SANDBOX' => 'email-test',
            'HTTP_HOST' => 'localhost',
        ]);

        $this->assertSame('email-test', $result['sandbox_id']);
        $this->assertTrue($result['disable_email']);
    }

    public function testAppDomainMapResolvesToApp(): void
    {
        $this->createFakeAppInDir('client-a-app', ['client-a.com']);

        $result = $this->runBootstrap([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'client-a.com',
        ]);

        $this->assertSame('client-a-app', $result['sandbox_id']);
        $this->assertTrue($result['is_app']);
        $this->assertSame('http://client-a.com', $result['wp_siteurl']);
        $this->assertSame('http://client-a.com', $result['wp_home']);
        $this->assertFalse($result['disable_email']);
        $this->assertNull($result['cookie_sandbox']);
    }

    public function testAppDomainMapWinsOverSandboxCookie(): void
    {
        $this->createFakeAppInDir('priority-app', ['priority.example.com']);
        $this->createFakeSandboxInDir('cookie-box');

        $result = $this->runBootstrap(
            serverVars: [
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'priority.example.com',
            ],
            cookieVars: ['rudel_sandbox' => 'cookie-box'],
        );

        $this->assertSame('priority-app', $result['sandbox_id']);
        $this->assertTrue($result['is_app']);
    }

    public function testNoSandboxDoesNotDisableEmail(): void
    {
        $result = $this->runBootstrap([
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'example.com',
        ]);

        $this->assertNull($result['disable_email']);
    }

    // Helpers

    private function createFakeSandboxInDir(string $id, string $engine = 'sqlite'): string
    {
        return $this->createRuntimeSandbox($id, $this->sandboxesDir, $engine);
    }

    private function createFakeAppInDir(string $id, array $domains, string $engine = 'sqlite'): string
    {
        mkdir($this->appsDir, 0755, true);
        return $this->createRuntimeSandbox($id, $this->appsDir, $engine, 'app', $domains);
    }

    private function createRuntimeSandbox(string $id, string $baseDir, string $engine = 'sqlite', string $type = 'sandbox', array $domains = []): string
    {
        $path = $baseDir . '/' . $id;
        mkdir($path, 0755, true);

        $environment = new Environment(
            id: $id,
            name: $id,
            path: $path,
            created_at: '2026-01-01T00:00:00+00:00',
            engine: $engine,
            type: $type,
            domains: 'app' === $type ? $domains : null,
        );

        $repository = new EnvironmentRepository($this->runtimeStore(), $baseDir, $type);
        $saved = $repository->save($environment);

        if ('app' === $type) {
            $apps = new AppRepository($this->runtimeStore(), new EnvironmentRepository($this->runtimeStore(), $baseDir, 'app'));
            $apps->create($saved, $domains);
        }

        return $path;
    }
}
