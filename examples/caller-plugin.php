<?php

/**
 * Plugin Name: Example spend-meter caller
 * Description: How a plugin that calls a model integrates with spend-meter using hooks only. Copy the pattern, not the file.
 * Version:     0.1.0
 * License:     MIT
 */

declare(strict_types=1);

namespace ExampleCaller;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The caller keeps its own daily cap and kill switch. spend-meter is a second
 * line, never the only one, and may be absent.
 */
function own_guards_allow(): bool
{
    if ('1' === get_option('example_caller_kill', '0')) {
        return false;
    }
    $today = gmdate('Y-m-d');
    $count = (int) get_transient('example_caller_calls_' . $today);

    return $count < (int) get_option('example_caller_daily_calls', 200);
}

/**
 * Make one billable call through spend-meter's pre-spend check and record.
 *
 * @param callable(): array{status: int, body: array<mixed>} $send Sends the request.
 */
function summarise(callable $send): ?array
{
    if (!own_guards_allow()) {
        return null;
    }
    $intent = [
        'purpose' => 'summary',
        'caller' => 'example-caller',
        'provider' => 'example-provider',
        'model' => 'example-model',
        'est_input_tokens' => 1200,
        'max_output_tokens' => 400,
    ];
    $meter = defined('SPEND_METER_API') && 1 === SPEND_METER_API;
    $decision = $meter ? apply_filters('spend_meter_pre_spend', null, $intent) : null;
    if (is_array($decision) && true !== $decision['allowed']) {
        // Unknown reason codes are denies too.
        return null;
    }
    $reservation = is_array($decision) ? $decision['reservation_id'] : null;

    $started = microtime(true);
    try {
        $response = $send();
    } catch (\Throwable $e) {
        if ($meter) {
            do_action('spend_meter_record', [
                'reservation_id' => $reservation,
                'purpose' => 'summary', 'caller' => 'example-caller',
                'provider' => 'example-provider', 'model' => 'example-model',
                'status' => 'error_unbilled',
                'error_code' => 'transport',
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }
        return null;
    }

    if ($meter) {
        $usage = spend_meter_usage_from_response($response['body']);
        $ok = 200 === $response['status'] && isset($response['body']['choices']);
        do_action('spend_meter_record', [
            'reservation_id' => $reservation,
            'purpose' => 'summary', 'caller' => 'example-caller',
            'provider' => 'example-provider', 'model' => 'example-model',
            'status' => $ok ? 'ok' : ($usage['usage_missing'] ? 'error_unbilled' : 'error_billed'),
            'http_status' => $response['status'],
            'input_tokens' => $usage['input_tokens'],
            'cached_input_tokens' => $usage['cached_input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'provider_cost' => $usage['provider_cost'],
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }
    $today = gmdate('Y-m-d');
    set_transient('example_caller_calls_' . $today, (int) get_transient('example_caller_calls_' . $today) + 1, DAY_IN_SECONDS);

    return $response['body'];
}

/**
 * Decided not to call after all: give the reservation back.
 */
function give_up(?string $reservationId): void
{
    if (null !== $reservationId) {
        do_action('spend_meter_release', $reservationId);
    }
}
