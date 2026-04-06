<?php

namespace Rudel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rudel\WpdbStore;

class WpdbStoreTest extends TestCase
{
	public function testInsertRaisesRuntimeExceptionWhenWpdbFails(): void
	{
		$wpdb = new class () {
			public string $prefix = 'wp_';
			public string $base_prefix = 'wp_';
			public int $insert_id = 0;
			public string $last_error = 'Duplicate app domain';

			public function insert(string $table, array $data)
			{
				unset($table, $data);
				return false;
			}

			public function update(string $table, array $data, array $where)
			{
				unset($table, $data, $where);
				return 0;
			}

			public function delete(string $table, array $where)
			{
				unset($table, $where);
				return 0;
			}

			public function prepare(string $query, ...$args)
			{
				unset($args);
				return $query;
			}
		};

		$store = new WpdbStore($wpdb);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Duplicate app domain');

		$store->insert('wp_rudel_app_domains', ['domain' => 'demo.example.test']);
	}

	public function testUpdateRaisesRuntimeExceptionWhenWpdbFails(): void
	{
		$wpdb = new class () {
			public string $prefix = 'wp_';
			public string $base_prefix = 'wp_';
			public int $insert_id = 0;
			public string $last_error = 'Failed to update Rudel runtime rows.';

			public function insert(string $table, array $data)
			{
				unset($table, $data);
				return 1;
			}

			public function update(string $table, array $data, array $where)
			{
				unset($table, $data, $where);
				return false;
			}

			public function delete(string $table, array $where)
			{
				unset($table, $where);
				return 0;
			}

			public function prepare(string $query, ...$args)
			{
				unset($args);
				return $query;
			}
		};

		$store = new WpdbStore($wpdb);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to update Rudel runtime rows.');

		$store->update('wp_rudel_environments', ['name' => 'Demo'], ['id' => 1]);
	}

	public function testDeleteRaisesRuntimeExceptionWhenWpdbFails(): void
	{
		$wpdb = new class () {
			public string $prefix = 'wp_';
			public string $base_prefix = 'wp_';
			public int $insert_id = 0;
			public string $last_error = 'Failed to delete Rudel runtime rows.';

			public function insert(string $table, array $data)
			{
				unset($table, $data);
				return 1;
			}

			public function update(string $table, array $data, array $where)
			{
				unset($table, $data, $where);
				return 1;
			}

			public function delete(string $table, array $where)
			{
				unset($table, $where);
				return false;
			}

			public function prepare(string $query, ...$args)
			{
				unset($args);
				return $query;
			}
		};

		$store = new WpdbStore($wpdb);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Failed to delete Rudel runtime rows.');

		$store->delete('wp_rudel_environments', ['id' => 1]);
	}
}
