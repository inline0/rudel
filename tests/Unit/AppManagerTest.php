<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
	private function hasGit(): bool
	{
		exec('git --version 2>&1', $output, $code);
		return 0 === $code;
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
		if (! $this->hasGit()) {
			$this->markTestSkipped('git not available');
		}

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
		mkdir($themePath, 0755, true);
		file_put_contents($themePath . '/style.css', 'body { color: red; }');
		exec('git -C ' . escapeshellarg($themePath) . ' init 2>&1');
		exec('git -C ' . escapeshellarg($themePath) . ' config user.email "test@test.com" 2>&1');
		exec('git -C ' . escapeshellarg($themePath) . ' config user.name "Test" 2>&1');
		exec('git -C ' . escapeshellarg($themePath) . ' add -A 2>&1');
		exec('git -C ' . escapeshellarg($themePath) . ' commit -m "init" 2>&1');
		exec('git -C ' . escapeshellarg($themePath) . ' branch -M main 2>&1');

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$worktrees = $sandbox->clone_source['git_worktrees'] ?? [];

		$this->assertNotEmpty($worktrees);
		$this->assertSame('themes', $worktrees[0]['type']);
		$this->assertSame('client-theme', $worktrees[0]['name']);
		$this->assertSame($sandbox->path . '/wp-content/themes/client-theme', $worktrees[0]['repo']);
		$this->assertDirectoryExists($sandbox->path . '/wp-content/themes/client-theme');
		$this->assertFileExists($sandbox->path . '/wp-content/themes/client-theme/style.css');
		$this->assertFileExists($sandbox->path . '/wp-content/themes/client-theme/.git');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDeployFromGitTrackedSandboxKeepsAppWorktreeLocal(): void
	{
		if (! $this->hasGit()) {
			$this->markTestSkipped('git not available');
		}

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
		mkdir($appThemePath, 0755, true);
		file_put_contents($appThemePath . '/style.css', 'body { color: red; }');
		exec('git -C ' . escapeshellarg($appThemePath) . ' init 2>&1');
		exec('git -C ' . escapeshellarg($appThemePath) . ' config user.email "test@test.com" 2>&1');
		exec('git -C ' . escapeshellarg($appThemePath) . ' config user.name "Test" 2>&1');
		exec('git -C ' . escapeshellarg($appThemePath) . ' add -A 2>&1');
		exec('git -C ' . escapeshellarg($appThemePath) . ' commit -m "init" 2>&1');
		exec('git -C ' . escapeshellarg($appThemePath) . ' branch -M main 2>&1');

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
