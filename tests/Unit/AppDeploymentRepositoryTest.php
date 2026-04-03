<?php

namespace Rudel\Tests\Unit;

use Rudel\AppDeploymentRepository;
use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class AppDeploymentRepositoryTest extends RudelTestCase
{
    private function appEnvironmentRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/apps', 'app');
    }

    private function sandboxRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/sandboxes', 'sandbox');
    }

    private function createApp(string $id, array $domains): Environment
    {
        $path = $this->tmpDir . '/apps/' . $id;
        mkdir($path, 0755, true);

        $environment = $this->appEnvironmentRepository()->save(
            new Environment(
                id: $id,
                name: ucfirst($id),
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00',
                type: 'app',
                tracked_github_repo: 'inline0/' . $id,
                tracked_github_branch: 'main',
                tracked_github_dir: 'themes/' . $id
            )
        );

        return (new AppRepository($this->runtimeStore(), $this->appEnvironmentRepository()))->create($environment, $domains);
    }

    private function createSandbox(string $id, ?int $appId = null): Environment
    {
        $path = $this->tmpDir . '/sandboxes/' . $id;
        mkdir($path, 0755, true);

        return $this->sandboxRepository()->save(
            new Environment(
                id: $id,
                name: ucfirst($id),
                path: $path,
                created_at: '2026-01-02T00:00:00+00:00',
                app_record_id: $appId,
                clone_source: [
                    'github_repo' => 'inline0/client-a',
                    'github_base_branch' => 'release/2026',
                    'github_dir' => 'themes/client-a',
                ]
            )
        );
    }

    public function testRecordAndFindPersistDeploymentHistory(): void
    {
        $app = $this->createApp('client-a', ['client-a.com']);
        $sandbox = $this->createSandbox('client-a-fix', (int) $app->app_record_id);

        $repository = new AppDeploymentRepository($this->runtimeStore());
        $record = $repository->record($app, $sandbox, [
            'deployed_at' => '2026-02-01T00:00:00+00:00',
            'backup_name' => 'before-deploy',
            'label' => 'Release candidate',
            'notes' => 'QA approved',
        ]);

        $found = $repository->find((int) $app->app_record_id, $record['id']);

        $this->assertNotNull($found);
        $this->assertSame('client-a', $found['app_id']);
        $this->assertSame('client-a-fix', $found['sandbox_id']);
        $this->assertSame(['client-a.com'], $found['app_domains']);
        $this->assertSame('release/2026', $found['github_base_branch']);
    }

    public function testListSortsNewestFirstAndPruneRemovesOlderDeployments(): void
    {
        $app = $this->createApp('client-b', ['client-b.com']);
        $sandbox = $this->createSandbox('client-b-fix', (int) $app->app_record_id);

        $repository = new AppDeploymentRepository($this->runtimeStore());
        $first = $repository->record($app, $sandbox, ['deployed_at' => '2026-02-01T00:00:00+00:00']);
        $second = $repository->record($app, $sandbox, ['deployed_at' => '2026-02-02T00:00:00+00:00']);
        $third = $repository->record($app, $sandbox, ['deployed_at' => '2026-02-03T00:00:00+00:00']);

        $listed = $repository->list((int) $app->app_record_id);

        $this->assertSame([$third['id'], $second['id'], $first['id']], array_column($listed, 'id'));
        $this->assertSame([$first['id'], $second['id']], $repository->prune((int) $app->app_record_id, 1));
        $this->assertSame([$third['id']], array_column($repository->list((int) $app->app_record_id), 'id'));
    }
}
