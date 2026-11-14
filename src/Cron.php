<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use Throwable;

/**
 * Hourly: mark expired reservations. Daily: retention in batches of 1,000.
 */
final class Cron
{
    public const HOURLY = 'spend_meter_hourly';
    public const DAILY = 'spend_meter_daily';
    public const BATCH = 1000;

    public static function schedule(): void
    {
        if (false === wp_next_scheduled(self::HOURLY)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOURLY);
        }
        if (false === wp_next_scheduled(self::DAILY)) {
            wp_schedule_event(time() + 900, 'daily', self::DAILY);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOURLY);
        wp_clear_scheduled_hook(self::DAILY);
    }

    public static function hourly(): void
    {
        $plugin = Plugin::instance();
        try {
            $plugin->ledger()->expire();
        } catch (Throwable $e) {
            Logger::error('cron', 'expiring reservations failed: ' . $e->getMessage());
        }
        Options::set(Options::LAST_CRON, $plugin->clock()->timestamp());
    }

    public static function daily(): void
    {
        $plugin = Plugin::instance();
        $months = $plugin->settings()->all()['retention_months'];
        if ($months > 0) {
            try {
                self::prune($plugin, $months);
            } catch (Throwable $e) {
                Logger::error('cron', 'retention failed: ' . $e->getMessage());
            }
        }
        Options::set(Options::LAST_CRON, $plugin->clock()->timestamp());
    }

    /**
     * UTC cutoff for a retention period in months.
     */
    public static function cutoff(Clock $clock, int $months): string
    {
        return (new \DateTimeImmutable('@' . $clock->timestamp()))
            ->modify('-' . $months . ' months')
            ->format('Y-m-d H:i:s');
    }

    /**
     * Remove rows that are not live reservations, older than the cutoff, in
     * batches of 1,000. Returns the number removed.
     */
    public static function prune(Plugin $plugin, int $months): int
    {
        $cutoff = self::cutoff($plugin->clock(), $months);
        $store = $plugin->store();
        $total = 0;
        do {
            $ids = $store->idsSettledBefore($cutoff, self::BATCH);
            $total += $store->deleteIds($ids);
        } while (count($ids) === self::BATCH);

        return $total;
    }
}
