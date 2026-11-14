<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Caps per scope: site (per blog id), purpose (per blog id and slug or
 * prefix pattern) and network (multisite only). Each has an optional day
 * limit and an optional month limit in nanos. Null means no cap; zero
 * denies every call in the scope.
 *
 * Stored in one network-wide option:
 * { sites: { <blog_id>: { day, month, purposes: { <pattern>: { day, month } } } }, network: { day, month } }
 *
 * @phpstan-type Limit array{day: int|null, month: int|null}
 */
final class Caps
{
    /**
     * @return array{sites: array<int, array{day: int|null, month: int|null, purposes: array<string, Limit>}>, network: Limit}
     */
    public function all(): array
    {
        $raw = Options::get(Options::CAPS, []);
        if (!is_array($raw)) {
            Logger::error('caps_corrupt', 'caps option is corrupt; no caps apply until it is saved again');
            $raw = [];
        }
        $out = ['sites' => [], 'network' => self::limit($raw['network'] ?? null)];
        foreach ((array) ($raw['sites'] ?? []) as $blogId => $site) {
            if (!is_int($blogId) && !ctype_digit((string) $blogId)) {
                continue;
            }
            $site = is_array($site) ? $site : [];
            $purposes = [];
            foreach ((array) ($site['purposes'] ?? []) as $pattern => $limit) {
                if (Pattern::isPattern($pattern)) {
                    $purposes[(string) $pattern] = self::limit($limit);
                }
            }
            $out['sites'][(int) $blogId] = self::limit($site) + ['purposes' => $purposes];
        }

        return $out;
    }

    /**
     * @return Limit
     */
    public function site(int $blogId): array
    {
        $site = $this->all()['sites'][$blogId] ?? null;

        return null === $site ? ['day' => null, 'month' => null] : ['day' => $site['day'], 'month' => $site['month']];
    }

    /**
     * Every purpose cap whose pattern matches the purpose.
     *
     * @return array<string, Limit>
     */
    public function purposes(int $blogId, ?string $purpose = null): array
    {
        $all = $this->all()['sites'][$blogId]['purposes'] ?? [];
        if (null === $purpose) {
            return $all;
        }

        return array_intersect_key($all, array_flip(Pattern::matching(array_keys($all), $purpose)));
    }

    /**
     * Network caps; always empty on a single site.
     *
     * @return Limit
     */
    public function network(): array
    {
        if (!is_multisite()) {
            return ['day' => null, 'month' => null];
        }

        return $this->all()['network'];
    }

    /**
     * True when any cap is set for the blog (site or any purpose) or the network.
     */
    public function anySet(int $blogId): bool
    {
        $isSet = static fn (array $limit): bool => null !== $limit['day'] || null !== $limit['month'];
        if ($isSet($this->site($blogId)) || $isSet($this->network())) {
            return true;
        }
        foreach ($this->purposes($blogId) as $limit) {
            if ($isSet($limit)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set a cap. Only the windows given change; pass null to leave a window
     * as it is. Use unset() to remove.
     *
     * @throws ValidationException
     */
    public function set(string $scope, int $blogId, ?string $purpose, ?int $day, ?int $month): void
    {
        $this->change($scope, $blogId, $purpose, static function (array $limit) use ($day, $month): array {
            if (null !== $day) {
                $limit['day'] = $day;
            }
            if (null !== $month) {
                $limit['month'] = $month;
            }
            return $limit;
        });
    }

    /**
     * Remove a cap window, or both when neither is named.
     *
     * @throws ValidationException
     */
    public function unset(string $scope, int $blogId, ?string $purpose, bool $day = true, bool $month = true): void
    {
        $this->change($scope, $blogId, $purpose, static function (array $limit) use ($day, $month): array {
            if ($day) {
                $limit['day'] = null;
            }
            if ($month) {
                $limit['month'] = null;
            }
            return $limit;
        });
    }

    /**
     * Replace every purpose cap of a blog (settings screen).
     *
     * @param array<string, Limit> $purposes
     */
    public function replacePurposes(int $blogId, array $purposes): void
    {
        $all = $this->all();
        $site = $all['sites'][$blogId] ?? ['day' => null, 'month' => null, 'purposes' => []];
        $site['purposes'] = $purposes;
        $all['sites'][$blogId] = $site;
        $this->save($all);
    }

    /**
     * @param callable(Limit): Limit $mutate
     * @throws ValidationException
     */
    private function change(string $scope, int $blogId, ?string $purpose, callable $mutate): void
    {
        $all = $this->all();
        switch ($scope) {
            case 'site':
                $site = $all['sites'][$blogId] ?? ['day' => null, 'month' => null, 'purposes' => []];
                $limit = $mutate(['day' => $site['day'], 'month' => $site['month']]);
                $all['sites'][$blogId] = $limit + ['purposes' => $site['purposes']];
                break;
            case 'purpose':
                if (null === $purpose || !Pattern::isPattern($purpose)) {
                    throw new ValidationException('purpose', 'a purpose slug or prefix pattern is required');
                }
                $site = $all['sites'][$blogId] ?? ['day' => null, 'month' => null, 'purposes' => []];
                $limit = $mutate($site['purposes'][$purpose] ?? ['day' => null, 'month' => null]);
                if (null === $limit['day'] && null === $limit['month']) {
                    unset($site['purposes'][$purpose]);
                } else {
                    $site['purposes'][$purpose] = $limit;
                }
                $all['sites'][$blogId] = $site;
                break;
            case 'network':
                if (!is_multisite()) {
                    throw new ValidationException('scope', 'network caps exist only on multisite');
                }
                $all['network'] = $mutate($all['network']);
                break;
            default:
                throw new ValidationException('scope', 'scope must be site, purpose or network');
        }
        $this->save($all);
    }

    /**
     * @param array{sites: array<int, array{day: int|null, month: int|null, purposes: array<string, Limit>}>, network: Limit} $all
     */
    private function save(array $all): void
    {
        foreach ($all['sites'] as $blogId => $site) {
            if (null === $site['day'] && null === $site['month'] && [] === $site['purposes']) {
                unset($all['sites'][$blogId]);
            }
        }
        Options::set(Options::CAPS, $all);
    }

    /**
     * @return Limit
     */
    private static function limit(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $one = static fn (mixed $v): ?int => is_int($v) && $v >= 0 ? $v : null;

        return ['day' => $one($raw['day'] ?? null), 'month' => $one($raw['month'] ?? null)];
    }
}
