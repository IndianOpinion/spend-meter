<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use IndianOpinion\SpendMeter\Store\Store;
use IndianOpinion\SpendMeter\Store\StoreException;

/**
 * Ledger schema, creation and verification.
 *
 * The schema routine can skip a column without an error, so after every
 * install or upgrade each expected column is checked. The db version option
 * is written only when every column exists; until then the pre-spend check
 * denies with ledger_unavailable and an admin notice names the missing columns.
 */
final class Schema
{
    public const VERSION = '1';
    public const TABLE = 'spend_meter_ledger';

    public const FLAG_UNRESERVED = 1;
    public const FLAG_USAGE_MISSING = 2;
    public const FLAG_UNPRICED_ALLOWED = 4;

    public const SETTLED = ['ok', 'error_billed', 'error_unbilled'];

    /** Column => sprintf format used by $wpdb. */
    public const COLUMNS = [
        'id' => '%d',
        'call_id' => '%s',
        'reservation_id' => '%s',
        'parent_id' => '%d',
        'blog_id' => '%d',
        'created_at' => '%s',
        'day' => '%s',
        'month' => '%s',
        'purpose' => '%s',
        'caller' => '%s',
        'provider' => '%s',
        'model' => '%s',
        'fallback_from' => '%s',
        'status' => '%s',
        'input_tokens' => '%d',
        'cached_input_tokens' => '%d',
        'output_tokens' => '%d',
        'reasoning_tokens' => '%d',
        'units' => '%s',
        'estimate' => '%d',
        'cost' => '%d',
        'cost_table' => '%d',
        'cost_source' => '%s',
        'currency' => '%s',
        'price_version' => '%s',
        'http_status' => '%d',
        'error_code' => '%s',
        'latency_ms' => '%d',
        'attempt' => '%d',
        'flags' => '%d',
        'is_test' => '%d',
        'user_id' => '%d',
        'meta' => '%s',
    ];

    public static function createSql(string $table, string $collate): string
    {
        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  call_id char(36) NOT NULL,
  reservation_id char(36) DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  blog_id bigint(20) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  day date NOT NULL,
  month char(7) NOT NULL,
  purpose varchar(64) NOT NULL,
  caller varchar(64) NOT NULL,
  provider varchar(64) NOT NULL,
  model varchar(128) NOT NULL,
  fallback_from varchar(128) DEFAULT NULL,
  status varchar(16) NOT NULL,
  input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  cached_input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  reasoning_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
  units decimal(20,6) NOT NULL DEFAULT 0,
  estimate bigint(20) NOT NULL DEFAULT 0,
  cost bigint(20) NOT NULL DEFAULT 0,
  cost_table bigint(20) DEFAULT NULL,
  cost_source varchar(16) NOT NULL,
  currency char(3) NOT NULL,
  price_version char(12) DEFAULT NULL,
  http_status smallint(5) DEFAULT NULL,
  error_code varchar(64) DEFAULT NULL,
  latency_ms int(10) unsigned DEFAULT NULL,
  attempt smallint(5) unsigned NOT NULL DEFAULT 1,
  flags smallint(5) unsigned NOT NULL DEFAULT 0,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned DEFAULT NULL,
  meta text DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY call_id (call_id),
  KEY reservation_id (reservation_id),
  KEY blog_day_purpose (blog_id,day,purpose),
  KEY blog_month_purpose (blog_id,month,purpose,is_test,day,status,created_at,estimate,cost),
  KEY day (day),
  KEY month_totals (month,is_test,day,status,created_at,estimate,cost),
  KEY status_created (status,created_at)
) {$collate};";
    }

    public static function ready(): bool
    {
        return self::VERSION === (string) Options::get(Options::DB_VERSION, '');
    }

    /**
     * Create or upgrade the table, then verify every column. Records the db
     * version only when the table is complete.
     *
     * @return list<string> Missing columns; empty when the ledger is ready.
     */
    public static function install(Store $store, Clock $clock): array
    {
        try {
            $store->install();
            $missing = $store->missingColumns();
        } catch (StoreException $e) {
            Logger::error('schema', 'ledger table could not be created: ' . $e->getMessage());
            return array_keys(self::COLUMNS);
        }
        if ([] !== $missing) {
            Logger::error('schema', 'ledger table is missing columns: ' . implode(', ', $missing));
            return $missing;
        }
        Options::set(Options::DB_VERSION, self::VERSION);
        if (null === Options::get(Options::LAST_CRON, null)) {
            Options::set(Options::LAST_CRON, $clock->timestamp());
        }

        return [];
    }

    /**
     * Install when the db version option is missing or old. Used on
     * admin_init and CLI runs, which also covers must-use installs where
     * activation hooks never run.
     *
     * @return list<string>
     */
    public static function maybeInstall(Store $store, Clock $clock): array
    {
        if (self::ready()) {
            return [];
        }

        return self::install($store, $clock);
    }
}
