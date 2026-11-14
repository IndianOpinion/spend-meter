<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use CliExit;
use IndianOpinion\SpendMeter\Cli\Command;
use IndianOpinion\SpendMeter\Tests\TestCase;
use WP_CLI;
use WpStub;

/**
 * WP-CLI: section 5.6, cases 68 to 72.
 */
final class CliTest extends TestCase
{
    private Command $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new Command($this->plugin);
        $this->price('acme', 'text-1', '2', '8');
    }

    /**
     * Run a command; return [exit code, output].
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @return array{0: int, 1: string}
     */
    private function cli(string $method, array $args = [], array $assoc = []): array
    {
        WP_CLI::reset();
        try {
            $this->command->{$method}($args, $assoc);
            return [0, WP_CLI::text()];
        } catch (CliExit $e) {
            return [$e->getCode(), WP_CLI::text()];
        }
    }

    public function testT68EveryCommandRunsWithFormatsAndExitCodes(): void
    {
        $this->record(['purpose' => 'summary', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1000]);
        $this->check();
        $ok = [
            ['status', [], []],
            ['report', [], ['by' => 'model']],
            ['check', [], ['purpose' => 'summary', 'caller' => 'c', 'provider' => 'acme', 'model' => 'text-1', 'in' => '10', 'out' => '10']],
            ['caps', ['list'], []],
            ['caps', ['set', 'site'], ['day' => '5']],
            ['caps', ['set', 'purpose', 'summary'], ['month' => '9.5']],
            ['caps', ['unset', 'purpose', 'summary'], []],
            ['kill', ['status'], []],
            ['kill', ['on'], ['purpose' => 'shadow*']],
            ['kill', ['off'], ['purpose' => 'shadow*']],
            ['prices', ['list'], []],
            ['prices', ['list'], ['stale' => true]],
            ['prices', ['export'], []],
            ['prices', ['unpriced'], []],
            ['reprice', [], ['dry-run' => true]],
            ['reservations', ['list'], []],
            ['reservations', ['expire'], []],
            ['export', [], ['from' => '2026-11-01', 'to' => '2026-11-30']],
            ['prune', [], ['older-than' => '12', 'dry-run' => true]],
        ];
        foreach ($ok as [$method, $args, $assoc]) {
            [$code, $out] = $this->cli($method, $args, $assoc);
            $this->assertSame(0, $code, $method . ' ' . implode(' ', $args) . ': ' . $out);
        }
        foreach (['table', 'json', 'csv'] as $format) {
            foreach ([['status', []], ['report', []], ['caps', ['list']], ['kill', ['status']], ['prices', ['list']], ['reservations', ['list']]] as [$method, $args]) {
                [$code, $out] = $this->cli($method, $args, ['format' => $format]);
                $this->assertSame(0, $code, $method . ' ' . $format);
                if ('json' === $format) {
                    $this->assertIsArray(json_decode($out, true), $method . ' json');
                }
            }
        }
        $bad = [
            ['status', [], ['format' => 'yaml']],
            ['report', [], ['by' => 'colour']],
            ['report', [], ['from' => '2026-11-30', 'to' => '2026-11-01']],
            ['caps', ['set', 'galaxy'], ['day' => '1']],
            ['caps', ['set', 'site'], []],
            ['caps', ['set', 'site'], ['day' => '-1']],
            ['caps', ['set', 'network'], ['day' => '1']],
            ['kill', ['sideways'], []],
            ['kill', ['on'], ['purpose' => 'Bad Purpose']],
            ['prices', ['import', '/nonexistent.json'], []],
            ['prices', ['unset', 'acme', 'never'], []],
            ['prices', ['set', 'acme', 'x'], ['in' => 'free']],
            ['prices', ['dance'], []],
            ['reservations', ['dance'], []],
            ['export', [], []],
            ['export', [], ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'xml']],
            ['prune', [], []],
            ['prune', [], ['older-than' => '0']],
        ];
        foreach ($bad as [$method, $args, $assoc]) {
            [$code] = $this->cli($method, $args, $assoc);
            $this->assertSame(1, $code, $method . ' ' . implode(' ', $args) . ' ' . (string) json_encode($assoc));
        }
    }

    public function testT68StatusShowsTimezoneAndPriceDate(): void
    {
        WpStub::$timezone = 'Asia/Kolkata';
        [, $out] = $this->cli('status');
        $this->assertStringContainsString('Asia/Kolkata', $out);
        $this->assertStringContainsString('oldest 2026-11-01', $out);
        $this->assertStringContainsString("kill_switch\toff", $out);
    }

    public function testT68CheckIsADryRun(): void
    {
        [$code, $out] = $this->cli('check', [], ['purpose' => 'summary', 'caller' => 'c', 'provider' => 'acme', 'model' => 'text-1']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString("allowed\ttrue", $out);
        $this->assertSame([], $this->store->all());
    }

    public function testT68PricesSetImportAndExport(): void
    {
        [$code] = $this->cli('prices', ['set', 'acme', 'img-1'], [
            'in' => '0', 'out' => '0', 'unit' => 'image', 'unit-price' => '0.04',
            'as-of' => '2026-11-01', 'source' => 'https://provider.example/pricing',
        ]);
        $this->assertSame(0, $code);
        $this->assertSame(40000000, $this->plugin->prices()->lookup('acme', 'img-1')['unit_price']);
        $file = dirname(__DIR__) . '/fixtures/import-good.json';
        [$code, $out] = $this->cli('prices', ['import', $file], ['dry-run' => true]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('+ router acme/text-1', $out);
        $this->assertStringContainsString('~ acme text-1', $out);
        $this->assertNull($this->plugin->prices()->lookup('router', 'acme/text-1'));
        $this->cli('prices', ['import', $file], ['mode' => 'merge']);
        $this->assertNotNull($this->plugin->prices()->lookup('router', 'acme/text-1'));
        [, $out] = $this->cli('prices', ['export'], ['overrides-only' => true]);
        $this->assertSame(1, json_decode($out, true)['schema']);
        [$code, $out] = $this->cli('prices', ['import', dirname(__DIR__, 2) . '/docs/prices.example.json'], []);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Warning: row 1', $out);
    }

    public function testT69KillOnOffFromCli(): void
    {
        $this->cli('kill', ['on']);
        $this->assertTrue($this->plugin->kill()->globalOn());
        $calls = WpStub::calls('spend_meter_kill_switch_changed');
        $this->assertSame(['global', false, true, 'cli'], $calls[0]);
        $this->cli('kill', ['off']);
        $this->assertFalse($this->plugin->kill()->globalOn());
    }

    public function testT44KillSwitchChangeActionFromScreenCarriesUserId(): void
    {
        WpStub::$userId = 7;
        \IndianOpinion\SpendMeter\Admin\SettingsPage::process([
            '_wpnonce' => 'nonce-spend_meter_save', 'kill_global' => '1', 'kill_purposes' => 'translate',
            'warning_threshold' => '80', 'default_output_tokens' => '4096', 'reservation_minutes' => '15',
            'currency' => 'USD', 'stale_days' => '120', 'retention_months' => '0',
        ], false);
        $calls = WpStub::calls('spend_meter_kill_switch_changed');
        $this->assertSame(['global', false, true, '7'], $calls[0]);
        $this->assertSame(['purpose:translate', false, true, '7'], $calls[1]);
        // No change, no action.
        $this->plugin->kill()->setGlobal(true, 'cli');
        $this->assertCount(2, WpStub::calls('spend_meter_kill_switch_changed'));
    }

    public function testT70RetentionRemovesOnlySettledRowsOlderThanTheLimit(): void
    {
        $old = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']);
        $this->clock->set(self::NOW - 400 * 86400);
        $ancient = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']);
        $this->check(); // A reservation from long ago that is still "reserved".
        $this->clock->set(self::NOW);
        $this->plugin->settings()->save(['retention_months' => 12], true);
        $this->refresh();
        \IndianOpinion\SpendMeter\Cron::daily();
        $this->assertArrayHasKey($old, $this->store->rows);
        $this->assertArrayNotHasKey($ancient, $this->store->rows);
        $this->assertCount(2, $this->store->rows, 'the live reservation stays');
    }

    public function testT71RetentionWorksInBatches(): void
    {
        $this->clock->set(self::NOW - 400 * 86400);
        for ($i = 0; $i < 2500; $i++) {
            $this->store->insert(['call_id' => 'c' . $i, 'status' => 'ok', 'created_at' => $this->clock->utc(), 'day' => '2025-10-10', 'month' => '2025-10']);
        }
        $this->clock->set(self::NOW);
        $store = $this->store;
        [$code, $out] = $this->cli('prune', [], ['older-than' => '12', 'dry-run' => true]);
        $this->assertStringContainsString('2500 rows', $out);
        $this->assertCount(2500, $store->rows);
        $before = $store->queries;
        $this->assertSame(2500, \IndianOpinion\SpendMeter\Cron::prune($this->plugin, 12));
        // Three batches: 1000, 1000, 500. Each batch is one select and one delete.
        $this->assertSame(6, $store->queries - $before);
        $this->assertSame([], $store->rows);
        $this->assertSame(0, $code);
    }

    public function testT72ExportColumnsAndRange(): void
    {
        $this->clock->set(self::NOW - 30 * 86400);
        $this->record(['purpose' => 'before', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']);
        $this->clock->set(self::NOW);
        $this->record(['purpose' => 'inside', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'meta' => ['job' => 'x,y']]);
        [, $csv] = $this->cli('export', [], ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $lines = explode("\n", $csv);
        $this->assertSame(implode(',', array_keys(\IndianOpinion\SpendMeter\Schema::COLUMNS)), $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('inside', $lines[1]);
        $this->assertStringContainsString('"{""job"":""x,y""}"', $lines[1]);
        [, $jsonl] = $this->cli('export', [], ['from' => '2026-11-01', 'to' => '2026-11-30', 'format' => 'jsonl']);
        $row = json_decode($jsonl, true);
        $this->assertSame(array_keys(\IndianOpinion\SpendMeter\Schema::COLUMNS), array_keys($row));
    }

    public function testReportGroupsAndExcludesTests(): void
    {
        $base = ['caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1000];
        $this->record(['purpose' => 'a'] + $base);
        $this->record(['purpose' => 'a'] + $base);
        $this->record(['purpose' => 'b', 'test' => true] + $base);
        [, $out] = $this->cli('report', [], ['format' => 'json']);
        $rows = json_decode($out, true);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['calls']);
        $this->assertSame('0.004 USD', $rows[0]['cost']);
        [, $out] = $this->cli('report', [], ['format' => 'json', 'include-tests' => true]);
        $this->assertCount(2, json_decode($out, true));
    }

    public function testCommandIsRegistered(): void
    {
        $this->assertContains('spend-meter', WP_CLI::$commands);
    }
}
