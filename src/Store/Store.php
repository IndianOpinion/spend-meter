<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Store;

/**
 * Ledger storage. The plugin uses WpdbStore; the test suite uses an
 * in-memory double with the same contract.
 *
 * Window totals count, for rows with is_test = 0: the cost of settled rows
 * (ok, error_billed, error_unbilled) plus the estimate of live reservations
 * (status reserved, created at or after $liveSince). Released and expired
 * rows count zero.
 *
 * Every method throws StoreException when the table cannot be read or written.
 *
 * @phpstan-type LedgerRow array<string, mixed>
 */
interface Store
{
    /**
     * @param  LedgerRow $row Column => value. Nulls are stored as NULL.
     * @return int The new row id.
     */
    public function insert(array $row): int;

    /**
     * Update one row only while $column holds one of $allowed.
     *
     * @param  LedgerRow $fields
     * @param  list<string> $allowed
     * @return bool True when the row was updated.
     */
    public function updateIf(int $id, array $fields, string $column, array $allowed): bool;

    public function delete(int $id): void;

    /** @return LedgerRow|null */
    public function find(int $id): ?array;

    /** @return LedgerRow|null */
    public function findByCallId(string $callId): ?array;

    /**
     * The reservation row itself (not linked retries).
     *
     * @return LedgerRow|null
     */
    public function findByReservationId(string $reservationId): ?array;

    /**
     * Site and purpose totals for one blog in one aggregate query.
     *
     * @param  list<string> $patterns Purpose slugs or prefix patterns.
     * @return array{site_day: int, site_month: int, purposes: array<string, array{day: int, month: int}>}
     */
    public function windowTotals(int $blogId, string $day, string $month, array $patterns, string $liveSince): array;

    /**
     * @return array{day: int, month: int}
     */
    public function networkTotals(string $day, string $month, string $liveSince): array;

    /** Mark reservations created before $before as expired. Returns the count. */
    public function expireReservations(string $before): int;

    /** @return list<LedgerRow> */
    public function reservations(int $limit): array;

    /**
     * Widget figures for one blog, or the network when $blogId is null.
     *
     * @return array{day_total: int, month_total: int, unpriced_today: int, unreserved_today: int,
     *     live_reservations: int, old_reservations: int}
     */
    public function summary(?int $blogId, string $day, string $month, string $liveSince, string $oldBefore): array;

    /** @return list<array{purpose: string, total: int}> */
    public function topPurposes(?int $blogId, string $day, string $month, string $liveSince, int $limit): array;

    /** @return LedgerRow|null */
    public function lastAccountError(?int $blogId, string $since): ?array;

    /** @return array<int, array{day: int, month: int}> */
    public function perSite(string $day, string $month, string $liveSince): array;

    /**
     * Settled rows grouped by one column.
     *
     * @return list<array{key: string, calls: int, input_tokens: int, cached_input_tokens: int,
     *     output_tokens: int, reasoning_tokens: int, cost: int}>
     */
    public function report(string $from, string $to, string $by, bool $includeTests, ?int $blogId): array;

    /** @return list<LedgerRow> */
    public function rowsBetween(string $from, string $to, int $afterId, int $limit): array;

    /** @return list<LedgerRow> */
    public function unpricedRows(string $from, string $to, int $afterId, int $limit): array;

    /** @return list<array{provider: string, model: string, calls: int}> */
    public function unpricedModels(string $since): array;

    /**
     * Ids of rows that are not live reservations, created before $before.
     *
     * @return list<int>
     */
    public function idsSettledBefore(string $before, int $limit): array;

    public function countSettledBefore(string $before): int;

    /** @param list<int> $ids */
    public function deleteIds(array $ids): int;

    public function hasRows(): bool;

    /** @return list<string> Expected columns that are missing. */
    public function missingColumns(): array;

    public function install(): void;

    public function drop(): void;
}
