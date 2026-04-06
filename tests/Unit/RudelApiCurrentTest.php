<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Rudel;
use Rudel\RudelDatabase;
use PHPUnit\Framework\TestCase;

class RudelApiCurrentTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextExposesCurrentMultisiteRuntimeShape(): void
    {
        $GLOBALS['wpdb'] = new \MockWpdb();
        $GLOBALS['wpdb']->base_prefix = 'wp_';
        $GLOBALS['wpdb']->prefix = 'wp_2_';

        define('RUDEL_ID', 'alpha-site');
        define('RUDEL_IS_APP', false);
        define('RUDEL_PATH', '/tmp/rudel/alpha-site');
        define('RUDEL_ENGINE', 'subsite');
        define('RUDEL_ENVIRONMENT_URL', 'http://alpha-site.example.test');
        define('RUDEL_HOST_URL', 'http://example.test');
        define('RUDEL_DISABLE_EMAIL', true);
        define('RUDEL_VERSION', '1.0.0');
        define('RUDEL_CLI_COMMAND', 'rudel');

        $context = Rudel::context();

        $this->assertTrue(Rudel::is_sandbox());
        $this->assertSame('subsite', Rudel::engine());
        $this->assertSame('wp_2_', Rudel::table_prefix());
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
        $this->assertSame('wp_2_', $context['table_prefix']);
        $this->assertSame('http://alpha-site.example.test/', $context['url']);
        $this->assertSame('http://example.test/', $context['exit_url']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUrlFallsBackToCanonicalAppDomainFromRuntimeRecords(): void
    {
        $root = sys_get_temp_dir() . '/rudel-api-current-' . uniqid('', true);
        $appsDir = $root . '/wp-content/rudel-apps';
        $sandboxesDir = $root . '/wp-content/rudel-environments';
        $appPath = $appsDir . '/demo-app';
        mkdir($appPath, 0755, true);
        mkdir($sandboxesDir, 0755, true);

        $GLOBALS['wpdb'] = new \MockWpdb();
        $GLOBALS['wpdb']->base_prefix = 'wp_';
        $GLOBALS['wpdb']->prefix = 'wp_2_';

        define('ABSPATH', $root . '/');
        define('WP_CONTENT_DIR', $root . '/wp-content');
        define('WP_HOME', 'http://example.test');
        define('DOMAIN_CURRENT_SITE', 'example.test');
        define('RUDEL_ID', 'demo-app');
        define('RUDEL_IS_APP', true);
        define('RUDEL_PATH', $appPath);
        define('RUDEL_ENGINE', 'subsite');
        define('RUDEL_HOST_URL', 'http://example.test');
        define('RUDEL_DISABLE_EMAIL', false);
        define('RUDEL_VERSION', '1.0.0');
        define('RUDEL_CLI_COMMAND', 'rudel');

        $store = RudelDatabase::for_paths($appsDir, $sandboxesDir);
        $repository = new EnvironmentRepository($store, $appsDir, 'app');
        $app = $repository->save(
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
        (new AppRepository($store, $repository))->create($app, ['demo.example.test']);

        $this->assertSame('http://demo.example.test/', Rudel::url());
        $this->assertSame('http://demo.example.test/', Rudel::context()['url']);
    }
}
