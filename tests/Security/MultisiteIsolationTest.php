<?php

namespace Rudel\Tests\Security;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class MultisiteIsolationTest extends RudelTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEachEnvironmentGetsItsOwnSubsiteIdentityAndTables(): void
    {
        $wordpressRoot = $this->tmpDir . '/wordpress';
        mkdir($wordpressRoot . '/wp-content', 0755, true);

        define('ABSPATH', $wordpressRoot . '/');
        define('WP_CONTENT_DIR', $wordpressRoot . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');

        $manager = new EnvironmentManager(
            $this->tmpDir . '/sandboxes',
            $this->tmpDir . '/apps',
            'sandbox',
            $this->runtimeStore()
        );

        $alpha = $manager->create('Alpha Site');
        $beta = $manager->create('Beta Site');

        $this->assertNotSame($alpha->blog_id, $beta->blog_id);
        $this->assertNotSame($alpha->get_table_prefix(), $beta->get_table_prefix());
        $this->assertNotSame($alpha->get_url(), $beta->get_url());
    }
}
