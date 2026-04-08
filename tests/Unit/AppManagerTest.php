<?php

namespace Rudel\Tests\Unit;

use Pitmaster\Pitmaster;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
	private function createTrackedThemeRepo(string $path, string $contents = 'body { color: red; }'): void
	{
		mkdir($path, 0755, true);
		file_put_contents($path . '/style.css', $contents);

		$repo = Pitmaster::init($path);
		$repo->config()->set('user.email', 'test@test.com');
		$repo->config()->set('user.name', 'Test');
		$repo->add('style.css');
		$repo->commit('init');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateAppUsesPrimaryDomainAsCanonicalUrl(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['demo.example.test']);

		$this->assertTrue($app->is_app());
		$this->assertSame('http://demo.example.test/', $app->get_url());
		$this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'siteurl'));
		$this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'home'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateAppKeepsTheNetworkPortInItsCanonicalUrl(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://localhost:9878');
		define('DOMAIN_CURRENT_SITE', 'localhost');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Port Demo', ['demo.example.test']);

		$this->assertSame('http://demo.example.test:9878/', $app->get_url());
		$this->assertSame('http://demo.example.test:9878', $this->siteOptionValue((int) $app->blog_id, 'siteurl'));
		$this->assertSame('http://demo.example.test:9878', $this->siteOptionValue((int) $app->blog_id, 'home'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxInheritsTrackedGitMetadataFromApp(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['client.example.test'], [
			'tracked_git_remote' => 'https://example.test/client-theme.git',
			'tracked_git_branch' => 'release',
			'tracked_git_dir' => 'themes/client-theme',
		]);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');

		$this->assertSame('https://example.test/client-theme.git', $sandbox->tracked_git_remote);
		$this->assertSame('release', $sandbox->tracked_git_branch);
		$this->assertSame('themes/client-theme', $sandbox->tracked_git_dir);
		$this->assertSame('https://example.test/client-theme.git', $sandbox->get_git_remote());
		$this->assertSame('release', $sandbox->get_git_base_branch());
		$this->assertSame('themes/client-theme', $sandbox->get_git_dir());
		$this->assertSame(
			'http://' . $sandbox->id . '.example.test',
			$this->siteOptionValue((int) $sandbox->blog_id, 'siteurl')
		);
		$this->assertSame(
			'http://' . $sandbox->id . '.example.test',
			$this->siteOptionValue((int) $sandbox->blog_id, 'home')
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxClonesTheAppIsolatedUsers(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['client.example.test']);
		$appUsersTable = (string) $app->get_users_table();
		$appUsermetaTable = (string) $app->get_usermeta_table();

		$appUsers = $GLOBALS['wpdb']->getTableRows($appUsersTable);
		$appUsers[] = [
			'ID' => 2,
			'user_login' => 'app-author',
			'user_pass' => '$P$app',
			'user_email' => 'author@example.test',
		];
		$GLOBALS['wpdb']->addTable($appUsersTable, 'CREATE TABLE `'.$appUsersTable.'` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_login` varchar(60), `user_pass` varchar(255), `user_email` varchar(100), PRIMARY KEY (`ID`))', $appUsers);

		$appUsermeta = $GLOBALS['wpdb']->getTableRows($appUsermetaTable);
		$appUsermeta[] = [
			'umeta_id' => 3,
			'user_id' => 2,
			'meta_key' => 'wp_' . $app->blog_id . '_capabilities',
			'meta_value' => 'a:1:{s:6:"author";b:1;}',
		];
		$GLOBALS['wpdb']->addTable($appUsermetaTable, 'CREATE TABLE `'.$appUsermetaTable.'` (`umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `meta_key` varchar(255), `meta_value` longtext, PRIMARY KEY (`umeta_id`))', $appUsermeta);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$sandboxUsers = $GLOBALS['wpdb']->getTableRows((string) $sandbox->get_users_table());
		$sandboxLogins = array_column($sandboxUsers, 'user_login');
		$sandboxMetaKeys = array_column($GLOBALS['wpdb']->getTableRows((string) $sandbox->get_usermeta_table()), 'meta_key');

		$this->assertContains('app-author', $sandboxLogins);
		$this->assertContains('wp_' . $sandbox->blog_id . '_capabilities', $sandboxMetaKeys);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxFromGitTrackedAppKeepsWorktreeInsideSandboxContentTree(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['client.example.test']);
		$themePath = $app->path . '/wp-content/themes/client-theme';
		$this->createTrackedThemeRepo($themePath);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$worktrees = $sandbox->clone_source['git_worktrees'] ?? [];
		$metadataNames = array_values(array_filter(array_map(static fn(array $worktree): ?string => $worktree['metadata_name'] ?? null, $worktrees)));
		$linkedWorktreeNames = array_values(
			array_filter(
				array_map(
					static fn(object $worktree): ?string => isset($worktree->name) && is_string($worktree->name) ? $worktree->name : null,
					Pitmaster::open($themePath)->worktrees()
				)
			)
		);

		$this->assertNotEmpty($worktrees);
		$this->assertSame('themes', $worktrees[0]['type']);
		$this->assertSame('client-theme', $worktrees[0]['name']);
		$this->assertSame($sandbox->path . '/wp-content/themes/client-theme', $worktrees[0]['repo']);
		$this->assertNotEmpty($metadataNames);
		$this->assertContains($worktrees[0]['metadata_name'], $linkedWorktreeNames);
		$this->assertDirectoryExists($sandbox->path . '/wp-content/themes/client-theme');
		$this->assertFileExists($sandbox->path . '/wp-content/themes/client-theme/style.css');
		$this->assertFileExists($sandbox->path . '/wp-content/themes/client-theme/.git');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDeployFromGitTrackedSandboxKeepsAppWorktreeLocal(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['client.example.test']);
		$appThemePath = $app->path . '/wp-content/themes/client-theme';
		$this->createTrackedThemeRepo($appThemePath);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$sandboxThemePath = $sandbox->path . '/wp-content/themes/client-theme';
		file_put_contents($sandboxThemePath . '/style.css', 'body { color: blue; }');
		file_put_contents($sandboxThemePath . '/new-template.php', '<?php echo "local";');

		$result = $manager->deploy($app->id, $sandbox->id, 'before-deploy');

		$this->assertSame($app->id, $result['app_id']);
		$this->assertSame($sandbox->id, $result['sandbox_id']);
		$this->assertSame('body { color: blue; }', file_get_contents($appThemePath . '/style.css'));
		$this->assertFileExists($appThemePath . '/new-template.php');
		$this->assertFileExists($appThemePath . '/.git');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxFromGitTrackedAppUsesDistinctMetadataNamesAcrossRepeatedCycles(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);
		$sandboxes = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->tmpDir . '/apps',
			'sandbox'
		);

		$app = $manager->create('Client Demo', ['client.example.test']);
		$themePath = $app->path . '/wp-content/themes/client-theme';
		$this->createTrackedThemeRepo($themePath);

		$first = $manager->create_sandbox($app->id, 'Feature One');
		$second = $manager->create_sandbox($app->id, 'Feature Two');

		$firstWorktree = $first->clone_source['git_worktrees'][0] ?? null;
		$secondWorktree = $second->clone_source['git_worktrees'][0] ?? null;

		$this->assertIsArray($firstWorktree);
		$this->assertIsArray($secondWorktree);
		$this->assertNotSame($firstWorktree['metadata_name'], $secondWorktree['metadata_name']);

		$namesBeforeDestroy = array_values(
			array_filter(
				array_map(
					static fn(object $worktree): ?string => isset($worktree->name) && is_string($worktree->name) ? $worktree->name : null,
					Pitmaster::open($themePath)->worktrees()
				)
			)
		);

		$this->assertContains($firstWorktree['metadata_name'], $namesBeforeDestroy);
		$this->assertContains($secondWorktree['metadata_name'], $namesBeforeDestroy);

		$this->assertTrue($sandboxes->destroy($first->id));

		$third = $manager->create_sandbox($app->id, 'Feature Three');
		$thirdWorktree = $third->clone_source['git_worktrees'][0] ?? null;
		$this->assertIsArray($thirdWorktree);
		$this->assertNotSame($secondWorktree['metadata_name'], $thirdWorktree['metadata_name']);

		$namesAfterRecreate = array_values(
			array_filter(
				array_map(
					static fn(object $worktree): ?string => isset($worktree->name) && is_string($worktree->name) ? $worktree->name : null,
					Pitmaster::open($themePath)->worktrees()
				)
			)
		);

		$this->assertNotContains($firstWorktree['metadata_name'], $namesAfterRecreate);
		$this->assertContains($secondWorktree['metadata_name'], $namesAfterRecreate);
		$this->assertContains($thirdWorktree['metadata_name'], $namesAfterRecreate);
		$this->assertFileExists($third->path . '/wp-content/themes/client-theme/.git');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateCleansUpTheAppWhenALateLifecycleFailureOccurs(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$GLOBALS['rudel_test_action_callbacks']['rudel_after_app_create'][] = static function (): void {
			throw new \RuntimeException('Late app create failure');
		};

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		try {
			$manager->create('Broken Demo', ['broken.example.test']);
			$this->fail('Expected app creation to fail after the environment had been created.');
		} catch (\RuntimeException $e) {
			$this->assertSame('Late app create failure', $e->getMessage());
		}

		$this->assertSame([], $manager->list());
		$this->assertSame([], glob($this->tmpDir . '/apps/*') ?: []);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRemovingPrimaryDomainPromotesTheNextDomainAndUpdatesSiteOptions(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['demo.example.test', 'www.demo.example.test']);
		$manager->remove_domain($app->id, 'demo.example.test');

		$updated = $manager->get($app->id);

		$this->assertNotNull($updated);
		$this->assertSame(['www.demo.example.test'], $updated->domains);
		$this->assertSame('http://www.demo.example.test/', $updated->get_url());
		$this->assertSame('http://www.demo.example.test', $this->siteOptionValue((int) $updated->blog_id, 'siteurl'));
		$this->assertSame('http://www.demo.example.test', $this->siteOptionValue((int) $updated->blog_id, 'home'));
	}
}
