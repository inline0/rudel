<?php

namespace Rudel\Tests\Unit;

use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class EnvironmentRepositoryTest extends RudelTestCase
{
    private function sandboxRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/sandboxes', 'sandbox');
    }

    private function appRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/apps', 'app');
    }

    public function testSaveRoundTripsEnvironmentThroughDbState(): void
    {
        $path = $this->tmpDir . '/sandboxes/feature-box';
        mkdir($path, 0755, true);

        $saved = $this->sandboxRepository()->save(
            new Environment(
                id: 'feature-box',
                name: 'Feature Box',
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00',
                engine: 'sqlite',
                clone_source: [
                    'github_repo' => 'inline0/client-a-theme',
                    'git_worktrees' => [[
                        'type' => 'theme',
                        'name' => 'client-a',
                        'branch' => 'feature-box',
                        'repo' => '/repos/client-a',
                    ]],
                ],
                owner: 'dennis',
                labels: ['qa'],
                purpose: 'Regression check',
            )
        );

        $loaded = $this->sandboxRepository()->get('feature-box');

        $this->assertNotNull($saved->record_id);
        $this->assertNotNull($loaded);
        $this->assertSame($saved->record_id, $loaded->record_id);
        $this->assertSame('dennis', $loaded->owner);
        $this->assertSame(['qa'], $loaded->labels);
        $this->assertSame('inline0/client-a-theme', $loaded->clone_source['github_repo']);
        $this->assertSame('/repos/client-a', $loaded->clone_source['git_worktrees'][0]['repo']);
        $this->assertSame($path, $this->sandboxRepository()->get_by_path($path)?->path);
    }

    public function testUpdateFieldsPersistsStateAndDeleteRemovesRecord(): void
    {
        $path = $this->tmpDir . '/sandboxes/update-box';
        mkdir($path, 0755, true);

        $this->sandboxRepository()->save(
            new Environment(
                id: 'update-box',
                name: 'Update Box',
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00',
                clone_source: [
                    'git_worktrees' => [[
                        'type' => 'plugin',
                        'name' => 'worker',
                        'branch' => 'main',
                        'repo' => '/repos/worker',
                    ]],
                ],
            )
        );

        $updated = $this->sandboxRepository()->update_fields('update-box', [
            'protected' => true,
            'labels' => ['urgent', 'review'],
            'tracked_github_repo' => 'inline0/worker',
            'clone_source' => [
                'github_repo' => 'inline0/worker',
                'git_worktrees' => [[
                    'type' => 'plugin',
                    'name' => 'worker',
                    'branch' => 'release/2026',
                    'repo' => '/repos/worker',
                ]],
            ],
        ]);

        $this->assertTrue($updated->is_protected());
        $this->assertSame(['urgent', 'review'], $updated->labels);
        $this->assertSame('inline0/worker', $updated->tracked_github_repo);
        $this->assertSame('release/2026', $updated->clone_source['git_worktrees'][0]['branch']);

        $this->assertTrue($this->sandboxRepository()->delete('update-box'));
        $this->assertNull($this->sandboxRepository()->get('update-box'));
    }

    public function testListByAppIdReturnsChildSandboxesOnly(): void
    {
        $appPath = $this->tmpDir . '/apps/client-a';
        mkdir($appPath, 0755, true);

        $appEnvironment = $this->appRepository()->save(
            new Environment(
                id: 'client-a',
                name: 'Client A',
                path: $appPath,
                created_at: '2026-01-01T00:00:00+00:00',
                type: 'app',
                domains: ['client-a.com'],
            )
        );

        $apps = new AppRepository($this->runtimeStore(), $this->appRepository());
        $registeredApp = $apps->create($appEnvironment, ['client-a.com']);

        $sandboxPath = $this->tmpDir . '/sandboxes/client-a-fix';
        mkdir($sandboxPath, 0755, true);
        $otherPath = $this->tmpDir . '/sandboxes/unrelated';
        mkdir($otherPath, 0755, true);

        $this->sandboxRepository()->save(
            new Environment(
                id: 'client-a-fix',
                name: 'Client A Fix',
                path: $sandboxPath,
                created_at: '2026-01-02T00:00:00+00:00',
                app_record_id: $registeredApp->app_record_id,
            )
        );
        $this->sandboxRepository()->save(
            new Environment(
                id: 'unrelated',
                name: 'Unrelated',
                path: $otherPath,
                created_at: '2026-01-03T00:00:00+00:00',
            )
        );

        $children = $this->sandboxRepository()->list_by_app_id((int) $registeredApp->app_record_id);

        $this->assertCount(1, $children);
        $this->assertSame('client-a-fix', $children[0]->id);
    }
}
