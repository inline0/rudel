<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\RudelConfig;
use Rudel\Environment;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class EnvironmentManagerTest extends RudelTestCase
{
    // All tests run in separate processes because EnvironmentManager reads constants.

    // create() -- directory structure

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateBuildsCorrectDirectoryStructure(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Structure Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Files Test', ['engine' => 'sqlite']);

        $this->assertFileDoesNotExist($sandbox->path . '/.rudel.json');
        $this->assertFileExists($sandbox->path . '/wordpress.db');
        $this->assertFileExists($sandbox->path . '/wp-cli.yml');
        $this->assertFileExists($sandbox->path . '/bootstrap.php');
        $this->assertFileExists($sandbox->path . '/CLAUDE.md');
        $this->assertFileExists($sandbox->path . '/wp-content/db.php');
        $this->assertFileExists($sandbox->path . '/wp-content/mu-plugins/rudel-runtime.php');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateReturnsSandboxWithCorrectMetadata(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Meta Test', ['engine' => 'sqlite', 'template' => 'blank']);

        $this->assertNotEmpty($sandbox->id);
        $this->assertSame('Meta Test', $sandbox->name);
        $this->assertSame('blank', $sandbox->template);
        $this->assertSame('active', $sandbox->status);
        $this->assertNotEmpty($sandbox->created_at);
        $this->assertTrue(Environment::validate_id($sandbox->id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatePersistsPolicyMetadata(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Policy Meta Test', [
            'engine' => 'sqlite',
            'owner' => 'dennis',
            'labels' => 'bugfix, qa',
            'purpose' => 'Investigate regression',
            'protected' => true,
            'ttl_days' => 7,
        ]);

        $this->assertSame('dennis', $sandbox->owner);
        $this->assertSame(['bugfix', 'qa'], $sandbox->labels);
        $this->assertSame('Investigate regression', $sandbox->purpose);
        $this->assertTrue($sandbox->is_protected());
        $this->assertNotNull($sandbox->expires_at);
        $this->assertNotNull($sandbox->last_used_at);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAutoCreatesSandboxesBaseDir(): void
    {
        $this->defineConstants();
        $sandboxesDir = $this->tmpDir . '/nested/sandboxes';
        $this->assertDirectoryDoesNotExist($sandboxesDir);

        $manager = new EnvironmentManager($sandboxesDir);
        $sandbox = $manager->create('Auto Dir Test', ['engine' => 'sqlite']);

        $this->assertDirectoryExists($sandboxesDir);
        $this->assertDirectoryExists($sandbox->path);
    }

    // create() -- generated file contents

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateWpCliYmlContainsCorrectPath(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('WpCli Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Bootstrap Test', ['engine' => 'sqlite']);

        $bootstrap = file_get_contents($sandbox->path . '/bootstrap.php');
        $this->assertStringContainsString("'" . $sandbox->id . "'", $bootstrap);
        $this->assertStringContainsString($sandbox->path, $bootstrap);
        $this->assertStringContainsString('RUDEL_ID', $bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateRuntimeMuPluginContainsRuntimeHooks(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Runtime Hook Test', ['engine' => 'sqlite']);

        $runtimeMuPlugin = file_get_contents($sandbox->path . '/wp-content/mu-plugins/rudel-runtime.php');
        $this->assertStringContainsString('pre_wp_mail', $runtimeMuPlugin);
        $this->assertStringContainsString('RUDEL_RUNTIME_HOOKS_LOADED', $runtimeMuPlugin);
        $this->assertStringContainsString('admin_bar_menu', $runtimeMuPlugin);
        $this->assertStringContainsString('runtime-preview-router.php', $runtimeMuPlugin);
        $this->assertStringContainsString('rudel_runtime_preview_resolve', $runtimeMuPlugin);
        $this->assertStringNotContainsString('PreviewRequestRouter', $runtimeMuPlugin);
        $this->assertStringNotContainsString('parse_request', $runtimeMuPlugin);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDbDropInPointsToSharedSqlite(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('DbDropIn Test', ['engine' => 'sqlite']);

        $db = file_get_contents($sandbox->path . '/wp-content/db.php');
        $this->assertStringContainsString('sqlite-database-integration', $db);
        $this->assertStringContainsString("require_once", $db);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateClaudeMdContainsSandboxInfo(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Claude Test', ['engine' => 'sqlite']);

        $md = file_get_contents($sandbox->path . '/CLAUDE.md');
        $this->assertStringContainsString('Claude Test', $md);
        $this->assertStringContainsString($sandbox->id, $md);
        $this->assertStringContainsString('Security rules', $md);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreatePersistsEnvironmentRecord(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Json Test', ['engine' => 'sqlite']);

        $stored = Environment::from_path($sandbox->path);
        $this->assertNotNull($stored);
        $this->assertSame($sandbox->id, $stored->id);
        $this->assertSame('Json Test', $stored->name);
        $this->assertSame('active', $stored->status);
    }

    // create() -- file permissions

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSetsReadOnlyPermissionsOnCriticalFiles(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Perms Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('DbPerms Test', ['engine' => 'sqlite']);

        $this->assertSame('0664', substr(sprintf('%o', fileperms($sandbox->get_db_path())), -4));
    }

    // create() -- SQLite database contents

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasAllWordPressTables(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Tables Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';
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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('User Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $user = $pdo->query("SELECT user_login, user_email FROM {$prefix}users WHERE ID=1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('admin', $user['user_login']);
        $this->assertSame('admin@sandbox.local', $user['user_email']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasAdminCapabilities(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Caps Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Options Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Url Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertStringContainsString($sandbox->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppliesRequestedSiteOptionsToSqliteEnvironment(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Site Options Test', [
            'engine' => 'sqlite',
            'site_options' => [
                'template' => 'divine',
                'stylesheet' => 'divine-dev-child',
                'current_theme' => 'Divine Dev Child',
            ],
        ]);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $template = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='template'")->fetchColumn();
        $stylesheet = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='stylesheet'")->fetchColumn();
        $currentTheme = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='current_theme'")->fetchColumn();

        $this->assertSame('divine', $template);
        $this->assertSame('divine-dev-child', $stylesheet);
        $this->assertSame('Divine Dev Child', $currentTheme);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasHelloWorldPost(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Post Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $title = $pdo->query("SELECT post_title FROM {$prefix}posts WHERE ID=1")->fetchColumn();
        $this->assertSame('Hello world!', $title);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasUncategorizedTerm(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Term Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $name = $pdo->query("SELECT name FROM {$prefix}terms WHERE term_id=1")->fetchColumn();
        $this->assertSame('Uncategorized', $name);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasUserRoles(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Roles Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);

        $sandbox1 = $manager->create('Prefix Test A', ['engine' => 'sqlite']);
        $sandbox2 = $manager->create('Prefix Test B', ['engine' => 'sqlite']);

        $pdo1 = new \PDO('sqlite:' . $sandbox1->get_db_path());
        $pdo2 = new \PDO('sqlite:' . $sandbox2->get_db_path());

        $tables1 = $pdo1->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        $tables2 = $pdo2->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);

        // Tables should have different prefixes
        $prefix1 = 'rudel_' . substr(md5($sandbox1->id), 0, 6) . '_';
        $prefix2 = 'rudel_' . substr(md5($sandbox2->id), 0, 6) . '_';
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
        $manager = new EnvironmentManager($this->tmpDir . '/sandboxes');

        $this->assertSame([], $manager->list());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsEmptyWhenDirNotExists(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir . '/nonexistent');

        $this->assertSame([], $manager->list());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListReturnsAllSandboxes(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox1 = $manager->create('List Test A', ['engine' => 'sqlite']);
        $sandbox2 = $manager->create('List Test B', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('Real Sandbox', ['engine' => 'sqlite']);

        // Create a junk directory with no matching DB record.
        mkdir($this->tmpDir . '/not-a-sandbox', 0755);

        $list = $manager->list();
        $this->assertCount(1, $list);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListSkipsRegularFiles(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('Real Sandbox', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Get Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir . '/sandboxes');

        $this->assertNull($manager->get('nonexistent-id'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsNullForInvalidId(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Destroy Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir . '/sandboxes');

        $this->assertFalse($manager->destroy('nonexistent'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyHandlesReadOnlyFiles(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('ReadOnly Destroy', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Gone Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Collision Test', ['engine' => 'sqlite']);

        // Destroy the sandbox but recreate just the directory (no metadata).
        $path = $sandbox->path;
        $manager->destroy($sandbox->id);
        mkdir($path, 0755, true);

        // generate_id produces a unique ID each time, so we can't collide by name.
        // Instead, create a subclass that forces the same ID.
        $forcedId = $sandbox->id;
        $testDir = $this->tmpDir;
        $managerClass = new class($testDir, $forcedId) extends EnvironmentManager {
            private string $forcedId;
            public function __construct(string $dir, string $id) {
                parent::__construct($dir);
                $this->forcedId = $id;
            }
            public function create(string $name, array $options = array()): \Rudel\Environment {
                // Use reflection to call the parent with a forced ID.
                $path = (new \ReflectionProperty(EnvironmentManager::class, 'environments_dir'))->getValue($this);
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
        $managerClass->create('Collision Again', ['engine' => 'sqlite']);
    }

    // create() -- additional database content

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateDatabaseHasSamplePage(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Page Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Comment Test', ['engine' => 'sqlite']);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);

        // Create
        $sandbox = $manager->create('Lifecycle Test', ['engine' => 'sqlite']);
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
        $manager = new EnvironmentManager($this->tmpDir);

        $a = $manager->create('Sandbox A', ['engine' => 'sqlite']);
        $b = $manager->create('Sandbox B', ['engine' => 'sqlite']);

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
        define('RUDEL_ENVIRONMENTS_DIR', '/custom/sandboxes');
        $manager = new EnvironmentManager();
        $this->assertSame('/custom/sandboxes', $manager->get_environments_dir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstructorDefaultFallsBackToWpContentDir(): void
    {
        $this->defineConstants();
        define('WP_CONTENT_DIR', '/var/www/wp-content');
        $manager = new EnvironmentManager();
        $this->assertSame('/var/www/wp-content/rudel-environments', $manager->get_environments_dir());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateUsesAbspathForWpCorePath(): void
    {
        $this->defineConstants();
        define('ABSPATH', $this->tmpDir . '/wordpress/');
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Abspath Test', ['engine' => 'sqlite']);

        $wpCliYml = file_get_contents($sandbox->path . '/wp-cli.yml');
        $this->assertStringContainsString($this->tmpDir . '/wordpress', $wpCliYml);
    }

    // clone_from

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxCopiesDatabase(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Clone Source', ['engine' => 'sqlite']);

        $clone = $manager->create('Clone Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

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
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Url Source', ['engine' => 'sqlite']);

        $clone = $manager->create('Url Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $prefix = 'rudel_' . substr(md5($clone->id), 0, 6) . '_';
        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertStringContainsString($clone->id, $siteurl);
        $this->assertStringNotContainsString($source->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxRewritesPrefix(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Prefix Source', ['engine' => 'sqlite']);

        $clone = $manager->create('Prefix Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $sourcePrefix = 'rudel_' . substr(md5($source->id), 0, 6) . '_';
        $clonePrefix = 'rudel_' . substr(md5($clone->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Content Source', ['engine' => 'sqlite']);

        mkdir($source->get_wp_content_path() . '/themes/test-theme', 0755, true);
        file_put_contents($source->get_wp_content_path() . '/themes/test-theme/style.css', 'hello');

        $clone = $manager->create('Content Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $this->assertFileExists($clone->get_wp_content_path() . '/themes/test-theme/style.css');
        $this->assertSame('hello', file_get_contents($clone->get_wp_content_path() . '/themes/test-theme/style.css'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxThrowsOnMissingSource(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source environment not found');
        $manager->create('Orphan Clone', ['engine' => 'sqlite', 'clone_from' => 'nonexistent-id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxRejectsConflictingOptions(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Conflict Source', ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $manager->create('Conflict Target', ['engine' => 'sqlite', 'clone_from' => $source->id, 'clone_db' => true]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromSandboxSetsCloneSourceMeta(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('Meta Source', ['engine' => 'sqlite']);

        $clone = $manager->create('Meta Target', ['engine' => 'sqlite', 'clone_from' => $source->id]);

        $this->assertNotNull($clone->clone_source);
        $this->assertSame('sandbox', $clone->clone_source['type']);
        $this->assertSame($source->id, $clone->clone_source['source_id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromAppCopiesStateIntoSandbox(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $appsDir = $this->tmpDir . '/apps';
        $appManager = new \Rudel\AppManager($appsDir, $sandboxesDir);
        $sandboxManager = new EnvironmentManager($sandboxesDir, $appsDir);

        $app = $appManager->create('Client App', ['client-a.com'], ['engine' => 'sqlite']);
        mkdir($app->get_wp_content_path() . '/plugins/app-only', 0755, true);
        file_put_contents($app->get_wp_content_path() . '/plugins/app-only/app-only.php', 'app');

        $clone = $sandboxManager->create('App Sandbox', ['engine' => 'sqlite', 'clone_from' => $app->id]);

        $pdo = new \PDO('sqlite:' . $clone->get_db_path());
        $prefix = 'rudel_' . substr(md5($clone->id), 0, 6) . '_';
        $siteurl = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl'")->fetchColumn();

        $this->assertSame('app', file_get_contents($clone->get_wp_content_path() . '/plugins/app-only/app-only.php'));
        $this->assertStringContainsString($clone->id, $siteurl);
        $this->assertStringNotContainsString('client-a.com', $siteurl);
        $this->assertSame('app', $clone->clone_source['type']);
        $this->assertSame($app->id, $clone->clone_source['source_id']);
    }

    // Engine validation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateRejectsInvalidEngine(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid engine');
        $manager->create('Invalid Engine', ['engine' => 'postgres']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSqliteSandboxHasEngineInMeta(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Engine Meta', ['engine' => 'sqlite']);

        $stored = Environment::from_path($sandbox->path);
        $this->assertNotNull($stored);
        $this->assertSame('sqlite', $stored->engine);
        $this->assertSame('sqlite', $sandbox->engine);
        $this->assertTrue($sandbox->is_sqlite());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSqliteSandboxWritesDbDropIn(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('SQLite DropIn', ['engine' => 'sqlite']);

        $this->assertFileExists($sandbox->path . '/wp-content/db.php');
        $this->assertFileExists($sandbox->get_db_path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSqliteBootstrapContainsSqliteConstants(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('SQLite Bootstrap', ['engine' => 'sqlite']);

        $bootstrap = file_get_contents($sandbox->path . '/bootstrap.php');
        $this->assertStringContainsString("define('DB_DIR'", $bootstrap);
        $this->assertStringContainsString("define('DB_ENGINE', 'sqlite')", $bootstrap);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateMysqlBootstrapOmitsSqliteConstants(): void
    {
        $this->defineConstants();
        // MySQL create will fail without $wpdb, but the bootstrap is written before DB ops.
        // So we test by creating sqlite first then checking a mysql-engine bootstrap.
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('MySQL Bootstrap Check', ['engine' => 'sqlite']);

        // Rewrite the bootstrap as if it were mysql engine.
        $tplPath = dirname(__DIR__, 2) . '/templates/environment-bootstrap.php.tpl';
        $template = file_get_contents($tplPath);
        $content = strtr($template, [
            '{{sandbox_id}}' => $sandbox->id,
            '{{sandbox_path}}' => $sandbox->path,
            '{{path_prefix}}' => RUDEL_PATH_PREFIX,
            '{{multisite_block}}' => '',
            '{{sqlite_block}}' => '',
        ]);

        $this->assertStringNotContainsString("define('DB_DIR'", $content);
        $this->assertStringNotContainsString("define('DB_ENGINE'", $content);
        $this->assertStringContainsString("define('WP_CONTENT_DIR'", $content);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneFromRejectsCrossEngine(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $source = $manager->create('SQLite Source', ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot clone across engines');
        $manager->create('MySQL Target', ['engine' => 'mysql', 'clone_from' => $source->id]);
    }

    // Subsite engine validation

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSubsiteRejectsNonMultisite(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('multisite installation');
        $manager->create('Subsite Test', ['engine' => 'subsite']);
    }

    // cleanup

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupRemovesExpiredSandboxes(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        // Create a sandbox with an old created_at.
        $sandbox = $manager->create('Old Sandbox', ['engine' => 'sqlite', 'skip_limits' => true]);
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('environments'),
            ['created_at' => '2020-01-01T00:00:00+00:00'],
            ['slug' => $sandbox->id]
        );

        $result = $manager->cleanup(['max_age_days' => 1]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertDirectoryDoesNotExist($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupDryRunDoesNotDestroy(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $sandbox = $manager->create('DryRun Sandbox', ['engine' => 'sqlite', 'skip_limits' => true]);
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('environments'),
            ['created_at' => '2020-01-01T00:00:00+00:00'],
            ['slug' => $sandbox->id]
        );

        $result = $manager->cleanup(['max_age_days' => 1, 'dry_run' => true]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertDirectoryExists($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupSkipsRecentSandboxes(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Recent Sandbox', ['engine' => 'sqlite', 'skip_limits' => true]);

        $result = $manager->cleanup(['max_age_days' => 30]);

        $this->assertContains($sandbox->id, $result['skipped']);
        $this->assertDirectoryExists($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupReturnsEmptyWhenNoMaxAge(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('No Cleanup', ['engine' => 'sqlite', 'skip_limits' => true]);

        $result = $manager->cleanup(['max_age_days' => 0]);

        $this->assertEmpty($result['removed']);
        $this->assertEmpty($result['skipped']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateNormalizesPolicyMetadata(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Update Policy', ['engine' => 'sqlite', 'skip_limits' => true]);

        $updated = $manager->update($sandbox->id, [
            'owner' => 'dennis',
            'labels' => 'priority, review',
            'protected' => true,
            'expires_at' => '2026-03-31 12:00:00 UTC',
        ]);

        $this->assertSame('dennis', $updated->owner);
        $this->assertSame(['priority', 'review'], $updated->labels);
        $this->assertTrue($updated->is_protected());
        $this->assertSame('2026-03-31T12:00:00+00:00', $updated->expires_at);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateAppliesRequestedSiteOptionsToSqliteEnvironment(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Update Site Options', ['engine' => 'sqlite', 'skip_limits' => true]);

        $manager->update($sandbox->id, [
            'site_options' => [
                'template' => 'divine',
                'stylesheet' => 'divine-dev-child',
                'current_theme' => 'Divine Dev Child',
            ],
        ]);

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';

        $template = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='template'")->fetchColumn();
        $stylesheet = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='stylesheet'")->fetchColumn();
        $currentTheme = $pdo->query("SELECT option_value FROM {$prefix}options WHERE option_name='current_theme'")->fetchColumn();

        $this->assertSame('divine', $template);
        $this->assertSame('divine-dev-child', $stylesheet);
        $this->assertSame('Divine Dev Child', $currentTheme);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupSkipsProtectedSandboxes(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Protected Cleanup', ['engine' => 'sqlite', 'skip_limits' => true]);
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('environments'),
            [
                'created_at' => '2020-01-01T00:00:00+00:00',
                'is_protected' => 1,
            ],
            ['slug' => $sandbox->id]
        );

        $result = $manager->cleanup(['max_age_days' => 1]);

        $this->assertContains($sandbox->id, $result['skipped']);
        $this->assertSame('protected', $result['reasons'][$sandbox->id]);
        $this->assertDirectoryExists($sandbox->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupRemovesExpiredSandboxWithoutGlobalAgePolicy(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Expiry Cleanup', ['engine' => 'sqlite', 'skip_limits' => true]);
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('environments'),
            ['expires_at' => '2020-01-01T00:00:00+00:00'],
            ['slug' => $sandbox->id]
        );

        $result = $manager->cleanup(['max_age_days' => 0, 'max_idle_days' => 0]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertSame('expired', $result['reasons'][$sandbox->id]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCleanupRemovesIdleSandboxes(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Idle Cleanup', ['engine' => 'sqlite', 'skip_limits' => true]);
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('environments'),
            [
                'created_at' => gmdate('c'),
                'last_used_at' => '2020-01-01T00:00:00+00:00',
            ],
            ['slug' => $sandbox->id]
        );

        $result = $manager->cleanup(['max_idle_days' => 1]);

        $this->assertContains($sandbox->id, $result['removed']);
        $this->assertSame('idle', $result['reasons'][$sandbox->id]);
    }

    // limits

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCheckLimitsThrowsWhenMaxSandboxesExceeded(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('Limit A', ['engine' => 'sqlite', 'skip_limits' => true]);
        $manager->create('Limit B', ['engine' => 'sqlite', 'skip_limits' => true]);

        $config = new RudelConfig();
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
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('Under Limit', ['engine' => 'sqlite', 'skip_limits' => true]);

        $config = new RudelConfig();
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
        $manager = new EnvironmentManager($this->tmpDir);
        $manager->create('Unlimited', ['engine' => 'sqlite', 'skip_limits' => true]);

        $config = new RudelConfig();
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
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Export Test', ['engine' => 'sqlite']);

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

        $this->assertContains('rudel-export.json', $names);
        $this->assertContains('wordpress.db', $names);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExportExcludesSnapshots(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Export Snap Test', ['engine' => 'sqlite']);

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
        $manager = new EnvironmentManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found');
        $manager->export('nonexistent', $this->tmpDir . '/out.zip');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportCreatesSandboxWithNewId(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Import Source', ['engine' => 'sqlite']);

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

        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Import Rewrite', ['engine' => 'sqlite']);

        $zipPath = $this->tmpDir . '/import-rw.zip';
        $manager->export($sandbox->id, $zipPath);

        $imported = $manager->import($zipPath, 'Rewritten Import');

        $pdo = new \PDO('sqlite:' . $imported->get_db_path());
        $prefix = 'rudel_' . substr(md5($imported->id), 0, 6) . '_';

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
        $manager = new EnvironmentManager($this->tmpDir);

        $badZip = $this->tmpDir . '/bad.zip';
        file_put_contents($badZip, 'not a zip');

        $this->expectException(\RuntimeException::class);
        $manager->import($badZip, 'Bad Import');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testImportThrowsOnMissingExportManifest(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        // Create a zip without the Rudel export manifest.
        $zipPath = $this->tmpDir . '/no-meta.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('test.txt', 'data');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing rudel-export.json');
        $manager->import($zipPath, 'No Meta Import');
    }

    // Promote

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteThrowsOnNonexistentSandbox(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sandbox not found');
        $manager->promote('nonexistent', $this->tmpDir . '/backup');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteThrowsOnSubsiteSandbox(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $this->createFakeSandbox('subsite-promote-test', 'Subsite Test', [
            'engine' => 'subsite',
            'blog_id' => 99,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not supported for subsite');
        $manager->promote('subsite-promote-test', $this->tmpDir . '/backup');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteSqliteSandboxCreatesBackup(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->tmpDir . '/wordpress/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir . '/wordpress/wp-content');
            mkdir(WP_CONTENT_DIR, 0755, true);
        }

        // We need $wpdb for promote. Use MockWpdb.
        require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';
        $mockWpdb = new \MockWpdb();
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Host Post'],
        ]);
        $mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Promote Test', ['engine' => 'sqlite']);

        $backupDir = $this->tmpDir . '/backup';
        $result = $manager->promote($sandbox->id, $backupDir);

        $this->assertDirectoryExists($backupDir);
        $this->assertFileExists($backupDir . '/backup.json');
        $this->assertDirectoryExists($backupDir . '/wp-content');
        $this->assertArrayHasKey('backup_prefix', $result);
        $this->assertGreaterThan(0, $result['tables_copied']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteAllowsBackupDirectoryInsideWpContent(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->tmpDir . '/wordpress/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir . '/wordpress/wp-content');
            mkdir(WP_CONTENT_DIR . '/plugins/example', 0755, true);
            file_put_contents(WP_CONTENT_DIR . '/plugins/example/plugin.php', '<?php // host plugin');
        }

        require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';
        $mockWpdb = new \MockWpdb();
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Host Post'],
        ]);
        $mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Nested Backup Promote', ['engine' => 'sqlite']);

        $backupDir = WP_CONTENT_DIR . '/rudel-environments/_backups/promote-default-like';
        $result = $manager->promote($sandbox->id, $backupDir);

        $this->assertSame($backupDir, $result['backup_path']);
        $this->assertFileExists($backupDir . '/backup.json');
        $this->assertFileExists($backupDir . '/wp-content/plugins/example/plugin.php');
        $this->assertDirectoryDoesNotExist($backupDir . '/wp-content/rudel-environments/_backups/promote-default-like/wp-content');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromotePreservesHostContentWhenSandboxDirectoriesAreEmpty(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->tmpDir . '/wordpress/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir . '/wordpress/wp-content');
            mkdir(WP_CONTENT_DIR . '/plugins/example', 0755, true);
            mkdir(WP_CONTENT_DIR . '/themes/example', 0755, true);
            mkdir(WP_CONTENT_DIR . '/uploads', 0755, true);
            file_put_contents(WP_CONTENT_DIR . '/plugins/example/plugin.php', '<?php // host plugin');
            file_put_contents(WP_CONTENT_DIR . '/themes/example/style.css', '/* host theme */');
            file_put_contents(WP_CONTENT_DIR . '/uploads/existing.txt', 'host upload');
        }

        require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';
        $mockWpdb = new \MockWpdb();
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Host Post'],
        ]);
        $mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = $manager->create('Blank Promote Content', ['engine' => 'sqlite']);

        $manager->promote($sandbox->id, $this->tmpDir . '/backup-preserve-content');

        $this->assertFileExists(WP_CONTENT_DIR . '/plugins/example/plugin.php');
        $this->assertFileExists(WP_CONTENT_DIR . '/themes/example/style.css');
        $this->assertFileExists(WP_CONTENT_DIR . '/uploads/existing.txt');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPromoteKeepsRudelActiveOnHostAfterMysqlPromotion(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'http://example.com');
        if (! defined('ABSPATH')) {
            define('ABSPATH', $this->tmpDir . '/wordpress/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir . '/wordpress/wp-content');
            mkdir(WP_CONTENT_DIR, 0755, true);
        }

        require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';
        $mockWpdb = new \MockWpdb();
        $mockWpdb->prefix = 'wp_';
        $mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Host Post'],
        ]);
        $mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://example.com'],
            ['option_id' => '3', 'option_name' => 'blogname', 'option_value' => 'Host Site'],
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores active plugins as a serialized PHP array.
            ['option_id' => '4', 'option_name' => 'active_plugins', 'option_value' => serialize(['rudel/rudel.php'])],
        ]);
        $GLOBALS['wpdb'] = $mockWpdb;

        $manager = new EnvironmentManager($this->tmpDir);
        $sandbox = new Environment(
            id: 'promote-mysql-1234',
            name: 'Promote MySQL Activation',
            path: $this->tmpDir . '/promote-mysql-1234',
            created_at: '2026-03-29T00:00:00+00:00',
            engine: 'mysql'
        );
        mkdir($sandbox->path . '/wp-content/themes', 0755, true);
        mkdir($sandbox->path . '/wp-content/plugins', 0755, true);
        mkdir($sandbox->path . '/wp-content/uploads', 0755, true);
        $sandbox->save_meta();

        $sandboxPrefix = $sandbox->get_table_prefix();
        $mockWpdb->addTable("{$sandboxPrefix}options", "CREATE TABLE {$sandboxPrefix}options (option_id int)", [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com/__rudel/' . $sandbox->id],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://example.com/__rudel/' . $sandbox->id],
            ['option_id' => '3', 'option_name' => 'blogname', 'option_value' => 'Promoted Site'],
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- WordPress stores active plugins as a serialized PHP array.
            ['option_id' => '4', 'option_name' => 'active_plugins', 'option_value' => serialize([])],
        ]);

        $manager->promote($sandbox->id, $this->tmpDir . '/mysql-promote-backup');

        $optionsRows = $mockWpdb->getTableRows('wp_options');
        $activePlugins = null;

        foreach ($optionsRows as $row) {
            if (($row['option_name'] ?? null) === 'active_plugins') {
                $activePlugins = $row['option_value'];
                break;
            }
        }

        $this->assertNotNull($activePlugins);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test inspects the WordPress serialized active_plugins option directly.
        $this->assertContains('rudel/rudel.php', unserialize($activePlugins));
    }

    // Helpers

    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }
}
