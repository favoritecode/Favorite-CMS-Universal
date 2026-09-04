<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\PaymentServiceInterface;
use FavoriteCMS\Pay\Contracts\RefundServiceInterface;
use FavoriteCMS\Pay\Contracts\WalletServiceInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use PHPUnit\Framework\TestCase;

class PluginLifecycleTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application();
    }

    public function testPluginBootstrapBindsAllServicesToContainer(): void
    {
        FavoritePayPlugin::bootstrap($this->app);

        $this->assertTrue($this->app->has(CurrencyServiceInterface::class));
        $this->assertTrue($this->app->has(WalletServiceInterface::class));
        $this->assertTrue($this->app->has(PaymentServiceInterface::class));
        $this->assertTrue($this->app->has(RefundServiceInterface::class));
        $this->assertTrue($this->app->has(GatewayRegistry::class));

        $currencyService = $this->app->make(CurrencyServiceInterface::class);
        $this->assertInstanceOf(CurrencyServiceInterface::class, $currencyService);

        $walletService = $this->app->make(WalletServiceInterface::class);
        $this->assertInstanceOf(WalletServiceInterface::class, $walletService);

        $paymentService = $this->app->make(PaymentServiceInterface::class);
        $this->assertInstanceOf(PaymentServiceInterface::class, $paymentService);

        $refundService = $this->app->make(RefundServiceInterface::class);
        $this->assertInstanceOf(RefundServiceInterface::class, $refundService);
    }

    public function testGatewayRegistryManagement(): void
    {
        FavoritePayPlugin::bootstrap($this->app);
        $registry = $this->app->make(GatewayRegistry::class);

        $mockGateway = new class implements PaymentGatewayInterface {
            public function getId(): string { return 'mock_bkash'; }
            public function getTitle(): string { return 'Mock bKash Gateway'; }
            public function getType(): PaymentMethodType { return PaymentMethodType::MANUAL_BD; }
            public function isEnabled(): bool { return true; }
            public function getSupportedCurrencies(): array { return ['BDT']; }
            public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt {
                return new PaymentAttempt('att_1', $intent->getId(), 'mock_bkash', $intent->getChargeAmount());
            }
            public function verifyAttempt(PaymentAttempt $attempt, array $data = []): PaymentAttempt {
                return $attempt;
            }
        };

        $registry->register($mockGateway);

        $this->assertTrue($registry->has('mock_bkash'));
        $this->assertSame('Mock bKash Gateway', $registry->get('mock_bkash')->getTitle());
        $this->assertCount(1, $registry->enabled());
    }

    public function testRefundLifecycleFullAndPartial(): void
    {
        FavoritePayPlugin::bootstrap($this->app);
        $paymentService = $this->app->make(PaymentServiceInterface::class);
        $refundService = $this->app->make(RefundServiceInterface::class);

        // 1. Create and settle an intent
        $intent = $paymentService->createIntent('favorite-shop', 'ORDER-999', Money::bdt(10000));
        $paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        // 2. Partial Refund of 30.00 BDT (3,000 Poisha)
        $partRefund = $refundService->createRefund($intent->getId(), Money::bdt(3000), 'Partial damaged item');
        $this->assertSame(3000, $partRefund['amount']);
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED, $paymentService->getIntent($intent->getId())->getStatus());

        // 3. Full Refund of remaining
        $fullRefund = $refundService->createRefund($intent->getId(), Money::bdt(10000), 'Complete return');
        $this->assertSame(PaymentStatus::REFUNDED, $paymentService->getIntent($intent->getId())->getStatus());

        $allRefunds = $refundService->getRefunds($intent->getId());
        $this->assertCount(2, $allRefunds);
    }
}
