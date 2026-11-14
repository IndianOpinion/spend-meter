<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Money and quantity arithmetic on integers.
 *
 * Money is an integer number of nanos (one billionth of the ledger currency
 * unit). Prices arrive as decimal strings and are converted with string
 * arithmetic, never through floats. Every multiplication checks for integer
 * overflow and throws instead of returning a wrong number.
 */
final class Money
{
    public const NANOS_PER_UNIT = 1000000000;
    public const MICROS_PER_UNIT = 1000000;
    public const PER_MILLION = 1000000;

    /** Largest decimal accepted anywhere: 9 integer digits, 9 decimal places. */
    private const DECIMAL_PATTERN = '/^(\d{1,9})(?:\.(\d{1,9}))?$/';

    /**
     * True when the value is a non-negative decimal string with at most nine
     * places and at most nine integer digits.
     */
    public static function isDecimal(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match(self::DECIMAL_PATTERN, $value);
    }

    /**
     * Convert a decimal string in currency units to nanos.
     *
     * @throws ValidationException When the string is not a valid decimal.
     */
    public static function decimalToNanos(string $decimal, string $key = 'amount'): int
    {
        if (1 !== preg_match(self::DECIMAL_PATTERN, $decimal, $m)) {
            throw new ValidationException(
                $key,
                sprintf('%s must be a non-negative decimal string with at most 9 places', $key)
            );
        }
        $fraction = str_pad($m[2] ?? '', 9, '0');

        return ((int) $m[1]) * self::NANOS_PER_UNIT + (int) $fraction;
    }

    /**
     * Render nanos as a decimal string with trailing zeros removed.
     */
    public static function nanosToDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);
        $whole = intdiv($abs, self::NANOS_PER_UNIT);
        $fraction = rtrim(str_pad((string) ($abs % self::NANOS_PER_UNIT), 9, '0', STR_PAD_LEFT), '0');

        return $sign . $whole . ('' === $fraction ? '' : '.' . $fraction);
    }

    /**
     * Convert a quantity (int, float or decimal string) to millionths.
     *
     * @throws ValidationException When the value is not a non-negative number.
     */
    public static function toMicros(mixed $value, string $key = 'units'): int
    {
        if (is_int($value)) {
            if ($value < 0 || $value > 1000000000000) {
                throw new ValidationException($key, sprintf('%s must be between 0 and 10^12', $key));
            }
            return $value * self::MICROS_PER_UNIT;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                throw new ValidationException($key, sprintf('%s must be a finite non-negative number', $key));
            }
            $value = sprintf('%.6F', $value);
        }
        if (!is_string($value) || 1 !== preg_match('/^(\d{1,12})(?:\.(\d+))?$/', $value, $m)) {
            throw new ValidationException($key, sprintf('%s must be a non-negative number', $key));
        }
        $fraction = substr(str_pad($m[2] ?? '', 6, '0'), 0, 6);

        return ((int) $m[1]) * self::MICROS_PER_UNIT + (int) $fraction;
    }

    /**
     * Render millionths as a decimal string with six places (DECIMAL(20,6)).
     */
    public static function microsToDecimal(int $micros): string
    {
        return intdiv($micros, self::MICROS_PER_UNIT) . '.'
            . str_pad((string) ($micros % self::MICROS_PER_UNIT), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Compute round(a * b / d) for non-negative integers, half up, without
     * overflowing on the intermediate product where the result fits.
     *
     * @throws ValidationException When the result does not fit in an integer.
     */
    public static function mulDiv(int $a, int $b, int $d): int
    {
        if ($a < 0 || $b < 0 || $d <= 0) {
            throw new ValidationException('amount', 'negative amount in money arithmetic');
        }
        $q = intdiv($b, $d);
        $r = $b % $d;
        $whole = self::mul($a, $q);
        // a * r can overflow when a is large; split a the same way.
        $aq = intdiv($a, $d);
        $ar = $a % $d;
        $part = self::add(self::mul($aq, $r), intdiv(self::add(self::mul($ar, $r), intdiv($d, 2)), $d));

        return self::add($whole, $part);
    }

    /**
     * Overflow-checked multiplication of non-negative integers.
     *
     * @throws ValidationException On overflow.
     */
    public static function mul(int $a, int $b): int
    {
        if ($a < 0 || $b < 0) {
            throw new ValidationException('amount', 'negative amount in money arithmetic');
        }
        if (0 !== $a && $b > intdiv(PHP_INT_MAX, $a)) {
            throw new ValidationException('amount', 'amount too large');
        }

        return $a * $b;
    }

    /**
     * Overflow-checked addition of non-negative integers.
     *
     * @throws ValidationException On overflow.
     */
    public static function add(int ...$values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            if ($value < 0) {
                throw new ValidationException('amount', 'negative amount in money arithmetic');
            }
            if ($value > PHP_INT_MAX - $sum) {
                throw new ValidationException('amount', 'amount too large');
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * Cost in nanos of a token count at a price in nanos per million tokens.
     */
    public static function perMillion(int $tokens, int $nanosPerMillion): int
    {
        return self::mulDiv($tokens, $nanosPerMillion, self::PER_MILLION);
    }

    /**
     * Cost in nanos of a quantity in millionths at a price in nanos per unit.
     */
    public static function perUnit(int $micros, int $nanosPerUnit): int
    {
        return self::mulDiv($micros, $nanosPerUnit, self::MICROS_PER_UNIT);
    }

    /**
     * Money for display: currency code and two decimal places.
     * Positive values under one cent show as "< 0.01".
     */
    public static function format(int $nanos, string $currency): string
    {
        $prefix = '' === $currency ? '' : $currency . ' ';
        if ($nanos > 0 && $nanos < 10000000) {
            return $prefix . '< 0.01';
        }
        $sign = $nanos < 0 ? '-' : '';
        $cents = intdiv(abs($nanos) + 5000000, 10000000);

        return $prefix . $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
