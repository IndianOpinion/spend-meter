<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time source. Rows store UTC timestamps plus the site-local day and month
 * at write time. Tests pass a fixed timestamp.
 */
class Clock
{
    public function __construct(private ?int $fixed = null)
    {
    }

    public function set(int $timestamp): void
    {
        $this->fixed = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->fixed = $this->timestamp() + $seconds;
    }

    public function timestamp(): int
    {
        return $this->fixed ?? time();
    }

    public function timezone(): DateTimeZone
    {
        return function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    }

    /**
     * UTC "Y-m-d H:i:s", optionally shifted by a number of seconds.
     */
    public function utc(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', $this->timestamp() + $offset);
    }

    public function local(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp()))->setTimezone($this->timezone());
    }

    /** Site-local day key, YYYY-MM-DD. */
    public function day(): string
    {
        return $this->local()->format('Y-m-d');
    }

    /** Site-local month key, YYYY-MM. */
    public function month(): string
    {
        return $this->local()->format('Y-m');
    }
}
