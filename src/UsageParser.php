<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter;

/**
 * Normalises the usage block of a decoded provider response.
 *
 * Two shapes are understood: input_tokens/output_tokens with
 * input_tokens_details.cached_tokens and output_tokens_details.reasoning_tokens,
 * and prompt_tokens/completion_tokens with prompt_tokens_details.cached_tokens
 * and completion_tokens_details.reasoning_tokens. Cached and reasoning tokens
 * are subsets of input and output and are never added on top. The parser
 * reads only the usage block, never message content.
 */
final class UsageParser
{
    /**
     * @param  array<mixed> $body Decoded response body.
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, reasoning_tokens: int,
     *     provider_cost: int|null, usage_missing: bool, notices: list<string>}
     */
    public static function parse(array $body): array
    {
        $out = [
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'provider_cost' => null,
            'usage_missing' => true,
            'notices' => [],
        ];
        $usage = $body['usage'] ?? null;
        if (!is_array($usage)) {
            return $out;
        }
        $hasFirst = array_key_exists('input_tokens', $usage) || array_key_exists('output_tokens', $usage);
        $hasSecond = array_key_exists('prompt_tokens', $usage) || array_key_exists('completion_tokens', $usage);
        if ($hasFirst) {
            $keys = ['input_tokens', 'output_tokens', 'input_tokens_details', 'output_tokens_details'];
            if ($hasSecond) {
                $out['notices'][] = 'both usage shapes present; input_tokens/output_tokens used';
            }
        } elseif ($hasSecond) {
            $keys = ['prompt_tokens', 'completion_tokens', 'prompt_tokens_details', 'completion_tokens_details'];
        } else {
            $keys = null;
        }
        if (null !== $keys) {
            $out['usage_missing'] = false;
            $out['input_tokens'] = self::count($usage[$keys[0]] ?? null, $keys[0], $out['notices']);
            $out['output_tokens'] = self::count($usage[$keys[1]] ?? null, $keys[1], $out['notices']);
            $inDetails = $usage[$keys[2]] ?? null;
            $outDetails = $usage[$keys[3]] ?? null;
            $cached = is_array($inDetails)
                ? self::count($inDetails['cached_tokens'] ?? null, 'cached_tokens', $out['notices'])
                : 0;
            $reasoning = is_array($outDetails)
                ? self::count($outDetails['reasoning_tokens'] ?? null, 'reasoning_tokens', $out['notices'])
                : 0;
            if ($cached > $out['input_tokens']) {
                $out['notices'][] = 'cached_tokens larger than input tokens; clamped';
                $cached = $out['input_tokens'];
            }
            if ($reasoning > $out['output_tokens']) {
                $out['notices'][] = 'reasoning_tokens larger than output tokens; clamped';
                $reasoning = $out['output_tokens'];
            }
            $out['cached_input_tokens'] = $cached;
            $out['reasoning_tokens'] = $reasoning;
        }
        if (array_key_exists('cost', $usage)) {
            $out['provider_cost'] = self::cost($usage['cost'], $out['notices']);
            if (null !== $out['provider_cost']) {
                $out['usage_missing'] = false;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $notices
     */
    private static function count(mixed $value, string $key, array &$notices): int
    {
        if (null === $value) {
            return 0;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value >= 0 && $value < 1e15 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && 1 === preg_match('/^\d{1,15}$/', $value)) {
            return (int) $value;
        }
        $notices[] = sprintf('%s is not a non-negative integer; ignored', $key);

        return 0;
    }

    /**
     * @param list<string> $notices
     */
    private static function cost(mixed $value, array &$notices): ?int
    {
        if (is_int($value) && $value >= 0) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value) && $value >= 0) {
            $value = rtrim(rtrim(sprintf('%.9F', $value), '0'), '.');
        } elseif (is_string($value) && 1 === preg_match('/^\d+(\.\d+)?$/', $value)) {
            // A numeric string with more than nine places is cut to nine:
            // nanos are the ledger resolution.
            if (str_contains($value, '.')) {
                [$whole, $fraction] = explode('.', $value, 2);
                $value = $whole . '.' . substr($fraction, 0, 9);
            }
        } else {
            $notices[] = 'usage.cost is not a non-negative number; ignored';
            return null;
        }
        try {
            return Money::decimalToNanos($value, 'usage.cost');
        } catch (ValidationException) {
            $notices[] = 'usage.cost is out of range; ignored';
            return null;
        }
    }
}
