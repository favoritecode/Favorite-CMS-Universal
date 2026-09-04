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
    public const ALLOWED_BASE_URLS = [
        'https://bpay.binanceapi.com',
    ];

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

        if (empty($config) && class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $saved = \FavoriteCMS\Models\Setting::getGroup('favorite_pay_binance');
                if (!empty($saved)) {
                    $config = [
                        'enabled'        => !empty($saved['enabled']),
                        'certificate_sn' => (string)($saved['certificate_sn'] ?? ''),
                        'api_secret'     => (string)($saved['api_secret'] ?? ''),
                        'sandbox'        => !empty($saved['sandbox']),
                    ];
                }
            } catch (\Throwable) {
                // Ignore DB access failure on boot or installation
            }
        }

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
        // 1. Fail-safe: Verify gateway is enabled
        if (!$this->isEnabled()) {
            throw new RuntimeException("Binance Pay gateway is disabled.");
        }

        // 2. Fail-safe: Verify required credentials exist
        if (empty($this->config['certificate_sn'])) {
            throw new RuntimeException("Binance Pay is not configured: missing Certificate-SN.");
        }
        if (empty($this->config['api_secret'])) {
            throw new RuntimeException("Binance Pay is not configured: missing API Secret Key.");
        }

        $amount = $intent->getAmount();
        $currency = strtoupper($amount->getCurrency());

        // 3. Strict Currency verification
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

        if (is_string($payload) && trim($payload) === '') {
            return VerifiedWebhookResult::rejected("Malformed or empty Binance Pay webhook payload.");
        }

        $rawBody = is_array($payload)
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string)$payload;

        $signPayload = $timestamp . "\n" . $nonce . "\n" . $rawBody . "\n";

        // Signature verification:
        // Exclusively HMAC-SHA512 with API secret and constant-time hash_equals according to official Binance Pay Merchant API specifications.
        // RSA verification is not part of the Binance Pay Merchant OpenAPI and is strictly rejected.
        $apiSecret = $this->config['api_secret'] ?? null;
        if (empty($apiSecret)) {
            SafeLogger::error("Binance Pay API secret is not configured.");
            return VerifiedWebhookResult::rejected("Binance Pay API secret is not configured.");
        }

        $expectedSig = strtoupper(hash_hmac('sha512', $signPayload, $apiSecret));
        $isVerified = hash_equals($expectedSig, strtoupper((string)$signature));

        if (!$isVerified) {
            SafeLogger::warning("Binance Pay webhook signature verification failed.", [
                'timestamp' => $timestamp,
                'nonce'     => $nonce,
                'cert_sn'   => $certSn,
            ]);
            return VerifiedWebhookResult::rejected("Invalid Binance Pay webhook signature.");
        }

        // Parse JSON payload
        $parsed = is_array($payload) ? $payload : json_decode($rawBody, true);
        if (!is_array($parsed) || empty($parsed)) {
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

    public function isConfigured(): bool
    {
        return !empty($this->config['certificate_sn']) && !empty($this->config['api_secret']);
    }

    public function isCurrencySupported(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), $this->supportedCurrencies, true);
    }

    public function isReady(?string $currency = null): bool
    {
        if (!$this->isEnabled() || !$this->isConfigured()) {
            return false;
        }

        if ($currency === null) {
            $currency = class_exists(\FavoriteCMS\Core\Currency::class)
                ? \FavoriteCMS\Core\Currency::getPrimaryCurrency()
                : 'BDT';
        }

        return $this->isCurrencySupported($currency);
    }

    public function getWebhookUrl(?string $baseUrl = null): string
    {
        $path = '/api/favorite-pay/webhook/' . $this->id;
        if ($baseUrl !== null && trim($baseUrl) !== '') {
            return rtrim($baseUrl, '/') . $path;
        }
        if (class_exists(\FavoriteCMS\Models\Setting::class)) {
            $siteUrl = \FavoriteCMS\Models\Setting::get('general', 'site_url');
            if (!empty($siteUrl)) {
                return rtrim((string)$siteUrl, '/') . $path;
            }
        }
        if (function_exists('url')) {
            return url($path);
        }
        return 'http://localhost' . $path;
    }

    public function getPublicConfig(): array
    {
        return [
            'enabled'        => $this->isEnabled(),
            'certificate_sn' => $this->config['certificate_sn'] ?? '',
            'has_api_secret' => !empty($this->config['api_secret']),
            'sandbox'        => !empty($this->config['sandbox']),
            'base_url'       => $this->client->getBaseUrl(),
        ];
    }

    public function getConfigurationStatus(?string $primaryCurrency = null): array
    {
        if ($primaryCurrency === null) {
            $primaryCurrency = class_exists(\FavoriteCMS\Core\Currency::class)
                ? \FavoriteCMS\Core\Currency::getPrimaryCurrency()
                : 'BDT';
        }
        $primaryCurrency = strtoupper(trim($primaryCurrency));

        $isConfigured = $this->isConfigured();
        $isEnabled = $this->isEnabled();
        $isCurrencySupported = $this->isCurrencySupported($primaryCurrency);

        if (!$isEnabled) {
            $state = 'DISABLED';
            $message = 'Binance Pay is disabled by administrator.';
        } elseif (!$isConfigured) {
            $state = 'NOT_READY';
            $missing = [];
            if (empty($this->config['certificate_sn'])) {
                $missing[] = 'Certificate-SN';
            }
            if (empty($this->config['api_secret'])) {
                $missing[] = 'API Secret Key';
            }
            $message = 'Binance Pay is enabled but incomplete. Missing: ' . implode(', ', $missing) . '.';
        } elseif (!$isCurrencySupported) {
            $state = 'NOT_READY';
            $message = "Binance Pay is not available for the current Primary Currency ({$primaryCurrency}).";
        } else {
            $state = 'READY';
            $message = 'Binance Pay is enabled, configured, and ready to accept payments.';
        }

        return [
            'gateway_id'           => $this->id,
            'title'                => $this->title,
            'enabled'              => $isEnabled,
            'is_configured'        => $isConfigured,
            'is_ready'             => ($state === 'READY'),
            'state'                => $state,
            'message'              => $message,
            'has_certificate_sn'   => !empty($this->config['certificate_sn']),
            'has_api_secret'       => !empty($this->config['api_secret']),
            'certificate_sn'       => $this->config['certificate_sn'] ?? '',
            'webhook_url'          => $this->getWebhookUrl(),
            'supported_currencies' => $this->supportedCurrencies,
            'primary_currency'     => $primaryCurrency,
            'currency_compatible'  => $isCurrencySupported,
            'currency_message'     => $isCurrencySupported
                ? "Primary Currency '{$primaryCurrency}' is supported by Binance Pay."
                : "Binance Pay is not available for the current Primary Currency ({$primaryCurrency}).",
            'environment'          => !empty($this->config['sandbox']) ? 'sandbox' : 'production',
            'api_base_url'         => $this->client->getBaseUrl(),
        ];
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

        $certSn = trim((string)($config['certificate_sn'] ?? ''));
        if ($certSn !== '' && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $certSn) === 0) {
            throw new InvalidArgumentException("Invalid Certificate-SN format. Certificate serial number may only contain alphanumeric characters, hyphens, and underscores.");
        }
        $validated['certificate_sn'] = $certSn;

        $apiSecret = trim((string)($config['api_secret'] ?? ''));
        $validated['api_secret'] = $apiSecret;

        $validated['sandbox'] = !empty($config['sandbox']);

        // SSRF protection: strict allowlist for API base URL
        if (isset($config['base_url'])) {
            $baseUrl = trim((string)$config['base_url']);
            if ($baseUrl !== '' && !in_array($baseUrl, self::ALLOWED_BASE_URLS, true)) {
                throw new InvalidArgumentException(
                    "Disallowed Binance Pay API base URL '{$baseUrl}'. Only official Binance Pay endpoints ("
                    . implode(', ', self::ALLOWED_BASE_URLS) . ") are permitted."
                );
            }
            $validated['base_url'] = $baseUrl !== '' ? $baseUrl : BinancePayHttpClient::DEFAULT_BASE_URL;
        }

        return $validated;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        // Secret preservation:
        // A. Existing secret + blank submitted field: preserve existing secret.
        $submittedSecret = trim((string)($config['api_secret'] ?? ''));
        if ($submittedSecret === '' && !empty($this->config['api_secret'])) {
            $config['api_secret'] = $this->config['api_secret'];
        }

        $validated = $this->validateConfig($config);
        $this->config = array_merge($this->config, $validated);
        $this->enabled = $this->config['enabled'];
        $this->client->setCredentials($this->config['certificate_sn'], $this->config['api_secret']);
        if (isset($this->config['base_url'])) {
            $this->client->setBaseUrl($this->config['base_url']);
        }
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
