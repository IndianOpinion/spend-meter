<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests;

use IndianOpinion\SpendMeter\Clock;
use IndianOpinion\SpendMeter\Logger;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\Tests\Support\MemoryStore;
use PHPUnit\Framework\TestCase as BaseTestCase;
use WP_CLI;
use WpStub;

/**
 * Fresh stub state, an in-memory ledger and a fixed clock for every test.
 * All prices used in tests are invented: provider "acme" and friends.
 */
abstract class TestCase extends BaseTestCase
{
    protected MemoryStore $store;
    protected Clock $clock;
    protected Plugin $plugin;
    /** @var list<string> */
    protected array $logs = [];

    /** 2026-11-14 12:00:00 UTC */
    protected const NOW = 1794657600;

    protected function setUp(): void
    {
        WpStub::reset();
        WP_CLI::reset();
        $this->logs = [];
        Logger::setSink(function (string $line): void {
            $this->logs[] = $line;
        });
        $this->store = new MemoryStore();
        $this->clock = new Clock(self::NOW);
        $this->boot($this->seedPath());
    }

    protected function tearDown(): void
    {
        Logger::setSink(null);
        Plugin::setInstance(null);
    }

    protected function seedPath(): string
    {
        return dirname(__DIR__) . '/data/prices.json';
    }

    protected function boot(string $seedPath): void
    {
        $this->plugin = new Plugin($this->store, $this->clock, $seedPath);
        Plugin::setInstance($this->plugin);
        WpStub::$hooks = [];
        Plugin::register(dirname(__DIR__) . '/spend-meter.php');
        Options::set(Options::DB_VERSION, Schema::VERSION);
    }

    /** Rebuild cached services after options change under them. */
    protected function refresh(): void
    {
        $this->plugin->settings()->flush();
        $this->plugin->resetPrices();
    }

    /**
     * Invented price, decimal strings per million tokens.
     *
     * @param array<string, string|null> $extra
     */
    protected function price(string $provider, string $model, string $in, string $out, array $extra = []): void
    {
        $this->plugin->prices()->setOverride($extra + [
            'provider' => $provider,
            'model' => $model,
            'input_per_million' => $in,
            'output_per_million' => $out,
            'as_of' => '2026-11-01',
            'source' => 'https://provider.example/pricing',
        ]);
        $this->plugin->resetPrices();
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function intent(array $overrides = []): array
    {
        return $overrides + [
            'purpose' => 'summary',
            'caller' => 'test-plugin',
            'provider' => 'acme',
            'model' => 'text-1',
            'est_input_tokens' => 1000,
            'max_output_tokens' => 500,
        ];
    }

    /**
     * @param  array<string, mixed> $intent
     * @return array<string, mixed>
     */
    protected function check(array $intent = []): array
    {
        $result = apply_filters('spend_meter_pre_spend', null, $this->intent($intent));
        $this->assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function record(array $overrides = []): int
    {
        return spend_meter_record($overrides + ['status' => 'ok']);
    }

    /** Nanos from a decimal string. */
    protected static function n(string $decimal): int
    {
        return \IndianOpinion\SpendMeter\Money::decimalToNanos($decimal);
    }

    protected function setCaps(?string $siteDay = null, ?string $siteMonth = null): void
    {
        $this->plugin->caps()->set(
            'site',
            1,
            null,
            null === $siteDay ? null : self::n($siteDay),
            null === $siteMonth ? null : self::n($siteMonth)
        );
    }

    protected function logged(string $needle): bool
    {
        foreach ($this->logs as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }
        return false;
    }
}
