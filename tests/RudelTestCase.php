<?php

namespace Rudel\Tests;

use PHPUnit\Framework\TestCase;
use Rudel\AppRepository;
use Rudel\DatabaseStore;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\RudelDatabase;

/**
 * Base test case for Rudel tests.
 *
 * Provides temp directory management and helper utilities.
 */
abstract class RudelTestCase extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rudel_test_actions'] = [];
        $GLOBALS['rudel_test_filters'] = [];
        $GLOBALS['rudel_test_action_callbacks'] = [];
        $GLOBALS['rudel_test_filter_callbacks'] = [];
        RudelDatabase::reset();
        $this->tmpDir = RUDEL_TEST_TMPDIR . '/' . uniqid('test-');
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
        parent::tearDown();
    }

    /**
     * Create a fake environment directory and persist its runtime record in the test DB.
     */
    protected function createFakeSandbox(string $id, string $name = 'test', array $extraMeta = []): string
    {
        $path = $this->tmpDir . '/' . $id;
        mkdir($path, 0755, true);
        mkdir($path . '/wp-content', 0755, true);
        mkdir($path . '/wp-content/plugins', 0755);
        mkdir($path . '/wp-content/themes', 0755);
        mkdir($path . '/wp-content/uploads', 0755);
        mkdir($path . '/wp-content/mu-plugins', 0755);
        mkdir($path . '/tmp', 0755);

        $meta = array_merge(
            [
                'created_at' => '2026-01-01T00:00:00+00:00',
                'template' => 'blank',
                'status' => 'active',
                'engine' => 'mysql',
                'type' => 'sandbox',
            ],
            $extraMeta
        );

        $environment = new Environment(
            id: $id,
            name: $name,
            path: $path,
            created_at: (string) $meta['created_at'],
            template: (string) ($meta['template'] ?? 'blank'),
            status: (string) ($meta['status'] ?? 'active'),
            clone_source: isset($meta['clone_source']) && is_array($meta['clone_source']) ? $meta['clone_source'] : null,
            multisite: ! empty($meta['multisite']),
            engine: (string) ($meta['engine'] ?? 'mysql'),
            blog_id: isset($meta['blog_id']) ? (int) $meta['blog_id'] : null,
            type: (string) ($meta['type'] ?? 'sandbox'),
            domains: isset($meta['domains']) && is_array($meta['domains']) ? array_values($meta['domains']) : null,
            owner: isset($meta['owner']) && is_scalar($meta['owner']) ? (string) $meta['owner'] : null,
            labels: isset($meta['labels']) && is_array($meta['labels']) ? array_values($meta['labels']) : [],
            purpose: isset($meta['purpose']) && is_scalar($meta['purpose']) ? (string) $meta['purpose'] : null,
            is_protected: ! empty($meta['protected']) || ! empty($meta['is_protected']),
            expires_at: isset($meta['expires_at']) && is_scalar($meta['expires_at']) ? (string) $meta['expires_at'] : null,
            last_used_at: isset($meta['last_used_at']) && is_scalar($meta['last_used_at']) ? (string) $meta['last_used_at'] : (string) $meta['created_at'],
            source_environment_id: isset($meta['source_environment_id']) && is_scalar($meta['source_environment_id']) ? (string) $meta['source_environment_id'] : null,
            source_environment_type: isset($meta['source_environment_type']) && is_scalar($meta['source_environment_type']) ? (string) $meta['source_environment_type'] : null,
            last_deployed_from_id: isset($meta['last_deployed_from_id']) && is_scalar($meta['last_deployed_from_id']) ? (string) $meta['last_deployed_from_id'] : null,
            last_deployed_from_type: isset($meta['last_deployed_from_type']) && is_scalar($meta['last_deployed_from_type']) ? (string) $meta['last_deployed_from_type'] : null,
            last_deployed_at: isset($meta['last_deployed_at']) && is_scalar($meta['last_deployed_at']) ? (string) $meta['last_deployed_at'] : null,
            tracked_github_repo: isset($meta['tracked_github_repo']) && is_scalar($meta['tracked_github_repo']) ? (string) $meta['tracked_github_repo'] : null,
            tracked_github_branch: isset($meta['tracked_github_branch']) && is_scalar($meta['tracked_github_branch']) ? (string) $meta['tracked_github_branch'] : null,
            tracked_github_dir: isset($meta['tracked_github_dir']) && is_scalar($meta['tracked_github_dir']) ? (string) $meta['tracked_github_dir'] : null,
        );

        $repository = $this->environmentRepository((string) ($meta['type'] ?? 'sandbox'));
        $saved = $repository->save($environment);

        if ('app' === $saved->type) {
            $apps = new AppRepository($this->runtimeStore(), $this->environmentRepository('app'));
            $apps->create($saved, isset($meta['domains']) && is_array($meta['domains']) ? $meta['domains'] : []);
        }

        return $path;
    }

    /**
     * Return the shared runtime store for the current test workspace.
     */
    protected function runtimeStore(): DatabaseStore
    {
        return RudelDatabase::for_paths($this->tmpDir);
    }

    /**
     * Build an environment repository for the current test workspace.
     */
    protected function environmentRepository(?string $managedType = 'sandbox'): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir, null, $managedType);
    }

    /**
     * Create a minimal wp-config.php file.
     */
    protected function createWpConfig(string $dir, ?string $content = null): string
    {
        $path = $dir . '/wp-config.php';
        $content ??= "<?php\n// Test wp-config.php\ndefine('DB_NAME', 'test');\nrequire_once __DIR__ . '/wp-settings.php';\n";
        file_put_contents($path, $content);
        return $path;
    }

    /**
     * Write a file with known content for size testing.
     */
    protected function createFileWithSize(string $path, int $bytes): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, str_repeat('x', $bytes));
    }
}
