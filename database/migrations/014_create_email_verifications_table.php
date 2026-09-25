<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

class CreateEmailVerificationsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->execute('
            CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id`         BIGINT       AUTO_INCREMENT PRIMARY KEY,
                `user_id`    BIGINT       NOT NULL,
                `email`      VARCHAR(191) NOT NULL,
                `token_hash` VARCHAR(64)  NOT NULL,
                `expires_at` DATETIME     NOT NULL,
                `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_email_verif_user`  (`user_id`),
                INDEX `idx_email_verif_token` (`token_hash`),
                INDEX `idx_email_verif_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Backfill pre-existing users without verification date to preserve existing active accounts
        try {
            $this->db->execute('UPDATE `users` SET `email_verified_at` = `created_at` WHERE `email_verified_at` IS NULL');
        } catch (\Throwable) {
            // Non-fatal if table is empty
        }

        // Insert default setting require_email_verification = 1 in general settings
        try {
            $this->db->execute("
                INSERT IGNORE INTO `settings` (`group_name`, `setting_key`, `value`, `type`, `label`, `is_public`)
                VALUES ('general', 'require_email_verification', '1', 'bool', 'Require Email Verification', 0)
            ");
        } catch (\Throwable) {
            // Non-fatal if setting table is uninitialized in test mocks
        }
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS `email_verifications`');
        $this->db->execute("DELETE FROM `settings` WHERE `group_name` = 'general' AND `setting_key` = 'require_email_verification'");
    }
}

