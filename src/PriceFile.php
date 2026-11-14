<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use JsonException;

/**
 * Parses and validates the price file format (schema 1).
 *
 * One bad row rejects the whole file. Every price is a decimal string with at
 * most nine places; anything else, including the placeholders in the example
 * file, is rejected so nothing is imported by accident.
 */
final class PriceFile
{
    public const MAX_BYTES = 1048576;
    public const UNITS = ['image', 'second', 'character', 'request'];
    public const PRICE_FIELDS = [
        'input_per_million', 'cached_input_per_million', 'output_per_million',
        'reasoning_per_million', 'unit_price', 'per_call_fee',
    ];

    /**
     * @return array{schema: int, currency: string, as_of: string|null, prices: list<array<string, string|null>>}
     * @throws PriceFileException
     */
    public static function parse(string $json, string $ledgerCurrency): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new PriceFileException(['file is larger than 1 MB']);
        }
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PriceFileException(['not valid JSON: ' . $e->getMessage()]);
        }
        if (!is_array($data)) {
            throw new PriceFileException(['top level must be an object']);
        }

        return self::validate($data, $ledgerCurrency);
    }

    /**
     * @param  array<mixed> $data
     * @return array{schema: int, currency: string, as_of: string|null, prices: list<array<string, string|null>>}
     * @throws PriceFileException
     */
    public static function validate(array $data, string $ledgerCurrency): array
    {
        $errors = [];
        if (1 !== ($data['schema'] ?? null)) {
            $errors[] = 'schema must be 1';
        }
        $currency = $data['currency'] ?? null;
        if (!is_string($currency) || !in_array($currency, Settings::CURRENCIES, true)) {
            $errors[] = 'currency must be a known ISO 4217 code';
            $currency = '';
        } elseif ($currency !== $ledgerCurrency) {
            $errors[] = sprintf('currency %s differs from the ledger currency %s', $currency, $ledgerCurrency);
        }
        $asOf = $data['as_of'] ?? null;
        if (null !== $asOf && !self::isDate($asOf)) {
            $errors[] = 'as_of must be null or a YYYY-MM-DD date';
        }
        $prices = $data['prices'] ?? null;
        if (!is_array($prices) || !array_is_list($prices)) {
            $errors[] = 'prices must be an array';
            $prices = [];
        }
        $rows = [];
        $seen = [];
        foreach ($prices as $i => $row) {
            $label = sprintf('row %d', $i + 1);
            if (!is_array($row)) {
                $errors[] = $label . ': must be an object';
                continue;
            }
            [$clean, $rowErrors] = self::row($row, $currency);
            foreach ($rowErrors as $error) {
                $errors[] = $label . ': ' . $error;
            }
            if ([] === $rowErrors) {
                $key = $clean['provider'] . '|' . $clean['model'];
                if (isset($seen[$key])) {
                    $errors[] = sprintf('%s: duplicate of row %d (%s %s)', $label, $seen[$key], $clean['provider'], $clean['model']);
                    continue;
                }
                $seen[$key] = $i + 1;
                $rows[] = $clean;
            }
        }
        if ([] !== $errors) {
            throw new PriceFileException($errors);
        }

        return [
            'schema' => 1,
            'currency' => (string) $currency,
            'as_of' => is_string($asOf) ? $asOf : null,
            'prices' => $rows,
        ];
    }

    /**
     * Validate one row. Provider and model are lower-cased.
     *
     * @param  array<mixed> $row
     * @return array{0: array<string, string|null>, 1: list<string>}
     */
    public static function row(array $row, string $currency): array
    {
        $errors = [];
        $provider = is_string($row['provider'] ?? null) ? strtolower($row['provider']) : null;
        if (!Pattern::isSlug($provider)) {
            $errors[] = 'provider must match ^[a-z0-9][a-z0-9_.-]{0,63}$';
        }
        $model = is_string($row['model'] ?? null) ? strtolower($row['model']) : null;
        if (null === $model || 1 !== preg_match('/^[^\x00-\x1f\x7f]{1,128}$/u', $model)) {
            $errors[] = 'model must be a string of 1 to 128 characters';
        }
        $clean = ['provider' => (string) $provider, 'model' => (string) $model];
        foreach (self::PRICE_FIELDS as $field) {
            $value = $row[$field] ?? null;
            if (null !== $value && !Money::isDecimal($value)) {
                $errors[] = sprintf('%s must be null or a decimal string with at most 9 places', $field);
            }
            $clean[$field] = is_string($value) ? $value : null;
        }
        $unit = $row['unit'] ?? null;
        if (null !== $unit && !in_array($unit, self::UNITS, true)) {
            $errors[] = 'unit must be null or one of ' . implode(', ', self::UNITS);
        }
        $clean['unit'] = is_string($unit) ? $unit : null;
        if ((null === $clean['unit']) !== (null === $clean['unit_price'])) {
            $errors[] = 'unit and unit_price must be given together';
        }
        if (null === $clean['unit_price']) {
            foreach (['input_per_million', 'output_per_million'] as $field) {
                if (null === $clean[$field]) {
                    $errors[] = sprintf('%s is required unless the row has a unit price', $field);
                }
            }
        }
        $rowCurrency = $row['currency'] ?? null;
        if (null !== $rowCurrency && $rowCurrency !== $currency) {
            $errors[] = 'currency must match the file currency';
        }
        $asOf = $row['as_of'] ?? null;
        if (!self::isDate($asOf)) {
            $errors[] = 'as_of must be a YYYY-MM-DD date';
        }
        $clean['as_of'] = is_string($asOf) ? $asOf : null;
        $source = $row['source'] ?? null;
        if (!self::isSource($source)) {
            $errors[] = 'source must be an http or https URL';
        }
        $clean['source'] = is_string($source) ? $source : null;

        return [$clean, $errors];
    }

    public static function isDate(mixed $value): bool
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isSource(mixed $value): bool
    {
        if (!is_string($value) || strlen($value) > 2048 || 1 === preg_match('/\s/', $value)) {
            return false;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);
        $host = parse_url($value, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && is_string($host) && '' !== $host;
    }

    /**
     * Encode rows as a price file. The top-level as_of is the oldest row date.
     *
     * @param list<array<string, string|null>> $rows
     */
    public static function encode(array $rows, string $currency): string
    {
        $dates = array_filter(array_column($rows, 'as_of'));
        sort($dates);
        $file = [
            'schema' => 1,
            'as_of' => $dates[0] ?? null,
            'currency' => $currency,
            'prices' => array_map(static fn (array $row): array => [
                'provider' => $row['provider'],
                'model' => $row['model'],
                'input_per_million' => $row['input_per_million'] ?? null,
                'cached_input_per_million' => $row['cached_input_per_million'] ?? null,
                'output_per_million' => $row['output_per_million'] ?? null,
                'reasoning_per_million' => $row['reasoning_per_million'] ?? null,
                'unit' => $row['unit'] ?? null,
                'unit_price' => $row['unit_price'] ?? null,
                'per_call_fee' => $row['per_call_fee'] ?? null,
                'as_of' => $row['as_of'] ?? null,
                'source' => $row['source'] ?? null,
            ], $rows),
        ];

        return (string) json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
