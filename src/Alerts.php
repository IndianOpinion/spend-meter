<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Fires spend_meter_cap_warning and spend_meter_cap_reached at most once per
 * scope per window. State lives in the spend_meter_alert_state option, keyed
 * by kind, scope and window key, and is pruned after two windows.
 */
final class Alerts
{
    public function __construct(private Clock $clock)
    {
    }

    /**
     * Evaluate one cap and fire whichever actions are due.
     *
     * @param string $window "day" or "month".
     * @param bool   $denied True when this cap denied the call.
     */
    public function evaluate(
        string $scope,
        string $window,
        int $limit,
        int $total,
        string $windowKey,
        int $threshold,
        bool $denied = false
    ): void {
        $reached = $denied || (0 === $limit ? $total > 0 : $total >= $limit);
        $warn = $total > 0 && Money::mul($total, 100) >= Money::mul($limit, $threshold);
        if (!$warn && !$reached) {
            return;
        }
        $state = $this->state();
        $changed = false;
        foreach (['warning' => $warn || $reached, 'reached' => $reached] as $kind => $due) {
            $key = $kind . '|' . $scope . '|' . $windowKey;
            if (!$due || isset($state[$key])) {
                continue;
            }
            $state[$key] = $this->clock->timestamp();
            $changed = true;
            do_action('spend_meter_cap_' . $kind, $scope, $window, $limit, $total, $windowKey);
        }
        if ($changed) {
            Options::set(Options::ALERT_STATE, $this->prune($state));
        }
    }

    /**
     * @return array<string, int>
     */
    private function state(): array
    {
        $state = Options::get(Options::ALERT_STATE, []);

        return is_array($state) ? array_filter($state, 'is_int') : [];
    }

    /**
     * Keep entries for the current and previous window only.
     *
     * @param  array<string, int> $state
     * @return array<string, int>
     */
    public function prune(array $state): array
    {
        $local = $this->clock->local();
        $keepDays = [$local->format('Y-m-d'), $local->modify('-1 day')->format('Y-m-d')];
        $keepMonths = [$local->format('Y-m'), $local->modify('first day of last month')->format('Y-m')];
        $out = [];
        foreach ($state as $key => $time) {
            $windowKey = substr((string) strrchr($key, '|'), 1);
            if (in_array($windowKey, $keepDays, true) || in_array($windowKey, $keepMonths, true)) {
                $out[$key] = $time;
            }
        }

        return $out;
    }
}
