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
    public function testContextExposesCurrentMultisiteRuntimeShape(): void
    {
        define('RUDEL_ID', 'alpha-site');
        define('RUDEL_IS_APP', false);
        define('RUDEL_PATH', '/tmp/rudel/alpha-site');
        define('RUDEL_ENGINE', 'subsite');
        define('RUDEL_TABLE_PREFIX', 'wp_2_');
        define('RUDEL_ENVIRONMENT_URL', 'http://alpha-site.example.test');
        define('RUDEL_HOST_URL', 'http://example.test');
        define('RUDEL_DISABLE_EMAIL', true);
        define('RUDEL_VERSION', '1.0.0');
        define('RUDEL_CLI_COMMAND', 'rudel');

        $context = Rudel::context();

        $this->assertTrue(Rudel::is_sandbox());
        $this->assertSame('subsite', Rudel::engine());
        $this->assertSame('http://alpha-site.example.test/', Rudel::url());
        $this->assertSame('http://example.test/', Rudel::exit_url());
        $this->assertSame(
            [
                'is_sandbox',
                'is_app',
                'id',
                'app_id',
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
        $this->assertSame('subsite', $context['engine']);
        $this->assertSame('http://alpha-site.example.test/', $context['url']);
        $this->assertSame('http://example.test/', $context['exit_url']);
    }
}
