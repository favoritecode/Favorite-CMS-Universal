<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Import\Security;

use InvalidArgumentException;

class SsrfGuard
{
    /**
     * Allowed URI schemes.
     */
    protected const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Allowed network ports for media download.
     */
    protected const ALLOWED_PORTS = [80, 443, 8080, 8443];

    /**
     * Reserved/local domain suffixes.
     */
    protected const BLOCKED_HOST_SUFFIXES = [
        '.localhost',
        '.local',
        '.internal',
        '.lan',
        '.home',
        '.corp',
        '.test',
        '.example',
        '.invalid',
    ];

    /**
     * Exact blocked hostnames.
     */
    protected const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'broadcasthost',
        'metadata.google.internal',
        'instance-data',
    ];

    /**
     * Check whether a given URL is safe for server-side fetching.
     */
    public static function isUrlSafe(string $url): bool
    {
        try {
            self::assertUrlSafe($url);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Validate that a given URL is safe, throwing an InvalidArgumentException if SSRF or unsafe targets are detected.
     *
     * @param string $url
     * @throws InvalidArgumentException
     */
    public static function assertUrlSafe(string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Target URL is empty.');
        }

        // Basic syntax validation
        if (!filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Malformed URL format: '{$url}'.");
        }

        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException("Unable to determine host from URL: '{$url}'.");
        }

        // 1. Enforce Scheme
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new InvalidArgumentException("Disallowed URL scheme '{$scheme}'. Only HTTP and HTTPS are permitted.");
        }

        // 2. Enforce Port
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) {
            throw new InvalidArgumentException("Disallowed target port '{$port}'. Only standard web ports are permitted.");
        }

        $host = strtolower($parts['host']);

        // 3. Block Local Hostnames & Suffixes
        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new InvalidArgumentException("Access to local hostname '{$host}' is prohibited.");
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new InvalidArgumentException("Access to internal domain '{$host}' is prohibited.");
            }
        }

        // 4. Check if host is direct IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertIpIsPublic($host);
            return;
        }

        // 5. DNS Resolution & IP Range Verification
        // Resolve host to IPv4 addresses
        $resolvedIps = @gethostbynamel($host);
        if (empty($resolvedIps)) {
            // Check if hostname can be resolved at all
            $ip = @gethostbyname($host);
            if ($ip === $host) {
                // Resolution failed
                throw new InvalidArgumentException("Could not resolve host '{$host}'.");
            }
            $resolvedIps = [$ip];
        }

        foreach ($resolvedIps as $ip) {
            self::assertIpIsPublic($ip);
        }
    }

    /**
     * Check if a given IPv4 or IPv6 address is publicly routable and NOT private, loopback, link-local, or reserved.
     *
     * @throws InvalidArgumentException
     */
    public static function assertIpIsPublic(string $ip): void
    {
        // Check for IPv4-mapped IPv6 address (::ffff:192.0.2.1)
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $ip = substr($ip, 7);
        }

        // PHP's native filter flags for private and reserved ranges
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            throw new InvalidArgumentException("Blocked request to non-public/private IP address: '{$ip}'.");
        }

        // Additional IPv4 CIDR checks to cover any edge cases (e.g. 0.0.0.0/8, 169.254.0.0/16 AWS metadata)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                throw new InvalidArgumentException("Invalid IPv4 address representation: '{$ip}'.");
            }

            // Loopback (127.0.0.0/8)
            if (($long & 0xFF000000) === 0x7F000000) {
                throw new InvalidArgumentException("Blocked request to loopback IP: '{$ip}'.");
            }
            // 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) {
                throw new InvalidArgumentException("Blocked request to 0.0.0.0/8 address: '{$ip}'.");
            }
            // Link-local / Cloud Metadata (169.254.0.0/16)
            if (($long & 0xFFFF0000) === (int)ip2long('169.254.0.0')) {
                throw new InvalidArgumentException("Blocked request to link-local/cloud metadata IP: '{$ip}'.");
            }
            // Private Class A: 10.0.0.0/8
            if (($long & 0xFF000000) === (int)ip2long('10.0.0.0')) {
                throw new InvalidArgumentException("Blocked request to private 10.0.0.0/8 IP: '{$ip}'.");
            }
            // Private Class B: 172.16.0.0/12
            if (($long & 0xFFF00000) === (int)ip2long('172.16.0.0')) {
                throw new InvalidArgumentException("Blocked request to private 172.16.0.0/12 IP: '{$ip}'.");
            }
            // Private Class C: 192.168.0.0/16
            if (($long & 0xFFFF0000) === (int)ip2long('192.168.0.0')) {
                throw new InvalidArgumentException("Blocked request to private 192.168.0.0/16 IP: '{$ip}'.");
            }
            // Broadcast (255.255.255.255)
            if ($long === -1 || $long === (int)ip2long('255.255.255.255')) {
                throw new InvalidArgumentException("Blocked request to broadcast address: '{$ip}'.");
            }
        }

        // Additional IPv6 checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            // IPv6 Loopback (::1)
            if ($lower === '::1' || $lower === '0:0:0:0:0:0:0:1') {
                throw new InvalidArgumentException("Blocked request to IPv6 loopback: '{$ip}'.");
            }
            // IPv6 Unique Local (fc00::/7)
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                throw new InvalidArgumentException("Blocked request to IPv6 unique local address: '{$ip}'.");
            }
            // IPv6 Link-Local (fe80::/10)
            if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                throw new InvalidArgumentException("Blocked request to IPv6 link-local address: '{$ip}'.");
            }
        }
    }
}
