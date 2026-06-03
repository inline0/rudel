<?php

namespace Rudel\Tests\Unit;

use Rudel\HookCatalog;
use Rudel\Tests\RudelTestCase;

class HookCatalogTest extends RudelTestCase
{
    public function testCatalogIncludesCurrentOperationalAndAutomationHooks(): void
    {
        $hooks = HookCatalog::all();

        $this->assertSame('action', $hooks['rudel_environment_replace_state_failed']['type']);
        $this->assertSame(['$context', '$error'], $hooks['rudel_environment_push_failed']['args']);
        $this->assertSame('action', $hooks['rudel_after_automation_expiring_environments']['type']);
        $this->assertSame('filter', $hooks['rudel_environment_cleanup_options']['type']);
        $this->assertSame('filter', $hooks['rudel_environment_db_dropin_contents']['type']);
        $this->assertSame(['$contents', '$context'], $hooks['rudel_environment_db_dropin_contents']['args']);
        $this->assertArrayHasKey('rudel_after_environment_replace_state', $hooks);
    }
}
