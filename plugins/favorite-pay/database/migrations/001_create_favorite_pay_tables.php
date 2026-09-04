<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Pay — Initial Database Schema Migration
 *
 * Creates the 7 core Phase 2 tables:
 * 1. favorite_pay_gateways        - Configured payment gateway drivers
 * 2. favorite_pay_rates           - Authoritative exchange rates & snapshots
 * 3. favorite_pay_transactions    - Core payment intents & transaction ledger
 * 4. favorite_pay_attempts        - Individual gateway execution attempts (TrxIDs)
 * 5. favorite_pay_refunds         - Refund and reversal tracking
 * 6. favorite_pay_wallets         - BDT-denominated customer balance accounts
 * 7. favorite_pay_wallet_entries  - Append-only financial ledger entries
 *
 * Designed with strict MySQL/MariaDB production syntax (InnoDB, utf8mb4)
 * and automatic SQLite fallback compatibility for lightweight testing.
 */
class CreateFavoritePayTables
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
        $updatedAt = $isSqlite
            ? 'DATETIME DEFAULT CURRENT_TIMESTAMP'
            : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

        // 1. favorite_pay_gateways
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_gateways` (
                `id`                   VARCHAR(64)  PRIMARY KEY,
                `title`                VARCHAR(191) NOT NULL,
                `type`                 VARCHAR(32)  NOT NULL,
                `is_enabled`           TINYINT(1)   NOT NULL DEFAULT 0,
                `supported_currencies` TEXT         NULL,
                `config`               TEXT         NULL,
                `sort_order`           INT          NOT NULL DEFAULT 0,
                `created_at`           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`           {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_gateways', 'idx_fpay_gw_type', '`type`');
        $this->createIndexIfNotExists('favorite_pay_gateways', 'idx_fpay_gw_enabled', '`is_enabled`');

        // 2. favorite_pay_rates
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_rates` (
                `id`               {$pkBigint},
                `base_currency`    VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `quote_currency`   VARCHAR(3)     NOT NULL,
                `rate`             DECIMAL(18, 6) NOT NULL,
                `rate_factor`      BIGINT         NOT NULL,
                `rate_scale`       INT            NOT NULL DEFAULT 1000000,
                `is_authoritative` TINYINT(1)     NOT NULL DEFAULT 1,
                `source`           VARCHAR(64)    NOT NULL DEFAULT 'operator',
                `operator_id`      BIGINT         NULL,
                `effective_at`     DATETIME       NOT NULL,
                `created_at`       TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_rates', 'idx_fpay_rates_pair', '`base_currency`, `quote_currency`');
        $this->createIndexIfNotExists('favorite_pay_rates', 'idx_fpay_rates_effective', '`effective_at`');

        // 3. favorite_pay_transactions
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_transactions` (
                `id`                  {$pkBigint},
                `transaction_id`      VARCHAR(64)    NOT NULL UNIQUE,
                `source_plugin`       VARCHAR(64)    NOT NULL,
                `source_reference`    VARCHAR(191)   NOT NULL,
                `user_id`             BIGINT         NULL,
                `base_amount`         BIGINT         NOT NULL,
                `base_currency`       VARCHAR(3)     NOT NULL DEFAULT 'BDT',
                `charge_amount`       BIGINT         NOT NULL,
                `charge_currency`     VARCHAR(3)     NOT NULL,
                `exchange_rate`       DECIMAL(18, 6) NULL,
                `rate_factor`         BIGINT         NULL,
                `rate_scale`          INT            NULL,
                `payment_method_type` VARCHAR(32)    NULL,
                `gateway_id`          VARCHAR(64)    NULL,
                `status`              VARCHAR(32)    NOT NULL DEFAULT 'pending',
                `idempotency_key`     VARCHAR(191)   NULL UNIQUE,
                `external_reference`  VARCHAR(191)   NULL,
                `metadata`            TEXT           NULL,
                `failure_reason`      TEXT           NULL,
                `completed_at`        DATETIME       NULL,
                `created_at`          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
                `updated_at`          {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_transactions', 'idx_fpay_tx_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_pay_transactions', 'idx_fpay_tx_status', '`status`');
        $this->createIndexIfNotExists('favorite_pay_transactions', 'idx_fpay_tx_source', '`source_plugin`, `source_reference`');
        $this->createIndexIfNotExists('favorite_pay_transactions', 'idx_fpay_tx_gateway', '`gateway_id`');
        $this->createIndexIfNotExists('favorite_pay_transactions', 'idx_fpay_tx_created', '`created_at`');

        // 4. favorite_pay_attempts
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_attempts` (
                `id`                 {$pkBigint},
                `attempt_id`         VARCHAR(64)  NOT NULL UNIQUE,
                `transaction_id`     VARCHAR(64)  NOT NULL,
                `gateway_id`         VARCHAR(64)  NOT NULL,
                `amount`             BIGINT       NOT NULL,
                `currency`           VARCHAR(3)   NOT NULL,
                `status`             VARCHAR(32)  NOT NULL DEFAULT 'pending',
                `provider_reference` VARCHAR(191) NULL,
                `provider_status`    VARCHAR(64)  NULL,
                `request_payload`    TEXT         NULL,
                `response_payload`   TEXT         NULL,
                `error_message`      TEXT         NULL,
                `operator_notes`     TEXT         NULL,
                `verified_by`        BIGINT       NULL,
                `verified_at`        DATETIME     NULL,
                `created_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                `updated_at`         {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_attempts', 'idx_fpay_att_tx', '`transaction_id`');
        $this->createIndexIfNotExists('favorite_pay_attempts', 'idx_fpay_att_gw', '`gateway_id`');
        $this->createIndexIfNotExists('favorite_pay_attempts', 'idx_fpay_att_status', '`status`');
        $this->createIndexIfNotExists('favorite_pay_attempts', 'idx_fpay_att_pref', '`provider_reference`');
        $this->createIndexIfNotExists('favorite_pay_attempts', 'uq_fpay_att_gw_pref', '`gateway_id`, `provider_reference`', true);

        // 5. favorite_pay_refunds
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_refunds` (
                `id`                        {$pkBigint},
                `refund_id`                 VARCHAR(64)  NOT NULL UNIQUE,
                `transaction_id`            VARCHAR(64)  NOT NULL,
                `amount`                    BIGINT       NOT NULL,
                `currency`                  VARCHAR(3)   NOT NULL,
                `status`                    VARCHAR(32)  NOT NULL DEFAULT 'succeeded',
                `provider_refund_reference` VARCHAR(191) NULL,
                `reason`                    TEXT         NULL,
                `operator_id`               BIGINT       NULL,
                `created_at`                TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_refunds', 'idx_fpay_ref_tx', '`transaction_id`');
        $this->createIndexIfNotExists('favorite_pay_refunds', 'idx_fpay_ref_status', '`status`');
        $this->createIndexIfNotExists('favorite_pay_refunds', 'idx_fpay_ref_pref', '`provider_refund_reference`');

        // 6. favorite_pay_wallets
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_wallets` (
                `id`         {$pkBigint},
                `user_id`    BIGINT      NOT NULL UNIQUE,
                `balance`    BIGINT      NOT NULL DEFAULT 0,
                `currency`   VARCHAR(3)  NOT NULL DEFAULT 'BDT',
                `status`     VARCHAR(32) NOT NULL DEFAULT 'active',
                `created_at` TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
                `updated_at` {$updatedAt}
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_wallets', 'idx_fpay_wallets_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_pay_wallets', 'idx_fpay_wallets_status', '`status`');

        // 7. favorite_pay_wallet_entries
        $this->db->execute("
            CREATE TABLE IF NOT EXISTS `favorite_pay_wallet_entries` (
                `id`              {$pkBigint},
                `entry_id`        VARCHAR(64)  NOT NULL UNIQUE,
                `wallet_id`       BIGINT       NOT NULL,
                `user_id`         BIGINT       NOT NULL,
                `type`            VARCHAR(32)  NOT NULL,
                `amount`          BIGINT       NOT NULL,
                `balance_after`   BIGINT       NOT NULL,
                `reference_type`  VARCHAR(64)  NOT NULL,
                `reference_id`    VARCHAR(191) NOT NULL,
                `idempotency_key` VARCHAR(191) NULL UNIQUE,
                `description`     VARCHAR(500) NOT NULL DEFAULT '',
                `metadata`        TEXT         NULL,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            ){$engine};
        ");

        $this->createIndexIfNotExists('favorite_pay_wallet_entries', 'idx_fpay_led_wallet', '`wallet_id`');
        $this->createIndexIfNotExists('favorite_pay_wallet_entries', 'idx_fpay_led_user', '`user_id`');
        $this->createIndexIfNotExists('favorite_pay_wallet_entries', 'idx_fpay_led_type', '`type`');
        $this->createIndexIfNotExists('favorite_pay_wallet_entries', 'idx_fpay_led_ref', '`reference_type`, `reference_id`');
        $this->createIndexIfNotExists('favorite_pay_wallet_entries', 'idx_fpay_led_created', '`created_at`');
    }

    public function down(): void
    {
        $tables = [
            'favorite_pay_wallet_entries',
            'favorite_pay_wallets',
            'favorite_pay_refunds',
            'favorite_pay_attempts',
            'favorite_pay_transactions',
            'favorite_pay_rates',
            'favorite_pay_gateways',
        ];

        foreach ($tables as $table) {
            $this->db->execute("DROP TABLE IF EXISTS `{$table}`");
        }
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

        // MySQL index existence check
        try {
            $existing = $this->db->select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
            if (empty($existing)) {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            }
        } catch (\Throwable) {
            // Fallback for environments with restricted metadata permissions
            try {
                $uniqueClause = $unique ? 'UNIQUE' : '';
                $this->db->execute("ALTER TABLE `{$table}` ADD {$uniqueClause} INDEX `{$indexName}` ({$columns})");
            } catch (\Throwable) {
                // index might already exist
            }
        }
    }
}
