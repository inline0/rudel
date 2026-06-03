<?php

namespace Rudel\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class BootstrapRoutingPrecedenceTest extends RudelTestCase
{
	private function createRuntimeDirs(string $wordpressRoot, string $home = 'http://example.test', string $sapi = 'fpm-fcgi'): array
	{
		$environmentsDir = $wordpressRoot . '/wp-content/rudel-environments';
		mkdir($environmentsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', $home);
		define('RUDEL_BOOTSTRAP_SAPI', $sapi);

		return [$environmentsDir];
	}

	private function saveOverlayEnvironment(
		string $baseDir,
		string $id,
		string $tablePrefix = '',
		string $themeSlug = 'host-theme',
		?string $templateSlug = null
	): Environment {
		$path = $baseDir . '/' . $id;
		mkdir($path . '/themes/' . $themeSlug, 0755, true);
		file_put_contents(
			$path . '/themes/' . $themeSlug . '/style.css',
			null !== $templateSlug
				? "/*\nTheme Name: {$themeSlug}\nTemplate: {$templateSlug}\n*/"
				: '/* overlay */'
		);
		if (null !== $templateSlug && $templateSlug !== $themeSlug) {
			mkdir($path . '/themes/' . $templateSlug, 0755, true);
			file_put_contents($path . '/themes/' . $templateSlug . '/style.css', "/*\nTheme Name: {$templateSlug}\n*/");
		}

		$repository = new EnvironmentRepository($this->runtimeStore(), $baseDir, 'sandbox');
		$environment = $repository->save(
			new Environment(
				id: $id,
				name: $id,
				path: $path,
				created_at: '2026-01-01T00:00:00+00:00',
				engine: 'overlay',
				type: 'sandbox',
				table_prefix: '' !== $tablePrefix ? $tablePrefix : 'wp_' . str_replace('-', '_', $id) . '_',
				theme_slug: $themeSlug
			)
		);

		return $environment;
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testExplicitEnvironmentHeaderWinsOverCookie(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		[$environmentsDir] = $this->createRuntimeDirs($wordpressRoot);

		$cookieSandbox = $this->saveOverlayEnvironment($environmentsDir, 'cookie-one', 'wp_cookie_one_');
		$sandbox = $this->saveOverlayEnvironment($environmentsDir, 'feature-one', 'wp_feature_one_');

		$_SERVER['HTTP_HOST'] = 'example.test';
		$_SERVER['HTTP_X_RUDEL_ENVIRONMENT'] = $sandbox->id;
		$_COOKIE['rudel_environment'] = $cookieSandbox->id;
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';
		$table_prefix = 'wp_';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($sandbox->id, constant('RUDEL_ID'));
		$this->assertSame('overlay', constant('RUDEL_ENGINE'));
		$this->assertSame($sandbox->get_table_prefix(), constant('RUDEL_TABLE_PREFIX'));
		$this->assertSame($sandbox->get_table_prefix(), $GLOBALS['table_prefix']);
		$this->assertSame($sandbox->get_table_prefix(), $table_prefix);
		$this->assertSame('host-theme', constant('RUDEL_THEME_SLUG'));
		$this->assertSame('host-theme', constant('RUDEL_TEMPLATE_SLUG'));
		$this->assertSame(realpath($sandbox->path) . '/themes', constant('RUDEL_ENVIRONMENT_THEME_ROOT'));
		$this->assertSame('http://example.test/wp-content/rudel-environments/' . $sandbox->id . '/themes', constant('RUDEL_ENVIRONMENT_THEME_ROOT_URI'));
		$this->assertNotSame($cookieSandbox->id, constant('RUDEL_ID'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCookieSelectsOverlayWithoutChangingHostHeaders(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		[$environmentsDir] = $this->createRuntimeDirs($wordpressRoot);
		$sandbox = $this->saveOverlayEnvironment($environmentsDir, 'feature-cookie', 'wp_cookie_');

		$_SERVER['HTTP_HOST'] = 'localhost:8000';
		$_SERVER['SERVER_NAME'] = 'localhost:8000';
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';
		$_COOKIE['rudel_environment'] = $sandbox->id;

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($sandbox->id, constant('RUDEL_ID'));
		$this->assertSame('localhost:8000', $_SERVER['HTTP_HOST']);
		$this->assertSame('localhost:8000', $_SERVER['SERVER_NAME']);
		$this->assertSame('http://localhost:8000', constant('RUDEL_ENVIRONMENT_URL'));
		$this->assertSame($sandbox->get_table_prefix(), constant('RUDEL_TABLE_PREFIX'));
		$this->assertFalse(defined('WP_CONTENT_DIR') && WP_CONTENT_DIR === $sandbox->path);
		$this->assertFalse(defined('WP_HOME') && WP_HOME === constant('RUDEL_ENVIRONMENT_URL'));
		$this->assertFalse(defined('WP_SITEURL'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testHostRequestWithoutEnvironmentLeavesRuntimeUntouched(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$this->createRuntimeDirs($wordpressRoot, 'http://localhost:8000');

		$_SERVER['HTTP_HOST'] = 'localhost:8000';
		$_SERVER['SERVER_NAME'] = 'localhost:8000';
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertFalse(defined('RUDEL_ID'));
		$this->assertSame('localhost:8000', $_SERVER['HTTP_HOST']);
		$this->assertSame('localhost:8000', $_SERVER['SERVER_NAME']);
		$this->assertSame('http://localhost:8000', constant('RUDEL_HOST_URL'));
		$this->assertSame('wp_', $GLOBALS['table_prefix']);
		$this->assertFalse(defined('RUDEL_TABLE_PREFIX'));
		$this->assertFalse(defined('WP_SITEURL'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCliEnvironmentVariableSelectsOverlayWithoutRewritingUrlArguments(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		[$environmentsDir] = $this->createRuntimeDirs($wordpressRoot, 'http://example.test', 'cli');
		$sandbox = $this->saveOverlayEnvironment($environmentsDir, 'feature-cli', 'wp_feature_cli_');
		putenv('RUDEL_ENVIRONMENT=' . $sandbox->id);

		global $argv;
		$argv = ['wp', '--url=http://localhost:8000/', 'option', 'get', 'blogname'];
		$_SERVER['argv'] = ['wp', '--url', 'http://localhost:8000/', 'option', 'get', 'blogname'];
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';
		$table_prefix = 'wp_';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($sandbox->id, constant('RUDEL_ID'));
		$this->assertSame('--url=http://localhost:8000/', $argv[1]);
		$this->assertSame('http://localhost:8000/', $_SERVER['argv'][2]);
		$this->assertSame($sandbox->get_table_prefix(), constant('RUDEL_TABLE_PREFIX'));
		$this->assertSame('wp_', constant('RUDEL_HOST_TABLE_PREFIX'));
		$this->assertSame($sandbox->get_table_prefix(), $GLOBALS['table_prefix']);
		$this->assertSame($sandbox->get_table_prefix(), $table_prefix);
		putenv('RUDEL_ENVIRONMENT');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testBootstrapDefinesSeparateTemplateSlugForChildThemeOverlay(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		[$environmentsDir] = $this->createRuntimeDirs($wordpressRoot);
		$sandbox = $this->saveOverlayEnvironment(
			$environmentsDir,
			'child-feature',
			'wp_child_feature_',
			'child-theme',
			'parent-theme'
		);

		$_SERVER['HTTP_HOST'] = 'example.test';
		$_SERVER['HTTP_X_RUDEL_ENVIRONMENT'] = $sandbox->id;
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame('child-theme', constant('RUDEL_THEME_SLUG'));
		$this->assertSame('parent-theme', constant('RUDEL_TEMPLATE_SLUG'));
	}
}
