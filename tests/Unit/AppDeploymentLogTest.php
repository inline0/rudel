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
            'tracked_git_remote' => 'https://example.test/client-a-theme.git',
            'tracked_git_branch' => 'main',
            'tracked_git_dir' => 'themes/client-a',
        ]));
        $sandbox = Environment::from_path($this->createFakeSandbox('client-a-sandbox', 'Client A Sandbox', [
            'clone_source' => [
                'git_remote' => 'https://example.test/client-a-theme.git',
                'git_base_branch' => 'release/2026',
                'git_dir' => 'themes/client-a',
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
        $this->assertSame('release/2026', $records[0]['git_base_branch']);
        $this->assertSame($first['id'], $log->find($first['id'])['id']);
        $this->assertSame($second['id'], $log->find($second['id'])['id']);
    }

    public function testRecordFallsBackToAppGitMetadataWhenSandboxHasNoGitContext(): void
    {
        $app = Environment::from_path($this->createFakeSandbox('fallback-app', 'Fallback App', [
            'type' => 'app',
            'domains' => ['fallback-app.com'],
            'tracked_git_remote' => 'https://example.test/fallback-theme.git',
            'tracked_git_branch' => 'main',
            'tracked_git_dir' => 'themes/fallback-theme',
        ]));
        $sandbox = Environment::from_path($this->createFakeSandbox('fallback-sandbox', 'Fallback Sandbox'));

        $record = (new AppDeploymentLog($app))->record($sandbox, [
            'deployed_at' => '2026-01-03T00:00:00+00:00',
        ]);

        $this->assertSame('https://example.test/fallback-theme.git', $record['git_remote']);
        $this->assertSame('main', $record['git_base_branch']);
        $this->assertSame('themes/fallback-theme', $record['git_dir']);
    }

    public function testFindDeleteAndPruneManageDeploymentHistory(): void
    {
        $app = Environment::from_path($this->createFakeSandbox('history-app', 'History App', [
            'type' => 'app',
            'domains' => ['history-app.com'],
        ]));
        $sandbox = Environment::from_path($this->createFakeSandbox('history-sandbox', 'History Sandbox'));

        $log = new AppDeploymentLog($app);

        $first = $log->record($sandbox, ['deployed_at' => '2026-01-01T00:00:00+00:00']);
        $second = $log->record($sandbox, ['deployed_at' => '2026-01-02T00:00:00+00:00']);
        $third = $log->record($sandbox, ['deployed_at' => '2026-01-03T00:00:00+00:00']);

        $this->assertSame($second['id'], $log->find($second['id'])['id']);
        $this->assertTrue($log->delete($first['id']));
        $this->assertNull($log->find($first['id']));

        $removed = $log->prune(1);
        $this->assertSame([$second['id']], $removed);
        $this->assertCount(1, $log->list());
        $this->assertSame($third['id'], $log->list()[0]['id']);
    }
}
