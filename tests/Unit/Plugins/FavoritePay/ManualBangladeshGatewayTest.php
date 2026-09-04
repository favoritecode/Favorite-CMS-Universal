<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\FavoritePayPlugin;
use FavoriteCMS\Pay\Gateways\ManualBangladeshGateway;
use FavoriteCMS\Pay\Services\CurrencyService;
use FavoriteCMS\Pay\Services\GatewayRegistry;
use FavoriteCMS\Pay\Services\PaymentService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ManualBangladeshGatewayTest extends TestCase
{
    private PaymentService $paymentService;
    private GatewayRegistry $registry;

    protected function setUp(): void
    {
        $currencyService = new CurrencyService();
        $this->registry = new GatewayRegistry();

        $manualBkash = new ManualBangladeshGateway(
            'manual_bkash',
            'bKash Manual Payment',
            PaymentMethodType::MANUAL_BKASH,
            [
                'channel'        => 'bkash',
                'account_name'   => 'Merchant Ltd',
                'account_number' => '01711111111',
                'account_type'   => 'Merchant',
                'instructions'   => 'Dial *247# to make payment.',
            ]
        );

        $manualNagad = new ManualBangladeshGateway(
            'manual_nagad',
            'Nagad Manual Payment',
            PaymentMethodType::MANUAL_NAGAD,
            [
                'channel'        => 'nagad',
                'account_name'   => 'Merchant Ltd',
                'account_number' => '01822222222',
            ]
        );

        $this->registry->register($manualBkash);
        $this->registry->register($manualNagad);
        $this->paymentService = new PaymentService($currencyService, $this->registry);
    }

    // A. Gateway registry
    public function testManualGatewaysAreRegisteredAndResolvable(): void
    {
        $this->assertTrue($this->registry->has('manual_bkash'));
        $this->assertTrue($this->registry->has('manual_nagad'));

        $bkash = $this->registry->get('manual_bkash');
        $this->assertInstanceOf(PaymentGatewayInterface::class, $bkash);
        $this->assertSame('manual_bkash', $bkash->getId());
        $this->assertSame('bKash Manual Payment', $bkash->getTitle());
        $this->assertSame(PaymentMethodType::MANUAL_BKASH, $bkash->getType());
        $this->assertTrue($bkash->isEnabled());
        $this->assertSame(['BDT'], $bkash->getSupportedCurrencies());
    }

    public function testPaymentInstructionsExposeConfiguredDetails(): void
    {
        $bkash = $this->registry->get('manual_bkash');
        $instructions = $bkash->getInstructions();

        $this->assertSame('manual_bkash', $instructions['gateway_id']);
        $this->assertSame('01711111111', $instructions['account_number']);
        $this->assertSame('Merchant', $instructions['account_type']);
        $this->assertSame('Dial *247# to make payment.', $instructions['instructions']);
    }

    // B. Manual payment creation / submission
    public function testValidSubmissionSucceeds(): void
    {
        $intent = $this->paymentService->createIntent(
            'favorite-digital',
            'ORDER-7001',
            Money::bdt(25000),
            ['method_type' => PaymentMethodType::MANUAL_BKASH->value]
        );

        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_VALID_12345',
            ['sender_account' => '01799999999', 'notes' => 'Paid from app']
        );

        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());
        $this->assertSame('TRX_VALID_12345', $attempt->getTransactionReference());
        $this->assertSame(25000, $attempt->getAmount()->getAmount());
        $this->assertSame('BDT', $attempt->getAmount()->getCurrency());

        $refreshedIntent = $this->paymentService->getIntent($intent->getId());
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $refreshedIntent->getStatus());
    }

    public function testMissingProviderReferenceFails(): void
    {
        $intent = $this->paymentService->createIntent('favorite-shop', 'ORDER-7002', Money::bdt(15000));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Transaction reference (TrxID) cannot be empty");

        $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', '   ');
    }

    public function testInactiveGatewayFails(): void
    {
        $disabledGw = new ManualBangladeshGateway('manual_disabled', 'Disabled Gateway', PaymentMethodType::MANUAL_BD, [], false);
        $this->registry->register($disabledGw);

        $intent = $this->paymentService->createIntent('favorite-shop', 'ORDER-7003', Money::bdt(10000));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Payment gateway 'manual_disabled' is disabled.");

        $this->paymentService->submitManualVerification($intent->getId(), 'manual_disabled', 'TRX_123');
    }

    public function testInvalidTransactionStateFails(): void
    {
        $intent = $this->paymentService->createIntent('favorite-shop', 'ORDER-7004', Money::bdt(10000));
        // Force intent to SUCCEEDED
        $this->paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot submit payment attempt for final intent status: succeeded");

        $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_LATE_123');
    }

    // C. Duplicate protection & Idempotency
    public function testDuplicateProviderReferenceIsRejected(): void
    {
        $intent1 = $this->paymentService->createIntent('favorite-digital', 'ORDER-7005', Money::bdt(10000));
        $intent2 = $this->paymentService->createIntent('favorite-digital', 'ORDER-7006', Money::bdt(10000));

        $this->paymentService->submitManualVerification($intent1->getId(), 'manual_bkash', 'TRX_REUSE_TEST');

        // Submitting same TrxID for second intent must fail
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate transaction reference 'TRX_REUSE_TEST' for gateway 'manual_bkash'");

        $this->paymentService->submitManualVerification($intent2->getId(), 'manual_bkash', 'TRX_REUSE_TEST');
    }

    public function testDuplicateIdempotencyRequestDoesNotCreateDuplicate(): void
    {
        $intent = $this->paymentService->createIntent('favorite-digital', 'ORDER-7007', Money::bdt(10000));

        $attempt1 = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_IDEM_1',
            ['idempotency_key' => 'IDEM_KEY_999']
        );

        $attempt2 = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_IDEM_1',
            ['idempotency_key' => 'IDEM_KEY_999']
        );

        $this->assertSame($attempt1->getId(), $attempt2->getId(), "Idempotent submission must return existing attempt.");
    }

    // D. State transitions
    public function testFullApprovalLifecycle(): void
    {
        $intent = $this->paymentService->createIntent('favorite-digital', 'ORDER-7008', Money::bdt(10000));

        // 1. Pending -> Awaiting Verification
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_bkash', 'TRX_APPROVAL_FLOW');
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());

        // 2. Awaiting Verification -> Succeeded
        $approved = $this->paymentService->approveManualPayment($attempt->getId(), 10, 'Statement checked OK');
        $this->assertSame(PaymentStatus::SUCCEEDED, $approved->getStatus());
        $this->assertSame(10, $approved->getVerifiedBy());
        $this->assertSame('Statement checked OK', $approved->getOperatorNotes());
        $this->assertSame(PaymentStatus::SUCCEEDED, $this->paymentService->getIntent($intent->getId())->getStatus());

        // 3. Already succeeded cannot be approved again
        try {
            $this->paymentService->approveManualPayment($attempt->getId(), 10);
            $this->fail("Expected RuntimeException when approving already approved attempt.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("already approved", $e->getMessage());
        }

        // 4. Succeeded cannot be rejected
        try {
            $this->paymentService->rejectManualPayment($attempt->getId(), 10, 'Revoke');
            $this->fail("Expected RuntimeException when rejecting already approved attempt.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("already approved", $e->getMessage());
        }
    }

    public function testFullRejectionLifecycle(): void
    {
        $intent = $this->paymentService->createIntent('favorite-shop', 'ORDER-7009', Money::bdt(20000));

        // 1. Pending -> Awaiting Verification
        $attempt = $this->paymentService->submitManualVerification($intent->getId(), 'manual_nagad', 'TRX_REJECT_FLOW');
        $this->assertSame(PaymentStatus::AWAITING_VERIFICATION, $attempt->getStatus());

        // 2. Awaiting Verification -> Failed
        $rejected = $this->paymentService->rejectManualPayment($attempt->getId(), 20, 'Payment not found in bank');
        $this->assertSame(PaymentStatus::FAILED, $rejected->getStatus());
        $this->assertSame(20, $rejected->getVerifiedBy());
        $this->assertSame('Payment not found in bank', $rejected->getErrorMessage());
        $this->assertSame(PaymentStatus::FAILED, $this->paymentService->getIntent($intent->getId())->getStatus());

        // 3. Already failed cannot be rejected again
        try {
            $this->paymentService->rejectManualPayment($attempt->getId(), 20, 'Second reject');
            $this->fail("Expected RuntimeException when rejecting already rejected attempt.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("already rejected", $e->getMessage());
        }

        // 4. Failed cannot be approved
        try {
            $this->paymentService->approveManualPayment($attempt->getId(), 20);
            $this->fail("Expected RuntimeException when approving already rejected attempt.");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("already rejected", $e->getMessage());
        }
    }

    // E. Security guarantees
    public function testClientCannotDirectlySetStatusToSucceededOrApproved(): void
    {
        $intent = $this->paymentService->createIntent('favorite-digital', 'ORDER-7010', Money::bdt(5000));

        // Malicious client tries to inject status => succeeded in details
        $attempt = $this->paymentService->submitManualVerification(
            $intent->getId(),
            'manual_bkash',
            'TRX_EXPLOIT_TRY',
            ['status' => 'succeeded', 'approved' => true]
        );

        $this->assertSame(
            PaymentStatus::AWAITING_VERIFICATION,
            $attempt->getStatus(),
            "Client injected status must be ignored; attempt must be strictly AWAITING_VERIFICATION."
        );
    }
}
