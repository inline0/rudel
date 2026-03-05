<?php

namespace Rudel\Tests\Unit;

use Rudel\DatabaseCloner;
use Rudel\Tests\RudelTestCase;

class DatabaseClonerTest extends RudelTestCase
{
    private DatabaseCloner $cloner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloner = new DatabaseCloner();
    }

    // rename_prefix()

    public function testRenamePrefixReplacesMatchingPrefix(): void
    {
        $result = $this->cloner->rename_prefix('wp_posts', 'wp_', 'wp_abc123_');
        $this->assertSame('wp_abc123_posts', $result);
    }

    public function testRenamePrefixLeavesNonMatchingTableUnchanged(): void
    {
        $result = $this->cloner->rename_prefix('other_posts', 'wp_', 'wp_abc123_');
        $this->assertSame('other_posts', $result);
    }

    public function testRenamePrefixHandlesEmptySourcePrefix(): void
    {
        $result = $this->cloner->rename_prefix('posts', '', 'wp_abc123_');
        $this->assertSame('wp_abc123_posts', $result);
    }

    public function testRenamePrefixHandlesIdenticalPrefixes(): void
    {
        $result = $this->cloner->rename_prefix('wp_posts', 'wp_', 'wp_');
        $this->assertSame('wp_posts', $result);
    }

    public function testRenamePrefixHandlesLongerSourcePrefix(): void
    {
        $result = $this->cloner->rename_prefix('wp_custom_posts', 'wp_custom_', 'wp_x_');
        $this->assertSame('wp_x_posts', $result);
    }

    // rename_prefix_in_ddl()

    public function testRenamePrefixInDdlReplacesTableName(): void
    {
        $ddl = 'CREATE TABLE `wp_posts` ( `ID` bigint(20) NOT NULL )';
        $result = $this->cloner->rename_prefix_in_ddl($ddl, 'wp_posts', 'wp_', 'wp_abc_');
        $this->assertSame('CREATE TABLE `wp_abc_posts` ( `ID` bigint(20) NOT NULL )', $result);
    }

    public function testRenamePrefixInDdlPreservesOtherContent(): void
    {
        $ddl = "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) NOT NULL,\n  `option_name` varchar(191) DEFAULT ''\n)";
        $result = $this->cloner->rename_prefix_in_ddl($ddl, 'wp_options', 'wp_', 'wp_test_');
        $this->assertStringContainsString('`wp_test_options`', $result);
        $this->assertStringContainsString('`option_id`', $result);
        $this->assertStringContainsString('`option_name`', $result);
    }

    // search_replace_value() -- plain strings

    public function testSearchReplaceValueReplacesPlainUrl(): void
    {
        $value = 'http://example.com/wp-content/uploads/image.jpg';
        $result = $this->cloner->search_replace_value($value, 'http://example.com', 'http://example.com/' . RUDEL_PATH_PREFIX . '/test-1234');
        $this->assertSame('http://example.com/' . RUDEL_PATH_PREFIX . '/test-1234/wp-content/uploads/image.jpg', $result);
    }

    public function testSearchReplaceValueHandlesMultipleOccurrences(): void
    {
        $value = 'Visit http://example.com and http://example.com/about';
        $result = $this->cloner->search_replace_value($value, 'http://example.com', 'http://sandbox.local');
        $this->assertSame('Visit http://sandbox.local and http://sandbox.local/about', $result);
    }

    public function testSearchReplaceValueReturnsUnchangedWhenNoMatch(): void
    {
        $value = 'Nothing to replace here';
        $result = $this->cloner->search_replace_value($value, 'http://example.com', 'http://sandbox.local');
        $this->assertSame('Nothing to replace here', $result);
    }

    // search_replace_value() -- serialized data

    public function testSearchReplaceValueHandlesSerializedString(): void
    {
        $original = serialize('http://example.com/path');
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/path', $unserialized);
    }

    public function testSearchReplaceValueHandlesSerializedArray(): void
    {
        $original = serialize([
            'url' => 'http://example.com',
            'path' => '/uploads',
        ]);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local', $unserialized['url']);
        $this->assertSame('/uploads', $unserialized['path']);
    }

    public function testSearchReplaceValueHandlesNestedSerializedArray(): void
    {
        $original = serialize([
            'level1' => [
                'url' => 'http://example.com/deep',
            ],
        ]);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/deep', $unserialized['level1']['url']);
    }

    public function testSearchReplaceValuePreservesSerializedStringLengths(): void
    {
        $data = ['key' => 'http://example.com/some/path'];
        $original = serialize($data);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');

        // The result should be valid serialized data.
        $unserialized = unserialize($result);
        $this->assertIsArray($unserialized);
        $this->assertSame('http://sandbox.local/some/path', $unserialized['key']);
    }

    public function testSearchReplaceValueHandlesSerializedObject(): void
    {
        $obj = new \stdClass();
        $obj->url = 'http://example.com/page';
        $obj->title = 'Test';
        $original = serialize($obj);

        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $unserialized = unserialize($result);
        $this->assertSame('http://sandbox.local/page', $unserialized->url);
        $this->assertSame('Test', $unserialized->title);
    }

    public function testSearchReplaceValueHandlesSerializedBoolean(): void
    {
        $original = serialize(true);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $this->assertSame($original, $result);
    }

    public function testSearchReplaceValueHandlesSerializedInteger(): void
    {
        $original = serialize(42);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $this->assertSame($original, $result);
    }

    public function testSearchReplaceValueHandlesSerializedNull(): void
    {
        $original = serialize(null);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $this->assertSame($original, $result);
    }

    public function testSearchReplaceValueHandlesEmptySerializedArray(): void
    {
        $original = serialize([]);
        $result = $this->cloner->search_replace_value($original, 'http://example.com', 'http://sandbox.local');
        $this->assertSame($original, $result);
    }

    // rewrite_urls() via SQLite

    public function testRewriteUrlsUpdatesOptionsTable(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('siteurl', 'http://host.local')");
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('home', 'http://host.local')");
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('blogname', 'Test Blog')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $siteurl = $pdo->query("SELECT option_value FROM wp_test_options WHERE option_name='siteurl'")->fetchColumn();
        $home = $pdo->query("SELECT option_value FROM wp_test_options WHERE option_name='home'")->fetchColumn();
        $blogname = $pdo->query("SELECT option_value FROM wp_test_options WHERE option_name='blogname'")->fetchColumn();

        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test', $siteurl);
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test', $home);
        $this->assertSame('Test Blog', $blogname);
    }

    public function testRewriteUrlsUpdatesPostContent(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_posts (ID, post_author, post_content, post_title, post_date, post_date_gmt, post_modified, post_modified_gmt) VALUES (1, 1, 'Visit http://host.local/about for info', 'Test', '2026-01-01', '2026-01-01', '2026-01-01', '2026-01-01')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $content = $pdo->query("SELECT post_content FROM wp_test_posts WHERE ID=1")->fetchColumn();
        $this->assertSame('Visit http://host.local/' . RUDEL_PATH_PREFIX . '/test/about for info', $content);
    }

    public function testRewriteUrlsUpdatesPostGuid(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_posts (ID, post_author, post_content, post_title, guid, post_date, post_date_gmt, post_modified, post_modified_gmt) VALUES (1, 1, 'content', 'Test', 'http://host.local/?p=1', '2026-01-01', '2026-01-01', '2026-01-01', '2026-01-01')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $guid = $pdo->query("SELECT guid FROM wp_test_posts WHERE ID=1")->fetchColumn();
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test/?p=1', $guid);
    }

    public function testRewriteUrlsSkipsWhenUrlsAreIdentical(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('siteurl', 'http://host.local')");

        // Should not throw or modify anything.
        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local');

        $siteurl = $pdo->query("SELECT option_value FROM wp_test_options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertSame('http://host.local', $siteurl);
    }

    public function testRewriteUrlsHandlesSerializedOptionValues(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $serialized = serialize(['widget_url' => 'http://host.local/widget']);
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('widget_config', " . $pdo->quote($serialized) . ")");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $value = $pdo->query("SELECT option_value FROM wp_test_options WHERE option_name='widget_config'")->fetchColumn();
        $data = unserialize($value);
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test/widget', $data['widget_url']);
    }

    // rewrite_table_prefix_in_data()

    public function testRewriteTablePrefixInDataUpdatesUsermetaKeys(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_capabilities', 'a:1:{s:13:\"administrator\";b:1;}')");
        $pdo->exec("INSERT INTO wp_test_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_user_level', '10')");
        $pdo->exec("INSERT INTO wp_test_usermeta (user_id, meta_key, meta_value) VALUES (1, 'nickname', 'admin')");

        $this->cloner->rewrite_table_prefix_in_data($pdo, 'wp_test_', 'wp_', 'wp_test_');

        $caps = $pdo->query("SELECT meta_key FROM wp_test_usermeta WHERE user_id=1 AND meta_key LIKE '%capabilities'")->fetchColumn();
        $level = $pdo->query("SELECT meta_key FROM wp_test_usermeta WHERE user_id=1 AND meta_key LIKE '%user_level'")->fetchColumn();
        $nick = $pdo->query("SELECT meta_key FROM wp_test_usermeta WHERE user_id=1 AND meta_key='nickname'")->fetchColumn();

        $this->assertSame('wp_test_capabilities', $caps);
        $this->assertSame('wp_test_user_level', $level);
        $this->assertSame('nickname', $nick);
    }

    public function testRewriteTablePrefixInDataUpdatesOptionNames(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('wp_user_roles', 'serialized_roles')");
        $pdo->exec("INSERT INTO wp_test_options (option_name, option_value) VALUES ('blogname', 'Test')");

        $this->cloner->rewrite_table_prefix_in_data($pdo, 'wp_test_', 'wp_', 'wp_test_');

        $roles = $pdo->query("SELECT option_name FROM wp_test_options WHERE option_value='serialized_roles'")->fetchColumn();
        $blog = $pdo->query("SELECT option_name FROM wp_test_options WHERE option_value='Test'")->fetchColumn();

        $this->assertSame('wp_test_user_roles', $roles);
        $this->assertSame('blogname', $blog);
    }

    public function testRewriteTablePrefixInDataSkipsWhenPrefixesMatch(): void
    {
        $pdo = $this->createTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_usermeta (user_id, meta_key, meta_value) VALUES (1, 'wp_test_capabilities', 'test')");

        $this->cloner->rewrite_table_prefix_in_data($pdo, 'wp_test_', 'wp_test_', 'wp_test_');

        $key = $pdo->query("SELECT meta_key FROM wp_test_usermeta WHERE user_id=1")->fetchColumn();
        $this->assertSame('wp_test_capabilities', $key);
    }

    // discover_tables() -- cannot test without $wpdb, so we test rename_prefix coverage

    public function testRenamePrefixWithPluginTable(): void
    {
        $result = $this->cloner->rename_prefix('wp_woocommerce_orders', 'wp_', 'wp_abc_');
        $this->assertSame('wp_abc_woocommerce_orders', $result);
    }

    public function testRenamePrefixWithMultisite(): void
    {
        $result = $this->cloner->rename_prefix('wp_2_posts', 'wp_2_', 'wp_abc_');
        $this->assertSame('wp_abc_posts', $result);
    }

    // rewrite_urls() -- multisite per-blog tables

    public function testRewriteUrlsProcessesPerBlogPostsGuid(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_2_posts (ID, post_author, post_content, post_title, guid, post_date, post_date_gmt, post_modified, post_modified_gmt) VALUES (1, 1, 'content', 'News Post', 'http://host.local/?p=10', '2026-01-01', '2026-01-01', '2026-01-01', '2026-01-01')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $guid = $pdo->query("SELECT guid FROM wp_test_2_posts WHERE ID=1")->fetchColumn();
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test/?p=10', $guid);
    }

    public function testRewriteUrlsProcessesPerBlogOptions(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_2_options (option_name, option_value) VALUES ('siteurl', 'http://host.local/news')");
        $pdo->exec("INSERT INTO wp_test_2_options (option_name, option_value) VALUES ('home', 'http://host.local/news')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $siteurl = $pdo->query("SELECT option_value FROM wp_test_2_options WHERE option_name='siteurl'")->fetchColumn();
        $home = $pdo->query("SELECT option_value FROM wp_test_2_options WHERE option_name='home'")->fetchColumn();
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test/news', $siteurl);
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test/news', $home);
    }

    public function testRewriteUrlsProcessesPerBlogPostContent(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_3_posts (ID, post_author, post_content, post_title, post_date, post_date_gmt, post_modified, post_modified_gmt) VALUES (1, 1, 'Visit http://host.local/shop for deals', 'Shop Post', '2026-01-01', '2026-01-01', '2026-01-01', '2026-01-01')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $content = $pdo->query("SELECT post_content FROM wp_test_3_posts WHERE ID=1")->fetchColumn();
        $this->assertSame('Visit http://host.local/' . RUDEL_PATH_PREFIX . '/test/shop for deals', $content);
    }

    public function testRewriteUrlsProcessesSitemeta(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_sitemeta (site_id, meta_key, meta_value) VALUES (1, 'siteurl', 'http://host.local')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $value = $pdo->query("SELECT meta_value FROM wp_test_sitemeta WHERE meta_key='siteurl'")->fetchColumn();
        $this->assertSame('http://host.local/' . RUDEL_PATH_PREFIX . '/test', $value);
    }

    public function testRewriteUrlsRewritesWpBlogsPath(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_blogs (blog_id, site_id, domain, path) VALUES (1, 1, 'host.local', '/')");
        $pdo->exec("INSERT INTO wp_test_blogs (blog_id, site_id, domain, path) VALUES (2, 1, 'host.local', '/news/')");
        $pdo->exec("INSERT INTO wp_test_blogs (blog_id, site_id, domain, path) VALUES (3, 1, 'host.local', '/shop/')");

        $this->cloner->rewrite_urls($pdo, 'wp_test_', 'http://host.local', 'http://host.local/' . RUDEL_PATH_PREFIX . '/test');

        $paths = $pdo->query("SELECT blog_id, path FROM wp_test_blogs ORDER BY blog_id")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame('/' . RUDEL_PATH_PREFIX . '/test/', $paths[1]);
        $this->assertSame('/' . RUDEL_PATH_PREFIX . '/test/news/', $paths[2]);
        $this->assertSame('/' . RUDEL_PATH_PREFIX . '/test/shop/', $paths[3]);
    }

    // rewrite_table_prefix_in_data() -- multisite per-blog options

    public function testRewriteTablePrefixInDataHandlesPerBlogOptions(): void
    {
        $pdo = $this->createMultisiteTestDb('wp_test_');
        $pdo->exec("INSERT INTO wp_test_2_options (option_name, option_value) VALUES ('wp_user_roles', 'roles_data')");
        $pdo->exec("INSERT INTO wp_test_2_options (option_name, option_value) VALUES ('blogname', 'News')");

        $this->cloner->rewrite_table_prefix_in_data($pdo, 'wp_test_', 'wp_', 'wp_test_');

        $roles = $pdo->query("SELECT option_name FROM wp_test_2_options WHERE option_value='roles_data'")->fetchColumn();
        $blog = $pdo->query("SELECT option_name FROM wp_test_2_options WHERE option_value='News'")->fetchColumn();
        $this->assertSame('wp_test_user_roles', $roles);
        $this->assertSame('blogname', $blog);
    }

    // Helpers

    private function createMultisiteTestDb(string $prefix): \PDO
    {
        $pdo = $this->createTestDb($prefix);

        // Per-blog tables for blog_id=2
        $pdo->exec("CREATE TABLE {$prefix}2_options (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload TEXT NOT NULL DEFAULT 'yes'
        )");
        $pdo->exec("CREATE TABLE {$prefix}2_posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_author INTEGER NOT NULL DEFAULT 0,
            post_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content TEXT NOT NULL DEFAULT '',
            post_title TEXT NOT NULL DEFAULT '',
            post_excerpt TEXT NOT NULL DEFAULT '',
            post_status TEXT NOT NULL DEFAULT 'publish',
            comment_status TEXT NOT NULL DEFAULT 'open',
            ping_status TEXT NOT NULL DEFAULT 'open',
            post_password TEXT NOT NULL DEFAULT '',
            post_name TEXT NOT NULL DEFAULT '',
            to_ping TEXT NOT NULL DEFAULT '',
            pinged TEXT NOT NULL DEFAULT '',
            post_modified TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content_filtered TEXT NOT NULL DEFAULT '',
            post_parent INTEGER NOT NULL DEFAULT 0,
            guid TEXT NOT NULL DEFAULT '',
            menu_order INTEGER NOT NULL DEFAULT 0,
            post_type TEXT NOT NULL DEFAULT 'post',
            post_mime_type TEXT NOT NULL DEFAULT '',
            comment_count INTEGER NOT NULL DEFAULT 0
        )");

        // Per-blog tables for blog_id=3
        $pdo->exec("CREATE TABLE {$prefix}3_options (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload TEXT NOT NULL DEFAULT 'yes'
        )");
        $pdo->exec("CREATE TABLE {$prefix}3_posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_author INTEGER NOT NULL DEFAULT 0,
            post_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content TEXT NOT NULL DEFAULT '',
            post_title TEXT NOT NULL DEFAULT '',
            post_excerpt TEXT NOT NULL DEFAULT '',
            post_status TEXT NOT NULL DEFAULT 'publish',
            comment_status TEXT NOT NULL DEFAULT 'open',
            ping_status TEXT NOT NULL DEFAULT 'open',
            post_password TEXT NOT NULL DEFAULT '',
            post_name TEXT NOT NULL DEFAULT '',
            to_ping TEXT NOT NULL DEFAULT '',
            pinged TEXT NOT NULL DEFAULT '',
            post_modified TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content_filtered TEXT NOT NULL DEFAULT '',
            post_parent INTEGER NOT NULL DEFAULT 0,
            guid TEXT NOT NULL DEFAULT '',
            menu_order INTEGER NOT NULL DEFAULT 0,
            post_type TEXT NOT NULL DEFAULT 'post',
            post_mime_type TEXT NOT NULL DEFAULT '',
            comment_count INTEGER NOT NULL DEFAULT 0
        )");

        // Network tables
        $pdo->exec("CREATE TABLE {$prefix}blogs (
            blog_id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL DEFAULT 0,
            domain TEXT NOT NULL DEFAULT '',
            path TEXT NOT NULL DEFAULT '/',
            registered TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_updated TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            public INTEGER NOT NULL DEFAULT 1,
            archived INTEGER NOT NULL DEFAULT 0,
            mature INTEGER NOT NULL DEFAULT 0,
            spam INTEGER NOT NULL DEFAULT 0,
            deleted INTEGER NOT NULL DEFAULT 0,
            lang_id INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE {$prefix}sitemeta (
            meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL DEFAULT 0,
            meta_key TEXT DEFAULT NULL,
            meta_value TEXT
        )");

        return $pdo;
    }

    private function createTestDb(string $prefix): \PDO
    {
        $dbPath = $this->tmpDir . '/test.db';
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE {$prefix}options (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL DEFAULT '' UNIQUE,
            option_value TEXT NOT NULL DEFAULT '',
            autoload TEXT NOT NULL DEFAULT 'yes'
        )");
        $pdo->exec("CREATE TABLE {$prefix}posts (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            post_author INTEGER NOT NULL DEFAULT 0,
            post_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content TEXT NOT NULL DEFAULT '',
            post_title TEXT NOT NULL DEFAULT '',
            post_excerpt TEXT NOT NULL DEFAULT '',
            post_status TEXT NOT NULL DEFAULT 'publish',
            comment_status TEXT NOT NULL DEFAULT 'open',
            ping_status TEXT NOT NULL DEFAULT 'open',
            post_password TEXT NOT NULL DEFAULT '',
            post_name TEXT NOT NULL DEFAULT '',
            to_ping TEXT NOT NULL DEFAULT '',
            pinged TEXT NOT NULL DEFAULT '',
            post_modified TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_modified_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            post_content_filtered TEXT NOT NULL DEFAULT '',
            post_parent INTEGER NOT NULL DEFAULT 0,
            guid TEXT NOT NULL DEFAULT '',
            menu_order INTEGER NOT NULL DEFAULT 0,
            post_type TEXT NOT NULL DEFAULT 'post',
            post_mime_type TEXT NOT NULL DEFAULT '',
            comment_count INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE {$prefix}postmeta (
            meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL DEFAULT 0,
            meta_key TEXT DEFAULT NULL,
            meta_value TEXT
        )");
        $pdo->exec("CREATE TABLE {$prefix}usermeta (
            umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 0,
            meta_key TEXT DEFAULT NULL,
            meta_value TEXT
        )");
        $pdo->exec("CREATE TABLE {$prefix}comments (
            comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_post_ID INTEGER NOT NULL DEFAULT 0,
            comment_author TEXT NOT NULL DEFAULT '',
            comment_author_email TEXT NOT NULL DEFAULT '',
            comment_author_url TEXT NOT NULL DEFAULT '',
            comment_author_IP TEXT NOT NULL DEFAULT '',
            comment_date TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            comment_date_gmt TEXT NOT NULL DEFAULT '0000-00-00 00:00:00',
            comment_content TEXT NOT NULL,
            comment_karma INTEGER NOT NULL DEFAULT 0,
            comment_approved TEXT NOT NULL DEFAULT '1',
            comment_agent TEXT NOT NULL DEFAULT '',
            comment_type TEXT NOT NULL DEFAULT 'comment',
            comment_parent INTEGER NOT NULL DEFAULT 0,
            user_id INTEGER NOT NULL DEFAULT 0
        )");

        return $pdo;
    }
}
