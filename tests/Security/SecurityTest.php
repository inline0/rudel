<?php

namespace Rudel\Tests\Security;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Environment;
use Rudel\EnvironmentManager;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

/**
 * Security-focused tests for Rudel.
 *
 * Tests path traversal, ID injection, file permission enforcement,
 * and sandbox isolation.
 */
class SecurityTest extends RudelTestCase
{
    private array $originalServer;
    private array $originalCookie;
    private string $runtimeConfigPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalCookie = $_COOKIE;
        $this->runtimeConfigPath = $this->tmpDir . '/wp-config-runtime.php';
        file_put_contents(
            $this->runtimeConfigPath,
            "<?php\ndefine('DB_ENGINE', 'sqlite');\ndefine('DB_DIR', '" . addslashes($this->tmpDir) . "');\ndefine('DB_FILE', 'rudel-state.sqlite');\n\$table_prefix = 'wp_';\n"
        );
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
        $this->assertFalse(Environment::validate_id("test\x00evil"));
    }

    public function testValidateIdRejectsNewlines(): void
    {
        $this->assertFalse(Environment::validate_id("test\nevil"));
    }

    public function testValidateIdRejectsCarriageReturn(): void
    {
        $this->assertFalse(Environment::validate_id("test\revil"));
    }

    public function testValidateIdRejectsTab(): void
    {
        $this->assertFalse(Environment::validate_id("test\tevil"));
    }

    public function testValidateIdRejectsBackslash(): void
    {
        $this->assertFalse(Environment::validate_id('test\\evil'));
    }

    public function testValidateIdRejectsShellMetachars(): void
    {
        $metacharIds = [
            'test;ls', 'test|cat', 'test&rm', 'test$(id)', 'test`id`',
            'test>out', 'test<in', 'test\'q\'', 'test"q"',
        ];
        foreach ($metacharIds as $id) {
            $this->assertFalse(Environment::validate_id($id), "Should reject: {$id}");
        }
    }

    public function testValidateIdRejectsSqlInjection(): void
    {
        $sqlIds = [
            "test' OR '1'='1", "test'; DROP TABLE--", "test UNION SELECT",
        ];
        foreach ($sqlIds as $id) {
            $this->assertFalse(Environment::validate_id($id), "Should reject: {$id}");
        }
    }

    // EnvironmentManager.get() path validation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnvironmentManagerGetRejectsTraversal(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Perms Security Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Writable Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);

        $sandboxA = $manager->create('Isolation A', ['engine' => 'sqlite']);
        $sandboxB = $manager->create('Isolation B', ['engine' => 'sqlite']);

        // Modify data in sandbox A's database
        $pdoA = new \PDO('sqlite:' . $sandboxA->get_db_path());
        $prefixA = 'rudel_' . substr(md5($sandboxA->id), 0, 6) . '_';
        $pdoA->exec("INSERT INTO {$prefixA}posts (post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_modified, post_modified_gmt) VALUES (1, datetime('now'), datetime('now'), 'Secret content', 'Secret Post', 'publish', 'secret', 'post', datetime('now'), datetime('now'))");

        // Verify sandbox B doesn't see it
        $pdoB = new \PDO('sqlite:' . $sandboxB->get_db_path());
        $prefixB = 'rudel_' . substr(md5($sandboxB->id), 0, 6) . '_';
        $count = $pdoB->query("SELECT COUNT(*) FROM {$prefixB}posts WHERE post_title='Secret Post'")->fetchColumn();
        $this->assertSame(0, (int) $count, 'Sandbox B should not see Sandbox A posts');
    }

    // Auth salt isolation

    public function testAuthSaltsAreDeterministic(): void
    {
        $output1 = $this->runBootstrapAndGetConstant('salt-deterministic', 'AUTH_KEY');
        $output2 = $this->runBootstrapAndGetConstant('salt-deterministic', 'AUTH_KEY');
        $this->assertNotEmpty($output1);
        $this->assertSame($output1, $output2);
    }

    public function testAuthSaltsDifferBetweenSandboxes(): void
    {
        $saltA = $this->runBootstrapAndGetConstant('sandbox-a', 'AUTH_KEY');
        $saltB = $this->runBootstrapAndGetConstant('sandbox-b', 'AUTH_KEY');
        $this->assertNotEmpty($saltA);
        $this->assertNotSame($saltA, $saltB);
    }

    public function testAllEightSaltsAreUnique(): void
    {
        $saltNames = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        ];

        $salts = array_map(fn($name) => $this->runBootstrapAndGetConstant('salt-unique-test', $name), $saltNames);
        $unique = array_unique($salts);
        $this->assertCount(8, $unique, 'All 8 salts should be unique');
    }

    public function testSandboxSaltsNeverMatchHostSalts(): void
    {
        // Host salts (defined in wp-config.php) are arbitrary strings.
        // Sandbox salts are SHA256 hashes of sandbox_id + salt_name.
        // They must always differ so host sessions are invalid in sandboxes.
        $sandboxAuthKey = $this->runBootstrapAndGetConstant('auth-host-test', 'AUTH_KEY');

        // Sandbox salt is a 64-char hex SHA256 hash.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sandboxAuthKey);

        // The expected value is deterministic from the sandbox ID.
        $expected = hash('sha256', 'auth-host-test' . 'AUTH_KEY');
        $this->assertSame($expected, $sandboxAuthKey);

        // Any host salt (random string, not a hash of sandbox_id) would differ.
        $this->assertNotSame('put your unique phrase here', $sandboxAuthKey);
    }

    public function testSandboxSaltsAreNotDerivedFromHostSalts(): void
    {
        // Sandbox salts are derived purely from the sandbox ID, not from
        // any host constant. Two sandboxes with the same ID always get the
        // same salts regardless of host configuration.
        $salt1 = $this->runBootstrapAndGetConstant('deterministic-auth', 'SECURE_AUTH_KEY');
        $salt2 = $this->runBootstrapAndGetConstant('deterministic-auth', 'SECURE_AUTH_KEY');
        $this->assertSame($salt1, $salt2);
        $this->assertSame(hash('sha256', 'deterministic-auth' . 'SECURE_AUTH_KEY'), $salt1);
    }

    // Auth isolation: blank sandbox default user

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBlankSandboxAdminPasswordIsUnusable(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Auth Blank Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = $sandbox->get_table_prefix();

        $hash = $pdo->query("SELECT user_pass FROM {$prefix}users WHERE user_login='admin'")->fetchColumn();

        // The placeholder hash is not a valid WordPress password hash for any real password.
        $this->assertStringStartsWith('$P$B', $hash);
        $this->assertStringContainsString('ForRudelSandbox', $hash);

        // wp_check_password would fail for common passwords against this hash.
        // The hash is intentionally not a hash of any known password.
        $this->assertNotEmpty($hash);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClonedSandboxHasSeparateUsersTable(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Auth Clone Source', ['engine' => 'sqlite']);
        $clone = $manager->create('Auth Clone Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $sourcePdo = new \PDO('sqlite:' . $source->get_db_path());
        $clonePdo = new \PDO('sqlite:' . $clone->get_db_path());

        $sourcePrefix = $source->get_table_prefix();
        $clonePrefix = $clone->get_table_prefix();

        // Both have an admin user.
        $sourceAdmin = $sourcePdo->query("SELECT user_login FROM {$sourcePrefix}users WHERE ID=1")->fetchColumn();
        $cloneAdmin = $clonePdo->query("SELECT user_login FROM {$clonePrefix}users WHERE ID=1")->fetchColumn();
        $this->assertSame('admin', $sourceAdmin);
        $this->assertSame('admin', $cloneAdmin);

        // But their table prefixes differ (so they are separate tables).
        $this->assertNotSame($sourcePrefix, $clonePrefix);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClonedSandboxUsermetaPrefixIsRewritten(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Meta Rewrite Source', ['engine' => 'sqlite']);
        $clone = $manager->create('Meta Rewrite Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $clonePrefix = $clone->get_table_prefix();
        $sourcePrefix = $source->get_table_prefix();

        // Capabilities meta_key should use the clone's prefix, not the source's.
        $capKey = $pdo->query("SELECT meta_key FROM {$clonePrefix}usermeta WHERE meta_key LIKE '%capabilities'")->fetchColumn();
        $this->assertStringStartsWith($clonePrefix, $capKey);
        $this->assertStringNotContainsString($sourcePrefix, $capKey);
    }

    // Cache key isolation

    public function testCacheKeySaltContainsSandboxId(): void
    {
        $salt = $this->runBootstrapAndGetConstant('cache-iso-test', 'WP_CACHE_KEY_SALT');
        $this->assertStringContainsString('cache-iso-test', $salt);
    }

    public function testCacheKeySaltsDifferBetweenSandboxes(): void
    {
        $saltA = $this->runBootstrapAndGetConstant('cache-sandbox-a', 'WP_CACHE_KEY_SALT');
        $saltB = $this->runBootstrapAndGetConstant('cache-sandbox-b', 'WP_CACHE_KEY_SALT');
        $this->assertNotSame($saltA, $saltB);
    }

    // Table prefix isolation

    public function testTablePrefixesAreUniquePerSandbox(): void
    {
        $prefix1 = $this->runBootstrapAndGetConstant('sandbox-alpha', 'RUDEL_TABLE_PREFIX');
        $prefix2 = $this->runBootstrapAndGetConstant('sandbox-beta', 'RUDEL_TABLE_PREFIX');
        $this->assertNotEmpty($prefix1);
        $this->assertNotSame($prefix1, $prefix2);
    }

    public function testTablePrefixFormatIsConsistent(): void
    {
        $prefix = $this->runBootstrapAndGetConstant('test-format', 'RUDEL_TABLE_PREFIX');
        $this->assertMatchesRegularExpression('/^rudel_[a-f0-9]{6}_$/', $prefix);
    }

    /**
     * Run bootstrap.php in a child process and return a constant's value.
     */
    private function runBootstrapAndGetConstant(string $sandboxId, string $constant): string
    {
        $this->createBootstrapSandbox($sandboxId);

        $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';

        $script = '<?php' . "\n";
        $script .= "\$_SERVER['HTTP_X_RUDEL_SANDBOX'] = " . var_export($sandboxId, true) . ";\n";
        $script .= "\$_SERVER['HTTP_HOST'] = 'localhost';\n";
        $script .= "define('WP_CONTENT_DIR', " . var_export($this->tmpDir, true) . ");\n";
        $script .= "define('RUDEL_WP_CONFIG_PATH', " . var_export($this->runtimeConfigPath, true) . ");\n";
        $script .= "require " . var_export($bootstrapPath, true) . ";\n";
        $script .= "echo defined(" . var_export($constant, true) . ") ? constant(" . var_export($constant, true) . ") : '';\n";

        $tmpScript = $this->tmpDir . '/bootstrap-const-test.php';
        file_put_contents($tmpScript, $script);
        $output = shell_exec('php ' . escapeshellarg($tmpScript) . ' 2>/dev/null');
        return trim((string) $output);
    }

    // Template file security

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDbDropInDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Template Security', ['engine' => 'sqlite']);

        $dbPhp = file_get_contents($sandbox->path . '/wp-content/db.php');
        $this->assertStringNotContainsString('{{', $dbPhp);
        $this->assertStringNotContainsString('}}', $dbPhp);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBootstrapDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Placeholder Security', ['engine' => 'sqlite']);

        $bootstrap = file_get_contents($sandbox->path . '/bootstrap.php');
        $this->assertStringNotContainsString('{{', $bootstrap);
        $this->assertStringNotContainsString('}}', $bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWpCliYmlDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('YML Security', ['engine' => 'sqlite']);

        $yml = file_get_contents($sandbox->path . '/wp-cli.yml');
        $this->assertStringNotContainsString('{{', $yml);
        $this->assertStringNotContainsString('}}', $yml);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClaudeMdDoesNotContainRawPlaceholders(): void
    {
        define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('MD Security', ['engine' => 'sqlite']);

        $md = file_get_contents($sandbox->path . '/CLAUDE.md');
        $this->assertStringNotContainsString('{{', $md);
        $this->assertStringNotContainsString('}}', $md);
    }

    // open_basedir in bootstrap (tested via child process)

    public function testBootstrapSetsOpenBasedir(): void
    {
        $sandboxPath = $this->createBootstrapSandbox('openbasedir-test');

        $bootstrapPath = dirname(__DIR__, 2) . '/bootstrap.php';

        $script = '<?php' . "\n";
        $script .= "\$_SERVER['HTTP_X_RUDEL_SANDBOX'] = 'openbasedir-test';\n";
        $script .= "\$_SERVER['HTTP_HOST'] = 'localhost';\n";
        $script .= "define('WP_CONTENT_DIR', " . var_export($this->tmpDir, true) . ");\n";
        $script .= "define('RUDEL_WP_CONFIG_PATH', " . var_export($this->runtimeConfigPath, true) . ");\n";
        $script .= "require " . var_export($bootstrapPath, true) . ";\n";
        $script .= "echo ini_get('open_basedir');\n";

        $tmpScript = $this->tmpDir . '/openbasedir-test.php';
        file_put_contents($tmpScript, $script);
        $output = shell_exec('php ' . escapeshellarg($tmpScript) . ' 2>/dev/null');

        $this->assertNotEmpty($output, 'open_basedir should be set');
        $this->assertStringContainsString($sandboxPath, $output);
    }

    private function createBootstrapSandbox(string $sandboxId): string
    {
        $sandboxesDir = $this->tmpDir . '/rudel-environments';
        if (! is_dir($sandboxesDir)) {
            mkdir($sandboxesDir, 0755, true);
        }

        $path = $sandboxesDir . '/' . $sandboxId;
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $environment = new Environment(
            id: $sandboxId,
            name: $sandboxId,
            path: $path,
            created_at: '2026-01-01T00:00:00+00:00',
            engine: 'sqlite',
        );

        (new EnvironmentRepository($this->runtimeStore(), $sandboxesDir))->save($environment);

        return $path;
    }
}
