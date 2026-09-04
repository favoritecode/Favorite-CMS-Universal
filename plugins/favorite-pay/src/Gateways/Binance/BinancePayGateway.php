<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways\Binance;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Pay\Contracts\ConfigurableGatewayInterface;
use FavoriteCMS\Pay\Contracts\CurrencyServiceInterface;
use FavoriteCMS\Pay\Exceptions\UnauthoritativeRateException;
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
    private ?CurrencyServiceInterface $currencyService;

    public function __construct(
        array $config = [],
        ?BinancePayHttpClient $client = null,
        ?Database $db = null,
        ?CurrencyServiceInterface $currencyService = null
    ) {
        $this->id = self::GATEWAY_ID;
        $this->title = 'Binance Pay';

        if (empty($config) && class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $saved = \FavoriteCMS\Models\Setting::getGroup('favorite_pay_binance');
                if (!empty($saved)) {
                    $config = [
                        'enabled'            => !empty($saved['enabled']),
                        'certificate_sn'     => (string)($saved['certificate_sn'] ?? ''),
                        'api_secret'         => (string)($saved['api_secret'] ?? ''),
                        'sandbox'            => !empty($saved['sandbox']),
                        'preferred_currency' => (string)($saved['preferred_currency'] ?? 'USDT'),
                    ];
                }
            } catch (\Throwable) {
                // Ignore DB access failure on boot or installation
            }
        }

        $this->enabled = !empty($config['enabled']);
        $this->config = $config;
        $this->db = $db;

        if ($currencyService === null && class_exists(\FavoriteCMS\Core\Application::class)) {
            $app = \FavoriteCMS\Core\Application::getInstance();
            if ($app && $app->has(CurrencyServiceInterface::class)) {
                $currencyService = $app->make(CurrencyServiceInterface::class);
            }
        }
        $this->currencyService = $currencyService;

        // Supported currencies: Binance Pay official supported payment assets & fiat quotes
        $this->supportedCurrencies = ['USDT', 'USDC', 'BTC', 'ETH', 'BNB'];

        $certSn = $config['certificate_sn'] ?? null;
        $apiSecret = $config['api_secret'] ?? null;
        $baseUrl = !empty($config['sandbox'])
            ? ($config['sandbox_base_url'] ?? 'https://bpay.binanceapi.com')
            : ($config['base_url'] ?? BinancePayHttpClient::DEFAULT_BASE_URL);

        $this->client = $client ?? new BinancePayHttpClient($certSn, $apiSecret, $baseUrl);
    }

    public function setCurrencyService(?CurrencyServiceInterface $currencyService): void
    {
        $this->currencyService = $currencyService;
    }

    public function getCurrencyService(): ?CurrencyServiceInterface
    {
        return $this->currencyService;
    }

    public function getPreferredPaymentCurrency(): string
    {
        $pref = strtoupper(trim((string)($this->config['preferred_currency'] ?? 'USDT')));
        return in_array($pref, $this->supportedCurrencies, true) ? $pref : 'USDT';
    }

    public function setPreferredPaymentCurrency(string $currency): void
    {
        $cur = strtoupper(trim($currency));
        if (in_array($cur, $this->supportedCurrencies, true)) {
            $this->config['preferred_currency'] = $cur;
        }
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
        $preferred = $this->getPreferredPaymentCurrency();
        return [
            'type'             => 'binance_pay',
            'title'            => 'Pay with Binance Pay',
            'description'      => "Fast, secure cryptocurrency payments directly through your Binance account or Binance App (charged in {$preferred}).",
            'currencies'       => $this->supportedCurrencies,
            'payment_currency' => $preferred,
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
        $snapshot = $intent->getConversionSnapshot();

        // 3. Strict Currency verification & automated conversion for supported site currencies (e.g. BDT, EUR)
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            $preferred = $this->getPreferredPaymentCurrency();
            if ($this->currencyService === null || !$this->currencyService->hasRate($currency, $preferred)) {
                throw new UnauthoritativeRateException(
                    "Cannot process payment: No valid authoritative exchange rate is available between order currency '{$currency}' and Binance acquiring currency '{$preferred}'.",
                    $currency,
                    $preferred
                );
            }
            $snapshot = $this->currencyService->createLockedSnapshot($currency, $preferred);
            if (!$snapshot->isValidForPayment()) {
                throw new UnauthoritativeRateException(
                    "Cannot process payment: Exchange rate between '{$currency}' and '{$preferred}' is not valid or has expired.",
                    $currency,
                    $preferred
                );
            }
            $amount = $snapshot->convert($amount);
            $currency = $amount->getCurrency();
        } else {
            if ($snapshot !== null && !$snapshot->isValidForPayment()) {
                throw new UnauthoritativeRateException(
                    "Cannot process payment: Payment conversion snapshot is non-authoritative or expired.",
                    $snapshot->getFromCurrency(),
                    $snapshot->getToCurrency()
                );
            }
        }

        // 4. Strict Amount verification
        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Payment amount must be strictly positive.");
        }

        // 5. Generate unique alphanumeric merchantTradeNo (max 32 chars, no hyphens, underscores, slashes)
        // Format: 'FP' + 20 chars random hex + 8 chars random hex = 30 chars
        $attemptHex = bin2hex(random_bytes(10));
        $attemptId = 'att_' . $attemptHex;
        $merchantTradeNo = 'FP' . strtoupper($attemptHex) . strtoupper(bin2hex(random_bytes(4)));

        // 6. Exact decimal amount formatting without floating point arithmetic
        $decimalAmountStr = DecimalFormatter::minorUnitToDecimal($amount->getAmount(), 2);

        // 7. Build Create Order payload (Binance Pay OpenAPI v3)
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

        // 8. Execute request
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
            'charge_currency'   => $currency,
            'charge_amount'     => $amount->getAmount(),
            'base_currency'     => $intent->getBaseAmount()->getCurrency(),
            'base_amount'       => $intent->getBaseAmount()->getAmount(),
            'rate_factor'       => $snapshot?->getRateFactor(),
            'rate_scale'        => $snapshot?->getRateScale(),
            'created_currency'  => $currency,
            'decimal_amount'    => $decimalAmountStr,
        ];

        $attempt = new PaymentAttempt(
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

        return $attempt;
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
        $cur = strtoupper(trim($currency));
        if ($cur === $this->getPreferredPaymentCurrency()) {
            return true;
        }
        if (in_array($cur, $this->supportedCurrencies, true)) {
            return true;
        }

        if ($this->currencyService !== null) {
            return $this->currencyService->hasRate($cur, $this->getPreferredPaymentCurrency());
        }

        return false;
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
            'enabled'            => $this->isEnabled(),
            'certificate_sn'     => $this->config['certificate_sn'] ?? '',
            'has_api_secret'     => !empty($this->config['api_secret']),
            'sandbox'            => !empty($this->config['sandbox']),
            'preferred_currency' => $this->getPreferredPaymentCurrency(),
            'base_url'           => $this->client->getBaseUrl(),
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
        $paymentCurrency = $this->getPreferredPaymentCurrency();

        $isConfigured = $this->isConfigured();
        $isEnabled = $this->isEnabled();

        $conversionReady = false;
        $rateSource = 'None';
        $rateStatus = 'No valid authoritative rate';

        if ($primaryCurrency === $paymentCurrency) {
            $conversionReady = true;
            $rateSource = 'Identity';
            $rateStatus = 'Native (No conversion needed)';
        } elseif ($this->currencyService !== null) {
            try {
                $snapshot = $this->currencyService->getRate($primaryCurrency, $paymentCurrency);
                if ($snapshot->isValidForPayment()) {
                    $conversionReady = true;
                    $rateSource = ucfirst($snapshot->getSource());
                    $rateStatus = 'Valid (Fresh)';
                } elseif ($snapshot->isExpired()) {
                    $conversionReady = false;
                    $rateSource = ucfirst($snapshot->getSource());
                    $rateStatus = 'Expired';
                } else {
                    $conversionReady = false;
                    $rateSource = ucfirst($snapshot->getSource());
                    $rateStatus = 'Non-authoritative';
                }
            } catch (\Throwable $e) {
                SafeLogger::debug("Binance Pay configuration status rate check: " . $e->getMessage());
                $conversionReady = false;
                $rateSource = 'None';
                $rateStatus = 'No valid authoritative rate';
            }
        }

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
        } elseif (!$conversionReady) {
            $state = 'NOT_READY';
            $message = "Exchange rate conversion is not available between Primary Currency ({$primaryCurrency}) and Binance Acquiring Currency ({$paymentCurrency}). Rate status: {$rateStatus}.";
        } else {
            $state = 'READY';
            if ($primaryCurrency === $paymentCurrency) {
                $message = "Binance Pay is enabled, configured, and ready to accept payments directly in {$paymentCurrency}.";
            } else {
                $message = "Binance Pay is enabled, configured, and ready to accept payments. Orders in {$primaryCurrency} will be converted to {$paymentCurrency} at locked checkout rates.";
            }
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
            'preferred_currency'   => $paymentCurrency,
            'payment_currency'     => $paymentCurrency,
            'currency_conversion'  => $conversionReady ? 'READY' : 'NOT_READY',
            'rate_source'          => $rateSource,
            'rate_status'          => $rateStatus,
            'webhook_url'          => $this->getWebhookUrl(),
            'supported_currencies' => $this->supportedCurrencies,
            'primary_currency'     => $primaryCurrency,
            'currency_compatible'  => $conversionReady,
            'currency_message'     => $conversionReady
                ? ($primaryCurrency === $paymentCurrency
                    ? "Primary Currency '{$primaryCurrency}' is supported natively by Binance Pay."
                    : "Orders in Primary Currency '{$primaryCurrency}' will be converted to {$paymentCurrency} via locked checkout rates.")
                : "Exchange rate conversion is not available between Primary Currency ({$primaryCurrency}) and {$paymentCurrency}. Rate status: {$rateStatus}.",
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
            'preferred_currency' => [
                'type'    => 'select',
                'label'   => 'Binance Acquiring / Payment Currency',
                'options' => ['USDT', 'USDC'],
                'default' => 'USDT',
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

        $preferred = strtoupper(trim((string)($config['preferred_currency'] ?? 'USDT')));
        $validated['preferred_currency'] = in_array($preferred, ['USDT', 'USDC', 'BTC', 'ETH', 'BNB', 'USD', 'EUR'], true) ? $preferred : 'USDT';

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
