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
}
