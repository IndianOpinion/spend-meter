<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Admin;

/**
 * Capability and nonce checks shared by the screens and the widget.
 */
final class Access
{
    public static function capability(bool $network = false): string
    {
        if ($network) {
            return 'manage_network_options';
        }
        $cap = apply_filters('spend_meter_capability', 'manage_options');

        return is_string($cap) && '' !== $cap ? $cap : 'manage_options';
    }

    public static function allowed(bool $network = false): bool
    {
        return current_user_can(self::capability($network));
    }

    /**
     * @param array<mixed> $request
     */
    public static function nonceOk(array $request, string $action): bool
    {
        $nonce = $request['_wpnonce'] ?? null;

        return is_string($nonce) && false !== wp_verify_nonce($nonce, $action);
    }
}
