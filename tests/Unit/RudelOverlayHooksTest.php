<?php

namespace {
	if (! function_exists('plugin_dir_path')) {
		function plugin_dir_path(string $file): string
		{
			return rtrim(dirname($file), '/') . '/';
		}
	}

	if (! function_exists('plugin_dir_url')) {
		function plugin_dir_url(string $file): string
		{
			unset($file);
			return 'http://example.test/wp-content/plugins/rudel/';
		}
	}

	if (! function_exists('register_activation_hook')) {
		function register_activation_hook(string $file, callable $callback): void
		{
			unset($file, $callback);
		}
	}

	if (! function_exists('register_deactivation_hook')) {
		function register_deactivation_hook(string $file, callable $callback): void
		{
			unset($file, $callback);
		}
	}

	if (! function_exists('register_theme_directory')) {
		function register_theme_directory(string $directory): bool
		{
			return is_dir($directory);
		}
	}

	if (! function_exists('wp_get_theme')) {
		function wp_get_theme(string $slug): object
		{
			return new class($slug) {
				public function __construct(private string $slug)
				{
				}

				public function exists(): bool
				{
					return true;
				}

				public function get(string $field): string
				{
					return 'Name' === $field ? $this->slug : '';
				}
			};
		}
	}
}

namespace Rudel\Tests\Unit {

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class RudelOverlayHooksTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testThemeRootCallbacksAcceptCoreEmptyStylesheetValues(): void
	{
		$themeRoot = $this->tmpDir . '/themes';
		mkdir($themeRoot . '/child-theme', 0755, true);
		mkdir($themeRoot . '/parent-theme', 0755, true);

		define('ABSPATH', $this->tmpDir . '/wordpress/');
		define('RUDEL_THEME_SLUG', 'child-theme');
		define('RUDEL_TEMPLATE_SLUG', 'parent-theme');
		define('RUDEL_ENVIRONMENT_THEME_ROOT', $themeRoot);
		define('RUDEL_ENVIRONMENT_THEME_ROOT_URI', 'http://example.test/wp-content/rudel-environments/child/themes');

		require dirname(__DIR__, 2) . '/rudel.php';

		$this->assertSame('/host/themes', \rudel_overlay_theme_root('/host/themes', false));
		$this->assertSame('http://example.test/wp-content/themes', \rudel_overlay_theme_root_uri('http://example.test/wp-content/themes', 'http://example.test', false));
		$this->assertSame($themeRoot, \rudel_overlay_theme_root('/host/themes', 'child-theme'));
		$this->assertSame($themeRoot, \rudel_overlay_theme_root('/host/themes', 'parent-theme'));
		$this->assertSame(RUDEL_ENVIRONMENT_THEME_ROOT_URI, \rudel_overlay_theme_root_uri('http://example.test/wp-content/themes', 'http://example.test', 'child-theme'));
		$this->assertSame(RUDEL_ENVIRONMENT_THEME_ROOT_URI, \rudel_overlay_theme_root_uri('http://example.test/wp-content/themes', 'http://example.test', 'parent-theme'));
	}
}
}
