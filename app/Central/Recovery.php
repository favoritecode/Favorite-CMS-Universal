<?php
 declare(strict_types=1);
 namespace FavoriteCMS\Central;
 use FavoriteCMS\Core\{Request,Response};
 final class Recovery {
  public static function response(Request $r):Response{
   if(!current_user())return Response::redirect(site_path('/admin/login'));
   if(!current_user_can('manage_options'))return Response::make('Administrator access required.',403);
   $client=new Client();$notice='';
   if($r->isPost()){
    if(!hash_equals(csrf_token(),(string)$r->post('_token','')))return Response::make('Session expired.',403);
    try{
     if($r->post('action')==='recover'){
      if((int)($_SESSION['_central_recovery_retry_after']??0)>time())throw new \RuntimeException('Wait briefly before retrying.');
      $_SESSION['_central_recovery_retry_after']=time()+5;
      if(!current_user()->verifyPassword((string)$r->post('password','')))return Response::make('Confirm your current administrator password.',403);
      $client->recoverOfficialConnection();$notice='Official connection reverified. The Owner action remains authoritative.';
     }else{$client->autoConnect();$notice='Automatic connection checked. Retry timing and ownership verification remain enforced.';}
    }catch(\Throwable){$notice='Connection could not yet be reverified. Existing identity and data are preserved. Restore official files first if integrity verification failed.';}
   }
   $c=$client->config();$integrity=\FavoriteCMS\Security\CoreIntegrity::report(APP_ROOT);
   $state=$integrity['status']==='verified'?$client->policy():['policy_status'=>'integrity_failed','policy_reason'=>'Restore the complete official signed ZIP. Normal site operation stays restricted until verification succeeds.'];
   $error=(string)($c['registration_last_error']??$c['registry_last_error']??$c['policy_last_error']??$state['policy_reason']??'');
   $h='<section class="card"><span class="eyebrow">SAFE RECOVERY</span><h1>Site recovery</h1><p>Sign-in, official ZIP repair and backups remain available while normal site access is restricted. Repairing files does not remove an Owner suspension or ban.</p><p>New sites can run pending during temporary service outages. Registered sites keep their last authenticated Owner decision. Automatic retries continue.</p><p>Status: <strong>'.Ui::e($state['policy_status']??'connection_required').'</strong> · Official files: <strong>'.Ui::e($integrity['status']).'</strong></p>';
   if($error!=='')$h.='<p class="notice">'.Ui::e($error).'</p>';if($notice!=='')$h.='<p class="notice" role="status">'.Ui::e($notice).'</p>';
   if(($integrity['status']??'')!=='verified')$h.='<p class="notice">Verification detail: '.Ui::e($integrity['reason']??'unavailable').(!empty($integrity['failed_file'])?' · '.Ui::e($integrity['failed_file']):'').'</p>';
   $h.='<div class="actions"><a class="button primary" href="'.Ui::e(site_path('/admin/updates')).'">Upload official ZIP / repair</a><a class="button" href="'.Ui::e(site_path('/admin/connection-status').'#backups').'">Existing backup</a><a class="button" href="'.Ui::e(site_path('/admin/connection-status').'#backups').'">Restore backup</a></div><form method="post">'.Ui::token('retry').'<div class="actions"><button>Check automatic connection</button></div></form><details><summary>Repair official connection credentials</summary><p>After restoring official files, reverify a damaged connection with your current administrator password. Your installation identity and Owner restrictions are retained; verification is not bypassed.</p><form method="post">'.Ui::token('recover').Ui::field('password','Current administrator password','','password').'<div class="actions"><button>Reverify official connection</button></div></form></details>';
   if(in_array($state['policy_status']??'',['active','warning','registration_pending'],true))$h.='<div class="actions"><a class="button primary" href="'.Ui::e(site_path('/admin').'?safe_repair=0').'">Open dashboard</a></div>';
   $h.='<details id="backups"><summary>Backups &amp; restore</summary><form method="post" action="'.Ui::e(site_path('/admin/tools/backup/create')).'">'.Ui::token('create_backup').'<div class="actions"><button>Create a full backup</button></div></form>';
   try{$backups=(new \FavoriteCMS\Services\BackupService())->getBackups();foreach(array_slice($backups,0,10)as$backup)$h.='<p><a href="'.Ui::e(site_path('/admin/tools/backup/download').'?file='.rawurlencode($backup['filename'])).'">'.Ui::e($backup['filename']).'</a></p>';if(!$backups)$h.='<p>No saved backup found.</p>';}catch(\Throwable){$h.='<p>Backup listing is temporarily unavailable.</p>';}
   $h.='<p>Restore changes site data. Keep a current backup first and use a trusted matching archive.</p><form method="post" enctype="multipart/form-data" action="'.Ui::e(site_path('/admin/tools/restore')).'" onsubmit="return confirm(&quot;Restore site data from this backup?&quot;)">'.Ui::token('restore_backup').'<label>Trusted site backup ZIP<input type="file" name="restore_file" accept=".zip" required></label><div class="actions"><button>Restore selected backup</button></div></form></details>';
   $h.='<details><summary>Registration and account directory disclosure</summary><p>The platform owner can see this installation and its account directory: account IDs, names, usernames, email addresses, roles and status. Passwords, tokens, payment secrets and private content are not synchronized.</p></details></section>';
   return Response::make(Ui::document('Site recovery',$h),200,['Cache-Control'=>'no-store, private','X-Robots-Tag'=>'noindex, nofollow']);
  }
 }
