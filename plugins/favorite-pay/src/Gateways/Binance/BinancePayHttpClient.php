<?php

declare(strict_types=1);

namespace FavoriteCMS\Pay\Gateways\Binance;

use FavoriteCMS\Pay\Support\SafeLogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * BinancePayHttpClient
 *
 * Dedicated HTTP client for the Binance Pay Merchant OpenAPI.
 * Enforces Binance Pay request signing:
 *   payload = timestamp + "\n" + nonce + "\n" + body + "\n"
 *   signature = uppercase_hex(HMAC-SHA512(payload, secretKey))
 *
 * Fully supports custom transport injection for test mocking without network calls.
 */
class BinancePayHttpClient
{
    public const DEFAULT_BASE_URL = 'https://bpay.binanceapi.com';

    private ?string $certificateSn;
    private ?string $apiSecret;
    private string $baseUrl;

    /** @var callable|null */
    private $transport;

    public function __construct(
        ?string $certificateSn = null,
        ?string $apiSecret = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?callable $transport = null
    ) {
        $this->certificateSn = $certificateSn;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    public function setCredentials(string $certificateSn, string $apiSecret): void
    {
        $this->certificateSn = $certificateSn;
        $this->apiSecret = $apiSecret;
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

    /**
     * Generate cryptographically secure 32-character hexadecimal nonce.
     */
    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate current Unix timestamp in milliseconds.
     */
    public static function generateTimestamp(): string
    {
        return (string)(int)round(microtime(true) * 1000);
    }

    /**
     * Build the Binance Pay HMAC-SHA512 signature.
     * Payload: timestamp + "\n" + nonce + "\n" + body + "\n"
     * Signature: uppercase hexadecimal of HMAC-SHA512
     */
    public static function buildSignature(
        string $timestamp,
        string $nonce,
        string $body,
        string $secretKey
    ): string {
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        return strtoupper(hash_hmac('sha512', $payload, $secretKey));
    }

    /**
     * Execute an authenticated request to the Binance Pay OpenAPI.
     *
     * @param string $method HTTP method (POST, GET, etc.)
     * @param string $path Endpoint path starting with /
     * @param array $data JSON payload
     * @return array Decoded JSON response
     * @throws RuntimeException On transport or API errors
     */
    public function request(string $method, string $path, array $data = []): array
    {
        if (empty($this->certificateSn)) {
            throw new RuntimeException("Binance Pay certificate serial number (Certificate-SN) is not configured.");
        }

        if (empty($this->apiSecret)) {
            throw new RuntimeException("Binance Pay API secret key is not configured.");
        }

        $url = $this->baseUrl . $path;
        $jsonBody = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            throw new RuntimeException("Failed to JSON encode Binance Pay request body.");
        }

        // Generate timestamp and nonce immediately before signing
        $timestamp = self::generateTimestamp();
        $nonce = self::generateNonce();
        $signature = self::buildSignature($timestamp, $nonce, $jsonBody, $this->apiSecret);

        $headers = [
            'Content-Type'              => 'application/json',
            'BinancePay-Timestamp'      => $timestamp,
            'BinancePay-Nonce'          => $nonce,
            'BinancePay-Certificate-SN' => $this->certificateSn,
            'BinancePay-Signature'      => $signature,
        ];

        // Format header list for stream / curl
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        if ($this->transport !== null) {
            $rawResponse = ($this->transport)($method, $url, $headers, $jsonBody);
        } else {
            $rawResponse = $this->executeDefaultTransport($method, $url, $headerLines, $jsonBody);
        }

        $statusCode = (int)($rawResponse['statusCode'] ?? 0);
        $body = (string)($rawResponse['body'] ?? '');

        if ($statusCode === 0 || empty($body)) {
            SafeLogger::error("Binance Pay network transport failure.", [
                'path'       => $path,
                'status_code'=> $statusCode,
            ]);
            throw new RuntimeException("Binance Pay transport failure: No response or connection failed.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            SafeLogger::error("Binance Pay invalid JSON response.", [
                'path'        => $path,
                'status_code' => $statusCode,
            ]);
            throw new RuntimeException("Binance Pay returned an invalid non-JSON response (HTTP {$statusCode}).");
        }

        $status = $decoded['status'] ?? '';
        $code = $decoded['code'] ?? '';
        $errorMessage = $decoded['errorMessage'] ?? ($decoded['message'] ?? 'Unknown error');

        if ($status !== 'SUCCESS' && $code !== '000000') {
            SafeLogger::warning("Binance Pay API error returned.", [
                'path' => $path,
                'code' => $code,
                'status' => $status,
                'message' => $errorMessage,
            ]);
            throw new RuntimeException("Binance Pay error [{$code}]: {$errorMessage}");
        }

        return $decoded;
    }

    /**
     * Default transport using PHP stream context (safe HTTPS).
     */
    private function executeDefaultTransport(
        string $method,
        string $url,
        array $headerLines,
        string $body
    ): array {
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headerLines) . "\r\n",
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
        $statusCode = 0;

        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('/^HTTP\/\d\.\d\s+(\d{3})/', $hdr, $matches)) {
                    $statusCode = (int)$matches[1];
                }
            }
        }

        return [
            'statusCode' => $statusCode,
            'body'       => $responseBody !== false ? $responseBody : '',
        ];
    }
}
