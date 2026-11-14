<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Admin;

use IndianOpinion\SpendMeter\KillSwitch;
use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\Pattern;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\PriceFileException;
use IndianOpinion\SpendMeter\Settings;
use IndianOpinion\SpendMeter\ValidationException;
use Throwable;

/**
 * The settings screen.
 *
 * Single site: one screen under Settings with everything. Multisite: the
 * site screen holds that site's caps and purpose caps; the Network Settings
 * screen holds kill switches, global settings, network caps and prices.
 * Every save checks the capability and a nonce and validates every field
 * first; one invalid field saves nothing.
 */
final class SettingsPage
{
    public const SLUG = 'spend-meter';
    public const SAVE_ACTION = 'spend_meter_save';
    public const IMPORT_ACTION = 'spend_meter_import';

    public static function menu(): void
    {
        add_options_page('Spend meter', 'Spend meter', Access::capability(), self::SLUG, [self::class, 'renderSite']);
    }

    public static function networkMenu(): void
    {
        add_submenu_page(
            'settings.php',
            'Spend meter',
            'Spend meter',
            Access::capability(true),
            self::SLUG,
            [self::class, 'renderNetwork']
        );
    }

    public static function renderSite(): void
    {
        echo self::html(Plugin::instance(), false); // phpcs:ignore -- escaped in html().
    }

    public static function renderNetwork(): void
    {
        echo self::html(Plugin::instance(), true); // phpcs:ignore -- escaped in html().
    }

    /**
     * True when the context edits global settings (single site, or the network screen).
     */
    public static function globalContext(bool $network): bool
    {
        return $network || !is_multisite();
    }

    public static function siteContext(bool $network): bool
    {
        return !$network;
    }

    public static function handlePost(): void
    {
        $network = '1' === ($_POST['network'] ?? ''); // phpcs:ignore -- nonce checked in process().
        $result = self::process(wp_unslash($_POST), $network); // phpcs:ignore
        self::finish($result, $network);
    }

    public static function handleImport(): void
    {
        $network = '1' === ($_POST['network'] ?? ''); // phpcs:ignore -- nonce checked in processImport().
        $result = self::processImport(wp_unslash($_POST), $network); // phpcs:ignore
        self::finish($result, $network);
    }

    /**
     * @param array{ok: bool, errors: array<string, string>, notice: string, forbidden?: bool} $result
     */
    private static function finish(array $result, bool $network): void
    {
        if (true === ($result['forbidden'] ?? false)) {
            wp_die(esc_html('You are not allowed to do that.'), 403);
        }
        set_transient(self::flashKey(), $result, 300);
        $base = $network ? network_admin_url('settings.php') : admin_url('options-general.php');
        wp_safe_redirect(add_query_arg('page', self::SLUG, $base));
        exit;
    }

    /**
     * Validate and save the settings form.
     *
     * @param  array<mixed> $post
     * @return array{ok: bool, errors: array<string, string>, notice: string, forbidden?: bool}
     */
    public static function process(array $post, bool $network): array
    {
        if (!Access::allowed($network) || !Access::nonceOk($post, self::SAVE_ACTION)) {
            return ['ok' => false, 'errors' => [], 'notice' => '', 'forbidden' => true];
        }
        $plugin = Plugin::instance();
        $errors = [];
        $actor = (string) get_current_user_id();
        $blogId = (int) get_current_blog_id();
        $text = static fn (string $key): string => is_string($post[$key] ?? null) ? trim($post[$key]) : '';
        $amount = static function (string $key) use ($text, &$errors): ?int {
            $value = $text($key);
            if ('' === $value) {
                return null;
            }
            try {
                return Money::decimalToNanos($value, $key);
            } catch (ValidationException) {
                $errors[$key] = 'Enter an amount such as 12.50, or leave it empty for no cap.';
                return null;
            }
        };

        $settingsInput = null;
        $killPurposes = null;
        $killGlobal = null;
        $networkCaps = null;
        if (self::globalContext($network)) {
            $settingsInput = [];
            foreach (['warning_threshold', 'default_output_tokens', 'reservation_minutes', 'currency', 'stale_days', 'retention_months'] as $key) {
                $settingsInput[$key] = $text($key);
            }
            $settingsInput['allow_unpriced'] = '1' === $text('allow_unpriced');
            $settingsInput['keep_ledger'] = '1' === $text('keep_ledger');
            [, $settingErrors] = Settings::validate($settingsInput, $plugin->settings()->all());
            $errors += $settingErrors;
            $killGlobal = '1' === $text('kill_global');
            $killPurposes = [];
            foreach (preg_split('/[\s,]+/', strtolower($text('kill_purposes'))) ?: [] as $entry) {
                if ('' === $entry) {
                    continue;
                }
                if (!Pattern::isPattern($entry)) {
                    $errors['kill_purposes'] = sprintf('"%s" is not a slug or a slug followed by *.', $entry);
                    break;
                }
                $killPurposes[] = $entry;
            }
            if ($network && is_multisite()) {
                $networkCaps = ['day' => $amount('network_cap_day'), 'month' => $amount('network_cap_month')];
            }
        }
        $siteCaps = null;
        $purposeCaps = null;
        if (self::siteContext($network)) {
            $siteCaps = ['day' => $amount('site_cap_day'), 'month' => $amount('site_cap_month')];
            $purposeCaps = [];
            $lines = preg_split('/\r\n|\r|\n/', $text('purpose_caps')) ?: [];
            foreach ($lines as $n => $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line) ?: [];
                $pattern = strtolower($parts[0]);
                $parsed = [];
                $bad = !Pattern::isPattern($pattern) || count($parts) < 2 || count($parts) > 3;
                foreach ([1 => 'day', 2 => 'month'] as $i => $window) {
                    $raw = $parts[$i] ?? '-';
                    if ('-' === $raw) {
                        $parsed[$window] = null;
                        continue;
                    }
                    try {
                        $parsed[$window] = Money::decimalToNanos($raw);
                    } catch (ValidationException) {
                        $bad = true;
                    }
                }
                if ($bad) {
                    $errors['purpose_caps'] = sprintf('Line %d: use "purpose day month", with "-" for no cap.', $n + 1);
                    break;
                }
                $purposeCaps[$pattern] = ['day' => $parsed['day'] ?? null, 'month' => $parsed['month'] ?? null];
            }
        }
        if ($killGlobal === false && KillSwitch::constantOn()) {
            $killGlobal = null; // Locked by the constant: the box is disabled and posts nothing.
        }
        if ([] !== $errors) {
            return ['ok' => false, 'errors' => $errors, 'notice' => 'Nothing was saved. Fix the fields marked below.'];
        }

        // Everything validated: write.
        if (null !== $settingsInput) {
            try {
                $hasRows = $plugin->store()->hasRows();
            } catch (Throwable) {
                $hasRows = true;
            }
            $saveErrors = $plugin->settings()->save($settingsInput, $hasRows);
            if ([] !== $saveErrors) {
                return ['ok' => false, 'errors' => $saveErrors, 'notice' => 'Nothing was saved. Fix the fields marked below.'];
            }
            $plugin->resetPrices();
        }
        try {
            if (null !== $killGlobal) {
                $plugin->kill()->setGlobal($killGlobal, $actor);
            }
            if (null !== $killPurposes) {
                $plugin->kill()->replacePurposes($killPurposes, $actor);
            }
            $caps = $plugin->caps();
            if (null !== $networkCaps) {
                $caps->unset('network', $blogId, null);
                $caps->set('network', $blogId, null, $networkCaps['day'], $networkCaps['month']);
            }
            if (null !== $siteCaps) {
                $caps->unset('site', $blogId, null);
                $caps->set('site', $blogId, null, $siteCaps['day'], $siteCaps['month']);
            }
            if (null !== $purposeCaps) {
                $caps->replacePurposes($blogId, $purposeCaps);
            }
        } catch (ValidationException $e) {
            return ['ok' => false, 'errors' => [$e->key() => $e->getMessage()], 'notice' => 'Some settings were not saved.'];
        }
        $plugin->widget()->clear($network ? null : $blogId);

        return ['ok' => true, 'errors' => [], 'notice' => 'Settings saved.'];
    }

    /**
     * Import a pasted price file.
     *
     * @param  array<mixed> $post
     * @return array{ok: bool, errors: array<string, string>, notice: string, forbidden?: bool}
     */
    public static function processImport(array $post, bool $network): array
    {
        if (!self::globalContext($network) || !Access::allowed($network) || !Access::nonceOk($post, self::IMPORT_ACTION)) {
            return ['ok' => false, 'errors' => [], 'notice' => '', 'forbidden' => true];
        }
        $json = is_string($post['prices_json'] ?? null) ? $post['prices_json'] : '';
        $mode = 'replace' === ($post['mode'] ?? '') ? 'replace' : 'merge';
        $dryRun = '1' === ($post['dry_run'] ?? '');
        try {
            $diff = Plugin::instance()->prices()->import($json, $mode, $dryRun);
        } catch (PriceFileException $e) {
            return ['ok' => false, 'errors' => ['prices_json' => implode(' ', $e->errors())], 'notice' => 'The price file was rejected; nothing was imported.'];
        }
        Plugin::instance()->resetPrices();
        $summary = sprintf(
            '%s: %d added, %d changed, %d removed, %d unchanged.',
            $dryRun ? 'Dry run, nothing saved' : 'Prices imported',
            count($diff['added']),
            count($diff['changed']),
            count($diff['removed']),
            $diff['unchanged']
        );
        $detail = [];
        foreach (['added', 'changed', 'removed'] as $kind) {
            if ([] !== $diff[$kind]) {
                $detail[] = ucfirst($kind) . ': ' . implode(', ', $diff[$kind]) . '.';
            }
        }

        return ['ok' => true, 'errors' => [], 'notice' => trim($summary . ' ' . implode(' ', $detail))];
    }

    private static function flashKey(): string
    {
        return 'spend_meter_flash_' . (string) get_current_user_id();
    }

    public static function html(Plugin $plugin, bool $network): string
    {
        if (!Access::allowed($network)) {
            return '';
        }
        $flash = get_transient(self::flashKey());
        if (is_array($flash)) {
            delete_transient(self::flashKey());
        }
        $errors = is_array($flash) && is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
        $settings = $plugin->settings()->all();
        $currency = $settings['currency'];
        $blogId = (int) get_current_blog_id();
        $err = static fn (string $key): string => isset($errors[$key])
            ? '<p class="description" style="color:#b32d2e">' . esc_html((string) $errors[$key]) . '</p>'
            : '';
        $dec = static fn (?int $nanos): string => null === $nanos ? '' : Money::nanosToDecimal($nanos);
        $input = static fn (string $name, string $value, string $type = 'text'): string => '<input type="' . esc_attr($type)
            . '" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';
        $row = static fn (string $label, string $field, string $key): string => '<tr><th scope="row"><label for="'
            . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>' . $field . $err($key) . '</td></tr>';
        $check = static fn (string $name, bool $on, bool $disabled = false): string => '<input type="checkbox" name="'
            . esc_attr($name) . '" id="' . esc_attr($name) . '" value="1"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>';

        $html = '<div class="wrap"><h1>' . esc_html($network ? 'Spend meter (network)' : 'Spend meter') . '</h1>';
        if (is_array($flash) && is_string($flash['notice'] ?? null) && '' !== $flash['notice']) {
            $class = true === ($flash['ok'] ?? false) ? 'notice-success' : 'notice-error';
            $html .= '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($flash['notice']) . '</p></div>';
        }
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">'
            . '<input type="hidden" name="network" value="' . esc_attr($network ? '1' : '0') . '">'
            . wp_nonce_field(self::SAVE_ACTION, '_wpnonce', true, false)
            . '<table class="form-table" role="presentation">';

        if (self::globalContext($network)) {
            $kill = $plugin->kill();
            $locked = KillSwitch::constantOn();
            $html .= $row(
                'Global kill switch',
                $check('kill_global', $kill->globalOn(), $locked)
                    . ($locked ? ' <strong>' . esc_html('Locked on by the SPEND_METER_KILL constant.') . '</strong>' : ''),
                'kill_global'
            );
            $constantPurposes = KillSwitch::constantPurposes();
            $html .= $row(
                'Purpose kill switches',
                '<textarea name="kill_purposes" id="kill_purposes" rows="3" cols="40">'
                    . esc_textarea(implode("\n", $kill->stored()['purposes'])) . '</textarea>'
                    . '<p class="description">' . esc_html('One slug per line; end with * to match a prefix.') . '</p>'
                    . ([] === $constantPurposes ? '' : '<p class="description">'
                    . esc_html('Held by SPEND_METER_KILL_PURPOSES (cannot be removed here): ' . implode(', ', $constantPurposes)) . '</p>'),
                'kill_purposes'
            );
        }
        if (self::siteContext($network)) {
            $site = $plugin->caps()->site($blogId);
            $html .= $row('Site day cap (' . $currency . ')', $input('site_cap_day', $dec($site['day'])), 'site_cap_day');
            $html .= $row('Site month cap (' . $currency . ')', $input('site_cap_month', $dec($site['month'])), 'site_cap_month');
            $lines = [];
            foreach ($plugin->caps()->purposes($blogId) as $pattern => $limit) {
                $lines[] = $pattern . ' ' . ($dec($limit['day']) ?: '-') . ' ' . ($dec($limit['month']) ?: '-');
            }
            $html .= $row(
                'Purpose caps',
                '<textarea name="purpose_caps" id="purpose_caps" rows="4" cols="40">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
                    . '<p class="description">' . esc_html('One per line: purpose day month. Use - for no cap; a trailing * matches a prefix.') . '</p>',
                'purpose_caps'
            );
        }
        if ($network && is_multisite()) {
            $net = $plugin->caps()->network();
            $html .= $row('Network day cap (' . $currency . ')', $input('network_cap_day', $dec($net['day'])), 'network_cap_day');
            $html .= $row('Network month cap (' . $currency . ')', $input('network_cap_month', $dec($net['month'])), 'network_cap_month');
        }
        if (self::globalContext($network)) {
            $html .= $row('Warning threshold (percent)', $input('warning_threshold', (string) $settings['warning_threshold'], 'number'), 'warning_threshold');
            $html .= $row(
                'Allow unpriced models',
                $check('allow_unpriced', $settings['allow_unpriced'])
                    . '<p class="description">' . esc_html('Off: a model with no price is refused whenever a cap is set. On: allowed, recorded at zero cost, and logged.') . '</p>',
                'allow_unpriced'
            );
            $html .= $row('Default output allowance (tokens)', $input('default_output_tokens', (string) $settings['default_output_tokens'], 'number'), 'default_output_tokens');
            $html .= $row('Reservation lifetime (minutes)', $input('reservation_minutes', (string) $settings['reservation_minutes'], 'number'), 'reservation_minutes');
            $html .= $row('Ledger currency', $input('currency', $settings['currency']), 'currency');
            $html .= $row('Price staleness threshold (days)', $input('stale_days', (string) $settings['stale_days'], 'number'), 'stale_days');
            $html .= $row('Retention (months, 0 keeps everything)', $input('retention_months', (string) $settings['retention_months'], 'number'), 'retention_months');
            $html .= $row('Keep the ledger on uninstall', $check('keep_ledger', $settings['keep_ledger']), 'keep_ledger');
        }
        $html .= '</table><p class="submit"><button type="submit" class="button button-primary">' . esc_html('Save changes') . '</button></p></form>';

        if (self::globalContext($network)) {
            $html .= self::pricesHtml($plugin, $network, $settings['stale_days'], $errors);
        }

        return $html . '</div>';
    }

    /**
     * @param array<mixed> $errors
     */
    private static function pricesHtml(Plugin $plugin, bool $network, int $staleDays, array $errors): string
    {
        $prices = $plugin->prices();
        $html = '<h2>' . esc_html('Prices') . '</h2>';
        if ($prices->isEmpty()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html(
                'No prices loaded. Read each price from the provider\'s own pricing page, date it, and import it below.'
            ) . '</p></div>';
        } else {
            $stale = [];
            foreach ($prices->stale($staleDays, $plugin->clock()->day()) as $price) {
                $stale[$price['provider'] . '|' . $price['model']] = true;
            }
            $html .= '<table class="widefat striped"><thead><tr>';
            foreach (['Provider', 'Model', 'Input / M', 'Cached / M', 'Output / M', 'Unit', 'Fee', 'As of', 'Source', 'From'] as $head) {
                $html .= '<th>' . esc_html($head) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            $dec = static fn (?int $v): string => null === $v ? '' : Money::nanosToDecimal($v);
            foreach ($prices->effective() as $key => $price) {
                $age = isset($stale[$key]) ? ' (stale)' : '';
                $unit = null === $price['unit'] ? '' : $dec($price['unit_price']) . ' / ' . $price['unit'];
                $html .= '<tr><td>' . esc_html($price['provider']) . '</td><td>' . esc_html($price['model']) . '</td><td>'
                    . esc_html($dec($price['input'])) . '</td><td>' . esc_html($dec($price['cached_input'])) . '</td><td>'
                    . esc_html($dec($price['output'])) . '</td><td>' . esc_html($unit) . '</td><td>'
                    . esc_html($dec($price['per_call_fee'])) . '</td><td>' . esc_html((string) $price['as_of'] . $age) . '</td><td>'
                    . (null === $price['source'] ? '' : '<a href="' . esc_url($price['source']) . '" rel="noopener noreferrer">' . esc_html('link') . '</a>')
                    . '</td><td>' . esc_html($price['origin']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $error = isset($errors['prices_json'])
            ? '<p class="description" style="color:#b32d2e">' . esc_html((string) $errors['prices_json']) . '</p>'
            : '';
        $html .= '<h3>' . esc_html('Import') . '</h3><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::IMPORT_ACTION) . '">'
            . '<input type="hidden" name="network" value="' . esc_attr($network ? '1' : '0') . '">'
            . wp_nonce_field(self::IMPORT_ACTION, '_wpnonce', true, false)
            . '<p><textarea name="prices_json" rows="10" cols="80" class="large-text code"></textarea></p>' . $error
            . '<p><label><input type="radio" name="mode" value="merge" checked> ' . esc_html('Merge') . '</label> '
            . '<label><input type="radio" name="mode" value="replace"> ' . esc_html('Replace all overrides') . '</label> '
            . '<label><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html('Dry run (show the diff only)') . '</label></p>'
            . '<p><button type="submit" class="button">' . esc_html('Import prices') . '</button></p></form>';
        $html .= '<h3>' . esc_html('Export (overrides)') . '</h3><p><textarea readonly rows="8" cols="80" class="large-text code">'
            . esc_textarea($prices->export(true)) . '</textarea></p>';

        return $html;
    }
}
