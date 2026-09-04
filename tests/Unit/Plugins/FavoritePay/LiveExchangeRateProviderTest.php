<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\ExchangeRateProviderInterface;
use FavoriteCMS\Pay\Domain\ConversionSnapshot;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Providers\DatabaseRateProvider;
use FavoriteCMS\Pay\Providers\LiveExchangeRateProvider;
use FavoriteCMS\Pay\Services\CurrencyService;
use PDO;
use PHPUnit\Framework\TestCase;

class LiveExchangeRateProviderTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private DatabaseRateProvider $fallbackProvider;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);

        $this->db = new class($this->pdo) extends Database {
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
                $this->config = ['driver' => 'sqlite'];
                $this->prefix = '';
            }
            public function getConnection(): PDO
            {
                return $this->pdo;
            }
        };

        $this->db->execute("
            CREATE TABLE favorite_pay_rates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                base_currency VARCHAR(10) NOT NULL,
                quote_currency VARCHAR(10) NOT NULL,
                rate_factor INTEGER NOT NULL,
                rate_scale INTEGER NOT NULL DEFAULT 1000000,
                is_authoritative INTEGER NOT NULL DEFAULT 1,
                status VARCHAR(20) DEFAULT 'active',
                effective_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                source VARCHAR(50) DEFAULT 'database',
                notes TEXT NULL,
                created_at DATETIME NOT NULL
            )
        ");

        $this->fallbackProvider = new DatabaseRateProvider($this->db);
    }

    public function testGetRateFetchesFromLiveTransportAndParsesScaledInteger(): void
    {
        $mockJson = json_encode([
            'result'    => 'success',
            'base_code' => 'USD',
            'rates'     => [
                'USD' => 1,
                'BDT' => 121.50,
                'EUR' => 0.92,
            ],
        ]);

        $mockTransport = function (string $method, string $url, array $headers, string $body) use ($mockJson) {
            return ['statusCode' => 200, 'body' => $mockJson];
        };

        $provider = new LiveExchangeRateProvider($this->db, $this->fallbackProvider, [], $mockTransport);

        $this->assertTrue($provider->hasRate('USD', 'BDT'));
        $snap = $provider->getRate('USD', 'BDT');

        $this->assertNotNull($snap);
        $this->assertSame('USD', $snap->getFromCurrency());
        $this->assertSame('BDT', $snap->getToCurrency());
        $this->assertTrue($snap->isAuthoritative());
        $this->assertSame(121500000, $snap->getRateFactor());
        $this->assertSame(ConversionSnapshot::DEFAULT_SCALE, $snap->getRateScale());
        $this->assertSame(LiveExchangeRateProvider::PROVIDER_ID, $snap->getSource());

        $snapUsdt = $provider->getRate('USDT', 'BDT');
        $this->assertNotNull($snapUsdt);
        $this->assertSame(121500000, $snapUsdt->getRateFactor());
    }

    public function testFallbackToDatabaseWhenLiveFetchFails(): void
    {
        $this->db->insert('favorite_pay_rates', [
            'base_currency'    => 'USDT',
            'quote_currency'   => 'BDT',
            'rate_factor'      => 125000000,
            'rate_scale'       => 1000000,
            'is_authoritative' => 1,
            'status'           => 'active',
            'effective_at'     => date('Y-m-d H:i:s', time() - 60),
            'expires_at'       => date('Y-m-d H:i:s', time() + 3600),
            'source'           => 'operator',
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $failingTransport = function () {
            return ['statusCode' => 500, 'body' => 'Internal Server Error'];
        };

        $provider = new LiveExchangeRateProvider($this->db, $this->fallbackProvider, [
            'fallback_database' => true,
        ], $failingTransport);

        $snap = $provider->getRate('USDT', 'BDT');
        $this->assertNotNull($snap);
        $this->assertSame(125000000, $snap->getRateFactor());
        $this->assertSame('operator', $snap->getSource());
    }

    public function testFailsClosedWhenNeitherLiveNorFallbackIsAvailable(): void
    {
        $failingTransport = function () {
            return ['statusCode' => 500, 'body' => 'Host Unreachable'];
        };

        $provider = new LiveExchangeRateProvider($this->db, $this->fallbackProvider, [], $failingTransport);

        $snap = $provider->getRate('UNKNOWN', 'BDT');
        $this->assertNull($snap);
        $this->assertFalse($provider->hasRate('UNKNOWN', 'BDT'));
    }

    public function testDiagnosticStatusReportsStateAndCounts(): void
    {
        $mockJson = json_encode([
            'result'    => 'success',
            'base_code' => 'USD',
            'rates'     => ['BDT' => 122.00],
        ]);
        $transport = fn() => ['statusCode' => 200, 'body' => $mockJson];

        $provider = new LiveExchangeRateProvider($this->db, $this->fallbackProvider, [], $transport);
        $statusBefore = $provider->getDiagnosticStatus();
        $this->assertSame('READY', $statusBefore['state']);

        $provider->refreshRates();
        $statusAfter = $provider->getDiagnosticStatus();
        $this->assertSame('READY', $statusAfter['state']);
        $this->assertTrue($statusAfter['has_memory_cache']);
        $this->assertNotNull($statusAfter['last_refresh_time']);
    }

    public function testCurrencyServiceIntegratesWithLiveProvider(): void
    {
        $mockJson = json_encode([
            'result'    => 'success',
            'base_code' => 'USD',
            'rates'     => ['BDT' => 120.00],
        ]);
        $transport = fn() => ['statusCode' => 200, 'body' => $mockJson];

        $liveProvider = new LiveExchangeRateProvider($this->db, $this->fallbackProvider, [], $transport);
        $currencyService = new CurrencyService($liveProvider, $this->db);

        $this->assertTrue($currencyService->hasRate('BDT', 'USDT'));
        
        $bdtMoney = new Money(12000, 'BDT');
        $converted = $currencyService->convert($bdtMoney, 'USDT');

        $this->assertSame('USDT', $converted->getCurrency());
        $this->assertSame(100, $converted->getAmount());
    }
}
