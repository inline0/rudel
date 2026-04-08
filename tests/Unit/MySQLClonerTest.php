<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\MySQLCloner;
use Rudel\Tests\RudelTestCase;

require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';

class MySQLClonerTest extends RudelTestCase
{
    private MySQLCloner $cloner;
    private \MockWpdb $mockWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloner = new MySQLCloner();
        $this->mockWpdb = new \MockWpdb();
        $this->mockWpdb->prefix = 'wp_';
    }

    private function setGlobalWpdb(): void
    {
        $GLOBALS['wpdb'] = $this->mockWpdb;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    // discover_tables()

    public function testDiscoverTablesFindsMatchingPrefix(): void
    {
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);
        $this->mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', []);
        $this->mockWpdb->addTable('other_table', 'CREATE TABLE other_table (id int)', []);

        $tables = $this->cloner->discover_tables($this->mockWpdb, 'wp_');

        $this->assertCount(2, $tables);
        $this->assertContains('wp_posts', $tables);
        $this->assertContains('wp_options', $tables);
        $this->assertNotContains('other_table', $tables);
    }

    public function testDiscoverTablesReturnsEmptyForNoMatch(): void
    {
        $this->mockWpdb->addTable('other_posts', 'CREATE TABLE other_posts (ID int)', []);

        $tables = $this->cloner->discover_tables($this->mockWpdb, 'wp_');

        $this->assertEmpty($tables);
    }

    public function testDiscoverTablesHandlesLongPrefix(): void
    {
        $this->mockWpdb->addTable('wp_abc123_posts', 'CREATE TABLE wp_abc123_posts (ID int)', []);
        $this->mockWpdb->addTable('wp_abc123_options', 'CREATE TABLE wp_abc123_options (option_id int)', []);
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);

        $tables = $this->cloner->discover_tables($this->mockWpdb, 'wp_abc123_');

        $this->assertCount(2, $tables);
        $this->assertContains('wp_abc123_posts', $tables);
        $this->assertContains('wp_abc123_options', $tables);
    }

    // drop_tables()

    public function testDropTablesRemovesAllWithPrefix(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('rudel_abc_posts', 'CREATE TABLE rudel_abc_posts (ID int)', []);
        $this->mockWpdb->addTable('rudel_abc_options', 'CREATE TABLE rudel_abc_options (option_id int)', []);
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);

        $count = $this->cloner->drop_tables('rudel_abc_');

        $this->assertSame(2, $count);
        $this->assertFalse($this->mockWpdb->hasTable('rudel_abc_posts'));
        $this->assertFalse($this->mockWpdb->hasTable('rudel_abc_options'));
        $this->assertTrue($this->mockWpdb->hasTable('wp_posts'));
    }

    public function testDropTablesReturnsZeroForNoMatch(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);

        $count = $this->cloner->drop_tables('rudel_nonexistent_');

        $this->assertSame(0, $count);
        $this->assertTrue($this->mockWpdb->hasTable('wp_posts'));
    }

    public function testDropTablesCanExcludeNestedSnapshotPrefixes(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('rudel_abc_posts', 'CREATE TABLE rudel_abc_posts (ID int)', []);
        $this->mockWpdb->addTable('rudel_abc_options', 'CREATE TABLE rudel_abc_options (option_id int)', []);
        $this->mockWpdb->addTable('rudel_abc_snap_deadbeef_posts', 'CREATE TABLE rudel_abc_snap_deadbeef_posts (ID int)', []);
        $this->mockWpdb->addTable('rudel_abc_snap_deadbeef_options', 'CREATE TABLE rudel_abc_snap_deadbeef_options (option_id int)', []);

        $count = $this->cloner->drop_tables('rudel_abc_', ['rudel_abc_snap_deadbeef_']);

        $this->assertSame(2, $count);
        $this->assertFalse($this->mockWpdb->hasTable('rudel_abc_posts'));
        $this->assertFalse($this->mockWpdb->hasTable('rudel_abc_options'));
        $this->assertTrue($this->mockWpdb->hasTable('rudel_abc_snap_deadbeef_posts'));
        $this->assertTrue($this->mockWpdb->hasTable('rudel_abc_snap_deadbeef_options'));
    }

    public function testDropTablesRefusesNonRudelPrefix(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to drop tables');
        $this->cloner->drop_tables('wp_');
    }

    public function testDropTablesRefusesCustomPrefix(): void
    {
        $this->setGlobalWpdb();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only Rudel-managed prefixes are allowed');
        $this->cloner->drop_tables('custom_prefix_');
    }

    // copy_tables()

    public function testCopyTablesDuplicatesWithNewPrefix(): void
    {
        $this->setGlobalWpdb();
        $rows = [
            ['ID' => '1', 'post_title' => 'Hello'],
            ['ID' => '2', 'post_title' => 'World'],
        ];
        $this->mockWpdb->addTable('wp_abc_posts', 'CREATE TABLE wp_abc_posts (ID int)', $rows);
        $this->mockWpdb->addTable('wp_abc_options', 'CREATE TABLE wp_abc_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);

        $count = $this->cloner->copy_tables('wp_abc_', 'wp_def_');

        $this->assertSame(2, $count);
        $this->assertTrue($this->mockWpdb->hasTable('wp_def_posts'));
        $this->assertTrue($this->mockWpdb->hasTable('wp_def_options'));
        $this->assertSame($rows, $this->mockWpdb->getTableRows('wp_def_posts'));
        // Originals still exist.
        $this->assertTrue($this->mockWpdb->hasTable('wp_abc_posts'));
    }

    public function testCopyTablesReturnsZeroForNoMatch(): void
    {
        $this->setGlobalWpdb();
        $count = $this->cloner->copy_tables('wp_nonexistent_', 'wp_new_');
        $this->assertSame(0, $count);
    }

    public function testCopyTablesCanExcludeNestedSnapshotPrefixes(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('rudel_abc_posts', 'CREATE TABLE rudel_abc_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Current'],
        ]);
        $this->mockWpdb->addTable('rudel_abc_snap_deadbeef_posts', 'CREATE TABLE rudel_abc_snap_deadbeef_posts (ID int)', [
            ['ID' => '99', 'post_title' => 'Old Snapshot'],
        ]);

        $count = $this->cloner->copy_tables('rudel_abc_', 'rudel_abc_snap_feedbeef_', ['rudel_abc_snap_']);

        $this->assertSame(1, $count);
        $this->assertTrue($this->mockWpdb->hasTable('rudel_abc_snap_feedbeef_posts'));
        $this->assertFalse($this->mockWpdb->hasTable('rudel_abc_snap_feedbeef_snap_deadbeef_posts'));
        $this->assertSame(
            [['ID' => '1', 'post_title' => 'Current']],
            $this->mockWpdb->getTableRows('rudel_abc_snap_feedbeef_posts')
        );
    }

    public function testCopyTablesCanReplaceExistingTargets(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_src_posts', 'CREATE TABLE wp_src_posts (ID int)', [
            ['ID' => '1', 'post_title' => 'Source'],
        ]);
        $this->mockWpdb->addTable('wp_dst_posts', 'CREATE TABLE wp_dst_posts (ID int)', [
            ['ID' => '99', 'post_title' => 'Stale'],
        ]);

        $count = $this->cloner->copy_tables('wp_src_', 'wp_dst_', [], true);

        $this->assertSame(1, $count);
        $this->assertSame(
            [['ID' => '1', 'post_title' => 'Source']],
            $this->mockWpdb->getTableRows('wp_dst_posts')
        );
    }

    // search_replace_value()

    public function testSearchReplaceValueReplacesPlainUrl(): void
    {
        $result = $this->cloner->search_replace_value(
            'http://example.com/wp-content/uploads/image.jpg',
            'http://example.com',
            'http://test-1234.example.com'
        );
        $this->assertSame('http://test-1234.example.com/wp-content/uploads/image.jpg', $result);
    }

    public function testSearchReplaceValueHandlesMultipleOccurrences(): void
    {
        $result = $this->cloner->search_replace_value(
            'Visit http://example.com and http://example.com/about',
            'http://example.com',
            'http://sandbox.local'
        );
        $this->assertSame('Visit http://sandbox.local and http://sandbox.local/about', $result);
    }

    public function testSearchReplaceValueHandlesSerializedString(): void
    {
        $data = serialize('http://example.com/path');
        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/path', $unserialized);
    }

    public function testSearchReplaceValueHandlesSerializedArray(): void
    {
        $data = serialize(['url' => 'http://example.com', 'name' => 'Test']);
        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local', $unserialized['url']);
        $this->assertSame('Test', $unserialized['name']);
    }

    public function testSearchReplaceValueHandlesNestedSerializedArray(): void
    {
        $data = serialize([
            'level1' => [
                'url' => 'http://example.com/deep',
                'child' => [
                    'another_url' => 'http://example.com/deeper',
                ],
            ],
        ]);
        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/deep', $unserialized['level1']['url']);
        $this->assertSame('http://sandbox.local/deeper', $unserialized['level1']['child']['another_url']);
    }

    public function testSearchReplaceValueHandlesSerializedObject(): void
    {
        $obj = new \stdClass();
        $obj->url = 'http://example.com/page';
        $obj->title = 'No URL here';
        $data = serialize($obj);

        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/page', $unserialized->url);
        $this->assertSame('No URL here', $unserialized->title);
    }

    public function testSearchReplaceValuePreservesSerializedStringLengths(): void
    {
        $data = serialize(['url' => 'http://example.com']);
        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');

        // The result should be valid serialized data.
        $unserialized = unserialize($result);
        $this->assertIsArray($unserialized);
        $this->assertSame('http://sandbox.local', $unserialized['url']);
    }

    public function testSearchReplaceValueHandlesSerializedFalse(): void
    {
        $data = serialize(false);

        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');

        $this->assertSame($data, $result);
        $this->assertFalse(unserialize($result));
    }

    public function testSearchReplaceValueLeavesNonMatchingDataUnchanged(): void
    {
        $data = serialize(['key' => 'no urls here']);
        $result = $this->cloner->search_replace_value($data, 'http://example.com', 'http://sandbox.local');
        $this->assertSame($data, $result);
    }

    public function testSearchReplaceValueHandlesPlainStringWithNoMatch(): void
    {
        $result = $this->cloner->search_replace_value('nothing to replace', 'http://example.com', 'http://sandbox.local');
        $this->assertSame('nothing to replace', $result);
    }

    // rewrite_urls()

    public function testRewriteUrlsUpdatesGuidColumn(): void
    {
        $this->mockWpdb->addTable('wp_test_posts', 'CREATE TABLE wp_test_posts (ID int)', [
            ['ID' => '1', 'guid' => 'http://example.com/?p=1', 'post_content' => 'no url'],
        ]);

        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_test_', 'http://example.com', 'http://sandbox.local');

        $rows = $this->mockWpdb->getTableRows('wp_test_posts');
        $this->assertStringContainsString('http://sandbox.local', $rows[0]['guid']);
    }

    public function testRewriteUrlsSkipsWhenUrlsIdentical(): void
    {
        $this->mockWpdb->addTable('wp_test_posts', 'CREATE TABLE wp_test_posts (ID int)', [
            ['ID' => '1', 'guid' => 'http://example.com/?p=1'],
        ]);

        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_test_', 'http://example.com', 'http://example.com');

        $rows = $this->mockWpdb->getTableRows('wp_test_posts');
        $this->assertSame('http://example.com/?p=1', $rows[0]['guid']);
    }

    public function testRewriteUrlsTrimsTrailingSlashes(): void
    {
        $this->mockWpdb->addTable('wp_test_posts', 'CREATE TABLE wp_test_posts (ID int)', [
            ['ID' => '1', 'guid' => 'http://example.com/?p=1'],
        ]);

        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_test_', 'http://example.com/', 'http://sandbox.local/');

        $rows = $this->mockWpdb->getTableRows('wp_test_posts');
        $this->assertStringContainsString('http://sandbox.local', $rows[0]['guid']);
    }

    public function testRewriteUrlsSkipsNonExistentTable(): void
    {
        // Should not throw even if tables don't exist.
        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_missing_', 'http://example.com', 'http://sandbox.local');
        $this->assertTrue(true);
    }

    public function testRewriteUrlsHandlesOptionsWithSerializedData(): void
    {
        $serialized = serialize(['url' => 'http://example.com/path']);
        $this->mockWpdb->addTable('wp_test_options', 'CREATE TABLE wp_test_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'widget_data', 'option_value' => $serialized],
        ]);

        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_test_', 'http://example.com', 'http://sandbox.local');

        $rows = $this->mockWpdb->getTableRows('wp_test_options');
        $unserialized = unserialize($rows[0]['option_value']);
        $this->assertSame('http://sandbox.local/path', $unserialized['url']);
    }

    public function testRewriteUrlsForcesCanonicalSiteOptions(): void
    {
        $this->mockWpdb->addTable('wp_test_options', 'CREATE TABLE wp_test_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://example.com'],
        ]);

        $this->cloner->rewrite_urls($this->mockWpdb, 'wp_test_', 'http://example.com', 'http://sandbox.local');

        $rows = $this->mockWpdb->getTableRows('wp_test_options');
        $this->assertSame('http://sandbox.local', $rows[0]['option_value']);
        $this->assertSame('http://sandbox.local', $rows[1]['option_value']);
    }

    // rewrite_table_prefix_in_data()

    public function testRewriteTablePrefixInUsermetaMetaKey(): void
    {
        $this->mockWpdb->addTable('wp_new_usermeta', 'CREATE TABLE wp_new_usermeta (umeta_id int)', [
            ['umeta_id' => '1', 'meta_key' => 'wp_old_capabilities', 'meta_value' => 'a:1:{s:13:"administrator";b:1;}'],
            ['umeta_id' => '2', 'meta_key' => 'wp_old_user_level', 'meta_value' => '10'],
            ['umeta_id' => '3', 'meta_key' => 'nickname', 'meta_value' => 'admin'],
        ]);

        $this->cloner->rewrite_table_prefix_in_data($this->mockWpdb, 'wp_new_', 'wp_old_', 'wp_new_');

        $rows = $this->mockWpdb->getTableRows('wp_new_usermeta');
        $this->assertSame('wp_new_capabilities', $rows[0]['meta_key']);
        $this->assertSame('wp_new_user_level', $rows[1]['meta_key']);
        $this->assertSame('nickname', $rows[2]['meta_key']);
    }

    public function testRewriteTablePrefixInOptionsOptionName(): void
    {
        $this->mockWpdb->addTable('wp_new_options', 'CREATE TABLE wp_new_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'wp_old_user_roles', 'option_value' => 'roles_data'],
            ['option_id' => '2', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);

        $this->cloner->rewrite_table_prefix_in_data($this->mockWpdb, 'wp_new_', 'wp_old_', 'wp_new_');

        $rows = $this->mockWpdb->getTableRows('wp_new_options');
        $this->assertSame('wp_new_user_roles', $rows[0]['option_name']);
        $this->assertSame('siteurl', $rows[1]['option_name']);
    }

    public function testRewriteTablePrefixSkipsWhenPrefixesIdentical(): void
    {
        $this->mockWpdb->addTable('wp_same_usermeta', 'CREATE TABLE wp_same_usermeta (umeta_id int)', [
            ['umeta_id' => '1', 'meta_key' => 'wp_same_capabilities'],
        ]);

        $this->cloner->rewrite_table_prefix_in_data($this->mockWpdb, 'wp_same_', 'wp_same_', 'wp_same_');

        $rows = $this->mockWpdb->getTableRows('wp_same_usermeta');
        $this->assertSame('wp_same_capabilities', $rows[0]['meta_key']);
    }

    // clone_database()

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneDatabaseClonesAllTablesAndRewritesUrls(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'guid' => 'http://example.com/?p=1', 'post_content' => 'Hello world'],
        ]);
        $this->mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://example.com'],
        ]);
        $this->mockWpdb->addTable('wp_usermeta', 'CREATE TABLE wp_usermeta (umeta_id int)', [
            ['umeta_id' => '1', 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['administrator' => true])],
        ]);

        if (! defined('WP_HOME')) {
            define('WP_HOME', 'http://example.com');
        }

        $result = $this->cloner->clone_database('rudel_sbx_', 'http://test-1234.example.com');

        $this->assertSame(3, $result['tables_cloned']);
        $this->assertGreaterThan(0, $result['rows_cloned']);
        $this->assertFalse($result['is_multisite']);

        // Target tables exist.
        $this->assertTrue($this->mockWpdb->hasTable('rudel_sbx_posts'));
        $this->assertTrue($this->mockWpdb->hasTable('rudel_sbx_options'));
        $this->assertTrue($this->mockWpdb->hasTable('rudel_sbx_usermeta'));

        // Source tables still exist.
        $this->assertTrue($this->mockWpdb->hasTable('wp_posts'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneDatabaseDetectsMultisite(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', []);
        $this->mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', []);
        $this->mockWpdb->addTable('wp_blogs', 'CREATE TABLE wp_blogs (blog_id int)', [
            ['blog_id' => '1', 'path' => '/'],
        ]);

        if (! defined('WP_HOME')) {
            define('WP_HOME', 'http://example.com');
        }

        $result = $this->cloner->clone_database('rudel_sbx_', 'http://test-1234.example.com');

        $this->assertTrue($result['is_multisite']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneDatabaseThrowsWhenNoTablesFound(): void
    {
        $this->setGlobalWpdb();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No tables found');
        $this->cloner->clone_database('rudel_sbx_', 'http://test.example.com');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneDatabaseThrowsWithoutWpdb(): void
    {
        unset($GLOBALS['wpdb']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('$wpdb is not available');
        $this->cloner->clone_database('rudel_sbx_', 'http://test.example.com');
    }

    // Integration: clone then drop

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneThenDropCleansUp(): void
    {
        $this->setGlobalWpdb();
        $this->mockWpdb->addTable('wp_posts', 'CREATE TABLE wp_posts (ID int)', [
            ['ID' => '1', 'guid' => 'http://example.com/?p=1', 'post_content' => 'test'],
        ]);
        $this->mockWpdb->addTable('wp_options', 'CREATE TABLE wp_options (option_id int)', [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://example.com'],
        ]);

        if (! defined('WP_HOME')) {
            define('WP_HOME', 'http://example.com');
        }

        $this->cloner->clone_database('rudel_sbx_', 'http://sandbox.local');

        $this->assertTrue($this->mockWpdb->hasTable('rudel_sbx_posts'));
        $this->assertTrue($this->mockWpdb->hasTable('rudel_sbx_options'));

        $dropped = $this->cloner->drop_tables('rudel_sbx_');
        $this->assertSame(2, $dropped);
        $this->assertFalse($this->mockWpdb->hasTable('rudel_sbx_posts'));
        $this->assertFalse($this->mockWpdb->hasTable('rudel_sbx_options'));

        // Originals untouched.
        $this->assertTrue($this->mockWpdb->hasTable('wp_posts'));
    }

    // Integration: copy then verify isolation

    public function testCopyTablesPreservesOriginalData(): void
    {
        $this->setGlobalWpdb();
        $originalRows = [
            ['ID' => '1', 'post_title' => 'Original'],
        ];
        $this->mockWpdb->addTable('wp_src_posts', 'CREATE TABLE wp_src_posts (ID int)', $originalRows);

        $this->cloner->copy_tables('wp_src_', 'wp_dst_');

        // Both have same data.
        $this->assertSame($originalRows, $this->mockWpdb->getTableRows('wp_src_posts'));
        $this->assertSame($originalRows, $this->mockWpdb->getTableRows('wp_dst_posts'));
    }
}
