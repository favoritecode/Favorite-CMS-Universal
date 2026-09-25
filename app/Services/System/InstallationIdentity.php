<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\System;

use FavoriteCMS\Models\Setting;
use FavoriteCMS\Services\Security\Crypto;
use RuntimeException;

/**
 * Enterprise Installation Identity Service.
 *
 * Provides cryptographically strong per-installation identity generation,
 * encrypted secret persistence at rest, enrollment lifecycle management,
 * and canonical HMAC-SHA256 request signing for the Central OAuth Gateway.
 */
class InstallationIdentity
{
    /**
     * Test overrides for automated testing isolation.
     */
    public static ?string $idOverride = null;
    public static ?string $secretOverride = null;
    public static ?bool $enrolledOverride = null;

    /**
     * Reset all test overrides.
     */
    public static function reset(): void
    {
        static::$idOverride = null;
        static::$secretOverride = null;
        static::$enrolledOverride = null;
    }

    /**
     * Retrieve the unique installation ID (64-character random hex).
     * Automatically generates and persists if not already present.
     */
    public static function getId(): string
    {
        if (static::$idOverride !== null) {
            return static::$idOverride;
        }

        $id = trim((string)Setting::get('system', 'installation_id', ''));
        if ($id !== '' && preg_match('/^[a-f0-9]{64}$/i', $id)) {
            return $id;
        }

        // Generate 32 bytes of cryptographically secure random entropy (64 hex characters)
        $newId = bin2hex(random_bytes(32));
        Setting::set('system', 'installation_id', $newId);
        Setting::clearCache();

        return $newId;
    }

    /**
     * Retrieve the unique installation secret (64-character random hex).
     * Always encrypted at rest in the settings table via authenticated AES-256-GCM.
     */
    public static function getSecret(): string
    {
        if (static::$secretOverride !== null) {
            return static::$secretOverride;
        }

        $stored = (string)Setting::get('system', 'installation_secret', '');
        if ($stored !== '') {
            $decrypted = null;
            if (str_starts_with($stored, Crypto::VERSION . ':')) {
                $decrypted = Crypto::decrypt($stored);
            } else {
                $decrypted = $stored;
            }

            if ($decrypted !== null && preg_match('/^[a-f0-9]{64}$/i', $decrypted)) {
                return $decrypted;
            }
        }

        // Generate 32 bytes of cryptographically secure random entropy (64 hex characters)
        $newSecret = bin2hex(random_bytes(32));
        if (Crypto::isAvailable()) {
            $encrypted = Crypto::encrypt($newSecret);
            Setting::set('system', 'installation_secret', $encrypted);
        } else {
            Setting::set('system', 'installation_secret', $newSecret);
        }
        Setting::clearCache();

        return $newSecret;
    }

    /**
     * Check whether the installation has completed first-time enrollment with the Gateway.
     */
    public static function isEnrolled(): bool
    {
        if (static::$enrolledOverride !== null) {
            return static::$enrolledOverride;
        }

        return (int)Setting::get('system', 'installation_enrolled', 0) === 1;
    }

    /**
     * Update installation enrollment status.
     * Note: Generating an installation secret does NOT mean enrolled.
     * It is set to true only after successful Gateway ticket exchange confirmation.
     */
    public static function setEnrolled(bool $enrolled): void
    {
        Setting::set('system', 'installation_enrolled', $enrolled ? 1 : 0, 'int');
        Setting::clearCache();
    }

    /**
     * Build the deterministic canonical payload string for HMAC-SHA256 signing.
     *
     * Format:
     * HTTP_METHOD\n
     * PATH\n
     * TIMESTAMP\n
     * NONCE\n
     * INSTALLATION_ID\n
     * BODY_HASH
     */
    public static function buildCanonicalPayload(
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $installationId,
        string $body = ''
    ): string {
        $cleanMethod = strtoupper(trim($method));
        $cleanPath = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?? $path, '/');
        $bodyHash = hash('sha256', $body);

        return implode("\n", [
            $cleanMethod,
            $cleanPath,
            (string)$timestamp,
            $nonce,
            $installationId,
            $bodyHash,
        ]);
    }

    /**
     * Sign a canonical payload using the installation secret via HMAC-SHA256.
     */
    public static function sign(string $payload): string
    {
        $secret = static::getSecret();
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Verify an HMAC-SHA256 signature using constant-time comparison.
     */
    public static function verifySignature(string $payload, string $signature): bool
    {
        $expected = static::sign($payload);
        return hash_equals($expected, strtolower(trim($signature)));
    }
}

