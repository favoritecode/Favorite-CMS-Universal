<?php
declare(strict_types=1);
use FavoriteCMS\Core\Database;
class CreateSeoAutomation {
 public function __construct(protected Database $db) {}
 public function up(): void {
  $this->db->registerPrefixableTables(['seo_redirects']);
  $sqlite=$this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
  $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT AUTO_INCREMENT PRIMARY KEY';
  $suffix=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $this->db->execute("CREATE TABLE IF NOT EXISTS `seo_redirects` (`id` {$id}, `object_type` VARCHAR(8) NOT NULL, `object_id` BIGINT NOT NULL, `old_slug` VARCHAR(255) NOT NULL, `created_at` DATETIME NOT NULL, UNIQUE (`object_type`,`old_slug`)){$suffix}");
  $columns=$sqlite?$this->db->select('PRAGMA table_info('.$this->db->quoteIdentifier('seo_meta').')'):$this->db->select('SHOW COLUMNS FROM `seo_meta`');
  $found=false;foreach($columns as $column)if(($column->name??$column->Field??'')==='og_image_url')$found=true;
  if(!$found)$this->db->execute('ALTER TABLE `seo_meta` ADD COLUMN `og_image_url` VARCHAR(1000) NULL');
 }
}