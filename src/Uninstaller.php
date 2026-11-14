<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use Throwable;

/**
 * Removes every option, network option, transient and cron event the plugin
 * created, and drops the ledger table unless the "keep the ledger" setting is
 * on or SPEND_METER_KEEP_LEDGER is true. Run export first.
 */
final class Uninstaller
{
    public static function keepLedger(Plugin $plugin): bool
    {
        if (defined('SPEND_METER_KEEP_LEDGER') && true === constant('SPEND_METER_KEEP_LEDGER')) {
            return true;
        }

        return $plugin->settings()->all()['keep_ledger'];
    }

    public static function run(Plugin $plugin): void
    {
        $keep = self::keepLedger($plugin);
        Cron::unschedule();
        foreach (Options::all() as $name) {
            Options::delete($name);
            delete_option($name);
        }
        $blogIds = [(int) get_current_blog_id()];
        if (is_multisite() && function_exists('get_sites')) {
            $blogIds = array_map('intval', (array) get_sites(['fields' => 'ids', 'number' => 0]));
        }
        $clock = $plugin->clock();
        foreach ($blogIds as $blogId) {
            delete_site_transient('spend_meter_summary_' . $blogId);
            wp_cache_delete('spend_meter_summary_' . $blogId, 'spend_meter');
        }
        delete_site_transient('spend_meter_summary_network');
        delete_site_transient('spend_meter_network_' . $clock->day());
        if (!$keep) {
            try {
                $plugin->store()->drop();
            } catch (Throwable $e) {
                Logger::error('uninstall', 'ledger table could not be dropped: ' . $e->getMessage());
            }
        }
    }
}
