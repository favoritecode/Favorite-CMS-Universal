<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Mail;

use Closure;
use RuntimeException;
use InvalidArgumentException;

/**
 * Robust, secure, RFC 5321 compliant native PHP SMTP transport.
 *
 * Implements standard SMTP communication using PHP streams with:
 * - Implicit SSL/TLS (port 465)
 * - Explicit STARTTLS (port 587/25) with crypto negotiation
 * - Plain/Local SMTP (port 25/1025)
 * - Strict TLS certificate verification
 * - Dynamic AUTH capability detection (AUTH LOGIN and AUTH PLAIN)
 * - Dot-stuffing and CRLF normalization
 * - Safe diagnostics without credential leakage
 * - Test connection capability (without sending email)
 * - Stream factory hook for mock test isolation
 */
class SmtpTransport
{
    public const ENCRYPTION_NONE = 'none';
    public const ENCRYPTION_SSL  = 'ssl';
    public const ENCRYPTION_TLS  = 'tls';

    public const AUTH_AUTO    = 'auto';
    public const AUTH_LOGIN   = 'login';
    public const AUTH_PLAIN   = 'plain';
    public const AUTH_XOAUTH2 = 'xoauth2';

    /**
     * Optional custom stream factory for automated testing / mocking.
     * Signature: fn(string $remoteSocket, int &$errno, string &$errstr, float $timeout, int $flags, $context)
     */
    public static ?Closure $streamFactory = null;

    protected string $host;
    protected int $port;
    protected string $encryption;
    protected string $username;
    protected string $password;
    protected int $timeout;
    protected string $authMode;
    protected string $oauthToken;
    protected ?string $lastError = null;
    protected array $capabilities = [];

    public function __construct(
        string $host,
        int $port = 587,
        string $encryption = self::ENCRYPTION_TLS,
        string $username = '',
        string $password = '',
        int $timeout = 15,
        string $authMode = self::AUTH_AUTO,
        string $oauthToken = ''
    ) {
        $this->host = trim($host);
        $this->port = $port;
        $this->encryption = strtolower(trim($encryption));
        if ($this->encryption === 'starttls') {
            $this->encryption = self::ENCRYPTION_TLS;
        }
        $this->username = trim($username);
        $this->password = $password;
        $this->timeout = max(1, min(120, $timeout));
        $this->authMode = strtolower(trim($authMode));
        if (!in_array($this->authMode, [self::AUTH_AUTO, self::AUTH_LOGIN, self::AUTH_PLAIN, self::AUTH_XOAUTH2], true)) {
            $this->authMode = self::AUTH_AUTO;
        }
        $this->oauthToken = trim($oauthToken);

        $this->validateConfiguration();
    }

    /**
     * Validate configuration parameters to guard against CRLF injection and invalid inputs.
     */
    protected function validateConfiguration(): void
    {
        if ($this->host === '') {
            throw new InvalidArgumentException('SMTP host cannot be empty.');
        }

        if (strpbrk($this->host, "\r\n\t") !== false) {
            throw new InvalidArgumentException('CRLF injection detected in SMTP host.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException("Invalid SMTP port '{$this->port}'. Must be between 1 and 65535.");
        }

        if (!in_array($this->encryption, [self::ENCRYPTION_NONE, self::ENCRYPTION_SSL, self::ENCRYPTION_TLS], true)) {
            throw new InvalidArgumentException("Invalid SMTP encryption '{$this->encryption}'. Allowed: none, ssl, tls.");
        }

        if (strpbrk($this->username, "\r\n\t") !== false) {
            throw new InvalidArgumentException('CRLF injection detected in SMTP username.');
        }

        if (strpbrk($this->password, "\r\n") !== false) {
            throw new InvalidArgumentException('CRLF injection detected in SMTP password.');
        }

        if (strpbrk($this->oauthToken, "\r\n") !== false) {
            throw new InvalidArgumentException('CRLF injection detected in OAuth token.');
        }
    }

    public function getAuthMode(): string
    {
        return $this->authMode;
    }

    public function getOAuthToken(): string
    {
        return $this->oauthToken;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getEncryption(): string
    {
        return $this->encryption;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Test connection to the SMTP server without dispatching an email.
     * Performs connection, TLS upgrade, EHLO, and authentication check if credentials are provided.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $this->lastError = null;
        $socket = null;

        try {
            $socket = $this->connect();
            $this->readExpectedResponse($socket, [220], 'Connection banner');

            $this->performHandshake($socket);

            $hasAuth = ($this->authMode === self::AUTH_XOAUTH2 || $this->oauthToken !== '')
                ? ($this->username !== '' && $this->oauthToken !== '')
                : ($this->username !== '' && $this->password !== '');

            if ($hasAuth) {
                $this->authenticate($socket);
            }

            $this->sendCommand($socket, 'QUIT', [221], 'QUIT');
            $this->closeSocket($socket);

            return [
                'success' => true,
                'message' => 'SMTP connection and handshake successful.',
                'details' => [
                    'host'          => $this->host,
                    'port'          => $this->port,
                    'encryption'    => $this->encryption,
                    'capabilities'  => array_keys($this->capabilities),
                    'auth_mode'     => $this->authMode,
                    'authenticated' => $hasAuth,
                ],
            ];
        } catch (\Throwable $e) {
            if (is_resource($socket)) {
                $this->closeSocket($socket);
            }
            $cleanError = $this->sanitizeError($e->getMessage());
            $this->lastError = $cleanError;

            return [
                'success' => false,
                'message' => "SMTP connection failed: {$cleanError}",
                'details' => [
                    'host'       => $this->host,
                    'port'       => $this->port,
                    'encryption' => $this->encryption,
                ],
            ];
        }
    }

    /**
     * Send an email over SMTP.
     *
     * @param string $to Recipient email
     * @param string $encodedSubject Clean/encoded subject line
     * @param string $message Plain text email body
     * @param array<string> $standardHeaders Formatted RFC headers
     * @param string $fromEmail Envelope sender / From address
     * @return bool
     */
    public function send(
        string $to,
        string $encodedSubject,
        string $message,
        array $standardHeaders,
        string $fromEmail
    ): bool {
        $this->lastError = null;
        $socket = null;

        $to = trim($to);
        $fromEmail = trim($fromEmail);

        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strpbrk($to, "\r\n\t") !== false) {
            $this->lastError = 'Invalid recipient email address.';
            return false;
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strpbrk($fromEmail, "\r\n\t") !== false) {
            $this->lastError = 'Invalid sender email address.';
            return false;
        }

        try {
            $socket = $this->connect();
            $this->readExpectedResponse($socket, [220], 'Connection banner');

            $this->performHandshake($socket);

            $hasAuth = ($this->authMode === self::AUTH_XOAUTH2 || $this->oauthToken !== '')
                ? ($this->username !== '' && $this->oauthToken !== '')
                : ($this->username !== '' && $this->password !== '');

            if ($hasAuth) {
                $this->authenticate($socket);
            }

            // 1. MAIL FROM
            $this->sendCommand($socket, "MAIL FROM:<{$fromEmail}>", [250], 'MAIL FROM');

            // 2. RCPT TO
            $this->sendCommand($socket, "RCPT TO:<{$to}>", [250, 251], 'RCPT TO');

            // 3. DATA
            $this->sendCommand($socket, 'DATA', [354], 'DATA');

            // 4. Assemble Message Payload
            $payload = $this->buildMessagePayload($to, $encodedSubject, $message, $standardHeaders);

            // Send payload terminated by \r\n.\r\n
            $this->write($socket, $payload . "\r\n.\r\n");
            $this->readExpectedResponse($socket, [250], 'Message body transmission');

            // 5. QUIT
            try {
                $this->sendCommand($socket, 'QUIT', [221], 'QUIT');
            } catch (\Throwable) {
                // Non-fatal if server closes immediately
            }

            $this->closeSocket($socket);
            return true;
        } catch (\Throwable $e) {
            if (is_resource($socket)) {
                $this->closeSocket($socket);
            }
            $cleanError = $this->sanitizeError($e->getMessage());
            $this->lastError = $cleanError;
            return false;
        }
    }

    /**
     * Connect to the remote SMTP server using PHP streams.
     * Enforces certificate verification for TLS/SSL connections.
     *
     * @return resource
     */
    protected function connect()
    {
        $contextOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ],
        ];

        $context = stream_context_create($contextOptions);
        $protocol = ($this->encryption === self::ENCRYPTION_SSL) ? 'ssl://' : 'tcp://';
        $remoteSocket = $protocol . $this->host . ':' . $this->port;

        $errno = 0;
        $errstr = '';

        if (static::$streamFactory !== null) {
            $socket = (static::$streamFactory)(
                $remoteSocket,
                $errno,
                $errstr,
                (float)$this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            $socket = @stream_socket_client(
                $remoteSocket,
                $errno,
                $errstr,
                (float)$this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
        }

        if (!$socket || !is_resource($socket)) {
            $errorMsg = $errstr ?: "Failed to connect to SMTP server at {$this->host}:{$this->port}";
            if ($errno !== 0) {
                $errorMsg .= " (Code {$errno})";
            }
            throw new RuntimeException($errorMsg);
        }

        stream_set_timeout($socket, $this->timeout);
        return $socket;
    }

    /**
     * Perform initial EHLO/HELO and STARTTLS negotiation.
     *
     * @param resource $socket
     */
    protected function performHandshake($socket): void
    {
        $clientHost = $this->resolveClientHostname();

        // 1. EHLO
        $this->write($socket, "EHLO {$clientHost}\r\n");
        $ehloLines = $this->readResponse($socket);

        if (!isset($ehloLines[0]) || !str_starts_with($ehloLines[0], '250')) {
            // Fallback to HELO if EHLO rejected with 500 or 502
            $this->write($socket, "HELO {$clientHost}\r\n");
            $this->readExpectedResponse($socket, [250], 'HELO');
            $this->capabilities = [];
        } else {
            $this->parseCapabilities($ehloLines);
        }

        // 2. Explicit STARTTLS
        if ($this->encryption === self::ENCRYPTION_TLS) {
            if (!$this->hasCapability('STARTTLS')) {
                // If server doesn't advertise STARTTLS, try sending command anyway
            }

            $this->sendCommand($socket, 'STARTTLS', [220], 'STARTTLS');

            // Enable TLS crypto on stream
            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }

            $cryptoSuccess = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            if ($cryptoSuccess !== true) {
                throw new RuntimeException('TLS cryptographic negotiation failed. Check server certificate validity and TLS version.');
            }

            // RFC 3207 section 4.2: Must reissue EHLO after STARTTLS
            $this->write($socket, "EHLO {$clientHost}\r\n");
            $postTlsLines = $this->readExpectedResponse($socket, [250], 'post-STARTTLS EHLO');
            $this->parseCapabilities($postTlsLines);
        }
    }

    /**
     * Authenticate against the SMTP server using supported mechanism.
     *
     * @param resource $socket
     */
    protected function authenticate($socket): void
    {
        if ($this->authMode === self::AUTH_XOAUTH2 || ($this->authMode === self::AUTH_AUTO && $this->oauthToken !== '')) {
            $this->authXOAuth2($socket);
            return;
        }

        $authCaps = $this->getAuthMechanisms();

        if ($this->authMode === self::AUTH_PLAIN) {
            $this->authPlain($socket);
            return;
        }

        if ($this->authMode === self::AUTH_LOGIN) {
            $this->authLogin($socket);
            return;
        }

        // Decide mechanism: prefer LOGIN if supported or if generic; fallback to PLAIN
        if (in_array('LOGIN', $authCaps, true) || empty($authCaps)) {
            $this->authLogin($socket);
        } elseif (in_array('PLAIN', $authCaps, true)) {
            $this->authPlain($socket);
        } else {
            throw new RuntimeException('No supported SMTP authentication mechanism advertised by server (expected LOGIN or PLAIN).');
        }
    }

    /**
     * AUTH XOAUTH2 implementation (RFC 7628 / Google OAuth SMTP).
     *
     * @param resource $socket
     */
    protected function authXOAuth2($socket): void
    {
        $payload = base64_encode("user={$this->username}\x01auth=Bearer {$this->oauthToken}\x01\x01");
        $this->write($socket, "AUTH XOAUTH2 {$payload}\r\n");
        $lines = $this->readResponse($socket);

        if (empty($lines)) {
            throw new RuntimeException('AUTH XOAUTH2: Server closed connection unexpectedly.');
        }

        $firstLine = $lines[0];
        $code = (int)substr($firstLine, 0, 3);

        if ($code === 235) {
            return;
        }

        if ($code === 334) {
            // Google SASL error challenge (base64 encoded JSON)
            $challengeData = trim(substr($firstLine, 4));
            $decodedChallenge = @base64_decode($challengeData);

            // Acknowledge the error challenge with an empty line per RFC 7628
            $this->write($socket, "\r\n");
            $terminalLines = $this->readResponse($socket);
            $terminalMsg = !empty($terminalLines) ? $terminalLines[0] : 'Authentication failed';

            $errDetail = '';
            if ($decodedChallenge !== false && $decodedChallenge !== '') {
                $json = @json_decode($decodedChallenge, true);
                if (is_array($json) && !empty($json['status'])) {
                    $errDetail = " (status: {$json['status']})";
                }
            }

            $safeTerminal = $this->sanitizeError($terminalMsg);
            throw new RuntimeException("AUTH XOAUTH2 failed: {$safeTerminal}{$errDetail}");
        }

        $safeLine = $this->sanitizeError($firstLine);
        throw new RuntimeException("AUTH XOAUTH2 rejected by SMTP server (code {$code}): {$safeLine}");
    }

    /**
     * AUTH LOGIN implementation.
     *
     * @param resource $socket
     */
    protected function authLogin($socket): void
    {
        $this->sendCommand($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN initiation');
        $this->sendCommand($socket, base64_encode($this->username), [334], 'AUTH LOGIN username');
        $this->sendCommand($socket, base64_encode($this->password), [235], 'AUTH LOGIN password');
    }

    /**
     * AUTH PLAIN implementation.
     *
     * @param resource $socket
     */
    protected function authPlain($socket): void
    {
        $credentials = "\0" . $this->username . "\0" . $this->password;
        $encoded = base64_encode($credentials);
        $this->sendCommand($socket, "AUTH PLAIN {$encoded}", [235], 'AUTH PLAIN');
    }

    /**
     * Parse capabilities from 250 multiline EHLO response.
     *
     * @param array<string> $lines
     */
    protected function parseCapabilities(array $lines): void
    {
        $this->capabilities = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^250[ -]([A-Za-z0-9_-]+)(?:[ =](.*))?$/', $line, $matches)) {
                $cap = strtoupper($matches[1]);
                $param = $matches[2] ?? '';
                $this->capabilities[$cap] = trim($param);
            }
        }
    }

    public function hasCapability(string $cap): bool
    {
        return isset($this->capabilities[strtoupper($cap)]);
    }

    /**
     * Get advertised AUTH mechanisms (e.g. ['LOGIN', 'PLAIN']).
     *
     * @return array<string>
     */
    public function getAuthMechanisms(): array
    {
        $mechanisms = [];
        foreach (['AUTH', 'AUTH='] as $key) {
            if (isset($this->capabilities[$key])) {
                $parts = preg_split('/\s+/', strtoupper($this->capabilities[$key]));
                if (is_array($parts)) {
                    foreach ($parts as $p) {
                        if ($p !== '') {
                            $mechanisms[] = $p;
                        }
                    }
                }
            }
        }
        return array_values(array_unique($mechanisms));
    }

    /**
     * Send a single SMTP command and verify response code.
     *
     * @param resource $socket
     * @param string $command
     * @param array<int> $expectedCodes
     * @param string $context
     * @return array<string>
     */
    protected function sendCommand($socket, string $command, array $expectedCodes, string $context): array
    {
        $this->write($socket, $command . "\r\n");
        return $this->readExpectedResponse($socket, $expectedCodes, $context);
    }

    /**
     * Read lines from socket and ensure the first line matches expected code.
     *
     * @param resource $socket
     * @param array<int> $expectedCodes
     * @param string $context
     * @return array<string>
     */
    protected function readExpectedResponse($socket, array $expectedCodes, string $context): array
    {
        $lines = $this->readResponse($socket);
        if (empty($lines)) {
            throw new RuntimeException("{$context}: Server closed connection unexpectedly (no response).");
        }

        $firstLine = $lines[0];
        $code = (int)substr($firstLine, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            $safeLine = $this->sanitizeError($firstLine);
            throw new RuntimeException("{$context} rejected by SMTP server (code {$code}): {$safeLine}");
        }

        return $lines;
    }

    /**
     * Read full multiline response from socket per RFC 5321 (4.2.1).
     *
     * @param resource $socket
     * @return array<string>
     */
    protected function readResponse($socket): array
    {
        $lines = [];

        while (!feof($socket)) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                break;
            }

            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException("SMTP connection timed out after {$this->timeout}s while waiting for server response.");
            }

            $trimmed = rtrim($line, "\r\n");
            $lines[] = $trimmed;

            // RFC 5321: If 4th char is a space or line ends at 3 chars, response is complete
            if (strlen($trimmed) >= 3 && is_numeric(substr($trimmed, 0, 3))) {
                if (strlen($trimmed) === 3 || $trimmed[3] === ' ') {
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * Write data to socket stream with timeout and error checking.
     *
     * @param resource $socket
     * @param string $data
     */
    protected function write($socket, string $data): void
    {
        $totalBytes = strlen($data);
        $writtenTotal = 0;

        while ($writtenTotal < $totalBytes) {
            $written = @fwrite($socket, substr($data, $writtenTotal));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException("SMTP socket write timed out after {$this->timeout}s.");
                }
                throw new RuntimeException('Failed to write data to SMTP socket.');
            }
            $writtenTotal += $written;
        }
    }

    /**
     * Close socket resource safely.
     *
     * @param resource $socket
     */
    protected function closeSocket($socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    /**
     * Build RFC 5321 / RFC 5322 compliant message payload with dot-stuffing and CRLF line endings.
     *
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param array<string> $standardHeaders
     * @return string
     */
    protected function buildMessagePayload(
        string $to,
        string $subject,
        string $message,
        array $standardHeaders
    ): string {
        $headers = [];

        // Check if To and Subject already present in standardHeaders
        $hasTo = false;
        $hasSubject = false;
        foreach ($standardHeaders as $header) {
            if (stripos($header, 'To:') === 0) {
                $hasTo = true;
            }
            if (stripos($header, 'Subject:') === 0) {
                $hasSubject = true;
            }
            $headers[] = $header;
        }

        if (!$hasTo) {
            array_unshift($headers, 'To: ' . $to);
        }
        if (!$hasSubject) {
            $headers[] = 'Subject: ' . $subject;
        }

        // Date header if missing
        $hasDate = false;
        foreach ($headers as $h) {
            if (stripos($h, 'Date:') === 0) {
                $hasDate = true;
                break;
            }
        }
        if (!$hasDate) {
            $headers[] = 'Date: ' . date('r');
        }

        $headerBlock = implode("\r\n", $headers);

        // Normalize body line endings to \r\n
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $message);
        $bodyLines = explode("\n", $normalizedBody);

        // Dot-stuffing per RFC 5321 (4.5.2): line starting with '.' must be prepended with another '.'
        $stuffedLines = [];
        foreach ($bodyLines as $line) {
            if (str_starts_with($line, '.')) {
                $stuffedLines[] = '.' . $line;
            } else {
                $stuffedLines[] = $line;
            }
        }
        $bodyBlock = implode("\r\n", $stuffedLines);

        return $headerBlock . "\r\n\r\n" . $bodyBlock;
    }

    /**
     * Resolve the client hostname for EHLO/HELO identification.
     */
    protected function resolveClientHostname(): string
    {
        $host = '';
        if (!empty($_SERVER['SERVER_NAME'])) {
            $host = (string)$_SERVER['SERVER_NAME'];
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/:[0-9]+$/', '', (string)$_SERVER['HTTP_HOST']);
        }

        $host = trim(preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)$host));
        if ($host !== '' && str_contains($host, '.')) {
            return $host;
        }

        return 'localhost.localdomain';
    }

    /**
     * Sanitize error message to ensure no credentials or sensitive tokens are exposed.
     */
    protected function sanitizeError(string $raw): string
    {
        $clean = $raw;

        if ($this->password !== '') {
            $clean = str_replace($this->password, '***', $clean);
            $clean = str_replace(base64_encode($this->password), '***', $clean);
        }

        if ($this->oauthToken !== '') {
            $clean = str_replace($this->oauthToken, '***', $clean);
            $clean = str_replace(base64_encode($this->oauthToken), '***', $clean);
            $clean = str_replace(base64_encode("user={$this->username}\x01auth=Bearer {$this->oauthToken}\x01\x01"), '***', $clean);
        }

        if ($this->username !== '') {
            $clean = str_replace(base64_encode($this->username), '***', $clean);
        }

        // Strip file paths if present
        $clean = preg_replace('/in\s+\S+\s+on\s+line\s+\d+/i', '', $clean);

        return trim((string)$clean);
    }
}

