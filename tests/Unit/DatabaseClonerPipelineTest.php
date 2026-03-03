<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use Rudel\DatabaseCloner;
use Rudel\Tests\RudelTestCase;

/**
 * Full pipeline tests for DatabaseCloner.
 *
 * Uses MockWpdb + real WP_SQLite_Translator to test the complete
 * MySQL-to-SQLite clone flow with realistic schemas and data.
 */
class DatabaseClonerPipelineTest extends RudelTestCase
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

    private function getTableNames(\PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != '_mysql_data_types_cache' ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    // Full pipeline: WordPress core posts table

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneWordPressPostsTableWithData(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_posts', "CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_title` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_excerpt` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `post_password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `post_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `to_ping` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `pinged` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT '0',
  `post_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['ID' => '1', 'post_author' => '1', 'post_date' => '2026-01-15 10:30:00', 'post_date_gmt' => '2026-01-15 10:30:00', 'post_content' => '<p>Welcome to WordPress. <a href="http://host.local/about">About</a></p>', 'post_title' => 'Hello world!', 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'open', 'post_password' => '', 'post_name' => 'hello-world', 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-01-15 10:30:00', 'post_modified_gmt' => '2026-01-15 10:30:00', 'post_content_filtered' => '', 'post_parent' => '0', 'guid' => 'http://host.local/?p=1', 'menu_order' => '0', 'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => '1'],
            ['ID' => '2', 'post_author' => '1', 'post_date' => '2026-01-15 10:30:00', 'post_date_gmt' => '2026-01-15 10:30:00', 'post_content' => 'Sample page content.', 'post_title' => 'Sample Page', 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'open', 'post_password' => '', 'post_name' => 'sample-page', 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-01-15 10:30:00', 'post_modified_gmt' => '2026-01-15 10:30:00', 'post_content_filtered' => '', 'post_parent' => '0', 'guid' => 'http://host.local/?page_id=2', 'menu_order' => '0', 'post_type' => 'page', 'post_mime_type' => '', 'comment_count' => '0'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_posts', 'wp_', 'wp_sb_');
        $rows = $cloner->clone_table_data($wpdb, $translator, 'wp_posts', 'wp_sb_posts', 'wp_', 'wp_sb_');

        $this->assertEquals(2, $rows);

        $pdo = $this->openDb($dbPath);
        $tables = $this->getTableNames($pdo);
        $this->assertContains('wp_sb_posts', $tables);

        $count = $pdo->query("SELECT COUNT(*) FROM wp_sb_posts")->fetchColumn();
        $this->assertEquals('2', $count);

        $title = $pdo->query("SELECT post_title FROM wp_sb_posts WHERE ID=1")->fetchColumn();
        $this->assertEquals('Hello world!', $title);

        $type = $pdo->query("SELECT post_type FROM wp_sb_posts WHERE ID=2")->fetchColumn();
        $this->assertEquals('page', $type);
    }

    // Full pipeline: WordPress options with serialized data

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneOptionsWithSerializedAndUrlRewriting(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $activePlugins = serialize(['akismet/akismet.php', 'jetpack/jetpack.php']);
        $widgetConfig = serialize([
            'text-1' => ['title' => 'Widget', 'text' => 'Visit http://host.local/about'],
            'text-2' => ['title' => 'Links', 'text' => '<a href="http://host.local/contact">Contact</a>'],
        ]);
        $nestedConfig = serialize([
            'level1' => [
                'level2' => [
                    'url' => 'http://host.local/deep/nested/path',
                    'count' => 42,
                ],
            ],
        ]);

        $wpdb->addTable('wp_options', "CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `autoload` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) ENGINE=InnoDB AUTO_INCREMENT=200 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['option_id' => '1', 'option_name' => 'siteurl', 'option_value' => 'http://host.local', 'autoload' => 'yes'],
            ['option_id' => '2', 'option_name' => 'home', 'option_value' => 'http://host.local', 'autoload' => 'yes'],
            ['option_id' => '3', 'option_name' => 'blogname', 'option_value' => 'Test Blog', 'autoload' => 'yes'],
            ['option_id' => '4', 'option_name' => 'active_plugins', 'option_value' => $activePlugins, 'autoload' => 'yes'],
            ['option_id' => '5', 'option_name' => 'widget_text', 'option_value' => $widgetConfig, 'autoload' => 'yes'],
            ['option_id' => '6', 'option_name' => 'nested_config', 'option_value' => $nestedConfig, 'autoload' => 'yes'],
            ['option_id' => '7', 'option_name' => 'wp_user_roles', 'option_value' => 'serialized_roles_data', 'autoload' => 'yes'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_options', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_options', 'wp_sb_options', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $cloner->rewrite_urls($pdo, 'wp_sb_', 'http://host.local', 'http://host.local/__rudel/test-1234');
        $cloner->rewrite_table_prefix_in_data($pdo, 'wp_sb_', 'wp_', 'wp_sb_');

        // siteurl and home rewritten
        $siteurl = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='siteurl'")->fetchColumn();
        $this->assertEquals('http://host.local/__rudel/test-1234', $siteurl);

        // blogname unchanged
        $blogname = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='blogname'")->fetchColumn();
        $this->assertEquals('Test Blog', $blogname);

        // active_plugins serialized data unchanged (no URLs in it)
        $plugins = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='active_plugins'")->fetchColumn();
        $pluginData = unserialize($plugins);
        $this->assertCount(2, $pluginData);
        $this->assertEquals('akismet/akismet.php', $pluginData[0]);

        // widget_text serialized URLs rewritten
        $widgets = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='widget_text'")->fetchColumn();
        $widgetData = unserialize($widgets);
        $this->assertStringContainsString('http://host.local/__rudel/test-1234/about', $widgetData['text-1']['text']);
        $this->assertStringContainsString('http://host.local/__rudel/test-1234/contact', $widgetData['text-2']['text']);

        // Deeply nested serialized URL rewritten
        $nested = $pdo->query("SELECT option_value FROM wp_sb_options WHERE option_name='nested_config'")->fetchColumn();
        $nestedData = unserialize($nested);
        $this->assertEquals('http://host.local/__rudel/test-1234/deep/nested/path', $nestedData['level1']['level2']['url']);
        $this->assertEquals(42, $nestedData['level1']['level2']['count']);

        // Table prefix rewritten in option_name
        $rolesName = $pdo->query("SELECT option_name FROM wp_sb_options WHERE option_value='serialized_roles_data'")->fetchColumn();
        $this->assertEquals('wp_sb_user_roles', $rolesName);
    }

    // Full pipeline: WordPress usermeta with prefixed keys

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneUsermetaWithPrefixedKeys(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $caps = serialize(['administrator' => true]);
        $dashWidgets = serialize(['dashboard_quick_press' => true, 'dashboard_activity' => true]);

        $wpdb->addTable('wp_usermeta', "CREATE TABLE `wp_usermeta` (
  `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_value` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`umeta_id`),
  KEY `user_id` (`user_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['umeta_id' => '1', 'user_id' => '1', 'meta_key' => 'nickname', 'meta_value' => 'admin'],
            ['umeta_id' => '2', 'user_id' => '1', 'meta_key' => 'wp_capabilities', 'meta_value' => $caps],
            ['umeta_id' => '3', 'user_id' => '1', 'meta_key' => 'wp_user_level', 'meta_value' => '10'],
            ['umeta_id' => '4', 'user_id' => '1', 'meta_key' => 'wp_dashboard_quick_press_last_post_id', 'meta_value' => '3'],
            ['umeta_id' => '5', 'user_id' => '2', 'meta_key' => 'wp_capabilities', 'meta_value' => serialize(['editor' => true])],
            ['umeta_id' => '6', 'user_id' => '2', 'meta_key' => 'wp_user_level', 'meta_value' => '7'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_usermeta', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_usermeta', 'wp_sb_usermeta', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $cloner->rewrite_table_prefix_in_data($pdo, 'wp_sb_', 'wp_', 'wp_sb_');

        // Non-prefixed keys unchanged
        $nick = $pdo->query("SELECT meta_key FROM wp_sb_usermeta WHERE umeta_id=1")->fetchColumn();
        $this->assertEquals('nickname', $nick);

        // Prefixed keys rewritten for both users
        $caps1 = $pdo->query("SELECT meta_key FROM wp_sb_usermeta WHERE umeta_id=2")->fetchColumn();
        $this->assertEquals('wp_sb_capabilities', $caps1);

        $level1 = $pdo->query("SELECT meta_key FROM wp_sb_usermeta WHERE umeta_id=3")->fetchColumn();
        $this->assertEquals('wp_sb_user_level', $level1);

        $dash = $pdo->query("SELECT meta_key FROM wp_sb_usermeta WHERE umeta_id=4")->fetchColumn();
        $this->assertEquals('wp_sb_dashboard_quick_press_last_post_id', $dash);

        $caps2 = $pdo->query("SELECT meta_key FROM wp_sb_usermeta WHERE umeta_id=5")->fetchColumn();
        $this->assertEquals('wp_sb_capabilities', $caps2);

        // Verify serialized capability data is intact
        $capValue = $pdo->query("SELECT meta_value FROM wp_sb_usermeta WHERE umeta_id=2")->fetchColumn();
        $capData = unserialize($capValue);
        $this->assertTrue($capData['administrator']);
    }

    // Complex schema: WooCommerce-style plugin table with ENUMs and various types

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneWooCommerceStyleOrdersTable(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_wc_orders', "CREATE TABLE `wp_wc_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(20) NOT NULL DEFAULT 'wc-pending',
  `currency` varchar(10) NOT NULL DEFAULT '',
  `type` varchar(20) NOT NULL DEFAULT 'shop_order',
  `tax_amount` decimal(26,8) NOT NULL DEFAULT '0.00000000',
  `total_amount` decimal(26,8) NOT NULL DEFAULT '0.00000000',
  `customer_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `billing_email` varchar(320) NOT NULL DEFAULT '',
  `date_created_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `date_updated_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `parent_order_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `payment_method` varchar(100) NOT NULL DEFAULT '',
  `payment_method_title` text NOT NULL,
  `transaction_id` varchar(100) NOT NULL DEFAULT '',
  `ip_address` varchar(100) NOT NULL DEFAULT '',
  `user_agent` text NOT NULL,
  `customer_note` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `date_created` (`date_created_gmt`),
  KEY `customer_id_billing` (`customer_id`,`billing_email`(171)),
  KEY `type_status_date` (`type`,`status`,`date_created_gmt`)
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['id' => '1', 'status' => 'wc-completed', 'currency' => 'USD', 'type' => 'shop_order', 'tax_amount' => '5.25000000', 'total_amount' => '99.99000000', 'customer_id' => '1', 'billing_email' => 'customer@example.com', 'date_created_gmt' => '2026-02-01 14:30:00', 'date_updated_gmt' => '2026-02-01 15:00:00', 'parent_order_id' => '0', 'payment_method' => 'stripe', 'payment_method_title' => 'Credit Card (Stripe)', 'transaction_id' => 'ch_abc123', 'ip_address' => '192.168.1.100', 'user_agent' => 'Mozilla/5.0', 'customer_note' => 'Please ship ASAP'],
            ['id' => '2', 'status' => 'wc-processing', 'currency' => 'EUR', 'type' => 'shop_order', 'tax_amount' => '0.00000000', 'total_amount' => '249.50000000', 'customer_id' => '2', 'billing_email' => 'buyer@test.de', 'date_created_gmt' => '2026-02-15 09:00:00', 'date_updated_gmt' => '2026-02-15 09:00:00', 'parent_order_id' => '0', 'payment_method' => 'paypal', 'payment_method_title' => 'PayPal', 'transaction_id' => 'PAY-xyz789', 'ip_address' => '10.0.0.1', 'user_agent' => 'Safari/17.0', 'customer_note' => ''],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_wc_orders', 'wp_', 'wp_sb_');
        $rows = $cloner->clone_table_data($wpdb, $translator, 'wp_wc_orders', 'wp_sb_wc_orders', 'wp_', 'wp_sb_');

        $this->assertEquals(2, $rows);

        $pdo = $this->openDb($dbPath);
        $tables = $this->getTableNames($pdo);
        $this->assertContains('wp_sb_wc_orders', $tables);

        // Verify decimal precision survives
        $total = $pdo->query("SELECT total_amount FROM wp_sb_wc_orders WHERE id=1")->fetchColumn();
        $this->assertEquals('99.99000000', $total);

        $tax = $pdo->query("SELECT tax_amount FROM wp_sb_wc_orders WHERE id=1")->fetchColumn();
        $this->assertEquals('5.25000000', $tax);

        // Verify email and various string types
        $email = $pdo->query("SELECT billing_email FROM wp_sb_wc_orders WHERE id=2")->fetchColumn();
        $this->assertEquals('buyer@test.de', $email);
    }

    // Complex schema: table with FULLTEXT index and ENUM columns

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneTableWithFulltextAndEnum(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_search_index', "CREATE TABLE `wp_search_index` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `object_type` enum('post','page','product','attachment') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'post',
  `object_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `score` float NOT NULL DEFAULT '0',
  `indexed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `object_lookup` (`object_type`,`object_id`),
  FULLTEXT KEY `ft_content` (`title`,`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['id' => '1', 'object_type' => 'post', 'object_id' => '10', 'title' => 'WordPress Performance', 'content' => 'Long article about caching, CDNs, and database optimization for WordPress sites.', 'status' => '1', 'score' => '0.85', 'indexed_at' => '2026-01-20 12:00:00'],
            ['id' => '2', 'object_type' => 'product', 'object_id' => '42', 'title' => 'Premium Theme', 'content' => 'A beautiful responsive theme with built-in page builder and 50+ templates.', 'status' => '1', 'score' => '0.92', 'indexed_at' => '2026-02-10 08:30:00'],
            ['id' => '3', 'object_type' => 'attachment', 'object_id' => '99', 'title' => 'hero-banner.jpg', 'content' => '', 'status' => '0', 'score' => '0', 'indexed_at' => '2026-02-15 16:45:00'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_search_index', 'wp_', 'wp_sb_');
        $rows = $cloner->clone_table_data($wpdb, $translator, 'wp_search_index', 'wp_sb_search_index', 'wp_', 'wp_sb_');

        $this->assertEquals(3, $rows);

        $pdo = $this->openDb($dbPath);

        // ENUM values stored as text in SQLite
        $type = $pdo->query("SELECT object_type FROM wp_sb_search_index WHERE id=2")->fetchColumn();
        $this->assertEquals('product', $type);

        // Float precision
        $score = $pdo->query("SELECT score FROM wp_sb_search_index WHERE id=1")->fetchColumn();
        $this->assertEquals(0.85, (float) $score, '', 0.01);

        // Empty content
        $content = $pdo->query("SELECT content FROM wp_sb_search_index WHERE id=3")->fetchColumn();
        $this->assertEquals('', $content);
    }

    // Complex schema: table with composite primary key

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneTableWithCompositePrimaryKey(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_term_relationships', "CREATE TABLE `wp_term_relationships` (
  `object_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `term_taxonomy_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `term_order` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`object_id`,`term_taxonomy_id`),
  KEY `term_taxonomy_id` (`term_taxonomy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", [
            ['object_id' => '1', 'term_taxonomy_id' => '1', 'term_order' => '0'],
            ['object_id' => '1', 'term_taxonomy_id' => '3', 'term_order' => '1'],
            ['object_id' => '2', 'term_taxonomy_id' => '1', 'term_order' => '0'],
            ['object_id' => '3', 'term_taxonomy_id' => '5', 'term_order' => '2'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_term_relationships', 'wp_', 'wp_sb_');
        $rows = $cloner->clone_table_data($wpdb, $translator, 'wp_term_relationships', 'wp_sb_term_relationships', 'wp_', 'wp_sb_');

        $this->assertEquals(4, $rows);

        $pdo = $this->openDb($dbPath);
        $count = $pdo->query("SELECT COUNT(*) FROM wp_sb_term_relationships")->fetchColumn();
        $this->assertEquals('4', $count);

        // Verify composite key data integrity
        $order = $pdo->query("SELECT term_order FROM wp_sb_term_relationships WHERE object_id=1 AND term_taxonomy_id=3")->fetchColumn();
        $this->assertEquals('1', $order);
    }

    // Multi-table clone: full WordPress core schema set

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneMultipleWordPressCoreTables(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        // Simplified but real MySQL DDL for 4 core tables
        $wpdb->addTable('wp_posts', "CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT '0',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['ID' => '1', 'post_author' => '1', 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_content' => 'Content', 'post_title' => 'Hello', 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'open', 'post_password' => '', 'post_name' => 'hello', 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00', 'post_content_filtered' => '', 'post_parent' => '0', 'guid' => 'http://host.local/?p=1', 'menu_order' => '0', 'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => '0'],
        ]);

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['meta_id' => '1', 'post_id' => '1', 'meta_key' => '_edit_lock', 'meta_value' => '1706745600:1'],
            ['meta_id' => '2', 'post_id' => '1', 'meta_key' => '_thumbnail_id', 'meta_value' => '5'],
        ]);

        $wpdb->addTable('wp_users', "CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT '0',
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['ID' => '1', 'user_login' => 'admin', 'user_pass' => '$P$BhashValue123', 'user_nicename' => 'admin', 'user_email' => 'admin@host.local', 'user_url' => 'http://host.local', 'user_registered' => '2026-01-01 00:00:00', 'user_activation_key' => '', 'user_status' => '0', 'display_name' => 'Admin User'],
        ]);

        $wpdb->addTable('wp_comments', "CREATE TABLE `wp_comments` (
  `comment_ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `comment_post_ID` bigint(20) unsigned NOT NULL DEFAULT '0',
  `comment_author` tinytext NOT NULL,
  `comment_author_email` varchar(100) NOT NULL DEFAULT '',
  `comment_author_url` varchar(200) NOT NULL DEFAULT '',
  `comment_author_IP` varchar(100) NOT NULL DEFAULT '',
  `comment_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `comment_content` text NOT NULL,
  `comment_karma` int(11) NOT NULL DEFAULT '0',
  `comment_approved` varchar(20) NOT NULL DEFAULT '1',
  `comment_agent` varchar(255) NOT NULL DEFAULT '',
  `comment_type` varchar(20) NOT NULL DEFAULT 'comment',
  `comment_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`comment_ID`),
  KEY `comment_post_ID` (`comment_post_ID`),
  KEY `comment_approved_date_gmt` (`comment_approved`,`comment_date_gmt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['comment_ID' => '1', 'comment_post_ID' => '1', 'comment_author' => 'Test Commenter', 'comment_author_email' => 'test@example.com', 'comment_author_url' => 'http://host.local/blog', 'comment_author_IP' => '127.0.0.1', 'comment_date' => '2026-01-02 10:00:00', 'comment_date_gmt' => '2026-01-02 10:00:00', 'comment_content' => 'Great post! See more at http://host.local/resources', 'comment_karma' => '0', 'comment_approved' => '1', 'comment_agent' => 'Mozilla/5.0', 'comment_type' => 'comment', 'comment_parent' => '0', 'user_id' => '0'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $totalRows = 0;
        foreach (['wp_posts', 'wp_postmeta', 'wp_users', 'wp_comments'] as $table) {
            $target = $cloner->rename_prefix($table, 'wp_', 'wp_sb_');
            $cloner->clone_table_structure($wpdb, $translator, $table, 'wp_', 'wp_sb_');
            $totalRows += $cloner->clone_table_data($wpdb, $translator, $table, $target, 'wp_', 'wp_sb_');
        }

        $this->assertEquals(5, $totalRows);

        $pdo = $this->openDb($dbPath);
        $tables = $this->getTableNames($pdo);
        $this->assertCount(4, $tables);
        $this->assertContains('wp_sb_posts', $tables);
        $this->assertContains('wp_sb_postmeta', $tables);
        $this->assertContains('wp_sb_users', $tables);
        $this->assertContains('wp_sb_comments', $tables);

        // Verify data in each table
        $this->assertEquals('1', $pdo->query("SELECT COUNT(*) FROM wp_sb_posts")->fetchColumn());
        $this->assertEquals('2', $pdo->query("SELECT COUNT(*) FROM wp_sb_postmeta")->fetchColumn());
        $this->assertEquals('1', $pdo->query("SELECT COUNT(*) FROM wp_sb_users")->fetchColumn());
        $this->assertEquals('1', $pdo->query("SELECT COUNT(*) FROM wp_sb_comments")->fetchColumn());

        // Verify password hash (special chars) survived
        $pass = $pdo->query("SELECT user_pass FROM wp_sb_users WHERE ID=1")->fetchColumn();
        $this->assertEquals('$P$BhashValue123', $pass);
    }

    // Edge case: table with NULL values and empty strings

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneTableWithNullsAndEmptyStrings(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_postmeta', "CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['meta_id' => '1', 'post_id' => '1', 'meta_key' => '_wp_attached_file', 'meta_value' => '2026/01/photo.jpg'],
            ['meta_id' => '2', 'post_id' => '1', 'meta_key' => '_empty_meta', 'meta_value' => ''],
            ['meta_id' => '3', 'post_id' => '1', 'meta_key' => null, 'meta_value' => null],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_postmeta', 'wp_', 'wp_sb_');
        $rows = $cloner->clone_table_data($wpdb, $translator, 'wp_postmeta', 'wp_sb_postmeta', 'wp_', 'wp_sb_');

        $this->assertEquals(3, $rows);

        $pdo = $this->openDb($dbPath);

        $normal = $pdo->query("SELECT meta_value FROM wp_sb_postmeta WHERE meta_id=1")->fetchColumn();
        $this->assertEquals('2026/01/photo.jpg', $normal);

        $empty = $pdo->query("SELECT meta_value FROM wp_sb_postmeta WHERE meta_id=2")->fetchColumn();
        $this->assertEquals('', $empty);
    }

    // Edge case: special characters in data (quotes, backslashes, unicode)

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloneTableWithSpecialCharactersInData(): void
    {
        $this->skipIfNoTranslator();
        $this->loadDependencies();

        $wpdb = new \MockWpdb();
        $wpdb->prefix = 'wp_';

        $wpdb->addTable('wp_posts', "CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT '0',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", [
            ['ID' => '1', 'post_author' => '1', 'post_date' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00', 'post_content' => "He said \"hello\" and she said 'goodbye'. Then used a backslash \\ and a percent % sign.", 'post_title' => "O'Brien's \"Special\" Post", 'post_excerpt' => '', 'post_status' => 'publish', 'comment_status' => 'open', 'ping_status' => 'open', 'post_password' => '', 'post_name' => 'obriens-special-post', 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00', 'post_content_filtered' => '', 'post_parent' => '0', 'guid' => '', 'menu_order' => '0', 'post_type' => 'post', 'post_mime_type' => '', 'comment_count' => '0'],
        ]);

        $dbPath = $this->tmpDir . '/clone.db';
        $cloner = new DatabaseCloner(dirname(__DIR__, 2) . '/');
        $translator = $this->createTranslator($dbPath);

        $cloner->clone_table_structure($wpdb, $translator, 'wp_posts', 'wp_', 'wp_sb_');
        $cloner->clone_table_data($wpdb, $translator, 'wp_posts', 'wp_sb_posts', 'wp_', 'wp_sb_');

        $pdo = $this->openDb($dbPath);
        $title = $pdo->query("SELECT post_title FROM wp_sb_posts WHERE ID=1")->fetchColumn();
        $this->assertEquals("O'Brien's \"Special\" Post", $title);

        $content = $pdo->query("SELECT post_content FROM wp_sb_posts WHERE ID=1")->fetchColumn();
        $this->assertStringContainsString('"hello"', $content);
        $this->assertStringContainsString("'goodbye'", $content);
        $this->assertStringContainsString('\\', $content);
        $this->assertStringContainsString('%', $content);
    }
}
