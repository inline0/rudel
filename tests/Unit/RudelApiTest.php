<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\Rudel;
use Rudel\RudelConfig;
use Rudel\Tests\RudelTestCase;

class RudelApiTest extends RudelTestCase
{
    // Context: not in sandbox

    public function testIsSandboxReturnsFalseOutsideSandbox(): void
    {
        $this->assertFalse(Rudel::is_sandbox());
    }

    public function testIsAppReturnsFalseOutsideApp(): void
    {
        $this->assertFalse(Rudel::is_app());
    }

    public function testIdReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::id());
    }

    public function testAppIdReturnsNullOutsideApp(): void
    {
        $this->assertNull(Rudel::app_id());
    }

    public function testPathReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::path());
    }

    public function testEngineReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::engine());
    }

    public function testTablePrefixReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::table_prefix());
    }

    public function testUrlReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::url());
    }

    public function testLogPathReturnsNullOutsideSandbox(): void
    {
        $this->assertNull(Rudel::log_path());
    }

    public function testIsEmailDisabledReturnsFalseOutsideSandbox(): void
    {
        $this->assertFalse(Rudel::is_email_disabled());
    }

    public function testExitUrlWorksOutsideSandbox(): void
    {
        $this->assertSame('/?adminExit', Rudel::exit_url());
    }

    public function testContextReturnsArrayOutsideSandbox(): void
    {
        $ctx = Rudel::context();
        $this->assertFalse($ctx['is_sandbox']);
        $this->assertFalse($ctx['is_app']);
        $this->assertNull($ctx['id']);
        $this->assertNull($ctx['app_id']);
        $this->assertNull($ctx['engine']);
    }

    // Context: in sandbox

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsSandboxReturnsTrueInSandbox(): void
    {
        define('RUDEL_ID', 'test-sandbox');
        define('RUDEL_PATH', '/tmp/test-sandbox');
        $this->assertTrue(Rudel::is_sandbox());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIdReturnsSandboxId(): void
    {
        define('RUDEL_ID', 'my-box-1234');
        $this->assertSame('my-box-1234', Rudel::id());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPathReturnsSandboxPath(): void
    {
        define('RUDEL_PATH', '/var/sandboxes/my-box');
        $this->assertSame('/var/sandboxes/my-box', Rudel::path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEngineReadsFromMetadata(): void
    {
        $path = $this->tmpDir . '/engine-test';
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode(['engine' => 'sqlite']));

        define('RUDEL_ID', 'engine-test');
        define('RUDEL_PATH', $path);

        $this->assertSame('sqlite', Rudel::engine());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEngineDefaultsToMysql(): void
    {
        $path = $this->tmpDir . '/no-engine';
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode(['id' => 'no-engine']));

        define('RUDEL_ID', 'no-engine');
        define('RUDEL_PATH', $path);

        $this->assertSame('mysql', Rudel::engine());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTablePrefixReturnsConstant(): void
    {
        define('RUDEL_TABLE_PREFIX', 'rudel_abc123_');
        $this->assertSame('rudel_abc123_', Rudel::table_prefix());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUrlBuildsFromConstants(): void
    {
        define('RUDEL_ID', 'url-test');
        if (! defined('RUDEL_PATH_PREFIX')) {
            define('RUDEL_PATH_PREFIX', '__rudel');
        }
        define('WP_HOME', 'https://example.com');

        $this->assertSame('https://example.com/__rudel/url-test/', Rudel::url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExitUrlWithWpHome(): void
    {
        define('WP_HOME', 'https://example.com');
        $this->assertSame('https://example.com/?adminExit', Rudel::exit_url());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIsEmailDisabledWhenConstantSet(): void
    {
        define('RUDEL_ID', 'email-test');
        define('RUDEL_DISABLE_EMAIL', true);
        $this->assertTrue(Rudel::is_email_disabled());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLogPathInSandbox(): void
    {
        define('RUDEL_PATH', '/var/sandboxes/log-test');
        define('RUDEL_ID', 'log-test');
        $this->assertSame('/var/sandboxes/log-test/wp-content/debug.log', Rudel::log_path());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testContextReturnsFullStateInSandbox(): void
    {
        define('RUDEL_ID', 'ctx-test');
        define('RUDEL_PATH', '/tmp/ctx-test');
        define('RUDEL_TABLE_PREFIX', 'rudel_abc_');
        define('RUDEL_VERSION', '0.1.0');

        $ctx = Rudel::context();
        $this->assertTrue($ctx['is_sandbox']);
        $this->assertFalse($ctx['is_app']);
        $this->assertSame('ctx-test', $ctx['id']);
        $this->assertNull($ctx['app_id']);
        $this->assertSame('/tmp/ctx-test', $ctx['path']);
        $this->assertSame('rudel_abc_', $ctx['table_prefix']);
        $this->assertSame('0.1.0', $ctx['version']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAppContextIsSeparatedFromSandboxContext(): void
    {
        define('RUDEL_ID', 'client-a');
        define('RUDEL_IS_APP', true);
        define('RUDEL_PATH', '/var/apps/client-a');
        define('WP_HOME', 'https://client-a.com');

        $this->assertTrue(Rudel::is_app());
        $this->assertFalse(Rudel::is_sandbox());
        $this->assertNull(Rudel::id());
        $this->assertSame('client-a', Rudel::app_id());
        $this->assertSame('https://client-a.com/', Rudel::url());

        $ctx = Rudel::context();
        $this->assertTrue($ctx['is_app']);
        $this->assertNull($ctx['id']);
        $this->assertSame('client-a', $ctx['app_id']);
    }

    // Static helpers

    public function testVersionReturnsValue(): void
    {
        // RUDEL_VERSION is defined in rudel.php which may not be loaded in tests.
        $version = Rudel::version();
        if (defined('RUDEL_VERSION')) {
            $this->assertSame(RUDEL_VERSION, $version);
        } else {
            $this->assertNull($version);
        }
    }

    public function testCliCommandReturnsDefault(): void
    {
        $this->assertSame('rudel', Rudel::cli_command());
    }

    public function testPathPrefixReturnsDefault(): void
    {
        $this->assertSame('__rudel', Rudel::path_prefix());
    }

    // Management: list/get

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAllReturnsEmptyWhenNoSandboxes(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        $this->assertSame([], Rudel::all());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetReturnsNullForNonexistent(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        $this->assertNull(Rudel::get('nonexistent'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAndGetSandbox(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $sandbox = Rudel::create('API Test', ['engine' => 'sqlite']);
        $this->assertNotNull($sandbox);
        $this->assertSame('API Test', $sandbox->name);

        $found = Rudel::get($sandbox->id);
        $this->assertNotNull($found);
        $this->assertSame($sandbox->id, $found->id);

        $all = Rudel::all();
        $this->assertCount(1, $all);

        $this->assertTrue(Rudel::destroy($sandbox->id));
        $this->assertNull(Rudel::get($sandbox->id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateAndDestroyApp(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $app = Rudel::create_app('Client A', ['client-a.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]);
        $this->assertSame('app', $app->type);
        $this->assertSame('inline0/client-a-theme', $app->tracked_github_repo);

        $found = Rudel::app($app->id);
        $this->assertNotNull($found);
        $this->assertSame($app->id, $found->id);

        $apps = Rudel::apps();
        $this->assertCount(1, $apps);
        $this->assertSame($this->tmpDir . '/rudel-apps', Rudel::apps_dir());

        $this->assertTrue(Rudel::destroy_app($app->id));
        $this->assertNull(Rudel::app($app->id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateSandboxMetadataViaApi(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $sandbox = Rudel::create('API Update Sandbox', ['engine' => 'sqlite']);
        $updated = Rudel::update($sandbox->id, [
            'owner' => 'dennis',
            'labels' => 'priority, qa',
            'protected' => true,
        ]);

        $this->assertSame('dennis', $updated->owner);
        $this->assertSame(['priority', 'qa'], $updated->labels);
        $this->assertTrue($updated->is_protected());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpdateAppMetadataViaApi(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $app = Rudel::create_app('API Update App', ['api-update.com'], ['engine' => 'sqlite']);
        $updated = Rudel::update_app($app->id, [
            'owner' => 'dennis',
            'labels' => 'customer',
            'tracked_github_repo' => 'inline0/client-a-theme',
        ]);

        $this->assertSame('dennis', $updated->owner);
        $this->assertSame(['customer'], $updated->labels);
        $this->assertSame('inline0/client-a-theme', $updated->tracked_github_repo);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreateSandboxFromAppAndManageBackups(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }
        if (! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }

        $app = Rudel::create_app('Client B', ['client-b.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/client-b-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-b',
        ]);
        $sandbox = Rudel::create_sandbox_from_app($app->id, 'Client B Sandbox');

        $this->assertSame('sandbox', $sandbox->type);
        $this->assertSame($this->tmpDir . '/rudel-environments/' . $sandbox->id, $sandbox->path);
        $this->assertSame('inline0/client-b-theme', $sandbox->clone_source['github_repo'] ?? null);

        $backup = Rudel::backup_app($app->id, 'baseline');
        $this->assertSame('baseline', $backup['name']);
        $this->assertCount(1, Rudel::app_backups($app->id));

        file_put_contents($app->get_wp_content_path() . '/plugins/api-restore.txt', 'changed');
        Rudel::restore_app($app->id, 'baseline');

        $this->assertFileDoesNotExist($app->get_wp_content_path() . '/plugins/api-restore.txt');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeploySandboxToAppReturnsBackupMetadata(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }
        if (! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }

        $app = Rudel::create_app('Deploy API App', ['deploy-api.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/deploy-api-theme',
            'tracked_github_branch' => 'main',
        ]);
        $sandbox = Rudel::create_sandbox_from_app($app->id, 'Deploy API Sandbox');

        $result = Rudel::deploy_sandbox_to_app($app->id, $sandbox->id, 'pre-deploy', [
            'label' => 'Launch candidate',
            'notes' => 'Approved after QA sign-off',
        ]);
        $deployments = Rudel::app_deployments($app->id);

        $this->assertSame($app->id, $result['app_id']);
        $this->assertSame($sandbox->id, $result['sandbox_id']);
        $this->assertSame('pre-deploy', $result['backup']['name']);
        $this->assertSame('Launch candidate', $result['deployment']['label']);
        $this->assertCount(1, $deployments);
        $this->assertSame($result['deployment']['id'], $deployments[0]['id']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPlanRollbackAndHooksAreExposedThroughApi(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }
        if (! defined('WP_HOME')) {
            define('WP_HOME', 'https://host.test');
        }

        $app = Rudel::create_app('API Rollback App', ['api-rollback.com'], [
            'engine' => 'sqlite',
            'tracked_github_repo' => 'inline0/api-theme',
            'tracked_github_branch' => 'main',
        ]);
        file_put_contents($app->get_wp_content_path() . '/plugins/original.txt', 'before');

        $sandbox = Rudel::create_sandbox_from_app($app->id, 'API Rollback Sandbox');
        file_put_contents($sandbox->get_wp_content_path() . '/plugins/deployed.txt', 'after');

        $plan = Rudel::plan_app_deploy($app->id, $sandbox->id, 'preflight', ['label' => 'Preview']);
        $deploy = Rudel::deploy_sandbox_to_app($app->id, $sandbox->id, 'before-deploy');
        $rollback = Rudel::rollback_app_deployment($app->id, $deploy['deployment']['id']);

        $this->assertSame('preflight', $plan['backup_name']);
        $this->assertSame($app->id, $rollback['app_id']);
        $this->assertSame('before-deploy', $rollback['backup_name']);
        $this->assertFileDoesNotExist($app->get_wp_content_path() . '/plugins/deployed.txt');
        $this->assertArrayHasKey('rudel_before_app_deploy', Rudel::hooks());
        $this->assertSame('filter', Rudel::hooks()['rudel_app_deploy_plan']['type']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunAutomationReturnsAppMaintenanceSections(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $sandbox = Rudel::create('Expiring Sandbox', ['engine' => 'sqlite']);
        $sandboxMeta = json_decode(file_get_contents($sandbox->path . '/.rudel.json'), true);
        $sandboxMeta['expires_at'] = gmdate('c', strtotime('+1 day'));
        file_put_contents($sandbox->path . '/.rudel.json', json_encode($sandboxMeta));

        $app = Rudel::create_app('Automation API App', ['automation-api.com'], ['engine' => 'sqlite']);

        $config = new RudelConfig();
        $config->set('auto_cleanup_enabled', 0);
        $config->set('auto_cleanup_merged', 0);
        $config->set('auto_app_backups_enabled', 1);
        $config->set('auto_app_backup_interval_hours', 1);
        $config->set('auto_app_backup_retention_count', 2);
        $config->set('auto_app_deployment_retention_count', 2);
        $config->set('expiring_environment_notice_days', 2);
        $config->save();

        $result = Rudel::run_automation();

        $this->assertArrayHasKey($app->id, $result['app_backups']['created']);
        $this->assertSame(2, $result['expiring_environments']['days']);
        $this->assertContains($sandbox->id, array_column($result['expiring_environments']['expiring'], 'id'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTouchCurrentEnvironmentPersistsLastUsed(): void
    {
        $path = $this->tmpDir . '/touch-api';
        mkdir($path, 0755, true);
        file_put_contents($path . '/.rudel.json', json_encode([
            'id' => 'touch-api',
            'name' => 'Touch API',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'last_used_at' => '2020-01-01T00:00:00+00:00',
        ]));

        define('RUDEL_ID', 'touch-api');
        define('RUDEL_PATH', $path);

        Rudel::touch_current_environment();

        $meta = json_decode(file_get_contents($path . '/.rudel.json'), true);
        $this->assertNotSame('2020-01-01T00:00:00+00:00', $meta['last_used_at']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunScheduledCleanupUsesConfiguredPolicies(): void
    {
        if (! defined('RUDEL_PLUGIN_DIR')) {
            define('RUDEL_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }
        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $this->tmpDir);
        }

        $sandbox = Rudel::create('Scheduled Cleanup', ['engine' => 'sqlite']);
        $meta = json_decode(file_get_contents($sandbox->path . '/.rudel.json'), true);
        $meta['created_at'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($sandbox->path . '/.rudel.json', json_encode($meta));

        $config = new RudelConfig();
        $config->set('auto_cleanup_enabled', 1);
        $config->set('max_age_days', 1);
        $config->save();

        $result = Rudel::run_scheduled_cleanup();

        $this->assertContains($sandbox->id, $result['cleanup']['removed']);
    }
}
