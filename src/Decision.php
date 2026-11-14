<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use IndianOpinion\SpendMeter\Store\Store;
use IndianOpinion\SpendMeter\Store\StoreException;
use Throwable;

/**
 * The pre-spend check: allow, or deny with a machine-readable reason.
 *
 * Order: global kill switch, purpose kill switch, intent validation, ledger
 * readiness, price lookup and estimate, unpriced policy, then reserve and sum.
 * The reservation is written before the window totals are read, so two racing
 * processes each see the other's reservation: both may deny, neither
 * overshoots. Every decision then passes through spend_meter_decision, which
 * can turn an allow into a deny and never the reverse.
 *
 * @phpstan-type DecisionArray array{allowed: bool, reason: string, message: string,
 *     reservation_id: string|null, estimate: int, remaining: int|null, api: int}
 */
final class Decision
{
    public const REASONS = [
        'ok', 'kill_switch', 'kill_switch_purpose', 'invalid_request', 'unpriced_model',
        'cap_purpose_day', 'cap_purpose_month', 'cap_site_day', 'cap_site_month',
        'cap_network_day', 'cap_network_month', 'ledger_unavailable', 'denied_by_filter',
        'decision_filter_error',
    ];

    public const NETWORK_CACHE_TTL = 60;

    private bool $loggedOverride = false;

    public function __construct(
        private Store $store,
        private Clock $clock,
        private Settings $settings,
        private PriceTable $prices,
        private Caps $caps,
        private KillSwitch $kill,
        private Alerts $alerts,
        private Counters $counters
    ) {
    }

    /**
     * @param  array<mixed> $intent
     * @return DecisionArray
     */
    public function check(array $intent): array
    {
        // Same truthy values the validator accepts for dry_run.
        $dryRun = in_array($intent['dry_run'] ?? false, [true, 1, '1'], true);
        try {
            [$decision, $reservationRowId] = $this->decide($intent);
        } catch (Throwable $e) {
            Logger::error('check', 'pre-spend check failed: ' . $e->getMessage());
            [$decision, $reservationRowId] = [$this->deny('ledger_unavailable', 'ledger error during the check', 0, null), null];
        }
        $decision = $this->applyFilter($decision, $intent, $reservationRowId);
        if (!$decision['allowed']) {
            if (!$dryRun) {
                try {
                    $this->counters->denial($decision['reason']);
                } catch (Throwable $e) {
                    Logger::error('counter', 'denial counter could not be written: ' . $e->getMessage());
                }
            }
            Logger::debug(sprintf(
                'deny %s purpose=%s caller=%s',
                $decision['reason'],
                is_string($intent['purpose'] ?? null) ? $intent['purpose'] : '-',
                is_string($intent['caller'] ?? null) ? $intent['caller'] : '-'
            ));
        }

        return $decision;
    }

    /**
     * Estimate in nanos without any checks; 0 when unpriced and no override.
     *
     * @param array<mixed> $intent
     */
    public function estimate(array $intent): int
    {
        try {
            $clean = Validator::intent($intent);
            $price = $this->prices->lookup($clean['provider'], $clean['model']);

            return $this->estimateFor($clean, $price);
        } catch (ValidationException) {
            return 0;
        }
    }

    /**
     * @param  array<mixed> $intent
     * @return array{0: DecisionArray, 1: int|null} The decision and the reservation row id.
     */
    private function decide(array $intent): array
    {
        Options::refresh(Options::KILL, Options::CAPS);
        if ($this->kill->globalOn()) {
            return [$this->deny('kill_switch', 'the global kill switch is on', 0, null), null];
        }
        if (Pattern::isSlug($intent['purpose'] ?? null)) {
            $match = $this->kill->purposeMatch((string) $intent['purpose']);
            if (null !== $match) {
                return [$this->deny('kill_switch_purpose', sprintf('purpose kill switch "%s" matches', $match), 0, null), null];
            }
        }
        try {
            $clean = Validator::intent($intent);
        } catch (ValidationException $e) {
            return [$this->deny('invalid_request', $e->getMessage(), 0, null), null];
        }
        if (!Schema::ready()) {
            return [$this->deny('ledger_unavailable', 'the ledger table is not installed or is missing columns', 0, null), null];
        }
        $blogId = $clean['blog_id'] ?? (int) get_current_blog_id();
        $price = $this->prices->lookup($clean['provider'], $clean['model']);
        try {
            $estimate = $this->estimateFor($clean, $price);
        } catch (ValidationException $e) {
            return [$this->deny('invalid_request', $e->getMessage(), 0, null), null];
        }
        $flags = 0;
        if (null === $price && null === $clean['estimate_override'] && !$clean['test']) {
            if (!$this->settings->all()['allow_unpriced']) {
                return [$this->deny(
                    'unpriced_model',
                    sprintf('no price for %s %s; load prices or allow unpriced models', $clean['provider'], $clean['model']),
                    0,
                    null
                ), null];
            }
            $flags |= Schema::FLAG_UNPRICED_ALLOWED;
            Logger::error(
                'unpriced_allowed_' . $clean['provider'] . '_' . $clean['model'],
                sprintf('allowing unpriced model %s %s; its cost is not metered', $clean['provider'], $clean['model'])
            );
        }

        if ($clean['test']) {
            if ($clean['dry_run']) {
                return [$this->allow(null, $estimate, null), null];
            }
            try {
                [$rowId, $reservationId] = $this->reserve($clean, $blogId, $estimate, $price, $flags);
            } catch (StoreException $e) {
                Logger::error('reserve', 'reservation insert failed: ' . $e->getMessage());
                return [$this->deny('ledger_unavailable', 'the reservation could not be written', $estimate, null), null];
            }
            return [$this->allow($reservationId, $estimate, null), $rowId];
        }

        $siteCap = $this->caps->site($blogId);
        $purposeCaps = $this->caps->purposes($blogId, $clean['purpose']);
        $networkCap = $this->caps->network();
        $hasCap = static fn (array $limit): bool => null !== $limit['day'] || null !== $limit['month'];
        $anyPurpose = array_filter($purposeCaps, $hasCap);
        if (!$hasCap($siteCap) && [] === $anyPurpose && !$hasCap($networkCap)) {
            if ($clean['dry_run']) {
                return [$this->allow(null, $estimate, null), null];
            }
            try {
                [$rowId, $reservationId] = $this->reserve($clean, $blogId, $estimate, $price, $flags);
            } catch (StoreException $e) {
                Logger::error('reserve', 'reservation insert failed: ' . $e->getMessage());
                return [$this->deny('ledger_unavailable', 'the reservation could not be written', $estimate, null), null];
            }
            return [$this->allow($reservationId, $estimate, null), $rowId];
        }

        $rowId = null;
        $reservationId = null;
        $own = $clean['dry_run'] ? $estimate : 0;
        $day = $this->clock->day();
        $month = $this->clock->month();
        $liveSince = $this->clock->utc(-$this->settings->reservationSeconds());
        try {
            if (!$clean['dry_run']) {
                [$rowId, $reservationId] = $this->reserve($clean, $blogId, $estimate, $price, $flags);
            }
            $totals = $this->store->windowTotals($blogId, $day, $month, array_keys($anyPurpose), $liveSince);
            $network = null;
            if ($hasCap($networkCap)) {
                $network = $this->networkTotals($blogId, $day, $month, $liveSince, $totals, $own, false);
            }
        } catch (StoreException $e) {
            Logger::error('reserve', 'reservation or totals failed: ' . $e->getMessage());
            $this->dropReservation($rowId);
            return [$this->deny('ledger_unavailable', 'the ledger could not be read or written', $estimate, null), null];
        }

        $checks = [];
        foreach ($anyPurpose as $pattern => $limit) {
            $scope = 'purpose:' . $blogId . ':' . $pattern;
            $checks[] = ['cap_purpose_day', $scope, 'day', $limit['day'], $totals['purposes'][$pattern]['day'] + $own, $day];
        }
        foreach ($anyPurpose as $pattern => $limit) {
            $scope = 'purpose:' . $blogId . ':' . $pattern;
            $checks[] = ['cap_purpose_month', $scope, 'month', $limit['month'], $totals['purposes'][$pattern]['month'] + $own, $month];
        }
        $checks[] = ['cap_site_day', 'site:' . $blogId, 'day', $siteCap['day'], $totals['site_day'] + $own, $day];
        $checks[] = ['cap_site_month', 'site:' . $blogId, 'month', $siteCap['month'], $totals['site_month'] + $own, $month];
        if (null !== $network) {
            $checks[] = ['cap_network_day', 'network', 'day', $networkCap['day'], $network['day'], $day];
            $checks[] = ['cap_network_month', 'network', 'month', $networkCap['month'], $network['month'], $month];
        }

        $failed = $this->firstFailure($checks);
        if (null !== $failed && 0 === strpos($failed[0], 'cap_network') && null !== $network) {
            // The network total may come from the 60-second cache: recompute
            // live before denying on it.
            try {
                $network = $this->networkTotals($blogId, $day, $month, $liveSince, $totals, $own, true);
            } catch (StoreException $e) {
                $this->dropReservation($rowId);
                return [$this->deny('ledger_unavailable', 'the ledger could not be read', $estimate, null), null];
            }
            foreach ($checks as $i => $check) {
                if ('cap_network_day' === $check[0]) {
                    $checks[$i][4] = $network['day'];
                } elseif ('cap_network_month' === $check[0]) {
                    $checks[$i][4] = $network['month'];
                }
            }
            $failed = $this->firstFailure($checks);
        }

        $remaining = null;
        foreach ($checks as $check) {
            if (null !== $check[3]) {
                $left = max(0, $check[3] - $check[4]);
                $remaining = null === $remaining ? $left : min($remaining, $left);
            }
        }
        if (!$clean['dry_run']) {
            $threshold = $this->settings->all()['warning_threshold'];
            foreach ($checks as $check) {
                if (null !== $check[3]) {
                    $this->alerts->evaluate($check[1], $check[2], $check[3], $check[4], $check[5], $threshold, $failed === $check);
                }
            }
        }
        if (null !== $failed) {
            $this->dropReservation($rowId);
            return [$this->deny(
                $failed[0],
                sprintf(
                    '%s cap for %s: limit %s, total %s',
                    $failed[2],
                    $failed[1],
                    Money::nanosToDecimal((int) $failed[3]),
                    Money::nanosToDecimal($failed[4])
                ),
                $estimate,
                0
            ), null];
        }

        return [$this->allow($reservationId, $estimate, $remaining), $rowId];
    }

    /**
     * The first failing cap in priority order.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int|null, 4: int, 5: string}> $checks
     * @return array{0: string, 1: string, 2: string, 3: int|null, 4: int, 5: string}|null
     */
    private function firstFailure(array $checks): ?array
    {
        foreach ($checks as $check) {
            if (null !== $check[3] && (0 === $check[3] || $check[4] > $check[3])) {
                return $check;
            }
        }

        return null;
    }

    /**
     * Network totals for the cap check.
     *
     * The 60-second cache holds the network total minus this blog's own
     * total at the time it was computed ("the other sites"). The figure used
     * is that cached part plus this blog's live total, which already holds
     * this call's reservation, so a burst on this blog is always counted at
     * once; only other sites' spend inside the last minute can be missed.
     * $own is this call's estimate when it holds no reservation (dry run).
     * Live when $live is true or the cache is cold.
     *
     * @param  array{site_day: int, site_month: int, purposes: array<string, array{day: int, month: int}>} $site
     * @return array{day: int, month: int}
     */
    private function networkTotals(int $blogId, string $day, string $month, string $liveSince, array $site, int $own, bool $live): array
    {
        $key = self::networkCacheKey($day, $blogId);
        if (!$live) {
            $cached = wp_cache_get($key, 'spend_meter');
            if (false === $cached && !wp_using_ext_object_cache()) {
                $cached = get_site_transient($key);
            }
            if (is_array($cached) && is_int($cached['day'] ?? null) && is_int($cached['month'] ?? null)) {
                return [
                    'day' => Money::add($cached['day'], $site['site_day'], $own),
                    'month' => Money::add($cached['month'], $site['site_month'], $own),
                ];
            }
        }
        $totals = $this->store->networkTotals($day, $month, $liveSince);
        $others = [
            'day' => max(0, $totals['day'] - $site['site_day']),
            'month' => max(0, $totals['month'] - $site['site_month']),
        ];
        wp_cache_set($key, $others, 'spend_meter', self::NETWORK_CACHE_TTL);
        if (!wp_using_ext_object_cache()) {
            set_site_transient($key, $others, self::NETWORK_CACHE_TTL);
        }

        return ['day' => Money::add($totals['day'], $own), 'month' => Money::add($totals['month'], $own)];
    }

    /**
     * Cache key of the network total (other sites) for one blog and day.
     */
    public static function networkCacheKey(string $day, int $blogId): string
    {
        return 'spend_meter_network_' . $day . '_' . $blogId;
    }

    /**
     * @param array{purpose: string, caller: string, provider: string, model: string, est_input_tokens: int,
     *     max_output_tokens: int|null, est_units: int, estimate_override: int|null, blog_id: int|null,
     *     dry_run: bool, test: bool, meta: array<string, scalar>|null} $clean
     * @param array<string, mixed>|null $price
     */
    private function estimateFor(array $clean, ?array $price): int
    {
        $computed = 0;
        if (null !== $price) {
            /** @var array{provider: string, model: string, input: int, cached_input: int|null, output: int, reasoning: int|null, unit: string|null, unit_price: int|null, per_call_fee: int|null, as_of: string|null, source: string|null, origin: string, version: string} $price */
            $computed = PriceTable::estimate(
                $price,
                $clean['est_input_tokens'],
                $clean['max_output_tokens'] ?? $this->settings->all()['default_output_tokens'],
                $clean['est_units']
            );
        }

        return max($computed, $clean['estimate_override'] ?? 0);
    }

    /**
     * Insert the reservation row.
     *
     * @param array{purpose: string, caller: string, provider: string, model: string, est_input_tokens: int,
     *     max_output_tokens: int|null, est_units: int, estimate_override: int|null, blog_id: int|null,
     *     dry_run: bool, test: bool, meta: array<string, scalar>|null} $clean
     * @param array<string, mixed>|null $price
     * @return array{0: int, 1: string}
     */
    private function reserve(array $clean, int $blogId, int $estimate, ?array $price, int $flags): array
    {
        $reservationId = wp_generate_uuid4();
        $rowId = $this->store->insert([
            'call_id' => wp_generate_uuid4(),
            'reservation_id' => $reservationId,
            'parent_id' => null,
            'blog_id' => $blogId,
            'created_at' => $this->clock->utc(),
            'day' => $this->clock->day(),
            'month' => $this->clock->month(),
            'purpose' => $clean['purpose'],
            'caller' => $clean['caller'],
            'provider' => $clean['provider'],
            'model' => $clean['model'],
            'fallback_from' => null,
            'status' => 'reserved',
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'units' => '0.000000',
            'estimate' => $estimate,
            'cost' => 0,
            'cost_table' => null,
            'cost_source' => 'estimate',
            'currency' => $this->settings->all()['currency'],
            'price_version' => is_string($price['version'] ?? null) && '' !== $price['version'] ? $price['version'] : null,
            'http_status' => null,
            'error_code' => null,
            'latency_ms' => null,
            'attempt' => 1,
            'flags' => $flags,
            'is_test' => $clean['test'] ? 1 : 0,
            'user_id' => (int) get_current_user_id(),
            'meta' => null === $clean['meta'] ? null : (string) wp_json_encode($clean['meta']),
        ]);

        return [$rowId, $reservationId];
    }

    private function dropReservation(?int $rowId): void
    {
        if (null === $rowId) {
            return;
        }
        try {
            $this->store->delete($rowId);
        } catch (StoreException $e) {
            // Fall back to releasing it so it stops counting at once.
            Logger::error('reserve', 'own reservation could not be removed: ' . $e->getMessage());
            try {
                $this->store->updateIf($rowId, ['status' => 'released', 'cost' => 0], 'status', ['reserved']);
            } catch (StoreException) {
                // The reservation expires on its own after the lifetime.
            }
        }
    }

    /**
     * Pass the decision through spend_meter_decision. A filter may turn an
     * allow into a deny; a deny stays a deny.
     *
     * @param  DecisionArray $decision
     * @param  array<mixed> $intent
     * @return DecisionArray
     */
    private function applyFilter(array $decision, array $intent, ?int $rowId): array
    {
        try {
            $filtered = apply_filters('spend_meter_decision', $decision, $intent);
        } catch (Throwable $e) {
            Logger::error('decision_filter', 'a spend_meter_decision filter threw: ' . $e->getMessage());
            $this->dropReservation($rowId);
            return $this->deny('decision_filter_error', 'a decision filter failed', $decision['estimate'], null);
        }
        if (!is_array($filtered)) {
            Logger::error('decision_filter', 'a spend_meter_decision filter returned a non-array');
            $this->dropReservation($rowId);
            return $this->deny('decision_filter_error', 'a decision filter returned an invalid value', $decision['estimate'], null);
        }
        $allowed = true === ($filtered['allowed'] ?? null);
        if ($decision['allowed'] && !$allowed) {
            $this->dropReservation($rowId);
            $message = is_string($filtered['message'] ?? null) ? $filtered['message'] : 'denied by a decision filter';
            return $this->deny('denied_by_filter', $message, $decision['estimate'], null);
        }
        if (!$decision['allowed'] && $allowed && !$this->loggedOverride) {
            $this->loggedOverride = true;
            Logger::error('deny_to_allow', 'a spend_meter_decision filter tried to turn a deny into an allow; ignored');
        }

        return $decision;
    }

    /**
     * @return DecisionArray
     */
    private function allow(?string $reservationId, int $estimate, ?int $remaining): array
    {
        return [
            'allowed' => true,
            'reason' => 'ok',
            'message' => 'allowed',
            'reservation_id' => $reservationId,
            'estimate' => $estimate,
            'remaining' => $remaining,
            'api' => SPEND_METER_API,
        ];
    }

    /**
     * @return DecisionArray
     */
    private function deny(string $reason, string $message, int $estimate, ?int $remaining): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'message' => $message,
            'reservation_id' => null,
            'estimate' => $estimate,
            'remaining' => $remaining,
            'api' => SPEND_METER_API,
        ];
    }
}
