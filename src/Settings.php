<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Plugin settings: defaults, validation and storage.
 *
 * Kill switches and caps have their own options and classes; this holds
 * everything else on the settings screen.
 */
final class Settings
{
    /** ISO 4217 codes accepted as the ledger currency and in price files. */
    public const CURRENCIES = [
        'AED', 'ARS', 'AUD', 'BDT', 'BRL', 'CAD', 'CHF', 'CLP', 'CNY', 'COP', 'CZK', 'DKK', 'EGP', 'EUR',
        'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'JPY', 'KES', 'KRW', 'LKR', 'MXN', 'MYR', 'NGN', 'NOK',
        'NPR', 'NZD', 'PHP', 'PKR', 'PLN', 'RON', 'SAR', 'SEK', 'SGD', 'THB', 'TRY', 'TWD', 'UAH', 'USD',
        'VND', 'ZAR',
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * Defaults, after the spend_meter_default_settings filter.
     *
     * @return array{warning_threshold: int, allow_unpriced: bool, default_output_tokens: int,
     *     reservation_minutes: int, currency: string, stale_days: int, retention_months: int,
     *     keep_ledger: bool}
     */
    public static function defaults(): array
    {
        $defaults = [
            'warning_threshold' => 80,
            'allow_unpriced' => false,
            'default_output_tokens' => 4096,
            'reservation_minutes' => 15,
            'currency' => 'USD',
            'stale_days' => 120,
            'retention_months' => 0,
            'keep_ledger' => false,
        ];
        $filtered = apply_filters('spend_meter_default_settings', $defaults);
        if (!is_array($filtered)) {
            return $defaults;
        }
        [$clean] = self::validate($filtered + $defaults, $defaults);

        return $clean;
    }

    /**
     * Effective settings: the stored values over the defaults. A corrupt
     * option falls back to the defaults and logs once.
     *
     * @return array{warning_threshold: int, allow_unpriced: bool, default_output_tokens: int,
     *     reservation_minutes: int, currency: string, stale_days: int, retention_months: int,
     *     keep_ledger: bool}
     */
    public function all(): array
    {
        if (null !== $this->cache) {
            /** @var array{warning_threshold: int, allow_unpriced: bool, default_output_tokens: int, reservation_minutes: int, currency: string, stale_days: int, retention_months: int, keep_ledger: bool} */
            return $this->cache;
        }
        $defaults = self::defaults();
        $stored = Options::get(Options::SETTINGS, []);
        if (!is_array($stored)) {
            Logger::error('settings_corrupt', 'settings option is not an array; using defaults');
            $stored = [];
        }
        [$clean, $errors] = self::validate($stored + $defaults, $defaults);
        if ([] !== $errors) {
            Logger::error('settings_corrupt', 'settings option has invalid values; using defaults for them');
        }
        $this->cache = $clean;

        return $clean;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Reservation lifetime in seconds, after the
     * spend_meter_reservation_lifetime filter (minutes, clamped to 1..120).
     */
    public function reservationSeconds(): int
    {
        $minutes = apply_filters('spend_meter_reservation_lifetime', $this->all()['reservation_minutes']);
        $minutes = is_int($minutes) ? $minutes : $this->all()['reservation_minutes'];

        return max(1, min(120, $minutes)) * 60;
    }

    /**
     * Save validated settings. Invalid input saves nothing.
     *
     * @param  array<string, mixed> $input
     * @return array<string, string> Errors keyed by field; empty on success.
     */
    public function save(array $input, bool $ledgerHasRows): array
    {
        $current = $this->all();
        [$clean, $errors] = self::validate($input + $current, $current);
        if ($clean['currency'] !== $current['currency'] && $ledgerHasRows) {
            $errors['currency'] = 'The ledger currency cannot change once the ledger has rows; there is no conversion.';
        }
        if ([] !== $errors) {
            return $errors;
        }
        Options::set(Options::SETTINGS, $clean);
        $this->cache = null;

        return [];
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Validate every field. Returns the clean values (invalid fields keep
     * their fallback) and the errors keyed by field.
     *
     * @param  array<string, mixed> $input
     * @param  array<string, mixed> $fallback
     * @return array{0: array{warning_threshold: int, allow_unpriced: bool, default_output_tokens: int,
     *     reservation_minutes: int, currency: string, stale_days: int, retention_months: int,
     *     keep_ledger: bool}, 1: array<string, string>}
     */
    public static function validate(array $input, array $fallback): array
    {
        $errors = [];
        $int = static function (string $key, int $min, int $max, string $label) use ($input, $fallback, &$errors): int {
            $value = $input[$key] ?? null;
            if (is_string($value) && 1 === preg_match('/^\d{1,9}$/', trim($value))) {
                $value = (int) trim($value);
            }
            if (!is_int($value) || $value < $min || $value > $max) {
                $errors[$key] = sprintf('%s must be a whole number from %d to %d.', $label, $min, $max);
                $value = $fallback[$key] ?? $min;
                return is_int($value) ? $value : $min;
            }
            return $value;
        };
        $bool = static function (string $key) use ($input, &$errors): bool {
            $value = $input[$key] ?? false;
            if (is_bool($value)) {
                return $value;
            }
            if (in_array($value, [0, 1, '0', '1', '', 'on', 'yes', 'no', 'off'], true)) {
                return in_array($value, [1, '1', 'on', 'yes'], true);
            }
            $errors[$key] = sprintf('%s must be on or off.', $key);
            return false;
        };
        $clean = [
            'warning_threshold' => $int('warning_threshold', 1, 99, 'Warning threshold'),
            'allow_unpriced' => $bool('allow_unpriced'),
            'default_output_tokens' => $int('default_output_tokens', 1, 10000000, 'Default output allowance'),
            'reservation_minutes' => $int('reservation_minutes', 1, 120, 'Reservation lifetime'),
            'currency' => 'USD',
            'stale_days' => $int('stale_days', 1, 3650, 'Price staleness threshold'),
            'retention_months' => $int('retention_months', 0, 1200, 'Retention'),
            'keep_ledger' => $bool('keep_ledger'),
        ];
        $currency = $input['currency'] ?? null;
        $currency = is_string($currency) ? strtoupper(trim($currency)) : null;
        if (null === $currency || !in_array($currency, self::CURRENCIES, true)) {
            $errors['currency'] = 'Currency must be a known ISO 4217 code.';
            $fallbackCurrency = $fallback['currency'] ?? 'USD';
            $clean['currency'] = is_string($fallbackCurrency) ? $fallbackCurrency : 'USD';
        } else {
            $clean['currency'] = $currency;
        }

        return [$clean, $errors];
    }
}
