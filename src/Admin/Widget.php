<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Admin;

use IndianOpinion\SpendMeter\KillSwitch;
use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Schema;
use Throwable;

/**
 * Dashboard widget: today and this month against the caps, the top five
 * purposes today, the kill switch state and warnings. Figures come from a
 * summary cached for 60 seconds; the refresh link clears it.
 */
final class Widget
{
    public const REFRESH_ACTION = 'spend_meter_refresh';
    public const CACHE_TTL = 60;

    public function __construct(private Plugin $plugin)
    {
    }

    public static function setup(): void
    {
        if (self::disabled() || !Access::allowed()) {
            return;
        }
        wp_add_dashboard_widget('spend_meter', 'Model spend', static function (): void {
            echo Plugin::instance()->widget()->render(false); // phpcs:ignore -- escaped in render().
        });
    }

    public static function setupNetwork(): void
    {
        if (self::disabled() || !Access::allowed(true)) {
            return;
        }
        wp_add_dashboard_widget('spend_meter_network', 'Model spend (network)', static function (): void {
            echo Plugin::instance()->widget()->render(true); // phpcs:ignore -- escaped in render().
        });
    }

    public static function disabled(): bool
    {
        return defined('SPEND_METER_DISABLE_WIDGET') && true === constant('SPEND_METER_DISABLE_WIDGET');
    }

    public static function handleRefresh(): void
    {
        $network = isset($_GET['network']); // phpcs:ignore -- checked with the nonce below.
        if (!self::processRefresh($_GET, $network)) { // phpcs:ignore
            wp_die(esc_html('You are not allowed to do that.'), 403);
        }
        wp_safe_redirect($network ? network_admin_url() : admin_url());
        exit;
    }

    /**
     * Clear the cached summary when the nonce and capability check pass.
     *
     * @param array<mixed> $request
     */
    public static function processRefresh(array $request, bool $network = false): bool
    {
        if (!Access::allowed($network) || !Access::nonceOk($request, self::REFRESH_ACTION)) {
            return false;
        }
        Plugin::instance()->widget()->clear($network ? null : (int) get_current_blog_id());

        return true;
    }

    public function clear(?int $blogId): void
    {
        $key = $this->cacheKey($blogId);
        wp_cache_delete($key, 'spend_meter');
        delete_site_transient($key);
    }

    /**
     * Widget figures for one blog, or the network when $blogId is null.
     *
     * @return array<string, mixed>
     */
    public function summary(?int $blogId): array
    {
        $key = $this->cacheKey($blogId);
        $cached = wp_cache_get($key, 'spend_meter');
        if (!is_array($cached) && !wp_using_ext_object_cache()) {
            $cached = get_site_transient($key);
        }
        if (is_array($cached)) {
            return $cached;
        }
        $summary = $this->build($blogId);
        wp_cache_set($key, $summary, 'spend_meter', self::CACHE_TTL);
        if (!wp_using_ext_object_cache()) {
            set_site_transient($key, $summary, self::CACHE_TTL);
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(?int $blogId): array
    {
        $plugin = $this->plugin;
        $clock = $plugin->clock();
        $settings = $plugin->settings()->all();
        $lifetime = $plugin->settings()->reservationSeconds();
        $day = $clock->day();
        $month = $clock->month();
        $liveSince = $clock->utc(-$lifetime);
        $oldBefore = $clock->utc(-intdiv($lifetime, 2));
        $store = $plugin->store();
        $summary = [
            'currency' => $settings['currency'],
            'day' => $day,
            'month' => $month,
            'network' => null === $blogId,
            'error' => null,
            'totals' => ['day_total' => 0, 'month_total' => 0, 'unpriced_today' => 0, 'unreserved_today' => 0,
                'live_reservations' => 0, 'old_reservations' => 0],
            'top' => [],
            'last_account_error' => null,
            'per_site' => [],
        ];
        try {
            $summary['totals'] = $store->summary($blogId, $day, $month, $liveSince, $oldBefore);
            if (null === $blogId) {
                $summary['per_site'] = $store->perSite($day, $month, $liveSince);
            } else {
                $summary['top'] = $store->topPurposes($blogId, $day, $month, $liveSince, 5);
            }
            $error = $store->lastAccountError($blogId, $clock->utc(-7 * 86400));
            if (null !== $error) {
                $summary['last_account_error'] = [
                    'provider' => (string) $error['provider'],
                    'model' => (string) $error['model'],
                    'http_status' => (int) $error['http_status'],
                    'error_code' => (string) ($error['error_code'] ?? ''),
                    'created_at' => (string) $error['created_at'],
                ];
            }
        } catch (Throwable $e) {
            $summary['error'] = 'The ledger could not be read.';
        }
        $caps = null === $blogId ? $plugin->caps()->network() : $plugin->caps()->site($blogId);
        $summary['caps'] = $caps;
        $kill = $plugin->kill();
        $summary['kill'] = [
            'global' => $kill->globalOn(),
            'locked' => KillSwitch::constantOn(),
            'purposes' => $kill->purposes(),
        ];
        $prices = $plugin->prices();
        $summary['prices_empty'] = $prices->isEmpty();
        $summary['prices_corrupt'] = $prices->corrupt();
        $summary['stale_prices'] = count($prices->stale($settings['stale_days'], $day));
        $summary['losses'] = $plugin->counters()->losses()['count'];
        $lastCron = Options::get(Options::LAST_CRON, null);
        $summary['cron_stale'] = is_int($lastCron) && $clock->timestamp() - $lastCron > 7200;
        $summary['schema_ready'] = Schema::ready();
        $summary['threshold'] = $settings['warning_threshold'];

        return $summary;
    }

    /**
     * Warning lines for a summary.
     *
     * @param  array<string, mixed> $s
     * @return list<string>
     */
    public static function warnings(array $s): array
    {
        $out = [];
        $kill = is_array($s['kill'] ?? null) ? $s['kill'] : [];
        if (true === ($kill['global'] ?? false)) {
            $out[] = true === ($kill['locked'] ?? false)
                ? 'Kill switch is ON (held by the SPEND_METER_KILL constant).'
                : 'Kill switch is ON. Every new call is refused.';
        }
        if (is_array($kill['purposes'] ?? null) && [] !== $kill['purposes']) {
            $out[] = 'Purpose kill switches: ' . implode(', ', array_map('strval', $kill['purposes'])) . '.';
        }
        if (false === ($s['schema_ready'] ?? true)) {
            $out[] = 'The ledger table is not ready; every check is denied.';
        }
        if (is_string($s['error'] ?? null)) {
            $out[] = $s['error'];
        }
        if (true === ($s['prices_empty'] ?? false)) {
            $out[] = 'No prices loaded. Every model is unpriced.';
        }
        if (true === ($s['prices_corrupt'] ?? false)) {
            $out[] = 'The price override option is unreadable; re-import your prices.';
        }
        $totals = is_array($s['totals'] ?? null) ? $s['totals'] : [];
        if (($totals['unpriced_today'] ?? 0) > 0) {
            $out[] = sprintf('%d unpriced calls today.', (int) $totals['unpriced_today']);
        }
        if (($totals['unreserved_today'] ?? 0) > 0) {
            $out[] = sprintf('%d calls today were recorded without a pre-spend check.', (int) $totals['unreserved_today']);
        }
        if (($totals['old_reservations'] ?? 0) > 0) {
            $out[] = sprintf('%d live reservations are older than half their lifetime.', (int) $totals['old_reservations']);
        }
        $error = $s['last_account_error'] ?? null;
        if (is_array($error)) {
            $out[] = sprintf(
                'Provider refused: out of credit or bad key (%s %s, HTTP %d at %s UTC).',
                (string) $error['provider'],
                (string) $error['model'],
                (int) $error['http_status'],
                (string) $error['created_at']
            );
        }
        if (($s['losses'] ?? 0) > 0) {
            $out[] = sprintf('%d ledger writes were lost after retry.', (int) $s['losses']);
        }
        if (($s['stale_prices'] ?? 0) > 0) {
            $out[] = sprintf('%d prices are older than the staleness threshold.', (int) $s['stale_prices']);
        }
        if (true === ($s['cron_stale'] ?? false)) {
            $out[] = 'WP-Cron has not run for over two hours; expired reservations are not being marked.';
        }

        return $out;
    }

    public function render(bool $network): string
    {
        $s = $this->summary($network ? null : (int) get_current_blog_id());
        $currency = (string) $s['currency'];
        $totals = is_array($s['totals']) ? $s['totals'] : [];
        $caps = is_array($s['caps']) ? $s['caps'] : ['day' => null, 'month' => null];
        $threshold = (int) $s['threshold'];
        $html = '<div class="spend-meter-widget">';
        foreach (['day' => ['Today', 'day_total'], 'month' => ['This month', 'month_total']] as $window => [$label, $key]) {
            $total = (int) ($totals[$key] ?? 0);
            $limit = $caps[$window] ?? null;
            $line = Money::format($total, $currency);
            $style = '';
            if (is_int($limit)) {
                $percent = 0 === $limit ? 100 : (int) min(999, intdiv($total * 100, max(1, $limit)));
                $line .= ' of ' . Money::format($limit, $currency) . ' (' . $percent . '%)';
                $colour = $percent >= 100 ? '#d63638' : ($percent >= $threshold ? '#dba617' : '#00a32a');
                $style = '<div style="background:#f0f0f1;height:8px"><div style="background:' . esc_attr($colour)
                    . ';height:8px;width:' . esc_attr((string) min(100, $percent)) . '%"></div></div>';
            }
            $html .= '<p><strong>' . esc_html($label) . ':</strong> ' . esc_html($line) . '</p>' . $style;
        }
        if (is_array($s['top']) && [] !== $s['top']) {
            $html .= '<p><strong>' . esc_html('Top purposes today') . '</strong></p><ul>';
            foreach ($s['top'] as $row) {
                $html .= '<li>' . esc_html((string) $row['purpose']) . ': ' . esc_html(Money::format((int) $row['total'], $currency)) . '</li>';
            }
            $html .= '</ul>';
        }
        if (is_array($s['per_site']) && [] !== $s['per_site']) {
            $html .= '<table class="widefat"><thead><tr><th>' . esc_html('Site') . '</th><th>' . esc_html('Today')
                . '</th><th>' . esc_html('This month') . '</th></tr></thead><tbody>';
            foreach ($s['per_site'] as $blogId => $row) {
                $html .= '<tr><td>' . esc_html((string) $blogId) . '</td><td>' . esc_html(Money::format((int) $row['day'], $currency))
                    . '</td><td>' . esc_html(Money::format((int) $row['month'], $currency)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $kill = is_array($s['kill']) ? $s['kill'] : [];
        $html .= '<p><strong>' . esc_html('Kill switch') . ':</strong> ' . esc_html(true === ($kill['global'] ?? false) ? 'on' : 'off') . '</p>';
        $rows = apply_filters('spend_meter_widget_rows', []);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && is_scalar($row['label'] ?? null) && is_scalar($row['value'] ?? null)) {
                    $html .= '<p><strong>' . esc_html((string) $row['label']) . ':</strong> ' . esc_html((string) $row['value']) . '</p>';
                }
            }
        }
        $warnings = self::warnings($s);
        if ([] !== $warnings) {
            $html .= '<ul class="spend-meter-warnings">';
            foreach ($warnings as $warning) {
                $html .= '<li style="color:#b32d2e">' . esc_html($warning) . '</li>';
            }
            $html .= '</ul>';
        }
        $args = ['action' => self::REFRESH_ACTION];
        if ($network) {
            $args['network'] = '1';
        }
        $url = wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), self::REFRESH_ACTION);
        $html .= '<p><a href="' . esc_url($url) . '">' . esc_html('Refresh') . '</a></p></div>';

        return $html;
    }

    private function cacheKey(?int $blogId): string
    {
        return 'spend_meter_summary_' . (null === $blogId ? 'network' : (string) $blogId);
    }
}
