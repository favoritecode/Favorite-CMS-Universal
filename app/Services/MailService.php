<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\Mail\GoogleOAuthService;
use FavoriteCMS\Services\Mail\SmtpTransport;

/**
 * Authoritative Centralized Core Mail Architecture for Favorite CMS Universal.
 *
 * Provides hosting-agnostic, RFC-compliant plain-text email delivery
 * supporting multiple transports:
 * - AUTO: Detects Google OAuth first, then Manual SMTP, falling back to native PHP mail()
 * - GOOGLE: Google Mail / Gmail OAuth 2.0 with SASL XOAUTH2 over TLS stream
 * - SMTP: Native RFC 5321 pure PHP stream transport (SSL/TLS, STARTTLS, Plain, AUTH LOGIN/PLAIN)
 * - MAIL: Native PHP mail() with envelope sender and RFC 822 headers
 *
 * All CMS outgoing email (user verification, password reset, admin recovery,
 * test email, and notifications) must route exclusively through this service.
 */
class MailService
{
    /**
     * Captured last transport diagnostic error message (safe string, no secrets).
     */
    protected static ?string $lastTransportError = null;

    /**
     * Captured last transport mechanism used ('smtp' or 'mail').
     */
    protected static ?string $lastTransportUsed = null;

    /**
     * Optional SmtpTransport instance override for testing/mocking.
     */
    public static ?SmtpTransport $smtpTransportOverride = null;

    /**
     * Send an email using the authoritative Core mail system.
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $message Email body text (plain text)
     * @param array<string, string> $headers Optional header overrides (e.g. 'Reply-To', 'From-Name')
     * @return bool True if successfully handed to the mail transport, false otherwise
     */
    public static function send(string $to, string $subject, string $message, array $headers = []): bool
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strpbrk($to, "\r\n\t") !== false) {
            static::$lastTransportError = 'Invalid recipient email address.';
            return false;
        }

        // 1. Resolve From display name (email.sender_name -> general.site_name -> 'Favorite CMS')
        $fromNameOverride = $headers['From-Name'] ?? $headers['from_name'] ?? null;
        $fromName = ($fromNameOverride !== null)
            ? str_replace(["\r", "\n"], '', trim((string)$fromNameOverride))
            : static::resolveFromName();

        // 2. Resolve From email address (email.sender_email -> general.admin_email -> config -> canonical host -> server host -> fallback)
        $fromEmailOverride = $headers['From-Email'] ?? $headers['from_email'] ?? null;
        $fromEmail = ($fromEmailOverride !== null && filter_var($fromEmailOverride, FILTER_VALIDATE_EMAIL) && strpbrk($fromEmailOverride, "\r\n\t") === false)
            ? trim($fromEmailOverride)
            : static::resolveFromEmail();

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strpbrk($fromEmail, "\r\n\t") !== false) {
            static::$lastTransportError = 'Invalid sender email address.';
            return false;
        }

        // 3. Resolve Reply-To address
        $replyTo = $headers['Reply-To'] ?? $headers['reply_to'] ?? $fromEmail;
        if (!filter_var($replyTo, FILTER_VALIDATE_EMAIL) || strpbrk($replyTo, "\r\n\t") !== false) {
            $replyTo = $fromEmail;
        }

        // 4. Format RFC-compliant From header
        $fromName = str_replace(["\r", "\n"], '', trim((string)$fromName));
        if ($fromName !== '') {
            $encodedName = preg_match('/[^\x20-\x7E]/', $fromName)
                ? '=?UTF-8?B?' . base64_encode($fromName) . '?='
                : '"' . addcslashes($fromName, '"\\') . '"';
            $formattedFrom = "{$encodedName} <{$fromEmail}>";
        } else {
            $formattedFrom = $fromEmail;
        }

        // 5. Clean and encode Subject line (prevent CRLF header injection)
        $cleanSubject = str_replace(["\r", "\n"], '', trim($subject));
        $encodedSubject = preg_match('/[^\x20-\x7E]/', $cleanSubject)
            ? '=?UTF-8?B?' . base64_encode($cleanSubject) . '?='
            : $cleanSubject;

        // 6. Assemble RFC-compliant headers
        $standardHeaders = [
            'From: ' . ($headers['From'] ?? $formattedFrom),
            'Reply-To: ' . $replyTo,
            'X-Mailer: Favorite CMS Universal',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        // 7. Check extension point / hook for plugin mail transport
        $intercepted = Hook::applyFilters('pre_send_mail', null, [
            'to'        => $to,
            'subject'   => $cleanSubject,
            'message'   => $message,
            'headers'   => $standardHeaders,
            'from'      => $fromEmail,
            'reply_to'  => $replyTo,
        ]);

        if ($intercepted !== null) {
            $result = (bool)$intercepted;
            static::$lastTransportUsed = 'hook';
            if ($result) {
                Hook::doAction('mail_sent', $to, $cleanSubject);
            } else {
                static::$lastTransportError = 'Mail delivery intercepted and cancelled by filter.';
                Hook::doAction('mail_failed', $to, $cleanSubject);
            }
            return $result;
        }

        // 8. Resolve target transport mode ('auto', 'google', 'smtp', 'mail')
        $configuredTransport = strtolower(trim((string)Setting::get('email', 'transport', 'auto')));
        if (!in_array($configuredTransport, ['auto', 'google', 'mail', 'smtp'], true)) {
            $configuredTransport = 'auto';
        }

        $targetTransport = $configuredTransport;
        if ($configuredTransport === 'auto') {
            if (GoogleOAuthService::isConnected()) {
                $targetTransport = 'google';
            } elseif (static::isSmtpConfigured()) {
                $targetTransport = 'smtp';
            } else {
                $targetTransport = 'mail';
            }
        }

        static::$lastTransportUsed = $targetTransport;
        static::$lastTransportError = null;

        $sent = false;

        if ($targetTransport === 'google') {
            $sent = static::sendViaGoogle($to, $cleanSubject, $encodedSubject, $message, $standardHeaders, $fromEmail);
        } elseif ($targetTransport === 'smtp') {
            $sent = static::sendViaSmtp($to, $cleanSubject, $encodedSubject, $message, $standardHeaders, $fromEmail);
        } else {
            $sent = static::sendViaPhpMail($to, $encodedSubject, $message, $standardHeaders, $fromEmail);
        }

        $recipientHash = substr(hash('sha256', strtolower(trim($to))), 0, 16);
        if ($sent) {
            error_log(sprintf(
                '[Favorite CMS] %s transport accepted delivery for recipient sha256:%s (from: %s)',
                strtoupper((string)static::$lastTransportUsed),
                $recipientHash,
                $fromEmail
            ));
            Hook::doAction('mail_sent', $to, $cleanSubject);
        } else {
            $reasonDetail = static::$lastTransportError ? ' - Reason: ' . static::$lastTransportError : '';
            error_log(sprintf(
                '[Favorite CMS] %s transport delivery failed for recipient sha256:%s (from: %s)%s',
                strtoupper((string)static::$lastTransportUsed),
                $recipientHash,
                $fromEmail,
                $reasonDetail
            ));
            Hook::doAction('mail_failed', $to, $cleanSubject);
        }

        return $sent;
    }

    /**
     * Dispatch email using Google Mail (Gmail OAuth 2.0 + XOAUTH2).
     *
     * @param string $to
     * @param string $rawSubject
     * @param string $encodedSubject
     * @param string $message
     * @param array<string> $standardHeaders
     * @param string $fromEmail
     * @return bool
     */
    protected static function sendViaGoogle(
        string $to,
        string $rawSubject,
        string $encodedSubject,
        string $message,
        array $standardHeaders,
        string $fromEmail
    ): bool {
        if (!GoogleOAuthService::isConnected()) {
            static::$lastTransportError = 'Google Mail is not connected. Please authenticate with Google in Email Settings.';
            return false;
        }

        try {
            $smtp = static::$smtpTransportOverride ?? static::buildGoogleSmtpTransport();
            $success = $smtp->send($to, $encodedSubject, $message, $standardHeaders, $fromEmail);
            if (!$success) {
                static::$lastTransportError = $smtp->getLastError() ?: 'Google SMTP server rejected message delivery.';
            }
            return $success;
        } catch (\Throwable $e) {
            static::$lastTransportError = 'Google Mail error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Dispatch email using native SmtpTransport.
     *
     * @param string $to
     * @param string $rawSubject
     * @param string $encodedSubject
     * @param string $message
     * @param array<string> $standardHeaders
     * @param string $fromEmail
     * @return bool
     */
    protected static function sendViaSmtp(
        string $to,
        string $rawSubject,
        string $encodedSubject,
        string $message,
        array $standardHeaders,
        string $fromEmail
    ): bool {
        try {
            $smtp = static::$smtpTransportOverride ?? static::buildSmtpTransport();
            $success = $smtp->send($to, $encodedSubject, $message, $standardHeaders, $fromEmail);
            if (!$success) {
                static::$lastTransportError = $smtp->getLastError() ?: 'SMTP server rejected message delivery.';
            }
            return $success;
        } catch (\Throwable $e) {
            static::$lastTransportError = 'SMTP error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Dispatch email using native PHP mail().
     *
     * @param string $to
     * @param string $encodedSubject
     * @param string $message
     * @param array<string> $standardHeaders
     * @param string $fromEmail
     * @return bool
     */
    protected static function sendViaPhpMail(
        string $to,
        string $encodedSubject,
        string $message,
        array $standardHeaders,
        string $fromEmail
    ): bool {
        $disabledFuncs = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!function_exists('mail') || in_array('mail', $disabledFuncs, true)) {
            static::$lastTransportError = 'PHP mail() function is disabled on this server.';
            return false;
        }

        $headerString = implode("\r\n", $standardHeaders);
        $sent = false;

        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        try {
            if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) && strpbrk($fromEmail, "\r\n\t") === false) {
                $sent = @mail($to, $encodedSubject, $message, $headerString, "-f" . $fromEmail);
            }
            if (!$sent) {
                $sent = @mail($to, $encodedSubject, $message, $headerString);
            }
            if (!$sent) {
                // Safe basic header fallback for strict environments
                $sent = @mail($to, $encodedSubject, $message, "From: {$fromEmail}\r\nContent-Type: text/plain; charset=UTF-8");
            }
        } catch (\Throwable $e) {
            $sent = false;
            static::$lastTransportError = $e->getMessage();
        }

        if (!$sent && static::$lastTransportError === null) {
            $lastErr = error_get_last();
            if (!empty($lastErr['message'])) {
                $cleanMsg = preg_replace('/in\s+\S+\s+on\s+line\s+\d+/i', '', (string)$lastErr['message']);
                static::$lastTransportError = trim($cleanMsg);
            } else {
                static::$lastTransportError = 'PHP mail transport failed (local MTA not found or connection refused).';
            }
        }

        return $sent;
    }

    /**
     * Check whether SMTP settings are sufficiently configured to be usable.
     */
    public static function isSmtpConfigured(): bool
    {
        $host = trim((string)Setting::get('email', 'smtp_host', ''));
        if ($host === '') {
            return false;
        }

        $username = trim((string)Setting::get('email', 'smtp_username', ''));
        $password = (string)Setting::get('email', 'smtp_password', '');

        // If host is localhost/local relay, credentials may be optional
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        // For remote SMTP, both username and password must be configured
        return $username !== '' && $password !== '';
    }

    /**
     * Build an SmtpTransport instance from stored database settings.
     */
    public static function buildSmtpTransport(): SmtpTransport
    {
        $host = trim((string)Setting::get('email', 'smtp_host', ''));
        if ($host === '') {
            throw new \RuntimeException('SMTP host is not configured.');
        }
        $port = (int)Setting::get('email', 'smtp_port', 587);
        $encryption = (string)Setting::get('email', 'smtp_encryption', SmtpTransport::ENCRYPTION_TLS);
        $username = trim((string)Setting::get('email', 'smtp_username', ''));
        $password = (string)Setting::get('email', 'smtp_password', '');
        $timeout = (int)Setting::get('email', 'smtp_timeout', 15);

        return new SmtpTransport(
            host: $host,
            port: $port > 0 ? $port : 587,
            encryption: $encryption,
            username: $username,
            password: $password,
            timeout: $timeout > 0 ? $timeout : 15
        );
    }

    /**
     * Check whether Google Mail OAuth is connected.
     */
    public static function isGoogleConfigured(): bool
    {
        return GoogleOAuthService::isConnected();
    }

    /**
     * Build an SmtpTransport instance configured for Google OAuth XOAUTH2.
     */
    public static function buildGoogleSmtpTransport(): SmtpTransport
    {
        if (!GoogleOAuthService::isConnected()) {
            throw new \RuntimeException('Google Mail is not connected.');
        }

        $token = GoogleOAuthService::getValidAccessToken();
        if ($token === null || $token === '') {
            throw new \RuntimeException('Failed to obtain a valid Google access token. Please reconnect your Google account.');
        }

        $accountEmail = GoogleOAuthService::getAccountEmail();
        if ($accountEmail === '') {
            throw new \RuntimeException('Connected Google account has no associated email address.');
        }

        $timeout = (int)Setting::get('email', 'smtp_timeout', 15);

        return new SmtpTransport(
            host: 'smtp.gmail.com',
            port: 587,
            encryption: SmtpTransport::ENCRYPTION_TLS,
            username: $accountEmail,
            password: '',
            timeout: $timeout > 0 ? $timeout : 15,
            authMode: SmtpTransport::AUTH_XOAUTH2,
            oauthToken: $token
        );
    }

    /**
     * Resolve the authoritative From display name for outgoing system emails.
     * Priority:
     * 1. email.sender_name
     * 2. general.site_name
     * 3. 'Favorite CMS'
     */
    public static function resolveFromName(): string
    {
        $senderName = trim((string)Setting::get('email', 'sender_name', ''));
        if ($senderName !== '' && strpbrk($senderName, "\r\n") === false) {
            return $senderName;
        }

        $siteName = trim((string)Setting::get('general', 'site_name', ''));
        if ($siteName !== '' && strpbrk($siteName, "\r\n") === false) {
            return $siteName;
        }

        $appName = trim((string)config('app.name', 'Favorite CMS'));
        if ($appName !== '' && strpbrk($appName, "\r\n") === false) {
            return $appName;
        }

        return 'Favorite CMS';
    }

    /**
     * Resolve the authoritative From email address for outgoing system emails.
     * Priority:
     * 1. email.sender_email
     * 2. general.admin_email
     * 3. config('mail.from.address')
     * 4. Dynamic host fallback (canonical site_url / server host)
     */
    public static function resolveFromEmail(): string
    {
        // 1. Configured Sender Email in database settings (Admin -> Settings -> Email)
        $senderEmail = trim((string)Setting::get('email', 'sender_email', ''));
        if (filter_var($senderEmail, FILTER_VALIDATE_EMAIL) && strpbrk($senderEmail, "\r\n\t") === false) {
            return $senderEmail;
        }

        // 2. Original installation Admin Email in database settings
        $adminEmail = trim((string)Setting::get('general', 'admin_email', ''));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) && strpbrk($adminEmail, "\r\n\t") === false) {
            return $adminEmail;
        }

        // 3. Explicit config('mail.from.address')
        $configEmail = trim((string)config('mail.from.address', ''));
        if (filter_var($configEmail, FILTER_VALIDATE_EMAIL) && strpbrk($configEmail, "\r\n\t") === false) {
            return $configEmail;
        }

        // 4. Fallback derived from canonical site URL or request host
        $siteUrl = (string)Setting::get('general', 'site_url', '');
        $host = parse_url($siteUrl, PHP_URL_HOST);
        if (!$host && !empty($_SERVER['SERVER_NAME'])) {
            $host = (string)$_SERVER['SERVER_NAME'];
        }
        if (!$host && !empty($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/:[0-9]+$/', '', (string)$_SERVER['HTTP_HOST']);
        }
        $host = $host ? preg_replace('/^www\./i', '', (string)$host) : '';

        if ($host !== '' && filter_var('noreply@' . $host, FILTER_VALIDATE_EMAIL)) {
            return 'noreply@' . $host;
        }

        return 'noreply@example.com';
    }

    /**
     * Retrieve the last transport error message if any.
     */
    public static function getLastTransportError(): ?string
    {
        return static::$lastTransportError;
    }

    /**
     * Retrieve the last transport mechanism used ('smtp' or 'mail').
     */
    public static function getLastTransportUsed(): ?string
    {
        return static::$lastTransportUsed;
    }

    /**
     * Safe runtime transport diagnostics for administrative troubleshooting.
     * Never exposes secrets, passwords, tokens, or email bodies.
     *
     * @return array<string, mixed>
     */
    public static function getTransportDiagnostics(): array
    {
        $sendmailPath = (string)ini_get('sendmail_path');
        $smtpIni = (string)ini_get('SMTP');
        $smtpPortIni = (string)ini_get('smtp_port');
        $disabledFuncs = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        $mailDisabled = in_array('mail', $disabledFuncs, true);

        $configuredTransport = strtolower(trim((string)Setting::get('email', 'transport', 'auto')));
        if (!in_array($configuredTransport, ['auto', 'google', 'mail', 'smtp'], true)) {
            $configuredTransport = 'auto';
        }

        $resolvedTransport = $configuredTransport;
        if ($configuredTransport === 'auto') {
            if (GoogleOAuthService::isConnected()) {
                $resolvedTransport = 'google';
            } elseif (static::isSmtpConfigured()) {
                $resolvedTransport = 'smtp';
            } else {
                $resolvedTransport = 'mail';
            }
        }

        $smtpHost = (string)Setting::get('email', 'smtp_host', '');
        $smtpPort = (int)Setting::get('email', 'smtp_port', 587);
        $smtpEnc  = (string)Setting::get('email', 'smtp_encryption', 'tls');
        $smtpUser = (string)Setting::get('email', 'smtp_username', '');
        $smtpPass = (string)Setting::get('email', 'smtp_password', '');
        $smtpTimeout = (int)Setting::get('email', 'smtp_timeout', 15);

        $googleConnected = GoogleOAuthService::isConnected();
        $googleEmail = GoogleOAuthService::getAccountEmail();
        $senderEmail = static::resolveFromEmail();
        $googleSendAsMismatch = $googleConnected && ($googleEmail !== '') && (strtolower(trim($googleEmail)) !== strtolower(trim($senderEmail)));

        return [
            'transport_mode'            => $configuredTransport,
            'resolved_transport'        => $resolvedTransport,
            'last_transport_used'       => static::$lastTransportUsed,
            'last_transport_error'      => static::$lastTransportError,
            'php_version'               => PHP_VERSION,
            'mail_function_exists'      => function_exists('mail'),
            'mail_disabled'             => $mailDisabled,
            'sendmail_path'             => $sendmailPath !== '' ? $sendmailPath : null,
            'smtp'                      => $smtpIni !== '' ? $smtpIni : null,
            'smtp_port'                 => $smtpPortIni !== '' ? $smtpPortIni : null,
            'smtp_configured'           => static::isSmtpConfigured(),
            'smtp_host'                 => $smtpHost !== '' ? $smtpHost : null,
            'smtp_port_configured'      => $smtpPort > 0 ? $smtpPort : 587,
            'smtp_encryption'           => $smtpEnc,
            'smtp_username'             => $smtpUser !== '' ? $smtpUser : null,
            'smtp_password_configured'  => $smtpPass !== '',
            'smtp_timeout'              => $smtpTimeout > 0 ? $smtpTimeout : 15,
            'google_configured'         => $googleConnected,
            'google_connected'          => $googleConnected,
            'google_account_email'      => $googleEmail !== '' ? $googleEmail : null,
            'google_token_expired'      => $googleConnected ? GoogleOAuthService::isTokenExpired() : null,
            'google_send_as_match'      => !$googleSendAsMismatch,
            'google_send_as_advisory'   => $googleSendAsMismatch
                ? sprintf('Sender email (%s) differs from connected Google account email (%s). Gmail may rewrite From addresses unless configured as an alias in Gmail settings.', $senderEmail, $googleEmail)
                : null,
            'sender_name'               => static::resolveFromName(),
            'sender_email'              => $senderEmail,
            'sender_email_valid'        => filter_var($senderEmail, FILTER_VALIDATE_EMAIL) !== false,
            'admin_email'               => (string)Setting::get('general', 'admin_email', ''),
        ];
    }
}
