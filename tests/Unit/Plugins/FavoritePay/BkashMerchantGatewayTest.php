<?php

declare(strict_types=1);

namespace FavoriteCMS\Tests\Unit\Plugins\FavoritePay;

use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Gateways\Bkash\BkashHttpClient;
use FavoriteCMS\Pay\Gateways\Bkash\BkashMerchantGateway;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BkashMerchantGatewayTest extends TestCase
{
    public function testConfigurationValidationAndReadinessStates(): void
    {
        $gateway = new BkashMerchantGateway();

        // 1. Initial Disabled state
        $gateway->setEnabled(false);
        $status = $gateway->getConfigurationStatus();
        $this->assertSame('DISABLED', $status['state']);
        $this->assertFalse($status['is_ready']);

        // 2. Enabled but missing credentials
        $gateway->setEnabled(true);
        $status = $gateway->getConfigurationStatus();
        $this->assertSame('NOT_CONFIGURED', $status['state']);
        $this->assertFalse($gateway->isConfigured());
        $this->assertFalse($gateway->isAvailable());
        $this->assertStringContainsString('bKash Merchant credentials missing', $status['message']);

        // 3. Full credentials configured => READY
        $gateway->setConfig([
            'enabled'    => true,
            'app_key'    => 'bkash_test_app_key',
            'app_secret' => 'bkash_test_app_secret',
            'username'   => 'bkash_test_user',
            'password'   => 'bkash_test_pass',
            'sandbox'    => true,
        ]);
        $this->assertTrue($gateway->isConfigured());
        $this->assertTrue($gateway->isAvailable());
        $status = $gateway->getConfigurationStatus();
        $this->assertSame('READY', $status['state']);
        $this->assertTrue($status['is_ready']);
        $this->assertStringContainsString('Sandbox', $status['message']);
    }

    public function testCreateAttemptInitiatesRealBkashPaymentWithMockTransport(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'    => '0000',
                        'statusMessage' => 'Successful',
                        'id_token'      => 'mock_id_token_12345',
                        'expires_in'    => 3600,
                    ]),
                ];
            }
            if (str_contains($url, '/create')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'            => '0000',
                        'statusMessage'         => 'Successful',
                        'paymentID'             => 'TR0011mockPaymentId',
                        'bkashURL'              => 'https://sandbox.payment.bkash.com/checkout?paymentID=TR0011mockPaymentId',
                        'transactionStatus'     => 'Initiated',
                        'amount'                => '120.00',
                        'currency'              => 'BDT',
                        'merchantInvoiceNumber' => 'FP-INV-001',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient(
            'app_key',
            'app_secret',
            'user',
            'pass',
            BkashHttpClient::DEFAULT_SANDBOX_URL,
            $mockTransport
        );

        $gateway = new BkashMerchantGateway([
            'enabled'    => true,
            'app_key'    => 'app_key',
            'app_secret' => 'app_secret',
            'username'   => 'user',
            'password'   => 'pass',
        ], $client);

        $intent = new PaymentIntent('int_001', 'shop', 'ORD-1', new Money(12000, 'BDT'), new Money(12000, 'BDT'));
        $attempt = $gateway->createAttempt($intent);

        $this->assertInstanceOf(PaymentAttempt::class, $attempt);
        $this->assertSame('TR0011mockPaymentId', $attempt->getTransactionReference());
        $this->assertSame(PaymentStatus::PENDING, $attempt->getStatus());
        $this->assertSame('https://sandbox.payment.bkash.com/checkout?paymentID=TR0011mockPaymentId', $gateway->getRedirectUrl($attempt));
    }

    public function testExecuteCallbackMarksSucceededOnCompletedStatus(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/execute')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'         => '0000',
                        'statusMessage'      => 'Successful',
                        'paymentID'          => 'TR0011mockPaymentId',
                        'trxID'              => 'BKA9988776655',
                        'transactionStatus'  => 'Completed',
                        'amount'             => '120.00',
                        'currency'           => 'BDT',
                        'customerMsisdn'     => '01711000000',
                        'paymentExecuteTime' => '2026-09-05 01:20:00',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $attempt = new PaymentAttempt(
            'att_bkash_001',
            'int_001',
            'bkash_direct',
            new Money(12000, 'BDT'),
            PaymentStatus::PENDING,
            'TR0011mockPaymentId'
        );

        $verified = $gateway->executeCallback($attempt, [
            'paymentID' => 'TR0011mockPaymentId',
            'status'    => 'success',
        ]);

        $this->assertSame(PaymentStatus::SUCCEEDED, $verified->getStatus());
        $this->assertSame('BKA9988776655', $verified->getTransactionReference());
    }

    public function testExecuteCallbackHandlesCustomerCancellation(): void
    {
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']);

        $attempt = new PaymentAttempt(
            'att_bkash_001',
            'int_001',
            'bkash_direct',
            new Money(12000, 'BDT'),
            PaymentStatus::PENDING,
            'TR0011mockPaymentId'
        );

        $cancelled = $gateway->executeCallback($attempt, [
            'paymentID' => 'TR0011mockPaymentId',
            'status'    => 'cancel',
        ]);

        $this->assertSame(PaymentStatus::CANCELLED, $cancelled->getStatus());
    }

    public function testImplementsRefundableAndWebhookInterfaces(): void
    {
        $gateway = new BkashMerchantGateway();
        $this->assertInstanceOf(\FavoriteCMS\Pay\Contracts\RefundableGatewayInterface::class, $gateway);
        $this->assertInstanceOf(\FavoriteCMS\Pay\Contracts\WebhookGatewayInterface::class, $gateway);
    }

    public function testRefundSuccess(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/refund')) {
                $payload = json_decode($body, true);
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'    => '0000',
                        'statusMessage' => 'Successful',
                        'refundTrxID'   => 'REF_BKASH_999888',
                        'trxID'         => $payload['trxID'] ?? 'BKA123456',
                        'amount'        => $payload['amount'] ?? '50.00',
                        'currency'      => 'BDT',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $result = $gateway->refund('BKA123456', new Money(5000, 'BDT'), 'Item out of stock');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('REF_BKASH_999888', $result->getProviderRefundReference());
        $this->assertSame(5000, $result->getRefundedAmount()->getAmount());
        $this->assertSame('BDT', $result->getRefundedAmount()->getCurrency());
    }

    public function testRefundDeclined(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/refund')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'    => '2005',
                        'statusMessage' => 'Insufficient balance for refund',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $result = $gateway->refund('BKA123456', new Money(5000, 'BDT'), 'Defective item');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Insufficient balance for refund', $result->getErrorMessage());
    }

    public function testVerifyWebhookSuccessOnCompletedQuery(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/query')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'        => '0000',
                        'statusMessage'     => 'Successful',
                        'paymentID'         => 'TR0011mockPaymentId',
                        'trxID'             => 'BKA9988776655',
                        'transactionStatus' => 'Completed',
                        'amount'            => '120.00',
                        'currency'          => 'BDT',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $result = $gateway->verifyWebhook(
            ['Content-Type' => 'application/json'],
            json_encode(['paymentID' => 'TR0011mockPaymentId'])
        );

        $this->assertTrue($result->isVerified());
        $this->assertSame(PaymentStatus::SUCCEEDED, $result->getStatus());
        $this->assertSame('TR0011mockPaymentId', $result->getTransactionId());
        $this->assertSame('BKA9988776655', $result->getProviderReference());
        $this->assertSame(12000, $result->getAmount()->getAmount());
        $this->assertSame('BDT', $result->getAmount()->getCurrency());
    }

    public function testVerifyWebhookRejectsMissingOrMalformedPayload(): void
    {
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']);

        $malformedResult = $gateway->verifyWebhook([], 'not a json');
        $this->assertFalse($malformedResult->isVerified());
        $this->assertStringContainsString('Malformed', $malformedResult->getErrorMessage());

        $missingPaymentId = $gateway->verifyWebhook([], json_encode(['status' => 'success']));
        $this->assertFalse($missingPaymentId->isVerified());
        $this->assertStringContainsString('Missing required \'paymentID\'', $missingPaymentId->getErrorMessage());
    }

    public function testVerifyWebhookHandlesFailedOrCancelledStatus(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/query')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'        => '0000',
                        'statusMessage'     => 'Customer pin verification expired',
                        'paymentID'         => 'TR0011mockPaymentId',
                        'trxID'             => 'TR0011mockPaymentId',
                        'transactionStatus' => 'Failed',
                        'amount'            => '120.00',
                        'currency'          => 'BDT',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $result = $gateway->verifyWebhook([], ['paymentID' => 'TR0011mockPaymentId']);

        $this->assertTrue($result->isVerified());
        $this->assertSame(PaymentStatus::FAILED, $result->getStatus());
        $this->assertStringContainsString('Customer pin verification expired', $result->getErrorMessage());
    }

    public function testVerifyWebhookRejectsIncompleteOrUnverifiedQuery(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/query')) {
                return [
                    'statusCode'    => '2001',
                    'statusMessage' => 'Invalid paymentID provided',
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $result = $gateway->verifyWebhook([], ['paymentID' => 'TR0011invalid']);

        $this->assertFalse($result->isVerified());
        $this->assertStringContainsString('bKash webhook query verification rejected', $result->getErrorMessage());
    }

    public function testRefundServiceCanExecuteBkashGatewayRefund(): void
    {
        $mockTransport = function (string $method, string $url, array $headers, string $body) {
            if (str_contains($url, '/token/grant')) {
                return ['statusCode' => 200, 'body' => json_encode(['statusCode' => '0000', 'id_token' => 'tok', 'expires_in' => 3600])];
            }
            if (str_contains($url, '/payment/refund')) {
                return [
                    'statusCode' => 200,
                    'body'       => json_encode([
                        'statusCode'    => '0000',
                        'statusMessage' => 'Successful',
                        'refundTrxID'   => 'REF_BKASH_AUTOREF',
                        'amount'        => '120.00',
                        'currency'      => 'BDT',
                    ]),
                ];
            }
            return ['statusCode' => 404, 'body' => ''];
        };

        $client = new BkashHttpClient('k', 's', 'u', 'p', BkashHttpClient::DEFAULT_SANDBOX_URL, $mockTransport);
        $gateway = new BkashMerchantGateway(['enabled' => true, 'app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p'], $client);

        $registry = new \FavoriteCMS\Pay\Services\GatewayRegistry();
        $registry->register($gateway);

        $currencyService = new \FavoriteCMS\Pay\Services\CurrencyService();
        $paymentService = new \FavoriteCMS\Pay\Services\PaymentService($currencyService, $registry);
        $intent = $paymentService->createIntent(
            'shop',
            'ORD-999',
            new Money(12000, 'BDT'),
            ['gateway_id' => 'bkash_direct']
        );

        $paymentService->updateIntentStatus($intent->getId(), PaymentStatus::SUCCEEDED);

        $refundService = new \FavoriteCMS\Pay\Services\RefundService($paymentService, $registry);
        $refundRecord = $refundService->createGatewayRefund($intent->getId(), new Money(12000, 'BDT'), 'Customer cancellation');

        $this->assertSame('REF_BKASH_AUTOREF', $refundRecord['provider_refund_reference']);
        $this->assertSame(12000, $refundRecord['amount']);
        $this->assertSame('BDT', $refundRecord['currency']);
        $this->assertSame(PaymentStatus::REFUNDED, $paymentService->getIntent($intent->getId())->getStatus());
    }
}

