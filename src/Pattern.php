<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Slugs and the one pattern syntax spend-meter accepts: an exact slug, or a
 * slug followed by a single trailing "*" meaning "starts with".
 */
final class Pattern
{
    public const SLUG = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';
    public const PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,63}\*?$/';

    public static function isSlug(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match(self::SLUG, $value);
    }

    public static function isPattern(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match(self::PATTERN, $value);
    }

    public static function isPrefix(string $pattern): bool
    {
        return str_ends_with($pattern, '*');
    }

    /**
     * True when the slug equals the pattern, or the pattern ends in "*" and
     * the slug starts with what comes before it.
     */
    public static function matches(string $pattern, string $slug): bool
    {
        if (self::isPrefix($pattern)) {
            return str_starts_with($slug, substr($pattern, 0, -1));
        }

        return $pattern === $slug;
    }

    /**
     * Every pattern in the list that matches the slug, in list order.
     *
     * @param  iterable<string> $patterns
     * @return list<string>
     */
    public static function matching(iterable $patterns, string $slug): array
    {
        $out = [];
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $slug)) {
                $out[] = $pattern;
            }
        }

        return $out;
    }
}
