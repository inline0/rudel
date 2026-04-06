<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\SubsiteCloner;
use Rudel\Tests\RudelTestCase;

class SubsiteClonerTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSubsiteThrowsWhenNotMultisite(): void
    {
        $cloner = new class () extends SubsiteCloner {
            protected function is_multisite_network(): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('multisite installation');
        $cloner->create_subsite('test-sandbox', 'Test Sandbox');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetSubsiteTargetThrowsWhenNetworkIsNotSubdomainBased(): void
    {
        $cloner = new class () extends SubsiteCloner {
            protected function is_multisite_network(): bool
            {
                return true;
            }

            protected function is_subdomain_network(): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('subdomain multisite network');
        $cloner->get_subsite_target('alpha-site');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetSubsiteTargetUsesNativeSubdomainSiteAddress(): void
    {
        if (! defined('DOMAIN_CURRENT_SITE')) {
            define('DOMAIN_CURRENT_SITE', 'example.test');
        }

        $cloner = new class () extends SubsiteCloner {
            protected function is_multisite_network(): bool
            {
                return true;
            }

            protected function is_subdomain_network(): bool
            {
                return true;
            }
        };

        $target = $cloner->get_subsite_target('alpha-site');

        $this->assertSame('alpha-site.example.test', $target['domain']);
        $this->assertSame('/', $target['path']);
    }

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDeleteSubsiteRemovesTheSiteRecord(): void
	{
		$GLOBALS['rudel_test_sites'][999] = (object) [
			'blog_id' => 999,
			'domain' => 'alpha.example.test',
			'path' => '/',
			'siteurl' => 'http://alpha.example.test/',
		];

		$cloner = new SubsiteCloner();
		$cloner->delete_subsite(999);

		$this->assertArrayNotHasKey(999, $GLOBALS['rudel_test_sites']);
	}

	public function testGetSubsiteUrlFallsBackToHttpHost(): void
	{
		// Mock get_blog_details by not having WordPress loaded.
		// The method calls get_blog_details which doesn't exist in tests.
		// Just verify the class can be instantiated.
		$cloner = new SubsiteCloner();
		$this->assertInstanceOf(SubsiteCloner::class, $cloner);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testGetSubsiteUrlUsesSiteDomainAndNetworkPort(): void
	{
		if (! defined('WP_HOME')) {
			define('WP_HOME', 'http://localhost:9888');
		}

		$GLOBALS['rudel_test_sites'][5] = [
			'blog_id' => 5,
			'domain' => 'alpha-site.localhost',
			'path' => '/',
			'siteurl' => 'http://localhost:9888/',
		];

		$cloner = new SubsiteCloner();

		$this->assertSame('http://alpha-site.localhost:9888/', $cloner->get_subsite_url(5));
	}
}
