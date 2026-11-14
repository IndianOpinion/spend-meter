<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Cron;
use IndianOpinion\SpendMeter\Options;
use IndianOpinion\SpendMeter\Plugin;
use IndianOpinion\SpendMeter\Tests\TestCase;
use IndianOpinion\SpendMeter\Uninstaller;
use WpStub;

/**
 * Uninstall and deactivation: section 6.
 */
final class UninstallTest extends TestCase
{
    public function testT75UninstallRemovesOptionsAndTable(): void
    {
        $this->price('acme', 'text-1', '2', '8');
        $this->setCaps('1');
        $this->plugin->kill()->setPurpose('x', true, 'cli');
        $this->check();
        $this->plugin->widget()->summary(1);
        Cron::schedule();
        $this->assertNotEmpty(WpStub::$cron);
        Uninstaller::run($this->plugin);
        foreach (Options::all() as $name) {
            $this->assertArrayNotHasKey($name, WpStub::$options, $name);
        }
        $this->assertSame([], WpStub::$cron);
        $this->assertSame([], array_filter(array_keys(WpStub::$transients), static fn (string $k): bool => str_contains($k, 'spend_meter_summary')));
        $this->assertTrue($this->store->dropped);
    }

    public function testT75KeepsTheTableWhenAsked(): void
    {
        $this->plugin->settings()->save(['keep_ledger' => true], false);
        $this->refresh();
        Uninstaller::run($this->plugin);
        $this->assertFalse($this->store->dropped);
        $this->assertArrayNotHasKey(Options::SETTINGS, WpStub::$options);
    }

    public function testDeactivationRemovesCronOnly(): void
    {
        Plugin::activate();
        $this->assertArrayHasKey(Cron::HOURLY, WpStub::$cron);
        $this->assertArrayHasKey(Cron::DAILY, WpStub::$cron);
        do_action('deactivate_spend-meter');
        $this->assertSame([], WpStub::$cron);
        $this->assertArrayHasKey(Options::DB_VERSION, WpStub::$options);
        $this->assertFalse($this->store->dropped);
    }

    public function testMultisiteUninstallRemovesNetworkOptions(): void
    {
        WpStub::$multisite = true;
        WpStub::$sites = [1, 2];
        $this->refresh();
        Options::set(Options::CAPS, ['network' => ['day' => 1, 'month' => null]]);
        set_site_transient('spend_meter_summary_2', ['x'], 60);
        Uninstaller::run($this->plugin);
        $this->assertSame([], WpStub::$siteOptions);
        $this->assertFalse(get_site_transient('spend_meter_summary_2'));
    }
}
