<?php

namespace Rudel\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class BootstrapRoutingPrecedenceTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testExplicitSandboxHeaderWinsOverMappedAppDomain(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $wordpressRoot . '/wp-content/rudel-environments';
		$appsDir = $wordpressRoot . '/wp-content/rudel-apps';
		mkdir($environmentsDir, 0755, true);
		mkdir($appsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');
		define('RUDEL_BOOTSTRAP_SAPI', 'fpm-fcgi');
		define('RUDEL_DISABLE_OPEN_BASEDIR_JAIL', true);

		$appPath = $appsDir . '/demo-app';
		$sandboxPath = $environmentsDir . '/feature-one';
		mkdir($appPath, 0755, true);
		mkdir($sandboxPath, 0755, true);

		$appRepository = new EnvironmentRepository($this->runtimeStore(), $appsDir, 'app');
		$appEnvironment = $appRepository->save(
			new Environment(
				id: 'demo-app',
				name: 'Demo App',
				path: $appPath,
				created_at: '2026-01-01T00:00:00+00:00',
				multisite: true,
				engine: 'subsite',
				blog_id: 2,
				type: 'app',
				domains: ['demo.example.test']
			)
		);
		(new AppRepository($this->runtimeStore(), $appRepository))->create($appEnvironment, ['demo.example.test']);

		$sandboxRepository = new EnvironmentRepository($this->runtimeStore(), $environmentsDir, 'sandbox');
		$sandbox = $sandboxRepository->save(
			new Environment(
				id: 'feature-one',
				name: 'Feature One',
				path: $sandboxPath,
				created_at: '2026-01-01T00:00:00+00:00',
				multisite: true,
				engine: 'subsite',
				blog_id: 3,
				type: 'sandbox'
			)
		);

		$_SERVER['HTTP_HOST'] = 'demo.example.test';
		$_SERVER['HTTP_X_RUDEL_SANDBOX'] = $sandbox->id;
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertTrue(defined('RUDEL_ID'));
		$this->assertSame($sandbox->id, constant('RUDEL_ID'));
		$this->assertFalse(defined('RUDEL_IS_APP') ? (bool) constant('RUDEL_IS_APP') : true);
		$this->assertSame('wp_3_', constant('RUDEL_TABLE_PREFIX'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testBootstrapStripsNonDefaultPortsForCoreLookupWhileKeepingRenderedUrls(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $wordpressRoot . '/wp-content/rudel-environments';
		mkdir($environmentsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://localhost:9878');
		define('DOMAIN_CURRENT_SITE', 'localhost:9878');
		define('RUDEL_BOOTSTRAP_SAPI', 'fpm-fcgi');
		define('RUDEL_DISABLE_OPEN_BASEDIR_JAIL', true);

		$sandboxPath = $environmentsDir . '/feature-port';
		mkdir($sandboxPath, 0755, true);

		$sandbox = (new EnvironmentRepository($this->runtimeStore(), $environmentsDir, 'sandbox'))->save(
			new Environment(
				id: 'feature-port',
				name: 'Feature Port',
				path: $sandboxPath,
				created_at: '2026-01-01T00:00:00+00:00',
				multisite: true,
				engine: 'subsite',
				blog_id: 3,
				type: 'sandbox'
			)
		);

		$_SERVER['HTTP_HOST'] = 'feature-port.localhost:9878';
		$_SERVER['SERVER_NAME'] = 'feature-port.localhost:9878';
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame('feature-port.localhost', $_SERVER['HTTP_HOST']);
		$this->assertSame('feature-port.localhost', $_SERVER['SERVER_NAME']);
		$this->assertSame($sandbox->id, constant('RUDEL_ID'));
		$this->assertSame('http://feature-port.localhost:9878', constant('RUDEL_ENVIRONMENT_URL'));
		$this->assertSame('http://localhost:9878', constant('RUDEL_HOST_URL'));
		$this->assertSame('wp_3_', constant('RUDEL_TABLE_PREFIX'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testBootstrapNormalizesCliUrlArgumentsWhileKeepingRenderedCliUrl(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $wordpressRoot . '/wp-content/rudel-environments';
		mkdir($environmentsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://localhost:9878');
		define('DOMAIN_CURRENT_SITE', 'localhost');
		define('RUDEL_BOOTSTRAP_SAPI', 'cli');
		define('RUDEL_DISABLE_OPEN_BASEDIR_JAIL', true);

		$sandboxPath = $environmentsDir . '/feature-port';
		mkdir($sandboxPath, 0755, true);

		(new EnvironmentRepository($this->runtimeStore(), $environmentsDir, 'sandbox'))->save(
			new Environment(
				id: 'feature-port',
				name: 'Feature Port',
				path: $sandboxPath,
				created_at: '2026-01-01T00:00:00+00:00',
				multisite: true,
				engine: 'subsite',
				blog_id: 3,
				type: 'sandbox'
			)
		);

		global $argv;
		$argv = array('wp', '--url=http://feature-port.localhost:9878/', 'option', 'get', 'blogname');
		$_SERVER['argv'] = array('wp', '--url', 'http://feature-port.localhost:9878/', 'option', 'get', 'blogname');
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame('feature-port', constant('RUDEL_ID'));
		$this->assertSame('--url=http://feature-port.localhost/', $argv[1]);
		$this->assertSame('http://feature-port.localhost/', $_SERVER['argv'][2]);
		$this->assertSame('http://feature-port.localhost:9878', constant('RUDEL_ENVIRONMENT_URL'));
		$this->assertSame('http://localhost:9878', constant('RUDEL_HOST_URL'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMappedAppDomainPreservesCanonicalHostAndRenderedUrl(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		$environmentsDir = $wordpressRoot . '/wp-content/rudel-environments';
		$appsDir = $wordpressRoot . '/wp-content/rudel-apps';
		mkdir($environmentsDir, 0755, true);
		mkdir($appsDir, 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://localhost:9878');
		define('DOMAIN_CURRENT_SITE', 'localhost');
		define('RUDEL_BOOTSTRAP_SAPI', 'fpm-fcgi');
		define('RUDEL_DISABLE_OPEN_BASEDIR_JAIL', true);

		$appPath = $appsDir . '/demo-app';
		mkdir($appPath, 0755, true);

		$appRepository = new EnvironmentRepository($this->runtimeStore(), $appsDir, 'app');
		$appEnvironment = $appRepository->save(
			new Environment(
				id: 'demo-app',
				name: 'Demo App',
				path: $appPath,
				created_at: '2026-01-01T00:00:00+00:00',
				multisite: true,
				engine: 'subsite',
				blog_id: 4,
				type: 'app',
				domains: ['demo.example.test']
			)
		);
		(new AppRepository($this->runtimeStore(), $appRepository))->create($appEnvironment, ['demo.example.test']);

		$_SERVER['HTTP_HOST'] = 'demo.example.test:9878';
		$_SERVER['SERVER_NAME'] = 'demo.example.test:9878';
		$_SERVER['SCRIPT_FILENAME'] = $wordpressRoot . '/index.php';

		require dirname(__DIR__, 2) . '/bootstrap.php';

		$this->assertSame('demo-app', constant('RUDEL_ID'));
		$this->assertTrue((bool) constant('RUDEL_IS_APP'));
		$this->assertSame('demo.example.test', $_SERVER['HTTP_HOST']);
		$this->assertSame('demo.example.test', $_SERVER['SERVER_NAME']);
		$this->assertSame('http://demo.example.test:9878', constant('RUDEL_ENVIRONMENT_URL'));
		$this->assertSame('http://localhost:9878', constant('RUDEL_HOST_URL'));
		$this->assertSame('wp_4_', constant('RUDEL_TABLE_PREFIX'));
	}
}
