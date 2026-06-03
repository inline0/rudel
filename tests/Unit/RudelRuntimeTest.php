<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rudel\RudelRuntime;
use Rudel\RuntimeProfile;
use Rudel\Tests\Fixtures\RuntimeProfiles;
use Rudel\Tests\RudelTestCase;

class RudelRuntimeTest extends RudelTestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRuntimeApiReadsProfileProvidedConstants(): void
	{
		$profile = RuntimeProfile::from_array(RuntimeProfiles::neutral($this->tmpDir));
		RuntimeProfile::set_current($profile);
		define($profile->constant('id'), 'worktree-123');
		define($profile->constant('host_table_prefix'), 'wp_');

		$runtime = new RudelRuntime($profile);

		$this->assertSame('worktree-123', $runtime->active_id());
		$this->assertTrue($runtime->is_active('worktree-123'));
		$this->assertFalse($runtime->is_active('other'));
		$this->assertSame('wp_', $runtime->host_table_prefix());
	}

	public function testRuntimeApiBuildsActivationCookieAndEnvironmentVariablesFromProfile(): void
	{
		$profile = RuntimeProfile::from_array(RuntimeProfiles::neutral($this->tmpDir));
		$runtime = new RudelRuntime($profile);

		$cookie = $runtime->activation_cookie('worktree-456');

		$this->assertSame('fixture_environment', $cookie->name);
		$this->assertSame('worktree-456', $cookie->value);
		$this->assertSame(
			[
				'expires' => 0,
				'path' => '/',
				'secure' => false,
				'httponly' => true,
				'samesite' => 'Lax',
			],
			$cookie->options()
		);
		$this->assertSame(['FIXTURE_ENVIRONMENT' => 'worktree-456'], $runtime->environment_variable('worktree-456'));
	}

	public function testRuntimeApiMarksSecureCookieForHttpsRequests(): void
	{
		$profile = RuntimeProfile::from_array(RuntimeProfiles::neutral($this->tmpDir));
		$_SERVER['HTTPS'] = 'on';

		$cookie = (new RudelRuntime($profile))->activation_cookie('secure-id');

		$this->assertTrue($cookie->secure);
		unset($_SERVER['HTTPS']);
	}
}
