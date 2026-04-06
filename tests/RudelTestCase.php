<?php

namespace Rudel\Tests;

use PHPUnit\Framework\TestCase;
use Rudel\AppRepository;
use Rudel\DatabaseStore;
use Rudel\Environment;
use Rudel\EnvironmentRepository;
use Rudel\Rudel;
use Rudel\RudelDatabase;
use Rudel\RudelSchema;
use Rudel\WpdbStore;

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
	        Rudel::reset();
	        $GLOBALS['rudel_test_actions'] = [];
        $GLOBALS['rudel_test_filters'] = [];
        $GLOBALS['rudel_test_action_callbacks'] = [];
        $GLOBALS['rudel_test_filter_callbacks'] = [];
        $GLOBALS['rudel_test_multisite'] = true;
        $GLOBALS['rudel_test_subdomain_install'] = true;
	        $GLOBALS['rudel_test_next_blog_id'] = 2;
	        $GLOBALS['rudel_test_current_blog_id'] = 1;
	        $GLOBALS['rudel_test_blog_stack'] = [];
	        $GLOBALS['rudel_test_current_user_id'] = 0;
	        $GLOBALS['rudel_test_super_admins'] = [];
	        $GLOBALS['rudel_test_users'] = [];
        $GLOBALS['rudel_test_last_created_blog_admin_user_id'] = null;
        $GLOBALS['rudel_test_sites'] = [
            1 => [
                'blog_id' => 1,
                'domain' => 'example.test',
                'path' => '/',
                'siteurl' => 'http://example.test/',
                'home' => 'http://example.test/',
                'title' => 'Host Site',
            ],
        ];
	        $GLOBALS['wpdb'] = new \MockWpdb();
	        $GLOBALS['wpdb']->prefix = 'wp_';
	        $GLOBALS['wpdb']->base_prefix = 'wp_';
	        $GLOBALS['wpdb']->blogid = 1;
	        $GLOBALS['blog_id'] = 1;
	        $GLOBALS['current_blog'] = (object) $GLOBALS['rudel_test_sites'][1];
	        $GLOBALS['table_prefix'] = 'wp_';
        $GLOBALS['wpdb']->addTable(
            'wp_options',
            'CREATE TABLE `wp_options` (
                `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `option_name` varchar(191) NOT NULL,
                `option_value` longtext NOT NULL,
                `autoload` varchar(20) NOT NULL DEFAULT \'yes\',
                PRIMARY KEY (`option_id`)
            )',
            []
        );
        RudelDatabase::reset();
        $this->tmpDir = RUDEL_TEST_TMPDIR . '/' . uniqid('test-');
        mkdir($this->tmpDir, 0755, true);
        RudelSchema::ensure($this->runtimeStore());
    }

	    protected function tearDown(): void
	    {
	        if (is_dir($this->tmpDir)) {
	            exec('rm -rf ' . escapeshellarg($this->tmpDir));
	        }
	        Rudel::reset();
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
                'engine' => 'subsite',
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
            engine: (string) ($meta['engine'] ?? 'subsite'),
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
        return new WpdbStore($GLOBALS['wpdb']);
    }

    /**
     * Build an environment repository for the current test workspace.
     */
    protected function environmentRepository(?string $managedType = 'sandbox'): EnvironmentRepository
    {
        return new EnvironmentRepository($this->runtimeStore(), $this->tmpDir, $managedType);
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

    protected function setTestMultisite(bool $enabled, bool $subdomainInstall = true): void
    {
        $GLOBALS['rudel_test_multisite'] = $enabled;
        $GLOBALS['rudel_test_subdomain_install'] = $subdomainInstall;
    }

    protected function siteOptionValue(int $blogId, string $optionName)
    {
        $table = $GLOBALS['wpdb']->base_prefix . $blogId . '_options';
        foreach ($GLOBALS['wpdb']->getTableRows($table) as $row) {
            if (($row['option_name'] ?? null) === $optionName) {
                return $row['option_value'] ?? null;
            }
        }

        return null;
    }
}
