<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\DatabaseCloner;
use Rudel\Tests\RudelTestCase;

/**
 * Stress tests for DatabaseCloner.
 *
 * Tests large row counts, many tables, large blobs, and wide tables.
 */
class DatabaseClonerStressTest extends RudelTestCase
{
    private static bool $translatorAvailable;

    public static function setUpBeforeClass(): void
    {
        $base = dirname(__DIR__, 2) . '/lib/sqlite-database-integration/wp-includes/sqlite';
        self::$translatorAvailable = file_exists($base . '/class-wp-sqlite-translator.php');
    }

    private function skipIfNoTranslator(): void
    {
        if (! self::$translatorAvailable) {
            $this->markTestSkipped('SQLite translator not available (lib/ not downloaded).');
        }
    }

    private function loadDependencies(): void
    {
        require_once dirname(__DIR__) . '/Stubs/MockWpdb.php';

        $base = dirname(__DIR__, 2) . '/lib/sqlite-database-integration/wp-includes/sqlite';
        require_once $base . '/class-wp-sqlite-pdo-user-defined-functions.php';
        require_once $base . '/class-wp-sqlite-lexer.php';
        require_once $base . '/class-wp-sqlite-query-rewriter.php';
        require_once $base . '/class-wp-sqlite-token.php';
        require_once $base . '/class-wp-sqlite-translator.php';
    }

    private function createTranslator(string $dbPath): \WP_SQLite_Translator
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return new \WP_SQLite_Translator($pdo);
    }

    private function openDb(string $dbPath): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    // Stress: 2000 rows to exercise chunking (default chunk_size=500)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClone2000RowsWithChunking(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $rows = [];
        for ($i = 1; $i <= 2000; $i++) {
            $rows[] = [
                'meta_id' => (string) $i,
                'post_id' => (string) (($i % 100) + 1),
                'meta_key' => '_meta_key_' . $i,
                'meta_value' => 'Value for meta item ' . $i . ' with some padding to make it realistic.',
            ];
        }

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $rows);

        $dbPath = $this->tmpDir . '/stress-chunked.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_postmeta', 'wp_', 'wp_sb_');
        $cloned = $cloner->clone_table_data($wpdb, $translator, 'wp_postmeta', 'wp_sb_postmeta', 'wp_', 'wp_sb_', 500);

        $this->assertEquals(2000, $cloned);

        $pdo = $this->openDb($dbPath);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM wp_sb_postmeta")->fetchColumn();
        $this->assertEquals(2000, $count);

        // Verify first, middle, and last rows
        $first = $pdo->query("SELECT meta_key FROM wp_sb_postmeta WHERE meta_id=1")->fetchColumn();
        $this->assertEquals('_meta_key_1', $first);

        $middle = $pdo->query("SELECT meta_key FROM wp_sb_postmeta WHERE meta_id=1000")->fetchColumn();
        $this->assertEquals('_meta_key_1000', $middle);

        $last = $pdo->query("SELECT meta_key FROM wp_sb_postmeta WHERE meta_id=2000")->fetchColumn();
        $this->assertEquals('_meta_key_2000', $last);
    }

    // Stress: small chunk size (50) to force many iterations

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneWithSmallChunkSize(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $rows = [];
        for ($i = 1; $i <= 327; $i++) {
            $rows[] = [
                'meta_id' => (string) $i,
                'post_id' => '1',
                'meta_key' => 'key_' . $i,
                'meta_value' => 'val_' . $i,
            ];
        }

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $rows);

        $dbPath = $this->tmpDir . '/stress-small-chunk.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_postmeta', 'wp_', 'wp_sb_');
        // 327 rows / 50 per chunk = 7 full chunks + 1 partial (27 rows)
        $cloned = $cloner->clone_table_data($wpdb, $translator, 'wp_postmeta', 'wp_sb_postmeta', 'wp_', 'wp_sb_', 50);

        $this->assertEquals(327, $cloned);

        $pdo = $this->openDb($dbPath);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM wp_sb_postmeta")->fetchColumn();
        $this->assertEquals(327, $count);
    }

    // Stress: exactly chunk_size rows (boundary condition)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneExactlyChunkSizeRows(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $rows = [];
        for ($i = 1; $i <= 500; $i++) {
            $rows[] = [
                'meta_id' => (string) $i,
                'post_id' => '1',
                'meta_key' => 'key_' . $i,
                'meta_value' => 'val_' . $i,
            ];
        }

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $rows);

        $dbPath = $this->tmpDir . '/stress-exact-chunk.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_postmeta', 'wp_', 'wp_sb_');
        $cloned = $cloner->clone_table_data($wpdb, $translator, 'wp_postmeta', 'wp_sb_postmeta', 'wp_', 'wp_sb_', 500);

        $this->assertEquals(500, $cloned);
    }

    // Stress: 15 tables (simulating a site with many plugins)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testClone15Tables(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $tableNames = [
            'wp_posts', 'wp_postmeta', 'wp_options', 'wp_users', 'wp_usermeta',
            'wp_terms', 'wp_termmeta', 'wp_term_taxonomy', 'wp_term_relationships',
            'wp_comments', 'wp_commentmeta', 'wp_links',
            'wp_wc_orders', 'wp_wc_order_items', 'wp_actionscheduler_actions',
        ];

        foreach ($tableNames as $name) {
            $shortName = str_replace('wp_', '', $name);
            $wpdb->addTable($name, "CREATE TABLE `{$name}` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `data` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
                ['id' => '1', 'data' => "Data for {$shortName} row 1", 'created_at' => '2026-01-01 00:00:00'],
                ['id' => '2', 'data' => "Data for {$shortName} row 2", 'created_at' => '2026-02-01 00:00:00'],
            ]);
        }

        $dbPath = $this->tmpDir . '/stress-many-tables.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $totalRows = 0;
        $tables = $cloner->discover_tables($wpdb, 'wp_');
        $this->assertCount(15, $tables);

        foreach ($tables as $table) {
            $target = $cloner->rename_prefix($table, 'wp_', 'wp_sb_');
            $cloner->clone_table_structure($wpdb, $translator, $table, 'wp_', 'wp_sb_');
            $totalRows += $cloner->clone_table_data($wpdb, $translator, $table, $target, 'wp_', 'wp_sb_');
        }

        $this->assertEquals(30, $totalRows);

        $pdo = $this->openDb($dbPath);
        $sqliteTables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != '_mysql_data_types_cache' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(15, $sqliteTables);
        $this->assertContains('wp_sb_posts', $sqliteTables);
        $this->assertContains('wp_sb_wc_orders', $sqliteTables);
        $this->assertContains('wp_sb_actionscheduler_actions', $sqliteTables);
    }

    // Stress: large serialized blobs (50KB+ values)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneLargeSerializedBlobs(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        // Build a large serialized array (simulating complex widget/plugin config)
        $largeArray = [];
        for ($i = 0; $i < 200; $i++) {
            $largeArray["widget_{$i}"] = [
                'title' => "Widget Title {$i}",
                'content' => str_repeat("Content block {$i}. ", 50),
                'settings' => [
                    'url' => "http://host.local/widget/{$i}",
                    'enabled' => ($i % 2 === 0),
                    'priority' => $i * 10,
                ],
            ];
        }
        $largeSerialized = serialize($largeArray);
        // Should be well over 50KB
        $this->assertGreaterThan(50000, strlen($largeSerialized));

        // Another large blob: CSS/HTML content
        $largeHtml = '<div class="content">' . str_repeat('<p>Paragraph with http://host.local/page links and content. </p>', 500) . '</div>';

        $wpdb->addTable('wp_options', "CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://host.local', 'autoload' => 'yes'],
            ['option_id' => '2', 'option_name' => 'large_widget_config', 'option_value' => $largeSerialized, 'autoload' => 'yes'],
            ['option_id' => '3', 'option_name' => 'custom_css', 'option_value' => $largeHtml, 'autoload' => 'no'],
        ]);

        $dbPath = $this->tmpDir . '/stress-large-blobs.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_options', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_options', 'wp_sb_options', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $cloner->rewrite_urls($pdo, 'wp_sb_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        // Verify large serialized blob survived and was URL-rewritten
        $value = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='large_widget_config'")->fetchColumn();
        $this->assertGreaterThan(50000, strlen($value));
        $data = unserialize($value);
        $this->assertIsArray($data);
        $this->assertCount(200, $data);
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/test/widget/0', $data['widget_0']['settings']['url']);
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/test/widget/199', $data['widget_199']['settings']['url']);
        $this->assertTrue($data['widget_0']['settings']['enabled']);
        $this->assertFalse($data['widget_1']['settings']['enabled']);

        // Verify large HTML was URL-rewritten
        $html = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='custom_css'")->fetchColumn();
        $this->assertStringContainsString('http://host.local/' . RUDEL_PATH_PREFIX . '/test/page', $html);
        $this->assertStringNotContainsString('http://host.local/page', $html);
        $this->assertGreaterThan(35000, strlen($html));
    }

    // Stress: wide table with 30 columns

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneWideTableWith30Columns(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        // Build a CREATE TABLE with 30 columns (various types)
        $cols = ["`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT"];
        for ($i = 1; $i <= 10; $i++) {
            $cols[] = "`varchar_col_{$i}` varchar(255) NOT NULL DEFAULT ''";
        }
        for ($i = 1; $i <= 5; $i++) {
            $cols[] = "`int_col_{$i}` int(11) NOT NULL DEFAULT '0'";
        }
        for ($i = 1; $i <= 5; $i++) {
            $cols[] = "`text_col_{$i}` text NOT NULL";
        }
        for ($i = 1; $i <= 5; $i++) {
            $cols[] = "`decimal_col_{$i}` decimal(10,2) NOT NULL DEFAULT '0.00'";
        }
        for ($i = 1; $i <= 3; $i++) {
            $cols[] = "`datetime_col_{$i}` datetime NOT NULL DEFAULT '0000-00-00 00:00:00'";
        }
        $cols[] = "`bool_col` tinyint(1) NOT NULL DEFAULT '0'";
        $cols[] = "PRIMARY KEY (`id`)";

        $ddl = "CREATE TABLE `wp_wide_table` (\n  " . implode(",\n  ", $cols) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // Build row data
        $rows = [];
        for ($r = 1; $r <= 100; $r++) {
            $row = ['id' => (string) $r];
            for ($i = 1; $i <= 10; $i++) {
                $row["varchar_col_{$i}"] = "varchar_{$r}_{$i}";
            }
            for ($i = 1; $i <= 5; $i++) {
                $row["int_col_{$i}"] = (string) ($r * $i);
            }
            for ($i = 1; $i <= 5; $i++) {
                $row["text_col_{$i}"] = "Text content for row {$r} column {$i}. " . str_repeat('x', 100);
            }
            for ($i = 1; $i <= 5; $i++) {
                $row["decimal_col_{$i}"] = number_format($r * $i * 1.23, 2, '.', '');
            }
            for ($i = 1; $i <= 3; $i++) {
                $row["datetime_col_{$i}"] = "2026-0{$i}-15 12:00:00";
            }
            $row['bool_col'] = ($r % 2 === 0) ? '1' : '0';
            $rows[] = $row;
        }

        $wpdb->addTable('wp_wide_table', $ddl, $rows);

        $dbPath = $this->tmpDir . '/stress-wide.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_wide_table', 'wp_', 'wp_sb_');
        $cloned = $cloner->clone_table_data($wpdb, $translator, 'wp_wide_table', 'wp_sb_wide_table', 'wp_', 'wp_sb_');

        $this->assertEquals(100, $cloned);

        $pdo = $this->openDb($dbPath);
        $count = (int) $pdo->query("SELECT COUNT(*) FROM wp_sb_wide_table")->fetchColumn();
        $this->assertEquals(100, $count);

        // Verify various column types in first row
        $row = $pdo->query("SELECT * FROM wp_sb_wide_table WHERE id=1")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('varchar_1_1', $row['varchar_col_1']);
        $this->assertEquals('varchar_1_10', $row['varchar_col_10']);
        $this->assertEquals('1', $row['int_col_1']);
        $this->assertEquals('5', $row['int_col_5']);
        $this->assertEquals('1.23', $row['decimal_col_1']);
        $this->assertEquals('0', $row['bool_col']);

        // Verify last row
        $lastRow = $pdo->query("SELECT * FROM wp_sb_wide_table WHERE id=100")->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals('varchar_100_5', $lastRow['varchar_col_5']);
        $this->assertEquals('500', $lastRow['int_col_5']);
        $this->assertEquals('1', $lastRow['bool_col']);
    }

    // Stress: URL rewriting across 1000 rows

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRewriteUrlsAcross1000Rows(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = [
                'meta_id' => (string) $i,
                'post_id' => (string) (($i % 50) + 1),
                'meta_key' => '_url_meta_' . $i,
                'meta_value' => "http://host.local/resource/{$i}",
            ];
        }

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $rows);

        $dbPath = $this->tmpDir . '/stress-url-rewrite.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_postmeta', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_postmeta', 'wp_sb_postmeta', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $cloner->rewrite_urls($pdo, 'wp_sb_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/sandbox');

        // All 1000 rows should be rewritten
        $unrewritten = $pdo->query("SELECT COUNT(*) FROM wp_sb_postmeta WHERE meta_value LIKE '%http://host.local/resource%' AND meta_value NOT LIKE '%__rudel%'")->fetchColumn();
        $this->assertEquals('0', $unrewritten);

        // Spot check some rows
        $val1 = $pdo->query("SELECT meta_value FROM wp_sb_postmeta WHERE meta_id=1")->fetchColumn();
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/sandbox/resource/1', $val1);

        $val500 = $pdo->query("SELECT meta_value FROM wp_sb_postmeta WHERE meta_id=500")->fetchColumn();
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/sandbox/resource/500', $val500);

        $val1000 = $pdo->query("SELECT meta_value FROM wp_sb_postmeta WHERE meta_id=1000")->fetchColumn();
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/sandbox/resource/1000', $val1000);
    }

    // Stress: deeply nested serialized data (5 levels deep)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneDeeplyNestedSerializedData(): void
    {
        $cloner = new DatabaseCloner();

        // Build 5-level nested structure with URLs at every level
        $data = [
            'url' => 'http://host.local/level0',
            'level1' => [
                'url' => 'http://host.local/level1',
                'level2' => [
                    'url' => 'http://host.local/level2',
                    'items' => [
                        ['url' => 'http://host.local/level3/a', 'nested' => ['deep_url' => 'http://host.local/level4/x']],
                        ['url' => 'http://host.local/level3/b', 'nested' => ['deep_url' => 'http://host.local/level4/y']],
                    ],
                ],
            ],
            'flat' => 'no url here',
            'number' => 42,
            'bool' => true,
        ];

        $serialized = serialize($data);
        $result = $cloner->search_replace_value($serialized, 'http://host.local', 'http://sandbox.test');

        $output = unserialize($result);
        $this->assertEquals('http://sandbox.test/level0', $output['url']);
        $this->assertEquals('http://sandbox.test/level1', $output['level1']['url']);
        $this->assertEquals('http://sandbox.test/level2', $output['level1']['level2']['url']);
        $this->assertEquals('http://sandbox.test/level3/a', $output['level1']['level2']['items'][0]['url']);
        $this->assertEquals('http://sandbox.test/level4/x', $output['level1']['level2']['items'][0]['nested']['deep_url']);
        $this->assertEquals('http://sandbox.test/level3/b', $output['level1']['level2']['items'][1]['url']);
        $this->assertEquals('http://sandbox.test/level4/y', $output['level1']['level2']['items'][1]['nested']['deep_url']);
        $this->assertEquals('no url here', $output['flat']);
        $this->assertEquals(42, $output['number']);
        $this->assertTrue($output['bool']);
    }

    // Stress: mixed serialized and non-serialized URLs in same table

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRewriteMixedSerializedAndPlainUrlsInOptions(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $rows = [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://host.local', 'autoload' => 'yes'],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://host.local', 'autoload' => 'yes'],
            ['option_id' => '3', 'option_name' => 'blogname', 'option_value' => 'No URLs Here', 'autoload' => 'yes'],
            ['option_id' => '4', 'option_name' => 'serialized_with_urls', 'option_value' => serialize(['a' => 'http://host.local/a', 'b' => 'http://host.local/b']), 'autoload' => 'yes'],
            ['option_id' => '5', 'option_name' => 'serialized_without_urls', 'option_value' => serialize(['x' => 1, 'y' => 2]), 'autoload' => 'yes'],
            ['option_id' => '6', 'option_name' => 'plain_url', 'option_value' => 'Go to http://host.local/page for more', 'autoload' => 'no'],
            ['option_id' => '7', 'option_name' => 'empty_value', 'option_value' => '', 'autoload' => 'yes'],
        ];

        $wpdb->addTable('wp_options', "CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", $rows);

        $dbPath = $this->tmpDir . '/stress-mixed.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_options', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_options', 'wp_sb_options', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $cloner->rewrite_urls($pdo, 'wp_sb_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        // Plain URL options rewritten
        $siteurl = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/test', $siteurl);

        // Non-URL option untouched
        $blogname = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='blogname'")->fetchColumn();
        $this->assertEquals('No URLs Here', $blogname);

        // Serialized with URLs: rewritten properly
        $serWithUrls = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='serialized_with_urls'")->fetchColumn();
        $data = unserialize($serWithUrls);
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/test/a', $data['a']);
        $this->assertEquals('http://host.local/' . RUDEL_PATH_PREFIX . '/test/b', $data['b']);

        // Serialized without URLs: untouched
        $serNoUrls = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='serialized_without_urls'")->fetchColumn();
        $data2 = unserialize($serNoUrls);
        $this->assertEquals(1, $data2['x']);
        $this->assertEquals(2, $data2['y']);

        // Plain text with URL: rewritten
        $plainUrl = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='plain_url'")->fetchColumn();
        $this->assertEquals('Go to http://host.local/' . RUDEL_PATH_PREFIX . '/test/page for more', $plainUrl);

        // Empty value: untouched
        $empty = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='empty_value'")->fetchColumn();
        $this->assertEquals('', $empty);
    }
}
