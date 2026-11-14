<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use IndianOpinion\SpendMeter\Store\Store;
use IndianOpinion\SpendMeter\Store\StoreException;
use Throwable;

/**
 * Records calls, releases and expires reservations.
 *
 * Recording never throws and never blocks the caller. Money was spent, so a
 * valid record is never dropped: a reservation is settled in place, a retry on
 * a settled reservation adds a linked row, and anything else is inserted with
 * the unreserved flag. On a database error the write is buffered (100 per
 * request) and retried at shutdown; if that fails too, the loss is counted.
 */
final class Ledger
{
    public const BUFFER_LIMIT = 100;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: int, 3: array<string, mixed>|null}> */
    private array $buffer = [];

    private bool $shutdownHooked = false;

    public function __construct(
        private Store $store,
        private Clock $clock,
        private Settings $settings,
        private PriceTable $prices,
        private Caps $caps,
        private Alerts $alerts,
        private Counters $counters
    ) {
    }

    /**
     * Record one attempt.
     *
     * @param  array<mixed> $call
     * @return int Row id; 0 when buffered for a retry; -1 when invalid.
     */
    public function record(array $call): int
    {
        try {
            $clean = Validator::call($call);
        } catch (ValidationException $e) {
            Logger::error('record_invalid', 'record rejected: ' . $e->getMessage());
            return -1;
        } catch (Throwable $e) {
            Logger::error('record_invalid', 'record rejected: ' . $e->getMessage());
            return -1;
        }
        try {
            return $this->write($clean);
        } catch (ValidationException $e) {
            Logger::error('record_invalid', 'record rejected: ' . $e->getMessage());
            return -1;
        } catch (Throwable $e) {
            // Money was spent and nothing could be written: count the loss so
            // the widget shows it.
            Logger::error('record_failed', 'record could not be prepared: ' . $e->getMessage());
            try {
                $this->counters->loss();
            } catch (Throwable) {
                // Nothing more can be done.
            }
            return 0;
        }
    }

    /**
     * Give up a reservation. True when a live reservation was released.
     */
    public function release(string $reservationId): bool
    {
        $reservationId = strtolower($reservationId);
        if (1 !== preg_match(Validator::UUID, $reservationId)) {
            Logger::debug('release ignored: not a UUID');
            return false;
        }
        try {
            $row = $this->store->findByReservationId($reservationId);
            if (null === $row || 'reserved' !== $row['status']) {
                Logger::debug('release ignored: unknown or settled reservation ' . $reservationId);
                return false;
            }
            return $this->store->updateIf((int) $row['id'], ['status' => 'released', 'cost' => 0], 'status', ['reserved']);
        } catch (Throwable $e) {
            Logger::error('release_failed', 'release failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark reservations older than the lifetime as expired.
     */
    public function expire(): int
    {
        return $this->store->expireReservations($this->clock->utc(-$this->settings->reservationSeconds()));
    }

    /**
     * Retry buffered writes. Called at shutdown.
     */
    public function flush(): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];
        $lost = 0;
        foreach ($buffer as [$op, $row, $id, $fallback]) {
            try {
                if ('settle' === $op && $this->store->updateIf($id, $row, 'status', ['reserved', 'expired'])) {
                    $this->recorded($id, $row);
                    continue;
                }
                if ('settle' === $op) {
                    // Settled or released by someone else since: this attempt
                    // still cost money, so it gets its own row.
                    if (null === $fallback) {
                        throw new StoreException('reservation no longer open and no row to insert');
                    }
                    $row = $fallback;
                }
                $existing = $this->store->findByCallId((string) $row['call_id']);
                $newId = null === $existing ? $this->store->insert($row) : (int) $existing['id'];
                $this->recorded($newId, $row);
            } catch (Throwable $e) {
                $lost++;
                Logger::error('record_loss', 'ledger write lost after retry: ' . $e->getMessage());
            }
        }
        if ($lost > 0) {
            try {
                $this->counters->loss($lost);
            } catch (Throwable) {
                // Nothing more can be done without a database.
            }
        }
    }

    public function buffered(): int
    {
        return count($this->buffer);
    }

    /**
     * @param array{reservation_id: string|null, call_id: string|null, purpose: string|null,
     *     caller: string|null, provider: string|null, model: string|null, input_tokens: int,
     *     cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, units: int,
     *     provider_cost: int|null, status: string, http_status: int|null, error_code: string|null,
     *     latency_ms: int|null, attempt: int, fallback_from: string|null, test: bool,
     *     meta: array<string, scalar>|null} $call
     */
    private function write(array $call): int
    {
        try {
            if (null !== $call['call_id']) {
                $existing = $this->store->findByCallId($call['call_id']);
                if (null !== $existing) {
                    return (int) $existing['id'];
                }
            }
            $reservation = null === $call['reservation_id']
                ? null
                : $this->store->findByReservationId($call['reservation_id']);
        } catch (StoreException $e) {
            // Without a read we cannot settle; buffer an unreserved insert
            // that the shutdown retry will write.
            Logger::error('record_failed', 'ledger read failed: ' . $e->getMessage());
            $row = $this->buildRow($call, null, 'insert');
            return $this->buffer('insert', $row, 0, null);
        }

        $mode = 'insert';
        if (null !== $reservation) {
            if (in_array($reservation['status'], ['reserved', 'expired'], true)) {
                $mode = 'settle';
            } elseif (in_array($reservation['status'], Schema::SETTLED, true)) {
                $mode = 'linked';
            }
        }
        $row = $this->buildRow($call, $reservation, $mode);

        if ('settle' === $mode && null !== $reservation) {
            $id = (int) $reservation['id'];
            $fields = $row;
            unset($fields['reservation_id'], $fields['parent_id'], $fields['created_at'], $fields['day'], $fields['month'], $fields['blog_id']);
            if (null === $call['call_id']) {
                unset($fields['call_id']);
            }
            try {
                if ($this->store->updateIf($id, $fields, 'status', ['reserved', 'expired'])) {
                    $this->recorded($id, $fields + $reservation);
                    $this->afterWrite($fields + $reservation);
                    return $id;
                }
            } catch (StoreException $e) {
                Logger::error('record_failed', 'settle failed: ' . $e->getMessage());
                return $this->buffer('settle', $fields, $id, $this->buildRow($call, $reservation, 'linked'));
            }
            // Settled or released by someone else in the meantime. A settled
            // reservation gets a linked row (case 18); a released one means
            // this attempt is unreserved (case 19).
            try {
                $now = $this->store->find($id);
            } catch (StoreException) {
                $now = null;
            }
            $row = null !== $now && 'released' === $now['status']
                ? $this->buildRow($call, $reservation, 'insert')
                : $this->buildRow($call, $reservation, 'linked');
        }

        try {
            $id = $this->store->insert($row);
        } catch (StoreException $e) {
            try {
                $existing = $this->store->findByCallId((string) $row['call_id']);
                if (null !== $existing) {
                    return (int) $existing['id'];
                }
            } catch (StoreException) {
                // Fall through to the buffer.
            }
            Logger::error('record_failed', 'insert failed: ' . $e->getMessage());
            return $this->buffer('insert', $row, 0, null);
        }
        $this->recorded($id, $row + ['id' => $id]);
        $this->afterWrite($row);

        return $id;
    }

    /**
     * Build the ledger row for a call.
     *
     * @param array<string, mixed> $call
     * @param array<string, mixed>|null $reservation
     * @return array<string, mixed>
     */
    private function buildRow(array $call, ?array $reservation, string $mode): array
    {
        $flags = 0;
        $fromReservation = static fn (string $key): ?string => null !== $reservation && is_string($reservation[$key] ?? null)
            ? $reservation[$key]
            : null;
        $purpose = $call['purpose'] ?? $fromReservation('purpose');
        $caller = $call['caller'] ?? $fromReservation('caller');
        $provider = $call['provider'] ?? $fromReservation('provider');
        $model = $call['model'] ?? $fromReservation('model');
        if (null === $purpose || null === $caller || null === $provider || null === $model) {
            if (null === $call['reservation_id']) {
                $missing = null === $purpose ? 'purpose' : (null === $caller ? 'caller' : (null === $provider ? 'provider' : 'model'));
                throw new ValidationException($missing, sprintf('%s is required when there is no reservation', $missing));
            }
            // An unknown reservation id: money was spent, keep the row.
            $purpose ??= 'unknown';
            $caller ??= 'unknown';
            $provider ??= 'unknown';
            $model ??= 'unknown';
        }
        $fallback = $call['fallback_from'];
        $reservedModel = $fromReservation('model');
        if (null === $fallback && null !== $reservedModel && $reservedModel !== $model) {
            $fallback = $reservedModel;
        }
        if ('insert' === $mode) {
            $flags |= Schema::FLAG_UNRESERVED;
        }
        if ('settle' === $mode && null !== $reservation) {
            $flags |= ((int) $reservation['flags']) & Schema::FLAG_UNPRICED_ALLOWED;
        }

        $status = $call['status'];
        $errorCode = $call['error_code'];
        if (in_array($call['http_status'], [401, 402, 403], true)) {
            $status = 'error_unbilled';
            $errorCode ??= 'http_' . $call['http_status'];
        }

        $hasUsage = $call['input_tokens'] > 0 || $call['output_tokens'] > 0 || $call['units'] > 0
            || $call['cached_input_tokens'] > 0 || $call['reasoning_tokens'] > 0;
        $price = $this->prices->lookup($provider, $model);
        $tableCost = null === $price ? null : PriceTable::cost(
            $price,
            $call['input_tokens'],
            $call['cached_input_tokens'],
            $call['output_tokens'],
            $call['reasoning_tokens'],
            $call['units']
        );
        $estimate = 'settle' === $mode && null !== $reservation ? (int) $reservation['estimate'] : 0;
        $priceVersion = null;
        $costTable = null;
        if ('error_unbilled' === $status) {
            $cost = 0;
            $source = 'provider';
        } elseif (null !== $call['provider_cost']) {
            $cost = $call['provider_cost'];
            $source = 'provider';
            if (null !== $tableCost && null !== $price) {
                $costTable = $tableCost;
                $priceVersion = $price['version'];
            }
        } elseif ($hasUsage && null !== $tableCost && null !== $price) {
            $cost = $tableCost;
            $source = 'table';
            $priceVersion = $price['version'];
        } elseif (!$hasUsage && 'settle' === $mode) {
            $cost = $estimate;
            $source = 'estimate';
        } elseif (null !== $tableCost && null !== $price) {
            $cost = $tableCost;
            $source = 'table';
            $priceVersion = $price['version'];
        } else {
            $cost = 0;
            $source = 'unpriced';
        }
        if (!$hasUsage && null === $call['provider_cost'] && 'error_unbilled' !== $status) {
            $flags |= Schema::FLAG_USAGE_MISSING;
        }
        // The check decided whether this is a test: a live reservation counted
        // toward the caps and must not turn into a test row on record.
        $isTest = null !== $reservation && 'insert' !== $mode
            ? 1 === (int) $reservation['is_test']
            : $call['test'];
        $meta = $call['meta'];

        return [
            'call_id' => $call['call_id'] ?? wp_generate_uuid4(),
            'reservation_id' => 'insert' === $mode ? null : $call['reservation_id'],
            'parent_id' => 'linked' === $mode && null !== $reservation ? (int) $reservation['id'] : null,
            'blog_id' => null !== $reservation ? (int) $reservation['blog_id'] : (int) get_current_blog_id(),
            'created_at' => $this->clock->utc(),
            'day' => $this->clock->day(),
            'month' => $this->clock->month(),
            'purpose' => $purpose,
            'caller' => $caller,
            'provider' => $provider,
            'model' => $model,
            'fallback_from' => $fallback,
            'status' => $status,
            'input_tokens' => $call['input_tokens'],
            'cached_input_tokens' => $call['cached_input_tokens'],
            'output_tokens' => $call['output_tokens'],
            'reasoning_tokens' => $call['reasoning_tokens'],
            'units' => Money::microsToDecimal($call['units']),
            'estimate' => $estimate,
            'cost' => $cost,
            'cost_table' => $costTable,
            'cost_source' => $source,
            'currency' => $this->settings->all()['currency'],
            'price_version' => $priceVersion,
            'http_status' => $call['http_status'],
            'error_code' => $errorCode,
            'latency_ms' => $call['latency_ms'],
            'attempt' => $call['attempt'],
            'flags' => $flags,
            'is_test' => $isTest ? 1 : 0,
            'user_id' => (int) get_current_user_id(),
            'meta' => null === $meta ? null : (string) wp_json_encode($meta),
        ];
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $fallback For a settle: the linked row to insert if the reservation is no longer open.
     */
    private function buffer(string $op, array $row, int $id, ?array $fallback): int
    {
        if (count($this->buffer) >= self::BUFFER_LIMIT) {
            Logger::error('record_loss', 'record buffer full; a ledger write was lost');
            try {
                $this->counters->loss();
            } catch (Throwable) {
                // Nothing more can be done.
            }
            return 0;
        }
        $this->buffer[] = [$op, $row, $id, $fallback];
        if (!$this->shutdownHooked) {
            $this->shutdownHooked = true;
            add_action('shutdown', [$this, 'flush'], 1);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recorded(int $id, array $row): void
    {
        $row['id'] = $id;
        try {
            do_action('spend_meter_recorded', $id, $row);
        } catch (Throwable $e) {
            Logger::error('recorded_listener', 'a spend_meter_recorded listener threw: ' . $e->getMessage());
        }
    }

    /**
     * Fire cap alerts when a settled row costs more than it reserved (or was
     * never reserved) and a cap applies. The common path, settling at or
     * under the estimate, adds no query.
     *
     * @param array<string, mixed> $row
     */
    private function afterWrite(array $row): void
    {
        $cost = (int) $row['cost'];
        if (1 === (int) $row['is_test'] || $cost <= (int) $row['estimate']) {
            return;
        }
        try {
            $blogId = (int) $row['blog_id'];
            $purpose = (string) $row['purpose'];
            $site = $this->caps->site($blogId);
            $purposes = array_filter(
                $this->caps->purposes($blogId, $purpose),
                static fn (array $l): bool => null !== $l['day'] || null !== $l['month']
            );
            if (null === $site['day'] && null === $site['month'] && [] === $purposes) {
                return;
            }
            $day = $this->clock->day();
            $month = $this->clock->month();
            $totals = $this->store->windowTotals(
                $blogId,
                $day,
                $month,
                array_keys($purposes),
                $this->clock->utc(-$this->settings->reservationSeconds())
            );
            $threshold = $this->settings->all()['warning_threshold'];
            foreach ($purposes as $pattern => $limit) {
                $scope = 'purpose:' . $blogId . ':' . $pattern;
                if (null !== $limit['day']) {
                    $this->alerts->evaluate($scope, 'day', $limit['day'], $totals['purposes'][$pattern]['day'], $day, $threshold);
                }
                if (null !== $limit['month']) {
                    $this->alerts->evaluate($scope, 'month', $limit['month'], $totals['purposes'][$pattern]['month'], $month, $threshold);
                }
            }
            if (null !== $site['day']) {
                $this->alerts->evaluate('site:' . $blogId, 'day', $site['day'], $totals['site_day'], $day, $threshold);
            }
            if (null !== $site['month']) {
                $this->alerts->evaluate('site:' . $blogId, 'month', $site['month'], $totals['site_month'], $month, $threshold);
            }
        } catch (Throwable $e) {
            Logger::error('alerts', 'cap alert evaluation failed: ' . $e->getMessage());
        }
    }
}
