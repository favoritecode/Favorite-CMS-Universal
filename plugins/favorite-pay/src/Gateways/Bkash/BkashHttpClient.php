<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways\Bkash;

use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * bKash Dedicated HTTP Client
 *
 * Implements the official bKash Merchant Tokenized Checkout API contract:
 * - Token Grant: POST /tokenized/checkout/token/grant
 * - Token Refresh: POST /tokenized/checkout/token/refresh
 * - Create Payment: POST /tokenized/checkout/create
 * - Execute Payment: POST /tokenized/checkout/execute
 * - Query Payment: POST /tokenized/checkout/payment/query
 * - Search Transaction: POST /tokenized/checkout/general/searchTransaction
 * - Refund: POST /tokenized/checkout/payment/refund
 *
 * Supports custom transport injection for test mocking without external network calls.
 */
class BkashHttpClient
{
    public const DEFAULT_SANDBOX_URL = 'https://tokenized.sandbox.bka.sh/v2.0';
    public const DEFAULT_PRODUCTION_URL = 'https://tokenized.pay.bka.sh/v2.0';

    private string $appKey;
    private string $appSecret;
    private string $username;
    private string $password;
    private string $baseUrl;

    private ?string $idToken = null;
    private ?string $refreshToken = null;
    private ?int $tokenExpiresAt = null;

    /** @var callable|null */
    private $transport;

    public function __construct(
        string $appKey = '',
        string $appSecret = '',
        string $username = '',
        string $password = '',
        string $baseUrl = self::DEFAULT_SANDBOX_URL,
        ?callable $transport = null
    ) {
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    public function setCredentials(string $appKey, string $appSecret, string $username, string $password): void
    {
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->username = $username;
        $this->password = $password;
        $this->idToken = null;
        $this->refreshToken = null;
        $this->tokenExpiresAt = null;
    }

    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    public function hasValidToken(): bool
    {
        return $this->idToken !== null 
            && $this->tokenExpiresAt !== null 
            && $this->tokenExpiresAt > (time() + 60);
    }

    /**
     * Authenticate with bKash and obtain an authorization ID token.
     */
    public function grantToken(): array
    {
        if (empty($this->appKey) || empty($this->appSecret) || empty($this->username) || empty($this->password)) {
            throw new RuntimeException("bKash credentials (App Key, App Secret, Username, Password) are required.");
        }

        $headers = [
            'username: ' . $this->username,
            'password: ' . $this->password,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'app_key'    => $this->appKey,
            'app_secret' => $this->appSecret,
        ], JSON_UNESCAPED_SLASHES);

        $response = $this->sendRequest('POST', '/tokenized/checkout/token/grant', $headers, $payload);

        if (($response['statusCode'] ?? '') === '0000' && !empty($response['id_token'])) {
            $this->idToken = (string)$response['id_token'];
            $this->refreshToken = (string)($response['refresh_token'] ?? '');
            $expiresIn = (int)($response['expires_in'] ?? 3600);
            $this->tokenExpiresAt = time() + $expiresIn;
        }

        return $response;
    }

    /**
     * Ensure valid token before calling protected endpoints.
     */
    public function ensureToken(): string
    {
        if (!$this->hasValidToken()) {
            $res = $this->grantToken();
            if (($res['statusCode'] ?? '') !== '0000' || empty($this->idToken)) {
                $msg = $res['statusMessage'] ?? 'Failed to authenticate with bKash API';
                throw new RuntimeException("bKash token grant failed: {$msg}");
            }
        }
        return (string)$this->idToken;
    }

    /**
     * Create payment request with bKash Tokenized Checkout.
     */
    public function createPayment(
        string $amountDecimal,
        string $merchantInvoiceNumber,
        string $callbackUrl,
        string $payerReference = 'customer',
        string $intent = 'sale'
    ): array {
        $token = $this->ensureToken();

        $headers = [
            'Authorization: ' . $token,
            'X-APP-Key: ' . $this->appKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'mode'                  => '0011',
            'payerReference'        => $payerReference,
            'callbackURL'           => $callbackUrl,
            'amount'                => $amountDecimal,
            'currency'              => 'BDT',
            'intent'                => $intent,
            'merchantInvoiceNumber' => $merchantInvoiceNumber,
        ], JSON_UNESCAPED_SLASHES);

        return $this->sendRequest('POST', '/tokenized/checkout/create', $headers, $payload);
    }

    /**
     * Execute authorized payment after customer checkout redirect.
     */
    public function executePayment(string $paymentId): array
    {
        $token = $this->ensureToken();

        $headers = [
            'Authorization: ' . $token,
            'X-APP-Key: ' . $this->appKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'paymentID' => $paymentId,
        ], JSON_UNESCAPED_SLASHES);

        return $this->sendRequest('POST', '/tokenized/checkout/execute', $headers, $payload);
    }

    /**
     * Query status of an existing paymentID.
     */
    public function queryPayment(string $paymentId): array
    {
        $token = $this->ensureToken();

        $headers = [
            'Authorization: ' . $token,
            'X-APP-Key: ' . $this->appKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'paymentID' => $paymentId,
        ], JSON_UNESCAPED_SLASHES);

        return $this->sendRequest('POST', '/tokenized/checkout/payment/query', $headers, $payload);
    }

    /**
     * Refund payment.
     */
    public function refund(string $paymentId, string $amountDecimal, string $trxId, string $sku = '', string $reason = ''): array
    {
        $token = $this->ensureToken();

        $headers = [
            'Authorization: ' . $token,
            'X-APP-Key: ' . $this->appKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'paymentID' => $paymentId,
            'amount'    => $amountDecimal,
            'trxID'     => $trxId,
            'sku'       => $sku,
            'reason'    => $reason,
        ], JSON_UNESCAPED_SLASHES);

        return $this->sendRequest('POST', '/tokenized/checkout/payment/refund', $headers, $payload);
    }

    /**
     * Search transaction by TrxID.
     */
    public function searchTransaction(string $trxId): array
    {
        $token = $this->ensureToken();

        $headers = [
            'Authorization: ' . $token,
            'X-APP-Key: ' . $this->appKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $payload = json_encode([
            'trxID' => $trxId,
        ], JSON_UNESCAPED_SLASHES);

        return $this->sendRequest('POST', '/tokenized/checkout/general/searchTransaction', $headers, $payload);
    }

    /**
     * Execute HTTP request via transport or stream context.
     */
    private function sendRequest(string $method, string $path, array $headers, string $body): array
    {
        $url = $this->baseUrl . $path;

        if ($this->transport !== null) {
            $raw = ($this->transport)($method, $url, $headers, $body);
            if (is_array($raw) && isset($raw['body'])) {
                $decoded = json_decode((string)$raw['body'], true);
                return is_array($decoded) ? $decoded : ['statusCode' => '9999', 'statusMessage' => 'Invalid JSON'];
            }
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : ['statusCode' => '9999', 'statusMessage' => 'Invalid JSON'];
            }
            if (is_array($raw)) {
                return $raw;
            }
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers) . "\r\n",
                'content'       => $body,
                'timeout'       => 20.0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            SafeLogger::error("bKash HTTP request failed", ['path' => $path]);
            return [
                'statusCode'    => '9999',
                'statusMessage' => 'Failed to connect to bKash API host.',
            ];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            return [
                'statusCode'    => '9999',
                'statusMessage' => 'Malformed JSON response from bKash API.',
            ];
        }

        return $decoded;
    }
}
