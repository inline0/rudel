<?php

namespace Rudel\Tests\Unit;

use Rudel\HookCatalog;
use Rudel\Tests\RudelTestCase;

class HookCatalogTest extends RudelTestCase
{
    public function testCatalogIncludesCurrentOperationalAndAutomationHooks(): void
    {
        $hooks = HookCatalog::all();

        $this->assertSame('action', $hooks['rudel_app_rollback_failed']['type']);
        $this->assertSame(['$context', '$error'], $hooks['rudel_environment_push_failed']['args']);
        $this->assertSame('action', $hooks['rudel_after_automation_app_retention']['type']);
        $this->assertSame('filter', $hooks['rudel_app_deploy_plan']['type']);
        $this->assertArrayHasKey('rudel_app_domain_add_failed', $hooks);
        $this->assertArrayHasKey('rudel_after_environment_replace_state', $hooks);
    }
}
