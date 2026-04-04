<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Rudel\Rudel;

class RudelCliCommandOverrideTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliCommandUsesCustomConstantWhenDefined(): void
    {
        define('RUDEL_CLI_COMMAND', 'sandbox');

        $this->assertSame('sandbox', Rudel::cli_command());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliCommandFallsBackWhenOverrideIsEmpty(): void
    {
        define('RUDEL_CLI_COMMAND', '');

        $this->assertSame('rudel', Rudel::cli_command());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCliCommandMapUsesCustomRootCommand(): void
    {
        define('RUDEL_CLI_COMMAND', 'sandbox');

        $definitions = array_column(Rudel::cli_command_map(), null, 'operation');
        $definition = $definitions['template.list'];

        $this->assertSame('wp sandbox template list', $definition['wp_cli_command']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResolveCliCommandAcceptsCustomRootCommand(): void
    {
        define('RUDEL_CLI_COMMAND', 'sandbox');

        $plan = Rudel::resolve_cli_command('wp sandbox template list');

        $this->assertSame('template.list', $plan['operation']);
        $this->assertSame('wp sandbox template list', $plan['wp_cli_command']);
        $this->assertSame(\Rudel\Rudel::class . '::templates', $plan['callable']);
        $this->assertSame([], $plan['arguments']);
    }
}
