<?php
declare(strict_types=1);
namespace FavoriteCMS\Services;

use DateTimeImmutable;
use DateTimeZone;
use FavoriteCMS\Core\Database;
use FavoriteCMS\Core\DateTime;
use FavoriteCMS\Core\Request;
use FavoriteCMS\Models\User;

final class ScheduledPublication
{
    public static function choice(Request $request, User $actor, ?object $record, string $type): array
    {
        $status=(string)$request->post('status','draft');
        $legacy=(string)$request->post('action_type','');
        if($legacy==='draft')$status='draft';elseif($legacy==='publish')$status='published';
        $allowed=$type==='post'?['draft','published','scheduled','pending','rejected']:['draft','published','scheduled'];
        if(!in_array($status,$allowed,true))throw new \RuntimeException('Choose a valid publication status.');
        if($type==='post'&&!$actor->canDirectPublish()&&in_array($status,['published','scheduled','rejected'],true))$status='pending';
        if($type==='page'&&!$actor->canManagePages())throw new \RuntimeException('You cannot publish or schedule pages.');
        $scheduled=null;
        if($status==='scheduled'){
            $raw=(string)$request->post('scheduled_at','');
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$raw,DateTime::getTimezoneObject());
            $errors=DateTimeImmutable::getLastErrors();
            if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i')!==$raw)throw new \RuntimeException('Choose a valid schedule date and time in the site timezone.');
            if($date->getTimestamp()<=time())throw new \RuntimeException('The scheduled publication time must be in the future.');
            $scheduled=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $published=$record->published_at??null;
        if($status==='published'&&(($record->status??'')!=='published'||!$published))$published=gmdate('Y-m-d H:i:s');
        return ['status'=>$status,'scheduled_at'=>$scheduled,'scheduled_by'=>$scheduled?(int)$actor->id:null,'published_at'=>$published];
    }

    public static function runDue(Database $db): int
    {
        $count=0;$now=gmdate('Y-m-d H:i:s');
        // Two bounded indexed queries. No claim state: a conditional atomic update
        // prevents concurrent requests from publishing twice or overwriting a reschedule.
        foreach(['posts'=>'post','pages'=>'page'] as $table=>$type){
            try {
                $native=$table==='posts'?" AND type='post'":'';
                $rows=$db->select("SELECT id,scheduled_at,scheduled_by FROM `{$table}` WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at<=?{$native} ORDER BY scheduled_at,id LIMIT 50",[$now]);
                foreach($rows as $row){
                    $actor=User::find((int)$row->scheduled_by);
                    if(!$actor||!$actor->isActive()||!($type==='post'?$actor->canDirectPublish():$actor->canManagePages())){
                        $db->query("UPDATE `{$table}` SET status='draft',scheduled_at=NULL,scheduled_by=NULL,updated_at=? WHERE id=? AND status='scheduled' AND scheduled_at=? AND scheduled_by=?",[$now,(int)$row->id,$row->scheduled_at,(int)$row->scheduled_by]);
                        error_log('Favorite CMS cancelled schedule: publication permission unavailable for '.$type.' '.(int)$row->id);continue;
                    }
                    $changed=$db->query("UPDATE `{$table}` SET status='published',published_at=scheduled_at,scheduled_at=NULL,scheduled_by=NULL,updated_at=? WHERE id=? AND status='scheduled' AND scheduled_at=? AND scheduled_by=?",[$now,(int)$row->id,$row->scheduled_at,(int)$row->scheduled_by])->rowCount();
                    if($changed)$count++;
                }
            } catch(\Throwable $error){error_log('Favorite CMS scheduled publication: '.$error->getMessage());}
        }
        return $count;
    }
}
