<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Decision;
use IndianOpinion\SpendMeter\Tests\TestCase;
use WpStub;

/**
 * The public contract, seen from a second plugin: sections 5.1 to 5.4.
 */
final class ContractTest extends TestCase
{
    public function testT2WithThePluginAbsentTheFilterReturnsNull(): void
    {
        WpStub::$hooks = [];
        $this->assertNull(apply_filters('spend_meter_pre_spend', null, $this->intent()));
        // Record and release become no-ops for the caller, never errors.
        do_action('spend_meter_record', ['status' => 'ok']);
        do_action('spend_meter_release', 'x');
        $this->assertSame([], $this->store->all());
    }

    public function testT76ConstantHooksAndDecisionShape(): void
    {
        $this->assertTrue(defined('SPEND_METER_API'));
        $this->assertSame(1, SPEND_METER_API);
        $this->assertSame('0.1.0', SPEND_METER_VERSION);
        foreach (['spend_meter_pre_spend', 'spend_meter_record', 'spend_meter_release'] as $hook) {
            $this->assertTrue(has_filter($hook), $hook);
        }
        foreach (
            [
            'spend_meter_check', 'spend_meter_record', 'spend_meter_release', 'spend_meter_usage_from_response',
            'spend_meter_estimate', 'spend_meter_totals', 'spend_meter_format', 'spend_meter_kill_active',
            ] as $function
        ) {
            $this->assertTrue(function_exists($function), $function);
        }
        $this->plugin->kill()->setGlobal(true, 'cli');
        $deny = spend_meter_check($this->intent());
        $this->assertSame(['allowed', 'reason', 'message', 'reservation_id', 'estimate', 'remaining', 'api'], array_keys($deny));
        $this->assertSame(1, $deny['api']);
        $this->assertContains($deny['reason'], Decision::REASONS);
        $this->assertTrue(spend_meter_kill_active());
    }

    public function testT76EveryDenyCarriesEveryKey(): void
    {
        $this->setCaps('0');
        foreach ([[], ['purpose' => 'BAD'], ['model' => 'x']] as $change) {
            $d = spend_meter_check($this->intent($change));
            $this->assertFalse($d['allowed']);
            $this->assertNull($d['reservation_id']);
            $this->assertIsInt($d['estimate']);
            $this->assertSame(7, count($d));
        }
    }

    public function testT77SecondPluginUsingOnlyHooks(): void
    {
        $this->price('acme', 'text-1', '2', '8');
        $events = [];
        add_action('spend_meter_cap_reached', static function (string $scope) use (&$events): void {
            $events[] = 'reached:' . $scope;
        });
        add_action('spend_meter_kill_switch_changed', static function (string $scope, bool $old, bool $new) use (&$events): void {
            $events[] = 'kill:' . $scope . ':' . ($new ? 'on' : 'off');
        }, 10, 3);

        $caller = static function (): string {
            // What a consuming plugin does, written only against the hooks.
            $decision = apply_filters('spend_meter_pre_spend', null, [
                'purpose' => 'summary', 'caller' => 'other-plugin', 'provider' => 'acme', 'model' => 'text-1',
                'est_input_tokens' => 1000, 'max_output_tokens' => 500,
            ]);
            if (null === $decision) {
                return 'own-guards';
            }
            if (true !== $decision['allowed']) {
                return 'refused:' . $decision['reason'];
            }
            do_action('spend_meter_record', [
                'reservation_id' => $decision['reservation_id'],
                'status' => 'ok',
                'input_tokens' => 1000,
                'output_tokens' => 400,
            ]);
            return 'spent';
        };

        $this->assertSame('spent', $caller());
        $this->setCaps('0.008');
        $this->assertSame('refused:cap_site_day', $caller());
        $this->plugin->kill()->setGlobal(true, 'cli');
        $this->assertSame('refused:kill_switch', $caller());
        $this->assertSame(['reached:site:1', 'kill:global:on'], $events);

        // Release path.
        $this->plugin->kill()->setGlobal(false, 'cli');
        $this->plugin->caps()->unset('site', 1, null);
        $decision = apply_filters('spend_meter_pre_spend', null, $this->intent());
        do_action('spend_meter_release', $decision['reservation_id']);
        $this->assertSame('released', $this->store->findByReservationId((string) $decision['reservation_id'])['status']);

        // Absent.
        WpStub::$hooks = [];
        $this->assertSame('own-guards', $caller());
    }

    public function testT76UnknownReasonCodesAreDenies(): void
    {
        // The documented rule for callers: anything but allowed === true is a deny.
        $future = ['allowed' => false, 'reason' => 'cap_galaxy_week'];
        $this->assertFalse(true === $future['allowed']);
        $this->assertNotContains('cap_galaxy_week', Decision::REASONS);
    }
}
