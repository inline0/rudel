<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\RudelConfig;
use Rudel\SnapshotManager;
use Rudel\Tests\RudelTestCase;
use Rudel\ThemeOverlay;

class SnapshotManagerTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testSnapshotsStoreOnlyOverlayThemesAndRestoreThem(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content/plugins/demo-plugin', 0755, true);
		mkdir($wordpressRoot . '/wp-content/uploads/2026/04', 0755, true);
		file_put_contents($wordpressRoot . '/wp-content/plugins/demo-plugin/demo-plugin.php', '<?php');
		file_put_contents($wordpressRoot . '/wp-content/uploads/2026/04/demo.txt', 'shared upload');

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');

		$this->createFakeSandbox('shared-site', 'Shared Site', [
			'theme_slug' => 'demo-theme',
			'shared_plugins' => true,
			'shared_uploads' => true,
		]);

		$environment = $this->environmentRepository('sandbox')->get('shared-site');
		$this->assertNotNull($environment);

		mkdir(ThemeOverlay::theme_root_for($environment) . '/demo-theme', 0755, true);
		file_put_contents(ThemeOverlay::theme_root_for($environment) . '/demo-theme/style.css', 'theme css');
		$GLOBALS['wpdb']->addTable(
			$environment->get_table_prefix() . 'options',
			'CREATE TABLE `' . $environment->get_table_prefix() . 'options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
			[
				['option_id' => 1, 'option_name' => 'siteurl', 'option_value' => 'http://example.test'],
				['option_id' => 2, 'option_name' => 'home', 'option_value' => 'http://example.test'],
			]
		);

		(new RudelConfig())->set('auto_snapshot_before_restore', 0);

		$manager = new SnapshotManager($environment);
		$manager->create('baseline');

		$snapshotPath = $environment->path . '/snapshots/baseline';
		$this->assertDirectoryDoesNotExist($snapshotPath . '/wp-content/plugins');
		$this->assertDirectoryDoesNotExist($snapshotPath . '/wp-content/uploads');
		$this->assertFileExists($snapshotPath . '/themes/demo-theme/style.css');

		file_put_contents(ThemeOverlay::theme_root_for($environment) . '/demo-theme/style.css', 'changed css');
		file_put_contents(ThemeOverlay::theme_root_for($environment) . '/demo-theme/local-only.php', '<?php');

		$manager->restore('baseline');

		$this->assertSame('theme css', file_get_contents(ThemeOverlay::theme_root_for($environment) . '/demo-theme/style.css'));
		$this->assertFileDoesNotExist(ThemeOverlay::theme_root_for($environment) . '/demo-theme/local-only.php');
		$this->assertFileExists($wordpressRoot . '/wp-content/plugins/demo-plugin/demo-plugin.php');
		$this->assertFileExists($wordpressRoot . '/wp-content/uploads/2026/04/demo.txt');
	}
}
