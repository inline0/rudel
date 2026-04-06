<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\Tests\RudelTestCase;

class AppManagerTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateAppUsesPrimaryDomainAsCanonicalUrl(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['demo.example.test']);

		$this->assertTrue($app->is_app());
		$this->assertSame('http://demo.example.test/', $app->get_url());
		$this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'siteurl'));
		$this->assertSame('http://demo.example.test', $this->siteOptionValue((int) $app->blog_id, 'home'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCreateSandboxInheritsTrackedGithubMetadataFromApp(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['client.example.test'], [
			'tracked_github_repo' => 'inline0/client-theme',
			'tracked_github_branch' => 'release',
			'tracked_github_dir' => 'themes/client-theme',
		]);

		$sandbox = $manager->create_sandbox($app->id, 'Feature Sandbox');

		$this->assertSame('inline0/client-theme', $sandbox->tracked_github_repo);
		$this->assertSame('release', $sandbox->tracked_github_branch);
		$this->assertSame('themes/client-theme', $sandbox->tracked_github_dir);
		$this->assertSame('inline0/client-theme', $sandbox->get_github_repo());
		$this->assertSame('release', $sandbox->get_github_base_branch());
		$this->assertSame('themes/client-theme', $sandbox->get_github_dir());
		$this->assertSame(
			'http://' . $sandbox->id . '.example.test',
			$this->siteOptionValue((int) $sandbox->blog_id, 'siteurl')
		);
		$this->assertSame(
			'http://' . $sandbox->id . '.example.test',
			$this->siteOptionValue((int) $sandbox->blog_id, 'home')
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRemovingPrimaryDomainPromotesTheNextDomainAndUpdatesSiteOptions(): void
	{
		$wordpressRoot = $this->tmpDir . '/wordpress';
		mkdir($wordpressRoot . '/wp-content', 0755, true);

		define('ABSPATH', $wordpressRoot . '/');
		define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
		define('WP_HOME', 'http://example.test');
		define('DOMAIN_CURRENT_SITE', 'example.test');

		$manager = new AppManager(
			$this->tmpDir . '/apps',
			$this->tmpDir . '/sandboxes'
		);

		$app = $manager->create('Client Demo', ['demo.example.test', 'www.demo.example.test']);
		$manager->remove_domain($app->id, 'demo.example.test');

		$updated = $manager->get($app->id);

		$this->assertNotNull($updated);
		$this->assertSame(['www.demo.example.test'], $updated->domains);
		$this->assertSame('http://www.demo.example.test/', $updated->get_url());
		$this->assertSame('http://www.demo.example.test', $this->siteOptionValue((int) $updated->blog_id, 'siteurl'));
		$this->assertSame('http://www.demo.example.test', $this->siteOptionValue((int) $updated->blog_id, 'home'));
	}
}
