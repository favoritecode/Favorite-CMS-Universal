<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\Hook;
use FavoriteCMS\Models\Setting;

class PasswordResetService
{
    public function __construct(private Database $db) {}

    /** Never disclose whether an address exists or delivery succeeded. */
    public function request(string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $user = $this->db->selectOne("SELECT * FROM `users` WHERE `email` = ? AND `status` = 'active' LIMIT 1", [$email]);
        if (!$user) {
            return;
        }
        // Use the configured site URL, never a request Host header, for recovery links.
        $base = (string)Setting::get('general', 'site_url', '');
        if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $this->db->query('INSERT INTO `password_resets` (`user_id`, `email`, `token_hash`, `expires_at`, `created_at`)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `token_hash` = VALUES(`token_hash`),
                `expires_at` = VALUES(`expires_at`), `created_at` = VALUES(`created_at`)',
            [$user->id, $email, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 1800), gmdate('Y-m-d H:i:s')]);
        $url = rtrim($base, '/') . '/reset-password#token=' . rawurlencode($token);
        $subject = 'Reset your Favorite CMS password';
        $message = "Use this link to choose a new password:\n\n{$url}\n\nThis single-use link expires in 30 minutes. If you did not request it, ignore this email.";
        // Match the existing native-mail/filter convention without adding a mail dependency.
        $handled = Hook::applyFilters('pre_send_password_reset_email', null, ['to' => $email, 'subject' => $subject, 'message' => $message]);
        if ($handled !== null) {
            return;
        }
        $from = (string)Setting::get('general', 'admin_email', '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || strpbrk($from, "\r\n") !== false) {
            return;
        }
        @mail($email, $subject, $message, "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8");
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
                [password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s'), $record->user_id, $record->email])->rowCount();
            $this->db->delete('password_resets', ['user_id' => $record->user_id]);
            return $changed === 1;
        });
    }
}
