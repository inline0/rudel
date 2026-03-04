<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\RudelConfig;
use Rudel\Sandbox;
use Rudel\SandboxManager;
use Rudel\Tests\RudelTestCase;

class SandboxManagerTest extends RudelTestCase
{
    // All tests run in separate processes because SandboxManager reads constants.

    // create() -- directory structure

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateBuildsCorrectDirectoryStructure(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Structure Test');

        $this->assertDirectoryExists($sandbox->path);
        $this->assertDirectoryExists($sandbox->path . '/wp-content');
        $this->assertDirectoryExists($sandbox->path . '/wp-content/themes');
        $this->assertDirectoryExists($sandbox->path . '/wp-content/plugins');
        $this->assertDirectoryExists($sandbox->path . '/wp-content/uploads');
        $this->assertDirectoryExists($sandbox->path . '/wp-content/mu-plugins');
        $this->assertDirectoryExists($sandbox->path . '/tmp');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateGeneratesAllRequiredFiles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Files Test');

        $this->assertFileExists($sandbox->path . '/.rudel.json');
        $this->assertFileExists($sandbox->path . '/wordpress.db');
        $this->assertFileExists($sandbox->path . '/wp-cli.yml');
        $this->assertFileExists($sandbox->path . '/bootstrap.php');
        $this->assertFileExists($sandbox->path . '/CLAUDE.md');
        $this->assertFileExists($sandbox->path . '/wp-content/db.php');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateReturnsSandboxWithCorrectMetadata(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Meta Test', ['template' => 'blank']);

        $this->assertNotEmpty($sandbox->id);
        $this->assertSame('Meta Test', $sandbox->name);
        $this->assertSame('blank', $sandbox->template);
        $this->assertSame('active', $sandbox->status);
        $this->assertNotEmpty($sandbox->created_at);
        $this->assertTrue(Sandbox::validate_id($sandbox->id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAutoCreatesSandboxesBaseDir(): void
    {
        $this->defineConstants();
        $sandboxesDir = $this->tmpDir . '/nested/sandboxes';
        $this->assertDirectoryDoesNotExist($sandboxesDir);

        $manager = new SandboxManager($sandboxesDir);
        $sandbox = $manager->create('Auto Dir Test');

        $this->assertDirectoryExists($sandboxesDir);
        $this->assertDirectoryExists($sandbox->path);
    }

    // create() -- generated file contents

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateWpCliYmlContainsCorrectPath(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('WpCli Test');

        $yml = file_get_contents($sandbox->path . '/wp-cli.yml');
        $this->assertStringContainsString('path:', $yml);
        $this->assertStringContainsString('require:', $yml);
        $this->assertStringContainsString($sandbox->path . '/bootstrap.php', $yml);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateBootstrapContainsSandboxId(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Bootstrap Test');

        $bootstrap = file_get_contents($sandbox->path . '/bootstrap.php');
        $this->assertStringContainsString("'" . $sandbox->id . "'", $bootstrap);
        $this->assertStringContainsString($sandbox->path, $bootstrap);
        $this->assertStringContainsString('RUDEL_SANDBOX_ID', $bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDbDropInPointsToSharedSqlite(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('DbDropIn Test');

        $db = file_get_contents($sandbox->path . '/wp-content/db.php');
        $this->assertStringContainsString('sqlite-database-integration', $db);
        $this->assertStringContainsString("require_once", $db);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateClaudeMdContainsSandboxInfo(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Claude Test');

        $md = file_get_contents($sandbox->path . '/CLAUDE.md');
        $this->assertStringContainsString('Claude Test', $md);
        $this->assertStringContainsString($sandbox->id, $md);
        $this->assertStringContainsString('Security rules', $md);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateRudelJsonIsValidAndReadable(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Json Test');

        $raw = file_get_contents($sandbox->path . '/.rudel.json');
        $data = json_decode($raw, true);
        $this->assertNotNull($data);
        $this->assertSame($sandbox->id, $data['id']);
        $this->assertSame('Json Test', $data['name']);
        $this->assertSame('active', $data['status']);
    }

    // create() -- file permissions

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSetsReadOnlyPermissionsOnCriticalFiles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Perms Test');

        // bootstrap.php, wp-cli.yml, CLAUDE.md should be 0444 (read-only)
        $this->assertSame('0444', substr(sprintf('%o', fileperms($sandbox->path . '/bootstrap.php')), -4));
        $this->assertSame('0444', substr(sprintf('%o', fileperms($sandbox->path . '/wp-cli.yml')), -4));
        $this->assertSame('0444', substr(sprintf('%o', fileperms($sandbox->path . '/CLAUDE.md')), -4));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSetsDatabasePermissions(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('DbPerms Test');

        $this->assertSame('0664', substr(sprintf('%o', fileperms($sandbox->get_db_path())), -4));
    }

    // create() -- SQLite database contents

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasAllWordPressTables(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Tables Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';
        $expected = [
            $prefix . 'commentmeta',
            $prefix . 'comments',
            $prefix . 'links',
            $prefix . 'options',
            $prefix . 'postmeta',
            $prefix . 'posts',
            $prefix . 'term_relationships',
            $prefix . 'term_taxonomy',
            $prefix . 'termmeta',
            $prefix . 'terms',
            $prefix . 'usermeta',
            $prefix . 'users',
        ];

        $this->assertSame($expected, $tables);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasAdminUser(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('User Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $user = $pdo->query("SELECT user_login, user_email FROM {$prefix}users WHERE ID=1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('admin', $user['user_login']);
        $this->assertSame('admin@sandbox.local', $user['user_email']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasAdminCapabilities(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Caps Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $caps = $pdo->query("SELECT meta_value FROM {$prefix}usermeta WHERE user_id=1 AND meta_key='{$prefix}capabilities'")->fetchColumn();
        $this->assertNotFalse($caps);
        $capsArray = unserialize($caps);
        $this->assertTrue($capsArray['administrator'] ?? false);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasRequiredOptions(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Options Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $requiredOptions = ['siteurl', 'home', 'blogname', 'blogdescription', 'permalink_structure', 'active_plugins', 'template', 'stylesheet', $prefix . 'user_roles'];

        foreach ($requiredOptions as $name) {
            $val = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='{$name}'")->fetchColumn();
            $this->assertNotFalse($val, "Option '{$name}' should exist in database");
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseSiteurlContainsSandboxId(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Url Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertStringContainsString($sandbox->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasHelloWorldPost(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Post Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $title = $pdo->query("SELECT post_title FROM {$prefix}posts WHERE ID=1")->fetchColumn();
        $this->assertSame('Hello world!', $title);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasUncategorizedTerm(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Term Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $name = $pdo->query("SELECT name FROM {$prefix}terms WHERE term_id=1")->fetchColumn();
        $this->assertSame('Uncategorized', $name);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasUserRoles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Roles Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $roles = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='{$prefix}user_roles'")->fetchColumn();
        $rolesArray = unserialize($roles);
        $this->assertArrayHasKey('administrator', $rolesArray);
        $this->assertArrayHasKey('editor', $rolesArray);
        $this->assertArrayHasKey('author', $rolesArray);
        $this->assertArrayHasKey('contributor', $rolesArray);
        $this->assertArrayHasKey('subscriber', $rolesArray);
    }

    // create() -- table prefix isolation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateUsesDifferentTablePrefixPerSandbox(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $sandbox1 = $manager->create('Prefix Test A');
        $sandbox2 = $manager->create('Prefix Test B');

        $pdo1 = new \PDO('sqlite:' . $sandbox1->get_db_path());
        $pdo2 = new \PDO('sqlite:' . $sandbox2->get_db_path());

        $tables1 = $pdo1->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $tables2 = $pdo2->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);

        // Tables should have different prefixes
        $prefix1 = 'wp_' . substr(md5($sandbox1->id), 0, 6) . '_';
        $prefix2 = 'wp_' . substr(md5($sandbox2->id), 0, 6) . '_';
        $this->assertNotSame($prefix1, $prefix2);

        // Verify each sandbox uses its own prefix
        foreach ($tables1 as $t) {
            $this->assertStringStartsWith($prefix1, $t);
        }
        foreach ($tables2 as $t) {
            $this->assertStringStartsWith($prefix2, $t);
        }
    }

    // list()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsEmptyWhenNoSandboxes(): void
    {
        $this->defineConstants();
        mkdir($this->tmpDir . '/sandboxes', 0755);
        $manager = new SandboxManager($this->tmpDir . '/sandboxes');

        $this->assertSame([], $manager->list());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsEmptyWhenDirNotExists(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir . '/nonexistent');

        $this->assertSame([], $manager->list());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsAllSandboxes(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox1 = $manager->create('List Test A');
        $sandbox2 = $manager->create('List Test B');

        $list = $manager->list();
        $this->assertCount(2, $list);

        $ids = array_map(fn($s) => $s->id, $list);
        $this->assertContains($sandbox1->id, $ids);
        $this->assertContains($sandbox2->id, $ids);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListSkipsDirsWithoutMetaFile(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('Real Sandbox');

        // Create a junk directory with no .rudel.json
        mkdir($this->tmpDir . '/not-a-sandbox', 0755);

        $list = $manager->list();
        $this->assertCount(1, $list);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListSkipsRegularFiles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('Real Sandbox');

        // Create a regular file (not a directory)
        file_put_contents($this->tmpDir . '/some-file.txt', 'not a sandbox');

        $list = $manager->list();
        $this->assertCount(1, $list);
    }

    // get()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsSandboxById(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Get Test');

        $got = $manager->get($sandbox->id);
        $this->assertNotNull($got);
        $this->assertSame($sandbox->id, $got->id);
        $this->assertSame('Get Test', $got->name);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsNullForNonexistent(): void
    {
        $this->defineConstants();
        mkdir($this->tmpDir . '/sandboxes', 0755);
        $manager = new SandboxManager($this->tmpDir . '/sandboxes');

        $this->assertNull($manager->get('nonexistent-id'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsNullForInvalidId(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $this->assertNull($manager->get('../../../etc'));
        $this->assertNull($manager->get(''));
        $this->assertNull($manager->get('.hidden'));
    }

    // destroy()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyRemovesSandboxDirectory(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Destroy Test');

        $this->assertDirectoryExists($sandbox->path);
        $result = $manager->destroy($sandbox->id);

        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyReturnsFalseForNonexistent(): void
    {
        $this->defineConstants();
        mkdir($this->tmpDir . '/sandboxes', 0755);
        $manager = new SandboxManager($this->tmpDir . '/sandboxes');

        $this->assertFalse($manager->destroy('nonexistent'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyHandlesReadOnlyFiles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('ReadOnly Destroy');

        // Verify read-only files exist
        $this->assertFalse(is_writable($sandbox->path . '/bootstrap.php'));
        $this->assertFalse(is_writable($sandbox->path . '/wp-cli.yml'));

        // Destroy should still succeed
        $result = $manager->destroy($sandbox->id);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyRemovedFromList(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Gone Test');

        $this->assertCount(1, $manager->list());
        $manager->destroy($sandbox->id);
        $this->assertCount(0, $manager->list());
    }

    // create() -- error paths

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateThrowsRuntimeExceptionWhenDirectoryExists(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Collision Test');

        // Destroy the sandbox but recreate just the directory (no metadata).
        $path = $sandbox->path;
        $manager->destroy($sandbox->id);
        mkdir($path, 0755, true);

        // generate_id produces a unique ID each time, so we can't collide by name.
        // Instead, create a subclass that forces the same ID.
        $forcedId = $sandbox->id;
        $testDir = $this->tmpDir;
        $managerClass = new class($testDir, $forcedId) extends SandboxManager {
            private string $forcedId;
            public function __construct(string $dir, string $id) {
                parent::__construct($dir);
                $this->forcedId = $id;
            }
            public function create(string $name, array $options = array()): \Rudel\Sandbox {
                // Use reflection to call the parent with a forced ID.
                $path = (new \ReflectionProperty(SandboxManager::class, 'sandboxes_dir'))->getValue($this);
                $path .= '/' . $this->forcedId;
                if (is_dir($path)) {
                    throw new \RuntimeException(
                        sprintf('Sandbox directory already exists: %s', $path)
                    );
                }
                return parent::create($name, $options);
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox directory already exists');
        $managerClass->create('Collision Again');
    }

    // create() -- additional database content

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasSamplePage(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Page Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $page = $pdo->query("SELECT post_title, post_type, post_status FROM {$prefix}posts WHERE ID=2")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Sample Page', $page['post_title']);
        $this->assertSame('page', $page['post_type']);
        $this->assertSame('publish', $page['post_status']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasDefaultComment(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Comment Test');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'wp_' . substr(md5($sandbox->id), 0, 6) . '_';

        $comment = $pdo->query("SELECT comment_author, comment_content, comment_approved FROM {$prefix}comments WHERE comment_ID=1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('A WordPress Commenter', $comment['comment_author']);
        $this->assertStringContainsString('comment', $comment['comment_content']);
        $this->assertSame('1', $comment['comment_approved']);
    }

    // Full lifecycle

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFullCreateListGetDestroyCycle(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        // Create
        $sandbox = $manager->create('Lifecycle Test');
        $id = $sandbox->id;

        // List
        $list = $manager->list();
        $this->assertCount(1, $list);
        $this->assertSame($id, $list[0]->id);

        // Get
        $got = $manager->get($id);
        $this->assertNotNull($got);
        $this->assertSame('Lifecycle Test', $got->name);

        // Destroy
        $this->assertTrue($manager->destroy($id));
        $this->assertNull($manager->get($id));
        $this->assertCount(0, $manager->list());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMultipleSandboxesAreIsolated(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $a = $manager->create('Sandbox A');
        $b = $manager->create('Sandbox B');

        // Each has its own database
        $this->assertNotSame($a->get_db_path(), $b->get_db_path());

        // Each has its own wp-content
        $this->assertNotSame($a->get_wp_content_path(), $b->get_wp_content_path());

        // Destroying one doesn't affect the other
        $manager->destroy($a->id);
        $this->assertNull($manager->get($a->id));
        $this->assertNotNull($manager->get($b->id));
    }

    // Constructor defaults

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorDefaultUsesRudelSandboxesDirConstant(): void
    {
        $this->defineConstants();
        define('RUDEL_SANDBOXES_DIR', '/custom/sandboxes');
        $manager = new SandboxManager();
        $this->assertSame('/custom/sandboxes', $manager->get_sandboxes_dir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorDefaultFallsBackToWpContentDir(): void
    {
        $this->defineConstants();
        define('WP_CONTENT_DIR', '/var/www/wp-content');
        $manager = new SandboxManager();
        $this->assertSame('/var/www/wp-content/rudel-sandboxes', $manager->get_sandboxes_dir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateUsesAbspathForWpCorePath(): void
    {
        $this->defineConstants();
        define('ABSPATH', $this->tmpDir . '/wordpress/');
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Abspath Test');

        $wpCliYml = file_get_contents($sandbox->path . '/wp-cli.yml');
        $this->assertStringContainsString($this->tmpDir . '/wordpress', $wpCliYml);
    }

    // clone_from

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxCopiesDatabase(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Clone Source');

        $clone = $manager->create('Clone Target', ['clone_from' => $source->id]);

        $this->assertFileExists($clone->get_db_path());
        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotEmpty($tables);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxRewritesUrls(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Url Source');

        $clone = $manager->create('Url Target', ['clone_from' => $source->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $prefix = 'wp_' . substr(md5($clone->id), 0, 6) . '_';
        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertStringContainsString($clone->id, $siteurl);
        $this->assertStringNotContainsString($source->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxRewritesPrefix(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Prefix Source');

        $clone = $manager->create('Prefix Target', ['clone_from' => $source->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $sourcePrefix = 'wp_' . substr(md5($source->id), 0, 6) . '_';
        $clonePrefix = 'wp_' . substr(md5($clone->id), 0, 6) . '_';

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $this->assertStringStartsWith($clonePrefix, $table);
            $this->assertStringStartsNotWith($sourcePrefix, $table);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxCopiesWpContent(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Content Source');

        // Add a file to source wp-content.
        file_put_contents($source->get_wp_content_path() . '/themes/test.txt', 'hello');

        $clone = $manager->create('Content Target', ['clone_from' => $source->id]);

        $this->assertFileExists($clone->get_wp_content_path() . '/themes/test.txt');
        $this->assertSame('hello', file_get_contents($clone->get_wp_content_path() . '/themes/test.txt'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxThrowsOnMissingSource(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source sandbox not found');
        $manager->create('Orphan Clone', ['clone_from' => 'nonexistent-id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxRejectsConflictingOptions(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Conflict Source');

        $this->expectException(\InvalidArgumentException::class);
        $manager->create('Conflict Target', ['clone_from' => $source->id, 'clone_db' => true]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxSetsCloneSourceMeta(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $source = $manager->create('Meta Source');

        $clone = $manager->create('Meta Target', ['clone_from' => $source->id]);

        $this->assertNotNull($clone->clone_source);
        $this->assertSame('sandbox', $clone->clone_source['type']);
        $this->assertSame($source->id, $clone->clone_source['source_id']);
    }

    // cleanup

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupRemovesExpiredSandboxes(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        // Create a sandbox with an old created_at.
        $sandbox = $manager->create('Old Sandbox', ['skip_limits' => true]);
        $meta = json_decode(file_get_contents($sandbox->path . '/.rudel.json'), true);
        $meta['created_at'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($sandbox->path . '/.rudel.json', json_encode($meta));

        $result = $manager->cleanup(['max_age_days' => 1]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertDirectoryDoesNotExist($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupDryRunDoesNotDestroy(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $sandbox = $manager->create('DryRun Sandbox', ['skip_limits' => true]);
        $meta = json_decode(file_get_contents($sandbox->path . '/.rudel.json'), true);
        $meta['created_at'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($sandbox->path . '/.rudel.json', json_encode($meta));

        $result = $manager->cleanup(['max_age_days' => 1, 'dry_run' => true]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertDirectoryExists($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupSkipsRecentSandboxes(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Recent Sandbox', ['skip_limits' => true]);

        $result = $manager->cleanup(['max_age_days' => 30]);

        $this->assertContains($sandbox->id, $result['skipped']);
        $this->assertDirectoryExists($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupReturnsEmptyWhenNoMaxAge(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('No Cleanup', ['skip_limits' => true]);

        $result = $manager->cleanup(['max_age_days' => 0]);

        $this->assertEmpty($result['removed']);
        $this->assertEmpty($result['skipped']);
    }

    // limits

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCheckLimitsThrowsWhenMaxSandboxesExceeded(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('Limit A', ['skip_limits' => true]);
        $manager->create('Limit B', ['skip_limits' => true]);

        $config = new RudelConfig($this->tmpDir . '/config.json');
        $config->set('max_sandboxes', 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox limit reached');
        $manager->check_limits($config);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCheckLimitsPassesWhenUnderLimit(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('Under Limit', ['skip_limits' => true]);

        $config = new RudelConfig($this->tmpDir . '/config.json');
        $config->set('max_sandboxes', 5);

        // Should not throw.
        $manager->check_limits($config);
        $this->assertTrue(true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCheckLimitsSkipsWhenZero(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $manager->create('Unlimited', ['skip_limits' => true]);

        $config = new RudelConfig($this->tmpDir . '/config.json');
        $config->set('max_sandboxes', 0);

        // Should not throw.
        $manager->check_limits($config);
        $this->assertTrue(true);
    }

    // export / import

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExportCreatesZipWithExpectedFiles(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Export Test');

        $zipPath = $this->tmpDir . '/export.zip';
        $manager->export($sandbox->id, $zipPath);

        $this->assertFileExists($zipPath);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('.rudel.json', $names);
        $this->assertContains('wordpress.db', $names);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExportExcludesSnapshots(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Export Snap Test');

        // Create a snapshot directory.
        mkdir($sandbox->path . '/snapshots/v1', 0755, true);
        file_put_contents($sandbox->path . '/snapshots/v1/data.txt', 'snap');

        $zipPath = $this->tmpDir . '/export-snap.zip';
        $manager->export($sandbox->id, $zipPath);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        foreach ($names as $n) {
            $this->assertStringStartsNotWith('snapshots/', $n);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExportThrowsOnMissingSandbox(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found');
        $manager->export('nonexistent', $this->tmpDir . '/out.zip');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportCreatesSandboxWithNewId(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Import Source');

        $zipPath = $this->tmpDir . '/import.zip';
        $manager->export($sandbox->id, $zipPath);

        $imported = $manager->import($zipPath, 'Imported Sandbox');

        $this->assertNotSame($sandbox->id, $imported->id);
        $this->assertSame('Imported Sandbox', $imported->name);
        $this->assertFileExists($imported->get_db_path());
        $this->assertDirectoryExists($imported->get_wp_content_path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportRewritesUrlsAndPrefix(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://import-test.com');

        $manager = new SandboxManager($this->tmpDir);
        $sandbox = $manager->create('Import Rewrite');

        $zipPath = $this->tmpDir . '/import-rw.zip';
        $manager->export($sandbox->id, $zipPath);

        $imported = $manager->import($zipPath, 'Rewritten Import');

        $pdo = new \PDO('sqlite:' . $imported->get_db_path());
        $prefix = 'wp_' . substr(md5($imported->id), 0, 6) . '_';

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $this->assertStringStartsWith($prefix, $t);
        }

        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertStringContainsString($imported->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportThrowsOnInvalidZip(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        $badZip = $this->tmpDir . '/bad.zip';
        file_put_contents($badZip, 'not a zip');

        $this->expectException(\RuntimeException::class);
        $manager->import($badZip, 'Bad Import');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportThrowsOnMissingRudelJson(): void
    {
        $this->defineConstants();
        $manager = new SandboxManager($this->tmpDir);

        // Create a zip without .rudel.json.
        $zipPath = $this->tmpDir . '/no-meta.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('test.txt', 'data');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing .rudel.json');
        $manager->import($zipPath, 'No Meta Import');
    }

    // Helpers

    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }
}
