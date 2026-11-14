<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Option storage. There is one ledger per network, so every spend-meter
 * option is network-wide: a network option on multisite and a normal option
 * on a single site. Only the settings array autoloads.
 */
final class Options
{
    public const SETTINGS = 'spend_meter_settings';
    public const CAPS = 'spend_meter_caps';
    public const KILL = 'spend_meter_kill';
    public const PRICES = 'spend_meter_prices_override';
    public const DB_VERSION = 'spend_meter_db_version';
    public const ALERT_STATE = 'spend_meter_alert_state';
    public const LAST_CRON = 'spend_meter_last_cron';
    public const LOSSES = 'spend_meter_record_losses';
    public const DENIALS = 'spend_meter_denials';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::SETTINGS, self::CAPS, self::KILL, self::PRICES, self::DB_VERSION,
            self::ALERT_STATE, self::LAST_CRON, self::LOSSES, self::DENIALS,
        ];
    }

    public static function get(string $name, mixed $default = null): mixed
    {
        if (is_multisite()) {
            return get_site_option($name, $default);
        }

        return get_option($name, $default);
    }

    public static function set(string $name, mixed $value): void
    {
        if (is_multisite()) {
            update_site_option($name, $value);
            return;
        }
        update_option($name, $value, self::SETTINGS === $name);
    }

    public static function delete(string $name): void
    {
        if (is_multisite()) {
            delete_site_option($name);
            return;
        }
        delete_option($name);
    }
}
