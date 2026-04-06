<?php

namespace Rudel\Tests\Unit;

use Rudel\AppRepository;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class AppRepositoryTest extends RudelTestCase
{
    private function appEnvironmentRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/apps', 'app');
    }

    private function sandboxRepository(): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/sandboxes', 'sandbox');
    }

    private function apps(): AppRepository
    {
        return new AppRepository($this->runtimeStore(), $this->appEnvironmentRepository());
    }

    public function testCreateNormalizesDomainsAndResolvesBySlugAndDomain(): void
    {
        $path = $this->tmpDir . '/apps/client-a';
        mkdir($path, 0755, true);

        $environment = $this->appEnvironmentRepository()->save(
            new Environment(
                id: 'client-a',
                name: 'Client A',
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00',
                type: 'app'
            )
        );

        $app = $this->apps()->create($environment, ['Client-A.com', 'www.client-a.com', 'client-a.com']);

        $this->assertNotNull($app->app_record_id);
        $this->assertSame(['client-a.com', 'www.client-a.com'], $app->domains);
        $this->assertSame($app->id, $this->apps()->get('client-a')?->id);
        $this->assertSame($app->id, $this->apps()->get_by_domain('CLIENT-A.COM')?->id);
    }

    public function testDeleteDetachesChildSandboxes(): void
    {
        $appPath = $this->tmpDir . '/apps/client-b';
        mkdir($appPath, 0755, true);

        $appEnvironment = $this->appEnvironmentRepository()->save(
            new Environment(
                id: 'client-b',
                name: 'Client B',
                path: $appPath,
                created_at: '2026-01-01T00:00:00+00:00',
                type: 'app'
            )
        );

        $app = $this->apps()->create($appEnvironment, ['client-b.com']);

        $sandboxPath = $this->tmpDir . '/sandboxes/client-b-fix';
        mkdir($sandboxPath, 0755, true);
        $sandbox = $this->sandboxRepository()->save(
            new Environment(
                id: 'client-b-fix',
                name: 'Client B Fix',
                path: $sandboxPath,
                created_at: '2026-01-02T00:00:00+00:00',
                app_record_id: $app->app_record_id
            )
        );

        $this->assertTrue($this->apps()->delete($app->id));

        $detached = $this->sandboxRepository()->get($sandbox->id);
        $this->assertNotNull($detached);
        $this->assertNull($detached->app_record_id);
        $this->assertNull($this->apps()->get($app->id));
    }

    public function testCreateRejectsDuplicateDomainsBeforeMutatingRows(): void
    {
        $firstPath = $this->tmpDir . '/apps/client-c';
        $secondPath = $this->tmpDir . '/apps/client-d';
        mkdir($firstPath, 0755, true);
        mkdir($secondPath, 0755, true);

        $first = $this->appEnvironmentRepository()->save(
            new Environment(
                id: 'client-c',
                name: 'Client C',
                path: $firstPath,
                created_at: '2026-01-01T00:00:00+00:00',
                type: 'app'
            )
        );
        $second = $this->appEnvironmentRepository()->save(
            new Environment(
                id: 'client-d',
                name: 'Client D',
                path: $secondPath,
                created_at: '2026-01-02T00:00:00+00:00',
                type: 'app'
            )
        );

        $this->apps()->create($first, ['shared.example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('shared.example.com');
        $this->apps()->create($second, ['shared.example.com']);
    }
}
