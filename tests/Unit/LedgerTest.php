<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WpStub;

/**
 * Recording calls, releases and reservations: sections 4.2 and 4.6.
 */
final class LedgerTest extends TestCase
{
    private const CALL_ID = '11111111-2222-4333-8444-555555555555';

    protected function setUp(): void
    {
        parent::setUp();
        $this->price('acme', 'text-1', '2', '8');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->store->rows[$id] ?? null;
        $this->assertIsArray($row);

        return $row;
    }

    public function testT16RecordViaActionAndFunction(): void
    {
        $d = $this->check();
        do_action('spend_meter_record', ['reservation_id' => $d['reservation_id'], 'status' => 'ok', 'input_tokens' => 10]);
        $this->assertSame('ok', $this->row(1)['status']);
        $id = $this->record(['purpose' => 'summary', 'caller' => 'p', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 10]);
        $this->assertSame(2, $id);
        $recorded = WpStub::calls('spend_meter_recorded');
        $this->assertCount(2, $recorded);
        $this->assertSame(2, $recorded[1][0]);
        $this->assertSame('summary', $recorded[1][1]['purpose']);
        $this->assertArrayHasKey('cost_source', $recorded[1][1]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'no status' => [['purpose' => 'a', 'caller' => 'b', 'provider' => 'c', 'model' => 'd', 'status' => null]];
        yield 'released status' => [['purpose' => 'a', 'caller' => 'b', 'provider' => 'c', 'model' => 'd', 'status' => 'released']];
        yield 'bad slug' => [['purpose' => 'A B', 'caller' => 'b', 'provider' => 'c', 'model' => 'd']];
        yield 'negative tokens' => [['purpose' => 'a', 'caller' => 'b', 'provider' => 'c', 'model' => 'd', 'input_tokens' => -5]];
        yield 'missing identity' => [['caller' => 'b', 'provider' => 'c', 'model' => 'd']];
        yield 'bad reservation id' => [['reservation_id' => 'abc']];
        yield 'long error code' => [['purpose' => 'a', 'caller' => 'b', 'provider' => 'c', 'model' => 'd', 'error_code' => str_repeat('e', 65)]];
    }

    /**
     * @param array<string, mixed> $call
     */
    #[DataProvider('invalidCalls')]
    public function testT16InvalidCallReturnsMinusOneAndWritesNothing(array $call): void
    {
        $call += ['status' => 'ok'];
        if (null === $call['status']) {
            unset($call['status']);
        }
        $this->assertSame(-1, spend_meter_record($call));
        $this->assertSame([], $this->store->all());
        $this->assertTrue($this->logged('record rejected'));
    }

    public function testT16FailedWriteReturnsZero(): void
    {
        $this->store->failWrites = true;
        $this->assertSame(0, $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']));
    }

    public function testT17SettlingUpdatesInPlace(): void
    {
        $d = $this->check(['meta' => ['job' => 7]]);
        $id = $this->record([
            'reservation_id' => $d['reservation_id'],
            'input_tokens' => 1000,
            'output_tokens' => 100,
            'latency_ms' => 900,
        ]);
        $this->assertSame(1, $id);
        $this->assertCount(1, $this->store->all());
        $row = $this->row(1);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(1000, $row['input_tokens']);
        $this->assertSame(2000000 + 800000, $row['cost']);
        $this->assertSame('table', $row['cost_source']);
        $this->assertSame(900, $row['latency_ms']);
        $this->assertSame(self::n('0.006'), $row['estimate']);
        $this->assertSame(0, $row['flags']);
        $this->assertNull($row['fallback_from']);
    }

    public function testT17ExpiredReservationStillSettles(): void
    {
        $d = $this->check();
        $this->clock->advance(20 * 60);
        $this->plugin->ledger()->expire();
        $this->assertSame('expired', $this->row(1)['status']);
        $this->assertSame(1, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]));
        $this->assertSame('ok', $this->row(1)['status']);
    }

    public function testT18SecondRecordAddsLinkedRow(): void
    {
        $d = $this->check();
        $first = $this->record(['reservation_id' => $d['reservation_id'], 'status' => 'error_billed', 'input_tokens' => 1000]);
        $second = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1000, 'attempt' => 2]);
        $this->assertSame([1, 2], [$first, $second]);
        $this->assertSame('error_billed', $this->row(1)['status']);
        $this->assertSame(1, $this->row(2)['parent_id']);
        $this->assertSame(2, $this->row(2)['attempt']);
        $this->assertSame(0, $this->row(2)['flags'] & Schema::FLAG_UNRESERVED);
    }

    public function testT19UnreservedRecords(): void
    {
        $base = ['purpose' => 'summary', 'caller' => 'p', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1];
        $a = $this->record($base);
        $b = $this->record($base + ['reservation_id' => '99999999-9999-4999-8999-999999999999']);
        $d = $this->check();
        spend_meter_release((string) $d['reservation_id']);
        $c = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]);
        foreach ([$a, $b, $c] as $id) {
            $this->assertSame(Schema::FLAG_UNRESERVED, $this->row($id)['flags'] & Schema::FLAG_UNRESERVED);
        }
        $this->assertSame('released', $this->row(3)['status']);
        $this->assertSame('summary', $this->row($c)['purpose'], 'identity taken from the released reservation');
        // Unknown reservation with no identity: kept, never dropped.
        $e = $this->record(['reservation_id' => '88888888-8888-4888-8888-888888888888', 'provider_cost' => 5]);
        $this->assertGreaterThan(0, $e);
        $this->assertSame('unknown', $this->row($e)['purpose']);
        $this->assertSame(5, $this->row($e)['cost']);
    }

    public function testT20CostSourcePrecedence(): void
    {
        // 1. Provider cost.
        $d = $this->check();
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1000, 'provider_cost' => 1234]);
        $this->assertSame(['provider', 1234], [$this->row($id)['cost_source'], $this->row($id)['cost']]);
        // 2. Table.
        $d = $this->check();
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'output_tokens' => 1000]);
        $this->assertSame(['table', 8000000], [$this->row($id)['cost_source'], $this->row($id)['cost']]);
        $this->assertNotNull($this->row($id)['price_version']);
        // 3. No usage, reservation exists: the estimate, flagged.
        $d = $this->check();
        $id = $this->record(['reservation_id' => $d['reservation_id']]);
        $this->assertSame(['estimate', self::n('0.006')], [$this->row($id)['cost_source'], $this->row($id)['cost']]);
        $this->assertSame(Schema::FLAG_USAGE_MISSING, $this->row($id)['flags'] & Schema::FLAG_USAGE_MISSING);
        // 4. No price, no provider cost.
        $id = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'mystery', 'input_tokens' => 50]);
        $this->assertSame(['unpriced', 0], [$this->row($id)['cost_source'], $this->row($id)['cost']]);
    }

    public function testT21ProviderAndTableCostBothStored(): void
    {
        $id = $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1000, 'provider_cost' => 2500000]);
        $this->assertSame(2500000, $this->row($id)['cost']);
        $this->assertSame(2000000, $this->row($id)['cost_table']);
    }

    public function testT22EachStatusStoredAndValidated(): void
    {
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1];
        foreach (['ok', 'error_billed', 'error_unbilled'] as $status) {
            $id = $this->record(['status' => $status] + $base);
            $this->assertSame($status, $this->row($id)['status']);
        }
        foreach (['released', 'expired', 'reserved', 'OK', ''] as $status) {
            $this->assertSame(-1, $this->record(['status' => $status] + $base));
        }
        $d = $this->check();
        spend_meter_release((string) $d['reservation_id']);
        $this->assertSame('released', $this->store->all()[3]['status']);
    }

    public function testT23ErrorBilledCountsErrorUnbilledDoesNot(): void
    {
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1000];
        $this->record(['status' => 'error_billed'] + $base);
        $this->assertSame(2000000, spend_meter_totals('site', 'day'));
        $id = $this->record(['status' => 'error_unbilled', 'provider_cost' => 999] + $base);
        $this->assertSame(0, $this->row($id)['cost']);
        $this->assertSame(2000000, spend_meter_totals('site', 'day'));
    }

    public function testT24AccountErrorsStoredAndSurfaced(): void
    {
        foreach ([401, 402, 403] as $i => $http) {
            $id = $this->record([
                'purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1',
                'status' => 'ok', 'http_status' => $http, 'input_tokens' => 10,
            ]);
            $row = $this->row($id);
            $this->assertSame('error_unbilled', $row['status']);
            $this->assertSame('http_' . $http, $row['error_code']);
            $this->assertSame(0, $row['cost']);
            $this->clock->advance(1);
        }
        $summary = $this->plugin->widget()->build(1);
        $this->assertSame(403, $summary['last_account_error']['http_status']);
        $warnings = \IndianOpinion\SpendMeter\Admin\Widget::warnings($summary);
        $this->assertNotEmpty(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'out of credit or bad key')));
    }

    public function testT25FallbackRecordsBilledModel(): void
    {
        $this->price('acme', 'text-2', '1', '1');
        $d = $this->check();
        $first = $this->record(['reservation_id' => $d['reservation_id'], 'status' => 'error_unbilled', 'http_status' => 503]);
        $second = $this->record(['reservation_id' => $d['reservation_id'], 'model' => 'text-2', 'input_tokens' => 1000, 'attempt' => 2]);
        $this->assertSame('text-1', $this->row($first)['model']);
        $this->assertSame('text-2', $this->row($second)['model']);
        $this->assertSame('text-1', $this->row($second)['fallback_from']);
        $this->assertSame(1000000, $this->row($second)['cost']);
        // Settling in place with a different model keeps the reserved model as fallback_from.
        $d = $this->check();
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'model' => 'text-2', 'input_tokens' => 1]);
        $this->assertSame('text-1', $this->row($id)['fallback_from']);
        $explicit = $this->check();
        $id = $this->record(['reservation_id' => $explicit['reservation_id'], 'model' => 'text-2', 'fallback_from' => 'text-0']);
        $this->assertSame('text-0', $this->row($id)['fallback_from']);
    }

    public function testT26DatabaseFailureBuffersRetriesThenCountsLoss(): void
    {
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1];
        $this->store->failWrites = true;
        $this->assertSame(0, $this->record($base));
        $this->assertSame(1, $this->plugin->ledger()->buffered());
        $this->assertTrue(has_action('shutdown'));
        // Retry succeeds.
        $this->store->failWrites = false;
        do_action('shutdown');
        $this->assertCount(1, $this->store->all());
        // Retry fails: counted, logged, nothing thrown.
        $this->store->failWrites = true;
        $this->assertSame(0, $this->record($base));
        $this->plugin->ledger()->flush();
        $this->assertSame(1, $this->plugin->counters()->losses()['count']);
        $this->assertTrue($this->logged('ledger write lost after retry'));
    }

    public function testT26BufferIsBounded(): void
    {
        $this->store->failWrites = true;
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1'];
        for ($i = 0; $i < 105; $i++) {
            $this->record($base);
        }
        $this->assertSame(100, $this->plugin->ledger()->buffered());
        $this->assertSame(5, $this->plugin->counters()->losses()['count']);
    }

    public function testT26SettleFailureIsBufferedAndRetried(): void
    {
        $d = $this->check();
        $this->store->failWrites = true;
        $this->assertSame(0, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]));
        $this->store->failWrites = false;
        $this->plugin->ledger()->flush();
        $this->assertSame('ok', $this->row(1)['status']);
    }

    public function testT26BufferedSettleOnAReservationSettledMeanwhileAddsALinkedRow(): void
    {
        $d = $this->check();
        $this->store->failWrites = true;
        $this->assertSame(0, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]));
        $this->store->failWrites = false;
        // Another attempt settles the reservation before the shutdown retry.
        $this->assertSame(1, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 2]));
        $this->plugin->ledger()->flush();
        $this->assertCount(2, $this->store->all());
        $this->assertSame(1, $this->row(2)['parent_id']);
        $this->assertSame(1, $this->row(2)['input_tokens']);
        $this->assertSame(0, $this->plugin->counters()->losses()['count']);
    }

    public function testT19SettleRacingAReleaseIsRecordedUnreserved(): void
    {
        $d = $this->check();
        // The release lands between the record's lookup and its settle.
        $reservationId = (string) $d['reservation_id'];
        $this->store->beforeUpdate = static function () use ($reservationId): void {
            spend_meter_release($reservationId);
        };
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 10]);
        $row = $this->row($id);
        $this->assertNotSame(1, $id);
        $this->assertSame(Schema::FLAG_UNRESERVED, $row['flags'] & Schema::FLAG_UNRESERVED);
        $this->assertSame('summary', $row['purpose']);
        $this->assertSame('released', $this->row(1)['status']);
    }

    public function testRecordThatCannotBePreparedCountsALoss(): void
    {
        add_filter('spend_meter_price', static function (): never {
            throw new \RuntimeException('price filter broke');
        });
        $this->assertSame(0, $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']));
        $this->assertSame(1, $this->plugin->counters()->losses()['count']);
    }

    public function testT15LiveReservationCannotTurnIntoATestRowOnRecord(): void
    {
        $d = $this->check();
        $id = $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 10, 'test' => true]);
        $this->assertSame(0, (int) $this->row($id)['is_test']);
    }

    public function testT27DuplicateCallIdReturnsFirstId(): void
    {
        $base = ['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'call_id' => self::CALL_ID];
        $first = $this->record($base);
        $second = $this->record($base + ['input_tokens' => 99]);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->store->all());
        // A retry reusing a reservation with a new call_id makes a linked row.
        $d = $this->check();
        $this->record(['reservation_id' => $d['reservation_id'], 'call_id' => '11111111-2222-4333-8444-000000000001']);
        $third = $this->record(['reservation_id' => $d['reservation_id'], 'call_id' => '11111111-2222-4333-8444-000000000002']);
        $this->assertSame(2, $this->row($third)['parent_id']);
    }

    public function testT28KillSwitchedPurposeStillRecorded(): void
    {
        $this->plugin->kill()->setPurpose('summary', true, 'cli');
        $this->plugin->kill()->setGlobal(true, 'cli');
        $id = $this->record(['purpose' => 'summary', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'input_tokens' => 1]);
        $this->assertGreaterThan(0, $id);
    }

    public function testT45RecordAfterKillFlipStillSettles(): void
    {
        $d = $this->check();
        $this->plugin->kill()->setGlobal(true, 'cli');
        $this->assertSame(1, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]));
        $this->assertSame('ok', $this->row(1)['status']);
    }

    public function testT47ReleaseSetsReleasedAndZeroCost(): void
    {
        $d = $this->check();
        do_action('spend_meter_release', $d['reservation_id']);
        $this->assertSame('released', $this->row(1)['status']);
        $this->assertSame(0, $this->row(1)['cost']);
        $this->assertSame(0, spend_meter_totals('site', 'day'));
        $d = $this->check();
        $this->assertTrue(spend_meter_release((string) $d['reservation_id']));
    }

    public function testT48ReleaseOfUnknownOrSettledIsNoOp(): void
    {
        $this->assertFalse(spend_meter_release('99999999-9999-4999-8999-999999999999'));
        $this->assertFalse(spend_meter_release('not-a-uuid'));
        $d = $this->check();
        $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]);
        $this->assertFalse(spend_meter_release((string) $d['reservation_id']));
        $this->assertSame('ok', $this->row(1)['status']);
        // A record for that settled reservation is not dropped.
        $this->assertSame(2, $this->record(['reservation_id' => $d['reservation_id'], 'input_tokens' => 1]));
    }

    public function testRecordedListenerThrowingDoesNotReachCaller(): void
    {
        add_action('spend_meter_recorded', static function (): void {
            throw new \RuntimeException('listener');
        });
        $this->assertSame(1, $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1']));
        $this->assertTrue($this->logged('listener threw'));
    }

    public function testCachedAndReasoningPricedOnRecord(): void
    {
        $this->price('acme', 'think-1', '10', '20', ['cached_input_per_million' => '1', 'reasoning_per_million' => '40']);
        $id = $this->record([
            'purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'think-1',
            'input_tokens' => 1000, 'cached_input_tokens' => 400, 'output_tokens' => 100, 'reasoning_tokens' => 60,
        ]);
        // 600 * 10 + 400 * 1 + 40 * 20 + 60 * 40 = 6000 + 400 + 800 + 2400 = 9600 (per million, in units)
        $this->assertSame(9600 * 1000, $this->row($id)['cost']);
        $this->assertSame(Options::get(Options::LOSSES, null), null);
    }
}
