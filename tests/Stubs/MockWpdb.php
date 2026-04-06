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
    public string $base_prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';

    /**
     * Registered tables: table_name => ['ddl' => string, 'rows' => array[]]
     */
    private array $tables = [];
    private array $autoIncrement = [];
    private array $transactionSnapshots = [];

    /**
     * Register a table with its MySQL CREATE TABLE DDL and row data.
     */
    public function addTable(string $name, string $ddl, array $rows = []): void
    {
        $this->tables[$name] = [
            'ddl' => $ddl,
            'rows' => $rows,
        ];
        $maxId = 0;
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $maxId = max($maxId, (int) $row['id']);
            }
        }
        $this->autoIncrement[$name] = $maxId;
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

        $rows = $this->selectRows($query);
        if (empty($rows)) {
            return null;
        }

        return $rows[0];

        return null;
    }

    /**
     * Simulate $wpdb->get_results() for SELECT queries.
     */
    public function get_results(string $query, $output = null): array
    {
        // SELECT * FROM table LIMIT N OFFSET N
        if (preg_match('/SELECT \* FROM `?(\w+)`?\s+LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $query, $m)) {
            $table = $m[1];
            $limit = (int) $m[2];
            $offset = (int) $m[3];

            if (! isset($this->tables[$table])) {
                return [];
            }

            return array_slice($this->tables[$table]['rows'], $offset, $limit);
        }

        // SELECT `pk`, `col` FROM table WHERE `col` LIKE '%search%'
        if (preg_match('/SELECT `(\w+)`, `(\w+)` FROM `(\w+)` WHERE `\w+` LIKE/i', $query, $m)) {
            $pk = $m[1];
            $col = $m[2];
            $table = $m[3];

            if (! isset($this->tables[$table])) {
                return [];
            }

            // Extract the LIKE pattern value.
            if (preg_match("/LIKE '([^']+)'/", $query, $lm)) {
                $search = str_replace(['%', '_'], ['', ''], stripslashes($lm[1]));
                $results = [];
                foreach ($this->tables[$table]['rows'] as $row) {
                    if (isset($row[$col]) && str_contains((string) $row[$col], $search)) {
                        $results[] = [$pk => $row[$pk], $col => $row[$col]];
                    }
                }
                return $results;
            }

            return [];
        }

        // SELECT COUNT(*) FROM table
        if (preg_match('/SELECT COUNT\(\*\) FROM `?(\w+)`?/i', $query, $m)) {
            $table = $m[1];
            return isset($this->tables[$table]) ? [['COUNT(*)' => count($this->tables[$table]['rows'])]] : [['COUNT(*)' => 0]];
        }

        return $this->selectRows($query);
    }

    /**
     * Simulate $wpdb->get_var() for SHOW TABLES LIKE and COUNT queries.
     */
    public function get_var(string $query)
    {
        // SHOW TABLES LIKE 'pattern'
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $query, $m)) {
            $like = stripslashes($m[1]);
            $pattern = str_replace(['\\%', '\\_', '%', '_'], ['%', '_', '.*', '.'], $like);
            $pattern = '/^' . $pattern . '$/i';
            foreach (array_keys($this->tables) as $t) {
                if (preg_match($pattern, $t)) {
                    return $t;
                }
            }
            return null;
        }

        // SELECT COUNT(*) FROM table
        if (preg_match('/SELECT COUNT\(\*\) FROM `?(\w+)`?/i', $query, $m)) {
            $table = $m[1];
            return isset($this->tables[$table]) ? (string) count($this->tables[$table]['rows']) : '0';
        }

        $output = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $row = $this->get_row($query, $output);
        if (is_array($row)) {
            $value = reset($row);
            return false === $value ? null : $value;
        }

        return null;
    }

    /**
     * Simulate $wpdb->query() for DDL and DML statements.
     * Tracks executed queries for assertion.
     */
    public array $queriesExecuted = [];

    public function query(string $query): bool
    {
        $this->queriesExecuted[] = $query;

        if (preg_match('/^START TRANSACTION$/i', trim($query))) {
            $this->transactionSnapshots[] = [
                'tables' => $this->tables,
                'autoIncrement' => $this->autoIncrement,
                'insert_id' => $this->insert_id,
                'last_error' => $this->last_error,
            ];
            return true;
        }

        if (preg_match('/^COMMIT$/i', trim($query))) {
            array_pop($this->transactionSnapshots);
            return true;
        }

        if (preg_match('/^ROLLBACK$/i', trim($query))) {
            $snapshot = array_pop($this->transactionSnapshots);
            if (is_array($snapshot)) {
                $this->tables = $snapshot['tables'];
                $this->autoIncrement = $snapshot['autoIncrement'];
                $this->insert_id = $snapshot['insert_id'];
                $this->last_error = $snapshot['last_error'];
            }
            return true;
        }

        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', $query, $m)) {
            $table = $m[1];
            if (! isset($this->tables[$table])) {
                $this->tables[$table] = [
                    'ddl' => $query,
                    'rows' => [],
                ];
                $this->autoIncrement[$table] = 0;
            }
            return true;
        }

        // CREATE TABLE target LIKE source
        if (preg_match('/CREATE TABLE `(\w+)` LIKE `(\w+)`/i', $query, $m)) {
            $target = $m[1];
            $source = $m[2];
            if (isset($this->tables[$source])) {
                $this->tables[$target] = [
                    'ddl' => str_replace($source, $target, $this->tables[$source]['ddl']),
                    'rows' => [],
                ];
                $this->autoIncrement[$target] = 0;
            }
            return true;
        }

        if (preg_match('/TRUNCATE TABLE `?(\w+)`?/i', $query, $m)) {
            $table = $m[1];
            if (isset($this->tables[$table])) {
                $this->tables[$table]['rows'] = [];
                $this->autoIncrement[$table] = 0;
            }
            return true;
        }

        // INSERT INTO target SELECT * FROM source
        if (preg_match('/INSERT INTO `(\w+)` SELECT \* FROM `(\w+)`/i', $query, $m)) {
            $target = $m[1];
            $source = $m[2];
            if (isset($this->tables[$source]) && isset($this->tables[$target])) {
                $this->tables[$target]['rows'] = $this->tables[$source]['rows'];
            }
            return true;
        }

        // DROP TABLE IF EXISTS
        if (preg_match('/DROP TABLE IF EXISTS `(\w+)`/i', $query, $m)) {
            unset($this->tables[$m[1]]);
            unset($this->autoIncrement[$m[1]]);
            return true;
        }

        if (preg_match('/DELETE FROM\s+`?(\w+)`?\s+WHERE\s+(.+)$/i', trim($query), $m)) {
            $table = $m[1];
            $conditions = $this->parseWhereClause($m[2]);
            if (! isset($this->tables[$table])) {
                return true;
            }
            $this->tables[$table]['rows'] = array_values(array_filter(
                $this->tables[$table]['rows'],
                fn(array $row): bool => ! $this->rowMatches($row, $conditions)
            ));
            return true;
        }

        // UPDATE with REPLACE (URL rewriting)
        if (preg_match('/UPDATE `(\w+)` SET `(\w+)` = REPLACE\(`\w+`,/i', $query, $m)) {
            $table = $m[1];
            $col = $m[2];
            if (isset($this->tables[$table])) {
                // Extract search and replace values from the prepared query.
                if (preg_match_all("/'([^']*)'/", $query, $vals)) {
                    $search = stripslashes($vals[1][0] ?? '');
                    $replace = stripslashes($vals[1][1] ?? '');
                    if ($search !== '' && $replace !== '') {
                        foreach ($this->tables[$table]['rows'] as &$row) {
                            if (isset($row[$col])) {
                                $row[$col] = str_replace($search, $replace, $row[$col]);
                            }
                        }
                        unset($row);
                    }
                }
            }
            return true;
        }

        // UPDATE single row (URL rewrite for serialized data)
        if (preg_match('/UPDATE `(\w+)` SET `(\w+)` = \'(.*?)\' WHERE `(\w+)` = \'(.*?)\'/s', $query, $m)) {
            $table = $m[1];
            $col = $m[2];
            $newVal = stripslashes($m[3]);
            $pkCol = $m[4];
            $pkVal = stripslashes($m[5]);
            if (isset($this->tables[$table])) {
                foreach ($this->tables[$table]['rows'] as &$row) {
                    if (isset($row[$pkCol]) && (string) $row[$pkCol] === $pkVal) {
                        $row[$col] = $newVal;
                    }
                }
                unset($row);
            }
            return true;
        }

        if (preg_match('/UPDATE\s+`?(\w+)`?\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', trim($query), $m)) {
            $table = $m[1];
            $assignments = $this->parseAssignments($m[2]);
            $conditions = $this->parseWhereClause($m[3]);
            if (! isset($this->tables[$table])) {
                return true;
            }

            foreach ($this->tables[$table]['rows'] as &$row) {
                if ($this->rowMatches($row, $conditions)) {
                    foreach ($assignments as $column => $value) {
                        $row[$column] = $value;
                    }
                }
            }
            unset($row);

            return true;
        }

        return true;
    }

    /**
     * Simulate $wpdb->insert().
     */
    public function insert(string $table, array $data): bool
    {
        $this->last_error = '';

        if (! isset($this->tables[$table])) {
            $this->tables[$table] = ['ddl' => '', 'rows' => []];
            $this->autoIncrement[$table] = 0;
        }

        $conflict = $this->uniqueConflictMessage($table, $data);
        if (null !== $conflict) {
            $this->last_error = $conflict;
            return false;
        }

        if (str_ends_with($table, 'options') && ! array_key_exists('option_id', $data)) {
            $data['option_id'] = ($this->autoIncrement[$table] ?? 0) + 1;
        }

        if (! array_key_exists('id', $data)) {
            $nextId = ($this->autoIncrement[$table] ?? 0) + 1;
            $data['id'] = $nextId;
            $this->autoIncrement[$table] = $nextId;
        } else {
            $this->autoIncrement[$table] = max($this->autoIncrement[$table] ?? 0, (int) $data['id']);
        }
        $this->tables[$table]['rows'][] = $data;
        $this->insert_id = (int) $data['id'];
        return true;
    }

    /**
     * Simulate $wpdb->update().
     */
    public function update(string $table, array $data, array $where)
    {
        if (! isset($this->tables[$table])) {
            return 0;
        }

        $updated = 0;
        foreach ($this->tables[$table]['rows'] as &$row) {
            if ($this->rowMatches($row, $where)) {
                foreach ($data as $column => $value) {
                    $row[$column] = $value;
                }
                $updated++;
            }
        }
        unset($row);

        return $updated;
    }

    /**
     * Simulate $wpdb->delete().
     */
    public function delete(string $table, array $where)
    {
        if (! isset($this->tables[$table])) {
            return 0;
        }

        $before = count($this->tables[$table]['rows']);
        $this->tables[$table]['rows'] = array_values(array_filter(
            $this->tables[$table]['rows'],
            fn(array $row): bool => ! $this->rowMatches($row, $where)
        ));

        return $before - count($this->tables[$table]['rows']);
    }

    /**
     * Mirror the unique indexes that the real Rudel runtime tables enforce.
     */
    private function uniqueConflictMessage(string $table, array $data): ?string
    {
        $rows = $this->tables[$table]['rows'] ?? [];

        foreach ($rows as $row) {
            if (str_ends_with($table, '_environments')) {
                if (
                    (($row['slug'] ?? null) === ($data['slug'] ?? null)) ||
                    (($row['path'] ?? null) === ($data['path'] ?? null))
                ) {
                    return 'Duplicate environment row';
                }
            }

            if (str_ends_with($table, '_apps')) {
                if (
                    (($row['environment_id'] ?? null) === ($data['environment_id'] ?? null)) ||
                    (($row['slug'] ?? null) === ($data['slug'] ?? null))
                ) {
                    return 'Duplicate app row';
                }
            }

            if (str_ends_with($table, '_app_domains')) {
                if (($row['domain'] ?? null) === ($data['domain'] ?? null)) {
                    return 'Duplicate app domain';
                }
            }

            if (str_ends_with($table, '_worktrees')) {
                if (
                    (($row['environment_id'] ?? null) === ($data['environment_id'] ?? null)) &&
                    (($row['content_type'] ?? null) === ($data['content_type'] ?? null)) &&
                    (($row['name'] ?? null) === ($data['name'] ?? null))
                ) {
                    return 'Duplicate worktree row';
                }
            }

            if (str_ends_with($table, '_app_deployments')) {
                if (($row['deployment_key'] ?? null) === ($data['deployment_key'] ?? null)) {
                    return 'Duplicate deployment row';
                }
            }
        }

        return null;
    }

    /**
     * Simulate $wpdb->get_charset_collate().
     */
    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * Get all registered table names.
     */
    public function getTableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Get rows for a table.
     */
    public function getTableRows(string $table): array
    {
        return $this->tables[$table]['rows'] ?? [];
    }

    /**
     * Check if a table exists.
     */
    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    private function selectRows(string $query): array
    {
        $query = trim($query);

        if (str_contains($query, 'INNER JOIN')) {
            return $this->selectJoinedRows($query);
        }

        if (! preg_match('/^SELECT\s+(.+?)\s+FROM\s+`?(\w+)`?(?:\s+WHERE\s+(.+?))?(?:\s+ORDER BY\s+(.+?))?(?:\s+LIMIT\s+(\d+))?$/is', $query, $m)) {
            return [];
        }

        $columns = trim($m[1]);
        $table = $m[2];
        $where = $m[3] ?? null;
        $order = $m[4] ?? null;
        $limit = isset($m[5]) ? (int) $m[5] : null;

        $rows = $this->tables[$table]['rows'] ?? [];

        if (null !== $where && '' !== trim($where)) {
            $conditions = $this->parseWhereClause($where);
            $rows = array_values(array_filter(
                $rows,
                fn(array $row): bool => $this->rowMatches($row, $conditions)
            ));
        }

        if (null !== $order && '' !== trim($order)) {
            $rows = $this->orderRows($rows, $order);
        }

        if (null !== $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ('*' === $columns) {
            return array_values($rows);
        }

        $selected = array_map('trim', explode(',', $columns));

        return array_values(array_map(
            function (array $row) use ($selected): array {
                $result = [];
                foreach ($selected as $column) {
                    $column = trim($column, '` ');
                    $result[$column] = $row[$column] ?? null;
                }
                return $result;
            },
            $rows
        ));
    }

    private function selectJoinedRows(string $query): array
    {
        if (! preg_match("/WHERE\\s+d\\.domain\\s*=\\s*'([^']+)'/i", $query, $m)) {
            return [];
        }

        if (! preg_match('/FROM\s+`?(\w+)`?\s+d\s+INNER JOIN\s+`?(\w+)`?\s+a\s+ON\s+a\.id\s*=\s*d\.app_id\s+INNER JOIN\s+`?(\w+)`?\s+e\s+ON\s+e\.id\s*=\s*a\.environment_id/i', $query, $tables)) {
            return [];
        }

        $domainTable = $tables[1];
        $appsTable = $tables[2];
        $envTable = $tables[3];
        $domain = stripslashes($m[1]);

        foreach ($this->tables[$domainTable]['rows'] ?? [] as $domainRow) {
            if (($domainRow['domain'] ?? null) !== $domain) {
                continue;
            }

            $appId = $domainRow['app_id'] ?? null;
            foreach ($this->tables[$appsTable]['rows'] ?? [] as $appRow) {
                if (($appRow['id'] ?? null) !== $appId) {
                    continue;
                }

                $environmentId = $appRow['environment_id'] ?? null;
                foreach ($this->tables[$envTable]['rows'] ?? [] as $environmentRow) {
                    if (($environmentRow['id'] ?? null) === $environmentId) {
                        return [[
                            'id' => $environmentRow['id'] ?? null,
                            'app_id' => $environmentRow['app_id'] ?? null,
                            'slug' => $environmentRow['slug'] ?? null,
                            'path' => $environmentRow['path'] ?? null,
                            'type' => $environmentRow['type'] ?? null,
                            'engine' => $environmentRow['engine'] ?? null,
                            'multisite' => $environmentRow['multisite'] ?? null,
                            'blog_id' => $environmentRow['blog_id'] ?? null,
                        ]];
                    }
                }
            }
        }

        return [];
    }

    private function parseWhereClause(string $where): array
    {
        $conditions = [];

        foreach (preg_split('/\s+AND\s+/i', trim($where)) ?: [] as $clause) {
            $clause = preg_replace('/\s+LIMIT\s+\d+$/i', '', trim($clause));

            if (preg_match('/`?(\w+)`?\s+IS\s+NULL/i', $clause, $m)) {
                $conditions[$m[1]] = null;
                continue;
            }

            if (preg_match('/`?(\w+)`?\s*=\s*(NULL|\'(?:\\\\\'|[^\'])*\'|-?\d+(?:\.\d+)?)$/i', $clause, $m)) {
                $conditions[$m[1]] = $this->normalizeSqlLiteral($m[2]);
            }
        }

        return $conditions;
    }

    private function parseAssignments(string $assignments): array
    {
        $parsed = [];

        foreach (preg_split('/\s*,\s*/', trim($assignments)) ?: [] as $assignment) {
            if (preg_match('/`?(\w+)`?\s*=\s*(NULL|\'(?:\\\\\'|[^\'])*\'|-?\d+(?:\.\d+)?)$/i', trim($assignment), $m)) {
                $parsed[$m[1]] = $this->normalizeSqlLiteral($m[2]);
            }
        }

        return $parsed;
    }

    private function normalizeSqlLiteral(string $value)
    {
        if ('NULL' === strtoupper($value)) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }

        return stripslashes(trim($value, "'"));
    }

    private function rowMatches(array $row, array $conditions): bool
    {
        foreach ($conditions as $column => $expected) {
            $actual = $row[$column] ?? null;
            if ($actual != $expected) {
                return false;
            }
        }

        return true;
    }

    private function orderRows(array $rows, string $order): array
    {
        $clauses = array_map('trim', explode(',', $order));

        usort(
            $rows,
            function (array $left, array $right) use ($clauses): int {
                foreach ($clauses as $clause) {
                    if (! preg_match('/`?(\w+)`?(?:\s+(ASC|DESC))?/i', $clause, $m)) {
                        continue;
                    }

                    $column = $m[1];
                    $direction = strtoupper($m[2] ?? 'ASC');
                    $leftValue = $left[$column] ?? null;
                    $rightValue = $right[$column] ?? null;

                    if ($leftValue == $rightValue) {
                        continue;
                    }

                    $result = $leftValue <=> $rightValue;
                    return 'DESC' === $direction ? -$result : $result;
                }

                return 0;
            }
        );

        return $rows;
    }
}
