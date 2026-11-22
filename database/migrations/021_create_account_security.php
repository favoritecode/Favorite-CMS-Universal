<?php
declare(strict_types=1);
use FavoriteCMS\Core\Database;
class CreateAccountSecurity {
 public function __construct(private Database $db){}
 public function up():void {
  $this->db->registerPrefixableTables(['account_security','notification_events','notification_outbox','notification_sources']);
  $sqlite=$this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
  $id=$sqlite?'INTEGER PRIMARY KEY AUTOINCREMENT':'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
  $this->db->execute("CREATE TABLE IF NOT EXISTS `account_security` (user_id BIGINT PRIMARY KEY, method VARCHAR(16) NOT NULL DEFAULT 'off', secret TEXT NULL, recovery TEXT NULL, last_step BIGINT NOT NULL DEFAULT -1, revision BIGINT NOT NULL DEFAULT 0, preferences TEXT NULL, enabled_at DATETIME NULL)");
  $this->db->execute("CREATE TABLE IF NOT EXISTS `notification_events` (id {$id}, event_key VARCHAR(191) NOT NULL UNIQUE, category VARCHAR(24) NOT NULL, subject VARCHAR(200) NOT NULL, body TEXT NOT NULL, user_id BIGINT NULL, cursor_id BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL)");
  $this->db->execute("CREATE TABLE IF NOT EXISTS `notification_outbox` (id {$id}, event_id BIGINT NOT NULL, user_id BIGINT NOT NULL, email VARCHAR(255) NOT NULL, attempts INT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'pending', available_at DATETIME NOT NULL, last_error VARCHAR(200) NULL, sent_at DATETIME NULL, UNIQUE(event_id,user_id))");
  $this->db->execute("CREATE TABLE IF NOT EXISTS `notification_sources` (source_key VARCHAR(100) PRIMARY KEY, cursor_time VARCHAR(30) NOT NULL, cursor_id BIGINT NOT NULL DEFAULT 0)");
  if(!$sqlite){$indexes=$this->db->select('SHOW INDEX FROM `notification_outbox`');$found=false;foreach($indexes as $idx)if($idx->Key_name==='idx_notice_pending')$found=true;if(!$found)$this->db->execute('CREATE INDEX idx_notice_pending ON `notification_outbox` (status,available_at)');} else $this->db->execute('CREATE INDEX IF NOT EXISTS idx_notice_pending ON `notification_outbox` (status,available_at)');
  if(!$this->db->selectOne("SELECT id FROM `settings` WHERE group_name='notifications' AND setting_key='installed_at'"))$this->db->insert('settings',['group_name'=>'notifications','setting_key'=>'installed_at','value'=>gmdate('Y-m-d H:i:s'),'type'=>'string','is_public'=>0]);
 }
}
