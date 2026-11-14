<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Admin;

use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Schema;
use Throwable;

/**
 * Admin notices: no prices loaded, ledger columns missing, price option corrupt.
 */
final class Notices
{
    public static function render(): void
    {
        echo self::html(Plugin::instance()); // phpcs:ignore -- escaped in html().
    }

    /**
     * @return list<string>
     */
    public static function messages(Plugin $plugin): array
    {
        if (!Access::allowed() && !Access::allowed(true)) {
            return [];
        }
        $out = [];
        if (!Schema::ready()) {
            try {
                $missing = $plugin->store()->missingColumns();
            } catch (Throwable) {
                $missing = [];
            }
            $out[] = [] === $missing
                ? 'spend-meter: the ledger table is not installed yet. Every pre-spend check is denied until it is.'
                    . ' Visit any admin page or run "wp spend-meter status" to install it.'
                : 'spend-meter: the ledger table is missing columns (' . implode(', ', $missing) . ').'
                    . ' Every pre-spend check is denied with ledger_unavailable until this is fixed.'
                    . ' Deactivate and reactivate the plugin, or add the columns by hand.';
        }
        $prices = $plugin->prices();
        if ($prices->isEmpty()) {
            $out[] = 'spend-meter: no prices loaded. Every model is unpriced, so every call is denied unless unpriced models are allowed.'
                . ' Import your own dated prices under Settings, Spend meter, or with "wp spend-meter prices import".';
        }
        if ($prices->corrupt()) {
            $out[] = 'spend-meter: the price override option is unreadable, so only the shipped seed is in use. Re-import your price file.';
        }

        return $out;
    }

    public static function html(Plugin $plugin): string
    {
        $html = '';
        foreach (self::messages($plugin) as $message) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html($message) . '</p></div>';
        }

        return $html;
    }
}
