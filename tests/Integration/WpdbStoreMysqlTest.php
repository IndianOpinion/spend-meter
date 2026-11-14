<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Integration;

use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Schema;
use IndianOpinion\SpendMeter\Store\WpdbStore;
use IndianOpinion\SpendMeter\Tests\Support\PdoWpdb;
use IndianOpinion\SpendMeter\Tests\TestCase;
use PDO;

/**
 * The real SQL against MySQL or MariaDB. Skipped unless
 * SPEND_METER_TEST_MYSQL_DSN is set, for example
 * "mysql:host=127.0.0.1;port=3306;dbname=spend_meter_test", with
 * SPEND_METER_TEST_MYSQL_USER and SPEND_METER_TEST_MYSQL_PASSWORD.
 * The test creates and drops its own table, it_spend_meter_ledger.
 */
final class WpdbStoreMysqlTest extends TestCase
{
    private WpdbStore $wpdbStore;
    private PdoWpdb $db;

    protected function setUp(): void
    {
        $dsn = getenv('SPEND_METER_TEST_MYSQL_DSN');
        if (!is_string($dsn) || '' === $dsn) {
            $this->markTestSkipped('Set SPEND_METER_TEST_MYSQL_DSN to run the MySQL integration test.');
        }
        parent::setUp();
        $pdo = new PDO($dsn, (string) getenv('SPEND_METER_TEST_MYSQL_USER'), (string) getenv('SPEND_METER_TEST_MYSQL_PASSWORD'));
        $this->db = new PdoWpdb($pdo);
        $this->wpdbStore = new WpdbStore($this->db);
        $this->db->query('DROP TABLE IF EXISTS ' . $this->wpdbStore->table());
        $this->assertNotFalse($this->db->query(Schema::createSql($this->wpdbStore->table(), $this->db->get_charset_collate())), $this->db->last_error);
        $this->plugin = new Plugin($this->wpdbStore, $this->clock, dirname(__DIR__, 2) . '/data/prices.json');
        Plugin::setInstance($this->plugin);
        \WpStub::$hooks = [];
        Plugin::register(dirname(__DIR__, 2) . '/spend-meter.php');
        Options::set(Options::DB_VERSION, Schema::VERSION);
        $this->price('acme', 'text-1', '2', '8');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->wpdbStore->table());
        }
        parent::tearDown();
    }

    public function testSchemaIsComplete(): void
    {
        $this->assertSame([], $this->wpdbStore->missingColumns());
        $this->db->query('ALTER TABLE ' . $this->wpdbStore->table() . ' DROP COLUMN flags');
        $this->assertSame(['flags'], $this->wpdbStore->missingColumns());
    }

    public function testReserveSumSettleAndRaceOnRealSql(): void
    {
        $this->setCaps('0.01');
        $this->plugin->caps()->set('purpose', 1, 'sum*', self::n('1'), self::n('1'));
        $d = $this->check();
        $this->assertTrue($d['allowed']);
        $this->assertSame(self::n('0.006'), spend_meter_totals('site', 'day'));
        $this->assertSame(self::n('0.006'), spend_meter_totals('purpose', 'month', 'summary'));
        $this->assertSame('cap_site_day', $this->check()['reason'], 'second reservation sees the first');
        $id = spend_meter_record(['reservation_id' => $d['reservation_id'], 'status' => 'ok', 'input_tokens' => 1000, 'output_tokens' => 100]);
        $this->assertGreaterThan(0, $id);
        $row = $this->wpdbStore->find($id);
        $this->assertSame('ok', $row['status']);
        $this->assertSame('2800000', $row['cost']);
        $this->assertSame(2800000, spend_meter_totals('site', 'day'));
        $linked = spend_meter_record(['reservation_id' => $d['reservation_id'], 'status' => 'ok', 'input_tokens' => 1]);
        $this->assertSame((string) $id, $this->wpdbStore->find($linked)['parent_id']);
        $call = [
            'purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'status' => 'ok',
            'call_id' => '11111111-2222-4333-8444-555555555555',
        ];
        $dup = spend_meter_record($call);
        $this->assertSame($dup, spend_meter_record($call));
    }

    public function testExpireReleaseReportExportAndRetention(): void
    {
        $d = $this->check(['purpose' => 'sum_a']);
        $e = $this->check(['purpose' => 'sum%x']);
        $this->assertFalse($e['allowed'], 'percent is not a valid slug character');
        $this->assertTrue(spend_meter_release((string) $d['reservation_id']));
        $this->check();
        $this->clock->advance(3600);
        $this->assertSame(1, $this->plugin->ledger()->expire());
        spend_meter_record(['purpose' => 'summary', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'status' => 'error_unbilled', 'http_status' => 402]);
        $summary = $this->wpdbStore->summary(1, $this->clock->day(), $this->clock->month(), $this->clock->utc(-900), $this->clock->utc(-450));
        $this->assertSame(1, $summary['unreserved_today']);
        $this->assertSame('402', $this->wpdbStore->lastAccountError(1, $this->clock->utc(-86400))['http_status']);
        $report = $this->wpdbStore->report('2026-11-01', '2026-11-30', 'purpose', false, 1);
        $this->assertSame('summary', $report[0]['key']);
        // Released, expired and the account error: the invalid check wrote nothing.
        $this->assertCount(3, $this->wpdbStore->rowsBetween('2026-11-01', '2026-11-30', 0, 100));
        $this->assertSame([1 => ['day' => 0, 'month' => 0]], $this->wpdbStore->perSite($this->clock->day(), $this->clock->month(), $this->clock->utc(-900)));
        $top = $this->wpdbStore->topPurposes(1, $this->clock->day(), $this->clock->month(), $this->clock->utc(-900), 5);
        $this->assertSame(['sum_a', 'summary'], array_column($top, 'purpose'));
        $this->assertSame(0, array_sum(array_column($top, 'total')), 'released, expired and unbilled rows count zero');
        $this->clock->advance(400 * 86400);
        $this->assertSame(3, $this->wpdbStore->countSettledBefore($this->clock->utc(-86400)));
        $ids = $this->wpdbStore->idsSettledBefore($this->clock->utc(-86400), 1000);
        $this->assertSame(3, $this->wpdbStore->deleteIds($ids));
        $this->assertFalse($this->wpdbStore->hasRows());
    }

    public function testPrefixPatternEscapesUnderscore(): void
    {
        $this->plugin->caps()->set('purpose', 1, 'a_b*', self::n('1'), null);
        $this->check(['purpose' => 'a_bc']);
        $this->check(['purpose' => 'axbc']);
        $totals = $this->wpdbStore->windowTotals(1, $this->clock->day(), $this->clock->month(), ['a_b*'], $this->clock->utc(-900));
        $this->assertSame(self::n('0.006'), $totals['purposes']['a_b*']['day'], 'underscore is literal, not a wildcard');
    }

    public function testNetworkTotalsAndUnpricedModels(): void
    {
        spend_meter_record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'mystery', 'status' => 'ok', 'input_tokens' => 1]);
        spend_meter_record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'text-1', 'status' => 'ok', 'input_tokens' => 1000]);
        $this->assertSame(['day' => 2000000, 'month' => 2000000], $this->wpdbStore->networkTotals($this->clock->day(), $this->clock->month(), $this->clock->utc(-900)));
        $this->assertSame([['provider' => 'acme', 'model' => 'mystery', 'calls' => 1]], $this->wpdbStore->unpricedModels('2026-11-01'));
        $this->assertCount(1, $this->wpdbStore->unpricedRows('2026-11-01', '2026-11-30', 0, 10));
        $this->assertCount(0, $this->wpdbStore->reservations(10));
    }
}
