<?php

namespace Rudel\Tests\Unit;

use Rudel\RuntimeProfile;
use Rudel\RuntimeTemplateRenderer;
use Rudel\Tests\Fixtures\RuntimeProfiles;
use Rudel\Tests\RudelTestCase;

class RuntimeTemplateRendererTest extends RudelTestCase
{
	public function testDbDropInUsesProfileProvidedConstants(): void
	{
		$profile = RuntimeProfile::from_array(RuntimeProfiles::neutral($this->tmpDir));
		$template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/db.php.tpl');

		$rendered = RuntimeTemplateRenderer::render($template, $profile);

		$this->assertStringContainsString("defined( 'FIXTURE_USERS_TABLE' )", $rendered);
		$this->assertStringContainsString("CUSTOM_USER_TABLE', FIXTURE_USERS_TABLE", $rendered);
		$this->assertStringContainsString("defined( 'FIXTURE_USERMETA_TABLE' )", $rendered);
		$this->assertStringNotContainsString('RUDEL_USERS_TABLE', $rendered);
		$this->assertStringNotContainsString('RUDEL_USERMETA_TABLE', $rendered);
	}

	public function testRuntimeMuPluginUsesProfileProvidedNamesAndLabels(): void
	{
		$profile = RuntimeProfile::from_array(RuntimeProfiles::neutral($this->tmpDir));
		$template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/runtime-mu-plugin.php.tpl');

		$rendered = RuntimeTemplateRenderer::render($template, $profile);

		$this->assertStringContainsString("defined( 'FIXTURE_RUNTIME_HOOKS_LOADED' )", $rendered);
		$this->assertStringContainsString('function fixture_runtime_environment_url()', $rendered);
		$this->assertStringContainsString("defined( 'FIXTURE_ENVIRONMENT_URL' )", $rendered);
		$this->assertStringContainsString("'id'    => 'fixture-environment'", $rendered);
		$this->assertStringContainsString('Fixture: email blocked', $rendered);
		$this->assertStringNotContainsString('RUDEL_RUNTIME_HOOKS_LOADED', $rendered);
		$this->assertStringNotContainsString('rudel_runtime_environment_url', $rendered);
		$this->assertStringNotContainsString('rudel-environment', $rendered);
	}
}
