<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Application;
use FavoriteCMS\Core\Container;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

class EmailVerificationService
{
    public const TOKEN_EXPIRATION_HOURS = 24;
    public const RESEND_COOLDOWN_SECONDS = 60;

    protected Database $db;

    public function __construct(?Database $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $this->db = Container::getInstance()->get(Database::class);
        }
    }

    /**
     * Determine if email verification is globally required for registration.
     */
    public static function isRequired(): bool
    {
        try {
            return (bool)(int)Setting::get('general', 'require_email_verification', 1);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Generate a new single-use verification token for a user and email.
     * Invalidates any existing pending tokens for this user.
     *
     * @param User|int $user
     * @param string $email
     * @return string The raw plain-text token (to send via email link)
     */
    public function createVerificationToken(User|int $user, string $email): string
    {
        $userId = $user instanceof User ? (int)$user->id : (int)$user;
        $cleanEmail = strtolower(trim($email));

        // Invalidate any previous verification tokens for this user
        $this->db->execute(
            "DELETE FROM `email_verifications` WHERE `user_id` = ?",
            [$userId]
        );

        // Generate cryptographically secure 64-character hex token (256 bits of entropy)
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        $now = time();
        $expiresAt = date('Y-m-d H:i:s', $now + (self::TOKEN_EXPIRATION_HOURS * 3600));

        $this->db->insert('email_verifications', [
            'user_id'    => $userId,
            'email'      => $cleanEmail,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s', $now),
        ]);

        return $rawToken;
    }

    /**
     * Check if a resend request is permitted under rate-limiting cooldown.
     */
    public function canResend(string $email): bool
    {
        return $this->getSecondsUntilResend($email) <= 0;
    }

    /**
     * Get remaining cooldown seconds before a resend is permitted.
     */
    public function getSecondsUntilResend(string $email): int
    {
        $cleanEmail = strtolower(trim($email));
        $record = $this->db->selectOne(
            "SELECT `created_at` FROM `email_verifications` WHERE `email` = ? ORDER BY `id` DESC LIMIT 1",
            [$cleanEmail]
        );

        if (!$record || empty($record->created_at)) {
            return 0;
        }

        $elapsed = time() - strtotime((string)$record->created_at);
        $remaining = self::RESEND_COOLDOWN_SECONDS - $elapsed;
        return max(0, $remaining);
    }

    /**
     * Verify a raw token from a link.
     *
     * @param string $rawToken
     * @return array{success: bool, error: ?string, user: ?User, isEmailChange: bool, newEmail: ?string}
     */
    public function verifyToken(string $rawToken): array
    {
        $token = trim($rawToken);
        if ($token === '' || strlen($token) < 32 || !ctype_xdigit($token)) {
            return [
                'success'       => false,
                'error'         => 'Invalid verification token.',
                'user'          => null,
                'isEmailChange' => false,
                'newEmail'      => null,
            ];
        }

        $tokenHash = hash('sha256', $token);
        $record = $this->db->selectOne(
            "SELECT * FROM `email_verifications` WHERE `token_hash` = ? LIMIT 1",
            [$tokenHash]
        );

        if (!$record) {
            return [
                'success'       => false,
                'error'         => 'Invalid, expired, or already-used verification link.',
                'user'          => null,
                'isEmailChange' => false,
                'newEmail'      => null,
            ];
        }

        // Check expiration
        if (strtotime((string)$record->expires_at) < time()) {
            $this->db->execute(
                "DELETE FROM `email_verifications` WHERE `id` = ?",
                [$record->id]
            );
            return [
                'success'       => false,
                'error'         => 'This verification link has expired. Please request a new verification email.',
                'user'          => null,
                'isEmailChange' => false,
                'newEmail'      => null,
            ];
        }

        $user = User::find((int)$record->user_id);
        if (!$user) {
            $this->db->execute(
                "DELETE FROM `email_verifications` WHERE `id` = ?",
                [$record->id]
            );
            return [
                'success'       => false,
                'error'         => 'Associated user account was not found.',
                'user'          => null,
                'isEmailChange' => false,
                'newEmail'      => null,
            ];
        }

        $now = date('Y-m-d H:i:s');
        $targetEmail = strtolower(trim((string)$record->email));
        $currentEmail = strtolower(trim((string)$user->email));
        $isEmailChange = ($targetEmail !== $currentEmail);

        if ($isEmailChange) {
            // Confirming an email change: update email to target email and mark verified
            $user->update([
                'email'             => $targetEmail,
                'email_verified_at' => $now,
                'updated_at'        => $now,
            ]);
        } else {
            // Confirming primary registration email
            $user->update([
                'email_verified_at' => $now,
                'updated_at'        => $now,
            ]);
        }

        // Delete used token and any residual tokens for this user
        $this->db->execute(
            "DELETE FROM `email_verifications` WHERE `user_id` = ?",
            [$user->id]
        );

        // Fire action hook for extensions
        Hook::doAction('user.email_verified', $user, $isEmailChange);

        return [
            'success'       => true,
            'error'         => null,
            'user'          => $user,
            'isEmailChange' => $isEmailChange,
            'newEmail'      => $targetEmail,
        ];
    }

    /**
     * Get any pending unverified email change address for a user.
     */
    public function getPendingEmailChange(int $userId): ?string
    {
        $user = User::find($userId);
        if (!$user) {
            return null;
        }

        $record = $this->db->selectOne(
            "SELECT `email`, `expires_at` FROM `email_verifications` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 1",
            [$userId]
        );

        if (!$record || empty($record->email)) {
            return null;
        }

        if (strtotime((string)$record->expires_at) < time()) {
            return null;
        }

        $cleanPending = strtolower(trim((string)$record->email));
        $cleanCurrent = strtolower(trim((string)$user->email));

        return ($cleanPending !== $cleanCurrent) ? $cleanPending : null;
    }

    /**
     * Send a verification email to the user.
     *
     * @param User $user
     * @param string $targetEmail
     * @param string $token
     * @param bool $isEmailChange
     * @return bool
     */
    public function sendVerificationEmail(User $user, string $targetEmail, string $token, bool $isEmailChange = false): bool
    {
        $siteName = Setting::get('general', 'site_name', 'Favorite CMS');
        $siteUrl = rtrim(config('app.url', 'http://localhost'), '/');
        $verificationUrl = $siteUrl . '/verify-email?token=' . urlencode($token);

        $subject = $isEmailChange
            ? "Confirm your new email address — {$siteName}"
            : "Verify your email address — {$siteName}";

        $greetingName = htmlspecialchars($user->name ?: $user->username, ENT_QUOTES, 'UTF-8');
        $safeSite = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

        $message = "Hello {$greetingName},\n\n";
        if ($isEmailChange) {
            $message .= "You recently requested to update your email address on {$siteName}.\n";
            $message .= "Please click the link below to confirm this change:\n\n";
        } else {
            $message .= "Thank you for registering at {$siteName}.\n";
            $message .= "Please verify your email address by clicking the link below:\n\n";
        }
        $message .= "{$verificationUrl}\n\n";
        $message .= "This link is valid for " . self::TOKEN_EXPIRATION_HOURS . " hours.\n";
        $message .= "If you did not request this, you can safely ignore this email.\n\n";
        $message .= "Regards,\nThe {$siteName} Team";

        // Allow plugins or mail systems to intercept/handle email dispatch
        $intercepted = Hook::applyFilters('pre_send_verification_email', null, [
            'to'              => $targetEmail,
            'subject'         => $subject,
            'message'         => $message,
            'user'            => $user,
            'verificationUrl' => $verificationUrl,
            'isEmailChange'   => $isEmailChange,
        ]);

        if ($intercepted !== null) {
            return (bool)$intercepted;
        }

        // Standard native mail dispatch (wrapped safely so failure doesn't halt execution)
        $adminEmail = Setting::get('general', 'admin_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $headers = [
            'From: ' . $siteName . ' <' . $adminEmail . '>',
            'Reply-To: ' . $adminEmail,
            'X-Mailer: Favorite CMS Universal',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        try {
            return @mail($targetEmail, $subject, $message, implode("\r\n", $headers));
        } catch (\Throwable) {
            return false;
        }
    }
}

