<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Services\CurrencyService;
use PHPUnit\Framework\TestCase;

class CurrencyConversionTest extends TestCase
{
    public function testDefaultConversionRates(): void
    {
        $service = new CurrencyService();
        $this->assertSame('BDT', $service->getBaseCurrency());

        $usd = Money::fromMajorString('10.00', 'USD'); // $10.00
        $converted = $service->convert($usd, 'BDT');

        // At rate 117.50, $10.00 = 1175.00 BDT = 117,500 Poisha
        $this->assertSame('BDT', $converted->getCurrency());
        $this->assertSame(117500, $converted->getAmount());
        $this->assertSame('1175.00', $converted->toMajorUnit());
    }

    public function testOperatorAuthoritativeLock(): void
    {
        $service = new CurrencyService();

        // Operator locks rate to 120.00
        $snapshot = $service->setOperatorRate('USD', '120.00', 1);
        $this->assertTrue($snapshot->isAuthoritative());

        $usd = Money::fromMajorString('10.00', 'USD');
        $converted = $service->convert($usd, 'BDT');
        $this->assertSame(120000, $converted->getAmount()); // 1200.00 BDT
    }

    public function testAutoSyncNeverSilentlyOverwritesOperatorLock(): void
    {
        $service = new CurrencyService();

        // 1. Operator manually locks rate
        $service->setOperatorRate('USD', '125.00', 1);

        // 2. Automated background sync tries to set rate to 118.00
        $synced = $service->syncAutomatedRate('USD', '118.00');
        $this->assertFalse($synced, "Automated sync must be rejected when an operator lock is active.");

        // 3. Verify rate remained at operator-locked value
        $rate = $service->getRate('USD', 'BDT');
        $this->assertTrue($rate->isAuthoritative());

        $usd = Money::fromMajorString('1.00', 'USD');
        $converted = $service->convert($usd, 'BDT');
        $this->assertSame(12500, $converted->getAmount()); // 125.00 BDT
    }
}
