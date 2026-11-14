<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Support;

use PDO;
use PDOException;

/**
 * The slice of $wpdb that WpdbStore uses, on PDO, for the MySQL integration
 * test. prepare() follows $wpdb: %d, %f and %s placeholders, values quoted.
 */
final class PdoWpdb
{
    public string $base_prefix = 'it_';
    public string $prefix = 'it_';
    public string $last_error = '';
    public int $insert_id = 0;
    private bool $suppress = false;

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%[dfs%]/', function (array $m) use (&$i, $args): string {
            if ('%%' === $m[0]) {
                return '%';
            }
            $value = $args[$i++] ?? null;
            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                default => (string) $this->pdo->quote((string) $value),
            };
        }, $query);
    }

    public function query(string $sql): int|bool
    {
        $this->last_error = '';
        try {
            $statement = $this->pdo->query($sql);
            if (false === $statement) {
                return false;
            }
            $count = $statement->rowCount();
            $statement->closeCursor();
            return $count;
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $formats
     */
    public function insert(string $table, array $data, array $formats = []): int|false
    {
        $columns = array_keys($data);
        $values = [];
        foreach (array_values($data) as $i => $value) {
            $values[] = null === $value ? 'NULL' : $this->prepare($formats[$i] ?? '%s', $value);
        }
        $result = $this->query('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')');
        if (false === $result) {
            return false;
        }
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $sql, string $output = 'ARRAY_A'): ?array
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function get_results(string $sql, string $output = 'ARRAY_A'): ?array
    {
        $this->last_error = '';
        try {
            $statement = $this->pdo->query($sql);
            $rows = false === $statement ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
            return array_map(static fn (array $row): array => array_map(
                static fn (mixed $v): mixed => null === $v ? null : (string) $v,
                $row
            ), $rows);
        } catch (PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_var(string $sql): ?string
    {
        $row = $this->get_row($sql);
        return null === $row ? null : (null === ($v = reset($row)) ? null : (string) $v);
    }

    /**
     * @return list<string>
     */
    public function get_col(string $sql, int $index = 0): array
    {
        $out = [];
        foreach ($this->get_results($sql) ?? [] as $row) {
            $out[] = (string) array_values($row)[$index];
        }
        return $out;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $old = $this->suppress;
        $this->suppress = $suppress;
        return $old;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }
}
