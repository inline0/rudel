<?php

namespace Rudel\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\RudelSchema;
use Rudel\RuntimeProfile;
use Rudel\Tests\Fixtures\RuntimeProfiles;
use Rudel\Tests\RudelTestCase;

class RuntimeProfileBootstrapTest extends RudelTestCase
{
	private function installNeutralProfile(string $wordpressRoot, string $home = 'http://example.test', string $sapi = 'fpm-fcgi'): string
	{
		RuntimeProfile::set_current(RuntimeProfiles::neutral($this->tmpDir));
		RudelSchema::ensure($this->runtimeStore());

		$environmentsDir = $wordpressRoot . '/wp-content/fixture-environments';
		mkdir($environmentsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', $home);
		define('RUDEL_BOOTSTRAP_SAPI', $sapi);

		return $environmentsDir;
	}

	private function saveOverlayEnvironment(string $baseDir, string $id, string $tablePrefix = ''): Environment
	{
		$path = $baseDir . '/' . $id;
		mkdir($path . '/themes/fixture-theme', 0755, true);
		file_put_contents($path . '/themes/fixture-theme/style.css', '/* fixture */');

		$repository = new EnvironmentRepository($this->runtimeStore(), $baseDir, 'sandbox');

		return $repository->save(
			new Environment(
				id: $id,
				name: $id,
				path: $path,
				created_at: '2026-01-01T00:00:00+00:00',
				engine: 'overlay',
				type: 'sandbox',
				table_prefix: '' !== $tablePrefix ? $tablePrefix : 'wp_fixture_' . str_replace('-', '_', $id) . '_',
				theme_slug: 'fixture-theme'
			)
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testBootstrapFailsClosedWhenProfileIsMissing(): void
	{
		RuntimeProfile::set_current(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Rudel runtime profile is required');

		require dirname(__DIR__, 2) . '/bootstrap.php';
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testNeutralProfileHeaderSelectsEnvironment(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $this->installNeutralProfile($wordpressRoot);
		$environment = $this->saveOverlayEnvironment($environmentsDir, 'feature-one', 'wp_fixture_one_');

		$_SERVER['HTTP_HOST'] = 'example.test';
		$_SERVER['HTTP_X_FIXTURE_ENVIRONMENT'] = $environment->id;
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($environment->id, constant('FIXTURE_ID'));
		$this->assertSame('overlay', constant('FIXTURE_ENGINE'));
		$this->assertSame($environment->get_table_prefix(), constant('FIXTURE_TABLE_PREFIX'));
		$this->assertFalse(defined('RUDEL_ID'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testNeutralProfileEnvironmentVariableSelectsEnvironment(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $this->installNeutralProfile($wordpressRoot, 'http://example.test', 'cli');
		$environment = $this->saveOverlayEnvironment($environmentsDir, 'feature-cli', 'wp_fixture_cli_');
		putenv('FIXTURE_ENVIRONMENT=' . $environment->id);

		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($environment->id, constant('FIXTURE_ID'));
		$this->assertSame($environment->get_table_prefix(), constant('FIXTURE_TABLE_PREFIX'));
		putenv('FIXTURE_ENVIRONMENT');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testNeutralProfileCookieSelectsEnvironment(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $this->installNeutralProfile($wordpressRoot);
		$environment = $this->saveOverlayEnvironment($environmentsDir, 'feature-cookie', 'wp_fixture_cookie_');

		$_SERVER['HTTP_HOST'] = 'example.test';
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';
		$_COOKIE['fixture_environment'] = $environment->id;

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame($environment->id, constant('FIXTURE_ID'));
		$this->assertSame($environment->get_table_prefix(), constant('FIXTURE_TABLE_PREFIX'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testLegacySelectorsAreIgnoredWhenProfileDoesNotDefineThem(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $this->installNeutralProfile($wordpressRoot);
		$environment = $this->saveOverlayEnvironment($environmentsDir, 'legacy-input', 'wp_fixture_legacy_');

		$_SERVER['HTTP_HOST'] = 'example.test';
		$_SERVER['HTTP_X_RUDEL_ENVIRONMENT'] = $environment->id;
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';
		$_COOKIE['rudel_environment'] = $environment->id;
		putenv('RUDEL_ENVIRONMENT=' . $environment->id);

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertFalse(defined('FIXTURE_ID'));
		$this->assertFalse(defined('RUDEL_ID'));
		putenv('RUDEL_ENVIRONMENT');
	}
}
