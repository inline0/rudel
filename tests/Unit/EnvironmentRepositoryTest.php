<?php

namespace Rudel\Tests\Unit;

use Rudel\DatabaseStore;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Tests\RudelTestCase;

class EnvironmentRepositoryTest extends RudelTestCase
{
    public function testUpdateFieldsMapsTrackedGitAndMetadataToTheRightColumns(): void
    {
        $repository = new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/sandboxes', 'sandbox');

        $path = $this->tmpDir . '/sandboxes/client-fix';
        mkdir($path, 0755, true);

        $saved = $repository->save(
            new Environment(
                id: 'client-fix',
                name: 'Client Fix',
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00'
            )
        );

        $updated = $repository->update_fields(
            $saved->id,
            [
                'name' => 'Client Fix Updated',
                'last_used_at' => '2026-01-02T00:00:00+00:00',
                'tracked_git_remote' => 'https://example.test/client-fix.git',
                'tracked_git_branch' => 'release',
                'tracked_git_dir' => 'themes/client-fix',
            ],
            'sandbox'
        );

        $this->assertSame('Client Fix Updated', $updated->name);
        $this->assertSame('2026-01-02T00:00:00+00:00', $updated->last_used_at);
        $this->assertSame('https://example.test/client-fix.git', $updated->tracked_git_remote);
        $this->assertSame('release', $updated->tracked_git_branch);
        $this->assertSame('themes/client-fix', $updated->tracked_git_dir);
    }

    public function testSaveRollsBackWhenWorktreePersistenceFails(): void
    {
        $store = $this->failingStore('worktrees');
        $repository = new EnvironmentRepository($store, $this->tmpDir . '/sandboxes', 'sandbox');

        $path = $this->tmpDir . '/sandboxes/client-fix';
        mkdir($path, 0755, true);

        $environment = new Environment(
            id: 'client-fix',
            name: 'Client Fix',
            path: $path,
            created_at: '2026-01-01T00:00:00+00:00',
            clone_source: [
                'git_worktrees' => [
                    [
                        'type' => 'themes',
                        'name' => 'client-theme',
                        'metadata_name' => 'rudel-client-fix-themes-client-theme-a1b2c3d4',
                        'branch' => 'rudel/client-fix',
                        'repo' => $path . '/wp-content/themes/client-theme',
                    ],
                ],
            ]
        );

        try {
            $repository->save($environment);
            $this->fail('Expected environment save to fail when worktree persistence throws.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure for worktrees.', $e->getMessage());
        }

        $this->assertNull($repository->get('client-fix'));
        $this->assertSame([], $store->fetch_all('SELECT * FROM ' . $store->table('worktrees')));
    }

    public function testSavePersistsWorktreeMetadataNames(): void
    {
        $repository = new EnvironmentRepository($this->runtimeStore(), $this->tmpDir . '/sandboxes', 'sandbox');

        $path = $this->tmpDir . '/sandboxes/client-fix';
        mkdir($path . '/wp-content/themes/client-theme', 0755, true);

        $saved = $repository->save(
            new Environment(
                id: 'client-fix',
                name: 'Client Fix',
                path: $path,
                created_at: '2026-01-01T00:00:00+00:00',
                clone_source: [
                    'git_worktrees' => [
                        [
                            'type' => 'themes',
                            'name' => 'client-theme',
                            'metadata_name' => 'rudel-client-fix-themes-client-theme-a1b2c3d4',
                            'branch' => 'rudel/client-fix',
                            'repo' => $path . '/wp-content/themes/client-theme',
                        ],
                    ],
                ]
            )
        );

        $worktrees = $saved->clone_source['git_worktrees'] ?? [];

        $this->assertCount(1, $worktrees);
        $this->assertSame('rudel-client-fix-themes-client-theme-a1b2c3d4', $worktrees[0]['metadata_name']);
    }

    private function failingStore(string $failingSuffix): DatabaseStore
    {
        $inner = $this->runtimeStore();

        return new class ($inner, $failingSuffix) implements DatabaseStore {
            public function __construct(
                private DatabaseStore $inner,
                private string $failingSuffix
            ) {
            }

            public function cache_key(): string
            {
                return $this->inner->cache_key();
            }

            public function driver(): string
            {
                return $this->inner->driver();
            }

            public function prefix(): string
            {
                return $this->inner->prefix();
            }

            public function table(string $suffix): string
            {
                return $this->inner->table($suffix);
            }

            public function execute(string $sql, array $params = array()): int
            {
                return $this->inner->execute($sql, $params);
            }

            public function fetch_row(string $sql, array $params = array()): ?array
            {
                return $this->inner->fetch_row($sql, $params);
            }

            public function fetch_all(string $sql, array $params = array()): array
            {
                return $this->inner->fetch_all($sql, $params);
            }

            public function fetch_var(string $sql, array $params = array())
            {
                return $this->inner->fetch_var($sql, $params);
            }

            public function insert(string $table, array $data): int
            {
                if ($table === $this->inner->table($this->failingSuffix)) {
                    throw new \RuntimeException(sprintf('Simulated failure for %s.', $this->failingSuffix));
                }

                return $this->inner->insert($table, $data);
            }

            public function update(string $table, array $data, array $where): int
            {
                return $this->inner->update($table, $data, $where);
            }

            public function delete(string $table, array $where): int
            {
                return $this->inner->delete($table, $where);
            }

            public function begin(): void
            {
                $this->inner->begin();
            }

            public function commit(): void
            {
                $this->inner->commit();
            }

            public function rollback(): void
            {
                $this->inner->rollback();
            }
        };
    }
}
