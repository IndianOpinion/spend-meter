<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\PriceTable;
use IndianOpinion\SpendMeter\UsageParser;
use PHPUnit\Framework\TestCase;

/**
 * Usage parsing helper: section 4.3. Runs without WordPress.
 */
final class UsageParserTest extends TestCase
{
    public function testT29AllNormalisedKeys(): void
    {
        $out = UsageParser::parse(['usage' => ['input_tokens' => 3, 'output_tokens' => 4]]);
        $this->assertSame(
            ['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'provider_cost', 'usage_missing', 'notices'],
            array_keys($out)
        );
        $this->assertSame($out, spend_meter_usage_from_response(['usage' => ['input_tokens' => 3, 'output_tokens' => 4]]));
    }

    public function testT30BothShapes(): void
    {
        $first = UsageParser::parse(['usage' => [
            'input_tokens' => 100,
            'output_tokens' => 50,
            'input_tokens_details' => ['cached_tokens' => 40],
            'output_tokens_details' => ['reasoning_tokens' => 20],
        ]]);
        $second = UsageParser::parse(['usage' => [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'prompt_tokens_details' => ['cached_tokens' => 40],
            'completion_tokens_details' => ['reasoning_tokens' => 20],
        ]]);
        foreach ([$first, $second] as $out) {
            $this->assertSame([100, 40, 50, 20, false], [
                $out['input_tokens'], $out['cached_input_tokens'], $out['output_tokens'], $out['reasoning_tokens'], $out['usage_missing'],
            ]);
            $this->assertSame([], $out['notices']);
        }
        $mixed = UsageParser::parse(['usage' => ['input_tokens' => 7, 'prompt_tokens' => 9, 'completion_tokens' => 2]]);
        $this->assertSame(7, $mixed['input_tokens']);
        $this->assertSame(0, $mixed['output_tokens']);
        $this->assertStringContainsString('both usage shapes', $mixed['notices'][0]);
    }

    public function testT31SubsetsNotDoubleCountedAndPricedCorrectly(): void
    {
        $out = UsageParser::parse(['usage' => [
            'prompt_tokens' => 1000,
            'completion_tokens' => 100,
            'prompt_tokens_details' => ['cached_tokens' => 400],
            'completion_tokens_details' => ['reasoning_tokens' => 60],
        ]]);
        $this->assertSame(1000, $out['input_tokens'], 'cached is inside input, not added');
        $this->assertSame(100, $out['output_tokens'], 'reasoning is inside output, not added');
        $price = [
            'provider' => 'acme', 'model' => 'm', 'input' => 10000000000, 'cached_input' => 1000000000,
            'output' => 20000000000, 'reasoning' => null, 'unit' => null, 'unit_price' => null,
            'per_call_fee' => null, 'as_of' => null, 'source' => null, 'origin' => 'override', 'version' => 'x',
        ];
        $cost = PriceTable::cost($price, $out['input_tokens'], $out['cached_input_tokens'], $out['output_tokens'], $out['reasoning_tokens'], 0);
        // 600 * 10 + 400 * 1 + 100 * 20 (reasoning at the output price) = 8400 units per million.
        $this->assertSame(8400 * 1000, $cost);
        $clamped = UsageParser::parse(['usage' => ['input_tokens' => 5, 'output_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 9]]]);
        $this->assertSame(5, $clamped['cached_input_tokens']);
    }

    public function testT32UsageCost(): void
    {
        $this->assertSame(1234500, UsageParser::parse(['usage' => ['prompt_tokens' => 1, 'cost' => 0.0012345]])['provider_cost']);
        $this->assertSame(2000000000, UsageParser::parse(['usage' => ['cost' => 2]])['provider_cost']);
        $this->assertSame(1500000, UsageParser::parse(['usage' => ['cost' => '0.0015']])['provider_cost']);
        foreach ([-1, 'abc', null, true, [1]] as $bad) {
            $out = UsageParser::parse(['usage' => ['prompt_tokens' => 1, 'cost' => $bad]]);
            $this->assertNull($out['provider_cost']);
            $this->assertStringContainsString('usage.cost', $out['notices'][0]);
        }
    }

    public function testT33EmbeddingGivesZeroOutput(): void
    {
        $out = UsageParser::parse(['object' => 'list', 'usage' => ['prompt_tokens' => 12, 'total_tokens' => 12]]);
        $this->assertSame(12, $out['input_tokens']);
        $this->assertSame(0, $out['output_tokens']);
        $this->assertFalse($out['usage_missing']);
    }

    public function testT34MissingUsage(): void
    {
        foreach ([[], ['usage' => null], ['usage' => 'n/a'], ['usage' => []]] as $body) {
            $out = UsageParser::parse($body);
            $this->assertSame([0, 0, 0, 0, null, true], [
                $out['input_tokens'], $out['cached_input_tokens'], $out['output_tokens'], $out['reasoning_tokens'],
                $out['provider_cost'], $out['usage_missing'],
            ]);
        }
    }

    public function testT35ContentIgnored(): void
    {
        $body = [
            'id' => 'resp_1',
            'choices' => [['message' => ['content' => 'SECRET-TEXT']]],
            'output' => [['content' => [['text' => 'SECRET-TEXT']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'extra' => 'x'],
        ];
        $out = UsageParser::parse($body);
        $this->assertStringNotContainsString('SECRET-TEXT', (string) json_encode($out));
        $this->assertSame(['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'provider_cost', 'usage_missing', 'notices'], array_keys($out));
    }

    public function testBadCountsBecomeZeroWithNotice(): void
    {
        $out = UsageParser::parse(['usage' => ['input_tokens' => -3, 'output_tokens' => 'many']]);
        $this->assertSame(0, $out['input_tokens']);
        $this->assertCount(2, $out['notices']);
    }
}
