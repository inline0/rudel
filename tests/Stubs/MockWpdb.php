<?php
/**
 * Mock wpdb for unit testing database cloning.
 *
 * Simulates a MySQL $wpdb with configurable tables, schemas, and row data.
 * Does not connect to any database; returns preconfigured responses.
 *
 * @package Rudel\Tests
 */

// phpcs:disable WordPress.NamingConventions, Squiz.Classes.ClassFileName, Squiz.Commenting, WordPress.DB

class MockWpdb
{
    public string $prefix = 'wp_';

    /**
     * Registered tables: table_name => ['ddl' => string, 'rows' => array[]]
     */
    private array $tables = [];

    /**
     * Register a table with its MySQL CREATE TABLE DDL and row data.
     */
    public function addTable(string $name, string $ddl, array $rows = []): void
    {
        $this->tables[$name] = [
            'ddl' => $ddl,
            'rows' => $rows,
        ];
    }

    /**
     * Simulate $wpdb->prepare().
     *
     * Does basic sprintf-style replacement for %s, %d, %f placeholders.
     * Good enough for the patterns used by DatabaseCloner.
     */
    public function prepare(string $query, ...$args): string
    {
        if (empty($args)) {
            return $query;
        }

        // Flatten if first arg is an array (older wpdb calling convention).
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $i = 0;
        $result = preg_replace_callback('/%[sdf]/', function ($match) use ($args, &$i) {
            $val = $args[$i] ?? '';
            $i++;
            if ($match[0] === '%d') {
                return (string) (int) $val;
            }
            if ($match[0] === '%f') {
                return (string) (float) $val;
            }
            // %s: quote for SQL
            return "'" . addslashes((string) $val) . "'";
        }, $query);

        return $result;
    }

    /**
     * Simulate $wpdb->esc_like().
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * Simulate $wpdb->get_col() for SHOW TABLES LIKE queries.
     */
    public function get_col(string $query): array
    {
        // Extract the LIKE pattern.
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $query, $m)) {
            // Undo SQL string escaping (addslashes doubles backslashes).
            $like = stripslashes($m[1]);
            // Convert MySQL LIKE to regex: escaped wildcards first, then wildcards.
            $pattern = str_replace(['\\%', '\\_', '%', '_'], ['%', '_', '.*', '.'], $like);
            $pattern = '/^' . $pattern . '$/i';
            return array_values(array_filter(
                array_keys($this->tables),
                fn($t) => preg_match($pattern, $t)
            ));
        }
        return array_keys($this->tables);
    }

    /**
     * Simulate $wpdb->get_row() for SHOW CREATE TABLE queries.
     */
    public function get_row(string $query, $output = null)
    {
        if (preg_match('/SHOW CREATE TABLE `?(\w+)`?/i', $query, $m)) {
            $table = $m[1];
            if (isset($this->tables[$table])) {
                return [$table, $this->tables[$table]['ddl']];
            }
        }
        return null;
    }

    /**
     * Simulate $wpdb->get_results() for SELECT * queries with LIMIT/OFFSET.
     */
    public function get_results(string $query, $output = null): array
    {
        if (preg_match('/SELECT \* FROM `?(\w+)`?\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $query, $m)) {
            $table = $m[1];
            $limit = (int) $m[2];
            $offset = (int) $m[3];

            if (! isset($this->tables[$table])) {
                return [];
            }

            return array_slice($this->tables[$table]['rows'], $offset, $limit);
        }
        return [];
    }
}
