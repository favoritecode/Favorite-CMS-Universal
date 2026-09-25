<?php

declare(strict_types=1);

namespace FavoriteCMS\Services\Security;

use RuntimeException;

/**
 * Authenticated Cryptographic Secret Storage Service.
 *
 * Uses AES-256-GCM authenticated symmetric encryption with unique per-message nonces
 * and authentication tags for tamper-proof storage of sensitive credentials at rest.
 *
 * Primary and ONLY supported algorithm: AES-256-GCM.
 * No unauthenticated fallbacks (e.g. CBC without MAC) are permitted.
 */
class Crypto
{
    public const CIPHER = 'aes-256-gcm';
    public const VERSION = 'v1';
    public const NONCE_LENGTH = 12; // 96-bit recommended standard for GCM
    public const TAG_LENGTH = 16;   // 128-bit authentication tag

    /**
     * Check whether cryptographic encryption is configured and available in the current runtime.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('openssl')) {
            return false;
        }

        $supported = in_array(self::CIPHER, openssl_get_cipher_methods(), true);
        if (!$supported) {
            return false;
        }

        $key = static::resolveKey();
        return $key !== null && strlen($key) === 32;
    }

    /**
     * Encrypt a plaintext string using AES-256-GCM.
     *
     * @param string $plaintext
     * @return string Versioned, base64-packed ciphertext (v1:<nonce>:<ciphertext>:<tag>)
     * @throws RuntimeException If cryptographic encryption fails or key is missing
     */
    public static function encrypt(string $plaintext): string
    {
        if (!extension_loaded('openssl') || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('AES-256-GCM authenticated encryption is not supported by this server environment.');
        }

        $key = static::resolveKey();
        if ($key === null || strlen($key) !== 32) {
            throw new RuntimeException('Cryptographic key is not configured or invalid in application environment.');
        }

        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = @openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION, // Additional Authenticated Data (AAD) bound to version
            self::TAG_LENGTH
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Failed to encrypt secret payload using AES-256-GCM.');
        }

        return sprintf(
            '%s:%s:%s:%s',
            self::VERSION,
            base64_encode($nonce),
            base64_encode($ciphertext),
            base64_encode($tag)
        );
    }

    /**
     * Decrypt a versioned ciphertext string using AES-256-GCM.
     *
     * @param string $payload
     * @return string|null Decrypted plaintext, or null if invalid or tampered
     */
    public static function decrypt(string $payload): ?string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        $parts = explode(':', $payload);
        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return null;
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);
        $tag = base64_decode($parts[3], true);

        if ($nonce === false || strlen($nonce) !== self::NONCE_LENGTH
            || $ciphertext === false
            || $tag === false || strlen($tag) !== self::TAG_LENGTH) {
            return null;
        }

        $key = static::resolveKey();
        if ($key === null || strlen($key) !== 32) {
            return null;
        }

        $plaintext = @openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::VERSION // Must match AAD bound during encryption
        );

        if ($plaintext === false) {
            // Authentication tag mismatch or corrupted ciphertext
            return null;
        }

        return $plaintext;
    }

    /**
     * Optional key override for automated testing or runtime dependency injection.
     */
    public static ?string $keyOverride = null;

    /**
     * Resolve and derive the authoritative 256-bit encryption key from application configuration.
     *
     * @return string|null 32-byte binary key, or null if unavailable
     */
    protected static function resolveKey(): ?string
    {
        if (static::$keyOverride !== null) {
            $raw = static::$keyOverride;
        } else {
            $raw = '';
            try {
                $raw = (string)config('app.key', '');
            } catch (\Throwable) {
            }
            if ($raw === '') {
                $raw = (string)getenv('APP_KEY');
            }
            if ($raw === '') {
                $raw = (string)($_ENV['APP_KEY'] ?? '');
            }
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        // Derive uniform 256-bit key using SHA-256
        return hash('sha256', $raw, true);
    }
}
