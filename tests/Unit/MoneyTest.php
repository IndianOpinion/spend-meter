<?php

declare(strict_types=1);

namespace IndianOpinion\SpendMeter\Tests\Unit;

use IndianOpinion\SpendMeter\Money;
use IndianOpinion\SpendMeter\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Integer money arithmetic. Runs without WordPress.
 */
final class MoneyTest extends TestCase
{
    public function testDecimalConversion(): void
    {
        $this->assertSame(1500000000, Money::decimalToNanos('1.5'));
        $this->assertSame(1, Money::decimalToNanos('0.000000001'));
        $this->assertSame('1.5', Money::nanosToDecimal(1500000000));
        $this->assertSame('0', Money::nanosToDecimal(0));
        $this->assertSame('0.000000001', Money::nanosToDecimal(1));
    }

    public function testRejectsBadDecimals(): void
    {
        foreach (['', '-1', '1.0000000001', '1e3', ' 1', '<decimal>', '1,5', '1234567890'] as $bad) {
            try {
                Money::decimalToNanos($bad);
                $this->fail('accepted ' . $bad);
            } catch (ValidationException $e) {
                $this->assertSame('amount', $e->key());
            }
        }
    }

    public function testPerMillionRoundsHalfUpAndDoesNotOverflow(): void
    {
        $this->assertSame(3, Money::perMillion(1, 2500000));
        $this->assertSame(2, Money::perMillion(1, 2499999));
        $this->assertSame(1000000000000, Money::perMillion(1000000000, 1000000000));
        $this->expectException(ValidationException::class);
        Money::perMillion(PHP_INT_MAX, PHP_INT_MAX);
    }

    public function testMicros(): void
    {
        $this->assertSame(2500000, Money::toMicros(2.5));
        $this->assertSame(3000000, Money::toMicros(3));
        $this->assertSame(1234567, Money::toMicros('1.2345678'));
        $this->assertSame('1.234567', Money::microsToDecimal(1234567));
        $this->assertSame(50000000, Money::perUnit(2500000, 20000000));
    }

    public function testT63FormatBelowOneCent(): void
    {
        $this->assertSame('USD < 0.01', Money::format(9999999, 'USD'));
        $this->assertSame('USD 0.01', Money::format(10000000, 'USD'));
        $this->assertSame('USD 0.00', Money::format(0, 'USD'));
        $this->assertSame('EUR 12.35', Money::format(12345000000, 'EUR'));
    }
}
