<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Admin\SettingsPage;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Tests\TestCase;
use WpStub;

/**
 * Settings screen: section 4.9.
 */
final class SettingsPageTest extends TestCase
{
    /**
     * @param  array<string, string> $fields
     * @return array<string, string>
     */
    private function post(array $fields = []): array
    {
        return $fields + [
            '_wpnonce' => 'nonce-' . SettingsPage::SAVE_ACTION,
            'kill_global' => '',
            'kill_purposes' => '',
            'warning_threshold' => '80',
            'default_output_tokens' => '4096',
            'reservation_minutes' => '15',
            'currency' => 'USD',
            'stale_days' => '120',
            'retention_months' => '0',
            'site_cap_day' => '',
            'site_cap_month' => '',
            'purpose_caps' => '',
        ];
    }

    public function testT65SaveCoversEveryField(): void
    {
        $result = SettingsPage::process($this->post([
            'kill_global' => '1',
            'kill_purposes' => "shadow*\ntranslate",
            'warning_threshold' => '70',
            'allow_unpriced' => '1',
            'default_output_tokens' => '2048',
            'reservation_minutes' => '30',
            'currency' => 'eur',
            'stale_days' => '90',
            'retention_months' => '12',
            'keep_ledger' => '1',
            'site_cap_day' => '5',
            'site_cap_month' => '100.50',
            'purpose_caps' => "summary 1 -\nshadow* - 3",
        ]), false);
        $this->assertTrue($result['ok'], (string) json_encode($result));
        $this->refresh();
        $this->assertSame([
            'warning_threshold' => 70,
            'allow_unpriced' => true,
            'default_output_tokens' => 2048,
            'reservation_minutes' => 30,
            'currency' => 'EUR',
            'stale_days' => 90,
            'retention_months' => 12,
            'keep_ledger' => true,
        ], $this->plugin->settings()->all());
        $this->assertTrue($this->plugin->kill()->globalOn());
        $this->assertSame(['shadow*', 'translate'], $this->plugin->kill()->purposes());
        $this->assertSame(['day' => self::n('5'), 'month' => self::n('100.5')], $this->plugin->caps()->site(1));
        $this->assertSame([
            'summary' => ['day' => self::n('1'), 'month' => null],
            'shadow*' => ['day' => null, 'month' => self::n('3')],
        ], $this->plugin->caps()->purposes(1));
        $this->assertTrue(WpStub::$autoload[Options::SETTINGS]);
        $this->assertFalse(WpStub::$autoload[Options::CAPS]);
        // Unchecking boxes turns them off.
        SettingsPage::process($this->post(['currency' => 'EUR']), false);
        $this->refresh();
        $this->assertFalse($this->plugin->settings()->all()['allow_unpriced']);
        $this->assertFalse($this->plugin->kill()->globalOn());
        $this->assertSame([], $this->plugin->kill()->purposes());
    }

    public function testT66BadNonceOrCapabilityRejected(): void
    {
        $result = SettingsPage::process($this->post(['_wpnonce' => 'forged', 'kill_global' => '1']), false);
        $this->assertTrue($result['forbidden'] ?? false);
        WpStub::$caps = [];
        $result = SettingsPage::process($this->post(['kill_global' => '1']), false);
        $this->assertTrue($result['forbidden'] ?? false);
        $this->assertFalse($this->plugin->kill()->globalOn());
        $this->assertSame('', SettingsPage::html($this->plugin, false));
    }

    public function testT66InvalidFieldSavesNothing(): void
    {
        $result = SettingsPage::process($this->post([
            'kill_global' => '1',
            'site_cap_day' => '5',
            'warning_threshold' => '150',
            'purpose_caps' => 'Bad Purpose 1 1',
        ]), false);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('warning_threshold', $result['errors']);
        $this->assertArrayHasKey('purpose_caps', $result['errors']);
        $this->assertFalse($this->plugin->kill()->globalOn());
        $this->assertSame(['day' => null, 'month' => null], $this->plugin->caps()->site(1));
        $this->assertFalse(Options::get(Options::SETTINGS, false));
        // Errors are shown next to the field after the redirect.
        set_transient('spend_meter_flash_1', $result, 300);
        $html = SettingsPage::html($this->plugin, false);
        $this->assertStringContainsString('Warning threshold must be a whole number from 1 to 99.', $html);
    }

    public function testT67CurrencyChangeRefusedOnceRowsExist(): void
    {
        $this->record(['purpose' => 'a', 'caller' => 'b', 'provider' => 'acme', 'model' => 'm']);
        $result = SettingsPage::process($this->post(['currency' => 'GBP']), false);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('currency', $result['errors']);
        $this->refresh();
        $this->assertSame('USD', $this->plugin->settings()->all()['currency']);
    }

    public function testImportFromScreen(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/import-good.json');
        $post = ['_wpnonce' => 'nonce-' . SettingsPage::IMPORT_ACTION, 'prices_json' => $json, 'mode' => 'merge', 'dry_run' => '1'];
        $result = SettingsPage::processImport($post, false);
        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Dry run', $result['notice']);
        $this->assertTrue($this->plugin->prices()->isEmpty());
        unset($post['dry_run']);
        $this->assertTrue(SettingsPage::processImport($post, false)['ok']);
        $this->assertFalse($this->plugin->prices()->isEmpty());
        $bad = SettingsPage::processImport(['prices_json' => '{"schema":2}'] + $post, false);
        $this->assertArrayHasKey('prices_json', $bad['errors']);
        $this->assertTrue(SettingsPage::processImport(['_wpnonce' => 'x'] + $post, false)['forbidden'] ?? false);
    }

    public function testMultisiteSplitsSiteAndNetworkScreens(): void
    {
        WpStub::$multisite = true;
        $this->refresh();
        $result = SettingsPage::process($this->post(['site_cap_day' => '2', 'warning_threshold' => 'garbage']), false);
        $this->assertTrue($result['ok'], 'global fields are not on the site screen');
        $this->assertSame(self::n('2'), $this->plugin->caps()->site(1)['day']);
        $result = SettingsPage::process($this->post(['network_cap_day' => '9', 'network' => '1']), true);
        $this->assertTrue($result['ok']);
        $this->assertSame(self::n('9'), $this->plugin->caps()->network()['day']);
        $html = SettingsPage::html($this->plugin, true);
        $this->assertStringContainsString('network_cap_day', $html);
        $this->assertStringNotContainsString('site_cap_day', $html);
        SettingsPage::networkMenu();
        $this->assertContains('settings.php:spend-meter:manage_network_options', WpStub::$menus);
    }

    public function testHtmlIsEscaped(): void
    {
        $this->plugin->kill()->setPurpose('x', true, 'cli');
        $html = SettingsPage::html($this->plugin, false);
        $this->assertStringContainsString('name="_wpnonce" value="nonce-spend_meter_save"', $html);
        $this->assertStringNotContainsString('<script', $html);
    }
}
