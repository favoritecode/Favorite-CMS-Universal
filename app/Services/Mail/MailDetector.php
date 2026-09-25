<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Mail;

/**
 * Universal, provider-agnostic mail detection and suggestion layer.
 *
 * Safely inspects current runtime environment, URL host, and optional MX hints
 * to provide sane default suggestions for sender address and SMTP settings
 * without assuming any single hosting provider or hardcoding platform logic.
 */
class MailDetector
{
    /**
     * Data-driven known provider profiles used ONLY as suggestion hints.
     */
    protected const PROVIDER_PROFILES = [
        'hostinger' => [
            'name'       => 'Hostinger',
            'mx_match'   => ['hostinger.com', 'hostingermail.com', 'titan.email', 'webhostbox.net'],
            'host'       => 'smtp.hostinger.com',
            'port'       => 465,
            'encryption' => 'ssl',
            'note'       => 'Detected potential Hostinger email service from domain/MX.',
        ],
        'google' => [
            'name'       => 'Google Workspace / Gmail',
            'mx_match'   => ['google.com', 'googlemail.com', 'aspmx.l.google.com'],
            'host'       => 'smtp.gmail.com',
            'port'       => 587,
            'encryption' => 'tls',
            'note'       => 'Detected Google Workspace mail service from MX records.',
        ],
        'microsoft' => [
            'name'       => 'Microsoft 365 / Outlook',
            'mx_match'   => ['outlook.com', 'office365.com', 'mail.protection.outlook.com'],
            'host'       => 'smtp.office365.com',
            'port'       => 587,
            'encryption' => 'tls',
            'note'       => 'Detected Microsoft 365 mail service from MX records.',
        ],
    ];

    /**
     * Suggest an authoritative outgoing sender email address from a site URL.
     *
     * @param string $siteUrl e.g. "https://example.com" or "http://localhost/sub"
     * @return string e.g. "noreply@example.com"
     */
    public static function suggestSenderEmail(string $siteUrl): string
    {
        $domain = static::extractDomain($siteUrl);
        if ($domain !== '' && filter_var("noreply@{$domain}", FILTER_VALIDATE_EMAIL)) {
            return "noreply@{$domain}";
        }

        return 'noreply@example.com';
    }

    /**
     * Suggest an outgoing sender display name.
     */
    public static function suggestSenderName(string $siteName): string
    {
        $name = trim($siteName);
        if ($name !== '' && strpbrk($name, "\r\n") === false) {
            return $name;
        }

        return 'Favorite CMS';
    }

    /**
     * Inspect runtime environment capabilities for email delivery.
     *
     * @return array<string, mixed>
     */
    public static function detectCapabilities(): array
    {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        $mailExists = function_exists('mail') && !in_array('mail', $disabled, true);
        $sendmailPath = (string)ini_get('sendmail_path');
        $socketsAvailable = function_exists('stream_socket_client');
        $opensslAvailable = extension_loaded('openssl');
        $dnsAvailable = function_exists('dns_get_record') || function_exists('getmxrr');

        return [
            'php_version'        => PHP_VERSION,
            'mail_available'     => $mailExists,
            'sendmail_path'      => $sendmailPath !== '' ? $sendmailPath : null,
            'sockets_available'  => $socketsAvailable,
            'openssl_available'  => $opensslAvailable,
            'dns_available'      => $dnsAvailable,
            'can_smtp'           => $socketsAvailable,
            'can_secure_smtp'    => $socketsAvailable && $opensslAvailable,
        ];
    }

    /**
     * Derive a suggested SMTP configuration profile for a given domain/URL.
     *
     * @param string $siteUrlOrDomain
     * @return array{host: string, port: int, encryption: string, source: string, note: string}
     */
    public static function detectSmtpProfile(string $siteUrlOrDomain): array
    {
        $domain = static::extractDomain($siteUrlOrDomain);
        if ($domain === '' || in_array(strtolower($domain), ['localhost', '127.0.0.1', '::1', 'example.com'], true)) {
            return [
                'host'       => 'localhost',
                'port'       => 25,
                'encryption' => 'none',
                'source'     => 'local',
                'note'       => 'Local environment detected. Using plain local SMTP default.',
            ];
        }

        // Try data-driven MX inspection if DNS queries are available and permitted
        $mxHosts = static::queryMxRecords($domain);
        if (!empty($mxHosts)) {
            foreach (static::PROVIDER_PROFILES as $profile) {
                foreach ($mxHosts as $mxHost) {
                    foreach ($profile['mx_match'] as $matchPattern) {
                        if (str_ends_with(strtolower($mxHost), strtolower($matchPattern))) {
                            return [
                                'host'       => $profile['host'],
                                'port'       => $profile['port'],
                                'encryption' => $profile['encryption'],
                                'source'     => 'mx_match',
                                'note'       => $profile['note'],
                            ];
                        }
                    }
                }
            }
        }

        // Default generic suggestion: smtp.<domain> (explicitly designated as a suggestion)
        return [
            'host'       => "smtp.{$domain}",
            'port'       => 587,
            'encryption' => 'tls',
            'source'     => 'domain_suggested',
            'note'       => "Suggested standard SMTP server for {$domain}. Verify with your hosting control panel.",
        ];
    }

    /**
     * Safely extract domain / hostname from URL or string.
     */
    public static function extractDomain(string $urlOrDomain): string
    {
        $clean = trim($urlOrDomain);
        if ($clean === '') {
            return '';
        }

        // If URL provided, parse host
        if (str_contains($clean, '://')) {
            $parsed = parse_url($clean, PHP_URL_HOST);
            $clean = is_string($parsed) ? $parsed : '';
        } else {
            // Strip any port or trailing slashes
            $clean = preg_replace('#[/\\?#].*$#', '', $clean);
            $clean = preg_replace('#:[0-9]+$#', '', (string)$clean);
        }

        // Strip leading www.
        $clean = preg_replace('/^www\./i', '', (string)$clean);
        return trim((string)$clean);
    }

    /**
     * Safely query MX records without throwing exceptions or timing out.
     *
     * @param string $domain
     * @return array<string>
     */
    protected static function queryMxRecords(string $domain): array
    {
        if (!function_exists('dns_get_record') && !function_exists('getmxrr')) {
            return [];
        }

        $hosts = [];

        try {
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($domain, DNS_MX);
                if (is_array($records)) {
                    foreach ($records as $r) {
                        if (!empty($r['target'])) {
                            $hosts[] = strtolower((string)$r['target']);
                        }
                    }
                }
            }

            if (empty($hosts) && function_exists('getmxrr')) {
                $mxhosts = [];
                if (@getmxrr($domain, $mxhosts) && is_array($mxhosts)) {
                    foreach ($mxhosts as $h) {
                        if (!empty($h)) {
                            $hosts[] = strtolower((string)$h);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique($hosts));
    }
}

