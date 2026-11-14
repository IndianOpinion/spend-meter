<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * The effective price table: operator overrides over the shipped seed.
 *
 * Lookup is by exact lower-cased provider and model; failing that, a row whose
 * model ends in "*" matches by prefix and the longest prefix wins. There is no
 * default price. Each effective row has a price_version, a short hash of its
 * contents, stored on every ledger row it priced.
 *
 * @phpstan-type Price array{provider: string, model: string, input: int, cached_input: int|null,
 *     output: int, reasoning: int|null, unit: string|null, unit_price: int|null, per_call_fee: int|null,
 *     as_of: string|null, source: string|null, origin: string, version: string}
 * @phpstan-type Row array<string, string|null>
 */
final class PriceTable
{
    /** @var array<string, Price>|null */
    private ?array $effective = null;

    private bool $corrupt = false;

    private bool $seedInvalid = false;

    /** @var list<Row>|null */
    private ?array $seed = null;

    public function __construct(private string $seedPath, private string $currency)
    {
    }

    /**
     * Every effective price, keyed by "provider|model".
     *
     * @return array<string, Price>
     */
    public function effective(): array
    {
        if (null !== $this->effective) {
            return $this->effective;
        }
        $table = [];
        foreach ($this->seedRows() as $row) {
            $table[$row['provider'] . '|' . $row['model']] = $this->compile($row, 'seed');
        }
        foreach ($this->overrides() as $row) {
            $table[$row['provider'] . '|' . $row['model']] = $this->compile($row, 'override');
        }
        ksort($table);
        $this->effective = $table;

        return $table;
    }

    public function isEmpty(): bool
    {
        return [] === $this->effective();
    }

    /** True when the override option was unreadable and the seed is in use. */
    public function corrupt(): bool
    {
        $this->effective();

        return $this->corrupt;
    }

    public function seedInvalid(): bool
    {
        $this->effective();

        return $this->seedInvalid;
    }

    public function flush(): void
    {
        $this->effective = null;
    }

    /**
     * Find the price for a provider and model, then pass it through the
     * spend_meter_price filter.
     *
     * @return Price|null
     */
    public function lookup(string $provider, string $model): ?array
    {
        $provider = strtolower($provider);
        $model = strtolower($model);
        $table = $this->effective();
        $price = $table[$provider . '|' . $model] ?? null;
        if (null === $price) {
            $best = -1;
            foreach ($table as $row) {
                if ($row['provider'] !== $provider || !str_ends_with($row['model'], '*')) {
                    continue;
                }
                $prefix = substr($row['model'], 0, -1);
                if (str_starts_with($model, $prefix) && strlen($prefix) > $best) {
                    $best = strlen($prefix);
                    $price = $row;
                }
            }
        }
        $filtered = apply_filters('spend_meter_price', $price, $provider, $model);
        if ($filtered === $price) {
            return $price;
        }
        if (null === $filtered) {
            return null;
        }
        if (is_array($filtered) && is_int($filtered['input'] ?? null) && is_int($filtered['output'] ?? null)) {
            return $this->normaliseFiltered($filtered, $provider, $model);
        }
        Logger::error('price_filter', 'spend_meter_price returned an unusable value; ignored');

        return $price;
    }

    /**
     * Estimated cost of a call: input tokens at the input price, the output
     * allowance at the output price, units at the unit price, plus the fee.
     *
     * @param Price $price
     */
    public static function estimate(array $price, int $inputTokens, int $outputTokens, int $unitMicros): int
    {
        return Money::add(
            Money::perMillion($inputTokens, $price['input']),
            Money::perMillion($outputTokens, $price['output']),
            null === $price['unit_price'] ? 0 : Money::perUnit($unitMicros, $price['unit_price']),
            $price['per_call_fee'] ?? 0
        );
    }

    /**
     * Cost of a recorded call. Cached tokens are the part of the input priced
     * at the cached price; reasoning tokens are the part of the output priced
     * at the reasoning price when the row has one.
     *
     * @param Price $price
     */
    public static function cost(
        array $price,
        int $input,
        int $cached,
        int $output,
        int $reasoning,
        int $unitMicros
    ): int {
        $cached = min($cached, $input);
        $reasoning = min($reasoning, $output);

        return Money::add(
            Money::perMillion($input - $cached, $price['input']),
            Money::perMillion($cached, $price['cached_input'] ?? $price['input']),
            Money::perMillion($output - $reasoning, $price['output']),
            Money::perMillion($reasoning, $price['reasoning'] ?? $price['output']),
            null === $price['unit_price'] ? 0 : Money::perUnit($unitMicros, $price['unit_price']),
            $price['per_call_fee'] ?? 0
        );
    }

    /**
     * Operator override rows in file shape.
     *
     * @return list<Row>
     */
    public function overrides(): array
    {
        $stored = Options::get(Options::PRICES, null);
        if (null === $stored || false === $stored) {
            return [];
        }
        if (!is_array($stored)) {
            $this->markCorrupt();
            return [];
        }
        try {
            $file = PriceFile::validate(
                ['schema' => 1, 'currency' => $this->currency, 'as_of' => null, 'prices' => $stored['rows'] ?? null],
                $this->currency
            );
        } catch (PriceFileException) {
            $this->markCorrupt();
            return [];
        }

        return $file['prices'];
    }

    /**
     * Seed rows from the shipped file. A missing or invalid seed gives none.
     *
     * @return list<Row>
     */
    public function seedRows(): array
    {
        if (null === $this->seed) {
            $this->seed = $this->readSeed();
        }

        return $this->seed;
    }

    /**
     * @return list<Row>
     */
    private function readSeed(): array
    {
        if (!is_readable($this->seedPath)) {
            $this->seedInvalid = true;
            Logger::error('seed_missing', 'price seed file is missing; every model is unpriced');
            return [];
        }
        $json = file_get_contents($this->seedPath);
        try {
            return PriceFile::parse(false === $json ? '' : $json, $this->currency)['prices'];
        } catch (PriceFileException $e) {
            $this->seedInvalid = true;
            Logger::error('seed_invalid', 'price seed file is invalid; every model is unpriced: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Set or replace one override row.
     *
     * @param  array<mixed> $row
     * @throws PriceFileException
     */
    public function setOverride(array $row): void
    {
        [$clean, $errors] = PriceFile::row($row, $this->currency);
        if ([] !== $errors) {
            throw new PriceFileException($errors);
        }
        $rows = [];
        foreach ($this->overrides() as $existing) {
            if ($existing['provider'] !== $clean['provider'] || $existing['model'] !== $clean['model']) {
                $rows[] = $existing;
            }
        }
        $rows[] = $clean;
        $this->saveOverrides($rows);
    }

    public function unsetOverride(string $provider, string $model): bool
    {
        $provider = strtolower($provider);
        $model = strtolower($model);
        $rows = [];
        $found = false;
        foreach ($this->overrides() as $row) {
            if ($row['provider'] === $provider && $row['model'] === $model) {
                $found = true;
                continue;
            }
            $rows[] = $row;
        }
        if ($found) {
            $this->saveOverrides($rows);
        }

        return $found;
    }

    /**
     * Import a price file. Merge adds and replaces overrides; replace makes
     * the file the whole override set. Returns the diff against the current
     * effective table; a dry run writes nothing.
     *
     * @return array{added: list<string>, changed: list<string>, removed: list<string>, unchanged: int}
     * @throws PriceFileException
     */
    public function import(string $json, string $mode = 'merge', bool $dryRun = false): array
    {
        if (!in_array($mode, ['merge', 'replace'], true)) {
            throw new PriceFileException(['mode must be merge or replace']);
        }
        $file = PriceFile::parse($json, $this->currency);
        $new = [];
        if ('merge' === $mode) {
            foreach ($this->overrides() as $row) {
                $new[$row['provider'] . '|' . $row['model']] = $row;
            }
        }
        foreach ($file['prices'] as $row) {
            $new[$row['provider'] . '|' . $row['model']] = $row;
        }
        $before = $this->effective();
        $after = [];
        foreach ($this->seedRows() as $row) {
            $after[$row['provider'] . '|' . $row['model']] = $this->compile($row, 'seed');
        }
        foreach ($new as $key => $row) {
            $after[$key] = $this->compile($row, 'override');
        }
        $diff = ['added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0];
        foreach ($after as $key => $row) {
            $label = str_replace('|', ' ', $key);
            if (!isset($before[$key])) {
                $diff['added'][] = $label;
            } elseif ($before[$key]['version'] !== $row['version']) {
                $diff['changed'][] = $label;
            } else {
                $diff['unchanged']++;
            }
        }
        foreach (array_keys(array_diff_key($before, $after)) as $key) {
            $diff['removed'][] = str_replace('|', ' ', $key);
        }
        if (!$dryRun) {
            $this->saveOverrides(array_values($new));
        }

        return $diff;
    }

    public function export(bool $overridesOnly = false): string
    {
        if ($overridesOnly) {
            return PriceFile::encode($this->overrides(), $this->currency);
        }
        $rows = [];
        foreach ($this->effective() as $price) {
            $rows[] = $this->decompile($price);
        }

        return PriceFile::encode($rows, $this->currency);
    }

    /**
     * Effective rows whose as_of is older than the threshold.
     *
     * @return list<Price>
     */
    public function stale(int $days, string $today): array
    {
        $limit = (new \DateTimeImmutable($today . ' 00:00:00'))->modify('-' . $days . ' days')->format('Y-m-d');
        $out = [];
        foreach ($this->effective() as $price) {
            if (null === $price['as_of'] || $price['as_of'] < $limit) {
                $out[] = $price;
            }
        }

        return $out;
    }

    public function oldestAsOf(): ?string
    {
        $dates = array_filter(array_column($this->effective(), 'as_of'));
        sort($dates);

        return $dates[0] ?? null;
    }

    /**
     * @param Row $row
     * @return Price
     */
    private function compile(array $row, string $origin): array
    {
        $nanos = static fn (?string $value): ?int => null === $value ? null : Money::decimalToNanos($value);
        $canonical = $row;
        ksort($canonical);
        $canonical['currency'] = $this->currency;

        return [
            'provider' => (string) $row['provider'],
            'model' => (string) $row['model'],
            'input' => $nanos($row['input_per_million'] ?? null) ?? 0,
            'cached_input' => $nanos($row['cached_input_per_million'] ?? null),
            'output' => $nanos($row['output_per_million'] ?? null) ?? 0,
            'reasoning' => $nanos($row['reasoning_per_million'] ?? null),
            'unit' => $row['unit'] ?? null,
            'unit_price' => $nanos($row['unit_price'] ?? null),
            'per_call_fee' => $nanos($row['per_call_fee'] ?? null),
            'as_of' => $row['as_of'] ?? null,
            'source' => $row['source'] ?? null,
            'origin' => $origin,
            'version' => substr(sha1((string) json_encode($canonical)), 0, 12),
        ];
    }

    /**
     * @param  Price $price
     * @return Row
     */
    private function decompile(array $price): array
    {
        $dec = static fn (?int $value): ?string => null === $value ? null : Money::nanosToDecimal($value);

        return [
            'provider' => $price['provider'],
            'model' => $price['model'],
            'input_per_million' => $dec($price['input']),
            'cached_input_per_million' => $dec($price['cached_input']),
            'output_per_million' => $dec($price['output']),
            'reasoning_per_million' => $dec($price['reasoning']),
            'unit' => $price['unit'],
            'unit_price' => $dec($price['unit_price']),
            'per_call_fee' => $dec($price['per_call_fee']),
            'as_of' => $price['as_of'],
            'source' => $price['source'],
        ];
    }

    /**
     * @param  array<mixed> $price
     * @return Price
     */
    private function normaliseFiltered(array $price, string $provider, string $model): array
    {
        $int = static fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : null;
        $out = [
            'provider' => $provider,
            'model' => $model,
            'input' => max(0, (int) $price['input']),
            'cached_input' => $int($price['cached_input'] ?? null),
            'output' => max(0, (int) $price['output']),
            'reasoning' => $int($price['reasoning'] ?? null),
            'unit' => is_string($price['unit'] ?? null) ? $price['unit'] : null,
            'unit_price' => $int($price['unit_price'] ?? null),
            'per_call_fee' => $int($price['per_call_fee'] ?? null),
            'as_of' => is_string($price['as_of'] ?? null) ? $price['as_of'] : null,
            'source' => is_string($price['source'] ?? null) ? $price['source'] : null,
            'origin' => 'filter',
            'version' => '',
        ];
        $version = $price['version'] ?? null;
        $out['version'] = is_string($version) && 1 === preg_match('/^[a-z0-9]{1,12}$/', $version)
            ? $version
            : substr(sha1((string) json_encode($out)), 0, 12);

        return $out;
    }

    /**
     * @param list<Row> $rows
     */
    private function saveOverrides(array $rows): void
    {
        usort($rows, static fn (array $a, array $b): int => [$a['provider'], $a['model']] <=> [$b['provider'], $b['model']]);
        Options::set(Options::PRICES, ['currency' => $this->currency, 'rows' => $rows]);
        $this->corrupt = false;
        $this->flush();
    }

    private function markCorrupt(): void
    {
        $this->corrupt = true;
        Logger::error('prices_corrupt', 'price override option is corrupt; using the seed file only');
    }
}
