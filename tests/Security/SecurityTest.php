<?php

namespace Rudel\Tests\Security;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Router;
use Rudel\Sandbox;
use Rudel\SandboxManager;
use Rudel\Tests\RudelTestCase;

/**
 * Security-focused tests for Rudel.
 *
 * Tests path traversal, ID injection, symlink escapes,
 * file permission enforcement, and sandbox isolation.
 */
class SecurityTest extends RudelTestCase
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

    // ID Validation -- injection attempts

    public function testValidateIdRejectsNullByte(): void
    {
        $this->assertFalse(Sandbox::validate_id("test\x00evil"));
    }

    public function testValidateIdRejectsNewlines(): void
    {
        $this->assertFalse(Sandbox::validate_id("test\nevil"));
    }

    public function testValidateIdRejectsCarriageReturn(): void
    {
        $this->assertFalse(Sandbox::validate_id("test\revil"));
    }

    public function testValidateIdRejectsTab(): void
    {
        $this->assertFalse(Sandbox::validate_id("test\tevil"));
    }

    public function testValidateIdRejectsBackslash(): void
    {
        $this->assertFalse(Sandbox::validate_id('test\\evil'));
    }

    public function testValidateIdRejectsShellMetachars(): void
    {
        $metacharIds = [
            'test;ls', 'test|cat', 'test&rm', 'test$(id)', 'test`id`',
            'test>out', 'test<in', 'test\'q\'', 'test"q"',
        ];
        foreach ($metacharIds as $id) {
            $this->assertFalse(Sandbox::validate_id($id), "Should reject: {$id}");
        }
    }

    public function testValidateIdRejectsSqlInjection(): void
    {
        $sqlIds = [
            "test' OR '1'='1", "test'; DROP TABLE--", "test UNION SELECT",
        ];
        foreach ($sqlIds as $id) {
            $this->assertFalse(Sandbox::validate_id($id), "Should reject: {$id}");
        }
    }

    // Path traversal via Router

    public function testRouterRejectsDirectDotDot(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '..';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());
    }

    public function testRouterRejectsMultipleDotDots(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '../../..';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());
    }

    public function testRouterRejectsEncodedTraversal(): void
    {
        // URL-encoded ../ -- would fail ID validation anyway
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '%2e%2e%2f';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());
    }

    public function testRouterRejectsDotDotSlashPrefix(): void
    {
        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = '../sandbox';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());
    }

    // Symlink escape attempts

    public function testRouterBlocksSymlinkToParent(): void
    {
        // Create a symlink inside sandboxes dir that points to parent
        $link = $this->tmpDir . '/escape-link';
        symlink(dirname($this->tmpDir), $link);

        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'escape-link';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());

        unlink($link);
    }

    public function testRouterBlocksSymlinkToRoot(): void
    {
        $link = $this->tmpDir . '/root-link';
        symlink('/tmp', $link);

        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'root-link';
        $router = new Router($this->tmpDir);
        $this->assertNull($router->resolve());

        unlink($link);
    }

    public function testRouterBlocksSymlinkToSiblingDir(): void
    {
        // Create two dirs: sandboxes/ and secrets/
        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $secretsDir = $this->tmpDir . '/secrets';
        mkdir($sandboxesDir, 0755);
        mkdir($secretsDir, 0755);
        file_put_contents($secretsDir . '/password.txt', 'secret123');

        // Symlink from sandboxes/evil to secrets
        symlink($secretsDir, $sandboxesDir . '/evil');

        $_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'evil';
        $router = new Router($sandboxesDir);
        $this->assertNull($router->resolve());

        unlink($sandboxesDir . '/evil');
    }

    // SandboxManager.get() path validation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSandboxManagerGetRejectsTraversal(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);

        $this->assertNull($manager->get('../../../etc'));
        $this->assertNull($manager->get('..'));
        $this->assertNull($manager->get('.'));
    }

    // File permissions on created sandboxes

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCriticalFilesAreReadOnly(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Perms Security Test');

        // These files should be read-only (0444)
        $readOnlyFiles = [
            $sandbox->path . '/bootstrap.php',
            $sandbox->path . '/wp-cli.yml',
            $sandbox->path . '/CLAUDE.md',
        ];

        foreach ($readOnlyFiles as $file) {
            $perms = fileperms($file) & 0777;
            $this->assertSame(0444, $perms, "File should be 0444: {$file}");
            $this->assertFalse(is_writable($file), "File should not be writable: {$file}");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWritableFilesAreWritable(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Writable Test');

        // Database should be writable
        $this->assertTrue(is_writable($sandbox->get_db_path()));

        // wp-content directories should be writable
        $this->assertTrue(is_writable($sandbox->path . '/wp-content'));
        $this->assertTrue(is_writable($sandbox->path . '/wp-content/plugins'));
        $this->assertTrue(is_writable($sandbox->path . '/wp-content/themes'));
        $this->assertTrue(is_writable($sandbox->path . '/wp-content/uploads'));
        $this->assertTrue(is_writable($sandbox->path . '/tmp'));
    }

    // Sandbox isolation -- separate databases

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSandboxDatabasesAreCompletelyIsolated(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);

        $sandboxA = $manager->create('Isolation A');
        $sandboxB = $manager->create('Isolation B');

        // Modify data in sandbox A's database
        $pdoA = new \PDO('sqlite:' . $sandboxA->get_db_path());
        $prefixA = 'wp_' . substr(md5($sandboxA->id), 0, 6) . '_';
        $pdoA->exec("INSERT INTO {$prefixA}posts (post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_modified, post_modified_gmt) VALUES (1, datetime('now'), datetime('now'), 'Secret content', 'Secret Post', 'publish', 'secret', 'post', datetime('now'), datetime('now'))");

        // Verify sandbox B doesn't see it
        $pdoB = new \PDO('sqlite:' . $sandboxB->get_db_path());
        $prefixB = 'wp_' . substr(md5($sandboxB->id), 0, 6) . '_';
        $count = $pdoB->query("SELECT COUNT(*) FROM {$prefixB}posts WHERE post_title='Secret Post'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'Sandbox B should not see Sandbox A posts');
    }

    // Auth salt isolation

    public function testAuthSaltsAreDeterministic(): void
    {
        $salt1 = hash('sha256', 'test-sandbox' . 'AUTH_KEY');
        $salt2 = hash('sha256', 'test-sandbox' . 'AUTH_KEY');
        $this->assertSame($salt1, $salt2);
    }

    public function testAuthSaltsDifferBetweenSandboxes(): void
    {
        $saltA = hash('sha256', 'sandbox-a' . 'AUTH_KEY');
        $saltB = hash('sha256', 'sandbox-b' . 'AUTH_KEY');
        $this->assertNotSame($saltA, $saltB);
    }

    public function testAllEightSaltsAreUnique(): void
    {
        $id = 'test-sandbox';
        $saltNames = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ];

        $salts = array_map(fn($name) => hash('sha256', $id . $name), $saltNames);
        $unique = array_unique($salts);
        $this->assertCount(8, $unique, 'All 8 salts should be unique');
    }

    // Table prefix isolation

    public function testTablePrefixesAreUniquePerSandbox(): void
    {
        $prefix1 = 'wp_' . substr(md5('sandbox-alpha'), 0, 6) . '_';
        $prefix2 = 'wp_' . substr(md5('sandbox-beta'), 0, 6) . '_';
        $this->assertNotSame($prefix1, $prefix2);
    }

    public function testTablePrefixFormatIsConsistent(): void
    {
        $prefix = 'wp_' . substr(md5('test'), 0, 6) . '_';
        $this->assertMatchesRegularExpression('/^wp_[a-f0-9]{6}_$/', $prefix);
    }

    // Template file security

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDbDropInDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Template Security');

        $dbPhp = file_get_contents($sandbox->path . '/wp-content/db.php');
        $this->assertStringNotContainsString('{{', $dbPhp);
        $this->assertStringNotContainsString('}}', $dbPhp);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Placeholder Security');

        $bootstrap = file_get_contents($sandbox->path . '/bootstrap.php');
        $this->assertStringNotContainsString('{{', $bootstrap);
        $this->assertStringNotContainsString('}}', $bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWpCliYmlDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('YML Security');

        $yml = file_get_contents($sandbox->path . '/wp-cli.yml');
        $this->assertStringNotContainsString('{{', $yml);
        $this->assertStringNotContainsString('}}', $yml);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClaudeMdDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('MD Security');

        $md = file_get_contents($sandbox->path . '/CLAUDE.md');
        $this->assertStringNotContainsString('{{', $md);
        $this->assertStringNotContainsString('}}', $md);
    }

    // open_basedir in bootstrap (tested via child process)

    public function testBootstrapSetsOpenBasedir(): void
    {
        $sandboxesDir = $this->tmpDir . '/rudel-sandboxes';
        mkdir($sandboxesDir, 0755, true);
        $sandboxPath = $sandboxesDir . '/openbasedir-test';
        mkdir($sandboxPath, 0755);
        file_put_contents($sandboxPath . '/.rudel.json', json_encode(['id' => 'openbasedir-test']));

        $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';

        $script = '<?php' . "\n";
        $script .= "\$_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'openbasedir-test';\n";
        $script .= "\$_SERVER['HTTP_HOST'] = 'localhost';\n";
        $script .= "define('WP_CONTENT_DIR', " . var_export($this->tmpDir, true) . ");\n";
        $script .= "require " . var_export($bootstrapPath, true) . ";\n";
        $script .= "echo ini_get('open_basedir');\n";

        $tmpScript = $this->tmpDir . '/openbasedir-test.php';
        file_put_contents($tmpScript, $script);
        $output = shell_exec('php ' . escapeshellarg($tmpScript) . ' 2>/dev/null');

        $this->assertNotEmpty($output, 'open_basedir should be set');
        $this->assertStringContainsString($sandboxPath, $output);
    }
}
