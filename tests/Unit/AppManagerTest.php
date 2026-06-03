<?php

namespace Rudel\Tests\Unit;

use Pitmaster\Pitmaster;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;
use Rudel\ThemeOverlay;

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

	private function defineWordPressRuntime(string $home = 'http://example.test'): string
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content/themes/host-theme', 0755, true);
		mkdir($wordpressRoot . '/wp-content/plugins/demo-plugin', 0755, true);
		mkdir($wordpressRoot . '/wp-content/uploads/2026/04', 0755, true);
		file_put_contents($wordpressRoot . '/wp-content/themes/host-theme/style.css', "/*\nTheme Name: Host Theme\n*/");
		file_put_contents($wordpressRoot . '/wp-content/plugins/demo-plugin/demo-plugin.php', '<?php');
		file_put_contents($wordpressRoot . '/wp-content/uploads/2026/04/demo.txt', 'shared upload');

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', $home);

		return $wordpressRoot;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateAppUsesPrimaryDomainAndOverlayTables(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Client Demo', ['demo.example.test'], ['theme' => 'host-theme']);

		$this->assertTrue($app->is_app());
		$this->assertTrue($app->is_overlay());
		$this->assertNull($app->blog_id);
		$this->assertSame('http://demo.example.test/', $app->get_url());
		$this->assertSame('host-theme', $app->theme_slug);
		$this->assertSame('http://demo.example.test', $this->tableOptionValue($app->get_table_prefix(), 'siteurl'));
		$this->assertSame('http://demo.example.test', $this->tableOptionValue($app->get_table_prefix(), 'home'));
		$this->assertSame('host-theme', $this->tableOptionValue($app->get_table_prefix(), 'stylesheet'));
		$this->assertFileExists(ThemeOverlay::theme_root_for($app) . '/host-theme/style.css');
		$this->assertFileDoesNotExist($app->path . '/plugins/demo-plugin/demo-plugin.php');
		$this->assertFileDoesNotExist($app->path . '/uploads/2026/04/demo.txt');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateAppKeepsExplicitDomainPortInCanonicalUrl(): void
	{
		$this->defineWordPressRuntime('http://localhost:8000');

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Port Demo', ['demo.example.test'], ['theme' => 'host-theme']);

		$this->assertSame('http://demo.example.test:8000/', $app->get_url());
		$this->assertSame('http://demo.example.test:8000', $this->tableOptionValue($app->get_table_prefix(), 'siteurl'));
		$this->assertSame('http://demo.example.test:8000', $this->tableOptionValue($app->get_table_prefix(), 'home'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxFromAppClonesDatabaseAndThemeOnly(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Client Demo', ['client.example.test'], ['theme' => 'host-theme']);

		$GLOBALS['wpdb']->insert(
			$app->get_table_prefix() . 'options',
			[
				'option_name' => 'blogname',
				'option_value' => 'App Blogname',
				'autoload' => 'yes',
			]
		);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');

		$this->assertTrue($sandbox->is_overlay());
		$this->assertSame((int) $app->record_id, $sandbox->app_record_id);
		$this->assertSame($app->theme_slug, $sandbox->theme_slug);
		$this->assertNotSame($app->get_table_prefix(), $sandbox->get_table_prefix());
		$this->assertSame('App Blogname', $this->tableOptionValue($sandbox->get_table_prefix(), 'blogname'));
		$this->assertSame(WP_HOME, $this->tableOptionValue($sandbox->get_table_prefix(), 'siteurl'));
		$this->assertFileExists(ThemeOverlay::theme_root_for($sandbox) . '/host-theme/style.css');
		$this->assertFileDoesNotExist($sandbox->path . '/plugins/demo-plugin/demo-plugin.php');
		$this->assertFileDoesNotExist($sandbox->path . '/uploads/2026/04/demo.txt');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxFromGitTrackedAppCreatesDistinctThemeWorktrees(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Client Demo', ['client.example.test'], [
			'tracked_git_remote' => 'https://example.test/client-theme.git',
			'tracked_git_branch' => 'main',
			'tracked_git_dir' => 'themes/client-theme',
		]);
		$themePath = ThemeOverlay::theme_root_for($app) . '/client-theme';
		$this->createTrackedThemeRepo($themePath);

		$first = $manager->create_sandbox($app->id, 'Feature One');
		$second = $manager->create_sandbox($app->id, 'Feature Two');

		$firstWorktree = $first->clone_source['git_worktrees'][0] ?? null;
		$secondWorktree = $second->clone_source['git_worktrees'][0] ?? null;

		$this->assertIsArray($firstWorktree);
		$this->assertIsArray($secondWorktree);
		$this->assertSame('themes', $firstWorktree['type']);
		$this->assertSame('client-theme', $firstWorktree['name']);
		$this->assertNotSame($firstWorktree['metadata_name'], $secondWorktree['metadata_name']);
		$this->assertFileExists(ThemeOverlay::theme_root_for($first) . '/client-theme/.git');
		$this->assertFileExists(ThemeOverlay::theme_root_for($second) . '/client-theme/.git');
		$this->assertSame('https://example.test/client-theme.git', $first->tracked_git_remote);
		$this->assertSame('themes/client-theme', $first->tracked_git_dir);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDeployFromSandboxCopiesOverlayThemeChangesBackToApp(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Client Demo', ['client.example.test'], [
			'tracked_git_remote' => 'https://example.test/client-theme.git',
			'tracked_git_branch' => 'main',
			'tracked_git_dir' => 'themes/client-theme',
		]);
		$appThemePath = ThemeOverlay::theme_root_for($app) . '/client-theme';
		$this->createTrackedThemeRepo($appThemePath);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$sandboxThemePath = ThemeOverlay::theme_root_for($sandbox) . '/client-theme';
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
	public function testDestroyRemovesOverlayTablesAndRecordedWorktrees(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$sandboxes = new EnvironmentManager($this->tmpDir . '/sandboxes', $this->tmpDir . '/apps', 'sandbox');
		$app = $manager->create('Client Demo', ['client.example.test'], [
			'tracked_git_remote' => 'https://example.test/client-theme.git',
			'tracked_git_branch' => 'main',
			'tracked_git_dir' => 'themes/client-theme',
		]);
		$themePath = ThemeOverlay::theme_root_for($app) . '/client-theme';
		$this->createTrackedThemeRepo($themePath);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');
		$worktree = $sandbox->clone_source['git_worktrees'][0] ?? null;

		$this->assertIsArray($worktree);
		$this->assertTrue($GLOBALS['wpdb']->hasTable($sandbox->get_table_prefix() . 'options'));
		$this->assertTrue($sandboxes->destroy($sandbox->id));
		$this->assertFalse($GLOBALS['wpdb']->hasTable($sandbox->get_table_prefix() . 'options'));
		$this->assertFileDoesNotExist((string) $worktree['repo']);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRemovingPrimaryDomainPromotesTheNextDomainAndUpdatesOverlayOptions(): void
	{
		$this->defineWordPressRuntime();

		$manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
		$app = $manager->create('Client Demo', ['demo.example.test', 'www.demo.example.test'], ['theme' => 'host-theme']);

		$manager->remove_domain($app->id, 'demo.example.test');
		$updated = $manager->get($app->id);

		$this->assertNotNull($updated);
		$this->assertSame(['www.demo.example.test'], $updated->domains);
		$this->assertSame('http://www.demo.example.test/', $updated->get_url());
		$this->assertSame('http://www.demo.example.test', $this->tableOptionValue($updated->get_table_prefix(), 'siteurl'));
		$this->assertSame('http://www.demo.example.test', $this->tableOptionValue($updated->get_table_prefix(), 'home'));
	}
}
