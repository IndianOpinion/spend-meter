<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Store;

use IndianOpinion\SpendMeter\Schema;

/**
 * Ledger storage on $wpdb. Every query goes through prepare(); table and
 * column names are constants in code and never come from input.
 *
 * SQL is kept to what MySQL 5.7+ and MariaDB 10.4+ both accept.
 */
final class WpdbStore implements Store
{
    private const AMOUNT = "(CASE WHEN status = 'reserved' THEN (CASE WHEN created_at >= %s THEN estimate ELSE 0 END)"
        . " WHEN status IN ('ok','error_billed','error_unbilled') THEN cost ELSE 0 END)";

    private string $table;

    /**
     * @param \wpdb $db
     */
    public function __construct(private object $db)
    {
        $this->table = $db->base_prefix . Schema::TABLE;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function insert(array $row): int
    {
        $data = [];
        $formats = [];
        foreach ($row as $column => $value) {
            if (!isset(Schema::COLUMNS[$column]) || 'id' === $column) {
                continue;
            }
            $data[$column] = $value;
            $formats[] = Schema::COLUMNS[$column];
        }
        $result = $this->db->insert($this->table, $data, $formats);
        if (false === $result) {
            throw new StoreException('insert failed: ' . $this->lastError());
        }

        return (int) $this->db->insert_id;
    }

    public function updateIf(int $id, array $fields, string $column, array $allowed): bool
    {
        if (!isset(Schema::COLUMNS[$column]) || [] === $allowed || [] === $fields) {
            return false;
        }
        $sets = [];
        $args = [];
        foreach ($fields as $name => $value) {
            if (!isset(Schema::COLUMNS[$name]) || 'id' === $name) {
                continue;
            }
            if (null === $value) {
                $sets[] = $name . ' = NULL';
                continue;
            }
            $sets[] = $name . ' = ' . Schema::COLUMNS[$name];
            $args[] = $value;
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets)
            . ' WHERE id = %d AND ' . $column . ' IN (' . $placeholders . ')';
        $result = $this->db->query($this->prepare($sql, ...array_merge($args, [$id], $allowed)));
        if (false === $result) {
            throw new StoreException('update failed: ' . $this->lastError());
        }

        return (int) $result > 0;
    }

    public function delete(int $id): void
    {
        $result = $this->db->query($this->prepare('DELETE FROM ' . $this->table . ' WHERE id = %d', $id));
        if (false === $result) {
            throw new StoreException('delete failed: ' . $this->lastError());
        }
    }

    public function find(int $id): ?array
    {
        return $this->row($this->prepare('SELECT * FROM ' . $this->table . ' WHERE id = %d', $id));
    }

    public function findByCallId(string $callId): ?array
    {
        return $this->row($this->prepare('SELECT * FROM ' . $this->table . ' WHERE call_id = %s', $callId));
    }

    public function findByReservationId(string $reservationId): ?array
    {
        return $this->row($this->prepare(
            'SELECT * FROM ' . $this->table . ' WHERE reservation_id = %s AND parent_id IS NULL ORDER BY id LIMIT 1',
            $reservationId
        ));
    }

    public function windowTotals(int $blogId, string $day, string $month, array $patterns, string $liveSince): array
    {
        $select = ['SUM(CASE WHEN day = %s THEN a ELSE 0 END) AS site_day', 'SUM(a) AS site_month'];
        $args = [$day];
        foreach (array_values($patterns) as $i => $pattern) {
            [$condition, $value] = $this->purposeCondition($pattern);
            $select[] = 'SUM(CASE WHEN day = %s AND ' . $condition . ' THEN a ELSE 0 END) AS p' . $i . 'd';
            $select[] = 'SUM(CASE WHEN ' . $condition . ' THEN a ELSE 0 END) AS p' . $i . 'm';
            array_push($args, $day, $value, $value);
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM (SELECT day, purpose, ' . self::AMOUNT . ' AS a FROM '
            . $this->table . ' WHERE blog_id = %d AND month = %s AND is_test = 0) x';
        array_push($args, $liveSince, $blogId, $month);
        $row = $this->row($this->prepare($sql, ...$args)) ?? [];
        $out = ['site_day' => (int) ($row['site_day'] ?? 0), 'site_month' => (int) ($row['site_month'] ?? 0), 'purposes' => []];
        foreach (array_values($patterns) as $i => $pattern) {
            $out['purposes'][$pattern] = [
                'day' => (int) ($row['p' . $i . 'd'] ?? 0),
                'month' => (int) ($row['p' . $i . 'm'] ?? 0),
            ];
        }

        return $out;
    }

    public function networkTotals(string $day, string $month, string $liveSince): array
    {
        $sql = 'SELECT SUM(CASE WHEN day = %s THEN a ELSE 0 END) AS d, SUM(a) AS m FROM (SELECT day, '
            . self::AMOUNT . ' AS a FROM ' . $this->table . ' WHERE month = %s AND is_test = 0) x';
        $row = $this->row($this->prepare($sql, $day, $liveSince, $month)) ?? [];

        return ['day' => (int) ($row['d'] ?? 0), 'month' => (int) ($row['m'] ?? 0)];
    }

    public function expireReservations(string $before): int
    {
        $result = $this->db->query($this->prepare(
            "UPDATE {$this->table} SET status = 'expired' WHERE status = 'reserved' AND created_at < %s",
            $before
        ));
        if (false === $result) {
            throw new StoreException('expire failed: ' . $this->lastError());
        }

        return (int) $result;
    }

    public function reservations(int $limit): array
    {
        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE status = 'reserved' ORDER BY created_at LIMIT %d",
            $limit
        ));
    }

    public function summary(?int $blogId, string $day, string $month, string $liveSince, string $oldBefore): array
    {
        $sql = 'SELECT SUM(CASE WHEN day = %s THEN a ELSE 0 END) AS day_total, SUM(a) AS month_total,'
            . " SUM(CASE WHEN day = %s AND cost_source = 'unpriced' AND status IN ('ok','error_billed') THEN 1 ELSE 0 END) AS unpriced_today,"
            . ' SUM(CASE WHEN day = %s AND (flags & 1) = 1 THEN 1 ELSE 0 END) AS unreserved_today,'
            . " SUM(CASE WHEN status = 'reserved' AND created_at >= %s THEN 1 ELSE 0 END) AS live_reservations,"
            . " SUM(CASE WHEN status = 'reserved' AND created_at >= %s AND created_at < %s THEN 1 ELSE 0 END) AS old_reservations"
            . ' FROM (SELECT day, status, created_at, cost_source, flags, ' . self::AMOUNT . ' AS a FROM ' . $this->table
            . ' WHERE month = %s AND is_test = 0' . (null === $blogId ? '' : ' AND blog_id = %d') . ') x';
        $args = [$day, $day, $day, $liveSince, $liveSince, $oldBefore, $liveSince, $month];
        if (null !== $blogId) {
            $args[] = $blogId;
        }
        $row = $this->row($this->prepare($sql, ...$args)) ?? [];
        $keys = ['day_total', 'month_total', 'unpriced_today', 'unreserved_today', 'live_reservations', 'old_reservations'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        /** @var array{day_total: int, month_total: int, unpriced_today: int, unreserved_today: int, live_reservations: int, old_reservations: int} */
        return $out;
    }

    public function topPurposes(?int $blogId, string $day, string $month, string $liveSince, int $limit): array
    {
        $sql = 'SELECT purpose, SUM(' . self::AMOUNT . ') AS total FROM ' . $this->table
            . ' WHERE day = %s AND is_test = 0' . (null === $blogId ? '' : ' AND blog_id = %d')
            . ' GROUP BY purpose ORDER BY total DESC, purpose ASC LIMIT %d';
        $args = [$liveSince, $day];
        if (null !== $blogId) {
            $args[] = $blogId;
        }
        $args[] = $limit;
        $out = [];
        foreach ($this->rows($this->prepare($sql, ...$args)) as $row) {
            $out[] = ['purpose' => (string) $row['purpose'], 'total' => (int) $row['total']];
        }

        return $out;
    }

    public function lastAccountError(?int $blogId, string $since): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE status = 'error_unbilled' AND created_at >= %s"
            . ' AND http_status IN (401,402,403)' . (null === $blogId ? '' : ' AND blog_id = %d')
            . ' ORDER BY created_at DESC, id DESC LIMIT 1';
        $args = [$since];
        if (null !== $blogId) {
            $args[] = $blogId;
        }

        return $this->row($this->prepare($sql, ...$args));
    }

    public function perSite(string $day, string $month, string $liveSince): array
    {
        $sql = 'SELECT blog_id, SUM(CASE WHEN day = %s THEN a ELSE 0 END) AS d, SUM(a) AS m FROM (SELECT blog_id, day, '
            . self::AMOUNT . ' AS a FROM ' . $this->table . ' WHERE month = %s AND is_test = 0) x GROUP BY blog_id ORDER BY blog_id';
        $out = [];
        foreach ($this->rows($this->prepare($sql, $day, $liveSince, $month)) as $row) {
            $out[(int) $row['blog_id']] = ['day' => (int) $row['d'], 'month' => (int) $row['m']];
        }

        return $out;
    }

    public function report(string $from, string $to, string $by, bool $includeTests, ?int $blogId): array
    {
        $columns = ['purpose', 'model', 'caller', 'provider', 'day'];
        if (!in_array($by, $columns, true)) {
            throw new StoreException('invalid report grouping');
        }
        $sql = 'SELECT ' . $by . ' AS k, COUNT(*) AS calls, SUM(input_tokens) AS input_tokens,'
            . ' SUM(cached_input_tokens) AS cached_input_tokens, SUM(output_tokens) AS output_tokens,'
            . ' SUM(reasoning_tokens) AS reasoning_tokens, SUM(cost) AS cost FROM ' . $this->table
            . " WHERE day >= %s AND day <= %s AND status IN ('ok','error_billed','error_unbilled')"
            . ($includeTests ? '' : ' AND is_test = 0') . (null === $blogId ? '' : ' AND blog_id = %d')
            . ' GROUP BY ' . $by . ' ORDER BY cost DESC, k ASC';
        $args = [$from, $to];
        if (null !== $blogId) {
            $args[] = $blogId;
        }
        $out = [];
        foreach ($this->rows($this->prepare($sql, ...$args)) as $row) {
            $out[] = [
                'key' => (string) $row['k'],
                'calls' => (int) $row['calls'],
                'input_tokens' => (int) $row['input_tokens'],
                'cached_input_tokens' => (int) $row['cached_input_tokens'],
                'output_tokens' => (int) $row['output_tokens'],
                'reasoning_tokens' => (int) $row['reasoning_tokens'],
                'cost' => (int) $row['cost'],
            ];
        }

        return $out;
    }

    public function rowsBetween(string $from, string $to, int $afterId, int $limit): array
    {
        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE day >= %s AND day <= %s AND id > %d ORDER BY id LIMIT %d",
            $from,
            $to,
            $afterId,
            $limit
        ));
    }

    public function unpricedRows(string $from, string $to, int $afterId, int $limit): array
    {
        return $this->rows($this->prepare(
            "SELECT * FROM {$this->table} WHERE cost_source = 'unpriced' AND status IN ('ok','error_billed')"
            . ' AND day >= %s AND day <= %s AND id > %d ORDER BY id LIMIT %d',
            $from,
            $to,
            $afterId,
            $limit
        ));
    }

    public function unpricedModels(string $since): array
    {
        $out = [];
        $rows = $this->rows($this->prepare(
            "SELECT provider, model, COUNT(*) AS calls FROM {$this->table} WHERE cost_source = 'unpriced'"
            . " AND status IN ('ok','error_billed') AND day >= %s GROUP BY provider, model ORDER BY calls DESC",
            $since
        ));
        foreach ($rows as $row) {
            $out[] = ['provider' => (string) $row['provider'], 'model' => (string) $row['model'], 'calls' => (int) $row['calls']];
        }

        return $out;
    }

    public function idsSettledBefore(string $before, int $limit): array
    {
        $ids = $this->db->get_col($this->prepare(
            "SELECT id FROM {$this->table} WHERE created_at < %s AND status <> 'reserved' ORDER BY id LIMIT %d",
            $before,
            $limit
        ));
        $this->check();

        return array_values(array_map('intval', (array) $ids));
    }

    public function countSettledBefore(string $before): int
    {
        $count = $this->db->get_var($this->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE created_at < %s AND status <> 'reserved'",
            $before
        ));
        $this->check();

        return (int) $count;
    }

    public function deleteIds(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $this->db->query($this->prepare(
            'DELETE FROM ' . $this->table . ' WHERE id IN (' . $placeholders . ')',
            ...$ids
        ));
        if (false === $result) {
            throw new StoreException('delete failed: ' . $this->lastError());
        }

        return (int) $result;
    }

    public function hasRows(): bool
    {
        $value = $this->db->get_var('SELECT id FROM ' . $this->table . ' LIMIT 1');
        $this->check();

        return null !== $value;
    }

    public function missingColumns(): array
    {
        $suppress = $this->db->suppress_errors(true);
        $columns = $this->db->get_col('SHOW COLUMNS FROM ' . $this->table, 0);
        $this->db->suppress_errors($suppress);
        $present = array_map('strtolower', array_map('strval', (array) $columns));

        return array_values(array_diff(array_keys(Schema::COLUMNS), $present));
    }

    public function install(): void
    {
        if (!function_exists('dbDelta')) {
            // @phpstan-ignore requireOnce.fileNotFound (part of WordPress, not of this package)
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(Schema::createSql($this->table, $this->db->get_charset_collate()));
    }

    public function drop(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->table);
    }

    /**
     * Condition and value for one purpose slug or prefix pattern.
     *
     * @return array{0: string, 1: string}
     */
    private function purposeCondition(string $pattern): array
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = strtr(substr($pattern, 0, -1), ['!' => '!!', '%' => '!%', '_' => '!_']);
            return ["purpose LIKE %s ESCAPE '!'", $prefix . '%'];
        }

        return ['purpose = %s', $pattern];
    }

    /**
     * $wpdb->prepare() with a failure check. The table name in the query is
     * a constant built in the constructor; every value is a placeholder.
     */
    private function prepare(string $sql, mixed ...$args): string
    {
        // @phpstan-ignore argument.type (the table name is not a literal, but it never comes from input)
        $prepared = $this->db->prepare($sql, ...$args);
        if (!is_string($prepared) || '' === $prepared) {
            throw new StoreException('query could not be prepared');
        }

        return $prepared;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $sql): ?array
    {
        $row = $this->db->get_row($sql, ARRAY_A);
        $this->check();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function check(): void
    {
        if ('' !== $this->lastError()) {
            throw new StoreException('query failed: ' . $this->lastError());
        }
    }

    private function lastError(): string
    {
        return (string) $this->db->last_error;
    }
}
