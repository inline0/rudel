<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;
use Rudel\ThemeOverlay;

class EnvironmentManagerOverlayTest extends RudelTestCase
{
	private function defineWordPressRuntime(?string $home = 'http://example.test'): string
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
		if (null !== $home) {
			define('WP_HOME', $home);
		}

		return $wordpressRoot;
	}

	private function createChildThemeFixtures(string $wordpressRoot): void
	{
		mkdir($wordpressRoot . '/wp-content/themes/parent-theme', 0755, true);
		mkdir($wordpressRoot . '/wp-content/themes/child-theme', 0755, true);
		file_put_contents($wordpressRoot . '/wp-content/themes/parent-theme/style.css', "/*\nTheme Name: Parent Theme\n*/");
		file_put_contents($wordpressRoot . '/wp-content/themes/child-theme/style.css', "/*\nTheme Name: Child Theme\nTemplate: parent-theme\n*/");
	}

	public function testDeleteDirectoryRemovesReadOnlyNestedDirectories(): void
	{
		$root = $this->tmpDir . '/cleanup-tree';
		mkdir($root . '/nested/child', 0755, true);
		file_put_contents($root . '/nested/child/example.txt', 'cleanup');

		chmod($root . '/nested/child/example.txt', 0444);
		chmod($root . '/nested/child', 0555);
		chmod($root . '/nested', 0555);

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$method = new \ReflectionMethod($manager, 'delete_directory');
		$method->setAccessible(true);

		$result = $method->invoke($manager, $root);

		$this->assertTrue($result);
		$this->assertDirectoryDoesNotExist($root);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateWritesOverlayRuntimeState(): void
	{
		$wordpressRoot = $this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Alpha Site', ['theme' => 'host-theme']);

		$this->assertTrue($environment->is_overlay());
		$this->assertFalse($environment->is_subsite());
		$this->assertNull($environment->blog_id);
		$this->assertSame('overlay', $environment->engine);
		$this->assertStringStartsWith('wp_', $environment->get_table_prefix());
		$this->assertStringEndsWith('_', $environment->get_table_prefix());
		$this->assertSame(WP_HOME . '/', $environment->get_url());
		$this->assertSame('host-theme', $environment->theme_slug);
		$this->assertSame(WP_HOME, $this->tableOptionValue($environment->get_table_prefix(), 'siteurl'));
		$this->assertSame(WP_HOME, $this->tableOptionValue($environment->get_table_prefix(), 'home'));
		$this->assertSame('host-theme', $this->tableOptionValue($environment->get_table_prefix(), 'stylesheet'));
		$this->assertFileExists($environment->path . '/wp-cli.yml');
		$this->assertStringContainsString('path: ' . $wordpressRoot, (string) file_get_contents($environment->path . '/wp-cli.yml'));
		$this->assertFileExists(ThemeOverlay::theme_root_for($environment) . '/host-theme/style.css');
		$this->assertFileDoesNotExist($environment->path . '/bootstrap.php');
		$this->assertFileDoesNotExist($environment->path . '/wp-content/db.php');
		$this->assertFileDoesNotExist($environment->path . '/plugins/demo-plugin/demo-plugin.php');
		$this->assertFileDoesNotExist($environment->path . '/uploads/2026/04/demo.txt');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateUsesResolvedHomeUrlWhenWpHomeConstantIsAbsent(): void
	{
		$this->defineWordPressRuntime(null);
		$GLOBALS['rudel_test_sites'][1]['home'] = 'http://localhost:8000/';
		$GLOBALS['rudel_test_sites'][1]['siteurl'] = 'http://localhost:8000/';

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Port Site', ['theme' => 'host-theme']);

		$this->assertSame('http://localhost:8000/', $environment->get_url());
		$this->assertSame('http://localhost:8000', $this->tableOptionValue($environment->get_table_prefix(), 'siteurl'));
		$this->assertSame('http://localhost:8000', $this->tableOptionValue($environment->get_table_prefix(), 'home'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateKeepsHostRuntimeTablesOutOfEnvironmentClone(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Runtime Table Isolation', ['theme' => 'host-theme']);
		$clonedTables = array_filter(
			$GLOBALS['wpdb']->getTableNames(),
			static fn(string $table): bool => str_starts_with($table, $environment->get_table_prefix())
		);

		$this->assertContains($environment->get_table_prefix() . 'options', $clonedTables);
		$this->assertNotContains($environment->get_table_prefix() . 'rudel_environments', $clonedTables);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateCopiesChildThemeParentAndStoresTemplateOption(): void
	{
		$wordpressRoot = $this->defineWordPressRuntime();
		$this->createChildThemeFixtures($wordpressRoot);

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Child Theme Site', ['theme' => 'child-theme']);

		$this->assertSame('child-theme', $environment->theme_slug);
		$this->assertSame('parent-theme', $this->tableOptionValue($environment->get_table_prefix(), 'template'));
		$this->assertSame('child-theme', $this->tableOptionValue($environment->get_table_prefix(), 'stylesheet'));
		$this->assertFileExists(ThemeOverlay::theme_root_for($environment) . '/child-theme/style.css');
		$this->assertFileExists(ThemeOverlay::theme_root_for($environment) . '/parent-theme/style.css');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCloneFromOverlayEnvironmentCopiesSourceTablesAndTheme(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$source = $manager->create('Source Site', ['theme' => 'host-theme']);
		file_put_contents(ThemeOverlay::theme_root_for($source) . '/host-theme/custom.php', '<?php echo "source";');
		$GLOBALS['wpdb']->insert(
			$source->get_table_prefix() . 'options',
			[
				'option_name' => 'blogname',
				'option_value' => 'Source Blogname',
				'autoload' => 'yes',
			]
		);

		$clone = $manager->create('Clone Site', ['clone_from' => $source->id]);

		$this->assertSame('Source Blogname', $this->tableOptionValue($clone->get_table_prefix(), 'blogname'));
		$this->assertSame(WP_HOME, $this->tableOptionValue($clone->get_table_prefix(), 'siteurl'));
		$this->assertSame($source->theme_slug, $clone->theme_slug);
		$this->assertFileExists(ThemeOverlay::theme_root_for($clone) . '/host-theme/custom.php');
		$this->assertSame($source->id, $clone->source_environment_id);
		$this->assertSame('overlay', $clone->clone_source['engine'] ?? null);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testUpdateWritesOverlaySiteOptionsAndMetadata(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);
		$environment = $manager->create('Mutable Site', ['theme' => 'host-theme']);

		$updated = $manager->update($environment->id, [
			'name' => 'Mutable Site Updated',
			'site_options' => [
				'blogname' => 'Overlay Blogname',
			],
			'tracked_git_remote' => 'https://example.test/theme.git',
			'tracked_git_branch' => 'main',
			'tracked_git_dir' => 'themes/host-theme',
		]);

		$this->assertSame('Mutable Site Updated', $updated->name);
		$this->assertSame('Overlay Blogname', $this->tableOptionValue($updated->get_table_prefix(), 'blogname'));
		$this->assertSame('https://example.test/theme.git', $updated->tracked_git_remote);
		$this->assertSame('themes/host-theme', $updated->tracked_git_dir);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDestroyDropsOverlayTablesAndEnvironmentDirectory(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);
		$environment = $manager->create('Disposable Site', ['theme' => 'host-theme']);

		$this->assertTrue($GLOBALS['wpdb']->hasTable($environment->get_table_prefix() . 'options'));
		$this->assertTrue($manager->destroy($environment->id));
		$this->assertFalse($GLOBALS['wpdb']->hasTable($environment->get_table_prefix() . 'options'));
		$this->assertDirectoryDoesNotExist($environment->path);
	}
}
