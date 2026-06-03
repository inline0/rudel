<?php

namespace Rudel\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\BootstrapRuntimeStore;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;
use Rudel\ThemeOverlay;

class OverlayRuntimeContractTest extends RudelTestCase
{
	private function defineWordPressRuntime(): string
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
		define('WP_HOME', 'http://example.test');

		return $wordpressRoot;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testBootstrapRuntimeStoreResolvesCreatedOverlayEnvironmentBySlug(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Bravo Site', ['theme' => 'host-theme']);
		$store = new BootstrapRuntimeStore();
		$record = $store->environment_by_slug($environment->id);

		$this->assertIsArray($record);
		$this->assertSame($environment->id, $record['slug']);
		$this->assertSame('overlay', $record['engine']);
		$this->assertNull($record['blog_id']);
		$this->assertSame($environment->get_table_prefix(), $record['table_prefix']);
		$this->assertSame('host-theme', $record['theme_slug']);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreatedEnvironmentOwnsOnlyOverlayThemeRoot(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$environment = $manager->create('Charlie Site', ['theme' => 'host-theme']);

		$this->assertSame($environment->path, $environment->get_runtime_wp_content_path());
		$this->assertSame($environment->path . '/themes', ThemeOverlay::theme_root_for($environment));
		$this->assertDirectoryExists($environment->get_runtime_content_path('themes'));
		$this->assertFileExists($environment->get_runtime_content_path('themes/host-theme/style.css'));
		$this->assertDirectoryDoesNotExist($environment->get_runtime_content_path('plugins'));
		$this->assertDirectoryDoesNotExist($environment->get_runtime_content_path('uploads'));
		$this->assertDirectoryExists(WP_CONTENT_DIR . '/plugins/demo-plugin');
		$this->assertDirectoryExists(WP_CONTENT_DIR . '/uploads/2026/04');
	}
}
