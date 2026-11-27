<?php

declare(strict_types=1);

namespace FavoriteCMS\Services;

use FavoriteCMS\Core\Database;
use FavoriteCMS\Models\Setting;
use PDO;
use RuntimeException;
use ZipArchive;

class BackupService
{
    protected string $backupDir;
    protected string $appRoot;
    protected array $sqliteSnapshots = [];

    public function __construct(?string $backupDir = null, ?string $appRoot = null)
    {
        $this->appRoot = $appRoot ?: (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2));
        $this->backupDir = $backupDir ?: $this->appRoot . '/storage/backups';
        $this->ensureBackupDirectory();
    }

    /**
     * Ensure the backup storage directory exists and is secured from direct web access.
     */
    protected function ensureBackupDirectory(): void
    {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0775, true);
        }

        $htaccess = $this->backupDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
    }

    /**
     * Create a complete, portable backup archive of the CMS.
     *
     * @param array $options Options: ['include_media' => true, 'include_themes' => true, 'include_plugins' => true]
     * @return array
     */
    public function createBackup(array $options = []): array
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('PHP Zip is required.');
        if (function_exists('set_time_limit')) @set_time_limit(300);
        $db = app(Database::class); $suffix = \FavoriteCMS\Core\DateTime::format(time(), 'Y-m-d_His').'_'.bin2hex(random_bytes(6));
        $filename='favorite_cms_backup_'.$suffix.'.zip'; $zipPath=$this->backupDir.'/'.$filename; $sql=$this->backupDir.'/dump_'.$suffix.'.sql';
        $working=$zipPath.'.partial';$zip=new ZipArchive();$opened=false;$this->sqliteSnapshots=[];
        try {
            $dump=$this->dumpDatabase($db->getPdo(),$db->prefix(),$sql);
            $files=$this->collectFiles($options);
            if($zip->open($working,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Backup archive could not be created.');$opened=true;@chmod($working,0600);
            if(!$zip->addFile($sql,'database.sql'))throw new RuntimeException('Database dump could not be archived.');
            $inventory=[];$snapshotBudget=16777216;
            foreach($files as$name=>$file){
                if($file===null){if(!$zip->addEmptyDir('files/'.rtrim($name,'/')))throw new RuntimeException('Empty directory could not be archived: '.$name);continue;}
                $source=$file;
                if(preg_match('~^storage/favorite-central-private/oauth/database/gateway-[a-f0-9]{16}\.sqlite$~',$name)){
                    $snapshot=$this->backupDir.'/oauth-snapshot-'.bin2hex(random_bytes(12)).'.sqlite';$this->sqliteSnapshots[]=$snapshot;
                    $sqlite=new PDO('sqlite:file:'.str_replace('\\','/',$file).'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$sqlite->exec('VACUUM INTO '.$sqlite->quote($snapshot));$sqlite=null;@chmod($snapshot,0600);$source=$snapshot;
                }
                // Connection state is rewritten by ordinary requests while the archive is built. addFile() reads at close(), so
                // archive the very bytes that were hashed (bounded in size and total memory) instead of racing those writes.
                if($source===$file&&$snapshotBudget>0&&preg_match('~^storage/favorite-central-(?:client|private)/~',$name)&&($probe=@filesize($source))!==false&&$probe<=262144){
                    $bytes=@file_get_contents($source);
                    if(is_string($bytes)&&strlen($bytes)<=$snapshotBudget){
                        if(!$zip->addFromString('files/'.$name,$bytes))throw new RuntimeException('File could not be backed up: '.$name);
                        $snapshotBudget-=strlen($bytes);$inventory['files/'.$name]=['size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];continue;
                    }
                }
                $hash=hash_file('sha256',$source);$size=filesize($source);if($hash===false||$size===false||!$zip->addFile($source,'files/'.$name))throw new RuntimeException('File could not be backed up: '.$name);
                $inventory['files/'.$name]=['size'=>$size,'sha256'=>$hash];
            }
            $manifest=['manifest_version'=>2,'files_prefix'=>'files/','cms_name'=>'Favorite CMS Universal','cms_version'=>defined('APP_VERSION')?APP_VERSION:'2.0.0-beta.2','schema_version'=>$this->getSchemaVersion($db),'created_at'=>date('c'),'site_name'=>Setting::get('general','site_name','Favorite CMS'),'site_url'=>Setting::get('general','site_url',(string)env('APP_URL','http://localhost')),'table_prefix'=>$db->prefix(),'tables'=>$dump['tables'],'database_objects'=>$dump['objects']??[],'total_rows'=>$dump['total_rows'],'database_size'=>filesize($sql),'includes'=>['database'=>true,'core'=>true,'configuration'=>true,'media'=>$options['include_media']??true,'themes'=>$options['include_themes']??true,'plugins'=>$options['include_plugins']??true],'excluded_runtime_paths'=>['storage/backups (including this archive)','storage/temp','storage/cache','storage/logs','storage/sessions','private server update staging','transient lock files; installed.lock is retained'],'file_count'=>count($inventory),'files'=>$inventory,'sql_checksum'=>hash_file('sha256',$sql)];
            if(!$zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)))throw new RuntimeException('Backup manifest could not be written.');
            if(!$zip->close())throw new RuntimeException('Archive could not be finalized; check free disk space.');$opened=false;
            $verify=new ZipArchive();if($verify->open($working,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Completed backup archive is corrupt.');
            try{foreach($inventory+['database.sql'=>['size'=>filesize($sql),'sha256'=>$manifest['sql_checksum']]]as$name=>$entry){$stream=$verify->getStream($name);if(!$stream)throw new RuntimeException('Backup file is missing: '.$name);$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream);fclose($stream);if($size!==$entry['size']||!hash_equals($entry['sha256'],hash_final($hash)))throw new RuntimeException('Backup file changed or was truncated: '.$name);}}finally{$verify->close();}
            if(array_keys($files)!==array_keys($this->collectFiles($options)))throw new RuntimeException('Website files changed during backup; retry when changes are paused.');
            if(!rename($working,$zipPath))throw new RuntimeException('Verified backup could not be published.');
            return ['success'=>true,'filename'=>$filename,'path'=>$zipPath,'size'=>filesize($zipPath),'sha256'=>hash_file('sha256',$zipPath),'manifest'=>$manifest];
        }catch(\Throwable $e){if($opened)try{$zip->close();}catch(\Throwable){}if(is_file($working))@unlink($working);if(is_file($zipPath))@unlink($zipPath);throw $e;}
        finally{if(is_file($sql))@unlink($sql);foreach($this->sqliteSnapshots as$file)if(is_file($file))@unlink($file);$this->sqliteSnapshots=[];}
    }

    public function dumpDatabase(PDO $pdo,string $prefix,string $outputPath): array
    {
        return MySqlBackup::dump($pdo,$prefix,$outputPath);
    }

    /** All website files, including dotfiles/keys. Exclusions are narrowly scoped runtime data. */
    protected function collectFiles(array $options):array
    {
        $root=realpath($this->appRoot);$backup=realpath($this->backupDir);if(!$root||!$backup)throw new RuntimeException('Backup paths are unavailable.');$files=[];
        $walk=function(string $dir,string $relative='')use(&$walk,&$files,$root,$backup,$options):void{
            $names=scandir($dir);if($names===false)throw new RuntimeException('A website directory cannot be read.');
            foreach($names as$name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;$rel=$relative===''?$name:$relative.'/'.$name;
                if($path===$backup||realpath($path)===$backup)continue;
                $omit=false;foreach(['storage/backups','storage/temp','storage/cache','storage/logs','storage/sessions','storage/favorite-central-private/server-update/pending']as$prefix)if($rel===$prefix||str_starts_with($rel,$prefix.'/')){$omit=true;break;}
                foreach(['include_media'=>'public/uploads','include_themes'=>'themes','include_plugins'=>'plugins']as$flag=>$prefix)if(($options[$flag]??true)===false&&($rel===$prefix||str_starts_with($rel,$prefix.'/')))$omit=true;
                if($omit)continue;
                if(is_link($path))throw new RuntimeException('A symbolic link needs a separate backup plan: '.$rel);
                if(in_array($rel,['storage/update.lock','storage/maintenance.lock'],true)||preg_match('~^storage/favorite-central-(?:client|private)/.*\.(?:lock|tmp)$~',$rel))continue;
                if(preg_match('~^storage/favorite-central-private/oauth/database/gateway-[a-f0-9]{16}\.sqlite-(?:wal|shm)$~',$rel))continue;
                if(is_dir($path)){$files[$rel.'/']=null;$walk($path,$rel);}elseif(is_file($path)&&is_readable($path)){$files[$rel]=$path;}else throw new RuntimeException('A website file cannot be read: '.$rel);
            }
        };$walk($root);ksort($files,SORT_STRING);return $files;
    }

    /**
     * Retrieve schema migration version count.
     */
    protected function getSchemaVersion(Database $db): int
    {
        try {
            $row = $db->selectOne("SELECT COUNT(*) as c FROM `{$db->prefix()}cms_migrations`");
            return (int)($row->c ?? 0);
        } catch (\Throwable) {
            return 13;
        }
    }

    /**
     * Get list of all available backups in the backup storage directory.
     */
    public function getBackups(): array
    {
        $backups = [];
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/favorite_cms_backup_*.zip') ?: [];
        rsort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            $size = filesize($file);
            $date = filemtime($file);

            $manifest = null;
            if (class_exists(ZipArchive::class)) {
                $zip = new ZipArchive();
                if ($zip->open($file) === true) {
                    $manifestJson = $zip->getFromName('manifest.json');
                    if ($manifestJson !== false) {
                        $manifest = json_decode($manifestJson, true);
                    }
                    $zip->close();
                }
            }

            $backups[] = [
                'filename'  => $filename,
                'path'      => $file,
                'size'      => $size,
                'date'      => $date,
                'manifest'  => $manifest,
            ];
        }

        return $backups;
    }

    /**
     * Delete a specific backup by filename.
     */
    public function deleteBackup(string $filename): bool
    {
        $safeName = basename($filename);
        if (!preg_match('/^favorite_cms_backup_[A-Za-z0-9_.-]+\.zip$/', $safeName)) {
            throw new \InvalidArgumentException('Invalid backup filename.');
        }

        $path = $this->backupDir . '/' . $safeName;
        if (file_exists($path)) {
            return @unlink($path);
        }

        return false;
    }
}

