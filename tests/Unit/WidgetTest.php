<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Admin\Widget;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Tests\TestCase;
use WpStub;

/**
 * Dashboard widget: section 4.8.
 */
final class WidgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->price('acme', 'text-1', '2', '8');
    }

    private function spend(string $purpose, int $nanos, array $extra = []): int
    {
        return $this->record($extra + ['purpose' => $purpose, 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'provider_cost' => $nanos, 'input_tokens' => 1]);
    }

    public function testT60SummaryValuesAndWarnings(): void
    {
        $this->setCaps('1', '10');
        foreach (['a' => 6, 'b' => 5, 'c' => 4, 'd' => 3, 'e' => 2, 'f' => 1] as $purpose => $cents) {
            $this->spend($purpose, $cents * 10000000);
        }
        $this->record(['purpose' => 'g', 'caller' => 'b', 'provider' => 'acme', 'model' => 'nope', 'input_tokens' => 1]);
        $this->check(); // A live reservation of 0.006.
        $this->clock->advance(10 * 60); // Older than half the 15-minute lifetime.
        $this->plugin->kill()->setPurpose('shadow*', true, 'cli');
        Options::set(Options::LOSSES, ['count' => 2, 'last' => self::NOW]);
        Options::set(Options::LAST_CRON, self::NOW - 3 * 3600);
        $s = $this->plugin->widget()->build(1);
        $this->assertSame(self::n('0.216'), $s['totals']['day_total']);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], array_column($s['top'], 'purpose'));
        $this->assertSame(1, $s['totals']['unpriced_today']);
        $this->assertSame(7, $s['totals']['unreserved_today']);
        $this->assertSame(1, $s['totals']['old_reservations']);
        $warnings = implode("\n", Widget::warnings($s));
        foreach (['shadow*', 'unpriced calls today', 'without a pre-spend check', 'older than half', 'lost after retry', 'WP-Cron'] as $needle) {
            $this->assertStringContainsString($needle, $warnings);
        }
        $html = $this->plugin->widget()->render(false);
        $this->assertStringContainsString('USD 0.22 of USD 1.00 (21%)', $html);
        $this->assertStringContainsString('_wpnonce=nonce-spend_meter_refresh', $html);
    }

    public function testT60ExtraRowsFilterIsEscaped(): void
    {
        add_filter('spend_meter_widget_rows', static fn (array $rows): array => [['label' => '<b>Queue</b>', 'value' => '3']]);
        $html = $this->plugin->widget()->render(false);
        $this->assertStringContainsString('&lt;b&gt;Queue&lt;/b&gt;', $html);
    }

    public function testT61HiddenWithoutCapability(): void
    {
        WpStub::$caps = [];
        Widget::setup();
        $this->assertSame([], WpStub::$widgets);
        WpStub::$caps = ['manage_options' => true];
        Widget::setup();
        $this->assertSame('spend_meter', WpStub::$widgets[0][0]);
        add_filter('spend_meter_capability', static fn (): string => 'view_spend');
        WpStub::$widgets = [];
        Widget::setup();
        $this->assertSame([], WpStub::$widgets);
    }

    public function testT62CacheAndNonceCheckedRefresh(): void
    {
        $this->plugin->widget()->summary(1);
        $queries = $this->store->queries;
        $this->assertLessThanOrEqual(4, $queries, 'at most four queries when cold');
        $this->spend('a', self::n('1'));
        $this->assertSame(0, $this->plugin->widget()->summary(1)['totals']['day_total'], 'served from cache');
        $this->assertFalse(Widget::processRefresh(['_wpnonce' => 'forged']));
        $this->assertSame(0, $this->plugin->widget()->summary(1)['totals']['day_total']);
        WpStub::$caps = [];
        $this->assertFalse(Widget::processRefresh(['_wpnonce' => 'nonce-spend_meter_refresh']));
        WpStub::$caps = ['manage_options' => true];
        $this->assertTrue(Widget::processRefresh(['_wpnonce' => 'nonce-spend_meter_refresh']));
        $this->assertSame(self::n('1'), $this->plugin->widget()->summary(1)['totals']['day_total']);
    }

    public function testT62WarmCacheRunsNoQuery(): void
    {
        $this->plugin->widget()->summary(1);
        $queries = $this->store->queries;
        $this->plugin->widget()->summary(1);
        $this->assertSame($queries, $this->store->queries);
    }

    public function testT63TestsExcludedAndSmallValues(): void
    {
        $this->spend('a', 5000000);
        $this->spend('t', self::n('5'), ['test' => true]);
        $s = $this->plugin->widget()->build(1);
        $this->assertSame(5000000, $s['totals']['day_total']);
        $this->assertStringContainsString('USD &lt; 0.01', $this->plugin->widget()->render(false));
    }

    public function testT64NetworkWidgetPerSite(): void
    {
        WpStub::$multisite = true;
        Options::set(Options::DB_VERSION, \IndianOpinion\SpendMeter\Schema::VERSION);
        $this->refresh();
        $this->price('acme', 'text-1', '2', '8');
        $this->spend('a', self::n('1'));
        WpStub::$blogId = 2;
        $this->spend('a', self::n('2'));
        $s = $this->plugin->widget()->build(null);
        $this->assertSame([1 => ['day' => self::n('1'), 'month' => self::n('1')], 2 => ['day' => self::n('2'), 'month' => self::n('2')]], $s['per_site']);
        $this->assertSame(self::n('3'), $s['totals']['day_total']);
        Widget::setupNetwork();
        $this->assertSame('spend_meter_network', WpStub::$widgets[0][0]);
        $html = $this->plugin->widget()->render(true);
        $this->assertStringContainsString('<td>2</td>', $html);
    }

    public function testWidgetCanBeDisabledByConstantDocumented(): void
    {
        $this->assertFalse(Widget::disabled());
    }
}
