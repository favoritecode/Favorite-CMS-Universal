<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways\Bkash;

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
 * bKash Merchant Payment Gateway Driver
 *
 * Implements the official bKash Merchant Tokenized Checkout API lifecycle:
 * - Direct BDT acquiring (zero FX conversion for regional BDT orders)
 * - Real token grant and checkout creation via BkashHttpClient
 * - Redirects to official bkashURL
 * - Server-side payment execution upon customer return
 * - Server-to-server webhook/IPN verification via authoritative query
 * - Status query and automated refund handling
 * - Clean NOT_CONFIGURED when credentials are missing
 */
class BkashMerchantGateway implements
    PaymentGatewayInterface,
    ConfigurableGatewayInterface,
    RedirectPaymentGatewayInterface,
    StatusQueryableGatewayInterface,
    RefundableGatewayInterface,
    WebhookGatewayInterface
{
    public const GATEWAY_ID = 'bkash_direct';

    private string $id;
    private string $title;
    private bool $enabled;
    private array $supportedCurrencies;
    private array $config;
    private BkashHttpClient $client;
    private ?Database $db;

    public function __construct(array $config = [], ?BkashHttpClient $client = null, ?Database $db = null)
    {
        $this->id = self::GATEWAY_ID;
        $this->title = 'bKash Online Payment';
        $this->supportedCurrencies = ['BDT'];
        $this->db = $db;

        if (empty($config) && class_exists(\FavoriteCMS\Models\Setting::class)) {
            try {
                $saved = \FavoriteCMS\Models\Setting::getGroup('favorite_pay_bkash_direct');
                if (!empty($saved)) {
                    $config = [
                        'enabled'    => !empty($saved['enabled']),
                        'sandbox'    => !isset($saved['sandbox']) || !empty($saved['sandbox']),
                        'app_key'    => (string)($saved['app_key'] ?? ''),
                        'app_secret' => (string)($saved['app_secret'] ?? ''),
                        'username'   => (string)($saved['username'] ?? ''),
                        'password'   => (string)($saved['password'] ?? ''),
                        'base_url'   => (string)($saved['base_url'] ?? ''),
                    ];
                }
            } catch (\Throwable) {
                // Ignore during installation or boot
            }
        }

        $this->enabled = !empty($config['enabled']);
        $this->config = $config;

        $baseUrl = !empty($config['base_url'])
            ? (string)$config['base_url']
            : (!empty($config['sandbox']) ? BkashHttpClient::DEFAULT_SANDBOX_URL : BkashHttpClient::DEFAULT_PRODUCTION_URL);

        $this->client = $client ?? new BkashHttpClient(
            (string)($config['app_key'] ?? ''),
            (string)($config['app_secret'] ?? ''),
            (string)($config['username'] ?? ''),
            (string)($config['password'] ?? ''),
            $baseUrl
        );
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
        return PaymentMethodType::BKASH;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->config['enabled'] = $enabled;
        return $this;
    }

    public function getSupportedCurrencies(): array
    {
        return $this->supportedCurrencies;
    }

    public function getHttpClient(): BkashHttpClient
    {
        return $this->client;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['app_key'])
            && !empty($this->config['app_secret'])
            && !empty($this->config['username'])
            && !empty($this->config['password']);
    }

    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->isConfigured();
    }

    public function getInstructions(array $context = []): array
    {
        return [
            'gateway_id'    => $this->id,
            'title'         => $this->title,
            'currencies'    => $this->supportedCurrencies,
            'is_enabled'    => $this->enabled,
            'is_configured' => $this->isConfigured(),
            'is_available'  => $this->isAvailable(),
            'instructions'  => 'You will be redirected to the secure bKash payment gateway to authorize payment.',
        ];
    }

    public function getConfigurationStatus(): array
    {
        $isEnabled = $this->isEnabled();
        $isConfigured = $this->isConfigured();
        $isReady = $isEnabled && $isConfigured;

        if (!$isEnabled) {
            $state = 'DISABLED';
            $message = 'bKash Automatic gateway is disabled by administrator.';
        } elseif (!$isConfigured) {
            $state = 'NOT_CONFIGURED';
            $missing = [];
            if (empty($this->config['app_key'])) $missing[] = 'App Key';
            if (empty($this->config['app_secret'])) $missing[] = 'App Secret';
            if (empty($this->config['username'])) $missing[] = 'Username';
            if (empty($this->config['password'])) $missing[] = 'Password';
            $message = 'bKash Merchant credentials missing: ' . implode(', ', $missing) . '.';
        } else {
            $state = 'READY';
            $env = !empty($this->config['sandbox']) ? 'Sandbox' : 'Production';
            $message = "bKash Automatic gateway is configured and ready (" . $env . " environment).";
        }

        return [
            'state'          => $state,
            'is_ready'       => $isReady,
            'is_configured'  => $isConfigured,
            'enabled'        => $isEnabled,
            'sandbox'        => !empty($this->config['sandbox']),
            'has_app_key'    => !empty($this->config['app_key']),
            'has_app_secret' => !empty($this->config['app_secret']),
            'has_username'   => !empty($this->config['username']),
            'has_password'   => !empty($this->config['password']),
            'message'        => $message,
            'gateway_id'     => $this->id,
            'charge_currency'=> 'BDT',
        ];
    }

    /**
     * Create payment attempt and initiate real bKash Checkout payment.
     */
    public function createAttempt(PaymentIntent $intent, array $params = []): PaymentAttempt
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException("bKash Automatic gateway is disabled.");
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException("bKash Automatic is not configured: merchant credentials (App Key, App Secret, Username, Password) are required.");
        }

        $amount = $intent->getChargeAmount();
        $currency = $amount->getCurrency();
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            throw new InvalidArgumentException("Currency '{$currency}' is not supported by bKash Automatic gateway (BDT required).");
        }

        if (!$amount->isPositive()) {
            throw new InvalidArgumentException("Payment amount must be strictly positive.");
        }

        $decimalAmountStr = DecimalFormatter::minorUnitToDecimal($amount->getAmount(), 2);
        $invoiceNumber = 'FP-' . $intent->getId() . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $callbackUrl = $params['callback_url'] ?? '/pay/bkash/callback';

        $response = $this->client->createPayment(
            $decimalAmountStr,
            $invoiceNumber,
            $callbackUrl,
            $params['payer_reference'] ?? 'customer'
        );

        $statusCode = (string)($response['statusCode'] ?? '');
        if ($statusCode !== '0000' || empty($response['paymentID']) || empty($response['bkashURL'])) {
            $errMsg = $response['statusMessage'] ?? 'bKash order creation failed';
            SafeLogger::error("bKash createPayment failure", ['response' => $response]);
            throw new RuntimeException("bKash payment initiation failed: {$errMsg} (Code: {$statusCode})");
        }

        $paymentId = (string)$response['paymentID'];
        $bkashUrl = (string)$response['bkashURL'];
        $attemptId = 'att_bkash_' . bin2hex(random_bytes(10));

        return new PaymentAttempt(
            $attemptId,
            $intent->getId(),
            $this->id,
            $amount,
            PaymentStatus::PENDING,
            $paymentId,
            null,
            null,
            null,
            null,
            $params['idempotency_key'] ?? null,
            null,
            [
                'payment_id'     => $paymentId,
                'bkash_url'      => $bkashUrl,
                'invoice_number' => $invoiceNumber,
                'environment'    => !empty($this->config['sandbox']) ? 'sandbox' : 'production',
            ]
        );
    }

    /**
     * Execute callback verification when customer returns from bKash checkout.
     */
    public function executeCallback(PaymentAttempt $attempt, array $callbackParams): PaymentAttempt
    {
        $status = strtolower(trim((string)($callbackParams['status'] ?? '')));
        $paymentId = (string)($callbackParams['paymentID'] ?? $attempt->getTransactionReference());

        if ($status === 'cancel') {
            return $attempt->markCancelled('Customer cancelled checkout on bKash.');
        }

        if ($status === 'failure' || $status !== 'success') {
            return $attempt->markFailed('bKash authorization was unsuccessful.');
        }

        $res = $this->client->executePayment($paymentId);
        $statusCode = (string)($res['statusCode'] ?? '');

        if ($statusCode === '0000' && ($res['transactionStatus'] ?? '') === 'Completed') {
            $trxId = (string)($res['trxID'] ?? $paymentId);
            $amountDecimal = (string)($res['amount'] ?? '');
            
            // Validate charge amount matches
            $expectedDecimal = DecimalFormatter::minorUnitToDecimal($attempt->getAmount()->getAmount(), 2);
            if ($amountDecimal !== '' && $amountDecimal !== $expectedDecimal) {
                SafeLogger::error("bKash amount mismatch", [
                    'expected' => $expectedDecimal,
                    'received' => $amountDecimal,
                ]);
                return $attempt->markFailed("Amount mismatch: expected {$expectedDecimal}, received {$amountDecimal}");
            }

            return $attempt->markSucceeded($trxId, [
                'bkash_trx_id'   => $trxId,
                'customer_msisdn'=> $res['customerMsisdn'] ?? null,
                'executed_at'    => $res['paymentExecuteTime'] ?? date('Y-m-d H:i:s'),
            ]);
        }

        $errMsg = $res['statusMessage'] ?? 'bKash execute payment failed';
        return $attempt->markFailed("bKash execution failed: {$errMsg} (Code: {$statusCode})");
    }

    public function verifyAttempt(PaymentAttempt $attempt, array $verificationData = []): PaymentAttempt
    {
        return $attempt;
    }

    public function queryStatus(PaymentAttempt $attempt): PaymentStatus
    {
        $paymentId = $attempt->getTransactionReference();
        if (empty($paymentId)) {
            return $attempt->getStatus();
        }

        try {
            $res = $this->client->queryPayment($paymentId);
            $trxStatus = $res['transactionStatus'] ?? '';
            if ($trxStatus === 'Completed') {
                return PaymentStatus::SUCCEEDED;
            }
            if ($trxStatus === 'Initiated') {
                return PaymentStatus::PENDING;
            }
            if (in_array($trxStatus, ['Failed', 'Cancelled'], true)) {
                return PaymentStatus::FAILED;
            }
        } catch (\Throwable $e) {
            SafeLogger::error("bKash queryStatus failure: " . $e->getMessage());
        }

        return $attempt->getStatus();
    }

    /**
     * Execute an automated refund with bKash Merchant API.
     */
    public function refund(string $transactionId, Money $amount, string $reason = ''): GatewayRefundResult
    {
        $paymentId = null;
        $trxId = null;

        if ($this->db !== null && method_exists($this->db, 'tableExists') && $this->db->tableExists('favorite_pay_attempts')) {
            try {
                $attRow = $this->db->selectOne(
                    "SELECT provider_reference, transaction_reference, response_payload FROM favorite_pay_attempts WHERE (transaction_id = ? OR provider_reference = ? OR transaction_reference = ?) AND status = 'succeeded' LIMIT 1",
                    [$transactionId, $transactionId, $transactionId]
                );
                if ($attRow) {
                    $paymentId = !empty($attRow->transaction_reference) ? (string)$attRow->transaction_reference : (string)$attRow->provider_reference;
                    $trxId = !empty($attRow->provider_reference) ? (string)$attRow->provider_reference : (string)$attRow->transaction_reference;
                    if (!empty($attRow->response_payload)) {
                        $meta = json_decode((string)$attRow->response_payload, true);
                        if (is_array($meta)) {
                            if (!empty($meta['payment_id'])) {
                                $paymentId = (string)$meta['payment_id'];
                            }
                            if (!empty($meta['bkash_trx_id'])) {
                                $trxId = (string)$meta['bkash_trx_id'];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore DB lookup error and fall back to transaction identifier
            }
        }

        if ($paymentId === null) {
            $paymentId = $transactionId;
        }
        if ($trxId === null) {
            $trxId = $transactionId;
        }

        try {
            $amountDecimal = DecimalFormatter::minorUnitToDecimal($amount->getAmount(), 2);
            $res = $this->client->refund($paymentId, $amountDecimal, $trxId, '', $reason);
            $statusCode = (string)($res['statusCode'] ?? '');

            if ($statusCode === '0000') {
                $refundTrxId = (string)($res['refundTrxID'] ?? ($res['trxID'] ?? $trxId));
                return GatewayRefundResult::success($refundTrxId, $amount, $res);
            }

            $errMsg = $res['statusMessage'] ?? 'bKash refund declined';
            return GatewayRefundResult::failure("bKash refund declined: {$errMsg} (Code: {$statusCode})", $res);
        } catch (\Throwable $e) {
            SafeLogger::error("bKash refund exception", ['error' => $e->getMessage(), 'transaction_id' => $transactionId]);
            return GatewayRefundResult::failure("bKash refund exception: " . $e->getMessage());
        }
    }

    /**
     * Backward compatible helper to refund directly from a PaymentAttempt instance.
     */
    public function refundAttempt(PaymentAttempt $attempt, Money $amount, string $reason = ''): GatewayRefundResult
    {
        $trxId = (string)($attempt->getMetadata()['bkash_trx_id'] ?? ($attempt->getTransactionReference() ?: $attempt->getId()));
        return $this->refund($trxId, $amount, $reason);
    }

    /**
     * Verify incoming provider webhook request and parse verified payment result.
     * Queries bKash Tokenized Checkout API to authoritatively confirm payment completion.
     *
     * @param array<string, string|string[]> $headers HTTP request headers
     * @param string|array $payload Raw or parsed webhook payload
     */
    public function verifyWebhook(array $headers, string|array $payload): VerifiedWebhookResult
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                return VerifiedWebhookResult::rejected("Malformed or unparseable bKash webhook JSON payload.");
            }
        } elseif (is_array($payload)) {
            $decoded = $payload;
        } else {
            return VerifiedWebhookResult::rejected("Invalid bKash webhook payload format.");
        }

        $paymentId = (string)($decoded['paymentID'] ?? ($decoded['paymentId'] ?? ''));
        if ($paymentId === '') {
            return VerifiedWebhookResult::rejected("Missing required 'paymentID' in bKash webhook payload.");
        }

        try {
            $queryRes = $this->client->queryPayment($paymentId);
            $statusCode = (string)($queryRes['statusCode'] ?? '');

            if ($statusCode !== '0000') {
                $statusMsg = (string)($queryRes['statusMessage'] ?? 'Incomplete or failed query');
                return VerifiedWebhookResult::rejected("bKash webhook query verification rejected: {$statusMsg} (Code: {$statusCode})", $queryRes);
            }

            $trxStatus = (string)($queryRes['transactionStatus'] ?? '');
            $trxId = (string)($queryRes['trxID'] ?? $paymentId);
            $amountDecimal = (string)($queryRes['amount'] ?? '0');
            $currency = strtoupper((string)($queryRes['currency'] ?? 'BDT'));
            $amountMinor = DecimalFormatter::decimalToMinorUnits($amountDecimal, 2);
            $amount = new Money($amountMinor, $currency);

            if ($trxStatus === 'Completed') {
                return VerifiedWebhookResult::success($paymentId, $trxId, $amount, $queryRes);
            }

            if (in_array($trxStatus, ['Failed', 'Cancelled', 'Declined'], true)) {
                $errorMsg = (string)($queryRes['statusMessage'] ?? "Payment {$trxStatus}");
                return VerifiedWebhookResult::failed($paymentId, $trxId, $amount, $errorMsg, $queryRes);
            }

            return VerifiedWebhookResult::rejected("bKash payment transactionStatus '{$trxStatus}' is not finalized.", $queryRes);
        } catch (\Throwable $e) {
            SafeLogger::error("bKash webhook query exception", ['error' => $e->getMessage(), 'payment_id' => $paymentId]);
            return VerifiedWebhookResult::rejected("bKash webhook verification exception: " . $e->getMessage());
        }
    }

    public function getRedirectUrl(PaymentAttempt $attempt): string
    {
        $metadata = $attempt->getMetadata();
        return (string)($metadata['bkash_url'] ?? '');
    }

    public function getRedirectMethod(): string
    {
        return 'GET';
    }

    public function getRedirectPayload(PaymentAttempt $attempt): array
    {
        return [
            'attempt_id' => $attempt->getId(),
            'payment_id' => $attempt->getTransactionReference(),
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'enabled' => [
                'type'        => 'checkbox',
                'label'       => 'Enable bKash Automatic Payment',
                'required'    => false,
                'secret'      => false,
                'description' => 'Enable automated acquiring via bKash Merchant API.',
            ],
            'sandbox' => [
                'type'        => 'checkbox',
                'label'       => 'Sandbox Mode',
                'required'    => false,
                'secret'      => false,
                'description' => 'Enable bKash Developer Sandbox for testing.',
            ],
            'app_key' => [
                'type'        => 'text',
                'label'       => 'bKash App Key',
                'required'    => true,
                'secret'      => false,
                'description' => 'Official merchant App Key provided by bKash Merchant Portal.',
            ],
            'app_secret' => [
                'type'        => 'password',
                'label'       => 'bKash App Secret',
                'required'    => true,
                'secret'      => true,
                'description' => 'Official merchant App Secret provided by bKash.',
            ],
            'username' => [
                'type'        => 'text',
                'label'       => 'bKash Merchant Username',
                'required'    => true,
                'secret'      => false,
                'description' => 'Merchant username for API token authorization.',
            ],
            'password' => [
                'type'        => 'password',
                'label'       => 'bKash Merchant Password',
                'required'    => true,
                'secret'      => true,
                'description' => 'Merchant password for API token authorization.',
            ],
            'base_url' => [
                'type'        => 'text',
                'label'       => 'API Base URL (Optional)',
                'required'    => false,
                'secret'      => false,
                'description' => 'Custom API endpoint URL. Leave blank for default endpoints.',
            ],
        ];
    }

    public function validateConfig(array $config): array
    {
        $validated = [];
        $validated['enabled'] = !empty($config['enabled']);
        $validated['sandbox'] = !empty($config['sandbox']);
        $validated['app_key'] = trim((string)($config['app_key'] ?? ''));
        $validated['app_secret'] = (string)($config['app_secret'] ?? '');
        $validated['username'] = trim((string)($config['username'] ?? ''));
        $validated['password'] = (string)($config['password'] ?? '');
        $validated['base_url'] = trim((string)($config['base_url'] ?? ''));

        if ($validated['enabled']) {
            if ($validated['app_key'] === '') {
                throw new InvalidArgumentException("bKash App Key is required when gateway is enabled.");
            }
            if ($validated['username'] === '') {
                throw new InvalidArgumentException("bKash Merchant Username is required when gateway is enabled.");
            }
        }

        return $validated;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getPublicConfig(): array
    {
        return [
            'enabled'    => $this->isEnabled(),
            'sandbox'    => !empty($this->config['sandbox']),
            'app_key'    => (string)($this->config['app_key'] ?? ''),
            'username'   => (string)($this->config['username'] ?? ''),
            'base_url'   => (string)($this->config['base_url'] ?? ''),
            'is_ready'   => $this->isConfigured(),
        ];
    }

    public function setConfig(array $config): void
    {
        if (empty($config['app_secret']) && !empty($this->config['app_secret'])) {
            $config['app_secret'] = $this->config['app_secret'];
        }
        if (empty($config['password']) && !empty($this->config['password'])) {
            $config['password'] = $this->config['password'];
        }

        $this->config = array_merge($this->config, $this->validateConfig($config));
        $this->enabled = !empty($this->config['enabled']);

        $baseUrl = !empty($this->config['base_url'])
            ? (string)$this->config['base_url']
            : (!empty($this->config['sandbox']) ? BkashHttpClient::DEFAULT_SANDBOX_URL : BkashHttpClient::DEFAULT_PRODUCTION_URL);

        $this->client->setCredentials(
            (string)($this->config['app_key'] ?? ''),
            (string)($this->config['app_secret'] ?? ''),
            (string)($this->config['username'] ?? ''),
            (string)($this->config['password'] ?? '')
        );
        $this->client->setBaseUrl($baseUrl);
    }
}
