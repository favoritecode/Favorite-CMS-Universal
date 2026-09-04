<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function testIntegerMinorUnitsStorage(): void
    {
        $money = Money::fromMinor(10050, 'BDT');
        $this->assertSame(10050, $money->getAmount());
        $this->assertSame('BDT', $money->getCurrency());
        $this->assertSame('100.50', $money->toMajorUnit());
        $this->assertSame('100.50 BDT', $money->format());
    }

    public function testParseFromMajorStringSafelyWithoutFloats(): void
    {
        $money = Money::fromMajorString('100.50', 'BDT');
        $this->assertSame(10050, $money->getAmount());

        $zeroCents = Money::fromMajorString('25', 'USD');
        $this->assertSame(2500, $zeroCents->getAmount());

        $fractionPadding = Money::fromMajorString('10.5', 'USD');
        $this->assertSame(1050, $fractionPadding->getAmount());

        $singlePoisha = Money::fromMajorString('0.05', 'BDT');
        $this->assertSame(5, $singlePoisha->getAmount());

        $negative = Money::fromMajorString('-50.25', 'BDT');
        $this->assertSame(-5025, $negative->getAmount());
        $this->assertSame('-50.25', $negative->toMajorUnit());
    }

    public function testInvalidStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromMajorString('abc.12');
    }

    public function testZeroDecimalsCurrency(): void
    {
        $jpy = Money::fromMajorString('500', 'JPY');
        $this->assertSame(500, $jpy->getAmount());
        $this->assertSame('500', $jpy->toMajorUnit());
    }

    public function testArithmeticOperations(): void
    {
        $m1 = Money::bdt(5000); // 50.00 BDT
        $m2 = Money::bdt(2550); // 25.50 BDT

        $sum = $m1->add($m2);
        $this->assertSame(7550, $sum->getAmount());
        $this->assertSame('75.50', $sum->toMajorUnit());

        $diff = $m1->subtract($m2);
        $this->assertSame(2450, $diff->getAmount());

        $multiplied = $m1->multiply(3);
        $this->assertSame(15000, $multiplied->getAmount());
    }

    public function testScaledIntegerMultiplication(): void
    {
        $m = Money::fromMinor(1000, 'USD'); // $10.00
        // Multiply by 1.5 using scale 1000: factor = 1500, scale = 1000
        $result = $m->multiplyScaled(1500, 1000);
        $this->assertSame(1500, $result->getAmount()); // $15.00
    }

    public function testCurrencyMismatchThrowsException(): void
    {
        $bdt = Money::bdt(1000);
        $usd = Money::fromMinor(1000, 'USD');

        $this->expectException(InvalidArgumentException::class);
        $bdt->add($usd);
    }

    public function testComparisons(): void
    {
        $a = Money::bdt(1000);
        $b = Money::bdt(2000);
        $c = Money::bdt(1000);

        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($a->equals($c));
        $this->assertFalse($a->equals($b));
    }
}
