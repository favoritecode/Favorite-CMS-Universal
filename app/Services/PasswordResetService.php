<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;
use FavoriteCMS\Models\User;

class PasswordResetService
{
    public function __construct(private Database $db) {}

    /**
     * Request a password reset for an account identified by email or username.
     * Never discloses whether an account exists or delivery succeeded.
     *
     * @param string $identity Email address or username
     * @return bool True if mail dispatch succeeded or was processed, false otherwise
     */
    public function request(string $identity): bool
    {
        $identity = trim($identity);
        if ($identity === '') {
            return false;
        }

        $userRow = null;
        if (filter_var($identity, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($identity);
            $userRow = $this->db->selectOne(
                "SELECT * FROM `users` WHERE `email` = ? AND `status` = 'active' LIMIT 1",
                [$email]
            );

            // If not found by direct email, check if email matches installation general.admin_email
            if (!$userRow) {
                $adminEmail = strtolower(trim((string)Setting::get('general', 'admin_email', '')));
                if ($adminEmail !== '' && $adminEmail === $email) {
                    $userRow = $this->db->selectOne(
                        "SELECT u.* FROM `users` u
                         JOIN `user_roles` ur ON u.id = ur.user_id
                         JOIN `roles` r ON ur.role_id = r.id
                         WHERE r.slug IN ('admin', 'super-admin') AND u.status = 'active'
                         ORDER BY u.id ASC LIMIT 1"
                    );
                    if (!$userRow) {
                        $userRow = $this->db->selectOne("SELECT * FROM `users` WHERE `id` = 1 AND `status` = 'active' LIMIT 1");
                    }
                }
            }
        } else {
            // Username lookup
            $userRow = $this->db->selectOne(
                "SELECT * FROM `users` WHERE `username` = ? AND `status` = 'active' LIMIT 1",
                [$identity]
            );
        }

        if (!$userRow) {
            return false;
        }

        $user = new User((array)$userRow);
        $isAdminAccount = $user->hasRole('admin') || $user->hasRole('super-admin') || (int)$user->id === 1;

        // Authoritative recipient determination:
        // Admin account recovery recipient is general.admin_email
        // Normal user recovery recipient is the user's registered account email
        $recipientEmail = '';
        if ($isAdminAccount) {
            $configuredAdminEmail = strtolower(trim((string)Setting::get('general', 'admin_email', '')));
            if ($configuredAdminEmail !== '' && filter_var($configuredAdminEmail, FILTER_VALIDATE_EMAIL)) {
                $recipientEmail = $configuredAdminEmail;
            } else {
                $recipientEmail = strtolower(trim((string)$user->email));
            }
        } else {
            $recipientEmail = strtolower(trim((string)$user->email));
        }

        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $this->db->query('INSERT INTO `password_resets` (`user_id`, `email`, `token_hash`, `expires_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `token_hash` = VALUES(`token_hash`),
                `expires_at` = VALUES(`expires_at`), `created_at` = VALUES(`created_at`)',
            [$user->id, $user->email, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 1800), gmdate('Y-m-d H:i:s')]);

        $url = app_url('/reset-password?token=' . rawurlencode($token));
        $siteName = (string)Setting::get('general', 'site_name', 'Favorite CMS');
        $subject = "Reset your {$siteName} password";
        $message = "Hello,\n\n"
            . "A request has been made to reset the password for your account on {$siteName}.\n\n"
            . "Please click the link below to choose a new password:\n"
            . "{$url}\n\n"
            . "If the link does not open directly, please copy and paste the full URL into your browser's address bar:\n"
            . "{$url}\n\n"
            . "This single-use link is valid for 30 minutes.\n\n"
            . "If you did not request a password reset, you can safely ignore this email. Your password will not change.\n\n"
            . "Regards,\nThe {$siteName} Team";

        // Plugin interception hook
        $handled = Hook::applyFilters('pre_send_password_reset_email', null, [
            'to'      => $recipientEmail,
            'subject' => $subject,
            'message' => $message,
            'url'     => $url,
            'token'   => $token,
        ]);
        if ($handled !== null) {
            return (bool)$handled;
        }

        return MailService::send($recipientEmail, $subject, $message);
    }

    public function reset(string $token, string $password): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($password) < 10
            || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return false;
        }
        return $this->db->transaction(function () use ($token, $password): bool {
            $record = $this->db->selectOne('SELECT * FROM `password_resets` WHERE `token_hash` = ? FOR UPDATE', [hash('sha256', $token)]);
            if (!$record || $record->expires_at <= gmdate('Y-m-d H:i:s')) {
                return false;
            }
            $changed = $this->db->query("UPDATE `users` SET `password` = ?, `auth_version` = `auth_version` + 1, `updated_at` = ?
                WHERE `id` = ? AND `email` = ? AND `status` = 'active'",
                [PasswordHasher::hash($password), gmdate('Y-m-d H:i:s'), $record->user_id, $record->email])->rowCount();
            $this->db->delete('password_resets', ['user_id' => $record->user_id]);
            return $changed === 1;
        });
    }
}
