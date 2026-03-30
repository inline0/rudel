<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\EnvironmentManager;
use Rudel\Tests\RudelTestCase;

class LifecycleHooksTest extends RudelTestCase
{
    private function defineConstants(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
    }

    private function actionNames(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['hook'],
            $GLOBALS['rudel_test_actions'] ?? []
        );
    }

    private function filterEntries(string $hook): array
    {
        return array_values(
            array_filter(
                $GLOBALS['rudel_test_filters'] ?? [],
                static fn(array $entry): bool => $hook === $entry['hook']
            )
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnvironmentCreateAndDestroyEmitLifecycleHooks(): void
    {
        $this->defineConstants();
        $manager = new EnvironmentManager($this->tmpDir);

        $sandbox = $manager->create('Hook Box', ['engine' => 'sqlite']);
        $manager->destroy($sandbox->id);

        $hooks = $this->actionNames();
        $this->assertContains('rudel_before_environment_create', $hooks);
        $this->assertContains('rudel_after_environment_create', $hooks);
        $this->assertContains('rudel_before_environment_destroy', $hooks);
        $this->assertContains('rudel_after_environment_destroy', $hooks);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppBackupAndDeployEmitAppAndRecoveryHooks(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Hook App', ['hook-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Hook App Sandbox');

        $GLOBALS['rudel_test_actions'] = [];

        $manager->backup($app->id, 'baseline');
        $manager->deploy($app->id, $sandbox->id, 'pre-deploy');

        $hooks = $this->actionNames();
        $this->assertContains('rudel_before_app_backup', $hooks);
        $this->assertContains('rudel_after_app_backup', $hooks);
        $this->assertContains('rudel_before_backup_create', $hooks);
        $this->assertContains('rudel_after_backup_create', $hooks);
        $this->assertContains('rudel_before_app_deploy', $hooks);
        $this->assertContains('rudel_after_app_deploy', $hooks);
        $this->assertContains('rudel_before_environment_replace_state', $hooks);
        $this->assertContains('rudel_after_environment_replace_state', $hooks);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppDeployHooksExposeContextAndRespectFilteredOptions(): void
    {
        $this->defineConstants();
        define('WP_HOME', 'https://host.test');

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Deploy Hook App', ['deploy-hook-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Deploy Hook Sandbox');

        $afterResult = null;
        $afterContext = null;

        add_filter(
            'rudel_app_deploy_options',
            function (array $options, \Rudel\Environment $filterApp, \Rudel\Environment $filterSandbox, AppManager $filterManager): array use ($app, $sandbox, $manager) {
                $this->assertSame($app->id, $filterApp->id);
                $this->assertSame($sandbox->id, $filterSandbox->id);
                $this->assertSame($manager, $filterManager);

                $options['label'] = 'Filtered deploy label';
                $options['notes'] = 'Filtered deploy notes';
                return $options;
            },
            10,
            4
        );

        add_action(
            'rudel_after_app_deploy',
            function (array $result, array $context) use (&$afterResult, &$afterContext): void {
                $afterResult = $result;
                $afterContext = $context;
            },
            10,
            2
        );

        $result = $manager->deploy($app->id, $sandbox->id, 'pre-deploy');

        $filterEntries = $this->filterEntries('rudel_app_deploy_options');

        $this->assertCount(1, $filterEntries);
        $this->assertSame('Filtered deploy label', $result['deployment']['label']);
        $this->assertSame('Filtered deploy notes', $result['deployment']['notes']);
        $this->assertSame($result['deployment']['id'], $afterResult['deployment']['id']);
        $this->assertSame($app->id, $afterContext['app']->id);
        $this->assertSame($sandbox->id, $afterContext['sandbox']->id);
        $this->assertSame('Filtered deploy label', $filterEntries[0]['value']['label']);
    }
}
