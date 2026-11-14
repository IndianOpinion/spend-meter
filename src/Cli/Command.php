<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Cli;

use IndianOpinion\SpendMeter\Cron;
use IndianOpinion\SpendMeter\KillSwitch;
use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\PriceFile;
use IndianOpinion\SpendMeter\PriceFileException;
use IndianOpinion\SpendMeter\PriceTable;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\SpendMeterException;
use IndianOpinion\SpendMeter\ValidationException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Meter model spend: status, reports, caps, kill switches, prices, reservations and export.
 *
 * Amounts are decimal strings in the ledger currency. Every command exits 0
 * on success and 1 on error.
 *
 * ## EXAMPLES
 *
 *     wp spend-meter status
 *     wp spend-meter kill on
 *     wp spend-meter caps set site --day=5
 *     wp spend-meter prices import prices.json --dry-run
 */
final class Command
{
    public const FORMATS = ['table', 'json', 'csv'];

    private Plugin $plugin;

    public function __construct(?Plugin $plugin = null)
    {
        $this->plugin = $plugin ?? Plugin::instance();
        Schema::maybeInstall($this->plugin->store(), $this->plugin->clock());
    }

    /**
     * Kill switches, today and month against caps, reservations, unpriced rows, last error, timezone, prices.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $format = $this->format($assoc);
        $p = $this->plugin;
        $blogId = (int) get_current_blog_id();
        $summary = $p->widget()->build($blogId);
        $currency = (string) $summary['currency'];
        $totals = (array) $summary['totals'];
        $site = $p->caps()->site($blogId);
        $kill = $p->kill();
        $limit = static fn (?int $v): string => null === $v ? 'none' : Money::format($v, $currency);
        $error = $summary['last_account_error'];
        $rows = [
            ['item' => 'kill_switch', 'value' => ($kill->globalOn() ? 'on' : 'off') . (KillSwitch::constantOn() ? ' (constant)' : '')],
            ['item' => 'purpose_kill', 'value' => implode(', ', $kill->purposes()) ?: 'none'],
            ['item' => 'today', 'value' => Money::format((int) $totals['day_total'], $currency) . ' of ' . $limit($site['day'])],
            ['item' => 'month', 'value' => Money::format((int) $totals['month_total'], $currency) . ' of ' . $limit($site['month'])],
            ['item' => 'live_reservations', 'value' => (string) $totals['live_reservations']],
            ['item' => 'unpriced_today', 'value' => (string) $totals['unpriced_today']],
            ['item' => 'unreserved_today', 'value' => (string) $totals['unreserved_today']],
            ['item' => 'last_account_error', 'value' => is_array($error)
                ? sprintf('%s %s HTTP %d at %s UTC', $error['provider'], $error['model'], $error['http_status'], $error['created_at'])
                : 'none'],
            ['item' => 'timezone', 'value' => $p->clock()->timezone()->getName() . ' (day ' . $p->clock()->day() . ')'],
            ['item' => 'prices', 'value' => $p->prices()->isEmpty() ? 'no prices loaded' : 'oldest ' . (string) $p->prices()->oldestAsOf()],
            ['item' => 'ledger', 'value' => Schema::ready() ? 'ready' : 'not ready'],
            ['item' => 'record_losses', 'value' => (string) $p->counters()->losses()['count']],
        ];
        Utils\format_items($format, $rows, ['item', 'value']);
    }

    /**
     * Totals and token counts grouped by purpose, model, caller, provider or day.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : First day, YYYY-MM-DD. Default: first day of this month.
     *
     * [--to=<date>]
     * : Last day, YYYY-MM-DD. Default: today.
     *
     * [--by=<column>]
     * : purpose, model, caller, provider or day.
     * ---
     * default: purpose
     * ---
     *
     * [--include-tests]
     * : Include test rows.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function report(array $args, array $assoc): void
    {
        $format = $this->format($assoc);
        [$from, $to] = $this->range($assoc);
        $by = (string) ($assoc['by'] ?? 'purpose');
        if (!in_array($by, ['purpose', 'model', 'caller', 'provider', 'day'], true)) {
            $this->fail('--by must be purpose, model, caller, provider or day.');
        }
        $currency = $this->plugin->settings()->all()['currency'];
        $rows = $this->guard(fn (): array => $this->plugin->store()->report(
            $from,
            $to,
            $by,
            isset($assoc['include-tests']),
            (int) get_current_blog_id()
        ));
        $out = [];
        foreach ($rows as $row) {
            $line = [$by => $row['key']];
            foreach (['calls', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens'] as $column) {
                $line[$column] = $row[$column];
            }
            $line['cost'] = Money::nanosToDecimal($row['cost']) . ' ' . $currency;
            $out[] = $line;
        }
        Utils\format_items($format, $out, [$by, 'calls', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'cost']);
    }

    /**
     * Dry-run pre-spend decision. Writes nothing.
     *
     * ## OPTIONS
     *
     * --purpose=<purpose>
     * : Purpose slug.
     *
     * --caller=<caller>
     * : Calling plugin slug.
     *
     * --provider=<provider>
     * : Provider slug.
     *
     * --model=<model>
     * : Model id.
     *
     * [--in=<tokens>]
     * : Estimated input tokens.
     *
     * [--out=<tokens>]
     * : Maximum output tokens.
     *
     * [--format=<format>]
     * : table, json or csv.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $format = $this->format($assoc);
        $intent = [
            'purpose' => $assoc['purpose'] ?? null,
            'caller' => $assoc['caller'] ?? null,
            'provider' => $assoc['provider'] ?? null,
            'model' => $assoc['model'] ?? null,
            'dry_run' => true,
        ];
        if (isset($assoc['in'])) {
            $intent['est_input_tokens'] = $assoc['in'];
        }
        if (isset($assoc['out'])) {
            $intent['max_output_tokens'] = $assoc['out'];
        }
        $decision = $this->plugin->decision()->check($intent);
        $currency = $this->plugin->settings()->all()['currency'];
        $rows = [];
        foreach ($decision as $key => $value) {
            if (('estimate' === $key || 'remaining' === $key) && is_int($value)) {
                $value = Money::nanosToDecimal($value) . ' ' . $currency;
            }
            $rows[] = ['key' => $key, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : (null === $value ? 'null' : (string) $value)];
        }
        Utils\format_items($format, $rows, ['key', 'value']);
    }

    /**
     * Manage caps: caps list | caps set <scope> [<purpose>] [--day=<amount>] [--month=<amount>] | caps unset <scope> [<purpose>] [--day] [--month].
     *
     * Scope is site, purpose or network. Purpose may end in * to match a prefix.
     *
     * ## EXAMPLES
     *
     *     wp spend-meter caps set site --day=5 --month=100
     *     wp spend-meter caps set purpose translate --day=1
     *     wp spend-meter caps unset purpose translate
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function caps(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'list';
        $blogId = (int) get_current_blog_id();
        $caps = $this->plugin->caps();
        $currency = $this->plugin->settings()->all()['currency'];
        if ('list' === $action) {
            $format = $this->format($assoc);
            $show = static fn (?int $v): string => null === $v ? '' : Money::nanosToDecimal($v);
            $rows = [];
            $site = $caps->site($blogId);
            $rows[] = ['scope' => 'site:' . $blogId, 'day' => $show($site['day']), 'month' => $show($site['month']), 'currency' => $currency];
            foreach ($caps->purposes($blogId) as $pattern => $limit) {
                $rows[] = ['scope' => 'purpose:' . $blogId . ':' . $pattern, 'day' => $show($limit['day']), 'month' => $show($limit['month']), 'currency' => $currency];
            }
            if (is_multisite()) {
                $net = $caps->network();
                $rows[] = ['scope' => 'network', 'day' => $show($net['day']), 'month' => $show($net['month']), 'currency' => $currency];
            }
            Utils\format_items($format, $rows, ['scope', 'day', 'month', 'currency']);
            return;
        }
        $scope = $args[1] ?? '';
        $purpose = 'purpose' === $scope ? ($args[2] ?? null) : null;
        if ('set' === $action) {
            $day = $this->amount($assoc, 'day');
            $month = $this->amount($assoc, 'month');
            if (null === $day && null === $month) {
                $this->fail('Give --day=<amount>, --month=<amount> or both.');
            }
            $this->guard(static fn () => $caps->set($scope, $blogId, $purpose, $day, $month));
            WP_CLI::success('Cap set.');
            return;
        }
        if ('unset' === $action) {
            $day = isset($assoc['day']);
            $month = isset($assoc['month']);
            if (!$day && !$month) {
                $day = $month = true;
            }
            $this->guard(static fn () => $caps->unset($scope, $blogId, $purpose, $day, $month));
            WP_CLI::success('Cap removed.');
            return;
        }
        $this->fail('Usage: caps list | caps set <scope> [<purpose>] | caps unset <scope> [<purpose>]');
    }

    /**
     * Kill switches: kill on | kill off [--purpose=<p>] | kill status. Works without the admin screen.
     *
     * ## EXAMPLES
     *
     *     wp spend-meter kill on
     *     wp spend-meter kill on --purpose=translate
     *     wp spend-meter kill off --purpose='shadow*'
     *     wp spend-meter kill status
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function kill(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'status';
        $kill = $this->plugin->kill();
        if ('status' === $action) {
            $format = $this->format($assoc);
            $rows = [['scope' => 'global', 'state' => $kill->globalOn() ? 'on' : 'off', 'source' => KillSwitch::constantOn() ? 'constant' : 'option']];
            $constant = KillSwitch::constantPurposes();
            foreach ($kill->purposes() as $pattern) {
                $rows[] = ['scope' => 'purpose:' . $pattern, 'state' => 'on', 'source' => in_array($pattern, $constant, true) ? 'constant' : 'option'];
            }
            Utils\format_items($format, $rows, ['scope', 'state', 'source']);
            return;
        }
        if (!in_array($action, ['on', 'off'], true)) {
            $this->fail('Usage: kill on|off [--purpose=<p>] | kill status');
        }
        $on = 'on' === $action;
        $purpose = isset($assoc['purpose']) ? (string) $assoc['purpose'] : null;
        $this->guard(static function () use ($kill, $on, $purpose): void {
            if (null === $purpose) {
                $kill->setGlobal($on, 'cli');
            } else {
                $kill->setPurpose($purpose, $on, 'cli');
            }
        });
        WP_CLI::success(sprintf('%s kill switch %s.', null === $purpose ? 'Global' : 'Purpose ' . $purpose, $on ? 'on' : 'off'));
    }

    /**
     * Price table: prices list [--stale] | set | unset | import | export | unpriced.
     *
     * ## EXAMPLES
     *
     *     wp spend-meter prices list --stale
     *     wp spend-meter prices set example-provider example-model --in=<decimal> --out=<decimal> --as-of=YYYY-MM-DD --source=https://provider.example/pricing
     *     wp spend-meter prices unset example-provider example-model
     *     wp spend-meter prices import prices.json --mode=merge --dry-run
     *     wp spend-meter prices export --overrides-only > prices.json
     *     wp spend-meter prices unpriced
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function prices(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'list';
        $prices = $this->plugin->prices();
        $settings = $this->plugin->settings()->all();
        switch ($action) {
            case 'list':
                $format = $this->format($assoc);
                $rows = isset($assoc['stale'])
                    ? $prices->stale($settings['stale_days'], $this->plugin->clock()->day())
                    : array_values($prices->effective());
                $dec = static fn (?int $v): string => null === $v ? '' : Money::nanosToDecimal($v);
                $out = [];
                foreach ($rows as $row) {
                    $out[] = [
                        'provider' => $row['provider'], 'model' => $row['model'], 'input' => $dec($row['input']),
                        'cached_input' => $dec($row['cached_input']), 'output' => $dec($row['output']),
                        'reasoning' => $dec($row['reasoning']), 'unit' => (string) $row['unit'],
                        'unit_price' => $dec($row['unit_price']), 'fee' => $dec($row['per_call_fee']),
                        'as_of' => (string) $row['as_of'], 'source' => (string) $row['source'],
                        'origin' => $row['origin'], 'version' => $row['version'],
                    ];
                }
                if ([] === $out && !isset($assoc['stale'])) {
                    WP_CLI::warning('No prices loaded. Every model is unpriced.');
                }
                Utils\format_items($format, $out, ['provider', 'model', 'input', 'cached_input', 'output', 'reasoning', 'unit', 'unit_price', 'fee', 'as_of', 'source', 'origin', 'version']);
                return;
            case 'set':
                $row = [
                    'provider' => $args[1] ?? null,
                    'model' => $args[2] ?? null,
                    'input_per_million' => $assoc['in'] ?? null,
                    'cached_input_per_million' => $assoc['cached'] ?? null,
                    'output_per_million' => $assoc['out'] ?? null,
                    'reasoning_per_million' => $assoc['reasoning'] ?? null,
                    'unit' => $assoc['unit'] ?? null,
                    'unit_price' => $assoc['unit-price'] ?? null,
                    'per_call_fee' => $assoc['fee'] ?? null,
                    'as_of' => $assoc['as-of'] ?? null,
                    'source' => $assoc['source'] ?? null,
                ];
                $this->guard(static fn () => $prices->setOverride($row));
                $this->plugin->resetPrices();
                WP_CLI::success('Price set.');
                return;
            case 'unset':
                if (!$prices->unsetOverride((string) ($args[1] ?? ''), (string) ($args[2] ?? ''))) {
                    $this->fail('No override for that provider and model.');
                }
                $this->plugin->resetPrices();
                WP_CLI::success('Override removed.');
                return;
            case 'import':
                $file = $args[1] ?? '';
                if ('' === $file || !is_file($file) || !is_readable($file)) {
                    $this->fail('Give a readable price file.');
                }
                if (filesize($file) > PriceFile::MAX_BYTES) {
                    $this->fail('The price file is larger than 1 MB.');
                }
                $json = (string) file_get_contents($file);
                $mode = (string) ($assoc['mode'] ?? 'merge');
                $dryRun = isset($assoc['dry-run']);
                $diff = $this->guard(static fn (): array => $prices->import($json, $mode, $dryRun));
                foreach (['added', 'changed', 'removed'] as $kind) {
                    foreach ($diff[$kind] as $label) {
                        WP_CLI::line(sprintf('%s %s', ['added' => '+', 'changed' => '~', 'removed' => '-'][$kind], $label));
                    }
                }
                WP_CLI::line(sprintf('%d unchanged', $diff['unchanged']));
                $this->plugin->resetPrices();
                WP_CLI::success($dryRun ? 'Dry run: nothing saved.' : 'Prices imported.');
                return;
            case 'export':
                WP_CLI::line(rtrim($prices->export(isset($assoc['overrides-only']))));
                return;
            case 'unpriced':
                $format = $this->format($assoc);
                $since = (new \DateTimeImmutable($this->plugin->clock()->day()))->modify('-30 days')->format('Y-m-d');
                $rows = $this->guard(fn (): array => $this->plugin->store()->unpricedModels($since));
                Utils\format_items($format, $rows, ['provider', 'model', 'calls']);
                return;
        }
        $this->fail('Usage: prices list|set|unset|import|export|unpriced');
    }

    /**
     * Fill cost for unpriced rows only, once a price exists. Never changes any other row.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : First day. Default: first day of this month.
     *
     * [--to=<date>]
     * : Last day. Default: today.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function reprice(array $args, array $assoc): void
    {
        [$from, $to] = $this->range($assoc);
        $count = $this->guard(fn (): int => self::repriceRows($this->plugin, $from, $to, isset($assoc['dry-run'])));
        WP_CLI::success(sprintf('%d rows %s.', $count, isset($assoc['dry-run']) ? 'would be repriced' : 'repriced'));
    }

    /**
     * Reprice unpriced rows in a day range. Returns how many rows were (or would be) filled.
     */
    public static function repriceRows(Plugin $plugin, string $from, string $to, bool $dryRun): int
    {
        $store = $plugin->store();
        $prices = $plugin->prices();
        $count = 0;
        $after = 0;
        do {
            $rows = $store->unpricedRows($from, $to, $after, 500);
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $price = $prices->lookup((string) $row['provider'], (string) $row['model']);
                if (null === $price) {
                    continue;
                }
                $cost = PriceTable::cost(
                    $price,
                    (int) $row['input_tokens'],
                    (int) $row['cached_input_tokens'],
                    (int) $row['output_tokens'],
                    (int) $row['reasoning_tokens'],
                    Money::toMicros((string) $row['units'])
                );
                if (!$dryRun) {
                    $store->updateIf(
                        $after,
                        ['cost' => $cost, 'cost_source' => 'table_late', 'price_version' => $price['version']],
                        'cost_source',
                        ['unpriced']
                    );
                }
                $count++;
            }
        } while (count($rows) === 500);

        return $count;
    }

    /**
     * Reservations: reservations list | reservations expire.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function reservations(array $args, array $assoc): void
    {
        $action = $args[0] ?? 'list';
        if ('expire' === $action) {
            $count = $this->guard(fn (): int => $this->plugin->ledger()->expire());
            WP_CLI::success(sprintf('%d reservations marked expired.', $count));
            return;
        }
        if ('list' !== $action) {
            $this->fail('Usage: reservations list | reservations expire');
        }
        $format = $this->format($assoc);
        $rows = $this->guard(fn (): array => $this->plugin->store()->reservations(500));
        $cutoff = $this->plugin->clock()->utc(-$this->plugin->settings()->reservationSeconds());
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'reservation_id' => (string) $row['reservation_id'],
                'created_at' => (string) $row['created_at'],
                'purpose' => (string) $row['purpose'],
                'caller' => (string) $row['caller'],
                'model' => (string) $row['model'],
                'estimate' => Money::nanosToDecimal((int) $row['estimate']),
                'live' => (string) $row['created_at'] >= $cutoff ? 'yes' : 'expired',
            ];
        }
        Utils\format_items($format, $out, ['reservation_id', 'created_at', 'purpose', 'caller', 'model', 'estimate', 'live']);
    }

    /**
     * Write the ledger to stdout as CSV or JSON lines.
     *
     * ## OPTIONS
     *
     * --from=<date>
     * : First day, YYYY-MM-DD.
     *
     * --to=<date>
     * : Last day, YYYY-MM-DD.
     *
     * [--format=<format>]
     * : csv or jsonl.
     * ---
     * default: csv
     * ---
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        if (!isset($assoc['from'], $assoc['to'])) {
            $this->fail('--from and --to are required.');
        }
        [$from, $to] = $this->range($assoc);
        $format = (string) ($assoc['format'] ?? 'csv');
        if (!in_array($format, ['csv', 'jsonl'], true)) {
            $this->fail('--format must be csv or jsonl.');
        }
        $columns = array_keys(Schema::COLUMNS);
        if ('csv' === $format) {
            WP_CLI::line(self::csvLine($columns));
        }
        $after = 0;
        do {
            $rows = $this->guard(fn (): array => $this->plugin->store()->rowsBetween($from, $to, $after, 1000));
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $ordered = [];
                foreach ($columns as $column) {
                    $ordered[$column] = $row[$column] ?? null;
                }
                WP_CLI::line('csv' === $format ? self::csvLine(array_values($ordered)) : (string) wp_json_encode($ordered));
            }
        } while (count($rows) === 1000);
    }

    /**
     * Remove settled rows older than a number of months, in batches of 1,000. Prints counts first.
     *
     * ## OPTIONS
     *
     * --older-than=<months>
     * : Months to keep.
     *
     * [--dry-run]
     * : Count only.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $months = (string) ($assoc['older-than'] ?? '');
        if (1 !== preg_match('/^[1-9]\d{0,3}$/', $months)) {
            $this->fail('--older-than must be a whole number of months, 1 or more.');
        }
        $cutoff = Cron::cutoff($this->plugin->clock(), (int) $months);
        $count = $this->guard(fn (): int => $this->plugin->store()->countSettledBefore($cutoff));
        WP_CLI::line(sprintf('%d rows created before %s UTC are eligible.', $count, $cutoff));
        if (isset($assoc['dry-run'])) {
            WP_CLI::success('Dry run: nothing removed.');
            return;
        }
        $removed = $this->guard(fn (): int => Cron::prune($this->plugin, (int) $months));
        WP_CLI::success(sprintf('%d rows removed.', $removed));
    }

    /**
     * Print an error and exit with code 1.
     */
    private function fail(string $message): never
    {
        WP_CLI::error($message);
        exit(1);
    }

    /**
     * @param list<mixed> $values
     */
    private static function csvLine(array $values): string
    {
        $out = [];
        foreach ($values as $value) {
            $value = null === $value ? '' : (string) $value;
            $out[] = 1 === preg_match('/[",\r\n]/', $value) ? '"' . str_replace('"', '""', $value) . '"' : $value;
        }

        return implode(',', $out);
    }

    /**
     * @param array<string, string|bool> $assoc
     */
    private function format(array $assoc): string
    {
        $format = (string) ($assoc['format'] ?? 'table');
        if (!in_array($format, self::FORMATS, true)) {
            $this->fail('--format must be table, json or csv.');
        }

        return $format;
    }

    /**
     * @param array<string, string|bool> $assoc
     */
    private function amount(array $assoc, string $key): ?int
    {
        if (!isset($assoc[$key])) {
            return null;
        }
        try {
            return Money::decimalToNanos((string) $assoc[$key], '--' . $key);
        } catch (ValidationException $e) {
            $this->fail($e->getMessage());
        }
    }

    /**
     * @param  array<string, string|bool> $assoc
     * @return array{0: string, 1: string}
     */
    private function range(array $assoc): array
    {
        $from = (string) ($assoc['from'] ?? substr($this->plugin->clock()->day(), 0, 8) . '01');
        $to = (string) ($assoc['to'] ?? $this->plugin->clock()->day());
        if (!PriceFile::isDate($from) || !PriceFile::isDate($to) || $from > $to) {
            $this->fail('--from and --to must be YYYY-MM-DD dates with from on or before to.');
        }

        return [$from, $to];
    }

    /**
     * Run an operation and turn spend-meter exceptions into a CLI error (exit 1).
     *
     * @template T
     * @param  callable(): T $operation
     * @return T
     */
    private function guard(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (PriceFileException $e) {
            foreach ($e->errors() as $error) {
                WP_CLI::warning($error);
            }
            $this->fail('Rejected; nothing was changed.');
        } catch (SpendMeterException $e) {
            $this->fail($e->getMessage());
        }
    }
}
