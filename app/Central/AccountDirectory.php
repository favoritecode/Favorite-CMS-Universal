<?php
declare(strict_types=1);
namespace FavoriteCMS\Central;
/** Allowlisted account metadata only; bounded incremental snapshots, no credentials. */
final class AccountDirectory {
 static function chunk(array $config):?array{
  $cursor=(array)($config['user_directory_cursor']??[]);if((int)($cursor['next_cycle_after']??0)>time())return null;
  $generation=(string)($cursor['generation']??bin2hex(random_bytes(16)));$after=max(0,(int)($cursor['after']??0));$db=app(\FavoriteCMS\Core\Database::class);
  try{$rows=$db->select('SELECT id,name,username,email,status FROM users WHERE id>? ORDER BY id LIMIT 50',[$after]);}catch(\Throwable){$rows=$db->select('SELECT id,username,email,status FROM users WHERE id>? ORDER BY id LIMIT 50',[$after]);}$items=[];$last=$after;
  foreach($rows as$r){$roles=$db->select('SELECT r.slug FROM roles r JOIN user_roles ur ON r.id=ur.role_id WHERE ur.user_id=?',[$r->id]);$items[]=['id'=>(string)$r->id,'name'=>(string)($r->name??''),'username'=>(string)($r->username??''),'email'=>(string)$r->email,'status'=>(string)$r->status,'roles'=>array_map(static fn($v)=>(string)$v->slug,$roles)];$last=(int)$r->id;}
  return ['generation'=>$generation,'after'=>$after,'next'=>$last,'complete'=>count($rows)<50,'items'=>$items,'reported_total'=>(int)$db->selectOne('SELECT COUNT(*) n FROM users')->n];
 }
 static function acknowledge(array $sent,array $ack):?array{
  if(($ack['generation']??null)!==$sent['generation']||($ack['next']??null)!==$sent['next']||($ack['complete']??null)!==$sent['complete'])return null;
  return $sent['complete']?['next_cycle_after'=>time()+300]:['generation'=>$sent['generation'],'after'=>$sent['next']];
 }
}
