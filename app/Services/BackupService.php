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
            $manifest=['manifest_version'=>2,'files_prefix'=>'files/','cms_name'=>'Favorite CMS Universal','cms_version'=>defined('APP_VERSION')?APP_VERSION:'2.0.0','schema_version'=>$this->getSchemaVersion($db),'created_at'=>date('c'),'site_name'=>Setting::get('general','site_name','Favorite CMS'),'site_url'=>Setting::get('general','site_url',(string)env('APP_URL','http://localhost')),'table_prefix'=>$db->prefix(),'tables'=>$dump['tables'],'database_objects'=>$dump['objects']??[],'total_rows'=>$dump['total_rows'],'database_size'=>filesize($sql),'includes'=>['database'=>true,'core'=>true,'configuration'=>true,'media'=>$options['include_media']??true,'themes'=>$options['include_themes']??true,'plugins'=>$options['include_plugins']??true],'excluded_runtime_paths'=>['storage/backups (including this archive)','storage/temp','storage/cache','storage/logs','storage/sessions','private server update staging','transient lock files; installed.lock is retained'],'file_count'=>count($inventory),'files'=>$inventory,'sql_checksum'=>hash_file('sha256',$sql)];
            if(!$zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)))throw new RuntimeException('Backup manifest could not be written.');
            if(!$zip->close())throw new RuntimeException('Archive could not be finalized; check free disk space.');$opened=false;
            $verify=new ZipArchive();if($verify->open($working,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Completed backup archive is corrupt.');
            try{foreach($inventory+['database.sql'=>['size'=>filesize($sql),'sha256'=>$manifest['sql_checksum']]]as$name=>$entry){$stream=$verify->getStream($name);if(!$stream)throw new RuntimeException('Backup file is missing: '.$name);$hash=hash_init('sha256');$size=hash_update_stream($hash,$stream);fclose($stream);if($size!==$entry['size']||!hash_equals($entry['sha256'],hash_final($hash)))throw new RuntimeException('Backup file changed or was truncated: '.$name);}}finally{$verify->close();}
            if(array_keys($files)!==array_keys($this->collectFiles($options)))throw new RuntimeException('Website files changed during backup; retry when changes are paused.');
            if(!rename($working,$zipPath))throw new RuntimeException('Verified backup could not be published.');
            $this->maybeApplyRetention(true); // the new archive exists before anything old is removed
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

    public const KEEP_LATEST = 3;
    public const MAX_AGE_DAYS = 30;

    /** Retention policy (Setting group "backup"): keep the newest 3 archives, delete the others once older than 30 days. */
    public function retentionPolicy(): array
    {
        try {
            $keep = max(self::KEEP_LATEST, (int)Setting::get('backup', 'keep_latest', self::KEEP_LATEST));
            $days = max(1, (int)Setting::get('backup', 'max_age_days', self::MAX_AGE_DAYS));
            $enabled = (string)Setting::get('backup', 'auto_cleanup', '1') !== '0';
        } catch (\Throwable) {
            return ['keep_latest' => self::KEEP_LATEST, 'max_age_days' => self::MAX_AGE_DAYS, 'enabled' => true];
        }
        return ['keep_latest' => $keep, 'max_age_days' => $days, 'enabled' => $enabled];
    }

    /**
     * Automatic retention. The newest $keepLatest archives are ALWAYS kept; of the remaining archives only those older than
     * $maxAgeDays days are deleted. Abandoned temporary files from interrupted runs (older than a day) are removed too.
     *
     * @return array{deleted: string[], freed: int, kept: int}
     */
    public function applyRetention(?int $keepLatest = null, ?int $maxAgeDays = null, ?int $now = null): array
    {
        $policy = $this->retentionPolicy();
        $keep = max(1, $keepLatest ?? $policy['keep_latest']);
        $days = max(1, $maxAgeDays ?? $policy['max_age_days']);
        $now ??= time();
        $deleted = []; $freed = 0;
        if (!is_dir($this->backupDir)) return ['deleted' => [], 'freed' => 0, 'kept' => 0];
        $files = glob($this->backupDir . '/favorite_cms_backup_*.zip') ?: [];
        usort($files, static fn($a, $b) => [(int)filemtime($b), $b] <=> [(int)filemtime($a), $a]); // newest first
        foreach (array_slice($files, $keep) as $file) {
            if ($now - (int)filemtime($file) < $days * 86400) continue;
            if (is_link($file) || !is_file($file)) continue;
            $size = (int)@filesize($file);
            if (@unlink($file)) { $deleted[] = basename($file); $freed += $size; }
        }
        foreach (['/favorite_cms_backup_*.zip.partial', '/dump_*.sql', '/oauth-snapshot-*.sqlite', '/database-export-*.sql'] as $pattern) {
            foreach (glob($this->backupDir . $pattern) ?: [] as $stale) {
                if (is_link($stale) || !is_file($stale) || $now - (int)filemtime($stale) <= 86400) continue;
                $size = (int)@filesize($stale);
                if (@unlink($stale)) { $deleted[] = basename($stale); $freed += $size; }
            }
        }
        return ['deleted' => $deleted, 'freed' => $freed, 'kept' => count(glob($this->backupDir . '/favorite_cms_backup_*.zip') ?: [])];
    }

    /** Run the retention at most every 12 hours (after each backup and when the Tools page opens). Never throws. */
    public function maybeApplyRetention(bool $ignoreThrottle = false): ?array
    {
        try {
            if (!$this->retentionPolicy()['enabled']) return null;
            if (!$ignoreThrottle && time() - (int)Setting::get('backup', 'last_cleanup_at', 0) < 43200) return null;
            Setting::set('backup', 'last_cleanup_at', time(), 'int');
            return $this->applyRetention();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Delete several backups at once (manual selection). Names are validated one by one; anything that is not a
     * Favorite CMS backup archive inside the backup directory is reported as failed, never touched.
     *
     * @return array{deleted: string[], failed: string[]}
     */
    public function deleteBackups(array $filenames): array
    {
        $deleted = []; $failed = [];
        foreach (array_slice(array_values(array_unique(array_map('strval', $filenames))), 0, 200) as $name) {
            try {
                if ($this->deleteBackup($name)) $deleted[] = basename($name); else $failed[] = basename($name);
            } catch (\InvalidArgumentException) {
                $failed[] = basename($name);
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}

