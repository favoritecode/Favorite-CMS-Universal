<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 014: Wallet Transactions Table
 *
 * Immutable financial ledger recording all wallet debits, refund credits, and reversals.
 */
class CreateFavoriteDigitalWalletTransactionsTable
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $isSqlite = $this->isSqlite();
        $engine = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pkBigint = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_digital_wallet_transactions` (
                `id`            {$pkBigint},
                `wallet_id`     BIGINT         NOT NULL,
                `type`          VARCHAR(32)    NOT NULL,
                `amount`        DECIMAL(14, 2) NOT NULL,
                `balance_after` DECIMAL(14, 2) NOT NULL,
                `order_id`      BIGINT         NULL,
                `reference_id`  VARCHAR(191)   NOT NULL,
                `description`   VARCHAR(500)   NOT NULL DEFAULT '',
                `created_at`    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_digital_wallet_transactions', 'idx_fd_wtx_wallet', '`wallet_id`');
        $this->createIndexIfNotExists('favorite_digital_wallet_transactions', 'idx_fd_wtx_type', '`type`');
        $this->createIndexIfNotExists('favorite_digital_wallet_transactions', 'idx_fd_wtx_ref', '`reference_id`');
        $this->createIndexIfNotExists('favorite_digital_wallet_transactions', 'idx_fd_wtx_order', '`order_id`');
        $this->createIndexIfNotExists('favorite_digital_wallet_transactions', 'idx_fd_wtx_created', '`created_at`');
    }

    public function down(): void
    {
        $this->db->execute("DROP TABLE IF EXISTS `favorite_digital_wallet_transactions`");
    }

    protected function isSqlite(): bool
    {
        try {
            $driver = $this->db->getConnection()->getAttribute(\PDO::ATTR_DRIVER_NAME);
            return strtolower((string)$driver) === 'sqlite';
        } catch (\Throwable) {
            return false;
        }
    }

    protected function createIndexIfNotExists(string $table, string $indexName, string $columns, bool $unique = false): void
    {
        if ($this->isSqlite()) {
            $uniqueClause = $unique ? 'UNIQUE' : '';
            $this->db->execute("CREATE {$uniqueClause} INDEX IF NOT EXISTS `{$indexName}` ON `{$table}` ({$columns})");
            return;
        }

        try {
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
            try {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            } catch (\Throwable) {
            }
        }
    }
}

