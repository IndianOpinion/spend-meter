<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Validates intent arrays (pre-spend) and call arrays (record).
 *
 * Nothing is ever truncated: a value that does not fit is rejected and the
 * error names the first bad key.
 */
final class Validator
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Upper bound for any token count or integer field: 10^12. */
    private const MAX_INT = 1000000000000;

    public const STATUSES = ['ok', 'error_billed', 'error_unbilled'];

    /**
     * @param  array<mixed> $intent
     * @return array{purpose: string, caller: string, provider: string, model: string,
     *     est_input_tokens: int, max_output_tokens: int|null, est_units: int, estimate_override: int|null,
     *     blog_id: int|null, dry_run: bool, test: bool, meta: array<string, scalar>|null}
     * @throws ValidationException
     */
    public static function intent(array $intent): array
    {
        foreach (['purpose', 'caller', 'provider', 'model'] as $key) {
            if (!array_key_exists($key, $intent)) {
                throw new ValidationException($key, sprintf('%s is required', $key));
            }
        }
        $purpose = self::slug($intent, 'purpose');
        $caller = self::slug($intent, 'caller');
        $provider = self::slug($intent, 'provider');
        $model = self::model($intent, 'model');

        $estUnits = 0;
        if (array_key_exists('est_units', $intent) && null !== $intent['est_units']) {
            if (is_bool($intent['est_units'])) {
                throw new ValidationException('est_units', 'est_units must be a non-negative number');
            }
            $estUnits = Money::toMicros($intent['est_units'], 'est_units');
        }
        $blogId = self::optionalInt($intent, 'blog_id');
        if (null !== $blogId && $blogId < 1) {
            throw new ValidationException('blog_id', 'blog_id must be a positive integer');
        }

        return [
            'purpose' => $purpose,
            'caller' => $caller,
            'provider' => $provider,
            'model' => $model,
            'est_input_tokens' => self::optionalInt($intent, 'est_input_tokens') ?? 0,
            'max_output_tokens' => self::optionalInt($intent, 'max_output_tokens'),
            'est_units' => $estUnits,
            'estimate_override' => self::optionalInt($intent, 'estimate_override'),
            'blog_id' => $blogId,
            'dry_run' => self::optionalBool($intent, 'dry_run'),
            'test' => self::optionalBool($intent, 'test'),
            'meta' => self::meta($intent),
        ];
    }

    /**
     * Validate a call array. Identity keys are optional here; the ledger
     * decides whether they are needed once it has looked up the reservation.
     *
     * @param  array<mixed> $call
     * @return array{reservation_id: string|null, call_id: string|null, purpose: string|null,
     *     caller: string|null, provider: string|null, model: string|null, input_tokens: int,
     *     cached_input_tokens: int, output_tokens: int, reasoning_tokens: int, units: int,
     *     provider_cost: int|null, status: string, http_status: int|null, error_code: string|null,
     *     latency_ms: int|null, attempt: int, fallback_from: string|null, test: bool,
     *     meta: array<string, scalar>|null}
     * @throws ValidationException
     */
    public static function call(array $call): array
    {
        if (!array_key_exists('status', $call)) {
            throw new ValidationException('status', 'status is required');
        }
        if (!is_string($call['status']) || !in_array($call['status'], self::STATUSES, true)) {
            throw new ValidationException('status', 'status must be ok, error_billed or error_unbilled');
        }
        $out = [
            'reservation_id' => self::optionalUuid($call, 'reservation_id'),
            'call_id' => self::optionalUuid($call, 'call_id'),
            'purpose' => null,
            'caller' => null,
            'provider' => null,
            'model' => null,
        ];
        foreach (['purpose', 'caller', 'provider'] as $key) {
            if (isset($call[$key])) {
                $out[$key] = self::slug($call, $key);
            }
        }
        if (isset($call['model'])) {
            $out['model'] = self::model($call, 'model');
        }
        $units = 0;
        if (isset($call['units'])) {
            if (is_bool($call['units'])) {
                throw new ValidationException('units', 'units must be a non-negative number');
            }
            $units = Money::toMicros($call['units'], 'units');
        }
        $http = self::optionalInt($call, 'http_status');
        if (null !== $http && ($http < 100 || $http > 599)) {
            throw new ValidationException('http_status', 'http_status must be from 100 to 599');
        }
        $latency = self::optionalInt($call, 'latency_ms');
        if (null !== $latency && $latency > 4294967295) {
            throw new ValidationException('latency_ms', 'latency_ms is too large');
        }
        $attempt = self::optionalInt($call, 'attempt') ?? 1;
        if ($attempt < 1 || $attempt > 65535) {
            throw new ValidationException('attempt', 'attempt must be from 1 to 65535');
        }
        $errorCode = null;
        if (isset($call['error_code'])) {
            if (!is_string($call['error_code']) || 1 !== preg_match('/^.{1,64}$/us', $call['error_code'])) {
                throw new ValidationException('error_code', 'error_code must be a string of 1 to 64 characters');
            }
            $errorCode = $call['error_code'];
        }
        $fallback = isset($call['fallback_from']) ? self::model($call, 'fallback_from') : null;

        return $out + [
            'input_tokens' => self::optionalInt($call, 'input_tokens') ?? 0,
            'cached_input_tokens' => self::optionalInt($call, 'cached_input_tokens') ?? 0,
            'output_tokens' => self::optionalInt($call, 'output_tokens') ?? 0,
            'reasoning_tokens' => self::optionalInt($call, 'reasoning_tokens') ?? 0,
            'units' => $units,
            'provider_cost' => self::optionalInt($call, 'provider_cost'),
            'status' => $call['status'],
            'http_status' => $http,
            'error_code' => $errorCode,
            'latency_ms' => $latency,
            'attempt' => $attempt,
            'fallback_from' => $fallback,
            'test' => self::optionalBool($call, 'test'),
            'meta' => self::meta($call),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private static function slug(array $data, string $key): string
    {
        if (!Pattern::isSlug($data[$key] ?? null)) {
            throw new ValidationException(
                $key,
                sprintf('%s must match ^[a-z0-9][a-z0-9_.-]{0,63}$ (never truncated)', $key)
            );
        }

        return (string) $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function model(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || 1 !== preg_match('/^[^\x00-\x1f\x7f]{1,128}$/u', $value)) {
            throw new ValidationException(
                $key,
                sprintf('%s must be a string of 1 to 128 characters without control characters', $key)
            );
        }

        return $value;
    }

    /**
     * An integer, or a string of digits. Floats, booleans and other strings
     * are invalid.
     *
     * @param array<mixed> $data
     */
    public static function optionalInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }
        $value = $data[$key];
        if (is_string($value) && 1 === preg_match('/^\d{1,13}$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > self::MAX_INT) {
            throw new ValidationException($key, sprintf('%s must be a non-negative integer up to 10^12', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalBool(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return false;
        }
        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, 1, '0', '1'], true)) {
            return 1 === (int) $value;
        }
        throw new ValidationException($key, sprintf('%s must be a boolean', $key));
    }

    /**
     * @param array<mixed> $data
     */
    private static function optionalUuid(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }
        $value = $data[$key];
        if (!is_string($value) || 1 !== preg_match(self::UUID, strtolower($value))) {
            throw new ValidationException($key, sprintf('%s must be a UUID', $key));
        }

        return strtolower($value);
    }

    /**
     * Up to 8 scalar pairs; keys ^[a-z0-9_]{1,32}$; values up to 191 chars.
     *
     * @param  array<mixed> $data
     * @return array<string, scalar>|null
     */
    public static function meta(array $data): ?array
    {
        if (!array_key_exists('meta', $data) || null === $data['meta']) {
            return null;
        }
        $meta = $data['meta'];
        if (!is_array($meta) || count($meta) > 8) {
            throw new ValidationException('meta', 'meta must be an array of at most 8 pairs');
        }
        $out = [];
        foreach ($meta as $k => $v) {
            if (!is_string($k) || 1 !== preg_match('/^[a-z0-9_]{1,32}$/', $k)) {
                throw new ValidationException('meta', 'meta keys must match ^[a-z0-9_]{1,32}$');
            }
            if (!is_scalar($v) || (is_string($v) && 1 !== preg_match('/^.{0,191}$/us', $v))) {
                throw new ValidationException('meta', 'meta values must be scalars of at most 191 characters');
            }
            if (is_float($v) && !is_finite($v)) {
                throw new ValidationException('meta', 'meta values must be finite');
            }
            $out[$k] = $v;
        }

        return [] === $out ? null : $out;
    }
}
