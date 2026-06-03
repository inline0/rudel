<?php

namespace Rudel\Tests\Security;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;
use Rudel\ThemeOverlay;

class OverlayIsolationTest extends RudelTestCase
{
	private function defineWordPressRuntime(): void
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
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testEachEnvironmentGetsItsOwnOverlayPrefixAndTables(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$alpha = $manager->create('Alpha Site', ['theme' => 'host-theme']);
		$beta = $manager->create('Beta Site', ['theme' => 'host-theme']);

		$this->assertNotSame($alpha->get_table_prefix(), $beta->get_table_prefix());
		$this->assertTrue($GLOBALS['wpdb']->hasTable($alpha->get_table_prefix() . 'options'));
		$this->assertTrue($GLOBALS['wpdb']->hasTable($beta->get_table_prefix() . 'options'));
		$this->assertNull($alpha->get_users_table());
		$this->assertNull($beta->get_users_table());
		$this->assertSame(WP_HOME . '/', $alpha->get_url());
		$this->assertSame(WP_HOME . '/', $beta->get_url());
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testEachEnvironmentKeepsItsOwnThemeTree(): void
	{
		$this->defineWordPressRuntime();

		$manager = new EnvironmentManager(
			$this->tmpDir . '/sandboxes',
			$this->runtimeStore()
		);

		$alpha = $manager->create('Alpha Site', ['theme' => 'host-theme']);
		$beta = $manager->create('Beta Site', ['theme' => 'host-theme']);

		file_put_contents(ThemeOverlay::theme_root_for($alpha) . '/host-theme/alpha.php', 'alpha');
		file_put_contents(ThemeOverlay::theme_root_for($beta) . '/host-theme/beta.php', 'beta');

		$this->assertFileExists(ThemeOverlay::theme_root_for($alpha) . '/host-theme/alpha.php');
		$this->assertFileExists(ThemeOverlay::theme_root_for($beta) . '/host-theme/beta.php');
		$this->assertFileDoesNotExist(ThemeOverlay::theme_root_for($alpha) . '/host-theme/beta.php');
		$this->assertFileDoesNotExist(ThemeOverlay::theme_root_for($beta) . '/host-theme/alpha.php');
		$this->assertFileExists(WP_CONTENT_DIR . '/plugins/demo-plugin/demo-plugin.php');
		$this->assertFileExists(WP_CONTENT_DIR . '/uploads/2026/04/demo.txt');
	}
}
