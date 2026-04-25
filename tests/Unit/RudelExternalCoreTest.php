<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rudel\Connection;
use Rudel\DatabaseStore;
use Rudel\PdoStore;
use Rudel\Rudel;
use Rudel\RudelDatabase;

class RudelExternalCoreTest extends TestCase {

	protected function tearDown(): void {
		Rudel::reset();
		parent::tearDown();
	}

	public function test_init_configures_standalone_store_and_context(): void {
		Rudel::init(
			new Connection( 'localhost', 'rudel', 'root', 'secret', 'wp_' ),
			array(
				'environments_dir' => '/srv/rudel/environments/',
				'apps_dir'         => '/srv/rudel/apps/',
			)
		);

		$this->assertInstanceOf( PdoStore::class, RudelDatabase::current_store() );
		$this->assertSame( '/srv/rudel/environments', Rudel::environments_dir() );
		$this->assertSame( '/srv/rudel/apps', Rudel::apps_dir() );
	}

	public function test_reset_clears_standalone_state(): void {
		Rudel::init(
			new Connection( 'localhost', 'rudel', 'root', 'secret', 'wp_' ),
			array(
				'environments_dir' => '/srv/rudel/environments',
			)
		);

		Rudel::reset();

		$this->assertNull( RudelDatabase::current_store() );
	}

	public function test_create_fails_early_outside_wordpress_runtime(): void {
		Rudel::init( new Connection( 'localhost', 'rudel', 'root', 'secret', 'wp_' ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'requires a live WordPress multisite runtime' );

		Rudel::create( 'outside-wp' );
	}

	public function test_init_accepts_per_connection_runtime_table_prefix(): void {
		Rudel::init( new Connection( 'localhost', 'rudel', 'root', 'secret', 'wp_', 'divine_rudel' ) );

		$store = RudelDatabase::current_store();

		$this->assertInstanceOf( PdoStore::class, $store );
		$this->assertSame( 'wp_divine_rudel_environments', $store->table( 'environments' ) );
		$this->assertSame( 'wp_divine_rudel_apps', $store->table( 'apps' ) );
		$this->assertSame( 'wp_divine_rudel_app_deployments', $store->table( 'app_deployments' ) );
	}

	public function test_set_store_reuses_explicit_standalone_store(): void {
		$store = new class implements DatabaseStore {
			public function cache_key(): string {
				return 'fake';
			}

			public function driver(): string {
				return 'fake';
			}

			public function prefix(): string {
				return 'wp_';
			}

			public function table( string $suffix ): string {
				return 'wp_' . $suffix;
			}

			public function execute( string $sql, array $params = array() ): int {
				unset( $sql, $params );
				return 0;
			}

			public function fetch_row( string $sql, array $params = array() ): ?array {
				unset( $sql, $params );
				return null;
			}

			public function fetch_all( string $sql, array $params = array() ): array {
				unset( $sql, $params );
				return array();
			}

			public function fetch_var( string $sql, array $params = array() ) {
				unset( $sql, $params );
				return null;
			}

			public function insert( string $table, array $data ): int {
				unset( $table, $data );
				return 1;
			}

			public function update( string $table, array $data, array $where ): int {
				unset( $table, $data, $where );
				return 0;
			}

			public function delete( string $table, array $where ): int {
				unset( $table, $where );
				return 0;
			}

			public function begin(): void {}

			public function commit(): void {}

			public function rollback(): void {}
		};

		RudelDatabase::set_store( $store );

		$this->assertSame( $store, RudelDatabase::for_paths( '/tmp/a', '/tmp/b' ) );
	}
}
