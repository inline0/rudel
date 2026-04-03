<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\AppManager;
use Rudel\Automation;
use Rudel\RudelConfig;
use Rudel\Tests\RudelTestCase;

class AutomationTest extends RudelTestCase
{
    private function defineConstants(bool $withHome = false): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }
        if (! defined('RUDEL_APPS_DIR')) {
            define('RUDEL_APPS_DIR', $this->tmpDir . '/apps');
        }
        if (! defined('RUDEL_ENVIRONMENTS_DIR')) {
            define('RUDEL_ENVIRONMENTS_DIR', $this->tmpDir . '/sandboxes');
        }
        if ($withHome && ! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }
    }

    private function defineCronFunctions(): void
    {
        eval(<<<'PHP'
namespace {
    if (! function_exists('wp_next_scheduled')) {
        function wp_next_scheduled(string $hook) {
            return $GLOBALS['rudel_scheduled_events'][$hook]['timestamp'] ?? false;
        }
    }

    if (! function_exists('wp_schedule_event')) {
        function wp_schedule_event(int $timestamp, string $recurrence, string $hook): void {
            $GLOBALS['rudel_scheduled_events'][$hook] = [
                'timestamp' => $timestamp,
                'recurrence' => $recurrence,
                'hook' => $hook,
            ];
        }
    }

    if (! function_exists('wp_clear_scheduled_hook')) {
        function wp_clear_scheduled_hook(string $hook): void {
            unset($GLOBALS['rudel_scheduled_events'][$hook]);
        }
    }
}
PHP);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEnsureScheduledRespondsToNewAutomationFlags(): void
    {
        $this->defineConstants();
        $this->defineCronFunctions();

        $config = new RudelConfig();
        $config->set('auto_cleanup_enabled', 0);
        $config->set('auto_cleanup_merged', 0);
        $config->set('auto_app_backups_enabled', 1);
        $config->set('auto_app_backup_retention_count', 0);
        $config->set('auto_app_deployment_retention_count', 0);
        $config->set('expiring_environment_notice_days', 0);
        $config->save();

        Automation::ensure_scheduled();
        $this->assertArrayHasKey(Automation::CRON_HOOK, $GLOBALS['rudel_scheduled_events']);

        $config->set('auto_app_backups_enabled', 0);
        $config->save();

        Automation::ensure_scheduled();
        $this->assertArrayNotHasKey(Automation::CRON_HOOK, $GLOBALS['rudel_scheduled_events']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunPrunesAppHistoryWhenRetentionIsEnabled(): void
    {
        $this->defineConstants(true);

        $manager = new AppManager($this->tmpDir . '/apps', $this->tmpDir . '/sandboxes');
        $app = $manager->create('Automation App', ['automation-app.com'], ['engine' => 'sqlite']);
        $sandbox = $manager->create_sandbox($app->id, 'Automation Sandbox');

        $first = $manager->deploy($app->id, $sandbox->id, 'before-deploy-1');
        $second = $manager->deploy($app->id, $sandbox->id, 'before-deploy-2');

        file_put_contents($app->path . '/backups/before-deploy-1/backup.json', json_encode([
            'name' => 'before-deploy-1',
            'app_id' => $app->id,
            'created_at' => '2026-01-01T00:00:00+00:00',
        ], JSON_PRETTY_PRINT));
        file_put_contents($app->path . '/backups/before-deploy-2/backup.json', json_encode([
            'name' => 'before-deploy-2',
            'app_id' => $app->id,
            'created_at' => '2026-01-02T00:00:00+00:00',
        ], JSON_PRETTY_PRINT));
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('app_deployments'),
            ['deployed_at' => '2026-01-01T00:00:00+00:00'],
            ['deployment_key' => $first['deployment']['id']]
        );
        $this->runtimeStore()->update(
            $this->runtimeStore()->table('app_deployments'),
            ['deployed_at' => '2026-01-02T00:00:00+00:00'],
            ['deployment_key' => $second['deployment']['id']]
        );

        $config = new RudelConfig();
        $config->set('auto_cleanup_enabled', 0);
        $config->set('auto_cleanup_merged', 0);
        $config->set('auto_app_backups_enabled', 0);
        $config->set('auto_app_backup_retention_count', 1);
        $config->set('auto_app_deployment_retention_count', 1);
        $config->set('expiring_environment_notice_days', 0);
        $config->save();

        $result = Automation::run();

        $this->assertCount(1, $result['app_retention']);
        $this->assertSame($app->id, $result['app_retention'][0]['app_id']);
        $this->assertContains('before-deploy-1', $result['app_retention'][0]['backups_removed']);
        $this->assertSame([$first['deployment']['id']], $result['app_retention'][0]['deployments_removed']);
    }
}
