<?php

/**
 * Public functions. Loaded by the plugin file, so they exist only while
 * spend-meter is active. Every one has the same behaviour as the hooks.
 */

declare(strict_types=1);

use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Store\StoreException;
use IndianOpinion\SpendMeter\UsageParser;
use IndianOpinion\SpendMeter\Logger;
use IndianOpinion\SpendMeter\Pattern;

if (!function_exists('spend_meter_check')) {
    /**
     * Pre-spend check. Same result as the spend_meter_pre_spend filter, never null.
     *
     * @param  array<string, mixed> $intent
     * @return array{allowed: bool, reason: string, message: string, reservation_id: string|null,
     *     estimate: int, remaining: int|null, api: int}
     */
    function spend_meter_check(array $intent): array
    {
        return Plugin::instance()->decision()->check($intent);
    }
}

if (!function_exists('spend_meter_record')) {
    /**
     * Record one attempt. Returns the row id, 0 when buffered for a retry,
     * or -1 when the call array is invalid. Never throws.
     *
     * @param array<string, mixed> $call
     */
    function spend_meter_record(array $call): int
    {
        return Plugin::instance()->ledger()->record($call);
    }
}

if (!function_exists('spend_meter_release')) {
    /**
     * Release a reservation. True when a live reservation was released.
     */
    function spend_meter_release(string $reservation_id): bool
    {
        return Plugin::instance()->ledger()->release($reservation_id);
    }
}

if (!function_exists('spend_meter_usage_from_response')) {
    /**
     * Normalised usage counts from a decoded provider response body.
     *
     * @param  array<mixed> $body
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int,
     *     provider_cost: int|null, usage_missing: bool, notices: list<string>}
     */
    function spend_meter_usage_from_response(array $body): array
    {
        return UsageParser::parse($body);
    }
}

if (!function_exists('spend_meter_estimate')) {
    /**
     * Estimate in nanos without any checks; 0 when unpriced and no override.
     *
     * @param array<string, mixed> $intent
     */
    function spend_meter_estimate(array $intent): int
    {
        return Plugin::instance()->decision()->estimate($intent);
    }
}

if (!function_exists('spend_meter_totals')) {
    /**
     * Current window total in nanos: settled plus live reservations, tests
     * excluded. Invalid arguments return 0 and log.
     */
    function spend_meter_totals(string $scope, string $window, ?string $purpose = null, ?int $blog_id = null): int
    {
        if (!in_array($scope, ['site', 'purpose', 'network'], true) || !in_array($window, ['day', 'month'], true)) {
            Logger::error('totals_args', 'spend_meter_totals: scope must be site, purpose or network; window day or month');
            return 0;
        }
        if ('purpose' === $scope && !Pattern::isSlug($purpose)) {
            Logger::error('totals_args', 'spend_meter_totals: purpose scope needs an exact purpose slug');
            return 0;
        }
        $plugin = Plugin::instance();
        $clock = $plugin->clock();
        $since = $clock->utc(-$plugin->settings()->reservationSeconds());
        try {
            if ('network' === $scope) {
                return $plugin->store()->networkTotals($clock->day(), $clock->month(), $since)[$window];
            }
            $blog_id ??= (int) get_current_blog_id();
            $patterns = 'purpose' === $scope ? [(string) $purpose] : [];
            $totals = $plugin->store()->windowTotals($blog_id, $clock->day(), $clock->month(), $patterns, $since);
            if ('purpose' === $scope) {
                return $totals['purposes'][(string) $purpose][$window];
            }
            return 'day' === $window ? $totals['site_day'] : $totals['site_month'];
        } catch (StoreException $e) {
            Logger::error('totals', 'spend_meter_totals failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('spend_meter_format')) {
    /**
     * Money for display in the ledger currency, two decimal places.
     */
    function spend_meter_format(int $nanos): string
    {
        return Money::format($nanos, Plugin::instance()->settings()->all()['currency']);
    }
}

if (!function_exists('spend_meter_kill_active')) {
    /**
     * True when the global switch is on, or $purpose matches a purpose entry.
     */
    function spend_meter_kill_active(?string $purpose = null): bool
    {
        return Plugin::instance()->kill()->active($purpose);
    }
}
