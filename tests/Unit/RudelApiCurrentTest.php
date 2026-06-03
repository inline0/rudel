<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Rudel;
use PHPUnit\Framework\TestCase;

class RudelApiCurrentTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextExposesCurrentOverlayRuntimeShape(): void
    {
        $GLOBALS['wpdb'] = new \MockWpdb();
        $GLOBALS['wpdb']->base_prefix = 'wp_';
        $GLOBALS['wpdb']->prefix = 'wp_alpha123_';

        define('RUDEL_ID', 'alpha-site');
        define('RUDEL_PATH', '/tmp/rudel/alpha-site');
        define('RUDEL_ENGINE', 'overlay');
        define('RUDEL_ENVIRONMENT_URL', 'http://example.test');
        define('RUDEL_HOST_URL', 'http://example.test');
        define('RUDEL_DISABLE_EMAIL', true);
        define('RUDEL_VERSION', '1.0.0');
        define('RUDEL_CLI_COMMAND', 'rudel');

        $context = Rudel::context();

        $this->assertTrue(Rudel::is_sandbox());
        $this->assertSame('overlay', Rudel::engine());
        $this->assertSame('wp_alpha123_', Rudel::table_prefix());
        $this->assertSame('http://example.test/', Rudel::url());
        $this->assertSame('http://example.test/', Rudel::exit_url());
        $this->assertSame(
            [
                'is_sandbox',
                'id',
                'path',
                'engine',
                'table_prefix',
                'url',
                'exit_url',
                'email_disabled',
                'log_path',
                'version',
                'cli_command',
            ],
            array_keys($context)
        );
        $this->assertSame('overlay', $context['engine']);
        $this->assertSame('wp_alpha123_', $context['table_prefix']);
        $this->assertSame('http://example.test/', $context['url']);
        $this->assertSame('http://example.test/', $context['exit_url']);
        $this->assertSame('/tmp/rudel/alpha-site/debug.log', $context['log_path']);
    }
}
