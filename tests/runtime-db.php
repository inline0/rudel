<?php

require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['pdo', 'mysqli'] as $driver) {
    $connection = new Rudel\Connection(
        getenv('RUDEL_TEST_DB_HOST') ?: '127.0.0.1',
        getenv('RUDEL_TEST_DB_NAME') ?: 'rudel_test',
        getenv('RUDEL_TEST_DB_USER') ?: 'root',
        getenv('RUDEL_TEST_DB_PASSWORD') ?: 'root',
        table_prefix: 'runtime_' . $driver . '_',
        driver: $driver,
    );
    $root = sys_get_temp_dir() . '/rudel-runtime-' . bin2hex(random_bytes(6));
    mkdir($root . '/consumer', 0755, true);
    Rudel\Rudel::init($connection, ['environments_dir' => $root]);
    Rudel\Rudel::ensure_schema();
    $store = Rudel\RudelDatabase::current_store();
    check($store instanceof Rudel\DatabaseStore, 'No standalone store.');
    $connection->pdo()->exec('ALTER TABLE `' . $store->table('environments') . '` DROP COLUMN tracked_git_remote');
    $connection->pdo()->exec('ALTER TABLE `' . $store->table('worktrees') . '` DROP COLUMN metadata_name');
    Rudel\RudelSchema::reset();
    Rudel\RudelSchema::ensure($store);
    $repository = new Rudel\EnvironmentRepository($store, $root);
    $environment = new Rudel\Environment(
        id: 'consumer', name: 'Consumer', path: $root . '/consumer', created_at: gmdate('c'), engine: 'overlay',
        tracked_git_remote: 'https://example.test/source.git',
    );
    $repository->save($environment);
    check(Rudel\Rudel::get('consumer')?->tracked_git_remote === 'https://example.test/source.git', 'Schema upgrade lost the tracking field.');
    $artifacts = new Rudel\ArtifactRepository($root . '/snapshots', 'snapshot');
    $artifacts->save('Case', ['name' => 'Case']);
    $artifacts->save('case', ['name' => 'case']);
    check(count($artifacts->all()) === 2, 'Artifact names collided.');
    check($artifacts->get('Case')['name'] === 'Case', 'Artifact round trip failed.');
    foreach (['artifacts', 'worktrees', 'environments'] as $suffix) {
        $connection->pdo()->exec('DROP TABLE `' . $store->table($suffix) . '`');
    }
    rmdir($root . '/consumer');
    rmdir($root);
    Rudel\Rudel::reset();
    echo 'PHP ' . PHP_VERSION . ': standalone ' . $driver . " schema upgrades and metadata passed\n";
}
