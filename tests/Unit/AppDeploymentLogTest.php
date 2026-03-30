<?php

namespace Rudel\Tests\Unit;

use Rudel\AppDeploymentLog;
use Rudel\Environment;
use Rudel\Tests\RudelTestCase;

class AppDeploymentLogTest extends RudelTestCase
{
    public function testRecordPersistsDeploymentMetadataNewestFirst(): void
    {
        $app = Environment::from_path($this->createFakeSandbox('client-a-app', 'Client A App', [
            'type' => 'app',
            'domains' => ['client-a.com'],
            'tracked_github_repo' => 'inline0/client-a-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/client-a',
        ]));
        $sandbox = Environment::from_path($this->createFakeSandbox('client-a-sandbox', 'Client A Sandbox', [
            'clone_source' => [
                'github_repo' => 'inline0/client-a-theme',
                'github_base_branch' => 'release/2026',
                'github_dir' => 'themes/client-a',
            ],
        ]));

        $log = new AppDeploymentLog($app);

        $first = $log->record($sandbox, [
            'deployed_at' => '2026-01-01T00:00:00+00:00',
            'label' => '  ',
            'notes' => '',
        ]);
        $second = $log->record($sandbox, [
            'deployed_at' => '2026-01-02T00:00:00+00:00',
            'backup_name' => 'before-launch',
            'label' => 'Launch candidate',
            'notes' => 'Approved after QA sign-off',
        ]);

        $records = $log->list();

        $this->assertCount(2, $records);
        $this->assertSame($second['id'], $records[0]['id']);
        $this->assertSame($first['id'], $records[1]['id']);
        $this->assertNull($records[1]['label']);
        $this->assertNull($records[1]['notes']);
        $this->assertSame('before-launch', $records[0]['backup_name']);
        $this->assertSame('release/2026', $records[0]['github_base_branch']);
        $this->assertFileExists($app->path . '/deployments/' . $first['id'] . '.json');
        $this->assertFileExists($app->path . '/deployments/' . $second['id'] . '.json');
    }

    public function testRecordFallsBackToAppGitMetadataWhenSandboxHasNoGitContext(): void
    {
        $app = Environment::from_path($this->createFakeSandbox('fallback-app', 'Fallback App', [
            'type' => 'app',
            'domains' => ['fallback-app.com'],
            'tracked_github_repo' => 'inline0/fallback-theme',
            'tracked_github_branch' => 'main',
            'tracked_github_dir' => 'themes/fallback-theme',
        ]));
        $sandbox = Environment::from_path($this->createFakeSandbox('fallback-sandbox', 'Fallback Sandbox'));

        $record = (new AppDeploymentLog($app))->record($sandbox, [
            'deployed_at' => '2026-01-03T00:00:00+00:00',
        ]);

        $this->assertSame('inline0/fallback-theme', $record['github_repo']);
        $this->assertSame('main', $record['github_base_branch']);
        $this->assertSame('themes/fallback-theme', $record['github_dir']);
    }
}
