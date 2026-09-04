<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways\Binance;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\ConfigurableGatewayInterface;
use FavoriteCMS\Pay\Contracts\PaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\RedirectPaymentGatewayInterface;
use FavoriteCMS\Pay\Contracts\RefundableGatewayInterface;
use FavoriteCMS\Pay\Contracts\StatusQueryableGatewayInterface;
use FavoriteCMS\Pay\Contracts\WebhookGatewayInterface;
use FavoriteCMS\Pay\Domain\GatewayRefundResult;
use FavoriteCMS\Pay\Domain\Money;
use FavoriteCMS\Pay\Domain\PaymentAttempt;
use FavoriteCMS\Pay\Domain\PaymentIntent;
use FavoriteCMS\Pay\Domain\PaymentMethodType;
use FavoriteCMS\Pay\Domain\PaymentStatus;
use FavoriteCMS\Pay\Domain\VerifiedWebhookResult;
use FavoriteCMS\Pay\Support\DecimalFormatter;
use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * BinancePayGateway
 *
 * Production automated payment gateway driver for Binance Pay Merchant API.
 * Uses official OpenAPI v3 for Order Creation, Order Query, and Refunds.
 */
class BinancePayGateway implements
    PaymentGatewayInterface,
    WebhookGatewayInterface,
    RefundableGatewayInterface,
    RedirectPaymentGatewayInterface,
    StatusQueryableGatewayInterface,
    ConfigurableGatewayInterface
{
    public const GATEWAY_ID = 'binance_pay';

    private string $id;
    private string $title;
    private bool $enabled;
    private array $supportedCurrencies;
    private array $config;
    private BinancePayHttpClient $client;
    private ?Database $db;

    public function __construct(
        array $config = [],
        ?BinancePayHttpClient $client = null,
        ?Database $db = null
    ) {
        $this->id = self::GATEWAY_ID;
        $this->title = 'Binance Pay';
        $this->enabled = !empty($config['enabled']);
        $this->config = $config;
        $this->db = $db;

        // Supported currencies: Binance Pay official supported payment assets & fiat quotes
        $this->supportedCurrencies = ['USDT', 'USDC', 'BTC', 'ETH', 'BNB', 'USD', 'EUR'];

        $certSn = $config['certificate_sn'] ?? null;
        $apiSecret = $config['api_secret'] ?? null;
        $baseUrl = !empty($config['sandbox'])
            ? ($config['sandbox_base_url'] ?? 'https://bpay.binanceapi.com')
            : ($config['base_url'] ?? BinancePayHttpClient::DEFAULT_BASE_URL);

        $this->client = $client ?? new BinancePayHttpClient($certSn, $apiSecret, $baseUrl);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): PaymentMethodType
    {
        return PaymentMethodType::CRYPTO;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    public function getHttpClient(): BinancePayHttpClient
    {
        return $this->client;
    }

    public function getInstructions(array $context = []): array
    {
        return [
            'type'        => 'binance_pay',
            'title'       => 'Pay with Binance Pay',
            'description' => 'Fast, secure cryptocurrency payments directly through your Binance account or Binance App.',
            'currencies'  => $this->supportedCurrencies,
        ];
    }

    /**
     * Create a payment attempt and initiate an order with Binance Pay OpenAPI v3.
     */
    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt
    {
        $amount = $intent->getAmount();
        $currency = strtoupper($amount->getCurrency());

        // 1. Strict Currency verification
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            throw new InvalidArgumentException(
                "Binance Pay does not support payment currency '{$currency}'. Supported currencies include: "
                . implode(', ', $this->supportedCurrencies) . "."
            );
        }

        // 2. Strict Amount verification
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Payment amount must be strictly positive.");
        }

        // 3. Generate unique alphanumeric merchantTradeNo (max 32 chars, no hyphens, underscores, slashes)
        // Format: 'FP' + 20 chars random hex + 8 chars random hex = 30 chars
        $attemptHex = bin2hex(random_bytes(10));
        $attemptId = 'att_' . $attemptHex;
        $merchantTradeNo = 'FP' . strtoupper($attemptHex) . strtoupper(bin2hex(random_bytes(4)));

        // 4. Exact decimal amount formatting without floating point arithmetic
        $decimalAmountStr = DecimalFormatter::minorUnitToDecimal($amount->getAmount(), 2);

        // 5. Build Create Order payload (Binance Pay OpenAPI v3)
        $orderData = [
            'env' => [
                'terminalType' => $params['terminal_type'] ?? 'WEB',
            ],
            'merchantTradeNo' => $merchantTradeNo,
            'orderAmount'     => (float)$decimalAmountStr,
            'currency'        => $currency,
            'goods' => [
                'goodsType'        => '02',
                'goodsCategory'    => 'Z000',
                'referenceGoodsId' => (string)($intent->getSourceId() ?? $merchantTradeNo),
                'goodsName'        => 'Payment for Order #' . ($intent->getSourceId() ?? $intent->getId()),
                'goodsDetail'      => 'Order reference: ' . ($intent->getSourceId() ?? $intent->getId()),
            ],
        ];

        if (!empty($params['return_url'])) {
            $orderData['returnUrl'] = $params['return_url'];
        }
        if (!empty($params['cancel_url'])) {
            $orderData['cancelUrl'] = $params['cancel_url'];
        }

        SafeLogger::info("Initiating Binance Pay Create Order.", [
            'intent_id'         => $intent->getId(),
            'merchant_trade_no' => $merchantTradeNo,
            'currency'          => $currency,
            'amount'            => $decimalAmountStr,
        ]);

        // 6. Execute request
        $response = $this->client->request('POST', '/binancepay/openapi/v3/order', $orderData);

        $responseData = $response['data'] ?? [];
        $prepayId = $responseData['prepayId'] ?? null;
        $checkoutUrl = $responseData['checkoutUrl'] ?? null;
        $qrCodeLink = $responseData['qrcodeLink'] ?? null;
        $qrContent = $responseData['qrContent'] ?? null;
        $universalUrl = $responseData['universalUrl'] ?? null;

        $metadata = [
            'merchant_trade_no' => $merchantTradeNo,
            'prepay_id'         => $prepayId,
            'checkout_url'      => $checkoutUrl,
            'qrcode_link'       => $qrCodeLink,
            'qr_content'        => $qrContent,
            'universal_url'     => $universalUrl,
            'created_currency'  => $currency,
            'decimal_amount'    => $decimalAmountStr,
        ];

        return new PaymentAttempt(
            $attemptId,
            $intent->getId(),
            $this->id,
            $amount,
            PaymentStatus::PENDING,
            $merchantTradeNo,
            null,
            null,
            null,
            null,
            $params['idempotency_key'] ?? null,
            null,
            $metadata
        );
    }

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt
    {
        return $attempt;
    }

    /**
     * Customer Redirect URL resolution.
     */
    public function getRedirectUrl(PaymentAttempt $attempt): ?string
    {
        $meta = $attempt->getMetadata();
        return $meta['checkout_url'] ?? ($meta['universal_url'] ?? null);
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectPayload(PaymentAttempt $attempt): array
    {
        $meta = $attempt->getMetadata();
        return [
            'checkout_url'      => $meta['checkout_url'] ?? null,
            'qrcode_link'       => $meta['qrcode_link'] ?? null,
            'prepay_id'         => $meta['prepay_id'] ?? null,
            'merchant_trade_no' => $meta['merchant_trade_no'] ?? $attempt->getTransactionReference(),
        ];
    }

    /**
     * Status Query via POST /binancepay/openapi/order/query.
     */
    public function queryStatus(PaymentAttempt $attempt): PaymentStatus
    {
        $meta = $attempt->getMetadata();
        $merchantTradeNo = $meta['merchant_trade_no'] ?? $attempt->getTransactionReference();
        $prepayId = $meta['prepay_id'] ?? null;

        if (empty($merchantTradeNo) && empty($prepayId)) {
            throw new RuntimeException("Cannot query Binance Pay status without merchantTradeNo or prepayId.");
        }

        $queryPayload = [];
        if (!empty($merchantTradeNo)) {
            $queryPayload['merchantTradeNo'] = $merchantTradeNo;
        }
        if (!empty($prepayId)) {
            $queryPayload['prepayId'] = $prepayId;
        }

        $response = $this->client->request('POST', '/binancepay/openapi/order/query', $queryPayload);
        $data = $response['data'] ?? [];

        $binanceStatus = $data['status'] ?? '';
        $orderCurrency = strtoupper((string)($data['currency'] ?? ''));
        $rawOrderAmount = (string)($data['orderAmount'] ?? '0');

        // Verify amount & currency strictly
        $receivedMinor = DecimalFormatter::decimalToMinorUnits($rawOrderAmount, 2);
        $expectedAmount = $attempt->getAmount();

        if ($receivedMinor !== $expectedAmount->getAmount() || $orderCurrency !== strtoupper($expectedAmount->getCurrency())) {
            SafeLogger::error("Binance Pay order query amount/currency mismatch.", [
                'attempt_id'        => $attempt->getId(),
                'expected_amount'   => $expectedAmount->getAmount(),
                'expected_currency' => $expectedAmount->getCurrency(),
                'received_amount'   => $receivedMinor,
                'received_currency' => $orderCurrency,
            ]);
            throw new RuntimeException("Order query mismatch: Expected {$expectedAmount->getCurrency()} {$expectedAmount->getAmount()}, received {$orderCurrency} {$receivedMinor}.");
        }

        return $this->mapBinanceStatusToPaymentStatus($binanceStatus);
    }

    /**
     * Webhook verification using official Binance Pay notification rules.
     */
    public function verifyWebhook(array $headers, string|array $payload): VerifiedWebhookResult
    {
        // Normalize headers
        $normalizedHeaders = [];
        foreach ($headers as $k => $v) {
            $normalizedHeaders[strtolower((string)$k)] = $v;
        }

        $timestamp = $normalizedHeaders['binancepay-timestamp'] ?? '';
        $nonce     = $normalizedHeaders['binancepay-nonce'] ?? '';
        $signature = $normalizedHeaders['binancepay-signature'] ?? '';
        $certSn    = $normalizedHeaders['binancepay-certificate-sn'] ?? '';

        if (empty($timestamp) || empty($nonce) || empty($signature)) {
            return VerifiedWebhookResult::rejected("Missing required Binance Pay webhook headers (timestamp, nonce, or signature).");
        }

        $rawBody = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string)$payload;

        $signPayload = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";

        // Signature verification:
        // 1. If RSA public key is configured, verify using openssl_verify with SHA256
        // 2. Otherwise verify using HMAC-SHA512 with API secret and constant-time hash_equals
        $isVerified = false;
        $publicKey = $this->config['public_key'] ?? null;
        $apiSecret = $this->config['api_secret'] ?? null;

        if (!empty($publicKey)) {
            $binarySig = base64_decode((string)$signature);
            if ($binarySig !== false) {
                $verifyResult = @openssl_verify($signPayload, $binarySig, $publicKey, OPENSSL_ALGO_SHA256);
                $isVerified = ($verifyResult === 1);
            }
        } elseif (!empty($apiSecret)) {
            $expectedSig = strtoupper(hash_hmac('sha512', $signPayload, $apiSecret));
            $isVerified = hash_equals($expectedSig, strtoupper((string)$signature));
        }

        if (!$isVerified) {
            SafeLogger::warning("Binance Pay webhook signature verification failed.", [
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'cert_sn'   => $certSn,
            ]);
            return VerifiedWebhookResult::rejected("Invalid Binance Pay webhook signature.");
        }

        // Parse JSON payload
        $parsed = is_array($payload) ? $payload : (json_decode($rawBody, true) ?: []);
        if (empty($parsed)) {
            return VerifiedWebhookResult::rejected("Malformed or empty Binance Pay webhook payload.");
        }

        // Binance notification data can be a nested JSON string or array
        $eventData = $parsed['data'] ?? [];
        if (is_string($eventData)) {
            $eventData = json_decode($eventData, true) ?: [];
        }

        $merchantTradeNo = (string)($eventData['merchantTradeNo'] ?? ($parsed['merchantTradeNo'] ?? ''));
        if (empty($merchantTradeNo)) {
            return VerifiedWebhookResult::rejected("Missing merchantTradeNo in Binance Pay webhook payload.");
        }

        $bizStatus = (string)($parsed['bizStatus'] ?? ($eventData['status'] ?? ''));
        $rawAmount = (string)($eventData['totalFee'] ?? ($eventData['orderAmount'] ?? '0'));
        $rawCurrency = strtoupper((string)($eventData['currency'] ?? ''));

        $amountMinor = DecimalFormatter::decimalToMinorUnits($rawAmount, 2);
        $money = new Money($amountMinor, $rawCurrency);

        $status = ($bizStatus === 'PAY_SUCCESS' || $bizStatus === 'PAID')
            ? PaymentStatus::SUCCEEDED
            : $this->mapBinanceStatusToPaymentStatus($bizStatus);

        return new VerifiedWebhookResult(
            true,
            $merchantTradeNo,
            $merchantTradeNo,
            $status,
            $money,
            null,
            $parsed
        );
    }

    /**
     * Automated merchant refund via POST /binancepay/openapi/order/refund.
     */
    public function refund(string $transactionId, Money $amount, string $reason = ''): GatewayRefundResult
    {
        if (!$amount->isPositive()) {
            return GatewayRefundResult::failure("Refund amount must be strictly positive.");
        }

        // Resolve merchantTradeNo / prepayId
        $merchantTradeNo = null;
        $prepayId = null;

        if ($this->db !== null && $this->db->tableExists('favorite_pay_attempts')) {
            $attRow = $this->db->selectOne(
                "SELECT provider_reference, response_payload FROM favorite_pay_attempts WHERE transaction_id = ? AND status = 'succeeded' LIMIT 1",
                [$transactionId]
            );
            if ($attRow) {
                $merchantTradeNo = (string)$attRow->provider_reference;
                if (!empty($attRow->response_payload)) {
                    $meta = json_decode((string)$attRow->response_payload, true);
                    $prepayId = $meta['prepay_id'] ?? null;
                }
            }
        }

        // Fallback: If transactionId itself is alphanumeric trade number
        if ($merchantTradeNo === null) {
            $merchantTradeNo = $transactionId;
        }

        $refundRequestId = 'REF' . strtoupper(bin2hex(random_bytes(14)));
        $decimalAmount = DecimalFormatter::minorUnitToDecimal($amount->getAmount(), 2);

        $refundPayload = [
            'refundRequestId' => $refundRequestId,
            'refundAmount'    => (float)$decimalAmount,
            'refundReason'    => $reason ?: 'Merchant initiated refund',
        ];

        if (!empty($prepayId)) {
            $refundPayload['prepayId'] = $prepayId;
        } else {
            $refundPayload['merchantTradeNo'] = $merchantTradeNo;
        }

        try {
            $response = $this->client->request('POST', '/binancepay/openapi/order/refund', $refundPayload);
            $refData = $response['data'] ?? [];
            $providerRefundId = (string)($refData['refundId'] ?? ($refData['refundRequestId'] ?? $refundRequestId));

            return GatewayRefundResult::success($providerRefundId, $amount, $refData);
        } catch (RuntimeException $e) {
            SafeLogger::error("Binance Pay refund failed.", [
                'transaction_id'    => $transactionId,
                'refund_request_id' => $refundRequestId,
                'error'             => $e->getMessage(),
            ]);
            return GatewayRefundResult::failure("Binance Pay refund declined: " . $e->getMessage());
        }
    }

    public function getConfigSchema(): array
    {
        return [
            'enabled' => [
                'type'    => 'boolean',
                'label'   => 'Enable Binance Pay',
                'default' => false,
            ],
            'certificate_sn' => [
                'type'     => 'text',
                'label'    => 'Binance Certificate Serial Number (Certificate-SN)',
                'required' => true,
                'secret'   => false,
            ],
            'api_secret' => [
                'type'     => 'password',
                'label'    => 'Binance API Secret Key',
                'required' => true,
                'secret'   => true,
            ],
            'public_key' => [
                'type'     => 'textarea',
                'label'    => 'Binance Public Key (PEM format for RSA webhook verification)',
                'required' => false,
                'secret'   => false,
            ],
            'sandbox' => [
                'type'    => 'boolean',
                'label'   => 'Sandbox / Test Mode',
                'default' => false,
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $validated = [];
        $validated['enabled'] = !empty($config['enabled']);
        $validated['certificate_sn'] = trim((string)($config['certificate_sn'] ?? ''));
        $validated['api_secret'] = trim((string)($config['api_secret'] ?? ''));
        $validated['public_key'] = trim((string)($config['public_key'] ?? ''));
        $validated['sandbox'] = !empty($config['sandbox']);
        return $validated;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $validated = $this->validateConfig($config);
        if (empty($validated['api_secret']) && !empty($this->config['api_secret'])) {
            $validated['api_secret'] = $this->config['api_secret'];
        }
        $this->config = array_merge($this->config, $validated);
        $this->enabled = $this->config['enabled'];
        $this->client->setCredentials($this->config['certificate_sn'], $this->config['api_secret']);
    }

    private function mapBinanceStatusToPaymentStatus(string $binanceStatus): PaymentStatus
    {
        return match (strtoupper($binanceStatus)) {
            'PAID', 'PAY_SUCCESS' => PaymentStatus::SUCCEEDED,
            'INITIAL', 'PENDING'  => PaymentStatus::PENDING,
            'CANCELED', 'EXPIRED' => PaymentStatus::CANCELLED,
            'REFUNDED', 'REFUNDING' => PaymentStatus::REFUNDED,
            default               => PaymentStatus::FAILED,
        };
    }
}
