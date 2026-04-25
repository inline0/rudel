<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rudel\Connection;

class ConnectionTest extends TestCase {

	protected function tearDown(): void {
		\Rudel\Rudel::reset();
		parent::tearDown();
	}

	public function test_table_prefixes_name(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );

		$this->assertSame( 'wp_rudel_environments', $conn->table( 'rudel_environments' ) );
	}

	public function test_table_with_custom_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'tenant_' );

		$this->assertSame( 'tenant_rudel_apps', $conn->table( 'rudel_apps' ) );
		$this->assertSame( 'tenant_rudel_worktrees', $conn->table( 'rudel_worktrees' ) );
	}

	public function test_table_with_empty_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', '' );

		$this->assertSame( 'rudel_app_domains', $conn->table( 'rudel_app_domains' ) );
	}

	public function test_prefix_returns_configured_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'custom_' );

		$this->assertSame( 'custom_', $conn->prefix() );
	}

	public function test_prefix_default_is_wp(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass' );

		$this->assertSame( 'wp_', $conn->prefix() );
	}

	public function test_table_prefix_defaults_to_runtime_config_prefix(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_' );

		$this->assertSame( 'rudel_', $conn->table_prefix() );
	}

	public function test_table_prefix_can_be_set_per_connection(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', 'divine_rudel' );

		$this->assertSame( 'divine_rudel_', $conn->table_prefix() );
		$this->assertSame( 'wp_divine_rudel_environments', $conn->table( 'rudel_environments' ) );
		$this->assertSame( 'wp_divine_rudel_apps', $conn->table( 'rudel_apps' ) );
		$this->assertSame( 'wp_divine_rudel_worktrees', $conn->table( 'rudel_worktrees' ) );
	}

	public function test_table_prefix_can_be_empty_per_connection(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', '' );

		$this->assertSame( '', $conn->table_prefix() );
		$this->assertSame( 'wp_environments', $conn->table( 'rudel_environments' ) );
	}

	public function test_connection_table_prefix_applies_to_unprefixed_names(): void {
		$conn = new Connection( 'localhost', 'testdb', 'root', 'pass', 'wp_', 'tenant_runtime' );

		$this->assertSame( 'wp_tenant_runtime_anything', $conn->table( 'anything' ) );
	}
}
