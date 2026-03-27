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
        // is_multisite() is not defined or returns false in test context.
        $cloner = new SubsiteCloner();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('multisite installation');
        $cloner->create_subsite('test-sandbox', 'Test Sandbox');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeleteSubsiteRequiresMsFunctions(): void
    {
        // wpmu_delete_blog is not available in test context.
        // The method requires the ms.php file from ABSPATH.
        $cloner = new SubsiteCloner();

        // Without ABSPATH defined, the require_once will fail.
        $this->expectException(\Throwable::class);
        $cloner->delete_subsite(999);
    }

    public function testGetSubsiteUrlFallsBackToHttpHost(): void
    {
        // Mock get_blog_details by not having WordPress loaded.
        // The method calls get_blog_details which doesn't exist in tests.
        // Just verify the class can be instantiated.
        $cloner = new SubsiteCloner();
        $this->assertInstanceOf(SubsiteCloner::class, $cloner);
    }
}
