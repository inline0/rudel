<?php

namespace Rudel\Tests\Unit;

use Rudel\Environment;
use Rudel\EnvironmentUserIsolationService;
use Rudel\Tests\RudelTestCase;

class EnvironmentUserIsolationServiceTest extends RudelTestCase
{
    public function testCloneFromHostCreatesIsolatedUserTablesAndRewritesCapabilityKeys(): void
    {
        $environment = new Environment(
            id: 'alpha-site',
            name: 'Alpha Site',
            path: $this->tmpDir . '/alpha-site',
            created_at: '2026-01-01T00:00:00+00:00',
            multisite: true,
            engine: 'subsite',
            blog_id: 2,
            type: 'sandbox'
        );

        (new EnvironmentUserIsolationService())->clone_from_host($environment);

        $usersTable = (string) $environment->get_users_table();
        $usermetaTable = (string) $environment->get_usermeta_table();

        $this->assertTrue($GLOBALS['wpdb']->hasTable($usersTable));
        $this->assertTrue($GLOBALS['wpdb']->hasTable($usermetaTable));
        $this->assertCount(1, $GLOBALS['wpdb']->getTableRows($usersTable));
        $this->assertSame('admin', $GLOBALS['wpdb']->getTableRows($usersTable)[0]['user_login'] ?? null);

        $metaKeys = array_column($GLOBALS['wpdb']->getTableRows($usermetaTable), 'meta_key');
        $this->assertContains('wp_2_capabilities', $metaKeys);
        $this->assertContains('wp_2_user_level', $metaKeys);
        $this->assertNotContains('wp_capabilities', $metaKeys);
    }

    public function testCloneFromEnvironmentCopiesIsolatedUsersIntoTheTargetEnvironment(): void
    {
        $source = new Environment(
            id: 'source-site',
            name: 'Source Site',
            path: $this->tmpDir . '/source-site',
            created_at: '2026-01-01T00:00:00+00:00',
            multisite: true,
            engine: 'subsite',
            blog_id: 2,
            type: 'app'
        );
        $target = new Environment(
            id: 'target-site',
            name: 'Target Site',
            path: $this->tmpDir . '/target-site',
            created_at: '2026-01-01T00:00:00+00:00',
            multisite: true,
            engine: 'subsite',
            blog_id: 3,
            type: 'sandbox'
        );

        mkdir($source->path . '/wp-content', 0755, true);
        mkdir($target->path . '/wp-content', 0755, true);

        $service = new EnvironmentUserIsolationService();
        $service->clone_from_host($source);

        $sourceUsersTable = (string) $source->get_users_table();
        $sourceUsermetaTable = (string) $source->get_usermeta_table();

        $GLOBALS['wpdb']->getTableRows($sourceUsersTable)[0]['user_login'] = 'app-admin';
        $rows = $GLOBALS['wpdb']->getTableRows($sourceUsersTable);
        $rows[0]['user_login'] = 'app-admin';
        $GLOBALS['wpdb']->addTable($sourceUsersTable, 'CREATE TABLE `'.$sourceUsersTable.'` (`ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_login` varchar(60), `user_pass` varchar(255), `user_email` varchar(100), PRIMARY KEY (`ID`))', $rows);

        $service->clone_from_environment($source, $target);

        $targetUsersTable = (string) $target->get_users_table();
        $targetUsermetaTable = (string) $target->get_usermeta_table();
        $targetRows = $GLOBALS['wpdb']->getTableRows($targetUsersTable);
        $targetLogins = array_column($targetRows, 'user_login');
        $targetMetaKeys = array_column($GLOBALS['wpdb']->getTableRows($targetUsermetaTable), 'meta_key');

        $this->assertContains('app-admin', $targetLogins);
        $this->assertContains('wp_3_capabilities', $targetMetaKeys);
        $this->assertNotContains('wp_2_capabilities', $targetMetaKeys);
    }
}
