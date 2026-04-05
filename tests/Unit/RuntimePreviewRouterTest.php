<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Tests\RudelTestCase;

class RuntimePreviewRouterTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuntimeRouterResolvesAdminIndexPhp(): void
	{
		require dirname(__DIR__, 2) . '/runtime-preview-router.php';

		$resolved = rudel_runtime_preview_resolve('/wp-admin/', '/var/www/html/', '/var/www/html/wp-content');

		$this->assertSame(
			[
				'type' => 'php',
				'path' => '/var/www/html/wp-admin/index.php',
				'request_path' => '/wp-admin/',
			],
			$resolved
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuntimeRouterResolvesRootLoginEntrypoint(): void
	{
		require dirname(__DIR__, 2) . '/runtime-preview-router.php';

		$resolved = rudel_runtime_preview_resolve('/wp-login.php?redirect_to=%2Fwp-admin%2F', '/var/www/html/', '/var/www/html/wp-content');

		$this->assertSame(
			[
				'type' => 'php',
				'path' => '/var/www/html/wp-login.php',
				'request_path' => '/wp-login.php',
			],
			$resolved
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuntimeRouterResolvesStaticAssetsInsideEnvironment(): void
	{
		require dirname(__DIR__, 2) . '/runtime-preview-router.php';

		$content_dir = $this->tmpDir . '/wp-content';
		mkdir($content_dir . '/themes/demo', 0755, true);
		file_put_contents($content_dir . '/themes/demo/style.css', 'body{}');

		$resolved = rudel_runtime_preview_resolve('/wp-content/themes/demo/style.css', '/var/www/html/', $content_dir);

		$this->assertSame(
			[
				'type' => 'static',
				'path' => realpath($content_dir . '/themes/demo/style.css'),
				'request_path' => '/wp-content/themes/demo/style.css',
			],
			$resolved
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuntimeRouterPreparePhpRequestSetsEntrypointGlobals(): void
	{
		require dirname(__DIR__, 2) . '/runtime-preview-router.php';

		rudel_runtime_preview_prepare_php_request('/wp-login.php', '/var/www/html/wp-login.php');

		$this->assertSame('/wp-login.php', $_SERVER['SCRIPT_NAME']);
		$this->assertSame('/wp-login.php', $_SERVER['PHP_SELF']);
		$this->assertSame('/var/www/html/wp-login.php', $_SERVER['SCRIPT_FILENAME']);
		$this->assertSame('/wp-login.php', $_SERVER['DOCUMENT_URI']);
	}
}
