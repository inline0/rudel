<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\AppManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }

    private function hasGit(): bool
    {
        exec('git --version 2>&1', $output, $code);
        return 0 === $code;
    }

    private function createGitRepo(string $path, string $relativeFile = 'style.css', string $contents = '/* theme */'): void
    {
        mkdir($path, 0755, true);
        exec('git -C ' . escapeshellarg($path) . ' init 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' config user.email "test@test.com" 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' config user.name "Test" 2>&1');

        $filePath = $path . '/' . $relativeFile;
        $fileDir = dirname($filePath);
        if (! is_dir($fileDir)) {
            mkdir($fileDir, 0755, true);
        }

        file_put_contents($filePath, $contents);
        exec('git -C ' . escapeshellarg($path) . ' add -A 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' commit -m "init" 2>&1');
        exec('git -C ' . escapeshellarg($path) . ' branch -M main 2>&1');
    }

    private function createGitWorktree(string $repoPath, string $targetPath, string $branch): void
    {
        exec(
            'git -C ' . escapeshellarg($repoPath) . ' worktree add -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($targetPath) . ' HEAD 2>&1',
            $output,
            $code
        );

        $this->assertSame(0, $code, implode("\n", $output));
    }

    // create()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppWithDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Client A', ['client-a.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]);

        $this->assertNotEmpty($app->id);
        $this->assertSame('Client A', $app->name);
        $this->assertSame('app', $app->type);
        $this->assertTrue($app->is_app());
        $this->assertSame(['client-a.com'], $app->domains);
        $this->assertSame('inline0/client-a-theme', $app->tracked_github_repo);
        $this->assertSame('main', $app->tracked_github_branch);
        $this->assertSame('themes/client-a', $app->tracked_github_dir);
        $this->assertDirectoryExists($app->path);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppPersistsDomainsInRuntimeStore(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Map Test', ['mapped.com'], ['engine' => 'sqlite']);

        $this->assertFileDoesNotExist($this->tmpDir . '/domains.json');
        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('mapped.com', $map);
        $this->assertSame($app->id, $map['mapped.com']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppNormalizesDomainsToLowercase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Case Test', ['Client-A.COM'], ['engine' => 'sqlite']);

        $this->assertSame(['client-a.com'], $app->domains);

        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('client-a.com', $map);
        $this->assertSame($app->id, $map['client-a.com']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRequiresDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one domain');
        $manager->create('No Domain', [], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsInvalidDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid domain');
        $manager->create('Bad Domain', ['not a domain'], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsSubsiteEngine(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('subsite engine');
        $manager->create('Subsite App', ['sub.com'], ['engine' => 'subsite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsDuplicateDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $manager->create('First App', ['taken.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already mapped');
        $manager->create('Second App', ['taken.com'], ['engine' => 'sqlite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppRejectsDuplicateDomainIgnoringCase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $manager->create('First App', ['Taken.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already mapped');
        $manager->create('Second App', ['taken.COM'], ['engine' => 'sqlite']);
    }

    // list() / get()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testListAndGet(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('List Test', ['list-test.com'], ['engine' => 'sqlite']);

        $all = $manager->list();
        $this->assertCount(1, $all);

        $found = $manager->get($app->id);
        $this->assertNotNull($found);
        $this->assertSame($app->id, $found->id);
    }

    // destroy()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDestroyRemovesAppAndDomainMap(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Destroy Test', ['destroy-test.com'], ['engine' => 'sqlite']);

        $this->assertTrue($manager->destroy($app->id));
        $this->assertNull($manager->get($app->id));

        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('destroy-test.com', $map);
    }

    // add_domain() / remove_domain()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddAndRemoveDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Test', ['primary.com'], ['engine' => 'sqlite']);

        $manager->add_domain($app->id, 'secondary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('secondary.com', $map);
        $this->assertSame($app->id, $map['secondary.com']);

        $manager->remove_domain($app->id, 'secondary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('secondary.com', $map);
        $this->assertArrayHasKey('primary.com', $map);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddAndRemoveDomainNormalizeCase(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Domain Case Test', ['primary.com'], ['engine' => 'sqlite']);

        $manager->add_domain($app->id, 'WWW.Primary.COM');
        $map = $manager->get_domain_map();
        $this->assertArrayHasKey('www.primary.com', $map);
        $this->assertSame($app->id, $map['www.primary.com']);

        $manager->remove_domain($app->id, 'www.primary.com');
        $map = $manager->get_domain_map();
        $this->assertArrayNotHasKey('www.primary.com', $map);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCannotRemoveLastDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('Last Domain', ['only.com'], ['engine' => 'sqlite']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('last domain');
        $manager->remove_domain($app->id, 'only.com');
    }

    // get_url()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppGetUrlReturnsDomain(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir);
        $app = $manager->create('URL Test', ['url-test.com'], ['engine' => 'sqlite']);

        $this->assertSame('https://url-test.com/', $app->get_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxFromAppClonesIntoSandboxDirectory(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $appsDir = $this->tmpDir . '/apps';
        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $manager = new AppManager($appsDir, $sandboxesDir);
        $app = $manager->create('Client A', ['client-a.com'], ['engine' => 'sqlite']);
        file_put_contents($app->get_wp_content_path() . '/themes/app.txt', 'from-app');

        $sandbox = $manager->create_sandbox($app->id, 'Client A Sandbox');

        $this->assertSame('sandbox', $sandbox->type);
        $this->assertFileExists($sandboxesDir . '/' . $sandbox->id . '/wp-content/themes/app.txt');
        $this->assertSame('from-app', file_get_contents($sandbox->get_wp_content_path() . '/themes/app.txt'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxFromAppInheritsTrackedGithubMetadata(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Client A', ['client-a.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]);

        $sandbox = $manager->create_sandbox($app->id, 'Client A Sandbox');

        $this->assertSame('inline0/client-a-theme', $sandbox->clone_source['github_repo'] ?? null);
        $this->assertSame('main', $sandbox->clone_source['github_base_branch'] ?? null);
        $this->assertSame('themes/client-a', $sandbox->clone_source['github_dir'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxFromAppCreatesGitWorktreeMetadataForThemeRepos(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Git App', ['git-app.com'], ['engine' => 'sqlite']);

        $themeRepo = $this->tmpDir . '/theme-repo';
        $this->createGitRepo($themeRepo, 'style.css', '/* app theme */');

        $appThemePath = $app->get_wp_content_path() . '/themes/client-a';
        $this->createGitWorktree($themeRepo, $appThemePath, 'app-client-a');

        $sandbox = $manager->create_sandbox($app->id, 'Git Sandbox');
        $sandboxThemePath = $sandbox->get_wp_content_path() . '/themes/client-a';

        $this->assertFileExists($sandboxThemePath . '/.git');
        $this->assertSame('/* app theme */', file_get_contents($sandboxThemePath . '/style.css'));
        $this->assertNotEmpty($sandbox->clone_source['git_worktrees'] ?? []);
        $this->assertSame('themes', $sandbox->clone_source['git_worktrees'][0]['type']);
        $this->assertSame('client-a', $sandbox->clone_source['git_worktrees'][0]['name']);
        $this->assertSame('rudel/' . $sandbox->id, $sandbox->clone_source['git_worktrees'][0]['branch']);

        exec('git -C ' . escapeshellarg($sandboxThemePath) . ' branch --show-current 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('rudel/' . $sandbox->id, trim($output[0] ?? ''));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBackupAndRestoreAppRoundTripsState(): void
    {
        $this->defineConstants();

        $appsDir = $this->tmpDir . '/apps';
        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $manager = new AppManager($appsDir, $sandboxesDir);
        $app = $manager->create('Backup App', ['backup-app.com'], ['engine' => 'sqlite']);

        $backup = $manager->backup($app->id, 'baseline');
        file_put_contents($app->get_wp_content_path() . '/plugins/changed.txt', 'changed');
        unlink($app->get_db_path());

        $manager->restore($app->id, 'baseline');

        $this->assertSame('baseline', $backup['name']);
        $this->assertFileExists($app->path . '/backups/baseline/backup.json');
        $this->assertFileExists($app->get_db_path());
        $this->assertFileDoesNotExist($app->get_wp_content_path() . '/plugins/changed.txt');

        $preRestoreBackups = glob($app->path . '/backups/pre-restore-*');
        $this->assertNotEmpty($preRestoreBackups);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeployCreatesBackupAndRewritesSandboxUrlBackToAppDomain(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $appsDir = $this->tmpDir . '/apps';
        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $manager = new AppManager($appsDir, $sandboxesDir);
        $app = $manager->create('Deploy App', ['deploy-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Deploy Sandbox');

        file_put_contents($sandbox->get_wp_content_path() . '/plugins/deployed.txt', 'yes');

        $pdo = new \PDO('sqlite:' . $sandbox->get_db_path());
        $prefix = 'rudel_' . substr(md5($sandbox->id), 0, 6) . '_';
        $pdo->exec("UPDATE {$prefix}options SET option_value='https://host.test/__rudel/{$sandbox->id}' WHERE option_name IN ('siteurl', 'home')");
        $pdo = null;

        $result = $manager->deploy($app->id, $sandbox->id, 'before-deploy');

        $appPdo = new \PDO('sqlite:' . $app->get_db_path());
        $appPrefix = 'rudel_' . substr(md5($app->id), 0, 6) . '_';
        $siteurl = $appPdo->query("SELECT option_value FROM {$appPrefix}options WHERE option_name='siteurl'")->fetchColumn();

        $this->assertSame('before-deploy', $result['backup']['name']);
        $this->assertFileExists($app->path . '/backups/before-deploy/backup.json');
        $this->assertFileExists($app->get_wp_content_path() . '/plugins/deployed.txt');
        $this->assertStringContainsString('deploy-app.com', $siteurl);
        $this->assertStringNotContainsString($sandbox->id, $siteurl);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateAppMetadataPersists(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Update App', ['update-app.com'], ['engine' => 'sqlite']);

        $updated = $manager->update($app->id, [
            'owner' => 'dennis',
            'labels' => 'priority, qa',
            'protected' => true,
            'tracked_github_repo' => 'inline0/client-a-theme',
        ]);

        $this->assertSame('dennis', $updated->owner);
        $this->assertSame(['priority', 'qa'], $updated->labels);
        $this->assertTrue($updated->is_protected());
        $this->assertSame('inline0/client-a-theme', $updated->tracked_github_repo);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateAppCanClearTrackedGithubMetadata(): void
    {
        $this->defineConstants();
        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Clear Git App', ['clear-git.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]);

        $updated = $manager->update($app->id, ['clear_github' => true]);

        $this->assertNull($updated->tracked_github_repo);
        $this->assertNull($updated->tracked_github_branch);
        $this->assertNull($updated->tracked_github_dir);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAppFromSandboxInheritsTrackedGithubMetadata(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $sourceApp = $manager->create('Source App', ['source-app.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/source-theme',
            'tracked_github_branch' => 'release/2026',
            'tracked_github_dir' => 'themes/source-theme',
        ]);
        $sandbox = $manager->create_sandbox($sourceApp->id, 'Source Sandbox');

        $clonedApp = $manager->create('Cloned App', ['cloned-app.com'], [
            'engine' => 'sqlite',
            'clone_from' => $sandbox->id,
        ]);

        $this->assertSame('inline0/source-theme', $clonedApp->tracked_github_repo);
        $this->assertSame('release/2026', $clonedApp->tracked_github_branch);
        $this->assertSame('themes/source-theme', $clonedApp->tracked_github_dir);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeployRecordsLineageOnApp(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $appsDir = $this->tmpDir . '/apps';
        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $manager = new AppManager($appsDir, $sandboxesDir);
        $app = $manager->create('Lineage App', ['lineage-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Lineage Sandbox');

        $manager->deploy($app->id, $sandbox->id, 'before-deploy');

        $updated = $manager->get($app->id);
        $this->assertSame($sandbox->id, $updated->last_deployed_from_id);
        $this->assertSame('sandbox', $updated->last_deployed_from_type);
        $this->assertNotNull($updated->last_deployed_at);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeployPreservesAppGitWorktreeAndSyncsSandboxChanges(): void
    {
        if (! $this->hasGit()) {
            $this->markTestSkipped('git not available');
        }

        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Deploy Git App', ['deploy-git-app.com'], ['engine' => 'sqlite']);

        $themeRepo = $this->tmpDir . '/deploy-theme-repo';
        $this->createGitRepo($themeRepo, 'style.css', '/* original theme */');

        $appThemePath = $app->get_wp_content_path() . '/themes/client-a';
        $this->createGitWorktree($themeRepo, $appThemePath, 'app-client-a');

        $sandbox = $manager->create_sandbox($app->id, 'Deploy Git Sandbox');
        $sandboxThemePath = $sandbox->get_wp_content_path() . '/themes/client-a';

        file_put_contents($sandboxThemePath . '/style.css', '/* sandbox change */');
        file_put_contents($sandboxThemePath . '/sandbox-only.txt', 'from sandbox');

        $manager->deploy($app->id, $sandbox->id, 'before-deploy');

        $this->assertFileExists($appThemePath . '/.git');
        $this->assertSame('/* sandbox change */', file_get_contents($appThemePath . '/style.css'));
        $this->assertSame('from sandbox', file_get_contents($appThemePath . '/sandbox-only.txt'));

        exec('git -C ' . escapeshellarg($appThemePath) . ' branch --show-current 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('app-client-a', trim($output[0] ?? ''));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeployRecordsDeploymentHistoryWithBackupAndNotes(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Deploy History App', ['deploy-history.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]);
        $sandbox = $manager->create_sandbox($app->id, 'Deploy History Sandbox');

        $result = $manager->deploy($app->id, $sandbox->id, 'before-launch', [
            'label' => 'Launch candidate',
            'notes' => 'Approved after QA sign-off',
        ]);

        $deployments = $manager->deployments($app->id);

        $this->assertSame('before-launch', $result['deployment']['backup_name']);
        $this->assertSame('Launch candidate', $result['deployment']['label']);
        $this->assertSame('Approved after QA sign-off', $result['deployment']['notes']);
        $this->assertSame('inline0/client-a-theme', $result['deployment']['github_repo']);
        $this->assertSame('main', $result['deployment']['github_base_branch']);
        $this->assertCount(1, $deployments);
        $this->assertSame($result['deployment']['id'], $deployments[0]['id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreviewDeployReturnsPlanWithoutMutatingState(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Preview App', ['preview-app.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/preview-theme',
            'tracked_github_branch' => 'main',
        ]);
        $sandbox = $manager->create_sandbox($app->id, 'Preview Sandbox');

        $plan = $manager->preview_deploy($app->id, $sandbox->id, 'preflight', [
            'label' => 'Preview only',
            'notes' => 'No changes yet',
        ]);

        $this->assertSame($app->id, $plan['app_id']);
        $this->assertSame($sandbox->id, $plan['sandbox_id']);
        $this->assertSame('preflight', $plan['backup_name']);
        $this->assertSame('inline0/preview-theme', $plan['tracked_github_repo']);
        $this->assertStringEndsWith('/tmp/app-state.lock', $plan['lock_path']);
        $this->assertSame([], $manager->deployments($app->id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRollbackRestoresBackupReferencedByDeployment(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Rollback App', ['rollback-app.com'], ['engine' => 'sqlite']);
        file_put_contents($app->get_wp_content_path() . '/plugins/original.txt', 'before');

        $sandbox = $manager->create_sandbox($app->id, 'Rollback Sandbox');
        file_put_contents($sandbox->get_wp_content_path() . '/plugins/deployed.txt', 'after');

        $deploy = $manager->deploy($app->id, $sandbox->id, 'before-deploy');
        $rollback = $manager->rollback($app->id, $deploy['deployment']['id']);

        $this->assertSame($deploy['deployment']['id'], $rollback['deployment_id']);
        $this->assertSame('before-deploy', $rollback['backup_name']);
        $this->assertFileExists($app->get_wp_content_path() . '/plugins/original.txt');
        $this->assertFileDoesNotExist($app->get_wp_content_path() . '/plugins/deployed.txt');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunScheduledBackupsSkipsAppsWithFreshBackups(): void
    {
        $this->defineConstants();

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Scheduled Backup App', ['scheduled-backup-app.com'], ['engine' => 'sqlite']);

        $manager->backup($app->id, 'baseline');
        $result = $manager->run_scheduled_backups(24);

        $this->assertSame([], $result['created']);
        $this->assertSame([$app->id], $result['skipped']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPruneHistoryRemovesOlderBackupsAndDeployments(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Prune History App', ['prune-history-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Prune History Sandbox');

        $manager->backup($app->id, 'manual-backup');
        $deployOne = $manager->deploy($app->id, $sandbox->id, 'before-deploy-1');
        $deployTwo = $manager->deploy($app->id, $sandbox->id, 'before-deploy-2');

        file_put_contents($app->path . '/backups/manual-backup/backup.json', json_encode([
            'name' => 'manual-backup',
            'app_id' => $app->id,
            'created_at' => '2026-01-01T00:00:00+00:00',
        ], JSON_PRETTY_PRINT));
        file_put_contents($app->path . '/backups/before-deploy-1/backup.json', json_encode([
            'name' => 'before-deploy-1',
            'app_id' => $app->id,
            'created_at' => '2026-01-02T00:00:00+00:00',
        ], JSON_PRETTY_PRINT));
        file_put_contents($app->path . '/backups/before-deploy-2/backup.json', json_encode([
            'name' => 'before-deploy-2',
            'app_id' => $app->id,
            'created_at' => '2026-01-03T00:00:00+00:00',
        ], JSON_PRETTY_PRINT));
        $removed = $manager->prune_history($app->id, [
            'keep_backups' => 1,
            'keep_deployments' => 1,
        ]);

        $this->assertContains('manual-backup', $removed['backups_removed']);
        $this->assertContains('before-deploy-1', $removed['backups_removed']);
        $this->assertSame([$deployOne['deployment']['id']], $removed['deployments_removed']);
        $this->assertSame([$deployTwo['deployment']['id']], array_column($manager->deployments($app->id), 'id'));
    }
}
