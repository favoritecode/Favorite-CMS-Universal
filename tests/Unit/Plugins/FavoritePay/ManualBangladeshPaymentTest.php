<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use PHPUnit\Framework\TestCase;

class ManualBangladeshPaymentTest extends TestCase
{
    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $currencyService = new CurrencyService();
        $registry = new GatewayRegistry();
        $this->paymentService = new PaymentService($currencyService, $registry);
    }

    public function testFullManualBangladeshApprovalFlow(): void
    {
        // 1. Digital product initiates checkout intent for 500.00 BDT (50,000 Poisha)
        $intent = $this->paymentService->createIntent(
            'favorite-digital',
            'ORDER-DIGITAL-101',
            Money::bdt(50000),
            ['method_type' => PaymentMethodType::MANUAL_BD->value]
        );

        $this->assertSame(PaymentStatus::PENDING, $intent->getStatus());
        $this->assertSame(50000, $intent->getBaseAmount()->getAmount());

        // 2. Customer pays via bKash and submits transaction reference TrxID
        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'bkash_manual',
            'TRX9827364510',
            ['notes' => 'Sent from 01700000000']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame('TRX9827364510', $attempt->getTransactionReference());

        // Verify intent status transitioned to AWAITING_VERIFICATION
        $refreshedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshedIntent->getStatus());

        // 3. Admin operator verifies statement and approves
        $operatorUserId = 42;
        $approvedAttempt = $this->paymentService->approveManualPayment(
            $attempt->getId(),
            $operatorUserId,
            'Verified on bKash merchant portal'
        );

        $this->assertSame(PaymentStatus::SUCCEEDED, $approvedAttempt->getStatus());
        $this->assertSame($operatorUserId, $approvedAttempt->getVerifiedBy());
        $this->assertNotNull($approvedAttempt->getVerifiedAt());

        // 4. Intent is now SUCCEEDED (unlocking customer digital goods)
        $settledIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::SUCCEEDED, $settledIntent->getStatus());
    }

    public function testManualBangladeshRejectionFlow(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite-shop',
            'ORDER-SHOP-202',
            Money::bdt(120000),
            ['method_type' => PaymentMethodType::MANUAL_BD->value]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'nagad_manual',
            'FAKE_TRX_999'
        );

        // Operator rejects attempt due to invalid transaction ID
        $rejectedAttempt = $this->paymentService->rejectManualPayment(
            $attempt->getId(),
            1,
            'Transaction reference not found on Nagad portal'
        );

        $this->assertSame(PaymentStatus::FAILED, $rejectedAttempt->getStatus());
        $this->assertSame('Transaction reference not found on Nagad portal', $rejectedAttempt->getErrorMessage());

        $failedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::FAILED, $failedIntent->getStatus());
    }
}
