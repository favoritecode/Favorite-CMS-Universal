<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
use FavoriteCMS\Pay\Providers\InMemoryExchangeRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use PHPUnit\Framework\TestCase;

class CurrencyConversionTest extends TestCase
{
    public function testProductionCurrencyServiceHasNoSeededRatesAndThrows(): void
    {
        $service = new CurrencyService();
        $this->assertSame('BDT', $service->getBaseCurrency());

        $usd = Money::fromMajorString('10.00', 'USD');

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("No valid authoritative exchange rate is available for conversion from 'USD' to 'BDT'.");
        $service->convert($usd, 'BDT');
    }

    public function testConversionWithAuthoritativeProviderRate(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        $provider->setRate('USD', 'BDT', '117.50', true);

        $service = new CurrencyService($provider);
        $usd = Money::fromMajorString('10.00', 'USD');
        $converted = $service->convert($usd, 'BDT');

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

    public function testExpiredRateThrowsUnauthoritativeRateException(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        // Rate that expired 1 hour ago
        $expiredAt = date('Y-m-d H:i:s', time() - 3600);
        $provider->setRate('USD', 'BDT', '120.00', true, $expiredAt);

        $service = new CurrencyService($provider);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("expired");
        $service->getRate('USD', 'BDT');
    }

    public function testNonAuthoritativeRateThrowsUnauthoritativeRateException(): void
    {
        $provider = new InMemoryExchangeRateProvider();
        $provider->setRate('USD', 'BDT', '120.00', false);

        $service = new CurrencyService($provider);

        $this->expectException(UnauthoritativeRateException::class);
        $this->expectExceptionMessage("not authoritative");
        $service->getRate('USD', 'BDT');
    }
}
