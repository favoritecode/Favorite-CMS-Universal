<?php

declare(strict_types=1);

use FavoriteCMS\Core\Database;

/**
 * Favorite Digital — Migration 016: Media and Resource Fields (v1.0.1)
 *
 * Adds cover image support to products and external resource URL support to digital product details.
 */
class AddV101MediaAndResourceFields
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // 1. Add cover_image_path and cover_image_url to favorite_digital_products
        if (!$this->columnExists('favorite_digital_products', 'cover_image_path')) {
            $this->db->execute("ALTER TABLE `favorite_digital_products` ADD COLUMN `cover_image_path` VARCHAR(500) NULL");
        }

        if (!$this->columnExists('favorite_digital_products', 'cover_image_url')) {
            $this->db->execute("ALTER TABLE `favorite_digital_products` ADD COLUMN `cover_image_url` VARCHAR(1000) NULL");
        }

        // 2. Add resource_type and resource_url to favorite_digital_product_details
        if (!$this->columnExists('favorite_digital_product_details', 'resource_type')) {
            $this->db->execute("ALTER TABLE `favorite_digital_product_details` ADD COLUMN `resource_type` VARCHAR(32) NOT NULL DEFAULT 'file'");
        }

        if (!$this->columnExists('favorite_digital_product_details', 'resource_url')) {
            $this->db->execute("ALTER TABLE `favorite_digital_product_details` ADD COLUMN `resource_url` VARCHAR(1000) NULL");
        }
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            // SQLite 3.35.0+ supports DROP COLUMN, ignore if unsupported
            try {
                if ($this->columnExists('favorite_digital_products', 'cover_image_url')) {
                    $this->db->execute("ALTER TABLE `favorite_digital_products` DROP COLUMN `cover_image_url`");
                }
                if ($this->columnExists('favorite_digital_products', 'cover_image_path')) {
                    $this->db->execute("ALTER TABLE `favorite_digital_products` DROP COLUMN `cover_image_path`");
                }
                if ($this->columnExists('favorite_digital_product_details', 'resource_url')) {
                    $this->db->execute("ALTER TABLE `favorite_digital_product_details` DROP COLUMN `resource_url`");
                }
                if ($this->columnExists('favorite_digital_product_details', 'resource_type')) {
                    $this->db->execute("ALTER TABLE `favorite_digital_product_details` DROP COLUMN `resource_type`");
                }
            } catch (\Throwable) {
            }
            return;
        }

        // MySQL / MariaDB DROP COLUMN
        try {
            if ($this->columnExists('favorite_digital_products', 'cover_image_url')) {
                $this->db->execute("ALTER TABLE `favorite_digital_products` DROP COLUMN `cover_image_url`");
            }
            if ($this->columnExists('favorite_digital_products', 'cover_image_path')) {
                $this->db->execute("ALTER TABLE `favorite_digital_products` DROP COLUMN `cover_image_path`");
            }
            if ($this->columnExists('favorite_digital_product_details', 'resource_url')) {
                $this->db->execute("ALTER TABLE `favorite_digital_product_details` DROP COLUMN `resource_url`");
            }
            if ($this->columnExists('favorite_digital_product_details', 'resource_type')) {
                $this->db->execute("ALTER TABLE `favorite_digital_product_details` DROP COLUMN `resource_type`");
            }
        } catch (\Throwable) {
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

    protected function columnExists(string $table, string $column): bool
    {
        try {
            $this->db->select("SELECT `{$column}` FROM `{$table}` LIMIT 0");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

