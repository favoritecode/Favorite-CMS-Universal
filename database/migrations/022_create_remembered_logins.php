<?php
declare(strict_types=1);
use FavoriteCMS\Core\Database;
class CreateRememberedLogins {
 public function __construct(private Database $db){}
 public function up():void {
  $this->db->registerPrefixableTables(['remembered_logins']);
  $this->db->execute('CREATE TABLE IF NOT EXISTS `remembered_logins` (selector VARCHAR(32) PRIMARY KEY, validator_hash VARCHAR(64) NOT NULL, user_id BIGINT NOT NULL, fingerprint VARCHAR(64) NOT NULL, expires_at BIGINT NOT NULL)');
 }
}
