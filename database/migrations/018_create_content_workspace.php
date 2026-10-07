<?php
declare(strict_types=1);
use FavoriteCMS\Core\Database;
class CreateContentWorkspace {
    public function __construct(protected Database $db) {}
    public function up(): void {
        $this->db->registerPrefixableTables(['content_drafts', 'content_history']);
        $id = $this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $mysql = $this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $draftIndex = $mysql ? ', INDEX workspace_draft_list (`user_id`, `content_type`, `content_id`, `updated_at`)' : '';
        $historyIndex = $mysql ? ', INDEX workspace_history_list (`content_type`, `content_id`, `id`)' : '';
        $this->db->execute("CREATE TABLE IF NOT EXISTS `content_drafts` (`id` {$id}, `user_id` BIGINT NOT NULL, `content_type` VARCHAR(8) NOT NULL, `content_id` BIGINT NOT NULL, `client_id` VARCHAR(64) NOT NULL, `baseline` VARCHAR(64) NOT NULL, `payload` LONGTEXT NOT NULL, `updated_at` DATETIME NOT NULL, UNIQUE (`user_id`, `content_type`, `content_id`, `client_id`){$draftIndex}){$suffix}");
        $this->db->execute("CREATE TABLE IF NOT EXISTS `content_history` (`id` {$id}, `user_id` BIGINT NOT NULL, `content_type` VARCHAR(8) NOT NULL, `content_id` BIGINT NOT NULL, `fingerprint` VARCHAR(64) NOT NULL, `payload` LONGTEXT NOT NULL, `created_at` DATETIME NOT NULL{$historyIndex}){$suffix}");
    }
}
