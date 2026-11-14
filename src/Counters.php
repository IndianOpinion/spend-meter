<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Denials per reason per day (last 14 days) and recording losses.
 */
final class Counters
{
    public function __construct(private Clock $clock)
    {
    }

    public function denial(string $reason): void
    {
        $data = Options::get(Options::DENIALS, []);
        $data = is_array($data) ? $data : [];
        $day = $this->clock->day();
        $today = is_array($data[$day] ?? null) ? $data[$day] : [];
        $today[$reason] = (int) ($today[$reason] ?? 0) + 1;
        $data[$day] = $today;
        krsort($data);
        Options::set(Options::DENIALS, array_slice($data, 0, 14, true));
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function denials(): array
    {
        $data = Options::get(Options::DENIALS, []);

        return is_array($data) ? $data : [];
    }

    public function loss(int $count = 1): void
    {
        $losses = $this->losses();
        Options::set(Options::LOSSES, [
            'count' => $losses['count'] + $count,
            'last' => $this->clock->timestamp(),
        ]);
    }

    /**
     * @return array{count: int, last: int|null}
     */
    public function losses(): array
    {
        $data = Options::get(Options::LOSSES, []);
        $data = is_array($data) ? $data : [];

        return [
            'count' => is_int($data['count'] ?? null) ? $data['count'] : 0,
            'last' => is_int($data['last'] ?? null) ? $data['last'] : null,
        ];
    }
}
