<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\Rudel;
use Rudel\Tests\RudelTestCase;

class CliCommandMapTest extends RudelTestCase
{
    public function testCliCommandMapIncludesExpectedOperations(): void
    {
        $operations = array_column(Rudel::cli_command_map(), 'operation');

        $this->assertContains('sandbox.create', $operations);
        $this->assertContains('sandbox.logs', $operations);
        $this->assertContains('template.list', $operations);
        $this->assertContains('app.deploy', $operations);
        $this->assertContains('app.domain-remove', $operations);
    }

    public function testResolveAcceptsFullWpCliPath(): void
    {
        $plan = Rudel::resolve_cli_command('wp rudel template list');

        $this->assertSame('template.list', $plan['operation']);
        $this->assertSame('php', $plan['transport']);
        $this->assertSame(\Rudel\Rudel::class . '::templates', $plan['callable']);
        $this->assertSame([], $plan['arguments']);
    }

    public function testResolveSandboxCreateWithGithubUsesGithubTarget(): void
    {
        $plan = Rudel::resolve_cli_command(
            ['create'],
            [],
            [
                'github' => 'inline0/rudel-theme',
                'type' => 'plugin',
                'engine' => 'sqlite',
                'ttl-days' => '3',
            ]
        );

        $this->assertSame('sandbox.create', $plan['operation']);
        $this->assertSame(\Rudel\Rudel::class . '::create_from_github', $plan['callable']);
        $this->assertSame('inline0/rudel-theme', $plan['arguments'][0]);
        $this->assertSame('rudel-theme', $plan['arguments'][1]['name']);
        $this->assertSame('plugin', $plan['arguments'][1]['type']);
        $this->assertSame('sqlite', $plan['arguments'][1]['engine']);
        $this->assertSame('3', $plan['arguments'][1]['ttl_days']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolveSandboxDestroyIncludesConfirmation(): void
    {
        [$sandboxesDir, $appsDir] = $this->bootstrapEnvironmentDirs();

        $manager = new \Rudel\EnvironmentManager($sandboxesDir, $appsDir);
        $sandbox = $manager->create('Destroy Me', ['engine' => 'sqlite']);

        $plan = Rudel::resolve_cli_command(['destroy'], [$sandbox->id], []);

        $this->assertSame('sandbox.destroy', $plan['operation']);
        $this->assertSame(\Rudel\Rudel::class . '::destroy', $plan['callable']);
        $this->assertTrue($plan['needs_confirmation']);
        $this->assertStringContainsString($sandbox->name, $plan['confirmation_message']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolveLogsFollowReturnsShellPlan(): void
    {
        [$sandboxesDir, $appsDir] = $this->bootstrapEnvironmentDirs();

        $manager = new \Rudel\EnvironmentManager($sandboxesDir, $appsDir);
        $sandbox = $manager->create('Log Tail', ['engine' => 'sqlite']);

        $plan = Rudel::resolve_cli_command(['logs'], [$sandbox->id], ['follow' => true]);

        $this->assertSame('sandbox.logs', $plan['operation']);
        $this->assertSame('shell', $plan['transport']);
        $this->assertSame(
            ['tail', '-f', $sandbox->get_wp_content_path() . '/debug.log'],
            $plan['command']
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolveAppDeployDryRunUsesPlanTarget(): void
    {
        [$sandboxesDir, $appsDir] = $this->bootstrapEnvironmentDirs();

        $appManager = new \Rudel\AppManager($appsDir, $sandboxesDir);
        $app = $appManager->create('Client A', ['client-a.test'], ['engine' => 'sqlite']);
        $sandbox = $appManager->create_sandbox($app->id, 'Client A QA', []);

        $plan = Rudel::resolve_cli_command(
            ['app', 'deploy'],
            [$app->id],
            [
                'from' => $sandbox->id,
                'backup' => 'before-qa',
                'label' => 'QA',
                'dry-run' => true,
            ]
        );

        $this->assertSame('app.deploy', $plan['operation']);
        $this->assertSame(\Rudel\Rudel::class . '::plan_app_deploy', $plan['callable']);
        $this->assertSame([$app->id, $sandbox->id, 'before-qa', ['label' => 'QA', 'notes' => null]], $plan['arguments']);
        $this->assertFalse($plan['needs_confirmation']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReadLogAndClearLogUseStructuredResults(): void
    {
        [$sandboxesDir, $appsDir] = $this->bootstrapEnvironmentDirs();

        $manager = new \Rudel\EnvironmentManager($sandboxesDir, $appsDir);
        $sandbox = $manager->create('Log Box', ['engine' => 'sqlite']);
        $logPath = $sandbox->get_wp_content_path() . '/debug.log';
        file_put_contents($logPath, "One\nTwo\nThree\n");

        $read = Rudel::read_log($sandbox->id, 2);
        $this->assertTrue($read['exists']);
        $this->assertFalse($read['empty']);
        $this->assertSame(3, $read['total_lines']);
        $this->assertSame(['Two', 'Three'], $read['lines']);

        $clear = Rudel::clear_log($sandbox->id);
        $this->assertTrue($clear['existed']);
        $this->assertTrue($clear['cleared']);
        $this->assertSame('', file_get_contents($logPath));
    }

    private function bootstrapEnvironmentDirs(): array
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }

        $sandboxesDir = $this->tmpDir . '/sandboxes';
        $appsDir = $this->tmpDir . '/apps';
        mkdir($sandboxesDir, 0755, true);
        mkdir($appsDir, 0755, true);

        define('RUDEL_ENVIRONMENTS_DIR', $sandboxesDir);
        define('RUDEL_APPS_DIR', $appsDir);

        return [$sandboxesDir, $appsDir];
    }
}
