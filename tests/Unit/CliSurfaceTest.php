<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rudel\CliCommandMap;

class CliSurfaceTest extends TestCase
{
    public function testCommandMapPublishesCurrentCommandSurface(): void
    {
        $definitions = CliCommandMap::definitions();
        $operations = array_values(array_map(static fn(array $definition): string => (string) $definition['operation'], $definitions));

        $this->assertSame(
            [
                'sandbox.create',
                'sandbox.list',
                'sandbox.info',
                'sandbox.destroy',
                'sandbox.update',
                'system.status',
                'sandbox.cleanup',
                'sandbox.logs',
                'sandbox.push',
                'sandbox.restore',
                'sandbox.snapshot',
                'template.list',
                'template.save',
                'template.delete',
                'app.create',
                'app.list',
                'app.info',
                'app.destroy',
                'app.update',
                'app.create-sandbox',
                'app.backup',
                'app.backups',
                'app.deployments',
                'app.restore',
                'app.deploy',
                'app.rollback',
                'app.domain-add',
                'app.domain-remove',
            ],
            $operations
        );
    }
}
