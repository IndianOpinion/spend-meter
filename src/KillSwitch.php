<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * The global kill switch and the per-purpose kill list.
 *
 * The constant SPEND_METER_KILL holds the global switch on; the constant
 * SPEND_METER_KILL_PURPOSES (comma-separated) adds purpose entries. Neither
 * can be undone from the screen or the CLI. Entries are exact slugs or a
 * trailing "*" prefix pattern.
 */
final class KillSwitch
{
    public static function constantOn(): bool
    {
        if (!defined('SPEND_METER_KILL')) {
            return false;
        }
        $value = constant('SPEND_METER_KILL');

        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * Valid purpose entries from SPEND_METER_KILL_PURPOSES.
     *
     * @return list<string>
     */
    public static function constantPurposes(): array
    {
        if (!defined('SPEND_METER_KILL_PURPOSES')) {
            return [];
        }
        $raw = constant('SPEND_METER_KILL_PURPOSES');
        if (!is_string($raw)) {
            Logger::error('kill_constant', 'SPEND_METER_KILL_PURPOSES must be a comma-separated string');
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = strtolower(trim($entry));
            if ('' === $entry) {
                continue;
            }
            if (!Pattern::isPattern($entry)) {
                Logger::error('kill_constant', 'SPEND_METER_KILL_PURPOSES has an invalid entry; ignored');
                continue;
            }
            $out[] = $entry;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{global: bool, purposes: list<string>}
     */
    public function stored(): array
    {
        $raw = Options::get(Options::KILL, []);
        if (!is_array($raw)) {
            Logger::error('kill_corrupt', 'kill switch option is corrupt; treated as off (constants still apply)');
            $raw = [];
        }
        $purposes = [];
        foreach ((array) ($raw['purposes'] ?? []) as $entry) {
            if (Pattern::isPattern($entry)) {
                $purposes[] = (string) $entry;
            }
        }

        return ['global' => true === ($raw['global'] ?? false), 'purposes' => array_values(array_unique($purposes))];
    }

    public function globalOn(): bool
    {
        return self::constantOn() || $this->stored()['global'];
    }

    /**
     * Every purpose entry in force: constant entries first, then stored ones.
     *
     * @return list<string>
     */
    public function purposes(): array
    {
        return array_values(array_unique(array_merge(self::constantPurposes(), $this->stored()['purposes'])));
    }

    /**
     * The first purpose entry matching the slug, or null.
     */
    public function purposeMatch(string $purpose): ?string
    {
        return Pattern::matching($this->purposes(), $purpose)[0] ?? null;
    }

    public function active(?string $purpose = null): bool
    {
        if ($this->globalOn()) {
            return true;
        }

        return null !== $purpose && null !== $this->purposeMatch($purpose);
    }

    /**
     * Turn the stored global switch on or off.
     *
     * @throws ValidationException When turning off while the constant holds it on.
     */
    public function setGlobal(bool $on, string $actor): void
    {
        if (!$on && self::constantOn()) {
            throw new ValidationException(
                'kill',
                'The kill switch is held on by the SPEND_METER_KILL constant; remove it from wp-config.php to turn it off.'
            );
        }
        $stored = $this->stored();
        $old = $stored['global'];
        if ($old === $on) {
            return;
        }
        $stored['global'] = $on;
        Options::set(Options::KILL, $stored);
        do_action('spend_meter_kill_switch_changed', 'global', $old, $on, $actor);
    }

    /**
     * Add or remove a stored purpose entry.
     *
     * @throws ValidationException On a bad pattern or a constant-held entry.
     */
    public function setPurpose(string $pattern, bool $on, string $actor): void
    {
        $pattern = strtolower(trim($pattern));
        if (!Pattern::isPattern($pattern)) {
            throw new ValidationException('purpose', 'purpose must be a slug, optionally ending in *');
        }
        if (!$on && in_array($pattern, self::constantPurposes(), true)) {
            throw new ValidationException(
                'purpose',
                sprintf('%s is held by the SPEND_METER_KILL_PURPOSES constant and cannot be removed here.', $pattern)
            );
        }
        $stored = $this->stored();
        $old = in_array($pattern, $stored['purposes'], true);
        if ($old === $on) {
            return;
        }
        $stored['purposes'] = $on
            ? array_values(array_merge($stored['purposes'], [$pattern]))
            : array_values(array_diff($stored['purposes'], [$pattern]));
        Options::set(Options::KILL, $stored);
        do_action('spend_meter_kill_switch_changed', 'purpose:' . $pattern, $old, $on, $actor);
    }

    /**
     * Replace the stored purpose list (settings screen). Fires one change
     * action per entry added or removed.
     *
     * @param  list<string> $patterns
     * @throws ValidationException
     */
    public function replacePurposes(array $patterns, string $actor): void
    {
        $patterns = array_values(array_unique(array_map(static fn (string $p): string => strtolower(trim($p)), $patterns)));
        foreach ($patterns as $pattern) {
            if (!Pattern::isPattern($pattern)) {
                throw new ValidationException('kill_purposes', sprintf('"%s" is not a slug or prefix pattern', $pattern));
            }
        }
        $current = $this->stored()['purposes'];
        foreach (array_diff($current, $patterns) as $removed) {
            if (!in_array($removed, self::constantPurposes(), true)) {
                $this->setPurpose($removed, false, $actor);
            }
        }
        foreach (array_diff($patterns, $current) as $added) {
            $this->setPurpose($added, true, $actor);
        }
    }
}
