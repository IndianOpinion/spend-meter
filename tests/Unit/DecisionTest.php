<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use WpStub;

/**
 * Pre-spend check: section 4.1 and the caps of section 4.4.
 */
final class DecisionTest extends TestCase
{
    private const KEYS = ['allowed', 'reason', 'message', 'reservation_id', 'estimate', 'remaining', 'api'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->price('acme', 'text-1', '2', '8');
    }

    public function testT1FilterReturnsEveryKey(): void
    {
        $d = $this->check();
        $this->assertSame(self::KEYS, array_keys($d));
        $this->assertTrue($d['allowed']);
        $this->assertSame(1, $d['api']);
        $this->assertNull($d['remaining']);
    }

    public function testT1NonNullInputIsOverwritten(): void
    {
        $d = apply_filters('spend_meter_pre_spend', ['allowed' => true, 'reason' => 'forged'], $this->intent(['purpose' => 'Bad Slug']));
        $this->assertFalse($d['allowed']);
        $this->assertSame('invalid_request', $d['reason']);
    }

    public function testT1UnknownIntentKeysAreIgnored(): void
    {
        $this->assertTrue($this->check(['colour' => 'blue'])['allowed']);
    }

    public function testT3GlobalKillDeniesWithoutReservation(): void
    {
        $this->plugin->kill()->setGlobal(true, 'cli');
        $d = $this->check();
        $this->assertFalse($d['allowed']);
        $this->assertSame('kill_switch', $d['reason']);
        $this->assertSame([], $this->store->all());
        $this->assertSame(['kill_switch' => 1], $this->plugin->counters()->denials()['2026-11-14']);
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $d);
        }
    }

    public function testT4PurposeKillExactAndPrefix(): void
    {
        $this->plugin->kill()->setPurpose('summary', true, 'cli');
        $this->plugin->kill()->setPurpose('shadow*', true, 'cli');
        $this->assertSame('kill_switch_purpose', $this->check()['reason']);
        $this->assertSame('kill_switch_purpose', $this->check(['purpose' => 'shadow-rank'])['reason']);
        $this->assertTrue($this->check(['purpose' => 'summary-long'])['allowed']);
        $this->assertTrue($this->check(['purpose' => 'translate'])['allowed']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidIntents(): iterable
    {
        yield 'missing purpose' => [['purpose' => null], 'purpose'];
        yield 'upper-case purpose' => [['purpose' => 'Summary'], 'purpose'];
        yield 'long purpose' => [['purpose' => str_repeat('a', 65)], 'purpose'];
        yield 'bad caller' => [['caller' => '-x'], 'caller'];
        yield 'bad provider' => [['provider' => 'Acme Inc'], 'provider'];
        yield 'empty model' => [['model' => ''], 'model'];
        yield 'long model' => [['model' => str_repeat('m', 129)], 'model'];
        yield 'negative tokens' => [['est_input_tokens' => -1], 'est_input_tokens'];
        yield 'float tokens' => [['est_input_tokens' => 1.5], 'est_input_tokens'];
        yield 'bool tokens' => [['max_output_tokens' => true], 'max_output_tokens'];
        yield 'word tokens' => [['max_output_tokens' => 'many'], 'max_output_tokens'];
        yield 'negative units' => [['est_units' => -2], 'est_units'];
        yield 'bad override' => [['estimate_override' => '1.5'], 'estimate_override'];
        yield 'zero blog' => [['blog_id' => 0], 'blog_id'];
        yield 'meta too many' => [['meta' => array_fill_keys(range('a', 'i'), 1)], 'meta'];
        yield 'meta bad key' => [['meta' => ['Bad Key' => 1]], 'meta'];
        yield 'meta nested' => [['meta' => ['k' => ['x']]], 'meta'];
        yield 'meta long value' => [['meta' => ['k' => str_repeat('v', 192)]], 'meta'];
        yield 'dry_run string' => [['dry_run' => 'yes'], 'dry_run'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidIntents')]
    public function testT5InvalidIntent(array $overrides, string $key): void
    {
        $intent = $this->intent($overrides);
        if (array_key_exists('purpose', $overrides) && null === $overrides['purpose']) {
            unset($intent['purpose']);
        }
        $d = apply_filters('spend_meter_pre_spend', null, $intent);
        $this->assertFalse($d['allowed']);
        $this->assertSame('invalid_request', $d['reason']);
        $this->assertStringContainsString($key, $d['message']);
        $this->assertSame(0, $d['estimate']);
        $this->assertNull($d['remaining']);
        $this->assertSame([], $this->store->all(), 'nothing truncated or written');
    }

    public function testT5DigitStringsAcceptedAndModelStoredAsGiven(): void
    {
        $d = $this->check(['est_input_tokens' => '1000', 'max_output_tokens' => '500', 'model' => 'TEXT-1']);
        $this->assertTrue($d['allowed']);
        $this->assertSame(self::n('0.006'), $d['estimate'], 'price lookup lower-cases the model');
        $this->assertSame('TEXT-1', $this->store->all()[0]['model']);
    }

    public function testT6UnpricedDeniedWhenCapSet(): void
    {
        $this->setCaps('1');
        $d = $this->check(['model' => 'unknown-9']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('unpriced_model', $d['reason']);
        $this->assertSame([], $this->store->all());
    }

    public function testT6UnpricedAllowedAndFlaggedUnderAllow(): void
    {
        $this->setCaps('1');
        $this->plugin->settings()->save(['allow_unpriced' => true], false);
        $this->refresh();
        $d = $this->check(['model' => 'unknown-9']);
        $this->assertTrue($d['allowed']);
        $this->assertSame(0, $d['estimate']);
        $row = $this->store->all()[0];
        $this->assertSame(Schema::FLAG_UNPRICED_ALLOWED, $row['flags'] & Schema::FLAG_UNPRICED_ALLOWED);
        $this->assertTrue($this->logged('allowing unpriced model'));
    }

    public function testT6UnpricedAllowedWhenNoCapIsSetAtAll(): void
    {
        $d = $this->check(['model' => 'unknown-9']);
        $this->assertTrue($d['allowed']);
        $this->assertSame(Schema::FLAG_UNPRICED_ALLOWED, $this->store->all()[0]['flags']);
    }

    public function testT6UnpricedWithOverrideUsesOverride(): void
    {
        $this->setCaps('1');
        $d = $this->check(['model' => 'unknown-9', 'estimate_override' => 5000]);
        $this->assertTrue($d['allowed']);
        $this->assertSame(5000, $d['estimate']);
    }

    public function testT7EstimateMaths(): void
    {
        $this->price('acme', 'img-1', '1', '4', ['unit' => 'image', 'unit_price' => '0.02', 'per_call_fee' => '0.001']);
        // 100 in at 1/M = 100000; 200 out at 4/M = 800000; 2.5 images at 0.02 = 0.05; fee 0.001.
        $d = $this->check(['model' => 'img-1', 'est_input_tokens' => 100, 'max_output_tokens' => 200, 'est_units' => 2.5, 'dry_run' => true]);
        $this->assertSame(100000 + 800000 + 50000000 + 1000000, $d['estimate']);
        // Override larger than computed wins; smaller does not.
        $this->assertSame(self::n('1'), $this->check(['estimate_override' => self::n('1'), 'dry_run' => true])['estimate']);
        $this->assertSame(self::n('0.006'), $this->check(['estimate_override' => 1, 'dry_run' => true])['estimate']);
        // Default output allowance (4096) when max_output_tokens is absent.
        $intent = $this->intent(['dry_run' => true]);
        unset($intent['max_output_tokens']);
        $d = apply_filters('spend_meter_pre_spend', null, $intent);
        $this->assertSame(2000000 + 4096 * 8000, $d['estimate']);
        $this->assertSame($d['estimate'], spend_meter_estimate($intent));
        $this->assertSame(0, spend_meter_estimate($this->intent(['model' => 'nothing'])));
    }

    public function testT8ReservationIncludedInTotals(): void
    {
        $this->setCaps('1');
        $d = $this->check();
        $this->assertSame('reserved', $this->store->all()[0]['status']);
        $this->assertSame(self::n('0.006'), spend_meter_totals('site', 'day'));
        $this->assertSame(self::n('1') - self::n('0.006'), $d['remaining']);
    }

    public function testT9CapReasonsInPriorityOrderAndOwnReservationRemoved(): void
    {
        $caps = $this->plugin->caps();
        $caps->set('site', 1, null, self::n('0.001'), self::n('0.001'));
        $caps->set('purpose', 1, 'summary', null, self::n('0.001'));
        $this->assertSame('cap_purpose_month', $this->check()['reason']);
        $caps->set('purpose', 1, 'summary', self::n('0.001'), null);
        $d = $this->check();
        $this->assertSame('cap_purpose_day', $d['reason']);
        $this->assertSame(0, $d['remaining']);
        $this->assertSame(self::n('0.006'), $d['estimate']);
        $this->assertStringContainsString('limit 0.001', $d['message']);
        $caps->unset('purpose', 1, 'summary');
        $this->assertSame('cap_site_day', $this->check()['reason']);
        $caps->unset('site', 1, null, true, false);
        $this->assertSame('cap_site_month', $this->check()['reason']);
        $this->assertSame([], $this->store->all(), 'every denied reservation was removed');
    }

    public function testT9NetworkCapReasons(): void
    {
        WpStub::$multisite = true;
        $this->refresh();
        \IndianOpinion\SpendMeter\Options::set(Options::DB_VERSION, Schema::VERSION);
        $this->price('acme', 'text-1', '2', '8');
        $this->plugin->caps()->set('network', 1, null, self::n('0.001'), null);
        $this->assertSame('cap_network_day', $this->check()['reason']);
        $this->plugin->caps()->unset('network', 1, null);
        $this->plugin->caps()->set('network', 1, null, null, self::n('0.001'));
        $this->assertSame('cap_network_month', $this->check()['reason']);
    }

    public function testT10AllowReturnsOkAndReservationId(): void
    {
        $d = $this->check();
        $this->assertTrue($d['allowed']);
        $this->assertSame('ok', $d['reason']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $d['reservation_id']);
        $this->assertSame($d['reservation_id'], $this->store->all()[0]['reservation_id']);
    }

    public function testT11InterleavedChecksNeverBothAllowPastTheCap(): void
    {
        // Cap fits one call (0.006) but not two.
        $this->setCaps('0.01');
        $second = null;
        $this->store->afterInsert = function () use (&$second): void {
            // Process B runs its whole check between A's insert and A's sum.
            $second = $this->check();
        };
        $first = $this->check();
        $this->assertIsArray($second);
        $allowed = (int) $first['allowed'] + (int) $second['allowed'];
        $this->assertLessThanOrEqual(1, $allowed, 'at most one of two racing checks is allowed');
        $live = array_filter($this->store->all(), static fn (array $r): bool => 'reserved' === $r['status']);
        $this->assertLessThanOrEqual(self::n('0.01'), array_sum(array_column($live, 'estimate')));
    }

    public function testT12ZeroCapDeniesEmptyCapDoesNot(): void
    {
        $this->setCaps('0');
        $this->assertSame('cap_site_day', $this->check(['estimate_override' => 0, 'model' => 'text-1', 'est_input_tokens' => 0, 'max_output_tokens' => 0])['reason']);
        $this->plugin->caps()->unset('site', 1, null);
        $this->assertTrue($this->check()['allowed']);
    }

    public function testT13FilterCanDenyAndReleasesReservation(): void
    {
        add_filter('spend_meter_decision', static function (array $d): array {
            $d['allowed'] = false;
            $d['message'] = 'office hours only';
            return $d;
        });
        $d = $this->check();
        $this->assertFalse($d['allowed']);
        $this->assertSame('denied_by_filter', $d['reason']);
        $this->assertSame('office hours only', $d['message']);
        $this->assertNull($d['reservation_id']);
        $this->assertSame([], $this->store->all());
    }

    public function testT13DenyToAllowIsIgnoredAndLoggedOnce(): void
    {
        $this->plugin->kill()->setGlobal(true, 'cli');
        add_filter('spend_meter_decision', static function (array $d): array {
            $d['allowed'] = true;
            $d['reason'] = 'ok';
            return $d;
        });
        $this->assertSame('kill_switch', $this->check()['reason']);
        $this->assertSame('kill_switch', $this->check()['reason']);
        $lines = array_filter($this->logs, static fn (string $l): bool => str_contains($l, 'deny into an allow'));
        $this->assertCount(1, $lines);
    }

    public function testT14DryRunWritesNothing(): void
    {
        $this->setCaps('1');
        $d = $this->check(['dry_run' => true]);
        $this->assertTrue($d['allowed']);
        $this->assertNull($d['reservation_id']);
        $this->assertSame([], $this->store->all());
        $this->assertSame(self::n('1') - self::n('0.006'), $d['remaining']);
    }

    public function testT15TestCallsAllowedRecordedAndExcluded(): void
    {
        $this->setCaps('0');
        $d = $this->check(['test' => true, 'model' => 'unknown-9']);
        $this->assertTrue($d['allowed'], 'over cap and unpriced, still allowed');
        $this->assertSame(0, $d['estimate']);
        $this->assertSame(1, $this->store->all()[0]['is_test']);
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 5]);
        $this->assertSame(1, $id);
        $this->assertSame(0, spend_meter_totals('site', 'day'));
        $this->assertSame(0, $this->store->all()[0]['flags'] & Schema::FLAG_UNRESERVED);
        $this->plugin->kill()->setGlobal(true, 'cli');
        $this->assertSame('kill_switch', $this->check(['test' => true])['reason']);
    }

    public function testT15PricedTestCallHasEstimate(): void
    {
        $this->assertSame(self::n('0.006'), $this->check(['test' => true])['estimate']);
    }

    public function testT36CapsStoredPerBlog(): void
    {
        $caps = $this->plugin->caps();
        $caps->set('site', 1, null, self::n('1'), null);
        $caps->set('site', 2, null, self::n('2'), null);
        $caps->set('purpose', 2, 'summary', self::n('3'), null);
        $this->assertSame(self::n('1'), $caps->site(1)['day']);
        $this->assertSame(self::n('2'), $caps->site(2)['day']);
        $this->assertSame([], $caps->purposes(1));
        $this->assertSame(['summary' => ['day' => self::n('3'), 'month' => null]], $caps->purposes(2));
        $this->assertSame(['day' => null, 'month' => null], $caps->network(), 'single site has no network caps');
        $this->expectException(\IndianOpinion\SpendMeter\ValidationException::class);
        $caps->set('network', 1, null, 1, 1);
    }

    public function testT37EveryMatchingPurposePatternApplies(): void
    {
        $caps = $this->plugin->caps();
        $caps->set('purpose', 1, 'sum*', self::n('1'), null);
        $caps->set('purpose', 1, 'summary', self::n('1'), null);
        $caps->set('purpose', 1, 's*', self::n('0.001'), null);
        $d = $this->check();
        $this->assertSame('cap_purpose_day', $d['reason']);
        $this->assertStringContainsString('purpose:1:s*', $d['message']);
        // The prefix cap counts every purpose it matches.
        $caps->unset('purpose', 1, 's*');
        $caps->set('purpose', 1, 'sum*', self::n('0.01'), null);
        $this->assertTrue($this->check(['purpose' => 'summary'])['allowed']);
        $this->assertSame('cap_purpose_day', $this->check(['purpose' => 'summary-short'])['reason']);
    }

    public function testT38LoweringACapDeniesNextAndChangesNoRows(): void
    {
        $this->setCaps('1');
        $d = $this->check();
        $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1000, 'output_tokens' => 500]);
        $before = $this->store->all();
        $this->setCaps('0.001');
        $this->assertSame('cap_site_day', $this->check()['reason']);
        $this->assertSame($before, $this->store->all());
    }

    public function testT39WarningFiresOncePerScopePerWindow(): void
    {
        $this->setCaps('0.01'); // 80 percent is 0.008.
        $this->check(['estimate_override' => self::n('0.009')]);
        $this->check(['estimate_override' => 1, 'est_input_tokens' => 0, 'max_output_tokens' => 0]);
        $warnings = WpStub::calls('spend_meter_cap_warning');
        $this->assertCount(1, $warnings);
        $this->assertSame(['site:1', 'day', self::n('0.01'), self::n('0.009'), '2026-11-14'], $warnings[0]);
        $this->assertSame([], WpStub::calls('spend_meter_cap_reached'));
        // Next day: a new window fires again.
        $this->clock->advance(86400);
        $this->check(['estimate_override' => self::n('0.009')]);
        $this->assertCount(2, WpStub::calls('spend_meter_cap_warning'));
    }

    public function testT40CapReachedFiresOncePerScopePerWindow(): void
    {
        $this->setCaps('0.01');
        $this->check(['estimate_override' => self::n('0.02')]);
        $this->check(['estimate_override' => self::n('0.02')]);
        $reached = WpStub::calls('spend_meter_cap_reached');
        $this->assertCount(1, $reached);
        $this->assertSame('site:1', $reached[0][0]);
        $this->assertSame('day', $reached[0][1]);
        $state = get_option(Options::ALERT_STATE);
        $this->assertArrayHasKey('reached|site:1|2026-11-14', $state);
    }

    public function testT40RecordPastThresholdFiresAlerts(): void
    {
        $this->setCaps('0.01');
        $d = $this->check(); // reserves 0.006, under 80 percent.
        $this->assertSame([], WpStub::calls('spend_meter_cap_warning'));
        $this->record(['reservation_id' => $d['reservation_id'], 'provider_cost' => self::n('0.011')]);
        $this->assertCount(1, WpStub::calls('spend_meter_cap_warning'));
        $this->assertCount(1, WpStub::calls('spend_meter_cap_reached'));
    }

    public function testT41DayFollowsSiteTimezoneAndRowsKeepTheirDay(): void
    {
        WpStub::$timezone = 'Asia/Kolkata'; // 12:00 UTC is 17:30 local, same day.
        $this->clock->set(gmmktime(20, 0, 0, 11, 14, 2026)); // 01:30 on the 15th in Kolkata.
        $this->check();
        $this->assertSame('2026-11-15', $this->store->all()[0]['day']);
        WpStub::$timezone = 'America/New_York'; // Now 15:00 on the 14th.
        $this->assertSame('2026-11-15', $this->store->all()[0]['day'], 'old rows do not move');
        $this->assertSame('2026-11-14', $this->clock->day());
    }

    public function testT46ExpiredReservationsExcludedBeforeCronAndMarkedByCron(): void
    {
        $this->setCaps('0.01');
        $this->check();
        $this->assertSame(self::n('0.006'), spend_meter_totals('site', 'day'));
        $this->clock->advance(16 * 60);
        $this->assertSame(0, spend_meter_totals('site', 'day'), 'no longer counts before cron');
        $this->assertTrue($this->check()['allowed']);
        \IndianOpinion\SpendMeter\Cron::hourly();
        $this->assertSame('expired', $this->store->all()[0]['status']);
        $this->assertSame(self::NOW + 16 * 60, get_option(Options::LAST_CRON));
    }

    public function testT46LifetimeIsFilterable(): void
    {
        add_filter('spend_meter_reservation_lifetime', static fn (): int => 30);
        $this->check();
        $this->clock->advance(20 * 60);
        $this->assertSame(self::n('0.006'), spend_meter_totals('site', 'day'));
        add_filter('spend_meter_reservation_lifetime', static fn (): int => 500);
        $this->assertSame(7200, $this->plugin->settings()->reservationSeconds(), 'clamped to 120 minutes');
    }

    public function testT73LedgerUnavailableWhenInsertFails(): void
    {
        $this->store->failWrites = true;
        $this->assertSame('ledger_unavailable', $this->check()['reason']);
        $this->store->failWrites = false;
        $this->setCaps('1');
        $this->store->failReads = true;
        $this->assertSame('ledger_unavailable', $this->check()['reason']);
        $this->store->failReads = false;
        $this->assertSame([], array_filter($this->store->all(), static fn (array $r): bool => 'reserved' === $r['status']));
    }

    public function testT73CorruptSettingsUseDefaultsAndConstantsStillApply(): void
    {
        update_option(Options::SETTINGS, 'garbage');
        $this->refresh();
        $this->assertSame(4096, $this->plugin->settings()->all()['default_output_tokens']);
        $this->assertTrue($this->logged('settings option is not an array'));
        $this->assertTrue($this->check()['allowed']);
    }

    public function testT73CorruptKillOptionLeavesSwitchOff(): void
    {
        update_option(Options::KILL, 'garbage');
        $this->assertTrue($this->check()['allowed']);
        $this->assertTrue($this->logged('kill switch option is corrupt'));
    }

    public function testT73CorruptPriceOptionFallsBackToSeed(): void
    {
        update_option(Options::PRICES, 'garbage');
        $this->refresh();
        $this->setCaps('1');
        $this->assertSame('unpriced_model', $this->check()['reason']);
        $this->assertTrue($this->plugin->prices()->corrupt());
        $this->assertTrue($this->logged('price override option is corrupt'));
    }

    public function testT73MissingSeedMeansUnpriced(): void
    {
        delete_option(Options::PRICES);
        $this->boot('/nonexistent/prices.json');
        $this->setCaps('1');
        $this->assertSame('unpriced_model', $this->check()['reason']);
        $this->assertTrue($this->plugin->prices()->seedInvalid());
    }

    public function testT73ObjectCacheDownReadsDatabase(): void
    {
        WpStub::$multisite = true;
        $this->refresh();
        Options::set(Options::DB_VERSION, Schema::VERSION);
        $this->price('acme', 'text-1', '2', '8');
        $this->plugin->caps()->set('network', 1, null, self::n('0.01'), null);
        $this->assertTrue($this->check()['allowed']);
        WpStub::$cache = [];
        WpStub::$transients = [];
        // With no cache at all the next check still sees the first reservation.
        $this->assertSame('cap_network_day', $this->check()['reason']);
    }

    public function testRuling4NetworkTotalCachedAndRecomputedOnDeny(): void
    {
        WpStub::$multisite = true;
        $this->refresh();
        Options::set(Options::DB_VERSION, Schema::VERSION);
        $this->price('acme', 'text-1', '2', '8');
        $this->plugin->caps()->set('network', 1, null, self::n('0.01'), null);
        $this->assertTrue($this->check()['allowed']);
        // A stale cache that says the network is over the cap: the deny path
        // recomputes live and allows.
        WpStub::$cache['spend_meter:spend_meter_network_2026-11-14'] = ['day' => self::n('5'), 'month' => self::n('5')];
        $this->store->rows = [];
        $this->assertTrue($this->check()['allowed']);
    }

    public function testT73DecisionFilterThrowing(): void
    {
        add_filter('spend_meter_decision', static function (): array {
            throw new RuntimeException('boom');
        });
        $d = $this->check();
        $this->assertSame('decision_filter_error', $d['reason']);
        $this->assertSame([], $this->store->all());
        remove_all_filters('spend_meter_decision');
        add_filter('spend_meter_decision', static fn (): string => 'nope');
        $this->assertSame('decision_filter_error', $this->check()['reason']);
    }

    public function testT74MissingColumnDeniesUntilFixed(): void
    {
        $this->store->missing = ['flags'];
        delete_option(Options::DB_VERSION);
        $this->assertSame(['flags'], Schema::maybeInstall($this->store, $this->clock));
        $this->assertSame('ledger_unavailable', $this->check()['reason']);
        $html = \IndianOpinion\SpendMeter\Admin\Notices::html($this->plugin);
        $this->assertStringContainsString('missing columns (flags)', $html);
        $this->store->missing = [];
        $this->assertSame([], Schema::maybeInstall($this->store, $this->clock));
        $this->assertTrue($this->check()['allowed']);
    }

    public function testDebugLineOnDenyOnlyWithWpDebug(): void
    {
        $this->plugin->kill()->setGlobal(true, 'cli');
        $this->check();
        $this->assertFalse($this->logged('deny kill_switch'));
    }
}
