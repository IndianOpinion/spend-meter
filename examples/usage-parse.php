<?php

/**
 * Runnable without WordPress: php examples/usage-parse.php
 *
 * Normalises the two common usage shapes and prices them against an invented
 * price row. The numbers are made up for the example.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\PriceTable;
use IndianOpinion\SpendMeter\UsageParser;

$bodies = [
    'input/output shape' => ['usage' => [
        'input_tokens' => 1200,
        'output_tokens' => 300,
        'input_tokens_details' => ['cached_tokens' => 1000],
        'output_tokens_details' => ['reasoning_tokens' => 120],
    ]],
    'prompt/completion shape' => ['usage' => [
        'prompt_tokens' => 1200,
        'completion_tokens' => 300,
        'prompt_tokens_details' => ['cached_tokens' => 1000],
        'completion_tokens_details' => ['reasoning_tokens' => 120],
        'cost' => 0.0002,
    ]],
    'no usage' => ['choices' => []],
];

// Invented price per million tokens: input 1, cached 0.1, output 4.
$price = [
    'provider' => 'example-provider', 'model' => 'example-model',
    'input' => Money::decimalToNanos('1'), 'cached_input' => Money::decimalToNanos('0.1'),
    'output' => Money::decimalToNanos('4'), 'reasoning' => null,
    'unit' => null, 'unit_price' => null, 'per_call_fee' => null,
    'as_of' => null, 'source' => null, 'origin' => 'example', 'version' => 'example',
];

foreach ($bodies as $label => $body) {
    $usage = UsageParser::parse($body);
    $cost = PriceTable::cost(
        $price,
        $usage['input_tokens'],
        $usage['cached_input_tokens'],
        $usage['output_tokens'],
        $usage['reasoning_tokens'],
        0
    );
    printf(
        "%-24s in=%d cached=%d out=%d reasoning=%d missing=%s table=%s provider=%s\n",
        $label,
        $usage['input_tokens'],
        $usage['cached_input_tokens'],
        $usage['output_tokens'],
        $usage['reasoning_tokens'],
        $usage['usage_missing'] ? 'yes' : 'no',
        Money::nanosToDecimal($cost),
        null === $usage['provider_cost'] ? '-' : Money::nanosToDecimal($usage['provider_cost'])
    );
}
