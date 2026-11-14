<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Cli\Command;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\PriceFile;
use IndianOpinion\SpendMeter\PriceFileException;
use IndianOpinion\SpendMeter\Tests\TestCase;

/**
 * Price table: section 4.7. Every price here is invented.
 */
final class PriceTableTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
    }

    public function testT49EveryFieldPriced(): void
    {
        $this->price('acme', 'all-1', '1', '2', [
            'cached_input_per_million' => '0.1',
            'reasoning_per_million' => '4',
            'unit' => 'second',
            'unit_price' => '0.01',
            'per_call_fee' => '0.002',
        ]);
        $price = $this->plugin->prices()->lookup('acme', 'all-1');
        $this->assertNotNull($price);
        $cost = \IndianOpinion\SpendMeter\PriceTable::cost($price, 1000, 200, 100, 30, 3000000);
        // 800*1 + 200*0.1 + 70*2 + 30*4 = 800 + 20 + 140 + 120 = 1080 per million -> 1080000 nanos;
        // 3 seconds at 0.01 = 30000000; fee 2000000.
        $this->assertSame(1080000 + 30000000 + 2000000, $cost);
    }

    public function testT50SeedIsAValidEmptyFile(): void
    {
        $seed = PriceFile::parse($this->fixture('../../data/prices.json'), 'USD');
        $this->assertSame([], $seed['prices']);
        $this->assertNull($seed['as_of']);
        $this->assertTrue($this->plugin->prices()->isEmpty());
        $this->assertStringContainsString('no prices loaded', \IndianOpinion\SpendMeter\Admin\Notices::html($this->plugin));
        $summary = $this->plugin->widget()->build(1);
        $this->assertContains('No prices loaded. Every model is unpriced.', \IndianOpinion\SpendMeter\Admin\Widget::warnings($summary));
    }

    public function testT50ExampleFileFailsImportOnPurpose(): void
    {
        try {
            $this->plugin->prices()->import($this->fixture('../../docs/prices.example.json'));
            $this->fail('the example file must not import');
        } catch (PriceFileException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertTrue($this->plugin->prices()->isEmpty());
    }

    public function testT50OnlyInventedProvidersInRepositoryFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/data/*.json') ?: [],
            glob($root . '/docs/*.json') ?: [],
            glob($root . '/tests/fixtures/*.json') ?: [],
            glob($root . '/examples/*.json') ?: []
        );
        $invented = ['acme', 'router', 'example-provider'];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            foreach ((array) ($data['prices'] ?? []) as $row) {
                $this->assertContains($row['provider'], $invented, basename($file) . ' must use invented providers only');
            }
        }
        $this->assertNotEmpty($files);
    }

    public function testT51OverrideBeatsSeedAndSurvivesSeedReplacement(): void
    {
        $this->boot(dirname(__DIR__) . '/fixtures/seed-acme.json');
        $this->assertSame(3000000000, $this->plugin->prices()->lookup('acme', 'text-1')['input']);
        $this->assertSame('seed', $this->plugin->prices()->lookup('acme', 'text-1')['origin']);
        $this->price('acme', 'text-1', '2', '8');
        $this->assertSame(2000000000, $this->plugin->prices()->lookup('acme', 'text-1')['input']);
        $this->assertSame('override', $this->plugin->prices()->lookup('acme', 'text-1')['origin']);
        // An upgrade replaces the seed (here: the empty shipped one); overrides stay.
        $this->boot(dirname(__DIR__, 2) . '/data/prices.json');
        $this->assertSame(2000000000, $this->plugin->prices()->lookup('acme', 'text-1')['input']);
        $this->assertNull($this->plugin->prices()->lookup('acme', 'seed-only'));
    }

    public function testT52PriceVersionChangesAndOldRowsStay(): void
    {
        $this->price('acme', 'text-1', '2', '8');
        $v1 = $this->plugin->prices()->lookup('acme', 'text-1')['version'];
        $id = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1000]);
        $before = $this->store->rows[$id];
        $this->price('acme', 'text-1', '3', '8');
        $v2 = $this->plugin->prices()->lookup('acme', 'text-1')['version'];
        $this->assertNotSame($v1, $v2);
        $this->assertSame(12, strlen($v2));
        $this->assertSame($before, $this->store->rows[$id]);
        $this->assertSame($v1, $before['price_version']);
    }

    public function testT53ExactThenLongestPrefixNoDefault(): void
    {
        $this->price('acme', 'text-*', '1', '1');
        $this->price('acme', 'text-pro-*', '5', '5');
        $this->price('acme', 'text-pro-2', '9', '9');
        $p = $this->plugin->prices();
        $this->assertSame(9000000000, $p->lookup('acme', 'TEXT-PRO-2')['input']);
        $this->assertSame(5000000000, $p->lookup('acme', 'text-pro-3')['input']);
        $this->assertSame(1000000000, $p->lookup('acme', 'text-mini')['input']);
        $this->assertNull($p->lookup('acme', 'image-1'));
        $this->assertNull($p->lookup('other', 'text-pro-2'));
    }

    public function testT53PriceFilterCanOverride(): void
    {
        add_filter('spend_meter_price', static fn (?array $price, string $provider, string $model): ?array => 'special' === $model
            ? ['input' => 7, 'output' => 7]
            : $price, 10, 3);
        $price = $this->plugin->prices()->lookup('acme', 'special');
        $this->assertSame(7, $price['input']);
        $this->assertSame('filter', $price['origin']);
    }

    public function testT54AggregatorModelIdPricedByItsOwnRow(): void
    {
        $this->plugin->prices()->import($this->fixture('import-good.json'));
        $this->refresh();
        $this->assertSame(2200000000, $this->plugin->prices()->lookup('router', 'acme/text-1')['input']);
        $id = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'router', 'model' => 'acme/text-1', 'input_tokens' => 1000000, 'provider_cost' => 5]);
        $this->assertSame(5, $this->store->rows[$id]['cost'], 'reported cost wins');
        $this->assertSame(2200000000, $this->store->rows[$id]['cost_table']);
    }

    public function testT55OneBadRowRejectsTheFileAndDryRunShowsDiff(): void
    {
        $data = json_decode($this->fixture('import-good.json'), true);
        $data['prices'][1]['output_per_million'] = '-1';
        $data['prices'][1]['source'] = 'ftp://x';
        try {
            $this->plugin->prices()->import((string) json_encode($data));
            $this->fail('bad file accepted');
        } catch (PriceFileException $e) {
            $this->assertCount(2, $e->errors());
            $this->assertStringStartsWith('row 2:', $e->errors()[0]);
        }
        $this->assertTrue($this->plugin->prices()->isEmpty());
        $this->price('acme', 'text-1', '1', '1');
        $this->price('acme', 'gone', '1', '1');
        $diff = $this->plugin->prices()->import($this->fixture('import-good.json'), 'replace', true);
        $this->assertSame(['router acme/text-1'], $diff['added']);
        $this->assertSame(['acme text-1'], $diff['changed']);
        $this->assertSame(['acme gone'], $diff['removed']);
        $this->assertNotNull($this->plugin->prices()->lookup('acme', 'gone'), 'dry run wrote nothing');
    }

    public function testT55ValidationRules(): void
    {
        $base = ['provider' => 'acme', 'model' => 'm', 'input_per_million' => '1', 'output_per_million' => '1',
            'as_of' => '2026-11-01', 'source' => 'https://provider.example/p'];
        $bad = [
            ['input_per_million' => 1.5],
            ['input_per_million' => '1.0000000001'],
            ['as_of' => '2026-02-30'],
            ['source' => 'javascript:alert(1)'],
            ['unit' => 'litre', 'unit_price' => '1'],
            ['unit' => 'image'],
            ['provider' => 'Bad Provider'],
            ['output_per_million' => null],
        ];
        foreach ($bad as $change) {
            [, $errors] = PriceFile::row($change + $base, 'USD');
            $this->assertNotEmpty($errors, (string) json_encode($change));
        }
        [, $errors] = PriceFile::row($base, 'USD');
        $this->assertSame([], $errors);
        $this->expectException(PriceFileException::class);
        PriceFile::parse(str_repeat(' ', PriceFile::MAX_BYTES + 1), 'USD');
    }

    public function testT56MergeAndReplace(): void
    {
        $this->price('acme', 'keep', '1', '1');
        $this->plugin->prices()->import($this->fixture('import-good.json'), 'merge');
        $this->refresh();
        $this->assertCount(3, $this->plugin->prices()->overrides());
        $this->plugin->prices()->import($this->fixture('import-good.json'), 'replace');
        $this->refresh();
        $this->assertCount(2, $this->plugin->prices()->overrides());
        $this->assertNull($this->plugin->prices()->lookup('acme', 'keep'));
    }

    public function testT57WrongCurrencyRejected(): void
    {
        $data = json_decode($this->fixture('import-good.json'), true);
        $data['currency'] = 'EUR';
        $this->expectException(PriceFileException::class);
        $this->plugin->prices()->import((string) json_encode($data));
    }

    public function testT57RowCurrencyMustMatch(): void
    {
        $data = json_decode($this->fixture('import-good.json'), true);
        $data['prices'][0]['currency'] = 'GBP';
        $this->expectException(PriceFileException::class);
        $this->plugin->prices()->import((string) json_encode($data));
    }

    public function testT58StalePricesFlagged(): void
    {
        $this->price('acme', 'old', '1', '1', ['as_of' => '2026-01-01']);
        $this->price('acme', 'new', '1', '1', ['as_of' => '2026-11-01']);
        $stale = $this->plugin->prices()->stale(120, '2026-11-14');
        $this->assertSame(['old'], array_column($stale, 'model'));
        $this->assertSame('2026-01-01', $this->plugin->prices()->oldestAsOf());
        $html = \IndianOpinion\SpendMeter\Admin\SettingsPage::html($this->plugin, false);
        $this->assertStringContainsString('2026-01-01 (stale)', $html);
        $this->assertSame(1, $this->plugin->widget()->build(1)['stale_prices']);
    }

    public function testT58EmptyTableShowsNotice(): void
    {
        $html = \IndianOpinion\SpendMeter\Admin\SettingsPage::html($this->plugin, false);
        $this->assertStringContainsString('No prices loaded', $html);
    }

    public function testT59RepriceFillsOnlyUnpricedRows(): void
    {
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'input_tokens' => 1000];
        $unpriced = $this->record($base + ['model' => 'late-1']);
        $provider = $this->record($base + ['model' => 'late-1', 'provider_cost' => 77]);
        $this->price('acme', 'other', '1', '1');
        $table = $this->record($base + ['model' => 'other']);
        $this->assertSame('unpriced', $this->store->rows[$unpriced]['cost_source']);
        $this->price('acme', 'late-1', '4', '4');
        $this->assertSame(1, Command::repriceRows($this->plugin, '2026-11-01', '2026-11-30', true));
        $this->assertSame('unpriced', $this->store->rows[$unpriced]['cost_source'], 'dry run');
        $before = [$this->store->rows[$provider], $this->store->rows[$table]];
        $this->assertSame(1, Command::repriceRows($this->plugin, '2026-11-01', '2026-11-30', false));
        $this->assertSame('table_late', $this->store->rows[$unpriced]['cost_source']);
        $this->assertSame(4000000, $this->store->rows[$unpriced]['cost']);
        $this->assertSame($before, [$this->store->rows[$provider], $this->store->rows[$table]]);
    }

    public function testExportRoundTrips(): void
    {
        $this->plugin->prices()->import($this->fixture('import-good.json'));
        $this->refresh();
        $json = $this->plugin->prices()->export(true);
        $parsed = PriceFile::parse($json, 'USD');
        $this->assertCount(2, $parsed['prices']);
        $this->assertSame('2026-11-01', $parsed['as_of']);
        $this->assertFalse(Options::get(Options::PRICES, null) === null);
    }
}
