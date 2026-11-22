<?php
declare(strict_types=1);
use FavoriteCMS\Core\Database;
class AddScheduledPublication
{
    public function __construct(protected Database $db){}
    public function up():void{
        $sqlite=$this->db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        foreach(['posts','pages'] as $table){
            $columns=$sqlite?$this->db->select('PRAGMA table_info('.$this->db->quoteIdentifier($table).')'):$this->db->select("SHOW COLUMNS FROM `{$table}`");
            $names=array_map(static fn($column)=>$column->name??$column->Field,$columns);
            foreach(['scheduled_at'=>'DATETIME NULL','scheduled_by'=>'BIGINT NULL','published_at'=>'DATETIME NULL'] as $name=>$definition)if(!in_array($name,$names,true))$this->db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
            if(!$sqlite){
                $statuses=$table==='posts'?"'draft','published','scheduled','archived','pending','rejected','trash'":"'draft','published','scheduled','trash'";
                $this->db->execute("ALTER TABLE `{$table}` MODIFY `status` ENUM({$statuses}) DEFAULT 'draft'");
                $indexes=$this->db->select("SHOW INDEX FROM `{$table}`");$found=false;foreach($indexes as $index)if($index->Key_name==='idx_scheduled_publication')$found=true;
                if(!$found)$this->db->execute("CREATE INDEX idx_scheduled_publication ON `{$table}` (status,scheduled_at)");
            }else $this->db->execute('CREATE INDEX IF NOT EXISTS '.$this->db->quoteIdentifier('idx_'.$table.'_scheduled_publication').' ON '.$this->db->quoteIdentifier($table).' (status,scheduled_at)');
        }
    }
}
