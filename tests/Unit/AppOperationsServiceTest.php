<?php

namespace Rudel\Tests\Unit;

use Rudel\AppOperationsService;
use Rudel\Environment;
use Rudel\EnvironmentManager;
use Rudel\EnvironmentRepository;
use Rudel\EnvironmentStateReplacer;
use Rudel\Tests\RudelTestCase;

class AppOperationsServiceTest extends RudelTestCase
{
    public function testDeployRestoresTheAppBackupWhenMetadataPersistenceFails(): void
    {
        global $wpdb;

        $appPath = $this->createFakeSandbox('restore-app', 'Restore App', [
            'type' => 'app',
            'domains' => ['restore-app.example.test'],
            'blog_id' => 2,
            'multisite' => true,
        ]);
        $sandboxPath = $this->createFakeSandbox('restore-sandbox', 'Restore Sandbox', [
            'blog_id' => 3,
            'multisite' => true,
        ]);

        file_put_contents($appPath . '/wp-content/state.txt', 'app state');
        file_put_contents($sandboxPath . '/wp-content/state.txt', 'sandbox state');

        $wpdb->addTable(
            'wp_2_options',
            'CREATE TABLE `wp_2_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Restore App'],
                ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'http://restore-app.example.test'],
                ['option_id' => 3, 'option_name' => 'home', 'option_value' => 'http://restore-app.example.test'],
            ]
        );
        $wpdb->addTable(
            'wp_3_options',
            'CREATE TABLE `wp_3_options` (`option_id` bigint(20), `option_name` varchar(191), `option_value` longtext)',
            [
                ['option_id' => 1, 'option_name' => 'blogname', 'option_value' => 'Restore Sandbox'],
                ['option_id' => 2, 'option_name' => 'siteurl', 'option_value' => 'http://restore-sandbox.example.test'],
                ['option_id' => 3, 'option_name' => 'home', 'option_value' => 'http://restore-sandbox.example.test'],
            ]
        );

        $app = Environment::from_path($appPath);
        $sandbox = Environment::from_path($sandboxPath);

        $this->assertInstanceOf(Environment::class, $app);
        $this->assertInstanceOf(Environment::class, $sandbox);

        $appRepository = new EnvironmentRepository($this->runtimeStore(), $this->tmpDir, 'app');
        $sandboxRepository = new EnvironmentRepository($this->runtimeStore(), $this->tmpDir, 'sandbox');

        $appManager = new class ($appRepository) extends EnvironmentManager {
            public int $updateCalls = 0;

            public function __construct(private EnvironmentRepository $repository)
            {
            }

            public function get(string $id): ?Environment
            {
                return $this->repository->resolve($id);
            }

            public function update(string $id, array $changes): Environment
            {
                $this->updateCalls++;
                if (1 === $this->updateCalls) {
                    throw new \RuntimeException('Metadata write failed');
                }

                return $this->repository->update_fields($id, $changes, 'app');
            }

            public function replace_environment_state(Environment $source, Environment $target): array
            {
                return (new EnvironmentStateReplacer())->replace($source, $target);
            }
        };

        $sandboxManager = new class ($sandboxRepository) extends EnvironmentManager {
            public function __construct(private EnvironmentRepository $repository)
            {
            }

            public function get(string $id): ?Environment
            {
                return $this->repository->resolve($id);
            }
        };

        $service = new AppOperationsService($appManager, $sandboxManager);

        try {
            $service->deploy($app, $sandbox, 'before-deploy');
            $this->fail('Expected deploy to fail after the environment state had already been replaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Metadata write failed', $e->getMessage());
        }

        $this->assertSame('app state', file_get_contents($appPath . '/wp-content/state.txt'));
        $this->assertSame('Restore App', $this->siteOptionValue(2, 'blogname'));
        $this->assertSame('http://restore-app.example.test', $this->siteOptionValue(2, 'siteurl'));
        $this->assertSame([], (new \Rudel\AppDeploymentLog($app))->list());
    }
}
