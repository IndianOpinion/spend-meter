<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * error_log lines prefixed "spend-meter:", rate-limited to one line per kind
 * per ten minutes. Debug lines are written only when WP_DEBUG is on.
 */
final class Logger
{
    public const WINDOW = 600;

    /** @var array<string, int> */
    private static array $last = [];

    /** @var (callable(string): void)|null */
    private static $sink = null;

    /**
     * Replace the output (tests). Pass null to restore error_log.
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setSink(?callable $sink): void
    {
        self::$sink = $sink;
        self::$last = [];
    }

    /**
     * Log a problem, at most once per kind per ten minutes across requests.
     */
    public static function error(string $kind, string $message): void
    {
        $now = time();
        if (isset(self::$last[$kind]) && $now - self::$last[$kind] < self::WINDOW) {
            return;
        }
        self::$last[$kind] = $now;
        $key = 'spend_meter_log_' . substr(md5($kind), 0, 12);
        if (function_exists('get_transient') && false !== get_transient($key)) {
            return;
        }
        if (function_exists('set_transient')) {
            set_transient($key, 1, self::WINDOW);
        }
        self::write($message);
    }

    /**
     * Log a debug line when WP_DEBUG is on. Not rate-limited.
     */
    public static function debug(string $message): void
    {
        if (defined('WP_DEBUG') && true === WP_DEBUG) {
            self::write($message);
        }
    }

    private static function write(string $message): void
    {
        $line = 'spend-meter: ' . $message;
        if (null !== self::$sink) {
            (self::$sink)($line);
            return;
        }
        error_log($line);
    }
}
