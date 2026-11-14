<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use CliExit;
use IndianOpinion\SpendMeter\Admin\SettingsPage;
use IndianOpinion\SpendMeter\Cli\Command;
use IndianOpinion\SpendMeter\Tests\TestCase;
use IndianOpinion\SpendMeter\Uninstaller;
use IndianOpinion\SpendMeter\ValidationException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_CLI;

/**
 * Constants cannot be undefined, so each test runs in its own process.
 */
final class ConstantsTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT42ConstantKillWinsOverOptionAndScreenShowsLocked(): void
    {
        define('SPEND_METER_KILL', true);
        $this->assertFalse($this->plugin->kill()->stored()['global']);
        $this->assertSame('kill_switch', $this->check()['reason']);
        $html = SettingsPage::html($this->plugin, false);
        $this->assertStringContainsString('Locked on by the SPEND_METER_KILL constant.', $html);
        $this->assertStringContainsString('id="kill_global" value="1" checked disabled', $html);
        // A save with the box unticked (a disabled box posts nothing) does not fail.
        $result = SettingsPage::process([
            '_wpnonce' => 'nonce-spend_meter_save', 'warning_threshold' => '80', 'default_output_tokens' => '4096',
            'reservation_minutes' => '15', 'currency' => 'USD', 'stale_days' => '120', 'retention_months' => '0',
        ], false);
        $this->assertTrue($result['ok']);
        $this->assertTrue($this->plugin->kill()->globalOn());
        $this->expectException(ValidationException::class);
        $this->plugin->kill()->setGlobal(false, 'cli');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT43PurposeKillFromConstantCannotBeRemoved(): void
    {
        define('SPEND_METER_KILL_PURPOSES', 'Translate, shadow* ,bad slug!');
        $this->assertSame(['translate', 'shadow*'], $this->plugin->kill()->purposes());
        $this->assertSame('kill_switch_purpose', $this->check(['purpose' => 'translate'])['reason']);
        $this->assertSame('kill_switch_purpose', $this->check(['purpose' => 'shadow-a'])['reason']);
        $this->plugin->kill()->replacePurposes([], '1');
        $this->assertSame(['translate', 'shadow*'], $this->plugin->kill()->purposes());
        $this->expectException(ValidationException::class);
        $this->plugin->kill()->setPurpose('translate', false, 'cli');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT69KillOffRefusedUnderTheConstant(): void
    {
        define('SPEND_METER_KILL', true);
        $cli = new Command($this->plugin);
        WP_CLI::reset();
        try {
            $cli->kill(['off'], []);
            $this->fail('kill off must be refused');
        } catch (CliExit $e) {
            $this->assertSame(1, $e->getCode());
            $this->assertStringContainsString('held on by the SPEND_METER_KILL constant', WP_CLI::text());
        }
        $this->assertTrue($this->plugin->kill()->globalOn());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testT75KeepLedgerConstant(): void
    {
        define('SPEND_METER_KEEP_LEDGER', true);
        Uninstaller::run($this->plugin);
        $this->assertFalse($this->store->dropped);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWidgetDisabledByConstant(): void
    {
        define('SPEND_METER_DISABLE_WIDGET', true);
        \IndianOpinion\SpendMeter\Admin\Widget::setup();
        $this->assertSame([], \WpStub::$widgets);
    }
}
