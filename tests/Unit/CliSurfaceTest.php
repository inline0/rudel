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

        $this->assertContains('sandbox.create', $operations);
        $this->assertContains('sandbox.snapshot', $operations);
        $this->assertContains('template.save', $operations);
        $this->assertContains('app.create', $operations);
        $this->assertContains('app.deploy', $operations);
    }
}
