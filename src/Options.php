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

    /**
     * Drop this process's in-memory copy of options, so the next read sees
     * what another process wrote.
     *
     * Called at the start of every pre-spend check for the kill switch and
     * the caps, which the admin screen or WP-CLI may change while a
     * long-running worker keeps checking (PRD cases 38 and 45). Without a
     * persistent object cache the local copy is dropped and the next read
     * goes to the database; with one, the shared copy (which every write
     * updates) is fetched past any local runtime copy.
     */
    public static function refresh(string ...$names): void
    {
        foreach ($names as $name) {
            if (is_multisite()) {
                $network = function_exists('get_current_network_id') ? (int) get_current_network_id() : 1;
                self::forget($network . ':' . $name, $network . ':notoptions', 'site-options', $name);
            } else {
                self::forget($name, 'notoptions', 'options', $name);
            }
        }
    }

    private static function forget(string $key, string $notKey, string $group, string $name): void
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_get($key, $group, true);
            wp_cache_get($notKey, $group, true);
            return;
        }
        wp_cache_delete($key, $group);
        $not = wp_cache_get($notKey, $group);
        if (is_array($not) && isset($not[$name])) {
            unset($not[$name]);
            wp_cache_set($notKey, $not, $group);
        }
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
