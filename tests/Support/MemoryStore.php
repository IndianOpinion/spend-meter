<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Support;

use Closure;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\Store\Store;
use IndianOpinion\SpendMeter\Store\StoreException;

/**
 * In-memory ledger double with the same contract as WpdbStore. Values come
 * back as strings, the way $wpdb returns them, so casting bugs show up here.
 * Failures can be injected, and a callback can run between the reservation
 * insert and the totals read to interleave two checks for real.
 */
final class MemoryStore implements Store
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $nextId = 1;
    public bool $failWrites = false;
    public bool $failReads = false;
    public bool $failInsertOnce = false;
    public int $queries = 0;
    /** @var list<string> */
    public array $missing = [];
    public bool $installed = true;
    public bool $dropped = false;
    /** @var Closure(): void|null */
    public ?Closure $afterInsert = null;
    /** @var Closure(): void|null Runs once at the start of the next updateIf(). */
    public ?Closure $beforeUpdate = null;

    public function insert(array $row): int
    {
        $this->queries++;
        if ($this->failWrites || $this->failInsertOnce) {
            $this->failInsertOnce = false;
            throw new StoreException('injected write failure');
        }
        foreach ($this->rows as $existing) {
            if ($existing['call_id'] === $row['call_id']) {
                throw new StoreException('duplicate call_id');
            }
        }
        $id = $this->nextId++;
        $full = ['id' => $id];
        foreach (array_keys(Schema::COLUMNS) as $column) {
            if ('id' !== $column) {
                $full[$column] = $row[$column] ?? null;
            }
        }
        $this->rows[$id] = $full;
        if (null !== $this->afterInsert) {
            $callback = $this->afterInsert;
            $this->afterInsert = null;
            $callback();
        }

        return $id;
    }

    public function updateIf(int $id, array $fields, string $column, array $allowed): bool
    {
        if (null !== $this->beforeUpdate) {
            $callback = $this->beforeUpdate;
            $this->beforeUpdate = null;
            $callback();
        }
        $this->queries++;
        if ($this->failWrites) {
            throw new StoreException('injected write failure');
        }
        if (!isset($this->rows[$id]) || !in_array((string) $this->rows[$id][$column], $allowed, true)) {
            return false;
        }
        foreach ($fields as $name => $value) {
            if (isset(Schema::COLUMNS[$name]) && 'id' !== $name) {
                $this->rows[$id][$name] = $value;
            }
        }

        return true;
    }

    public function delete(int $id): void
    {
        $this->queries++;
        if ($this->failWrites) {
            throw new StoreException('injected write failure');
        }
        unset($this->rows[$id]);
    }

    public function find(int $id): ?array
    {
        $this->read();
        return isset($this->rows[$id]) ? self::out($this->rows[$id]) : null;
    }

    public function findByCallId(string $callId): ?array
    {
        $this->read();
        foreach ($this->rows as $row) {
            if ($row['call_id'] === $callId) {
                return self::out($row);
            }
        }
        return null;
    }

    public function findByReservationId(string $reservationId): ?array
    {
        $this->read();
        foreach ($this->rows as $row) {
            if ($row['reservation_id'] === $reservationId && null === $row['parent_id']) {
                return self::out($row);
            }
        }
        return null;
    }

    public function windowTotals(int $blogId, string $day, string $month, array $patterns, string $liveSince): array
    {
        $this->read();
        $out = ['site_day' => 0, 'site_month' => 0, 'purposes' => []];
        foreach ($patterns as $pattern) {
            $out['purposes'][$pattern] = ['day' => 0, 'month' => 0];
        }
        foreach ($this->rows as $row) {
            if ((int) $row['blog_id'] !== $blogId || $row['month'] !== $month || 1 === (int) $row['is_test']) {
                continue;
            }
            $a = self::amount($row, $liveSince);
            $out['site_month'] += $a;
            if ($row['day'] === $day) {
                $out['site_day'] += $a;
            }
            foreach ($patterns as $pattern) {
                if (self::purposeMatches($pattern, (string) $row['purpose'])) {
                    $out['purposes'][$pattern]['month'] += $a;
                    if ($row['day'] === $day) {
                        $out['purposes'][$pattern]['day'] += $a;
                    }
                }
            }
        }
        return $out;
    }

    public function networkTotals(string $day, string $month, string $liveSince): array
    {
        $this->read();
        $out = ['day' => 0, 'month' => 0];
        foreach ($this->rows as $row) {
            if ($row['month'] !== $month || 1 === (int) $row['is_test']) {
                continue;
            }
            $a = self::amount($row, $liveSince);
            $out['month'] += $a;
            if ($row['day'] === $day) {
                $out['day'] += $a;
            }
        }
        return $out;
    }

    public function expireReservations(string $before): int
    {
        $this->queries++;
        $count = 0;
        foreach ($this->rows as $id => $row) {
            if ('reserved' === $row['status'] && $row['created_at'] < $before) {
                $this->rows[$id]['status'] = 'expired';
                $count++;
            }
        }
        return $count;
    }

    public function reservations(int $limit): array
    {
        $this->read();
        $out = [];
        foreach ($this->rows as $row) {
            if ('reserved' === $row['status']) {
                $out[] = self::out($row);
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function summary(?int $blogId, string $day, string $month, string $liveSince, string $oldBefore): array
    {
        $this->read();
        $out = ['day_total' => 0, 'month_total' => 0, 'unpriced_today' => 0, 'unreserved_today' => 0,
            'live_reservations' => 0, 'old_reservations' => 0];
        foreach ($this->rows as $row) {
            if ($row['month'] !== $month || 1 === (int) $row['is_test'] || (null !== $blogId && (int) $row['blog_id'] !== $blogId)) {
                continue;
            }
            $a = self::amount($row, $liveSince);
            $out['month_total'] += $a;
            if ($row['day'] === $day) {
                $out['day_total'] += $a;
                if ('unpriced' === $row['cost_source'] && in_array($row['status'], ['ok', 'error_billed'], true)) {
                    $out['unpriced_today']++;
                }
                if (1 === ((int) $row['flags'] & Schema::FLAG_UNRESERVED)) {
                    $out['unreserved_today']++;
                }
            }
            if ('reserved' === $row['status'] && $row['created_at'] >= $liveSince) {
                $out['live_reservations']++;
                if ($row['created_at'] < $oldBefore) {
                    $out['old_reservations']++;
                }
            }
        }
        return $out;
    }

    public function topPurposes(?int $blogId, string $day, string $month, string $liveSince, int $limit): array
    {
        $this->read();
        $totals = [];
        foreach ($this->rows as $row) {
            if ($row['day'] !== $day || 1 === (int) $row['is_test'] || (null !== $blogId && (int) $row['blog_id'] !== $blogId)) {
                continue;
            }
            $totals[(string) $row['purpose']] = ($totals[(string) $row['purpose']] ?? 0) + self::amount($row, $liveSince);
        }
        uksort($totals, static fn (string $a, string $b): int => [$totals[$b], $a] <=> [$totals[$a], $b]);
        $out = [];
        foreach (array_slice($totals, 0, $limit, true) as $purpose => $total) {
            $out[] = ['purpose' => (string) $purpose, 'total' => $total];
        }
        return $out;
    }

    public function lastAccountError(?int $blogId, string $since): ?array
    {
        $this->read();
        $found = null;
        foreach ($this->rows as $row) {
            if (
                'error_unbilled' === $row['status'] && $row['created_at'] >= $since
                && in_array((int) $row['http_status'], [401, 402, 403], true)
                && (null === $blogId || (int) $row['blog_id'] === $blogId)
            ) {
                $found = $row;
            }
        }
        return null === $found ? null : self::out($found);
    }

    public function perSite(string $day, string $month, string $liveSince): array
    {
        $this->read();
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['month'] !== $month || 1 === (int) $row['is_test']) {
                continue;
            }
            $blog = (int) $row['blog_id'];
            $out[$blog] ??= ['day' => 0, 'month' => 0];
            $a = self::amount($row, $liveSince);
            $out[$blog]['month'] += $a;
            if ($row['day'] === $day) {
                $out[$blog]['day'] += $a;
            }
        }
        ksort($out);
        return $out;
    }

    public function report(string $from, string $to, string $by, bool $includeTests, ?int $blogId): array
    {
        $this->read();
        if (!in_array($by, ['purpose', 'model', 'caller', 'provider', 'day'], true)) {
            throw new StoreException('invalid report grouping');
        }
        $groups = [];
        foreach ($this->rows as $row) {
            if (
                $row['day'] < $from || $row['day'] > $to || !in_array($row['status'], Schema::SETTLED, true)
                || (!$includeTests && 1 === (int) $row['is_test']) || (null !== $blogId && (int) $row['blog_id'] !== $blogId)
            ) {
                continue;
            }
            $key = (string) $row[$by];
            $groups[$key] ??= ['key' => $key, 'calls' => 0, 'input_tokens' => 0, 'cached_input_tokens' => 0,
                'output_tokens' => 0, 'reasoning_tokens' => 0, 'cost' => 0];
            $groups[$key]['calls']++;
            foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'cost'] as $col) {
                $groups[$key][$col] += (int) $row[$col];
            }
        }
        $out = array_values($groups);
        usort($out, static fn (array $a, array $b): int => [$b['cost'], $a['key']] <=> [$a['cost'], $b['key']]);
        return $out;
    }

    public function rowsBetween(string $from, string $to, int $afterId, int $limit): array
    {
        $this->read();
        $out = [];
        foreach ($this->rows as $id => $row) {
            if ($id > $afterId && $row['day'] >= $from && $row['day'] <= $to) {
                $out[] = self::out($row);
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function unpricedRows(string $from, string $to, int $afterId, int $limit): array
    {
        $this->read();
        $out = [];
        foreach ($this->rows as $id => $row) {
            if (
                $id > $afterId && 'unpriced' === $row['cost_source'] && in_array($row['status'], ['ok', 'error_billed'], true)
                && $row['day'] >= $from && $row['day'] <= $to
            ) {
                $out[] = self::out($row);
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function unpricedModels(string $since): array
    {
        $this->read();
        $groups = [];
        foreach ($this->rows as $row) {
            if ('unpriced' === $row['cost_source'] && in_array($row['status'], ['ok', 'error_billed'], true) && $row['day'] >= $since) {
                $key = $row['provider'] . '|' . $row['model'];
                $groups[$key] ??= ['provider' => (string) $row['provider'], 'model' => (string) $row['model'], 'calls' => 0];
                $groups[$key]['calls']++;
            }
        }
        return array_values($groups);
    }

    public function idsSettledBefore(string $before, int $limit): array
    {
        $this->read();
        $out = [];
        foreach ($this->rows as $id => $row) {
            if ($row['created_at'] < $before && 'reserved' !== $row['status']) {
                $out[] = $id;
            }
        }
        return array_slice($out, 0, $limit);
    }

    public function countSettledBefore(string $before): int
    {
        $this->read();
        $count = 0;
        foreach ($this->rows as $row) {
            if ($row['created_at'] < $before && 'reserved' !== $row['status']) {
                $count++;
            }
        }
        return $count;
    }

    public function deleteIds(array $ids): int
    {
        $this->queries++;
        $count = 0;
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                unset($this->rows[$id]);
                $count++;
            }
        }
        return $count;
    }

    public function hasRows(): bool
    {
        $this->read();
        return [] !== $this->rows;
    }

    public function missingColumns(): array
    {
        return $this->installed ? $this->missing : array_keys(Schema::COLUMNS);
    }

    public function install(): void
    {
        $this->installed = true;
    }

    public function drop(): void
    {
        $this->dropped = true;
        $this->rows = [];
    }

    /**
     * Rows as stored, for assertions (native types).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->rows);
    }

    private function read(): void
    {
        $this->queries++;
        if ($this->failReads) {
            throw new StoreException('injected read failure');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function amount(array $row, string $liveSince): int
    {
        if ('reserved' === $row['status']) {
            return $row['created_at'] >= $liveSince ? (int) $row['estimate'] : 0;
        }
        return in_array($row['status'], Schema::SETTLED, true) ? (int) $row['cost'] : 0;
    }

    private static function purposeMatches(string $pattern, string $purpose): bool
    {
        return str_ends_with($pattern, '*') ? str_starts_with($purpose, substr($pattern, 0, -1)) : $pattern === $purpose;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function out(array $row): array
    {
        return array_map(static fn (mixed $v): mixed => null === $v ? null : (string) $v, $row);
    }
}
